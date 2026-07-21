<?php

declare(strict_types=1);

/**
 * Conferma dell'arresto («Ferma AIManager») con `<dialog>` nativo al posto di `window.confirm`.
 *
 * La conferma segnala i processi persistenti e il backend li termina prima di AIManager.
 *
 * Il componente è lo STESSO della revoca cartella Code: una sola classe `.confirm-dialog`, nessuna
 * duplicazione di stile.
 */
$layoutSrc = static fn (): string => (string) file_get_contents(dirname(__DIR__) . '/app/Views/layouts/app.php');
$appJsSrc = static fn (): string => (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');

/** Ritaglia il blocco fra due marcatori: una lunghezza indovinata taglierebbe fuori le asserzioni. */
$block = static function (string $src, string $from, string $to): string {
    $at = strpos($src, $from);
    assertSame(true, $at !== false, 'blocco non trovato: ' . $from);
    $end = strpos($src, $to, (int) $at);
    assertSame(true, $end !== false, 'fine blocco non trovata: ' . $to);

    return substr($src, (int) $at, (int) $end - (int) $at + strlen($to));
};

test('conferma arresto: dialog col testo e le azioni richiesti, distruttiva distinta', function () use ($layoutSrc) {
    $layout = $layoutSrc();
    assertSame(1, substr_count($layout, '<dialog class="confirm-dialog" data-shutdown-dialog'));
    // `method="dialog"`: chiusura nativa, scelta in returnValue, Escape che annulla senza JS.
    assertSame(true, str_contains($layout, '<h2 id="shutdown-confirm-title">Fermare AIManager?</h2>'));
    assertSame(true, str_contains($layout, '<p>Il server locale verrà chiuso e la pagina non funzionerà finché non lo riavvii.</p>'));
    assertSame(true, str_contains($layout, 'processi in esecuzione. Fermando AIManager verranno terminati tutti. Vuoi continuare?'));
    assertSame(true, str_contains($layout, 'operazioni in attesa di decisione. Fermando AIManager verranno annullate. Vuoi continuare?'));
    assertSame(true, str_contains($layout, 'i processi verranno terminati e le operazioni annullate. Vuoi continuare?'));
    $dialog = (string) substr($layout, (int) strpos($layout, 'data-shutdown-dialog'), 1800);
    assertSame(true, str_contains($dialog, '<form method="dialog">'));
    // Fuoco iniziale su Annulla, non sull'azione distruttiva.
    assertSame(true, str_contains($dialog, '<button class="button ghost" value="cancel" autofocus>Annulla</button>'));
    assertSame(true, str_contains($dialog, '<button class="button danger" value="confirm" data-shutdown-accept>Ferma AIManager</button>'));
    assertSame(true, str_contains($layout, 'aria-labelledby="shutdown-confirm-title"'));
});

test('conferma arresto: componente condiviso con la revoca, nessun CSS duplicato', function () use ($layoutSrc) {
    $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
    // Una sola definizione del componente, usata da entrambi i dialog.
    assertSame(1, substr_count($css, '.confirm-dialog {'));
    assertSame(1, substr_count($css, '.confirm-dialog-actions {'));
    assertSame(true, str_contains($css, '.confirm-dialog::backdrop'));
    // La vecchia classe dedicata alla sola revoca non sopravvive in nessuna forma.
    assertSame(false, str_contains($css, 'code-confirm'));
    assertSame(false, str_contains($layoutSrc(), 'code-confirm'));
    // Entrambi i dialog usano la stessa classe.
    assertSame(2, substr_count($layoutSrc(), '<dialog class="confirm-dialog"'));
});

test('conferma arresto: mouse, tastiera, Escape e fallback senza <dialog>', function () use ($appJsSrc, $block) {
    $js = $appJsSrc();
    $block = $block(
        $js,
        "const shutdownDialog = document.querySelector('[data-shutdown-dialog]');",
        "document.querySelector('form[data-shutdown]')?.addEventListener"
    );
    assertSame(true, str_contains($block, 'shutdownDialog.showModal();'));
    // Si ferma SOLO su conferma esplicita: Annulla ed Escape lasciano returnValue diverso.
    assertSame(true, str_contains($block, "resolve(shutdownDialog.returnValue === 'confirm');"));
    // returnValue ripulito a ogni apertura: `close()` senza argomento non lo azzera.
    assertSame(true, str_contains($block, "shutdownDialog.returnValue = '';"));
    // Il listener non si accumula fra un'apertura e l'altra.
    assertSame(true, str_contains($block, '{ once: true }'));
    // Fallback: senza <dialog> nativo si torna al confirm del browser, mai un arresto non confermato.
    assertSame(true, str_contains($block, "if (!shutdownDialog || typeof shutdownDialog.showModal !== 'function') {"));
    assertSame(true, str_contains($block, 'window.confirm(' . "'" . 'Fermare AIManager?'));
    // Il confirm nativo NON è più la strada normale.
    assertSame(false, str_contains($js, 'if (!window.confirm(\'Fermare AIManager?'));
});

test('conferma arresto: il flusso di /system/stop resta identico', function () use ($appJsSrc, $block) {
    $js = $appJsSrc();
    $handler = $block(
        $js,
        "document.querySelector('form[data-shutdown]')?.addEventListener",
        'document.body.appendChild(overlay);'
    );
    // La conferma è attesa, e il form viene letto PRIMA: dopo un await `currentTarget` sarebbe null
    // e il flusso di arresto si romperebbe.
    assertSame(true, str_contains($handler, 'const form = event.currentTarget;'));
    assertSame(true, str_contains($handler, 'if (!(await confirmShutdown())) {'));
    assertSame(true, strpos($handler, 'const form = event.currentTarget;') < strpos($handler, 'await confirmShutdown()'));
    // Fetch, CSRF (dal FormData del form), stato e overlay invariati.
    assertSame(true, str_contains($handler, "await fetch('/system/stop', {"));
    assertSame(true, str_contains($handler, 'body: new FormData(form),'));
    assertSame(true, str_contains($handler, "headers: { 'X-Requested-With': 'fetch' }"));
    assertSame(true, str_contains($handler, "button.textContent = 'Arresto in corso...';"));
    assertSame(true, str_contains($handler, "overlay.className = 'shutdown-overlay';"));
    // Gli alert d'errore restano come sono: fuori scope.
    assertSame(true, str_contains($handler, "window.alert(shutdownResult.message || 'Impossibile fermare il server.');"));
});

test('conferma arresto: il form conserva route e CSRF', function () use ($layoutSrc) {
    $layout = $layoutSrc();
    // Stesso form, stessa route, stesso CSRF: cambia solo come si conferma.
    assertSame(true, str_contains($layout, '<form class="shutdown" method="post" action="/system/stop" data-shutdown>'));
    $form = (string) substr($layout, (int) strpos($layout, 'action="/system/stop" data-shutdown>'), 260);
    assertSame(true, str_contains($form, '<input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">'));
    assertSame(true, str_contains($form, '<button type="submit" class="shutdown-btn"'));
    // Il form non è agganciato al confirm generico degli altri form.
    assertSame(false, str_contains($form, 'data-confirm'));
});

test('arresto: tenta tutti i processi, spegne comunque e informa sugli eventuali superstiti', function () {
    $controller = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/SystemController.php');
    $service = (string) file_get_contents(dirname(__DIR__) . '/app/Core/Code/ProcessConfirmService.php');
    $repo = (string) file_get_contents(dirname(__DIR__) . '/app/Core/Code/ProcessRunRepository.php');

    assertSame(true, str_contains($controller, '->stopAllForShutdown()'));
    assertSame(true, strpos($controller, '->stopAllForShutdown()') < strpos($controller, '$this->scheduleShutdown($pid)'));
    assertSame(true, str_contains($controller, 'AIManager è stato fermato, ma almeno un processo potrebbe essere ancora in esecuzione.'));
    assertSame(true, str_contains($controller, "'ok' => true"));
    assertSame(true, str_contains($controller, "'manual_instructions'"));
    assertSame(true, str_contains($controller, 'lsof -nP -iTCP:'));
    assertSame(true, str_contains($controller, 'lsof -nP -iTCP -sTCP:LISTEN | grep php'));
    assertSame(true, str_contains($controller, 'kill -TERM <PID>'));
    assertSame(true, str_contains($service, '$this->repo->listAllActive()'));
    assertSame(true, str_contains($service, '$this->stop('));
    assertSame(true, str_contains($repo, 'public function listAllActive(): array'));
    assertSame(true, str_contains($repo, "state IN (\\'starting\\', \\'running\\')"));

    $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');
    assertSame(true, str_contains($js, 'shutdownResult?.process_cleanup?.manual_instructions'));
    assertSame(true, str_contains($js, 'warning.textContent = manualInstructions;'));
});

test('arresto: conta e annulla globalmente le proposte ancora aperte prima di spegnere', function () {
    $layout = (string) file_get_contents(dirname(__DIR__) . '/app/Views/layouts/app.php');
    $controller = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/SystemController.php');
    $service = (string) file_get_contents(dirname(__DIR__) . '/app/Core/Code/PendingOperationService.php');

    assertSame(true, str_contains($layout, '->countAll();'));
    assertSame(true, str_contains($controller, '->cancelAll();'));
    assertSame(true, strpos($controller, '->cancelAll();') < strpos($controller, '$this->scheduleShutdown($pid)'));
    assertSame(true, str_contains($controller, "'pending_cleanup' => \$pending"));
    foreach (['code_patch_operations', 'code_command_runs', 'code_processes', 'code_git_operations'] as $table) {
        assertSame(true, str_contains($service, "'{$table}'"), $table);
    }
    assertSame(true, str_contains($service, "'commit_pending'"));
});
