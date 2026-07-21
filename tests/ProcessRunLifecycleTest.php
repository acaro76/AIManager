<?php

declare(strict_types=1);

use App\Core\Code\ProcessRunRepository;
use App\Core\Database;
use App\Services\MigrationRunner;

// Fase 7 — lifecycle persistente su code_processes: transizioni MONOUSO, denied/reject/expire,
// markRunning con identità, orphaned (solo DB), forHistory. SQLite temporaneo, mai il DB reale.

$make = static function (): array {
    $path = sys_get_temp_dir() . '/aimanager_plife_' . uniqid('', true) . '.sqlite';
    $db = new Database($path);
    $root = dirname(__DIR__);
    (new MigrationRunner($db, $root . '/database/migrations', $root . '/database/seeds'))->run();
    $now = date('c');
    $db->execute('INSERT INTO code_workspaces (root_path, name, status, authorized_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', ['/tmp/plife', '', 'active', $now, $now, $now]);
    $wid = $db->lastInsertId();
    $db->execute('INSERT INTO code_sessions (workspace_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$wid, 't', 'active', $now, $now]);
    $sid = $db->lastInsertId();
    $db->execute('INSERT INTO code_conversations (code_session_id, role, content, provider, created_at) VALUES (?, ?, ?, ?, ?)', [$sid, 'assistant', 'x', 'code', $now]);
    $aid = $db->lastInsertId();
    $cleanup = static function () use ($path): void {
        foreach ([$path, $path . '-wal', $path . '-shm'] as $f) { if (is_file($f)) { @unlink($f); } }
    };
    return [$db, $wid, $sid, $aid, $cleanup];
};

$pending = static function (Database $db, int $wid, int $sid, int $aid, string $processId, string $digest = 'd1', int $pv = 1, int $port = 8000, string $dir = ''): void {
    (new ProcessRunRepository($db))->createPending($wid, $sid, $aid, $processId, $digest, $pv, 'php-server', 'php', 'php-server 127.0.0.1:' . $port . ' ' . ($dir === '' ? '(radice)' : $dir), '127.0.0.1', $port, $dir);
};

test('lifecycle processo: createPending → claim (starting) → markRunning → stop', function () use ($make, $pending) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $repo = new ProcessRunRepository($db);
        $pending($db, $wid, $sid, $aid, 'proc-aaaaaaaaaaaaaaaa');
        assertSame(true, $repo->claimForExecution('proc-aaaaaaaaaaaaaaaa', $wid, $sid, 'd1', 1, 'exec-1'));
        $row = $db->fetch('SELECT * FROM code_processes WHERE process_id = ?', ['proc-aaaaaaaaaaaaaaaa']);
        assertSame('starting', (string) $row['state']);
        assertSame(null, $row['pid']);
        assertSame(true, $repo->markRunning('proc-aaaaaaaaaaaaaaaa', $wid, $sid, 'exec-1', 4242, 4242, 'tok', 'SIG', 'exec-1'));
        $row = $db->fetch('SELECT * FROM code_processes WHERE process_id = ?', ['proc-aaaaaaaaaaaaaaaa']);
        assertSame('running', (string) $row['state']);
        assertSame(4242, (int) $row['pid']);
        assertSame(4242, (int) $row['pgid']);
        assertSame('tok', (string) $row['run_token']);
        assertSame('SIG', (string) $row['start_signature']);
        assertSame(true, $repo->markStopped('proc-aaaaaaaaaaaaaaaa', $wid, $sid));
        assertSame('stopped', (string) $db->fetch('SELECT state FROM code_processes WHERE process_id = ?', ['proc-aaaaaaaaaaaaaaaa'])['state']);
    } finally { $cleanup(); }
});

test('lifecycle processo: claim è MONOUSO e negato con digest/policy diversi', function () use ($make, $pending) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $repo = new ProcessRunRepository($db);
        $pending($db, $wid, $sid, $aid, 'proc-bbbbbbbbbbbbbbbb', 'dX', 1);
        assertSame(false, $repo->claimForExecution('proc-bbbbbbbbbbbbbbbb', $wid, $sid, 'WRONG', 1, 'e'));
        assertSame(false, $repo->claimForExecution('proc-bbbbbbbbbbbbbbbb', $wid, $sid, 'dX', 2, 'e'));
        assertSame(true, $repo->claimForExecution('proc-bbbbbbbbbbbbbbbb', $wid, $sid, 'dX', 1, 'e'));
        // Una seconda conferma non riavvia nulla.
        assertSame(false, $repo->claimForExecution('proc-bbbbbbbbbbbbbbbb', $wid, $sid, 'dX', 1, 'e2'));
    } finally { $cleanup(); }
});

test('lifecycle processo: markRunning è scoped alla execution_id reclamata', function () use ($make, $pending) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $repo = new ProcessRunRepository($db);
        $pending($db, $wid, $sid, $aid, 'proc-cccccccccccccccc');
        $repo->claimForExecution('proc-cccccccccccccccc', $wid, $sid, 'd1', 1, 'exec-run');
        assertSame(false, $repo->markRunning('proc-cccccccccccccccc', $wid, $sid, 'exec-ALTRA', 9, 9, 't', 's', 'l'));
        assertSame('starting', (string) $db->fetch('SELECT state FROM code_processes WHERE process_id = ?', ['proc-cccccccccccccccc'])['state']);
    } finally { $cleanup(); }
});

test('lifecycle processo: reject (pending→rejected) e markDenied (pending→denied)', function () use ($make, $pending) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $repo = new ProcessRunRepository($db);
        $pending($db, $wid, $sid, $aid, 'proc-dddddddddddddddd');
        assertSame(true, $repo->reject('proc-dddddddddddddddd', $wid, $sid));
        assertSame('rejected', (string) $db->fetch('SELECT state FROM code_processes WHERE process_id = ?', ['proc-dddddddddddddddd'])['state']);

        $pending($db, $wid, $sid, $aid, 'proc-eeeeeeeeeeeeeeee');
        $repo->markDenied('proc-eeeeeeeeeeeeeeee', $wid, $sid);
        assertSame('denied', (string) $db->fetch('SELECT state FROM code_processes WHERE process_id = ?', ['proc-eeeeeeeeeeeeeeee'])['state']);
    } finally { $cleanup(); }
});

test('lifecycle processo: expirePending (TTL) e markOrphaned (solo DB, nessun segnale)', function () use ($make, $pending) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $repo = new ProcessRunRepository($db);
        $pending($db, $wid, $sid, $aid, 'proc-ffffffffffffffff');
        $db->execute('UPDATE code_processes SET created_at = ? WHERE process_id = ?', [date('c', time() - 5000), 'proc-ffffffffffffffff']);
        assertSame(1, $repo->expirePending(900));
        assertSame('expired', (string) $db->fetch('SELECT state FROM code_processes WHERE process_id = ?', ['proc-ffffffffffffffff'])['state']);

        $pending($db, $wid, $sid, $aid, 'proc-gggggggggggggggg');
        $repo->claimForExecution('proc-gggggggggggggggg', $wid, $sid, 'd1', 1, 'e');
        $repo->markRunning('proc-gggggggggggggggg', $wid, $sid, 'e', 7, 7, 't', 's', 'e');
        $repo->markOrphaned('proc-gggggggggggggggg', $wid, $sid);
        assertSame('orphaned', (string) $db->fetch('SELECT state FROM code_processes WHERE process_id = ?', ['proc-gggggggggggggggg'])['state']);
    } finally { $cleanup(); }
});

test('lifecycle processo: forHistory raggruppa per turno assistant con card sanificata', function () use ($make, $pending) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $repo = new ProcessRunRepository($db);
        $pending($db, $wid, $sid, $aid, 'proc-hhhhhhhhhhhhhhhh', 'd1', 1, 8080);
        $hist = $repo->forHistory($wid, $sid);
        assertSame(true, isset($hist[$aid]));
        assertSame('pending', $hist[$aid][0]['state']);
        assertSame('127.0.0.1', $hist[$aid][0]['host']);
        assertSame(8080, $hist[$aid][0]['port']);
        assertSame('in attesa di conferma', $hist[$aid][0]['label']);
        assertSame(false, $hist[$aid][0]['can_stop']);
        assertSame('d1', $hist[$aid][0]['digest']); // digest per pending
    } finally { $cleanup(); }
});

test('lifecycle processo: createPending su sessione fuori scope → CodeWorkspaceException', function () use ($make) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $threw = false;
        try {
            (new ProcessRunRepository($db))->createPending($wid, $sid + 999, $aid, 'proc-iiiiiiiiiiiiiiii', 'd', 1, 'php-server', 'php', 's', '127.0.0.1', 8000, '');
        } catch (\App\Core\Code\CodeWorkspaceException) { $threw = true; }
        assertSame(true, $threw);
    } finally { $cleanup(); }
});

test('lifecycle processo: listActive ritorna solo starting/running nello scope', function () use ($make, $pending) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $repo = new ProcessRunRepository($db);
        $pending($db, $wid, $sid, $aid, 'proc-jjjjjjjjjjjjjjjj'); // pending
        $pending($db, $wid, $sid, $aid, 'proc-kkkkkkkkkkkkkkkk');
        $repo->claimForExecution('proc-kkkkkkkkkkkkkkkk', $wid, $sid, 'd1', 1, 'e'); // starting
        $active = $repo->listActive($wid, $sid);
        assertSame(1, count($active));
        assertSame('proc-kkkkkkkkkkkkkkkk', (string) $active[0]['process_id']);
    } finally { $cleanup(); }
});
