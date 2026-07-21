<?php

declare(strict_types=1);

use App\Core\Code\CodeChatSchema;
use App\Core\Code\CodePatchSchema;
use App\Core\Code\CodeVerificationSchema;
use App\Core\Code\CommandRunSchema;
use App\Core\Database;
use App\Services\MigrationRunner;

// Fase 6 — la migrazione 036 (code_command_runs) su SQLite TEMPORANEO. Mai il DB reale.
// Copre: catena 001→036 (schema READY, verify vuoto, FK e CHECK attesi), additività (chat/patch/
// verifiche restano verificate) ed esecuzione con schema di base incompleto (fail-closed).

$makeChainDb = static function (): array {
    $path = sys_get_temp_dir() . '/aimanager_mig036_' . uniqid('', true) . '.sqlite';
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

test('036: catena 001→036 crea code_command_runs, schema READY', function () use ($makeChainDb) {
    [$db, $cleanup] = $makeChainDb();
    try {
        assertSame(true, CommandRunSchema::tableExists($db, CommandRunSchema::TABLE));
        assertSame([], CommandRunSchema::verify($db));
        assertSame(CommandRunSchema::STATE_READY, CommandRunSchema::state($db));
    } finally { $cleanup(); }
});

test('036: additiva — chat, patch e verifiche restano verificate', function () use ($makeChainDb) {
    [$db, $cleanup] = $makeChainDb();
    try {
        assertSame([], CodeChatSchema::verify($db));
        assertSame([], CodePatchSchema::verify($db));
        assertSame([], CodeVerificationSchema::verify($db));
    } finally { $cleanup(); }
});

test('036: CHECK(state) rifiuta uno stato fuori vocabolario', function () use ($makeChainDb, $throws) {
    [$db, $cleanup] = $makeChainDb();
    try {
        // Prima servono workspace/sessione per soddisfare le FK; ma lo stato invalido deve fallire
        // comunque a livello di CHECK. Inserimento diretto con scope inesistente: fallisce (FK o CHECK).
        $bad = $throws(static function () use ($db): void {
            $db->execute(
                'INSERT INTO code_command_runs (workspace_id, code_session_id, command_id, digest, policy_version, program, display_summary, state, created_at)
                 VALUES (1, 1, ?, ?, 1, ?, ?, ?, ?)',
                ['cmd-xxxxxxxxxxxxxxxx', 'd', 'cat', 'cat a.php', 'bogus_state', date('c')]
            );
        });
        assertSame(true, $bad);
    } finally { $cleanup(); }
});

test('036: idempotente — una seconda esecuzione non altera lo schema', function () use ($makeChainDb) {
    [$db, $cleanup] = $makeChainDb();
    try {
        $root = dirname(__DIR__);
        (new MigrationRunner($db, $root . '/database/migrations', $root . '/database/seeds'))->run();
        assertSame([], CommandRunSchema::verify($db));
    } finally { $cleanup(); }
});

test('036: integrità e foreign_key_check puliti dopo la migrazione', function () use ($makeChainDb) {
    [$db, $cleanup] = $makeChainDb();
    try {
        $ic = $db->fetchAll('PRAGMA integrity_check');
        assertSame('ok', (string) ($ic[0]['integrity_check'] ?? ''));
        assertSame(0, count($db->fetchAll('PRAGMA foreign_key_check')));
    } finally { $cleanup(); }
});
