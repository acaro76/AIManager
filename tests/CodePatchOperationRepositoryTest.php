<?php

declare(strict_types=1);

use App\Core\Code\CodePatchOperationRepository;
use App\Core\Code\CodeWorkspaceException;
use App\Core\Database;
use App\Services\MigrationRunner;

// Fase 4 / F4.4 — repository del ciclo di vita delle operazioni: scoped, metadati soli,
// transizioni atomiche e guardate (monouso), scadenza. SQLite temporaneo, mai il DB reale.

$make = static function (): array {
    $path = sys_get_temp_dir() . '/aimanager_patchrepo_' . uniqid('', true) . '.sqlite';
    $db = new Database($path);
    $root = dirname(__DIR__);
    (new MigrationRunner($db, $root . '/database/migrations', $root . '/database/seeds'))->run();
    $now = date('c');
    $db->execute('INSERT INTO code_workspaces (root_path, name, status, authorized_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', ['/tmp/w1', '', 'active', $now, $now, $now]);
    $wid = $db->lastInsertId();
    $db->execute('INSERT INTO code_sessions (workspace_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$wid, 't', 'active', $now, $now]);
    $sid = $db->lastInsertId();
    // secondo workspace/sessione per i test di scope
    $db->execute('INSERT INTO code_workspaces (root_path, name, status, authorized_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', ['/tmp/w2', '', 'active', $now, $now, $now]);
    $wid2 = $db->lastInsertId();
    $db->execute('INSERT INTO code_sessions (workspace_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$wid2, 't', 'active', $now, $now]);
    $sid2 = $db->lastInsertId();
    $cleanup = static function () use ($path): void {
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    };
    return [$db, $wid, $sid, $wid2, $sid2, $cleanup];
};

$files = [['path' => 'app/Foo.php', 'op' => 'update', 'base_sha256' => str_repeat('a', 64), 'result_sha256' => str_repeat('b', 64)]];
$digest = str_repeat('c', 64);

$throws = static function (callable $fn): bool {
    try {
        $fn();
        return false;
    } catch (\Throwable $e) {
        return true;
    }
};

test('repo: create scoped registra proposta, find/findForScope', function () use ($make, $files, $digest) {
    [$db, $wid, $sid, , , $cleanup] = $make();
    try {
        $repo = new CodePatchOperationRepository($db);
        $repo->create('op-aaaaaaaaaaaaaaaa', $wid, $sid, null, $digest, $files, 1800);
        $row = $repo->find('op-aaaaaaaaaaaaaaaa');
        assertSame('proposed', (string) $row['status']);
        assertSame($digest, (string) $row['patch_digest']);
        assertSame(true, $repo->findForScope('op-aaaaaaaaaaaaaaaa', $wid, $sid) !== null);
    } finally {
        $cleanup();
    }
});

test('repo: create rifiutato se la sessione è di un altro workspace', function () use ($make, $files, $digest) {
    [$db, $wid, , , $sid2, $cleanup] = $make();
    try {
        $repo = new CodePatchOperationRepository($db);
        $caught = false;
        try {
            $repo->create('op-bbbbbbbbbbbbbbbb', $wid, $sid2, null, $digest, $files, 1800);
        } catch (CodeWorkspaceException $e) {
            $caught = true;
        }
        assertSame(true, $caught);
        assertSame(null, $repo->find('op-bbbbbbbbbbbbbbbb'));
    } finally {
        $cleanup();
    }
});

test('repo: findForScope non attraversa i workspace', function () use ($make, $files, $digest) {
    [$db, $wid, $sid, $wid2, $sid2, $cleanup] = $make();
    try {
        $repo = new CodePatchOperationRepository($db);
        $repo->create('op-cccccccccccccccc', $wid, $sid, null, $digest, $files, 1800);
        assertSame(null, $repo->findForScope('op-cccccccccccccccc', $wid2, $sid2));
    } finally {
        $cleanup();
    }
});

test('repo: transition guardata è monouso (una sola vince)', function () use ($make, $files, $digest) {
    [$db, $wid, $sid, , , $cleanup] = $make();
    try {
        $repo = new CodePatchOperationRepository($db);
        $repo->create('op-dddddddddddddddd', $wid, $sid, null, $digest, $files, 1800);
        // prima transizione proposed→applying riesce
        assertSame(true, $repo->transition('op-dddddddddddddddd', ['proposed'], 'applying'));
        // una seconda proposed→applying NON riesce (stato ora applying)
        assertSame(false, $repo->transition('op-dddddddddddddddd', ['proposed'], 'applying'));
        assertSame(true, $repo->transition('op-dddddddddddddddd', ['applying'], 'applied', true));
        $row = $repo->find('op-dddddddddddddddd');
        assertSame('applied', (string) $row['status']);
        assertSame(true, $row['applied_at'] !== null);
    } finally {
        $cleanup();
    }
});

test('repo: expireIfDue scade solo una proposta scaduta e ancora proposed', function () use ($make, $files, $digest) {
    [$db, $wid, $sid, , , $cleanup] = $make();
    try {
        $repo = new CodePatchOperationRepository($db);
        $repo->create('op-eeeeeeeeeeeeeeee', $wid, $sid, null, $digest, $files, 1);
        // non ancora scaduta
        assertSame(false, $repo->expireIfDue('op-eeeeeeeeeeeeeeee', time() - 10));
        // scaduta
        assertSame(true, $repo->expireIfDue('op-eeeeeeeeeeeeeeee', time() + 10));
        assertSame('expired', (string) $repo->find('op-eeeeeeeeeeeeeeee')['status']);
        // già expired: non ri-scade
        assertSame(false, $repo->expireIfDue('op-eeeeeeeeeeeeeeee', time() + 10));
    } finally {
        $cleanup();
    }
});

test('repo: listForSession filtra per stato e scope', function () use ($make, $files, $digest) {
    [$db, $wid, $sid, , , $cleanup] = $make();
    try {
        $repo = new CodePatchOperationRepository($db);
        $repo->create('op-ffffffffffffffff', $wid, $sid, null, $digest, $files, 1800);
        $repo->create('op-gggggggggggggggg', $wid, $sid, null, $digest, $files, 1800);
        $repo->transition('op-gggggggggggggggg', ['proposed'], 'rejected');
        $proposed = $repo->listForSession($wid, $sid, ['proposed']);
        assertSame(1, count($proposed));
        assertSame('op-ffffffffffffffff', (string) $proposed[0]['operation_id']);
    } finally {
        $cleanup();
    }
});

test('repo: input non validi rifiutati (operation_id, digest, hash, op)', function () use ($make, $throws) {
    [$db, $wid, $sid, , , $cleanup] = $make();
    try {
        $repo = new CodePatchOperationRepository($db);
        $good = [['path' => 'a.php', 'op' => 'update', 'base_sha256' => str_repeat('a', 64), 'result_sha256' => str_repeat('b', 64)]];
        // operation_id troppo corto
        assertSame(true, $throws(fn () => $repo->create('short', $wid, $sid, null, str_repeat('c', 64), $good, 1800)));
        // digest non esadecimale a 64
        assertSame(true, $throws(fn () => $repo->create('op-hhhhhhhhhhhhhhhh', $wid, $sid, null, 'nope', $good, 1800)));
        // op fuori vocabolario
        $badOp = [['path' => 'a.php', 'op' => 'delete', 'base_sha256' => null, 'result_sha256' => str_repeat('b', 64)]];
        assertSame(true, $throws(fn () => $repo->create('op-iiiiiiiiiiiiiiii', $wid, $sid, null, str_repeat('c', 64), $badOp, 1800)));
        // path non relativo
        $badPath = [['path' => '/etc/x', 'op' => 'create', 'base_sha256' => null, 'result_sha256' => str_repeat('b', 64)]];
        assertSame(true, $throws(fn () => $repo->create('op-jjjjjjjjjjjjjjjj', $wid, $sid, null, str_repeat('c', 64), $badPath, 1800)));
    } finally {
        $cleanup();
    }
});
