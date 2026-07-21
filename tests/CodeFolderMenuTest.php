<?php

declare(strict_types=1);

/**
 * Regressioni del difetto di accessibilità delle azioni cartella (revoca / riautorizza).
 *
 * Le azioni esistevano già nel layout, ma vivevano in `.code-folder-card`: si apriva solo in
 * hover/focus, era `position:absolute; left: calc(100% + 4px)` — cioè FUORI dalla sidebar, che ha
 * `overflow:hidden` (come `.code-nav-tree`, `overflow-y:auto`) e quindi la tagliava — e sotto 980px
 * era spenta con `display:none !important`. Risultato: revocare o riautorizzare era impossibile.
 *
 * Ora sono un menu `•••` esplicito dentro la riga, con lo stesso meccanismo dei menu di sessione
 * (`<details>/<summary>`: mouse e tastiera senza JS), ancorato dentro la sidebar e senza soglie di
 * larghezza. Route, form, CSRF e semantica della revoca restano quelli di prima.
 */
$layoutSrc = static fn (): string => (string) file_get_contents(dirname(__DIR__) . '/app/Views/layouts/app.php');
$cssSrc = static fn (): string => (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');

/** Ritaglia il blocco fra due marcatori, per asserire sul punto giusto e non su tutto il file. */
$block = static function (string $src, string $from, string $to): string {
    $at = strpos($src, $from);
    assertSame(true, $at !== false, 'blocco non trovato: ' . $from);
    $end = strpos($src, $to, (int) $at);
    assertSame(true, $end !== false, 'fine blocco non trovata: ' . $to);

    return substr($src, (int) $at, $end - (int) $at);
};

test('menu cartella: trigger esplicito nella riga, con details/summary come i menu sessione', function () use ($layoutSrc, $block) {
    $layout = $layoutSrc();
    // Il trigger è un vero <details>: apribile con mouse E tastiera, senza JS.
    assertSame(true, str_contains($layout, '<details class="code-nav-folder-menu"'));
    assertSame(true, str_contains($layout, '<summary aria-label="Azioni per <?= View::e($codeFolderLabel) ?>" title="Azioni cartella">•••</summary>'));
    // Vive DENTRO la riga della cartella, non in un elemento fratello posizionato altrove.
    $row = $block($layout, '<div class="code-nav-folder-row">', '<nav class="code-nav-sessions"');
    assertSame(true, str_contains($row, 'code-nav-folder-menu'));
    assertSame(true, str_contains($row, 'code-nav-folder-popover'));
    // La vecchia card in hover non esiste più: niente markup morto.
    assertSame(false, str_contains($layout, 'code-folder-card'));
});

test('menu cartella: riusa i form esistenti con le stesse route e il CSRF su ognuno', function () use ($layoutSrc, $block) {
    $layout = $layoutSrc();
    $menu = $block($layout, '<details class="code-nav-folder-menu"', '</details>');
    // Le tre azioni già esistenti: riautorizza (revocata) · nuova sessione + revoca (attiva).
    assertSame(true, str_contains($menu, 'action="/code/open"'));
    assertSame(true, str_contains($menu, 'action="/code/session/create"'));
    assertSame(true, str_contains($menu, 'action="/code/revoke"'));
    assertSame(true, str_contains($menu, '<button class="danger" type="submit">Revoca accesso</button>'));
    // Il picker nativo resta agganciato al form di riautorizzazione (il JS lega per data-attribute).
    assertSame(true, str_contains($menu, 'data-code-folder-form'));
    assertSame(true, str_contains($menu, 'data-code-folder-picker>Riautorizza</button>'));
    // La revoca resta confermata, ora dal dialog dedicato (vedi CodeRevokeConfirmTest).
    assertSame(true, str_contains($menu, 'data-code-revoke-form'));
    // CSRF su OGNI form del menu: tanti token quanti form, nessuno scoperto.
    assertSame(3, substr_count($menu, '<form method="post"'));
    assertSame(3, substr_count($menu, '<input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">'));
});

test('menu cartella: resta dentro la sidebar, senza uscirne come la vecchia card', function () use ($cssSrc) {
    $css = $cssSrc();
    // Ancoraggio al proprio trigger e apertura verso l'interno: nessun ancestor con overflow lo taglia.
    assertSame(true, str_contains($css, '.code-nav-folder-menu { position: relative; }'));
    assertSame(true, str_contains($css, '.code-nav-folder-popover {'));
    $popover = substr($css, (int) strpos($css, '.code-nav-folder-popover {'), 220);
    assertSame(true, str_contains($popover, 'position: absolute;'));
    assertSame(true, str_contains($popover, 'right: 0;'));
    // Il difetto: la card era spinta oltre il bordo destro della sidebar.
    assertSame(false, str_contains($css, 'left: calc(100% + 4px)'));
    // La sidebar resta scorribile e sopra la chat: non è ciò che si stava correggendo.
    assertSame(true, str_contains($css, '.code-surface-shell .sidebar { z-index: 60; overflow: hidden; }'));
});

test('menu sidebar: ne resta aperto uno solo, e si chiude fuori o con Escape', function () {
    $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/code-chat.js');
    // `<details>` da solo li lascerebbe aperti tutti insieme: si sovrapponevano fra loro e alle voci sotto.
    // La regola vale per OGNI menu della sidebar, rinomina sessione inclusa: nessuna voce esclusa.
    assertSame(true, str_contains($js, "const NAV_MENU_OPEN = 'details.code-nav-folder-menu[open], details.code-nav-session-menu[open]';"));
    assertSame(true, str_contains($js, 'const openNavMenus = () => document.querySelectorAll(NAV_MENU_OPEN);'));
    // Apertura: chiude gli altri. `toggle` non fa bubbling, va intercettato in cattura.
    assertSame(true, str_contains($js, 'if (menu instanceof HTMLElement && menu.matches(NAV_MENU_OPEN)) closeNavMenus(menu);'));
    assertSame(true, str_contains($js, "document.addEventListener('toggle', (event) => {"));
    assertSame(true, str_contains($js, '}, true);'));
    // Click fuori dal menu: si chiude. Click dentro (form, campo titolo, Rinomina): resta aperto.
    assertSame(true, str_contains($js, 'openNavMenus().forEach((menu) => { if (!menu.contains(event.target)) menu.open = false; });'));
    // Tastiera: Escape chiude e riporta il fuoco sul trigger.
    assertSame(true, str_contains($js, "if (event.key !== 'Escape') return;"));
    assertSame(true, str_contains($js, "menu.querySelector('summary')?.focus();"));
    // Deve girare anche su /code, dove la sidebar c'è ma `shell`/`chat` no: quindi PRIMA delle guardie.
    $guard = strpos($js, "const shell = document.querySelector('[data-code-shell]');");
    assertSame(true, strpos($js, 'const NAV_MENU_OPEN') < $guard);
    // Nessun residuo della versione che copriva le sole cartelle.
    assertSame(false, str_contains($js, 'openFolderMenus'));
});

test('menu cartella: nessuna disabilitazione responsive, le azioni esistono a ogni larghezza', function () use ($cssSrc) {
    $css = $cssSrc();
    // Il difetto: sotto 980px le azioni erano proprio spente.
    assertSame(false, str_contains($css, '.code-folder-card { display: none !important; }'));
    assertSame(false, str_contains($css, 'code-folder-card'), 'nessuna regola della vecchia card deve sopravvivere');
    // Il file ha più blocchi a 980px: si ritaglia QUELLO della sidebar Code, non il primo che capita.
    $anchor = strpos($css, '.code-nav-folder { flex: 0 0 220px; }');
    assertSame(true, $anchor !== false);
    $start = strrpos(substr($css, 0, (int) $anchor), '@media (max-width: 980px) {');
    assertSame(true, $start !== false);
    $end = strpos($css, '@media', (int) $start + 6);
    $narrow = substr($css, (int) $start, (int) $end - (int) $start);
    // Nessuna soglia di larghezza nasconde il menu: le azioni restano raggiungibili ovunque.
    assertSame(false, str_contains($narrow, 'code-nav-folder-menu'));
    assertSame(false, str_contains($narrow, 'code-nav-folder-popover'));
    assertSame(false, str_contains($narrow, 'display: none !important;'));
    // L'elenco cartelle resta scorribile (requisito preservato, non toccato dalla correzione).
    assertSame(true, str_contains($narrow, 'overflow-x: auto;'));
});
