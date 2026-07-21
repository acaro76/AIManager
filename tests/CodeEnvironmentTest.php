<?php

declare(strict_types=1);

use App\Core\Code\CodeWorkspace;
use App\Core\Code\RepoMap;
use App\Core\Code\SensitivePathPolicy;

/**
 * F0.c — ambiente Code top-level. Test ISOLATI: le viste sono rese direttamente (senza
 * boot dell'app, senza DB reale) perché usano solo View::e() (statica) e i dati passati;
 * il wiring di route e menu è verificato per ispezione statica dei file.
 */

$policy = new SensitivePathPolicy();

// Rende una vista Code catturando l'output, senza layout né App::boot.
$renderView = static function (string $relView, array $vars): string {
    extract($vars, EXTR_SKIP);
    ob_start();
    require dirname(__DIR__) . '/app/Views/' . $relView;
    return (string) ob_get_clean();
};

$ws = static function (int $id, string $root, string $name, string $status) use ($policy): CodeWorkspace {
    return new CodeWorkspace($id, $root, $name, $status, $policy);
};

test('code index: mostra soltanto l’autorizzazione iniziale, senza duplicare cartelle e sessioni', function () use ($renderView, $ws) {
    $html = $renderView('code/index.php', [
        'csrf' => 'TOK',
        'workspaces' => [
            $ws(1, '/tmp/act', 'Attivo', 'active'),
            $ws(2, '/tmp/rev', 'Revocato', 'revoked'),
        ],
    ]);
    assertSame(true, str_contains($html, 'Apri una cartella'));
    assertSame(true, str_contains($html, 'action="/code/open"'));
    assertSame(false, str_contains($html, '/code/workspace?id='));
    assertSame(false, str_contains($html, '/code/revoke'));
});

test('code workspace: non mostra explorer o ricerca file e mantiene la chat come superficie primaria', function () use ($renderView, $ws) {
    $map = new RepoMap([
        ['path' => 'src/A.php', 'symbols' => ['class Alpha', 'function f']],
        ['path' => 'README.md', 'symbols' => []],
    ], false);
    $html = $renderView('code/workspace.php', [
        'csrf' => 'TOK',
        'workspace' => $ws(1, '/tmp/proj', 'Proj', 'active'),
        'map' => $map,
        'error' => null,
    ]);
    assertSame(false, str_contains($html, 'src/A.php'));
    assertSame(false, str_contains($html, 'data-code-inventory'));
    assertSame(false, str_contains($html, 'data-code-tree'));
    assertSame(true, str_contains($html, 'code-chat-main'));
});

test('code workspace: root non valida mostra stato azionabile (Riautorizza)', function () use ($renderView, $ws) {
    $html = $renderView('code/workspace.php', [
        'csrf' => 'TOK',
        'workspace' => $ws(1, '/tmp/proj', 'Proj', 'active'),
        'map' => null,
        'error' => 'La cartella non è più valida (spostata, eliminata o inaccessibile). Riautorizzala.',
    ]);
    assertSame(true, str_contains($html, 'Riautorizza'));
    assertSame(true, str_contains($html, 'non è più valida'));
    // in stato errore non si prova a mostrare la struttura
    assertSame(false, str_contains($html, 'Struttura del progetto'));
});

test('code viste: output HTML correttamente escaped', function () use ($renderView, $ws) {
    $map = new RepoMap([['path' => '<b>p</b>.php', 'symbols' => ['class <i>X</i>']]], false);
    $html = $renderView('code/workspace.php', [
        'csrf' => 'TOK',
        'workspace' => $ws(1, '/tmp/<script>x', '<script>alert(1)</script>', 'active'),
        'map' => $map,
        'error' => null,
        'chatState' => App\Core\Code\CodeChatSchema::STATE_READY,
        'session' => ['id' => 4, 'status' => 'active'],
        'history' => [],
    ]);
    // niente markup grezzo iniettato
    assertSame(false, str_contains($html, '<script>alert(1)</script>'));
    assertSame(false, str_contains($html, '<b>p</b>.php'));
    // versione escaped presente
    assertSame(true, str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'));
    assertSame(true, str_contains($html, '&lt;script&gt;x'));
});

test('code wiring: le quattro route Code sono registrate', function () {
    $routes = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
    assertSame(true, str_contains($routes, "\$router->get('/code', [CodeController::class, 'index'])"));
    assertSame(true, str_contains($routes, "\$router->post('/code/open', [CodeController::class, 'open'])"));
    assertSame(true, str_contains($routes, "\$router->get('/code/workspace', [CodeController::class, 'workspace'])"));
    assertSame(true, str_contains($routes, "\$router->post('/code/revoke', [CodeController::class, 'revoke'])"));
});

test('code wiring: la voce Code top-level e\' nel menu globale', function () {
    $layout = (string) file_get_contents(dirname(__DIR__) . '/app/Views/layouts/app.php');
    assertSame(true, str_contains($layout, 'href="/code"'));
    assertSame(true, str_contains($layout, '>Code<'));
    assertSame(true, str_contains($layout, 'code-nav-folder'));
    assertSame(true, str_contains($layout, 'Nuova sessione'));
});

test('code layout: su /code non si interrogano Project/Session (isolamento reale, non solo HTML)', function () {
    $layout = (string) file_get_contents(dirname(__DIR__) . '/app/Views/layouts/app.php');
    // esiste il flag di superficie Code
    assertSame(true, str_contains($layout, "\$isCodeSurface = str_starts_with(\$current, '/code')"));
    // le query LLM (Project/Session) sono nel ramo NON-code; su Code si salta del tutto
    assertSame(true, str_contains($layout, 'if ($isCodeSurface) {'));
    assertSame(true, str_contains($layout, '$freeChatSessions = [];'));
    // la voce Code resta nel menu primario
    assertSame(true, str_contains($layout, 'href="/code"'));
});

test('code controller: una root revocata resta consultabile ma viene marcata senza accesso', function () {
    $controller = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/CodeController.php');
    assertSame(true, str_contains($controller, "\$accessRevoked = \$workspace->status === 'revoked'"));
    assertSame(true, str_contains($controller, "if (\$workspace === null)"));
});
