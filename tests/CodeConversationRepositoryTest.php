<?php

declare(strict_types=1);

use App\Core\Code\CodeChatSchema;
use App\Core\Code\CodeConversationRepository;
use App\Core\Code\CodeSessionRepository;
use App\Core\Database;

// F1.3 — turni di conversazione Code: scrittura SCOPED al workspace, cronologia stabile,
// limite positivo, letture scoped.

$throws = static function (callable $fn): bool {
    try { $fn(); return false; } catch (\Throwable $e) { return true; }
};

$mkdb = static function (): Database {
    $path = sys_get_temp_dir() . '/aimanager_ccr_' . uniqid('', true) . '.sqlite';
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

test('CodeConversationRepository: appendForWorkspace salva un turno nello stesso workspace', function () use ($mkdb) {
    $db = $mkdb();
    $sid = (new CodeSessionRepository($db))->create(1, 's');
    $repo = new CodeConversationRepository($db);
    $id = $repo->appendForWorkspace($sid, 1, 'user', 'ciao', 'lmstudio');
    assertSame(true, $id > 0);
    $h = $repo->history($sid, 10);
    assertSame(1, count($h));
    assertSame('user', (string) $h[0]['role']);
    assertSame('ciao', (string) $h[0]['content']);
    assertSame('lmstudio', (string) $h[0]['provider']);
});

test('CodeConversationRepository: appendForWorkspace blocca la sessione di un altro workspace e non inserisce nulla', function () use ($mkdb, $throws) {
    $db = $mkdb();
    $sid = (new CodeSessionRepository($db))->create(1, 's'); // sessione del workspace 1
    $repo = new CodeConversationRepository($db);
    assertSame(true, $throws(static fn () => $repo->appendForWorkspace($sid, 2, 'user', 'x'))); // ownership errata
    // nessuna riga inserita
    assertSame(0, (int) $db->fetch('SELECT COUNT(*) c FROM code_conversations')['c']);
});

test('CodeConversationRepository: appendForWorkspace su sessione inesistente fallisce e non inserisce', function () use ($mkdb, $throws) {
    $db = $mkdb();
    $repo = new CodeConversationRepository($db);
    assertSame(true, $throws(static fn () => $repo->appendForWorkspace(999, 1, 'user', 'x')));
    assertSame(0, (int) $db->fetch('SELECT COUNT(*) c FROM code_conversations')['c']);
});

test('CodeConversationRepository: appendForWorkspace nega la scrittura su sessione archiviata', function () use ($mkdb, $throws) {
    $db = $mkdb();
    $sr = new CodeSessionRepository($db);
    $sid = $sr->create(1, 's');
    $repo = new CodeConversationRepository($db);
    $repo->appendForWorkspace($sid, 1, 'user', 'prima'); // ok quando active
    $sr->updateStatusForWorkspace($sid, 1, 'archived');
    assertSame(true, $throws(static fn () => $repo->appendForWorkspace($sid, 1, 'user', 'dopo')));
    assertSame(1, (int) $db->fetch('SELECT COUNT(*) c FROM code_conversations')['c']); // invariato
    // ma lo storico resta CONSULTABILE anche da archiviata
    assertSame(1, count($repo->historyForWorkspace($sid, 1, 10)));
});

test('CodeConversationRepository: appendForWorkspace nega la scrittura se il workspace e\' revocato', function () use ($mkdb, $throws) {
    $db = $mkdb();
    $sid = (new CodeSessionRepository($db))->create(1, 's'); // sessione active
    $repo = new CodeConversationRepository($db);
    $repo->appendForWorkspace($sid, 1, 'user', 'prima');
    $db->execute('UPDATE code_workspaces SET status = \'revoked\' WHERE id = 1'); // root revocata
    assertSame(true, $throws(static fn () => $repo->appendForWorkspace($sid, 1, 'user', 'dopo')));
    assertSame(1, (int) $db->fetch('SELECT COUNT(*) c FROM code_conversations')['c']); // invariato
    // storico ancora leggibile
    assertSame(1, count($repo->historyForWorkspace($sid, 1, 10)));
});

test('CodeConversationRepository: appendForWorkspace rifiuta un ruolo non valido', function () use ($mkdb, $throws) {
    $db = $mkdb();
    $sid = (new CodeSessionRepository($db))->create(1, 's');
    $repo = new CodeConversationRepository($db);
    assertSame(true, $throws(static fn () => $repo->appendForWorkspace($sid, 1, 'system', 'x')));
});

test('CodeConversationRepository: history e\' cronologica e stabile', function () use ($mkdb) {
    $db = $mkdb();
    $sid = (new CodeSessionRepository($db))->create(1, 's');
    $repo = new CodeConversationRepository($db);
    foreach (['m1', 'm2', 'm3', 'm4'] as $i => $m) {
        $repo->appendForWorkspace($sid, 1, $i % 2 === 0 ? 'user' : 'assistant', $m);
    }
    $contents = array_map(static fn (array $r): string => (string) $r['content'], $repo->history($sid, 10));
    assertSame(['m1', 'm2', 'm3', 'm4'], $contents);
});

test('CodeConversationRepository: history restituisce gli ULTIMI N in ordine cronologico', function () use ($mkdb) {
    $db = $mkdb();
    $sid = (new CodeSessionRepository($db))->create(1, 's');
    $repo = new CodeConversationRepository($db);
    foreach (['m1', 'm2', 'm3', 'm4', 'm5'] as $m) {
        $repo->appendForWorkspace($sid, 1, 'user', $m);
    }
    $contents = array_map(static fn (array $r): string => (string) $r['content'], $repo->history($sid, 3));
    assertSame(['m3', 'm4', 'm5'], $contents);
});

test('CodeConversationRepository: history esige un limite positivo', function () use ($mkdb, $throws) {
    $db = $mkdb();
    $sid = (new CodeSessionRepository($db))->create(1, 's');
    $repo = new CodeConversationRepository($db);
    assertSame(true, $throws(static fn () => $repo->history($sid, 0)));
    assertSame(true, $throws(static fn () => $repo->history($sid, -5)));
});

test('CodeConversationRepository: historyForWorkspace blocca le letture cross-workspace', function () use ($mkdb) {
    $db = $mkdb();
    $sid = (new CodeSessionRepository($db))->create(1, 's'); // sessione del workspace 1
    $repo = new CodeConversationRepository($db);
    $repo->appendForWorkspace($sid, 1, 'user', 'segreto');
    assertSame(1, count($repo->historyForWorkspace($sid, 1, 5))); // stessa root: visibile
    assertSame(0, count($repo->historyForWorkspace($sid, 2, 5))); // altra root: nulla
});

test('CodeConversationRepository: historyForWorkspace esige un limite positivo', function () use ($mkdb, $throws) {
    $db = $mkdb();
    $sid = (new CodeSessionRepository($db))->create(1, 's');
    $repo = new CodeConversationRepository($db);
    assertSame(true, $throws(static fn () => $repo->historyForWorkspace($sid, 1, 0)));
});
