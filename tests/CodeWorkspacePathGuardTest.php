<?php

declare(strict_types=1);

use App\Core\Code\PathGuard;

// F0.2 — il test critico di sicurezza: confine + nessun symlink seguito (regola 1).

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

// Crea una radice temporanea canonica con: file.php e sub/.
$mkroot = static function () use ($tmpBase): string {
    $root = $tmpBase() . '/aimanager_pg_' . uniqid('', true);
    mkdir($root, 0777, true);
    file_put_contents($root . '/file.php', "<?php\n");
    mkdir($root . '/sub');
    return $root;
};

$throws = static function (callable $fn): bool {
    try {
        $fn();
        return false;
    } catch (\Throwable $e) {
        return true;
    }
};

test('PathGuard: percorso interno normale risolve dentro la radice', function () use ($mkroot, $rmrf) {
    $root = $mkroot();
    try {
        $g = new PathGuard($root);
        assertSame($root . '/file.php', $g->resolve('file.php'));
    } finally {
        $rmrf($root);
    }
});

test('PathGuard: sub/../file resta dentro ed e\' ammesso', function () use ($mkroot, $rmrf) {
    $root = $mkroot();
    try {
        $g = new PathGuard($root);
        assertSame($root . '/file.php', $g->resolve('sub/../file.php'));
    } finally {
        $rmrf($root);
    }
});

test('PathGuard: ../../etc/passwd e\' rifiutato', function () use ($mkroot, $rmrf, $throws) {
    $root = $mkroot();
    try {
        $g = new PathGuard($root);
        assertSame(true, $throws(static fn () => $g->resolve('../../etc/passwd')));
    } finally {
        $rmrf($root);
    }
});

test('PathGuard: percorso assoluto e\' rifiutato', function () use ($mkroot, $rmrf, $throws) {
    $root = $mkroot();
    try {
        $g = new PathGuard($root);
        assertSame(true, $throws(static fn () => $g->resolve('/etc/passwd')));
    } finally {
        $rmrf($root);
    }
});

test('PathGuard: una radice che e\' un symlink e\' rifiutata', function () use ($mkroot, $tmpBase, $rmrf, $throws) {
    $target = $mkroot();
    $link = $tmpBase() . '/aimanager_pg_link_' . uniqid('', true);
    symlink($target, $link);
    try {
        assertSame(true, $throws(static fn () => new PathGuard($link)));
    } finally {
        $rmrf($link);
        $rmrf($target);
    }
});

test('PathGuard: un symlink interno verso l\'esterno e\' rifiutato', function () use ($mkroot, $tmpBase, $rmrf, $throws) {
    $root = $mkroot();
    $outside = $tmpBase() . '/aimanager_pg_out_' . uniqid('', true);
    mkdir($outside);
    file_put_contents($outside . '/passwd', 'x');
    symlink($outside, $root . '/out');
    try {
        $g = new PathGuard($root);
        assertSame(true, $throws(static fn () => $g->resolve('out')));
        assertSame(true, $throws(static fn () => $g->resolve('out/passwd')));
    } finally {
        $rmrf($root);
        $rmrf($outside);
    }
});

test('PathGuard: un symlink circolare e\' gestito senza loop e rifiutato', function () use ($mkroot, $rmrf, $throws) {
    $root = $mkroot();
    symlink($root . '/b', $root . '/a');
    symlink($root . '/a', $root . '/b');
    try {
        $g = new PathGuard($root);
        assertSame(true, $throws(static fn () => $g->resolve('a')));
    } finally {
        $rmrf($root);
    }
});

test('PathGuard: un symlink rotto e\' rifiutato', function () use ($mkroot, $rmrf, $throws) {
    $root = $mkroot();
    symlink($root . '/inesistente', $root . '/broken');
    try {
        $g = new PathGuard($root);
        assertSame(true, $throws(static fn () => $g->resolve('broken')));
    } finally {
        $rmrf($root);
    }
});

test('PathGuard: una radice inesistente/eliminata e\' rifiutata', function () use ($mkroot, $rmrf, $throws) {
    $root = $mkroot();
    $rmrf($root); // radice eliminata dopo la creazione
    assertSame(true, $throws(static fn () => new PathGuard($root)));
});

test('PathGuard: la root del filesystem / e\' rifiutata', function () use ($throws) {
    assertSame(true, $throws(static fn () => new PathGuard('/')));
});

test('PathGuard: una radice non leggibile e\' rifiutata (skip se i permessi non sono affidabili)', function () use ($mkroot, $rmrf, $throws) {
    $root = $mkroot();
    @chmod($root, 0000);
    clearstatcache();
    try {
        if (is_readable($root)) {
            // Ambiente che ignora i permessi (root/FS): skip documentato.
            assertSame(true, true, 'skip: permessi non verificabili');
            return;
        }
        assertSame(true, $throws(static fn () => new PathGuard($root)));
    } finally {
        @chmod($root, 0777);
        $rmrf($root);
    }
});
