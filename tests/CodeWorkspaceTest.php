<?php

declare(strict_types=1);

use App\Core\Code\CodeWorkspace;
use App\Core\Code\CodeSelfProtection;
use App\Core\Code\SensitivePathPolicy;

// F0.2 — operazioni confinate del workspace: lettura, filtro sensibili, ri-validazione.

$rmrf = static function (string $path) use (&$rmrf): void {
    if (is_link($path)) {
        @unlink($path);
        return;
    }
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $rmrf($path . '/' . $e);
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
};

$tmpBase = static function (): string {
    $base = realpath(sys_get_temp_dir());
    return $base === false ? sys_get_temp_dir() : $base;
};

$throws = static function (callable $fn): bool {
    try {
        $fn();
        return false;
    } catch (\Throwable $e) {
        return true;
    }
};

// Radice con: file.php (contenuto noto), .env, sub/, symlink lnk.
$mkroot = static function () use ($tmpBase): string {
    $root = $tmpBase() . '/aimanager_ws_' . uniqid('', true);
    mkdir($root, 0777, true);
    file_put_contents($root . '/file.php', "<?php echo 'ciao';\n");
    file_put_contents($root . '/.env', "SECRET=1\n");
    mkdir($root . '/sub');
    symlink($root . '/file.php', $root . '/lnk');
    return $root;
};

$ws = static function (string $root): CodeWorkspace {
    return new CodeWorkspace(1, $root, '', 'active', new SensitivePathPolicy());
};

test('CodeWorkspace: legge un file interno', function () use ($mkroot, $ws, $rmrf) {
    $root = $mkroot();
    try {
        assertSame("<?php echo 'ciao';\n", $ws($root)->read('file.php'));
    } finally {
        $rmrf($root);
    }
});

test('CodeWorkspace: un file sensibile non si legge nemmeno se richiesto', function () use ($mkroot, $ws, $rmrf, $throws) {
    $root = $mkroot();
    try {
        assertSame(true, $throws(static fn () => $ws($root)->read('.env')));
    } finally {
        $rmrf($root);
    }
});

test('CodeWorkspace: un file mancante da\' errore controllato', function () use ($mkroot, $ws, $rmrf, $throws) {
    $root = $mkroot();
    try {
        assertSame(true, $throws(static fn () => $ws($root)->read('mancante.php')));
    } finally {
        $rmrf($root);
    }
});

test('CodeWorkspace: file eliminato tra resolve e read da\' errore controllato', function () use ($mkroot, $ws, $rmrf, $throws) {
    $root = $mkroot();
    try {
        $w = $ws($root);
        $path = $w->resolve('file.php');
        assertSame($root . '/file.php', $path);
        unlink($path); // eliminato dopo il resolve, prima del read
        assertSame(true, $throws(static fn () => $w->read('file.php')));
    } finally {
        $rmrf($root);
    }
});

test('CodeWorkspace: leggere fuori dalla radice e\' rifiutato', function () use ($mkroot, $ws, $rmrf, $throws) {
    $root = $mkroot();
    try {
        assertSame(true, $throws(static fn () => $ws($root)->read('../fuori.php')));
    } finally {
        $rmrf($root);
    }
});

test('CodeWorkspace: se la radice viene eliminata, l\'operazione fallisce chiusa', function () use ($mkroot, $ws, $rmrf, $throws) {
    $root = $mkroot();
    $w = $ws($root);
    $rmrf($root); // radice spostata/eliminata dopo l'autorizzazione
    assertSame(true, $throws(static fn () => $w->read('file.php')));
    assertSame(true, $throws(static fn () => $w->listFiles()));
});

test('CodeWorkspace: un workspace revocato rifiuta resolve, read e listFiles', function () use ($mkroot, $rmrf, $throws) {
    $root = $mkroot();
    try {
        $revoked = new CodeWorkspace(1, $root, '', 'revoked', new SensitivePathPolicy());
        assertSame(true, $throws(static fn () => $revoked->resolve('file.php')));
        assertSame(true, $throws(static fn () => $revoked->read('file.php')));
        assertSame(true, $throws(static fn () => $revoked->listFiles()));
    } finally {
        $rmrf($root);
    }
});

test('CodeWorkspace: un file non leggibile da\' errore controllato (skip se i permessi non sono affidabili)', function () use ($mkroot, $ws, $rmrf, $throws) {
    $root = $mkroot();
    $file = $root . '/nolettura.php';
    try {
        file_put_contents($file, "<?php\n");
        @chmod($file, 0000);
        clearstatcache();
        if (is_readable($file)) {
            // Ambiente che ignora i permessi (root, o certi filesystem): skip documentato.
            assertSame(true, true, 'skip: i permessi non sono verificabili in questo ambiente');
            return;
        }
        assertSame(true, $throws(static fn () => $ws($root)->read('nolettura.php')));
    } finally {
        @chmod($file, 0644);
        $rmrf($root);
    }
});

test('CodeWorkspace: listFiles esclude sensibili e symlink', function () use ($mkroot, $ws, $rmrf) {
    $root = $mkroot();
    try {
        // Entries reali: .env (sensibile), file.php, lnk (symlink), sub (dir).
        assertSame(
            [
                ['name' => 'file.php', 'type' => 'file'],
                ['name' => 'sub', 'type' => 'dir'],
            ],
            $ws($root)->listFiles()
        );
    } finally {
        $rmrf($root);
    }
});

test('CodeWorkspace: readLimited ritorna il contenuto sotto soglia', function () use ($mkroot, $ws, $rmrf) {
    $root = $mkroot();
    file_put_contents($root . '/small.txt', 'abc'); // 3 byte
    try {
        assertSame('abc', $ws($root)->readLimited('small.txt', 10));
    } finally {
        $rmrf($root);
    }
});

test('CodeWorkspace: readLimited al confine esatto della soglia ritorna il contenuto', function () use ($mkroot, $ws, $rmrf) {
    $root = $mkroot();
    file_put_contents($root . '/exact.txt', str_repeat('x', 100)); // esattamente 100
    try {
        assertSame(100, strlen($ws($root)->readLimited('exact.txt', 100)));
    } finally {
        $rmrf($root);
    }
});

test('CodeWorkspace: readLimited rifiuta un file oltre soglia senza caricarlo integralmente', function () use ($mkroot, $ws, $rmrf, $throws) {
    $root = $mkroot();
    file_put_contents($root . '/big.txt', str_repeat('x', 1000)); // ben oltre 100
    try {
        assertSame(true, $throws(static fn () => $ws($root)->readLimited('big.txt', 100)));
    } finally {
        $rmrf($root);
    }
});

test('CodeWorkspace: rootIsValid e\' vero con root valida, falso se eliminata', function () use ($mkroot, $ws, $rmrf) {
    $root = $mkroot();
    $w = $ws($root);
    assertSame(true, $w->rootIsValid());
    $rmrf($root); // root eliminata: ora non è più valida
    assertSame(false, $w->rootIsValid());
});

test('CodeWorkspace: anti-self nega root uguale, antenata e discendente a ogni uso', function () use ($tmpBase, $rmrf, $throws) {
    $base = $tmpBase() . '/aimanager_self_guard_' . uniqid('', true);
    $appRoot = $base . '/aimanager';
    mkdir($appRoot . '/sub', 0777, true);
    file_put_contents($appRoot . '/sub/file.txt', 'x');
    try {
        $protection = new CodeSelfProtection($appRoot);
        foreach ([$appRoot, $base, $appRoot . '/sub'] as $root) {
            $workspace = new CodeWorkspace(1, $root, '', 'active', new SensitivePathPolicy(), $protection);
            assertSame(false, $workspace->rootIsValid());
            assertSame(true, $throws(static fn () => $workspace->resolve('')));
        }
    } finally {
        $rmrf($base);
    }
});
