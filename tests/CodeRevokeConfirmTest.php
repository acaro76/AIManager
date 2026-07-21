<?php

declare(strict_types=1);

/**
 * Conferma della revoca cartella Code: `<dialog>` nativo coerente col tema al posto di `window.confirm`.
 *
 * Perimetro stretto: cambia SOLO il form `/code/revoke`. Il confirm nativo di `app.js` resta agli altri
 * form `data-confirm` (memoria, progetti, sessioni workspace), e il POST della revoca — route, CSRF, id
 * e semantica — non è toccato: dopo la conferma si invia lo stesso form.
 */
$layoutSrc = static fn (): string => (string) file_get_contents(dirname(__DIR__) . '/app/Views/layouts/app.php');
$codeJsSrc = static fn (): string => (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/code-chat.js');

test('conferma revoca: dialog unico, testo e azioni richiesti, distruttiva distinta', function () use ($layoutSrc) {
    $layout = $layoutSrc();
    // UN dialog per la sidebar, non uno per cartella. La classe è il componente CONDIVISO
    // (vedi ShutdownConfirmTest): stile e convenzioni non sono duplicati.
    assertSame(1, substr_count($layout, '<dialog class="confirm-dialog" data-code-revoke-dialog'));
    // `method="dialog"`: chiusura nativa e scelta in returnValue, senza JS di supporto.
    assertSame(true, str_contains($layout, '<form method="dialog">'));
    assertSame(true, str_contains($layout, '<h2 id="code-revoke-title">Revocare l\'accesso a questa cartella?</h2>'));
    // Fuoco iniziale su Annulla, non sull'azione distruttiva.
    $dialog = (string) substr($layout, (int) strpos($layout, 'data-code-revoke-dialog'), 620);
    assertSame(true, str_contains($dialog, '<button class="button ghost" value="cancel" autofocus>Annulla</button>'));
    // La distruttiva è visivamente distinta (classe già esistente nel tema) e vale 'confirm'.
    assertSame(true, str_contains($dialog, '<button class="button danger" value="confirm" data-code-revoke-accept>Revoca accesso</button>'));
    // Etichettatura per screen reader agganciata al titolo.
    assertSame(true, str_contains($layout, 'aria-labelledby="code-revoke-title"'));
});

test('conferma revoca: il POST resta identico, con CSRF e id invariati', function () use ($layoutSrc) {
    $layout = $layoutSrc();
    $form = (string) substr($layout, (int) strpos($layout, '<form method="post" action="/code/revoke"'), 330);
    // Il form non è più agganciato al confirm nativo generico...
    assertSame(false, str_contains($form, 'data-confirm'));
    // ...ma è marcato per il dialog dedicato.
    assertSame(true, str_contains($form, 'data-code-revoke-form'));
    // Route, CSRF e id invariati: la conferma non tocca il contenuto del POST.
    assertSame(true, str_contains($form, 'action="/code/revoke"'));
    assertSame(true, str_contains($form, '<input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">'));
    assertSame(true, str_contains($form, '<input type="hidden" name="id" value="<?= $codeNavWorkspace->id ?>">'));
});

test('conferma revoca: gli altri form data-confirm restano sul confirm nativo', function () use ($layoutSrc) {
    $appJs = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');
    // Il gestore generico non è stato toccato.
    assertSame(true, str_contains($appJs, "document.querySelectorAll('form[data-confirm]').forEach(function (form) {"));
    assertSame(true, str_contains($appJs, "if (!window.confirm(form.dataset.confirm || 'Confermi?')) {"));
    // Nessun form Code resta agganciato al confirm nativo.
    assertSame(false, str_contains($layoutSrc(), 'data-confirm'));
    // Gli altri form dell'app lo usano ancora: il perimetro è la sola revoca Code.
    $others = 0;
    foreach (['memory/index.php', 'projects/index.php', 'projects/form.php', 'workspace/show.php'] as $view) {
        $others += substr_count((string) file_get_contents(dirname(__DIR__) . '/app/Views/' . $view), 'data-confirm=');
    }
    assertSame(6, $others);
});

test('conferma revoca: mouse, tastiera, Escape e fallback senza <dialog>', function () use ($codeJsSrc) {
    $js = $codeJsSrc();
    $block = (string) substr($js, (int) strpos($js, "const revokeDialog = document.querySelector('[data-code-revoke-dialog]');"), 1200);
    // Il submit del form apre il dialog invece di inviare.
    assertSame(true, str_contains($block, "document.querySelectorAll('form[data-code-revoke-form]').forEach((form) => {"));
    assertSame(true, str_contains($block, 'event.preventDefault();'));
    assertSame(true, str_contains($block, 'revokeDialog.showModal();'));
    // returnValue ripulito a ogni apertura: `close()` senza argomento non lo azzera, e un Escape dopo
    // una conferma precedente revocherebbe da solo.
    assertSame(true, str_contains($block, "revokeDialog.returnValue = '';"));
    // Si invia SOLO su conferma esplicita: Escape/Annulla lasciano returnValue diverso da 'confirm'.
    assertSame(true, str_contains($block, "if (form && revokeDialog.returnValue === 'confirm') form.submit();"));
    // `submit()` e non `requestSubmit()`: non rilancia l'evento submit, quindi non si rientra nel dialog.
    assertSame(false, str_contains($block, 'requestSubmit()'));
    // Fallback: senza <dialog> nativo si torna al confirm del browser, mai una revoca non confermata.
    assertSame(true, str_contains($block, "if (revokeDialog && typeof revokeDialog.showModal === 'function') {"));
    assertSame(true, str_contains($block, "if (!window.confirm('Revocare l\\'accesso a questa cartella?')) event.preventDefault();"));
    // Deve girare anche su /code: prima delle guardie shell/chat.
    assertSame(true, strpos($js, 'const revokeDialog') < strpos($js, "const shell = document.querySelector('[data-code-shell]');"));
    // Con il dialog aperto l'Escape è suo: non chiude anche il menu dietro.
    assertSame(true, str_contains($js, "if (document.querySelector('dialog[open]')) return;"));
});
