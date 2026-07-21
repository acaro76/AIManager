<?php use App\Core\View; ?>
<?php
$enabled = (string) ($config['WEB_SEARCH_ENABLED'] ?? '1') !== '0';
$maxResults = (string) ($config['WEB_SEARCH_MAX_RESULTS'] ?? '5');
$timeout = (string) ($config['WEB_SEARCH_TIMEOUT'] ?? '8');
?>

<section class="provider-detail-head">
    <a class="button ghost small" href="/providers">Provider</a>
    <h2><?= View::e((string) $provider['label']) ?></h2>
</section>

<form class="panel form provider-detail-card" method="post" action="/providers/web/save" data-web-provider-form>
    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
    <input type="hidden" name="provider" value="<?= View::e($providerKey) ?>">

    <div class="panel-head">
        <div>
            <h2>Configurazione web</h2>
            <p class="empty">Fonti internet usate per ancorare le risposte AI.</p>
        </div>
        <label class="toggle"><input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>><span></span></label>
    </div>

    <input type="hidden" name="web_provider" value="tavily">
    <label>Provider web <input readonly value="Tavily"></label>

    <label>API key <?= $secret['env_key'] ? '<small>' . View::e($secret['env_key']) . '</small>' : '' ?>
        <span class="secret-input">
            <input name="api_key" type="password" value="" placeholder="<?= $secret['has_key'] ? View::e($secret['masked']) : 'Richiesta per Tavily' ?>" autocomplete="off" data-secret-input>
            <button class="button small ghost" type="button" data-secret-toggle>Mostra</button>
        </span>
    </label>

    <div class="form-grid">
        <label>Risultati <input name="max_results" type="number" min="1" max="10" value="<?= View::e($maxResults) ?>"></label>
        <label>Timeout <input name="timeout" type="number" min="3" max="20" value="<?= View::e($timeout) ?>"></label>
        <label>Test query <input name="query" value="OpenAI"></label>
    </div>

    <label>Stato <input readonly value="<?= View::e(strtoupper((string) ($provider['status'] ?? 'offline'))) ?><?= !empty($provider['has_key']) ? ' · chiave presente' : ' · chiave mancante' ?>"></label>

    <div class="actions">
        <button class="button" type="submit">Salva</button>
        <button class="button ghost" type="button" data-web-provider-test>Test ricerca</button>
        <a class="button ghost" href="/providers">Chiudi</a>
    </div>
    <p class="test-output" aria-live="polite"></p>
</form>
