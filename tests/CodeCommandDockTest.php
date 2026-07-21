<?php

declare(strict_types=1);

/**
 * Regressioni del difetto LIVE dell'isola operazioni (Fase 6), rilevato allo smoke UI:
 * le card dei comandi TERMINATI restavano nell'isola, che è `position:absolute` sopra il log.
 * L'isola accumulava card e output (63px → 396px), copriva i messaggi e, saturata, spingeva i
 * pulsanti dell'operazione pendente sotto il composer, rendendoli non cliccabili.
 *
 * Invariante ripristinata: nell'isola vive SOLO l'operazione `pending`/`running`; ogni esito torna
 * nel turno d'origine, senza azioni, nella stessa forma resa dal server dopo un refresh.
 *
 * Il client non ha un runner JS: si verifica il sorgente, come per le altre regressioni del client
 * Code (vedi CodeChatSurfaceTest / CodePhase2UxTest).
 */
$codeChatJs = static fn (): string => (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/code-chat.js');

/** Ritaglia il blocco fra due marcatori, per asserire sul punto giusto e non su tutto il file. */
$block = static function (string $js, string $from, ?string $to = null): string {
    $at = strpos($js, $from);
    assertSame(true, $at !== false, 'blocco non trovato: ' . $from);
    $end = $to === null ? false : strpos($js, $to, (int) $at);

    return substr($js, (int) $at, $end === false ? 520 : $end - (int) $at);
};

test('isola comandi: solo pending/running, con un unico vocabolario condiviso', function () use ($codeChatJs) {
    $js = $codeChatJs();
    // Un solo punto di verità per "operazione corrente": usato sia al caricamento sia a runtime.
    assertSame(true, str_contains($js, "function isCommandActive(card) { return ['pending', 'running'].includes(card.dataset.commandState || ''); }"));
    // L'hoisting iniziale nell'isola passa dallo stesso predicato (niente elenco duplicato che diverge).
    assertSame(true, str_contains($js, "const commandActive = card.matches('[data-code-command]') && isCommandActive(card);"));
    // Nessuna lista di stati riscritta a mano altrove nel client.
    assertSame(1, substr_count($js, "['pending', 'running']"));
});

test('isola comandi: un esito lascia l\'isola e torna nel turno d\'origine, senza azioni', function () use ($codeChatJs, $block) {
    $js = $codeChatJs();
    $settle = $block($js, 'function settleCommandCard(card)', 'function commandMessage(');
    // Finché è in corso, la card NON si muove: l'isola resta la sede dell'operazione corrente.
    assertSame(true, str_contains($settle, 'if (isCommandActive(card)) { syncProcessControl(); return; }'));
    // Esito: niente pulsanti (come il render server-side, che li omette fuori da `pending`).
    assertSame(true, str_contains($settle, "card.querySelector('[data-code-command-actions]')?.replaceChildren();"));
    // Torna nel turno che l'ha proposta; se il turno non c'è più, sparisce: mai orfana nell'isola.
    assertSame(true, str_contains($settle, 'const origin = card._codeOrigin;'));
    assertSame(true, str_contains($settle, 'if (origin && origin.isConnected) { origin.appendChild(card); } else { card.remove(); }'));
    // Lo spazio riservato in fondo al log segue subito la nuova altezza dell'isola.
    assertSame(true, str_contains($settle, 'syncDockSpace();'));
});

test('isola comandi: conferma e rifiuto passano entrambi dal settle, nessun accumulo', function () use ($codeChatJs, $block) {
    $js = $codeChatJs();
    $run = $block($js, "if (event.target.closest('[data-code-command-run]'))", "if (event.target.closest('[data-code-command-reject]'))");
    // Il difetto: l'esito veniva scritto sulla card e la card restava nell'isola per sempre.
    assertSame(true, str_contains($run, 'settleCommandCard(card);'));
    $reject = $block($js, "if (event.target.closest('[data-code-command-reject]'))", '    });');
    // Il rifiuto non cancella più la card: lascia traccia nel turno come dopo un refresh.
    assertSame(true, str_contains($reject, "card.dataset.commandState = 'rejected';"));
    assertSame(true, str_contains($reject, "commandLabel(card, 'rifiutato');"));
    assertSame(true, str_contains($reject, 'commandMessage(card, rejectedOperationMessage);'));
    assertSame(true, str_contains($reject, 'settleCommandCard(card);'));
    assertSame(false, str_contains($js, "if (res.status === 'rejected') { card.remove(); syncProcessControl(); }"));
});

test('operazioni pending: lock e rifiuto umano sono condivisi da Git, comando e processo', function () use ($codeChatJs) {
    $js = $codeChatJs();
    $view = (string) file_get_contents(dirname(__DIR__) . '/app/Views/code/_chat.php');

    assertSame(1, substr_count($js, "const rejectedOperationMessage = 'Proposta rifiutata. Nessuna modifica eseguita.';"));
    assertSame(true, str_contains($js, 'function beginOperationAction(card)'));
    assertSame(true, str_contains($js, "card.dataset.codeOperationBusy === '1'"));
    assertSame(5, substr_count($js, 'beginOperationAction(card)')); // definizione + 4 azioni mutualmente esclusive
    assertSame(true, str_contains($js, 'commandMessage(card, rejectedOperationMessage);'));
    assertSame(true, str_contains($js, 'processMessage(card, rejectedOperationMessage);'));
    assertSame(true, str_contains($js, 'msg.textContent=rejectedOperationMessage;'));
    assertSame(3, substr_count($view, 'Proposta rifiutata. Nessuna modifica eseguita.'));
});

test('operazioni pending: Git partecipa alla decisione aperta e le card SSE sono deduplicate per id', function () use ($codeChatJs) {
    $js = $codeChatJs();

    assertSame(true, str_contains($js, "[data-code-git][data-state=\"pending\"], [data-code-git][data-state=\"commit_pending\"]"));
    assertSame(true, str_contains($js, 'function hasOperationCard(type, id)'));
    foreach (['patch', 'command', 'process', 'git'] as $type) {
        assertSame(true, str_contains($js, "if (!hasOperationCard('{$type}',"), $type);
    }
});

test('Git live: la transizione stage → commit assume l’identità persistita del commit figlio', function () use ($codeChatJs) {
    $js = $codeChatJs();

    assertSame(true, str_contains($js, "card.dataset.operationId=String(c.git.operation_id||card.dataset.operationId||'')"));
    assertSame(true, str_contains($js, "card.dataset.digest=String(c.git.digest||'')"));
    assertSame(true, str_contains($js, "card.dataset.kind='commit'"));
});

test('isola comandi: il log riserva lo spazio dell\'isola senza muovere il composer', function () use ($codeChatJs, $block) {
    $js = $codeChatJs();
    $sync = $block($js, 'function syncDockSpace(forceBottom = false)', 'function hasPendingDecision()');
    // Il padding di base resta quello del CSS (cambia con le media query): si rilegge azzerando l'inline.
    assertSame(true, str_contains($sync, "log.style.paddingBottom = '';"));
    assertSame(true, str_contains($sync, 'const base = parseFloat(getComputedStyle(log).paddingBottom) || 0;'));
    // Lo spazio segue l'altezza EFFETTIVA dell'isola, non una costante.
    assertSame(true, str_contains($sync, 'const dockHeight = operationDock.getBoundingClientRect().height;'));
    assertSame(true, str_contains($sync, "log.style.paddingBottom = (base + dockHeight + 8) + 'px';"));
    // Chi era in fondo resta in fondo: il messaggio nuovo non finisce sotto l'isola.
    assertSame(true, str_contains($sync, 'if (atBottom) log.scrollTop = log.scrollHeight;'));
    // Ogni variazione d'altezza dell'isola è intercettata, non solo quelle previste dai click.
    assertSame(true, str_contains($js, "if (typeof ResizeObserver === 'function') new ResizeObserver(() => syncDockSpace()).observe(operationDock);"));
    // Si tocca SOLO il padding del log: il composer e l'isola non vengono spostati da JS.
    assertSame(false, str_contains($js, '.code-composer-stack.style'));
    assertSame(false, str_contains($js, 'operationDock.style'));
});

test('isola comandi: la card live è un box autonomo e forza il fondo dopo il layout', function () use ($codeChatJs) {
    $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
    // Senza un box proprio testo e pulsanti debordavano dal dock, rendendo falsa la sua altezza visiva.
    assertSame(true, str_contains($css, '.code-command-card {'));
    assertSame(true, str_contains($css, 'box-sizing: border-box;'));
    assertSame(true, str_contains($css, '.code-command-summary {'));
    assertSame(true, str_contains($css, 'grid-template-columns: auto minmax(0, 1fr) auto;'));
    assertSame(true, str_contains($css, '.code-command-actions { display: flex; flex-wrap: wrap;'));
    // La card nasce fuori dal flusso: la misura va presa nel frame successivo e il fondo va forzato.
    $js = $codeChatJs();
    assertSame(true, str_contains($js, 'window.requestAnimationFrame(() => syncDockSpace(true));'));
    assertSame(true, str_contains($js, 'function syncDockSpace(forceBottom = false)'));
});

test('isola comandi: l\'output resta confinato anche fuori dall\'isola', function () {
    $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
    // Nell'isola il tetto è sulla card; nel turno la card non ha più quel tetto, quindi un output
    // vicino al cap (256 KiB) allagherebbe il log: va vincolato l'elemento di output.
    assertSame(true, str_contains($css, '.code-operation-dock > .code-command-card { margin: 0; max-height: min(42vh, 430px); overflow: auto; }'));
    assertSame(true, str_contains($css, '.code-chat-log .code-command-output { max-height: min(32vh, 320px); overflow: auto; }'));
});

test('cronologia processi: ogni card resta ancorata al turno che l\'ha proposta', function () use ($codeChatJs, $block) {
    $js = $codeChatJs();
    $initial = $block($js, 'if (operationDock) {', '/**');
    assertSame(true, str_contains($initial, "log.querySelectorAll('[data-code-patch], [data-code-command]')"));
    assertSame(false, str_contains($initial, 'processActive'));

    $live = $block($js, 'if (payload.process && assistant)', 'if (fileInput)');
    assertSame(true, str_contains($live, 'assistant.article.appendChild(processCard);'));
    assertSame(false, str_contains($live, 'operationDock'));

    $settle = $block($js, 'function settleProcessCard(card)', 'async function processRequest');
    assertSame(false, str_contains($settle, 'appendChild'));
    assertSame(false, str_contains($settle, '_codeOrigin'));
    assertSame(true, str_contains($js, "log.querySelector('[data-code-process][data-process-state=\"pending\"]')"));
});

test('processi in esecuzione: indicatore e popover sono limitati alla sessione corrente', function () use ($codeChatJs) {
    $view = (string) file_get_contents(dirname(__DIR__) . '/app/Views/code/_chat.php');
    $controller = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/CodeController.php');
    $js = $codeChatJs();

    assertSame(true, str_contains($view, 'data-code-running-processes-toggle'));
    assertSame(true, str_contains($view, 'data-code-running-processes-menu'));
    assertSame(true, str_contains($view, 'data-code-running-process-stop'));
    // Scope esatto: workspace + session_id correnti, nessuna lista globale o di altre chat.
    assertSame(true, str_contains($controller, 'listActive($workspace->id, $sessionId)'));
    assertSame(true, str_contains($js, "shell.querySelector('[data-code-running-processes-toggle]')"));
    assertSame(true, str_contains($js, "runningProcesses?.addEventListener('click'"));
    assertSame(true, str_contains($js, "processRequest('/code/process/stop', runningItem)"));
    assertSame(true, str_contains($js, "['starting', 'running'].includes(state)"));
    assertSame(true, str_contains($js, "event.key === 'Escape'"));
    assertSame(true, str_contains($js, "processLog(card, '')"));
});

test('indicatore della chat: distingue processi attivi e decisioni in attesa nello stesso menu', function () use ($codeChatJs) {
    $view = (string) file_get_contents(dirname(__DIR__) . '/app/Views/code/_chat.php');
    $js = $codeChatJs();

    assertSame(true, str_contains($view, '>Processi attivi</strong>'));
    assertSame(true, str_contains($view, '>In attesa di decisione</strong>'));
    assertSame(true, str_contains($view, 'data-code-pending-operations-list'));
    assertSame(true, str_contains($js, 'function pendingOperationCards()'));
    assertSame(true, str_contains($js, 'const count = processCount + pendingCount;'));
    assertSame(true, str_contains($js, "show.textContent = 'Mostra'"));
    assertSame(true, str_contains($js, "cancel.textContent = 'Annulla'"));
});

test('indicatore della chat: Mostra torna alla card e Annulla riusa il suo rifiuto tipizzato', function () use ($codeChatJs) {
    $js = $codeChatJs();

    assertSame(true, str_contains($js, "card.scrollIntoView({ behavior: 'smooth', block: 'center' })"));
    assertSame(true, str_contains($js, "card.classList.add('code-operation-highlight')"));
    assertSame(true, str_contains($js, "[data-code-patch-reject], [data-code-command-reject], [data-code-process-reject], [data-code-git-reject]"));
    assertSame(true, str_contains($js, 'reject?.click();'));
    assertSame(true, str_contains($js, 'new MutationObserver(() =>'));
});
