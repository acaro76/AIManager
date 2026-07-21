<?php

declare(strict_types=1);

use App\Core\Code\CommandResult;
use App\Core\Code\CommandRunRepository;
use App\Core\Database;
use App\Services\MigrationRunner;

// Fase 6 — lifecycle persistente su code_command_runs: transizioni MONOUSO, denied/reject/expire,
// recovery orfani (solo DB), forHistory. SQLite temporaneo, mai il DB reale.

$make = static function (): array {
    $path = sys_get_temp_dir() . '/aimanager_clife_' . uniqid('', true) . '.sqlite';
    $db = new Database($path);
    $root = dirname(__DIR__);
    (new MigrationRunner($db, $root . '/database/migrations', $root . '/database/seeds'))->run();
    $now = date('c');
    $db->execute('INSERT INTO code_workspaces (root_path, name, status, authorized_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', ['/tmp/clife', '', 'active', $now, $now, $now]);
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

$pending = static function (Database $db, int $wid, int $sid, int $aid, string $commandId, string $digest = 'digest1', int $pv = 1): void {
    (new CommandRunRepository($db))->createPending($wid, $sid, $aid, $commandId, $digest, $pv, 'cat', 'cat a.php');
};

test('lifecycle: createPending → claim (running) → finalize (completed)', function () use ($make, $pending) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $repo = new CommandRunRepository($db);
        $pending($db, $wid, $sid, $aid, 'cmd-aaaaaaaaaaaaaaaa');
        assertSame(true, $repo->claimForExecution('cmd-aaaaaaaaaaaaaaaa', $wid, $sid, 'digest1', 1, 'exec-1'));
        $repo->finalize('cmd-aaaaaaaaaaaaaaaa', $wid, $sid, new CommandResult(CommandResult::COMPLETED, 0, 'out', 12, 3, false, false), 4242);
        $row = $db->fetch('SELECT * FROM code_command_runs WHERE command_id = ?', ['cmd-aaaaaaaaaaaaaaaa']);
        assertSame('completed', (string) $row['state']);
        assertSame(0, (int) $row['exit_code']);
        assertSame(4242, (int) $row['process_group_id']);
        assertSame('exec-1', (string) $row['execution_id']);
        assertSame(true, $row['confirmed_at'] !== null && $row['finished_at'] !== null);
    } finally { $cleanup(); }
});

test('lifecycle: claim è MONOUSO — la seconda conferma non riavvia nulla', function () use ($make, $pending) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $repo = new CommandRunRepository($db);
        $pending($db, $wid, $sid, $aid, 'cmd-bbbbbbbbbbbbbbbb');
        assertSame(true, $repo->claimForExecution('cmd-bbbbbbbbbbbbbbbb', $wid, $sid, 'digest1', 1, 'exec-1'));
        assertSame(false, $repo->claimForExecution('cmd-bbbbbbbbbbbbbbbb', $wid, $sid, 'digest1', 1, 'exec-2'));
    } finally { $cleanup(); }
});

test('lifecycle: claim negato con digest o policy_version diversi', function () use ($make, $pending) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $repo = new CommandRunRepository($db);
        $pending($db, $wid, $sid, $aid, 'cmd-cccccccccccccccc', 'digestX', 1);
        assertSame(false, $repo->claimForExecution('cmd-cccccccccccccccc', $wid, $sid, 'WRONG', 1, 'e'));
        assertSame(false, $repo->claimForExecution('cmd-cccccccccccccccc', $wid, $sid, 'digestX', 2, 'e'));
        assertSame(true, $repo->claimForExecution('cmd-cccccccccccccccc', $wid, $sid, 'digestX', 1, 'e'));
    } finally { $cleanup(); }
});

test('lifecycle: markStarted persiste pgid + started_at MENTRE è running (non solo in finalize)', function () use ($make, $pending) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $repo = new CommandRunRepository($db);
        $pending($db, $wid, $sid, $aid, 'cmd-jjjjjjjjjjjjjjjj');
        $repo->claimForExecution('cmd-jjjjjjjjjjjjjjjj', $wid, $sid, 'digest1', 1, 'exec-run');
        // Dopo il claim: running, pgid ancora assente.
        $row = $db->fetch('SELECT * FROM code_command_runs WHERE command_id = ?', ['cmd-jjjjjjjjjjjjjjjj']);
        assertSame('running', (string) $row['state']);
        assertSame(null, $row['process_group_id']);
        // markStarted: pgid persistito mentre è ANCORA running (non serve finalize).
        assertSame(true, $repo->markStarted('cmd-jjjjjjjjjjjjjjjj', $wid, $sid, 'exec-run', 9999));
        $row = $db->fetch('SELECT * FROM code_command_runs WHERE command_id = ?', ['cmd-jjjjjjjjjjjjjjjj']);
        assertSame('running', (string) $row['state']);
        assertSame(9999, (int) $row['process_group_id']);
        assertSame(true, $row['started_at'] !== null);
        // Scope: una execution_id diversa NON tocca la riga.
        assertSame(false, $repo->markStarted('cmd-jjjjjjjjjjjjjjjj', $wid, $sid, 'exec-ALTRA', 1));
        assertSame(9999, (int) $db->fetch('SELECT process_group_id FROM code_command_runs WHERE command_id = ?', ['cmd-jjjjjjjjjjjjjjjj'])['process_group_id']);
    } finally { $cleanup(); }
});

test('lifecycle: reject (pending→rejected) e markDenied (pending→denied)', function () use ($make, $pending) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $repo = new CommandRunRepository($db);
        $pending($db, $wid, $sid, $aid, 'cmd-dddddddddddddddd');
        assertSame(true, $repo->reject('cmd-dddddddddddddddd', $wid, $sid));
        assertSame('rejected', (string) $db->fetch('SELECT state FROM code_command_runs WHERE command_id = ?', ['cmd-dddddddddddddddd'])['state']);

        $pending($db, $wid, $sid, $aid, 'cmd-eeeeeeeeeeeeeeee');
        $repo->markDenied('cmd-eeeeeeeeeeeeeeee', $wid, $sid);
        assertSame('denied', (string) $db->fetch('SELECT state FROM code_command_runs WHERE command_id = ?', ['cmd-eeeeeeeeeeeeeeee'])['state']);
    } finally { $cleanup(); }
});

test('lifecycle: expirePending (TTL) e recoverOrphans (running→error, solo DB)', function () use ($make, $pending) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $repo = new CommandRunRepository($db);
        // Pending vecchia → expired.
        $pending($db, $wid, $sid, $aid, 'cmd-ffffffffffffffff');
        $db->execute('UPDATE code_command_runs SET created_at = ? WHERE command_id = ?', [date('c', time() - 5000), 'cmd-ffffffffffffffff']);
        assertSame(1, $repo->expirePending(900));
        assertSame('expired', (string) $db->fetch('SELECT state FROM code_command_runs WHERE command_id = ?', ['cmd-ffffffffffffffff'])['state']);

        // Running orfana (confirmed_at vecchio) → error. Una running RECENTE non viene spazzata.
        $pending($db, $wid, $sid, $aid, 'cmd-gggggggggggggggg');
        $repo->claimForExecution('cmd-gggggggggggggggg', $wid, $sid, 'digest1', 1, 'exec-orph');
        assertSame(0, $repo->recoverOrphans(300)); // appena reclamata: NON orfana
        $db->execute('UPDATE code_command_runs SET confirmed_at = ? WHERE command_id = ?', [date('c', time() - 5000), 'cmd-gggggggggggggggg']);
        assertSame(1, $repo->recoverOrphans(300));
        assertSame('error', (string) $db->fetch('SELECT state FROM code_command_runs WHERE command_id = ?', ['cmd-gggggggggggggggg'])['state']);
    } finally { $cleanup(); }
});

test('lifecycle: forHistory raggruppa per turno assistant con card sanificata', function () use ($make, $pending) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $repo = new CommandRunRepository($db);
        $pending($db, $wid, $sid, $aid, 'cmd-hhhhhhhhhhhhhhhh');
        $repo->claimForExecution('cmd-hhhhhhhhhhhhhhhh', $wid, $sid, 'digest1', 1, 'e');
        $repo->finalize('cmd-hhhhhhhhhhhhhhhh', $wid, $sid, new CommandResult(CommandResult::FAILED, 2, 'out', 1, 1, false, false), 1);
        $hist = $repo->forHistory($wid, $sid);
        assertSame(true, isset($hist[$aid]));
        assertSame('failed', $hist[$aid][0]['state']);
        assertSame('fallito (exit 2)', $hist[$aid][0]['label']);
        assertSame('cat a.php', $hist[$aid][0]['display_summary']);
        assertSame('', $hist[$aid][0]['digest']); // digest solo per pending
    } finally { $cleanup(); }
});

test('lifecycle: createPending su sessione fuori scope → nessuna riga (CodeWorkspaceException)', function () use ($make) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $threw = false;
        try {
            (new CommandRunRepository($db))->createPending($wid, $sid + 999, $aid, 'cmd-iiiiiiiiiiiiiiii', 'd', 1, 'cat', 'cat a.php');
        } catch (\App\Core\Code\CodeWorkspaceException) { $threw = true; }
        assertSame(true, $threw);
    } finally { $cleanup(); }
});
