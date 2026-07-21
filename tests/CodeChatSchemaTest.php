<?php

declare(strict_types=1);

use App\Core\Code\CodeChatSchema;
use App\Core\Database;

// F1.3 — schema chat Code isolato: DDL non distruttivo, FK/CHECK/UNIQUE attivi, coerenza
// workspace↔sessione, verifica strutturale COMPLETA. Tutto su SQLite temporaneo.

$throws = static function (callable $fn): bool {
    try { $fn(); return false; } catch (\Throwable $e) { return true; }
};

// DB temporaneo con code_workspaces (+2 workspace); con $withChat applica lo schema chat.
$mkdb = static function (bool $withChat = true): Database {
    $path = sys_get_temp_dir() . '/aimanager_ccs_' . uniqid('', true) . '.sqlite';
    $db = new Database($path);
    $db->pdo()->exec('PRAGMA foreign_keys = ON');
    $db->execute('CREATE TABLE code_workspaces (
        id INTEGER PRIMARY KEY AUTOINCREMENT, root_path TEXT NOT NULL UNIQUE, name TEXT NOT NULL DEFAULT \'\',
        status TEXT NOT NULL DEFAULT \'active\', authorized_at TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
        CHECK(status IN (\'active\',\'revoked\')))');
    $db->execute('INSERT INTO code_workspaces (root_path,name,status,authorized_at,created_at,updated_at) VALUES (\'/w1\',\'\',\'active\',\'t\',\'t\',\'t\')');
    $db->execute('INSERT INTO code_workspaces (root_path,name,status,authorized_at,created_at,updated_at) VALUES (\'/w2\',\'\',\'active\',\'t\',\'t\',\'t\')');
    if ($withChat) {
        CodeChatSchema::createForTests($db);
    }
    return $db;
};

$hasProblem = static function (array $problems, string $needle): bool {
    return str_contains(implode(' || ', $problems), $needle);
};

test('CodeChatSchema: il DDL non contiene operazioni distruttive ne\' IF NOT EXISTS', function () {
    $all = array_merge(array_values(CodeChatSchema::tableDdl()), CodeChatSchema::indexDdl());
    foreach ($all as $ddl) {
        $u = strtoupper($ddl);
        assertSame(false, str_contains($u, 'DROP'), $ddl);
        assertSame(false, str_contains($u, 'DELETE'), $ddl);
        assertSame(false, str_contains($u, 'IF NOT EXISTS'), $ddl);
    }
});

test('CodeChatSchema: createForTests costruisce le quattro tabelle e verify le riconosce compatibili', function () use ($mkdb) {
    $db = $mkdb();
    foreach (['code_sessions', 'code_conversations', 'code_operation_logs', 'code_response_evidence'] as $t) {
        assertSame(true, CodeChatSchema::tableExists($db, $t), $t);
    }
    assertSame([], CodeChatSchema::verify($db)); // schema reale === specifica attesa
});

test('CodeChatSchema: applyDdl gira dentro una transazione gia\' aperta (come la 032)', function () use ($mkdb) {
    // il MigrationRunner apre gia' la transazione: applyDdl NON deve aprirne un'altra
    $db = $mkdb(false);
    $db->transaction(static fn () => CodeChatSchema::applyDdl($db));
    assertSame([], CodeChatSchema::verify($db));
});

test('CodeChatSchema: nessuna dipendenza dalle tabelle LLM (assenti)', function () use ($mkdb) {
    $db = $mkdb();
    foreach (['projects', 'sessions', 'conversations', 'execution_states'] as $t) {
        assertSame(false, CodeChatSchema::tableExists($db, $t), $t);
    }
    assertSame([], CodeChatSchema::verify($db));
});

test('CodeChatSchema: le FK sono attive (turno/sessione con riferimenti inesistenti rifiutati)', function () use ($mkdb, $throws) {
    $db = $mkdb();
    assertSame(true, $throws(static fn () => $db->execute(
        'INSERT INTO code_conversations (code_session_id,role,content,provider,created_at) VALUES (999,\'user\',\'x\',\'\',\'t\')'
    )));
    assertSame(true, $throws(static fn () => $db->execute(
        'INSERT INTO code_sessions (workspace_id,title,status,created_at,updated_at) VALUES (999,\'\',\'active\',\'t\',\'t\')'
    )));
});

test('CodeChatSchema: i CHECK su stato, ruolo e outcome sono applicati', function () use ($mkdb, $throws) {
    $db = $mkdb();
    assertSame(true, $throws(static fn () => $db->execute(
        'INSERT INTO code_sessions (workspace_id,title,status,created_at,updated_at) VALUES (1,\'\',\'bogus\',\'t\',\'t\')'
    )));
    $db->execute('INSERT INTO code_sessions (workspace_id,title,status,created_at,updated_at) VALUES (1,\'\',\'active\',\'t\',\'t\')');
    $sid = $db->lastInsertId();
    assertSame(true, $throws(static fn () => $db->execute(
        'INSERT INTO code_conversations (code_session_id,role,content,provider,created_at) VALUES (?,\'system\',\'x\',\'\',\'t\')', [$sid]
    )));
    assertSame(true, $throws(static fn () => $db->execute(
        'INSERT INTO code_operation_logs (workspace_id,action,outcome,created_at) VALUES (1,\'read\',\'boom\',\'t\')'
    )));
    // azione fuori vocabolario: bloccata dal CHECK strutturale (non solo dal repository)
    assertSame(true, $throws(static fn () => $db->execute(
        'INSERT INTO code_operation_logs (workspace_id,action,outcome,created_at) VALUES (1,\'PROMPT DELL\'\'UTENTE\',\'ok\',\'t\')'
    )));
});

test('CodeChatSchema: la FK composita blocca un log che associa una sessione di un altro workspace', function () use ($mkdb, $throws) {
    $db = $mkdb();
    $db->execute('INSERT INTO code_sessions (workspace_id,title,status,created_at,updated_at) VALUES (1,\'\',\'active\',\'t\',\'t\')');
    $sid = $db->lastInsertId(); // sessione del workspace 1
    // log con workspace_id=2 ma code_session_id della sessione del workspace 1 → bloccato
    assertSame(true, $throws(static fn () => $db->execute(
        'INSERT INTO code_operation_logs (workspace_id,code_session_id,action,outcome,created_at) VALUES (2,?,\'read\',\'ok\',\'t\')', [$sid]
    )));
    // associazione coerente → ok
    $db->execute('INSERT INTO code_operation_logs (workspace_id,code_session_id,action,outcome,created_at) VALUES (1,?,\'read\',\'ok\',\'t\')', [$sid]);
    // operazione pre-sessione (code_session_id NULL) → ammessa
    $db->execute('INSERT INTO code_operation_logs (workspace_id,code_session_id,action,outcome,created_at) VALUES (1,NULL,\'chat\',\'ok\',\'t\')');
    assertSame(2, (int) $db->fetch('SELECT COUNT(*) c FROM code_operation_logs')['c']);
});

// --- verify() rileva ogni tipo di incompatibilità (test distinti) ---

test('CodeChatSchema.verify: rileva una colonna EXTRA', function () use ($mkdb, $hasProblem) {
    $db = $mkdb(false);
    $db->execute('CREATE TABLE code_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, workspace_id INTEGER NOT NULL,
        title TEXT NOT NULL DEFAULT \'\', status TEXT NOT NULL DEFAULT \'active\', created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
        foo TEXT, UNIQUE (id, workspace_id), CHECK (status IN (\'active\', \'archived\')), FOREIGN KEY (workspace_id) REFERENCES code_workspaces(id))');
    assertSame(true, $hasProblem(CodeChatSchema::verify($db), 'code_sessions: colonna extra foo'));
});

test('CodeChatSchema.verify: rileva nullability, default e PK errati', function () use ($mkdb, $hasProblem) {
    // nullability: workspace_id senza NOT NULL
    $db = $mkdb(false);
    $db->execute('CREATE TABLE code_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, workspace_id INTEGER,
        title TEXT NOT NULL DEFAULT \'\', status TEXT NOT NULL DEFAULT \'active\', created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    assertSame(true, $hasProblem(CodeChatSchema::verify($db), 'colonna workspace_id notnull atteso 1 trovato 0'));

    // default: title senza DEFAULT
    $db = $mkdb(false);
    $db->execute('CREATE TABLE code_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, workspace_id INTEGER NOT NULL,
        title TEXT NOT NULL, status TEXT NOT NULL DEFAULT \'active\', created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    assertSame(true, $hasProblem(CodeChatSchema::verify($db), 'colonna title default atteso'));

    // PK: id senza PRIMARY KEY
    $db = $mkdb(false);
    $db->execute('CREATE TABLE code_sessions (id INTEGER, workspace_id INTEGER NOT NULL,
        title TEXT NOT NULL DEFAULT \'\', status TEXT NOT NULL DEFAULT \'active\', created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    assertSame(true, $hasProblem(CodeChatSchema::verify($db), 'colonna id pk atteso 1 trovato 0'));
});

test('CodeChatSchema.verify: rileva una FK mancante', function () use ($mkdb, $hasProblem) {
    $db = $mkdb(false);
    $db->execute('CREATE TABLE code_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, workspace_id INTEGER NOT NULL,
        title TEXT NOT NULL DEFAULT \'\', status TEXT NOT NULL DEFAULT \'active\', created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
        UNIQUE (id, workspace_id), CHECK (status IN (\'active\', \'archived\')))'); // niente FOREIGN KEY
    assertSame(true, $hasProblem(CodeChatSchema::verify($db), 'code_sessions: FK attesa mancante: code_workspaces:workspace_id>id'));
});

test('CodeChatSchema.verify: rileva un CHECK mancante', function () use ($mkdb, $hasProblem) {
    $db = $mkdb(false);
    $db->execute('CREATE TABLE code_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, workspace_id INTEGER NOT NULL,
        title TEXT NOT NULL DEFAULT \'\', status TEXT NOT NULL DEFAULT \'active\', created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
        UNIQUE (id, workspace_id), FOREIGN KEY (workspace_id) REFERENCES code_workspaces(id))'); // niente CHECK
    assertSame(true, $hasProblem(CodeChatSchema::verify($db), 'code_sessions: CHECK atteso mancante'));
});

test('CodeChatSchema.verify: rileva il vincolo UNIQUE mancante', function () use ($mkdb, $hasProblem) {
    $db = $mkdb(false);
    $db->execute('CREATE TABLE code_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, workspace_id INTEGER NOT NULL,
        title TEXT NOT NULL DEFAULT \'\', status TEXT NOT NULL DEFAULT \'active\', created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
        CHECK (status IN (\'active\', \'archived\')), FOREIGN KEY (workspace_id) REFERENCES code_workspaces(id))'); // niente UNIQUE
    assertSame(true, $hasProblem(CodeChatSchema::verify($db), 'code_sessions: vincolo atteso mancante: UNIQUE (id, workspace_id)'));
});

test('CodeChatSchema.verify: rileva un indice mancante', function () use ($mkdb, $hasProblem) {
    // tabella corretta ma senza il suo indice
    $db = $mkdb(false);
    $db->execute(CodeChatSchema::tableDdl()['code_sessions']);
    assertSame(true, $hasProblem(CodeChatSchema::verify($db), 'code_sessions: indice assente idx_code_sessions_workspace'));
});
