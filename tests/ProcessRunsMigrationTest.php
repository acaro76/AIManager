<?php

declare(strict_types=1);

use App\Core\Code\CodeChatSchema;
use App\Core\Code\CodePatchSchema;
use App\Core\Code\CodeVerificationSchema;
use App\Core\Code\CommandRunSchema;
use App\Core\Code\ProcessRunSchema;
use App\Core\Database;
use App\Services\MigrationRunner;

// Fase 7 — la migrazione 038 (code_processes) su SQLite TEMPORANEO. Mai il DB reale.
// Copre: catena 001→038 (schema READY, verify vuoto, FK e CHECK attesi), additività (chat/patch/
// verifiche/comandi restano verificati) e i vincoli CHECK(state)/CHECK(host).

$makeChainDb = static function (): array {
    $path = sys_get_temp_dir() . '/aimanager_mig038_' . uniqid('', true) . '.sqlite';
    $db = new Database($path);
    $root = dirname(__DIR__);
    (new MigrationRunner($db, $root . '/database/migrations', $root . '/database/seeds'))->run();
    $cleanup = static function () use ($path): void {
        foreach ([$path, $path . '-wal', $path . '-shm'] as $f) { if (is_file($f)) { @unlink($f); } }
    };
    return [$db, $cleanup];
};

$throws = static function (callable $fn): bool {
    try { $fn(); return false; } catch (\Throwable) { return true; }
};

test('038: catena 001→038 crea code_processes, schema READY', function () use ($makeChainDb) {
    [$db, $cleanup] = $makeChainDb();
    try {
        assertSame(true, ProcessRunSchema::tableExists($db, ProcessRunSchema::TABLE));
        assertSame([], ProcessRunSchema::verify($db));
        assertSame(ProcessRunSchema::STATE_READY, ProcessRunSchema::state($db));
    } finally { $cleanup(); }
});

test('038: additiva — chat, patch, verifiche e comandi restano verificati', function () use ($makeChainDb) {
    [$db, $cleanup] = $makeChainDb();
    try {
        assertSame([], CodeChatSchema::verify($db));
        assertSame([], CodePatchSchema::verify($db));
        assertSame([], CodeVerificationSchema::verify($db));
        assertSame([], CommandRunSchema::verify($db));
    } finally { $cleanup(); }
});

test('038: CHECK(state) rifiuta uno stato fuori vocabolario', function () use ($makeChainDb, $throws) {
    [$db, $cleanup] = $makeChainDb();
    try {
        $bad = $throws(static function () use ($db): void {
            $db->execute(
                'INSERT INTO code_processes (workspace_id, code_session_id, process_id, digest, policy_version, profile_id, program, display_summary, host, port, directory, state, created_at)
                 VALUES (1, 1, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?)',
                ['proc-xxxxxxxxxxxxxxxx', 'd', 'php-server', 'php', 'x', '127.0.0.1', 8000, '', 'bogus_state', date('c')]
            );
        });
        assertSame(true, $bad);
    } finally { $cleanup(); }
});

test('038: CHECK(host) rifiuta un host diverso da 127.0.0.1 (nessun bind pubblico persistibile)', function () use ($makeChainDb, $throws) {
    [$db, $cleanup] = $makeChainDb();
    try {
        $bad = $throws(static function () use ($db): void {
            $db->execute(
                'INSERT INTO code_processes (workspace_id, code_session_id, process_id, digest, policy_version, profile_id, program, display_summary, host, port, directory, state, created_at)
                 VALUES (1, 1, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?)',
                ['proc-yyyyyyyyyyyyyyyy', 'd', 'php-server', 'php', 'x', '0.0.0.0', 8000, '', 'pending', date('c')]
            );
        });
        assertSame(true, $bad);
    } finally { $cleanup(); }
});

test('038: integrità e foreign_key_check puliti dopo la migrazione', function () use ($makeChainDb) {
    [$db, $cleanup] = $makeChainDb();
    try {
        $ic = $db->fetchAll('PRAGMA integrity_check');
        assertSame('ok', (string) ($ic[0]['integrity_check'] ?? ''));
        assertSame(0, count($db->fetchAll('PRAGMA foreign_key_check')));
    } finally { $cleanup(); }
});

test('038: idempotente — una seconda esecuzione non altera lo schema', function () use ($makeChainDb) {
    [$db, $cleanup] = $makeChainDb();
    try {
        $root = dirname(__DIR__);
        (new MigrationRunner($db, $root . '/database/migrations', $root . '/database/seeds'))->run();
        assertSame([], ProcessRunSchema::verify($db));
    } finally { $cleanup(); }
});
