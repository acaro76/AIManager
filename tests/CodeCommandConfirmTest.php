<?php

declare(strict_types=1);

use App\Core\Cancellation\CancellationStore;
use App\Core\Code\CommandConfirmService;
use App\Core\Code\CommandPlan;
use App\Core\Code\CommandProgramResolver;
use App\Core\Code\CommandRegistry;
use App\Core\Code\CommandRunner;
use App\Core\Code\CommandRunRepository;
use App\Core\Code\CommandStore;
use App\Core\Database;
use App\Services\MigrationRunner;

// Fase 6 — conferma ed esecuzione end-to-end: pending → confirm (cat reale) → completed; rifiuto;
// digest stale → denied; monouso (replay); scaduto. SQLite + cartella temporanei, mai il DB reale.

if (!CommandRunner::supportsProcessGroupIsolation()) {
    test('CommandConfirm: process group non disponibile (skip)', function () {
        assertSame(false, CommandRunner::supportsProcessGroupIsolation());
    });
    return;
}

$rmrf = static function (string $p) use (&$rmrf): void {
    if (is_dir($p) && !is_link($p)) {
        foreach (scandir($p) ?: [] as $e) { if ($e === '.' || $e === '..') { continue; } $rmrf($p . '/' . $e); }
        @rmdir($p);
        return;
    }
    @unlink($p);
};

// Prepara DB + workspace reale + una proposta PENDING coerente (store + riga), come persistCommandTurn.
$make = static function (array $args = ['hello.txt'], string $digestOverride = '') use ($rmrf): array {
    $base = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/aim_cconf_' . uniqid('', true);
    mkdir($base . '/ws', 0777, true);
    file_put_contents($base . '/ws/hello.txt', "ciao mondo\n");
    $root = realpath($base . '/ws');
    $storageDir = $base . '/code_commands';
    $runtimeDir = $base . '/code_runtime';

    $dbPath = $base . '/db.sqlite';
    $db = new Database($dbPath);
    $repoRoot = dirname(__DIR__);
    (new MigrationRunner($db, $repoRoot . '/database/migrations', $repoRoot . '/database/seeds'))->run();
    $now = date('c');
    $db->execute('INSERT INTO code_workspaces (root_path, name, status, authorized_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', [$root, '', 'active', $now, $now, $now]);
    $wid = $db->lastInsertId();
    $db->execute('INSERT INTO code_sessions (workspace_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$wid, 't', 'active', $now, $now]);
    $sid = $db->lastInsertId();
    $db->execute('INSERT INTO code_conversations (code_session_id, role, content, provider, created_at) VALUES (?, ?, ?, ?, ?)', [$sid, 'assistant', 'x', 'code', $now]);
    $aid = $db->lastInsertId();

    $registry = new CommandRegistry();
    $resolver = new CommandProgramResolver();
    $exe = $resolver->resolve('cat');
    $plan = new CommandPlan('cat', [], null, $args);
    $pv = $registry->policyVersion();
    $digest = $digestOverride !== '' ? $digestOverride : $plan->digest($root, $wid, $sid, $pv);
    $commandId = 'cmd-' . bin2hex(random_bytes(12));

    (new CommandStore($storageDir))->write($commandId, $plan->digest($root, $wid, $sid, $pv), $pv, (string) $exe, $plan->toStore());
    (new CommandRunRepository($db))->createPending($wid, $sid, $aid, $commandId, $digest, $pv, 'cat', $plan->displaySummary(400));

    $token = (new CancellationStore($base . '/cancel'))->token($commandId);
    $svc = new CommandConfirmService($db, $storageDir, $runtimeDir);
    $cleanup = static function () use ($rmrf, $base): void { $rmrf($base); };

    return compact('db', 'wid', 'sid', 'commandId', 'digest', 'token', 'svc', 'exe') + ['cleanup' => $cleanup];
};

test('confirm: pending → conferma → esecuzione reale (cat) → completed', function () use ($make) {
    ['exe' => $exe] = $c = $make();
    try {
        if ($exe === null) { assertSame(true, true); return; }
        $res = $c['svc']->confirm($c['wid'], $c['sid'], $c['commandId'], $c['digest'], $c['token']);
        assertSame('completed', $res['status']);
        assertSame(true, $res['ok']);
        assertSame(true, str_contains($res['output'], 'ciao mondo'));
        $row = $c['db']->fetch('SELECT * FROM code_command_runs WHERE command_id = ?', [$c['commandId']]);
        assertSame('completed', (string) $row['state']);
        // process_group_id persistito (via onStarted mentre running, poi finalize) + timestamp.
        assertSame(true, $row['process_group_id'] !== null && (int) $row['process_group_id'] > 1);
        assertSame(true, $row['started_at'] !== null && $row['finished_at'] !== null);
    } finally { $c['cleanup'](); }
});

test('confirm: MONOUSO — una seconda conferma non riesegue (not_found)', function () use ($make) {
    ['exe' => $exe] = $c = $make();
    try {
        if ($exe === null) { assertSame(true, true); return; }
        $c['svc']->confirm($c['wid'], $c['sid'], $c['commandId'], $c['digest'], $c['token']);
        $again = $c['svc']->confirm($c['wid'], $c['sid'], $c['commandId'], $c['digest'], $c['token']);
        assertSame('not_found', $again['status']);
    } finally { $c['cleanup'](); }
});

test('confirm: digest STALE → denied, nessuna esecuzione', function () use ($make) {
    ['exe' => $exe] = $c = $make();
    try {
        if ($exe === null) { assertSame(true, true); return; }
        $res = $c['svc']->confirm($c['wid'], $c['sid'], $c['commandId'], 'DIGEST-SBAGLIATO', $c['token']);
        assertSame('denied', $res['status']);
        assertSame('denied', (string) $c['db']->fetch('SELECT state FROM code_command_runs WHERE command_id = ?', [$c['commandId']])['state']);
    } finally { $c['cleanup'](); }
});

test('confirm: rifiuto (reject) → rejected, nessuna esecuzione', function () use ($make) {
    $c = $make();
    try {
        $res = $c['svc']->reject($c['wid'], $c['sid'], $c['commandId']);
        assertSame('rejected', $res['status']);
        assertSame('rejected', (string) $c['db']->fetch('SELECT state FROM code_command_runs WHERE command_id = ?', [$c['commandId']])['state']);
    } finally { $c['cleanup'](); }
});

test('confirm: proposta SCADUTA (TTL) → non eseguita, stato expired', function () use ($make) {
    $c = $make();
    try {
        $c['db']->execute('UPDATE code_command_runs SET created_at = ? WHERE command_id = ?', [date('c', time() - 100000), $c['commandId']]);
        $res = $c['svc']->confirm($c['wid'], $c['sid'], $c['commandId'], $c['digest'], $c['token']);
        assertSame(true, in_array($res['status'], ['not_found', 'expired'], true));
        assertSame('expired', (string) $c['db']->fetch('SELECT state FROM code_command_runs WHERE command_id = ?', [$c['commandId']])['state']);
    } finally { $c['cleanup'](); }
});

test('confirm: bind — un path divenuto sensibile alla conferma → denied', function () use ($make) {
    ['exe' => $exe] = $c = $make(['.env']); // proposta che punta a un file sensibile
    try {
        if ($exe === null) { assertSame(true, true); return; }
        $res = $c['svc']->confirm($c['wid'], $c['sid'], $c['commandId'], $c['digest'], $c['token']);
        assertSame('denied', $res['status']);
    } finally { $c['cleanup'](); }
});

test('confirm: isRunningInScope riconosce solo pending/running nello scope', function () use ($make) {
    $c = $make();
    try {
        assertSame(true, $c['svc']->isRunningInScope($c['commandId'], $c['wid'], $c['sid']));
        assertSame(false, $c['svc']->isRunningInScope($c['commandId'], $c['wid'], $c['sid'] + 999));
        assertSame(false, $c['svc']->isRunningInScope('cmd-zzzzzzzzzzzzzzzz', $c['wid'], $c['sid']));
    } finally { $c['cleanup'](); }
});
