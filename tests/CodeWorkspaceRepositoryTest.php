<?php

declare(strict_types=1);

use App\Core\Code\CodeWorkspaceRepository;
use App\Core\Code\CodeSelfProtection;
use App\Core\Database;
use App\Services\MigrationRunner;

/**
 * F0.b — persistenza autonoma del workspace su SQLite TEMPORANEO (mai il DB reale).
 * Identità per ROOT, nessun riferimento a `projects`.
 */

$makeTempDb = static function (): array {
    $path = sys_get_temp_dir() . '/aimanager_ws_repo_' . uniqid('', true) . '.sqlite';
    $db = new Database($path);
    $root = dirname(__DIR__);
    (new MigrationRunner($db, $root . '/database/migrations', $root . '/database/seeds'))->run();
    $cleanup = static function () use ($path): void {
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    };
    return [$db, $cleanup];
};

$tmpBase = static function (): string {
    $base = realpath(sys_get_temp_dir());
    return $base === false ? sys_get_temp_dir() : $base;
};

$tmpDir = static function () use ($tmpBase): string {
    $dir = $tmpBase() . '/aimanager_ws_root_' . uniqid('', true);
    mkdir($dir, 0777, true);
    return $dir;
};

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

$throws = static function (callable $fn): bool {
    try {
        $fn();
        return false;
    } catch (\Throwable $e) {
        return true;
    }
};

test('repo: authorizeRoot crea un workspace active con root canonica e nome', function () use ($makeTempDb, $tmpDir, $rmrf) {
    [$db, $cleanup] = $makeTempDb();
    $dir = $tmpDir();
    try {
        $ws = (new CodeWorkspaceRepository($db))->authorizeRoot($dir, 'Campione');
        assertSame('active', $ws->status);
        assertSame(rtrim((string) realpath($dir), '/'), $ws->rootPath);
        assertSame('Campione', $ws->name);
        assertSame(1, (int) $db->fetch('SELECT COUNT(*) c FROM code_workspaces')['c']);
    } finally {
        $rmrf($dir);
        $cleanup();
    }
});

test('repo: findById e findByRoot ritornano il workspace', function () use ($makeTempDb, $tmpDir, $rmrf) {
    [$db, $cleanup] = $makeTempDb();
    $dir = $tmpDir();
    try {
        $repo = new CodeWorkspaceRepository($db);
        $ws = $repo->authorizeRoot($dir);
        assertSame($ws->id, $repo->findById($ws->id)?->id);
        assertSame($ws->id, $repo->findByRoot($dir)?->id);
        assertSame(null, $repo->findById(999999));
    } finally {
        $rmrf($dir);
        $cleanup();
    }
});

test('repo: una root gia\' active restituisce lo stesso workspace, senza duplicati', function () use ($makeTempDb, $tmpDir, $rmrf) {
    [$db, $cleanup] = $makeTempDb();
    $dir = $tmpDir();
    try {
        $repo = new CodeWorkspaceRepository($db);
        $a = $repo->authorizeRoot($dir);
        $b = $repo->authorizeRoot($dir);
        assertSame($a->id, $b->id);
        assertSame(1, (int) $db->fetch('SELECT COUNT(*) c FROM code_workspaces')['c']);
    } finally {
        $rmrf($dir);
        $cleanup();
    }
});

test('repo: activeByRecentUse esclude le cartelle revocate e ordina per uso recente', function () use ($makeTempDb, $tmpDir, $rmrf) {
    [$db, $cleanup] = $makeTempDb();
    $aDir = $tmpDir();
    $bDir = $tmpDir();
    try {
        $repo = new CodeWorkspaceRepository($db);
        $a = $repo->authorizeRoot($aDir);
        $b = $repo->authorizeRoot($bDir);
        assertSame([$b->id, $a->id], array_map(static fn ($w): int => $w->id, $repo->activeByRecentUse()));
        $repo->revoke($b->id);
        assertSame([$a->id], array_map(static fn ($w): int => $w->id, $repo->activeByRecentUse()));
    } finally {
        $rmrf($aDir);
        $rmrf($bDir);
        $cleanup();
    }
});

test('repo: una root revoked viene riattivata mantenendo lo stesso id (niente duplicati)', function () use ($makeTempDb, $tmpDir, $rmrf) {
    [$db, $cleanup] = $makeTempDb();
    $dir = $tmpDir();
    try {
        $repo = new CodeWorkspaceRepository($db);
        $a = $repo->authorizeRoot($dir);
        $repo->revoke($a->id);
        assertSame('revoked', $repo->findById($a->id)?->status);

        $b = $repo->authorizeRoot($dir);
        assertSame($a->id, $b->id);
        assertSame('active', $b->status);
        assertSame(1, (int) $db->fetch('SELECT COUNT(*) c FROM code_workspaces')['c']);
    } finally {
        $rmrf($dir);
        $cleanup();
    }
});

test('repo: revoke di un id inesistente fallisce in modo controllato', function () use ($makeTempDb, $throws) {
    [$db, $cleanup] = $makeTempDb();
    try {
        $repo = new CodeWorkspaceRepository($db);
        assertSame(true, $throws(static fn () => $repo->revoke(999999)));
    } finally {
        $cleanup();
    }
});

test('repo: authorizeRoot rifiuta root / , symlink e cartella inesistente', function () use ($makeTempDb, $tmpDir, $tmpBase, $rmrf, $throws) {
    [$db, $cleanup] = $makeTempDb();
    $target = $tmpDir();
    $link = $tmpBase() . '/aimanager_ws_link_' . uniqid('', true);
    symlink($target, $link);
    try {
        $repo = new CodeWorkspaceRepository($db);
        assertSame(true, $throws(static fn () => $repo->authorizeRoot('/')));
        assertSame(true, $throws(static fn () => $repo->authorizeRoot($link)));
        assertSame(true, $throws(static fn () => $repo->authorizeRoot('/percorso/che/non/esiste_' . uniqid())));
    } finally {
        $rmrf($link);
        $rmrf($target);
        $cleanup();
    }
});

test('repo: authorizeRoot rifiuta ogni sovrapposizione con AIManager', function () use ($makeTempDb, $tmpDir, $rmrf, $throws) {
    [$db, $cleanup] = $makeTempDb();
    $base = $tmpDir();
    $appRoot = $base . '/aimanager';
    mkdir($appRoot . '/sub', 0777, true);
    try {
        $repo = new CodeWorkspaceRepository($db, selfProtection: new CodeSelfProtection($appRoot));
        assertSame(true, $throws(static fn () => $repo->authorizeRoot($appRoot)));
        assertSame(true, $throws(static fn () => $repo->authorizeRoot($base)));
        assertSame(true, $throws(static fn () => $repo->authorizeRoot($appRoot . '/sub')));
    } finally {
        $rmrf($base);
        $cleanup();
    }
});

test('repo: flusso standalone authorizeRoot -> lettura -> revoke -> lettura negata', function () use ($makeTempDb, $tmpDir, $rmrf, $throws) {
    [$db, $cleanup] = $makeTempDb();
    $dir = $tmpDir();
    file_put_contents($dir . '/file.php', "<?php echo 1;\n");
    try {
        $repo = new CodeWorkspaceRepository($db);
        $ws = $repo->authorizeRoot($dir);
        // active: lettura consentita dal componente filesystem
        assertSame("<?php echo 1;\n", $ws->read('file.php'));

        $repo->revoke($ws->id);
        $revoked = $repo->findById($ws->id);
        assertSame('revoked', $revoked?->status);
        // la revoca PERSISTITA e' rispettata realmente dal componente filesystem
        assertSame(true, $throws(static fn () => $revoked->read('file.php')));
    } finally {
        $rmrf($dir);
        $cleanup();
    }
});

test('repo: all() e\' deterministico (ordine per id crescente)', function () use ($makeTempDb, $tmpDir, $rmrf) {
    [$db, $cleanup] = $makeTempDb();
    $d1 = $tmpDir();
    $d2 = $tmpDir();
    try {
        $repo = new CodeWorkspaceRepository($db);
        $w1 = $repo->authorizeRoot($d1);
        $w2 = $repo->authorizeRoot($d2);
        $ids = array_map(static fn ($w): int => $w->id, $repo->all());
        assertSame([$w1->id, $w2->id], $ids);
    } finally {
        $rmrf($d1);
        $rmrf($d2);
        $cleanup();
    }
});
