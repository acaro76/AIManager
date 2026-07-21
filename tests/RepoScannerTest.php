<?php

declare(strict_types=1);

use App\Core\Code\CodeWorkspace;
use App\Core\Code\RepoScanner;
use App\Core\Code\SensitivePathPolicy;

// F0.3 — RepoScanner: percorre SOLO le operazioni confinate del workspace.

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

$mkroot = static function () use ($tmpBase): string {
    $root = $tmpBase() . '/aimanager_scan_' . uniqid('', true);
    mkdir($root, 0777, true);
    return $root;
};

$ws = static function (string $root): CodeWorkspace {
    return new CodeWorkspace(1, $root, '', 'active', new SensitivePathPolicy());
};

// Estrae i soli path della mappa.
$paths = static function (array $files): array {
    return array_map(static fn (array $f): string => $f['path'], $files);
};

test('scan: estrae simboli PHP e mette in inventario i file non-codice', function () use ($mkroot, $ws, $rmrf, $paths) {
    $root = $mkroot();
    mkdir($root . '/src');
    file_put_contents($root . '/src/A.php', "<?php\nclass Alpha { function m() {} }\nfunction helper() {}\n");
    file_put_contents($root . '/readme.md', "# titolo\n");
    try {
        $map = (new RepoScanner())->scan($ws($root));
        $files = $map->files();
        assertSame(['readme.md', 'src/A.php'], $paths($files));
        // readme.md: inventario senza simboli
        assertSame([], $files[0]['symbols']);
        // src/A.php: simboli ordinati
        assertSame(['class Alpha', 'function helper', 'function m'], $files[1]['symbols']);
        assertSame(false, $map->isTruncated());
    } finally {
        $rmrf($root);
    }
});

test('scan: esclude file sensibili annidati e .git', function () use ($mkroot, $ws, $rmrf, $paths) {
    $root = $mkroot();
    mkdir($root . '/sub');
    mkdir($root . '/sub/keys');
    mkdir($root . '/.git');
    file_put_contents($root . '/sub/app.php', "<?php\n");
    file_put_contents($root . '/sub/.env', "SECRET=1\n");
    file_put_contents($root . '/sub/keys/server.pem', "KEY\n");
    file_put_contents($root . '/.git/config', "[core]\n");
    try {
        $map = (new RepoScanner())->scan($ws($root));
        assertSame(['sub/app.php'], $paths($map->files()));
    } finally {
        $rmrf($root);
    }
});

test('scan: non segue le directory-symlink', function () use ($mkroot, $ws, $rmrf, $paths) {
    $root = $mkroot();
    mkdir($root . '/real');
    file_put_contents($root . '/real/x.php', "<?php\n");
    symlink($root . '/real', $root . '/link');
    try {
        $map = (new RepoScanner())->scan($ws($root));
        // real/x.php c'e', link/x.php no (symlink non seguito)
        assertSame(['real/x.php'], $paths($map->files()));
    } finally {
        $rmrf($root);
    }
});

test('scan: un file binario e\' letto entro soglia per il rilevamento ma non analizzato per i simboli', function () use ($mkroot, $ws, $rmrf) {
    $root = $mkroot();
    file_put_contents($root . '/weird.php', "<?php\0 class X {}"); // NUL => binario
    try {
        $map = (new RepoScanner())->scan($ws($root));
        $files = $map->files();
        assertSame('weird.php', $files[0]['path']); // resta in inventario
        assertSame([], $files[0]['symbols']);       // rilevato binario => nessun simbolo
    } finally {
        $rmrf($root);
    }
});

test('scan: un file troppo grande resta in inventario senza simboli', function () use ($mkroot, $ws, $rmrf) {
    $root = $mkroot();
    file_put_contents($root . '/big.php', "<?php\nclass Grande {}\n" . str_repeat('/* x */', 100));
    try {
        $map = (new RepoScanner(maxReadBytes: 10))->scan($ws($root));
        $files = $map->files();
        assertSame('big.php', $files[0]['path']);
        assertSame([], $files[0]['symbols']);
    } finally {
        $rmrf($root);
    }
});

test('scan: oltre maxFiles la mappa e\' troncata', function () use ($mkroot, $ws, $rmrf) {
    $root = $mkroot();
    for ($i = 0; $i < 10; $i++) {
        file_put_contents($root . '/f' . $i . '.php', "<?php\n");
    }
    try {
        $map = (new RepoScanner(maxFiles: 3))->scan($ws($root));
        assertSame(3, count($map->files()));
        assertSame(true, $map->isTruncated());
    } finally {
        $rmrf($root);
    }
});

test('scan: oltre maxDepth non si scende e la mappa e\' troncata', function () use ($mkroot, $ws, $rmrf, $paths) {
    $root = $mkroot();
    mkdir($root . '/a');
    mkdir($root . '/a/b');
    file_put_contents($root . '/top.php', "<?php\n");
    file_put_contents($root . '/a/file1.php', "<?php\n");
    file_put_contents($root . '/a/b/deep.php', "<?php\n");
    try {
        $map = (new RepoScanner(maxDepth: 1))->scan($ws($root));
        $p = $paths($map->files());
        assertSame(true, in_array('top.php', $p, true));
        assertSame(true, in_array('a/file1.php', $p, true));
        assertSame(false, in_array('a/b/deep.php', $p, true));
        assertSame(true, $map->isTruncated());
    } finally {
        $rmrf($root);
    }
});

test('scan: l\'ordinamento dei file e\' deterministico', function () use ($mkroot, $ws, $rmrf, $paths) {
    $root = $mkroot();
    foreach (['zeta.php', 'alpha.php', 'mid.php'] as $name) {
        file_put_contents($root . '/' . $name, "<?php\n");
    }
    try {
        $map = (new RepoScanner())->scan($ws($root));
        assertSame(['alpha.php', 'mid.php', 'zeta.php'], $paths($map->files()));
    } finally {
        $rmrf($root);
    }
});

test('scan: un workspace revocato viene rifiutato', function () use ($mkroot, $rmrf, $throws) {
    $root = $mkroot();
    file_put_contents($root . '/a.php', "<?php\n");
    try {
        $revoked = new CodeWorkspace(1, $root, '', 'revoked', new SensitivePathPolicy());
        assertSame(true, $throws(static fn () => (new RepoScanner())->scan($revoked)));
    } finally {
        $rmrf($root);
    }
});

test('scan: euristica JS e Python dichiarata', function () use ($mkroot, $ws, $rmrf) {
    $root = $mkroot();
    file_put_contents($root . '/app.js', "class Foo {}\nfunction bar() {}\n");
    file_put_contents($root . '/mod.py', "class Baz:\n    def qux(self):\n        pass\n");
    try {
        $map = (new RepoScanner())->scan($ws($root));
        $bySymbol = [];
        foreach ($map->files() as $f) {
            $bySymbol[$f['path']] = $f['symbols'];
        }
        assertSame(['class Foo', 'function bar'], $bySymbol['app.js']);
        assertSame(['class Baz', 'def qux'], $bySymbol['mod.py']);
    } finally {
        $rmrf($root);
    }
});

test('scan: estrae anche gli enum PHP (T_ENUM)', function () use ($mkroot, $ws, $rmrf) {
    $root = $mkroot();
    file_put_contents($root . '/E.php', "<?php\nenum Suit { case Hearts; case Spades; }\n");
    try {
        $map = (new RepoScanner())->scan($ws($root));
        assertSame(['enum Suit'], $map->files()[0]['symbols']);
    } finally {
        $rmrf($root);
    }
});

test('scan: le directory di rumore sono escluse a qualsiasi profondita\'', function () use ($mkroot, $ws, $rmrf, $paths) {
    $root = $mkroot();
    mkdir($root . '/src');
    mkdir($root . '/vendor');
    mkdir($root . '/node_modules');
    mkdir($root . '/storage');
    mkdir($root . '/src/vendor'); // esclusa anche annidata
    file_put_contents($root . '/src/app.php', "<?php\n");
    file_put_contents($root . '/vendor/lib.php', "<?php\n");
    file_put_contents($root . '/node_modules/pkg.js', "function x(){}\n");
    file_put_contents($root . '/storage/db.php', "<?php\n");
    file_put_contents($root . '/src/vendor/x.php', "<?php\n");
    try {
        $map = (new RepoScanner())->scan($ws($root));
        assertSame(['src/app.php'], $paths($map->files()));
    } finally {
        $rmrf($root);
    }
});

test('scan: i file di rumore (.DS_Store) sono esclusi dall\'inventario', function () use ($mkroot, $ws, $rmrf, $paths) {
    $root = $mkroot();
    file_put_contents($root . '/.DS_Store', 'junk');
    file_put_contents($root . '/app.php', "<?php\n");
    mkdir($root . '/sub');
    file_put_contents($root . '/sub/.DS_Store', 'junk'); // anche annidato
    file_put_contents($root . '/sub/x.php', "<?php\n");
    try {
        $map = (new RepoScanner())->scan($ws($root));
        assertSame(['app.php', 'sub/x.php'], $paths($map->files()));
    } finally {
        $rmrf($root);
    }
});

test('scan: le directory escluse custom si sommano ai default (merge)', function () use ($mkroot, $ws, $rmrf, $paths) {
    $root = $mkroot();
    mkdir($root . '/build');
    mkdir($root . '/vendor'); // default: deve restare escluso anche con lista custom
    file_put_contents($root . '/build/out.php', "<?php\n");
    file_put_contents($root . '/vendor/lib.php', "<?php\n");
    file_put_contents($root . '/keep.php', "<?php\n");
    try {
        $map = (new RepoScanner(excludedDirs: ['build']))->scan($ws($root));
        assertSame(['keep.php'], $paths($map->files())); // build E vendor esclusi
    } finally {
        $rmrf($root);
    }
});

test('scan: con maxSeconds=0 la mappa e\' troncata (timeout deterministico)', function () use ($mkroot, $ws, $rmrf) {
    $root = $mkroot();
    file_put_contents($root . '/a.php', "<?php\n");
    file_put_contents($root . '/b.php', "<?php\n");
    try {
        $map = (new RepoScanner(maxSeconds: 0.0))->scan($ws($root));
        assertSame(true, $map->isTruncated());
    } finally {
        $rmrf($root);
    }
});

test('scan: extractionAllowed nega dimensione sconosciuta e oltre soglia', function () {
    assertSame(false, RepoScanner::extractionAllowed(-1, 100)); // sconosciuta => negata
    assertSame(true, RepoScanner::extractionAllowed(0, 100));
    assertSame(true, RepoScanner::extractionAllowed(100, 100)); // confine esatto
    assertSame(false, RepoScanner::extractionAllowed(101, 100));
});
