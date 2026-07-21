<?php

declare(strict_types=1);

use App\Core\Code\CodeChatSchema;
use App\Core\Code\CodeOperationLogRepository;
use App\Core\Code\CodeSessionRepository;
use App\Core\Code\RetrievalResult;
use App\Core\Database;

// F1.6 — audit Code: metadati soltanto, scope verificato, percorsi relativi, pre-sessione
// ammessa. Nessuna tabella LLM, SQLite temporaneo.

$throws = static function (callable $fn): bool {
    try { $fn(); return false; } catch (\Throwable $e) { return true; }
};

$mkdb = static function (): Database {
    $path = sys_get_temp_dir() . '/aimanager_col_' . uniqid('', true) . '.sqlite';
    $db = new Database($path);
    $db->pdo()->exec('PRAGMA foreign_keys = ON');
    $db->execute('CREATE TABLE code_workspaces (
        id INTEGER PRIMARY KEY AUTOINCREMENT, root_path TEXT NOT NULL UNIQUE, name TEXT NOT NULL DEFAULT \'\',
        status TEXT NOT NULL DEFAULT \'active\', authorized_at TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
        CHECK(status IN (\'active\',\'revoked\')))');
    $db->execute('INSERT INTO code_workspaces (root_path,name,status,authorized_at,created_at,updated_at) VALUES (\'/w1\',\'\',\'active\',\'t\',\'t\',\'t\')');
    $db->execute('INSERT INTO code_workspaces (root_path,name,status,authorized_at,created_at,updated_at) VALUES (\'/w2\',\'\',\'active\',\'t\',\'t\',\'t\')');
    CodeChatSchema::createForTests($db);
    return $db;
};

$count = static fn (Database $db): int => (int) $db->fetch('SELECT COUNT(*) c FROM code_operation_logs')['c'];

test('audit: registra un\'operazione scoped con esito, limiti e metriche', function () use ($mkdb, $count) {
    $db = $mkdb();
    $sid = (new CodeSessionRepository($db))->create(1, 's');
    $repo = new CodeOperationLogRepository($db);

    $repo->record(1, $sid, 'retrieval', 'limited', null, ['scan', 'read:files'], ['filesRead' => 2, 'readBytes' => 100]);

    assertSame(1, $count($db));
    $row = $db->fetch('SELECT * FROM code_operation_logs WHERE id = 1');
    assertSame(1, (int) $row['workspace_id']);
    assertSame($sid, (int) $row['code_session_id']);
    assertSame('retrieval', (string) $row['action']);
    assertSame('limited', (string) $row['outcome']);
    assertSame(null, $row['rel_path']);
    assertSame(['scan', 'read:files'], json_decode((string) $row['limits_json'], true));
    assertSame(2, json_decode((string) $row['metrics_json'], true)['filesRead']);
});

test('audit: operazione PRE-SESSIONE ammessa con code_session_id NULL', function () use ($mkdb, $count) {
    $db = $mkdb();
    $repo = new CodeOperationLogRepository($db);
    $repo->record(1, null, 'chat', 'denied');

    assertSame(1, $count($db));
    $row = $db->fetch('SELECT * FROM code_operation_logs WHERE id = 1');
    assertSame(null, $row['code_session_id']);
    assertSame('denied', (string) $row['outcome']);
});

test('audit: registra un percorso RELATIVO nel rel_path', function () use ($mkdb) {
    $db = $mkdb();
    $sid = (new CodeSessionRepository($db))->create(1, 's');
    (new CodeOperationLogRepository($db))->record(1, $sid, 'read', 'ok', 'app/Auth/Login.php');

    $row = $db->fetch('SELECT * FROM code_operation_logs WHERE id = 1');
    assertSame('app/Auth/Login.php', (string) $row['rel_path']);
});

test('audit: rifiuta percorsi assoluti, traversal e byte NUL', function () use ($mkdb, $throws, $count) {
    $db = $mkdb();
    $sid = (new CodeSessionRepository($db))->create(1, 's');
    $repo = new CodeOperationLogRepository($db);

    foreach (['/etc/passwd', '../secret', 'a/../../x', "bad\0.php", 'C:\\Windows'] as $bad) {
        assertSame(true, $throws(static fn () => $repo->record(1, $sid, 'read', 'ok', $bad)), $bad);
    }
    assertSame(0, $count($db)); // nessun percorso fuori norma finisce nei log
});

test('audit: scope incrociato bloccato (sessione di un altro workspace) e nessuna riga', function () use ($mkdb, $throws, $count) {
    $db = $mkdb();
    $sid = (new CodeSessionRepository($db))->create(1, 's'); // sessione del workspace 1
    $repo = new CodeOperationLogRepository($db);

    // workspace 2 + sessione del workspace 1 => incoerenza
    assertSame(true, $throws(static fn () => $repo->record(2, $sid, 'read', 'ok', 'a.php')));
    assertSame(0, $count($db));
});

test('audit: esito e azione sono validati', function () use ($mkdb, $throws) {
    $db = $mkdb();
    $sid = (new CodeSessionRepository($db))->create(1, 's');
    $repo = new CodeOperationLogRepository($db);

    assertSame(true, $throws(static fn () => $repo->record(1, $sid, 'read', 'boom')));   // esito non valido
    assertSame(true, $throws(static fn () => $repo->record(1, $sid, '   ', 'ok')));      // azione vuota
    // tutti gli esiti ammessi passano
    foreach (['ok', 'denied', 'limited', 'cancelled', 'error'] as $outcome) {
        assertSame(false, $throws(static fn () => $repo->record(1, $sid, 'chat', $outcome)), $outcome);
    }
});

test('audit: VOCABOLARIO CHIUSO — prompt/contenuti/messaggi tecnici non entrano in action', function () use ($mkdb, $throws, $count) {
    $db = $mkdb();
    $sid = (new CodeSessionRepository($db))->create(1, 's');
    $repo = new CodeOperationLogRepository($db);

    foreach ([
        'PROMPT-SEGRETO dove sta il login',
        'function login() { return true; }',
        'SQLSTATE[23000]: errore tecnico',
        'open', 'write', '',
    ] as $bad) {
        assertSame(true, $throws(static fn () => $repo->record(1, $sid, $bad, 'ok')), $bad);
    }
    assertSame(0, $count($db)); // nessuna riga scritta

    // solo le azioni ammesse passano
    foreach (['chat', 'retrieval', 'read'] as $ok) {
        assertSame(false, $throws(static fn () => $repo->record(1, $sid, $ok, 'ok')), $ok);
    }
});

test('audit: VOCABOLARIO CHIUSO — i limiti accettano solo i codici del retrieval', function () use ($mkdb, $throws, $count) {
    $db = $mkdb();
    $sid = (new CodeSessionRepository($db))->create(1, 's');
    $repo = new CodeOperationLogRepository($db);

    // stringa arbitraria (es. un prompt o un contenuto)
    assertSame(true, $throws(static fn () => $repo->record(1, $sid, 'chat', 'ok', null, ['PROMPT-SEGRETO'])));
    // array associativo
    assertSame(true, $throws(static fn () => $repo->record(1, $sid, 'chat', 'ok', null, ['k' => 'scan'])));
    // valore annidato
    assertSame(true, $throws(static fn () => $repo->record(1, $sid, 'chat', 'ok', null, [['scan']])));
    // tipo sbagliato
    assertSame(true, $throws(static fn () => $repo->record(1, $sid, 'chat', 'ok', null, [42])));
    assertSame(0, $count($db));

    // tutti i codici prodotti dal retrieval sono ammessi
    assertSame(false, $throws(static fn () => $repo->record(1, $sid, 'chat', 'ok', null, RetrievalResult::LIMIT_CODES)));
});

test('audit: VOCABOLARIO CHIUSO — le metriche accettano solo chiavi note e interi non negativi', function () use ($mkdb, $throws, $count) {
    $db = $mkdb();
    $sid = (new CodeSessionRepository($db))->create(1, 's');
    $repo = new CodeOperationLogRepository($db);

    // chiave sconosciuta (potrebbe veicolare testo)
    assertSame(true, $throws(static fn () => $repo->record(1, $sid, 'chat', 'ok', null, [], ['promptUtente' => 1])));
    // valore non intero (stringa arbitraria)
    assertSame(true, $throws(static fn () => $repo->record(1, $sid, 'chat', 'ok', null, [], ['filesRead' => 'PROMPT-SEGRETO'])));
    // valore annidato
    assertSame(true, $throws(static fn () => $repo->record(1, $sid, 'chat', 'ok', null, [], ['filesRead' => ['x']])));
    // negativo
    assertSame(true, $throws(static fn () => $repo->record(1, $sid, 'chat', 'ok', null, [], ['filesRead' => -1])));
    assertSame(0, $count($db));

    // chiavi note con interi non negativi
    assertSame(false, $throws(static fn () => $repo->record(1, $sid, 'chat', 'ok', null, [], ['filesRead' => 0, 'readBytes' => 10])));
});

test('audit: il rel_path persistito e\' canonico (nessun backslash)', function () use ($mkdb, $throws, $count) {
    $db = $mkdb();
    $sid = (new CodeSessionRepository($db))->create(1, 's');
    $repo = new CodeOperationLogRepository($db);

    // backslash rifiutato: non esiste una seconda rappresentazione dello stesso percorso
    assertSame(true, $throws(static fn () => $repo->record(1, $sid, 'read', 'ok', 'src\\file.php')));
    assertSame(true, $throws(static fn () => $repo->record(1, $sid, 'read', 'ok', '..\\secret')));
    assertSame(true, $throws(static fn () => $repo->record(1, $sid, 'read', 'ok', 'a\\..\\..\\x')));
    assertSame(0, $count($db));

    // la forma con '/' passa ed e' esattamente quella persistita
    $repo->record(1, $sid, 'read', 'ok', 'src/file.php');
    $row = $db->fetch('SELECT rel_path FROM code_operation_logs WHERE id = 1');
    assertSame('src/file.php', (string) $row['rel_path']);
    assertSame(false, str_contains((string) $row['rel_path'], '\\'));
});

test('audit: listForWorkspace e\' scoped e ordinato, con limite positivo', function () use ($mkdb, $throws) {
    $db = $mkdb();
    $s1 = (new CodeSessionRepository($db))->create(1, 'a');
    $s2 = (new CodeSessionRepository($db))->create(2, 'b');
    $repo = new CodeOperationLogRepository($db);
    $repo->record(1, $s1, 'chat', 'ok');
    $repo->record(1, $s1, 'read', 'ok', 'a.php');
    $repo->record(2, $s2, 'chat', 'ok'); // altro workspace

    $rows = $repo->listForWorkspace(1, 10);
    assertSame(2, count($rows));
    foreach ($rows as $row) {
        assertSame(1, (int) $row['workspace_id']); // mai log di altre root
    }
    assertSame('read', (string) $rows[0]['action']); // piu' recenti prima
    assertSame(true, $throws(static fn () => $repo->listForWorkspace(1, 0)));
});

test('audit: funziona senza alcuna tabella LLM', function () use ($mkdb, $count) {
    $db = $mkdb();
    foreach (['projects', 'sessions', 'conversations', 'execution_states'] as $t) {
        assertSame(false, CodeChatSchema::tableExists($db, $t), $t);
    }
    $sid = (new CodeSessionRepository($db))->create(1, 's');
    (new CodeOperationLogRepository($db))->record(1, $sid, 'chat', 'ok');
    assertSame(1, $count($db));
});
