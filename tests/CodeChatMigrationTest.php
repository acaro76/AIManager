<?php

declare(strict_types=1);

use App\Core\Code\CodeChatSchema;
use App\Core\Database;
use App\Services\MigrationRunner;

/**
 * F1.7 — migrazione 032 (tabelle chat Code). Test ISOLATI: SQLite temporaneo, mai il DB reale.
 * Verificano che la 032 sia non distruttiva, idempotente e fail-closed.
 */

$throws = static function (callable $fn): bool {
    try { $fn(); return false; } catch (\Throwable $e) { return true; }
};

$root = dirname(__DIR__);

$runChain = static function (Database $db) use ($root): void {
    (new MigrationRunner($db, $root . '/database/migrations', $root . '/database/seeds'))->run();
};

$tmpDb = static function (): Database {
    return new Database(sys_get_temp_dir() . '/aimanager_mig032_' . uniqid('', true) . '.sqlite');
};

test('032→033: la catena produce lo schema chat ed evidenze conforme', function () use ($tmpDb, $runChain) {
    $db = $tmpDb();
    $runChain($db);

    assertSame(CodeChatSchema::STATE_READY, CodeChatSchema::state($db));
    assertSame([], CodeChatSchema::verify($db)); // struttura identica alla specifica attesa
    foreach (['code_sessions', 'code_conversations', 'code_operation_logs', 'code_response_evidence'] as $t) {
        assertSame(true, CodeChatSchema::tableExists($db, $t), $t);
    }
    // la 032 risulta registrata
    $names = array_column($db->fetchAll('SELECT name FROM migrations'), 'name');
    assertSame(true, in_array('032_create_code_chat_tables.php', $names, true));
    assertSame(true, in_array('033_create_code_response_evidence.php', $names, true));
});

test('032: e\' idempotente (una seconda esecuzione non fa nulla e non fallisce)', function () use ($tmpDb, $runChain) {
    $db = $tmpDb();
    $runChain($db);
    $before = $db->fetchAll('SELECT name FROM sqlite_master ORDER BY name');

    $runChain($db); // seconda passata completa

    assertSame(CodeChatSchema::STATE_READY, CodeChatSchema::state($db));
    assertSame([], CodeChatSchema::verify($db));
    assertSame($before, $db->fetchAll('SELECT name FROM sqlite_master ORDER BY name')); // schema invariato
});

test('032: applicata direttamente su schema READY e\' un NO-OP', function () use ($tmpDb, $runChain, $root) {
    $db = $tmpDb();
    $runChain($db); // schema gia' ready

    $migration = require $root . '/database/migrations/032_create_code_chat_tables.php';
    $db->transaction(static fn () => $migration($db)); // come farebbe il MigrationRunner

    assertSame(CodeChatSchema::STATE_READY, CodeChatSchema::state($db));
    assertSame([], CodeChatSchema::verify($db));
});

test('032: FAIL CLOSED su tabella omonima incompatibile, senza toccare i dati', function () use ($tmpDb, $runChain, $root, $throws) {
    // catena fino alla 031, poi una code_sessions "sbagliata" gia' presente
    $db = $tmpDb();
    (new MigrationRunner($db, $root . '/database/migrations', $root . '/database/seeds'))->run();
    // simuliamo lo scenario incompatibile su un DB pulito: ricreiamo lo stato "parziale"
    $db2 = $tmpDb();
    $db2->execute('CREATE TABLE code_workspaces (
        id INTEGER PRIMARY KEY AUTOINCREMENT, root_path TEXT NOT NULL UNIQUE, name TEXT NOT NULL DEFAULT \'\',
        status TEXT NOT NULL DEFAULT \'active\', authorized_at TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
        CHECK(status IN (\'active\',\'revoked\')))');
    $db2->execute('CREATE TABLE code_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, nota TEXT)'); // divergente
    $db2->execute('INSERT INTO code_sessions (nota) VALUES (\'dato preesistente\')');

    assertSame(CodeChatSchema::STATE_INCOMPATIBLE, CodeChatSchema::state($db2));

    $migration = require $root . '/database/migrations/032_create_code_chat_tables.php';
    assertSame(true, $throws(static fn () => $db2->transaction(static fn () => $migration($db2))));

    // i dati preesistenti sono INTATTI e nessuna nuova tabella e' stata creata
    assertSame(1, (int) $db2->fetch('SELECT COUNT(*) c FROM code_sessions')['c']);
    assertSame('dato preesistente', (string) $db2->fetch('SELECT nota FROM code_sessions')['nota']);
    assertSame(false, CodeChatSchema::tableExists($db2, 'code_conversations'));
    assertSame(false, CodeChatSchema::tableExists($db2, 'code_operation_logs'));
});

test('032: il sorgente non contiene operazioni distruttive', function () use ($root) {
    $src = strtoupper((string) file_get_contents($root . '/database/migrations/032_create_code_chat_tables.php'));
    assertSame(false, str_contains($src, 'DROP'));
    assertSame(false, str_contains($src, 'DELETE'));
    assertSame(false, str_contains($src, 'TRUNCATE'));
    // usa applyDdl (non transazionale) e NON createForTests
    $srcRaw = (string) file_get_contents($root . '/database/migrations/032_create_code_chat_tables.php');
    assertSame(true, str_contains($srcRaw, 'CodeChatSchema::applyDdl($db)'));
    assertSame(false, str_contains($srcRaw, 'createForTests'));
});

test('032: le tabelle chat non hanno alcuna FK verso le tabelle LLM', function () use ($tmpDb, $runChain) {
    $db = $tmpDb();
    $runChain($db);

    foreach (['code_sessions', 'code_conversations', 'code_operation_logs', 'code_response_evidence'] as $table) {
        foreach ($db->fetchAll('PRAGMA foreign_key_list(' . $table . ')') as $fk) {
            $target = (string) $fk['table'];
            assertSame(true, str_starts_with($target, 'code_'), "{$table} -> {$target}");
        }
        // nessuna colonna project_id
        $cols = array_column($db->fetchAll('PRAGMA table_info(' . $table . ')'), 'name');
        assertSame(false, in_array('project_id', $cols, true), $table);
    }
});

test('033: aggiorna in modo additivo un DB esistente con le tre tabelle della Fase 1', function () use ($tmpDb, $root) {
    $db = $tmpDb();
    $db->execute('CREATE TABLE code_workspaces (
        id INTEGER PRIMARY KEY AUTOINCREMENT, root_path TEXT NOT NULL UNIQUE, name TEXT NOT NULL DEFAULT \'\',
        status TEXT NOT NULL DEFAULT \'active\', authorized_at TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
        CHECK(status IN (\'active\',\'revoked\')))');
    $ddl = CodeChatSchema::tableDdl();
    foreach (['code_sessions', 'code_conversations', 'code_operation_logs'] as $table) {
        $db->execute($ddl[$table]);
    }
    foreach (CodeChatSchema::indexDdl() as $index) {
        if (!str_contains($index, 'response_evidence')) {
            $db->execute($index);
        }
    }
    $migration = require $root . '/database/migrations/033_create_code_response_evidence.php';
    $db->transaction(static fn () => $migration($db));
    assertSame(CodeChatSchema::STATE_READY, CodeChatSchema::state($db));
    assertSame([], CodeChatSchema::verify($db));
});

test('033: una divergenza preesistente fallisce chiusa e non lascia la nuova tabella', function () use ($tmpDb, $root) {
    $db = $tmpDb();
    $db->execute('CREATE TABLE code_workspaces (
        id INTEGER PRIMARY KEY AUTOINCREMENT, root_path TEXT NOT NULL UNIQUE, name TEXT NOT NULL DEFAULT \'\',
        status TEXT NOT NULL DEFAULT \'active\', authorized_at TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
        CHECK(status IN (\'active\',\'revoked\')))');
    $ddl = CodeChatSchema::tableDdl();
    foreach (['code_sessions', 'code_conversations', 'code_operation_logs'] as $table) {
        $db->execute($ddl[$table]);
    }
    foreach (CodeChatSchema::indexDdl() as $index) {
        if (!str_contains($index, 'response_evidence')) {
            $db->execute($index);
        }
    }
    $db->execute('ALTER TABLE code_sessions ADD COLUMN divergence TEXT');
    $migration = require $root . '/database/migrations/033_create_code_response_evidence.php';
    $failed = false;
    try {
        $db->transaction(static fn () => $migration($db));
    } catch (\RuntimeException $exception) {
        $failed = true;
    }
    assertSame(true, $failed);
    assertSame(false, CodeChatSchema::tableExists($db, 'code_response_evidence'));
});
