<?php use App\Core\View; ?>

<section class="code-authorize" id="code-authorize">
    <p class="eyebrow">Code · ambiente locale</p>
    <h1>Apri una cartella</h1>
    <p>Scegli la cartella su cui vuoi lavorare. Si aprirà direttamente una sessione Code.</p>
    <p class="code-safety-note">AIManager accederà soltanto alla cartella autorizzata. Letture, proposte e verifiche sono controllate; modifiche, comandi e operazioni Git richiedono conferma. Code non è una sandbox del sistema operativo.</p>
    <form method="post" action="/code/open" data-code-folder-form>
        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
        <input id="code-folder-path" type="hidden" name="path" value="">
        <label for="code-folder-picker">Cartella</label>
        <button id="code-folder-picker" class="code-folder-picker" type="button" data-code-folder-picker>
            <span aria-hidden="true">▱</span> Seleziona una cartella…
        </button>
        <p class="code-folder-picker-status" data-code-folder-picker-status role="status" aria-live="polite"></p>
    </form>
</section>
