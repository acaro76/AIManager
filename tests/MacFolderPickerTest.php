<?php

declare(strict_types=1);

test('Finder picker: route POST, CSRF e interfaccia senza campo percorso manuale', function () {
    $root = dirname(__DIR__);
    $routes = (string) file_get_contents($root . '/public/index.php');
    $controller = (string) file_get_contents($root . '/app/Controllers/CodeController.php');
    $view = (string) file_get_contents($root . '/app/Views/code/index.php');
    assertSame(true, str_contains($routes, "\$router->post('/code/folder/pick'"));
    assertSame(true, str_contains($controller, '$this->guard($request)'));
    assertSame(true, str_contains($view, 'data-code-folder-picker'));
    assertSame(true, str_contains($view, 'name="path"'));
    assertSame(true, str_contains($view, 'type="hidden" name="path"'));
    assertSame(false, str_contains($view, 'placeholder="/percorso'));
    assertSame(false, str_contains($view, '>Autorizza<'));
});

test('Finder picker: ponte macOS statico, senza comandi o percorsi ricevuti dalla richiesta', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/app/Core/Code/MacFolderPicker.php');
    assertSame(true, str_contains($source, '/usr/bin/osascript'));
    assertSame(true, str_contains($source, 'choose folder'));
    assertSame(false, str_contains($source, 'tell application'));
    foreach (['$_GET', '$_POST', 'Request', 'shell_exec', 'proc_open', 'passthru', 'system('] as $forbidden) {
        assertSame(false, str_contains($source, $forbidden), $forbidden);
    }
});

test('Autorizzazione Code: apre o crea automaticamente una sessione prima del redirect', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/CodeController.php');
    assertSame(true, str_contains($source, 'listByWorkspace($workspace->id)'));
    assertSame(true, str_contains($source, "create(\$workspace->id, 'Nuova sessione')"));
    assertSame(true, str_contains($source, "'&session_id=' . \$sessionId"));
});
