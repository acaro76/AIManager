<?php

declare(strict_types=1);

use App\Core\Code\CodeChatSchema;
use App\Core\Code\CodePatchSchema;
use App\Core\Code\CodeVerificationSchema;
use App\Core\Database;
use App\Services\MigrationRunner;

// Fase 5 — la migrazione 035 (code_verification_runs) su SQLite TEMPORANEO. Mai il DB reale.
// Copre: catena 001→035 (schema READY, verify vuoto, FK e CHECK attesi), additività (le tabelle
// chat/patch restano verificate) ed esecuzione diretta con schema di base incompleto (fail-closed).

$makeChainDb = static function (): array {
    $path = sys_get_temp_dir() . '/aimanager_mig035_' . uniqid('', true) . '.sqlite';
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

test('035: catena 001→035 crea code_verification_runs, schema READY', function () use ($makeChainDb) {
    [$db, $cleanup] = $makeChainDb();
    try {
        assertSame(true, CodeVerificationSchema::tableExists($db, CodeVerificationSchema::TABLE));
        assertSame([], CodeVerificationSchema::verify($db));
        assertSame(CodeVerificationSchema::STATE_READY, CodeVerificationSchema::state($db));
    } finally {
        $cleanup();
    }
});

test('035: additiva — chat e patch restano verificate', function () use ($makeChainDb) {
    [$db, $cleanup] = $makeChainDb();
    try {
        assertSame([], CodeChatSchema::verify($db));
        assertSame([], CodePatchSchema::verify($db));
    } finally {
        $cleanup();
    }
});

test('035: CHECK(outcome) rifiuta un esito fuori vocabolario', function () use ($makeChainDb, $throws) {
    [$db, $cleanup] = $makeChainDb();
    try {
        $now = date('c');
        $db->execute('INSERT INTO code_workspaces (root_path, name, status, authorized_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', ['/tmp/wv', '', 'active', $now, $now, $now]);
        $wid = $db->lastInsertId();
        $db->execute('INSERT INTO code_sessions (workspace_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$wid, 't', 'active', $now, $now]);
        $sid = $db->lastInsertId();

        $rejected = $throws(static function () use ($db, $wid, $sid, $now): void {
            $db->execute(
                'INSERT INTO code_verification_runs (workspace_id, code_session_id, profile_id, language, kind, outcome, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$wid, $sid, 'php-lint', 'php', 'lint', 'bogus', $now]
            );
        });
        assertSame(true, $rejected);
    } finally {
        $cleanup();
    }
});

test('035: CHECK(language) e CHECK(kind) impongono i vocabolari chiusi', function () use ($makeChainDb, $throws) {
    [$db, $cleanup] = $makeChainDb();
    try {
        $now = date('c');
        $db->execute('INSERT INTO code_workspaces (root_path, name, status, authorized_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', ['/tmp/wv2', '', 'active', $now, $now, $now]);
        $wid = $db->lastInsertId();
        $db->execute('INSERT INTO code_sessions (workspace_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$wid, 't', 'active', $now, $now]);
        $sid = $db->lastInsertId();

        assertSame(true, $throws(static fn () => $db->execute(
            'INSERT INTO code_verification_runs (workspace_id, code_session_id, profile_id, language, kind, outcome, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$wid, $sid, 'x', 'ruby', 'lint', 'passed', $now]
        )));
        assertSame(true, $throws(static fn () => $db->execute(
            'INSERT INTO code_verification_runs (workspace_id, code_session_id, profile_id, language, kind, outcome, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$wid, $sid, 'x', 'php', 'git', 'passed', $now]
        )));
    } finally {
        $cleanup();
    }
});

test('035: eseguita con schema di base incompleto fallisce chiusa', function () use ($throws) {
    $path = sys_get_temp_dir() . '/aimanager_mig035_bare_' . uniqid('', true) . '.sqlite';
    $db = new Database($path);
    try {
        $migration = require dirname(__DIR__) . '/database/migrations/035_create_code_verification_runs.php';
        $failed = $throws(static fn () => $migration($db));
        assertSame(true, $failed);
        assertSame(false, CodeVerificationSchema::tableExists($db, CodeVerificationSchema::TABLE));
    } finally {
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
});
