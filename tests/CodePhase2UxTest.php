<?php

declare(strict_types=1);

use App\Core\Code\CodeChatSchema;
use App\Core\Code\CodeConversationRepository;
use App\Core\Code\CodeResponseEvidenceRepository;
use App\Core\Code\CodeSessionRepository;
use App\Core\Database;

$throws = static function (callable $fn): bool {
    try { $fn(); return false; } catch (\Throwable $e) { return true; }
};

$mkdb = static function (): Database {
    $db = new Database(sys_get_temp_dir() . '/aimanager_phase2_' . uniqid('', true) . '.sqlite');
    $db->pdo()->exec('PRAGMA foreign_keys = ON');
    $db->execute('CREATE TABLE code_workspaces (
        id INTEGER PRIMARY KEY AUTOINCREMENT, root_path TEXT NOT NULL UNIQUE, name TEXT NOT NULL DEFAULT \'\',
        status TEXT NOT NULL DEFAULT \'active\', authorized_at TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
        CHECK(status IN (\'active\',\'revoked\')))');
    $db->execute("INSERT INTO code_workspaces (root_path,name,status,authorized_at,created_at,updated_at) VALUES ('/w1','','active','t','t','t')");
    $db->execute("INSERT INTO code_workspaces (root_path,name,status,authorized_at,created_at,updated_at) VALUES ('/w2','','active','t','t','t')");
    CodeChatSchema::createForTests($db);
    return $db;
};

test('Fase 2 evidenze: persiste solo metadati canonici associati al turno assistant', function () use ($mkdb) {
    $db = $mkdb();
    $sid = (new CodeSessionRepository($db))->create(1, 'UX');
    $aid = (new CodeConversationRepository($db))->appendForWorkspace($sid, 1, 'assistant', 'risposta');
    $repo = new CodeResponseEvidenceRepository($db);
    $repo->storeForAssistant($aid, $sid, 1, [
        ['path' => 'app/A.php', 'kind' => 'read', 'line' => null],
        ['path' => 'README.md', 'kind' => 'found', 'line' => 7],
    ], ['scan'], ['filesRead' => 1, 'searchMatches' => 2]);
    $stored = $repo->forHistory($sid, 1)[$aid];
    assertSame('app/A.php', $stored['citations'][0]['path']);
    assertSame(7, $stored['citations'][1]['line']);
    assertSame(['scan'], $stored['limits']);
    $raw = (string) $db->fetch('SELECT files_json FROM code_response_evidence')['files_json'];
    assertSame(false, str_contains($raw, 'risposta')); // nessun contenuto conversazione/file
});

test('Fase 2 evidenze: ownership, ruolo, path e vocabolari falliscono chiusi', function () use ($mkdb, $throws) {
    $db = $mkdb();
    $sid = (new CodeSessionRepository($db))->create(1, 'UX');
    $conv = new CodeConversationRepository($db);
    $uid = $conv->appendForWorkspace($sid, 1, 'user', 'domanda');
    $aid = $conv->appendForWorkspace($sid, 1, 'assistant', 'risposta');
    $repo = new CodeResponseEvidenceRepository($db);
    assertSame(true, $throws(static fn () => $repo->storeForAssistant($uid, $sid, 1, [], [], [])));
    assertSame(true, $throws(static fn () => $repo->storeForAssistant($aid, $sid, 2, [], [], [])));
    assertSame(true, $throws(static fn () => $repo->storeForAssistant($aid, $sid, 1, [['path' => '../x', 'kind' => 'read', 'line' => null]], [], [])));
    assertSame(true, $throws(static fn () => $repo->storeForAssistant($aid, $sid, 1, [], ['inventato'], [])));
    assertSame(0, (int) $db->fetch('SELECT COUNT(*) c FROM code_response_evidence')['c']);
});

test('Fase 2 wiring: rinomina correttiva disponibile, archiviazione assente', function () {
    $routes = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
    foreach ([
        "\$router->get('/code/children'", "\$router->get('/code/file'",
        "\$router->post('/code/session/rename'",
    ] as $route) {
        assertSame(true, str_contains($routes, $route), $route);
    }
    foreach (["/code/session/archive"] as $removed) {
        assertSame(false, str_contains($routes, $removed), $removed);
    }
    // Processi e Git sono superfici successive, entrambe tipizzate e confermate. Restano vietati
    // scrittura libera, terminale e tool generico.
    foreach (['/code/write', '/code/terminal', '/code/tool'] as $forbidden) {
        assertSame(false, str_contains($routes, $forbidden), $forbidden);
    }
});

test('Fase 2 controller: explorer e preview usano soltanto primitive confinate read-only', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/CodeController.php');
    assertSame(true, str_contains($source, '$workspace->children($path)'));
    assertSame(true, str_contains($source, '$workspace->readLimited($path, self::FILE_PREVIEW_BYTES)'));
    foreach (['file_put_contents', 'exec(', 'shell_exec', 'proc_open', 'system(', 'passthru'] as $forbidden) {
        assertSame(false, str_contains($source, $forbidden), $forbidden);
    }
});

test('Fase 2 UI: chat primaria, percorso sul composer, allegati espliciti e azione unica', function () {
    $workspace = (string) file_get_contents(dirname(__DIR__) . '/app/Views/code/workspace.php');
    $layout = (string) file_get_contents(dirname(__DIR__) . '/app/Views/layouts/app.php');
    $chat = (string) file_get_contents(dirname(__DIR__) . '/app/Views/code/_chat.php');
    $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/code-chat.js');
    $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
    foreach (['Vai alla chat', 'code-chat-main'] as $needle) {
        assertSame(true, str_contains($workspace, $needle), $needle);
    }
    foreach (['Evidenze', 'data-code-evidence-summary', 'data-code-preview-title', 'data-code-close-panel'] as $removed) {
        assertSame(false, str_contains($workspace, $removed), $removed);
    }
    foreach (['data-code-panel-target="files"', 'role="tree"', 'data-code-file-filter'] as $removed) {
        assertSame(false, str_contains($workspace, $removed), $removed);
    }
    foreach (['code-nav-folder', 'code-nav-sessions', 'Nuova sessione', '/code/session/create'] as $needle) {
        assertSame(true, str_contains($layout, $needle), $needle);
    }
    foreach (['aria-live="polite"', 'code-composer-location', 'data-code-provider-live', 'data-code-chat-attach', 'data-code-chat-action'] as $needle) {
        assertSame(true, str_contains($chat, $needle), $needle);
    }
    foreach (['code-tool-activity', 'Attività sui file', '>Letto<', '>Trovato<', '>Verifica<'] as $noise) {
        assertSame(false, str_contains($chat, $noise), $noise);
        assertSame(false, str_contains($js, $noise), $noise);
    }
    assertSame(false, str_contains($chat, 'data-code-chat-stop'));
    assertSame(false, str_contains($chat, 'data-code-chat-error'));
    assertSame(false, str_contains($css, '.code-chat-error'));
    foreach (['preparing', 'streaming', 'stopping', 'cancelled', "input.focus()"] as $needle) {
        assertSame(true, str_contains($js, $needle), $needle);
    }
    assertSame(true, str_contains($css, '@media (max-width: 1180px)'));
    assertSame(true, str_contains($css, '@media (prefers-reduced-motion: reduce)'));
    assertSame(true, str_contains($css, ':focus-visible'));
});
