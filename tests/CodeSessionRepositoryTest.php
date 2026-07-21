<?php

declare(strict_types=1);

use App\Core\Code\CodeChatSchema;
use App\Core\Code\CodeSessionRepository;
use App\Core\Database;

// F1.3 — sessioni Code scoped al workspace: nessun accesso incrociato tra cartelle.

$throws = static function (callable $fn): bool {
    try { $fn(); return false; } catch (\Throwable $e) { return true; }
};

$mkdb = static function (): Database {
    $path = sys_get_temp_dir() . '/aimanager_csr_' . uniqid('', true) . '.sqlite';
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

test('CodeSessionRepository: crea una sessione e la ritrova nello stesso workspace', function () use ($mkdb) {
    $repo = new CodeSessionRepository($mkdb());
    $id = $repo->create(1, 'prima');
    assertSame(true, $id > 0);
    $row = $repo->findForWorkspace($id, 1);
    assertSame(true, $row !== null);
    assertSame(1, (int) $row['workspace_id']);
    assertSame('prima', (string) $row['title']);
    assertSame('active', (string) $row['status']);
});

test('CodeSessionRepository: findForWorkspace nega l\'accesso da un altro workspace', function () use ($mkdb) {
    $repo = new CodeSessionRepository($mkdb());
    $id = $repo->create(1, 's');
    assertSame(null, $repo->findForWorkspace($id, 2)); // ownership: id giusto, root sbagliata
});

test('CodeSessionRepository: listByWorkspace restituisce solo le sessioni di quella root', function () use ($mkdb) {
    $repo = new CodeSessionRepository($mkdb());
    $repo->create(1, 'a');
    $repo->create(1, 'b');
    $repo->create(2, 'altro');
    $list = $repo->listByWorkspace(1);
    assertSame(2, count($list));
    foreach ($list as $row) {
        assertSame(1, (int) $row['workspace_id']);
    }
});

test('CodeSessionRepository: listByWorkspace ha ordine deterministico (recenti prima, id come spareggio)', function () use ($mkdb) {
    $repo = new CodeSessionRepository($mkdb());
    $a = $repo->create(1, 'a');
    $b = $repo->create(1, 'b');
    $c = $repo->create(1, 'c');
    $ids = array_map(static fn (array $r): int => (int) $r['id'], $repo->listByWorkspace(1));
    assertSame([$c, $b, $a], $ids); // updated_at desc, id desc
});

test('CodeSessionRepository: ripresa globale considera solo sessioni e workspace attivi', function () use ($mkdb, $throws) {
    $db = $mkdb();
    $repo = new CodeSessionRepository($db);
    $old = $repo->create(1, 'vecchia');
    $latest = $repo->create(2, 'recente');
    assertSame([$latest, $old], array_map(static fn (array $r): int => (int) $r['id'], $repo->recentActiveAcrossWorkspaces()));
    $repo->updateStatusForWorkspace($latest, 2, 'archived');
    assertSame([$old], array_map(static fn (array $r): int => (int) $r['id'], $repo->recentActiveAcrossWorkspaces()));
    $db->execute("UPDATE code_workspaces SET status = 'revoked' WHERE id = 1");
    assertSame([], $repo->recentActiveAcrossWorkspaces());
    assertSame(true, $throws(static fn () => $repo->recentActiveAcrossWorkspaces(0)));
});

test('CodeSessionRepository: create fallisce in modo controllato su workspace inesistente', function () use ($mkdb, $throws) {
    $repo = new CodeSessionRepository($mkdb());
    assertSame(true, $throws(static fn () => $repo->create(999, 'x')));
});

test('CodeSessionRepository: create rifiuta un workspace revocato', function () use ($mkdb, $throws) {
    $db = $mkdb();
    $db->execute('UPDATE code_workspaces SET status = \'revoked\' WHERE id = 2');
    $repo = new CodeSessionRepository($db);
    assertSame(true, $throws(static fn () => $repo->create(2, 'x'))); // root revocata: niente sessioni
    // il workspace attivo continua a funzionare
    assertSame(true, $repo->create(1, 'ok') > 0);
});

test('CodeSessionRepository: updateStatus e\' scoped e valida lo stato', function () use ($mkdb, $throws) {
    $repo = new CodeSessionRepository($mkdb());
    $id = $repo->create(1, 's');
    $repo->updateStatusForWorkspace($id, 1, 'archived');
    assertSame('archived', (string) $repo->findForWorkspace($id, 1)['status']);
    // stato non valido
    assertSame(true, $throws(static fn () => $repo->updateStatusForWorkspace($id, 1, 'bogus')));
    // workspace sbagliato: nessuna modifica, errore controllato
    assertSame(true, $throws(static fn () => $repo->updateStatusForWorkspace($id, 2, 'active')));
    assertSame('archived', (string) $repo->findForWorkspace($id, 1)['status']); // invariato
});

test('CodeSessionRepository: rinomina solo sessioni attive e scoped', function () use ($mkdb, $throws) {
    $repo = new CodeSessionRepository($mkdb());
    $id = $repo->create(1, 'prima');
    $repo->renameForWorkspace($id, 1, 'Titolo leggibile');
    assertSame('Titolo leggibile', (string) $repo->findForWorkspace($id, 1)['title']);
    assertSame(true, $throws(static fn () => $repo->renameForWorkspace($id, 2, 'altra root')));
    assertSame(true, $throws(static fn () => $repo->renameForWorkspace($id, 1, '')));
    $repo->updateStatusForWorkspace($id, 1, 'archived');
    assertSame(true, $throws(static fn () => $repo->renameForWorkspace($id, 1, 'troppo tardi')));
});

test('CodeSessionRepository: touch e\' scoped e fallisce su ownership errata', function () use ($mkdb, $throws) {
    $repo = new CodeSessionRepository($mkdb());
    $id = $repo->create(1, 's');
    $repo->touchForWorkspace($id, 1); // ok
    assertSame(true, $throws(static fn () => $repo->touchForWorkspace($id, 2)));
});

test('CodeSessionRepository: funziona anche senza le tabelle LLM', function () use ($mkdb) {
    $db = $mkdb();
    assertSame(false, CodeChatSchema::tableExists($db, 'projects'));
    $repo = new CodeSessionRepository($db);
    $id = $repo->create(1, 's');
    assertSame(true, $repo->findForWorkspace($id, 1) !== null);
});
