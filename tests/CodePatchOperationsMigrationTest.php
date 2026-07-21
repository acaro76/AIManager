<?php

declare(strict_types=1);

use App\Core\Code\CodePatchSchema;
use App\Core\Database;
use App\Services\MigrationRunner;

// Fase 4 / F4.4 — la migrazione 034 (code_patch_operations) su SQLite TEMPORANEO. Non tocca mai
// il DB reale. Copre: catena completa 001→034 (schema READY, verify vuoto, FK e CHECK attesi) e
// esecuzione diretta con schema di base incompleto (fail-closed, nessuna modifica).

$makeChainDb = static function (): array {
    $path = sys_get_temp_dir() . '/aimanager_mig034_' . uniqid('', true) . '.sqlite';
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

$throws = static function (callable $fn): bool {
    try {
        $fn();
        return false;
    } catch (\Throwable $e) {
        return true;
    }
};

test('034: catena 001→034 crea code_patch_operations, schema READY', function () use ($makeChainDb) {
    [$db, $cleanup] = $makeChainDb();
    try {
        assertSame(true, CodePatchSchema::tableExists($db, CodePatchSchema::TABLE));
        assertSame([], CodePatchSchema::verify($db));
        assertSame(CodePatchSchema::STATE_READY, CodePatchSchema::state($db));
    } finally {
        $cleanup();
    }
});

test('034: le quattro tabelle chat restano verificate (034 è additiva)', function () use ($makeChainDb) {
    [$db, $cleanup] = $makeChainDb();
    try {
        // CodeChatSchema non conosce la nuova tabella: la sua verifica deve restare vuota.
        assertSame([], \App\Core\Code\CodeChatSchema::verify($db));
    } finally {
        $cleanup();
    }
});

test('034: CHECK(status) rifiuta uno stato fuori vocabolario', function () use ($makeChainDb, $throws) {
    [$db, $cleanup] = $makeChainDb();
    try {
        $now = date('c');
        // serve un workspace + sessione per le FK
        $db->execute('INSERT INTO code_workspaces (root_path, name, status, authorized_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', ['/tmp/w', '', 'active', $now, $now, $now]);
        $wid = $db->lastInsertId();
        $db->execute('INSERT INTO code_sessions (workspace_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$wid, 't', 'active', $now, $now]);
        $sid = $db->lastInsertId();

        $rejected = $throws(static function () use ($db, $wid, $sid, $now): void {
            $db->execute(
                'INSERT INTO code_patch_operations (operation_id, workspace_id, code_session_id, patch_digest, status, files_json, created_at, updated_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                ['op-abcdef012345', $wid, $sid, str_repeat('a', 64), 'bogus', '[]', $now, $now, $now]
            );
        });
        assertSame(true, $rejected);
    } finally {
        $cleanup();
    }
});

test('034: eseguita con schema di base incompleto fallisce chiusa', function () use ($throws) {
    $path = sys_get_temp_dir() . '/aimanager_mig034_bare_' . uniqid('', true) . '.sqlite';
    $db = new Database($path);
    try {
        $migration = require dirname(__DIR__) . '/database/migrations/034_create_code_patch_operations.php';
        $failed = $throws(static fn () => $migration($db));
        assertSame(true, $failed);
        assertSame(false, CodePatchSchema::tableExists($db, CodePatchSchema::TABLE));
    } finally {
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
});
