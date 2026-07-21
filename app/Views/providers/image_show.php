<?php use App\Core\View; ?>
<?php $isCloudflare = $providerKey === 'cloudflare'; ?>

<section class="provider-detail-head">
    <a class="button ghost small" href="/providers">Provider</a>
    <h2><?= View::e((string) $provider['label']) ?></h2>
</section>

<form class="panel form provider-detail-card" method="post" action="/providers/image/save" data-image-provider-form>
    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
    <input type="hidden" name="provider" value="<?= View::e($providerKey) ?>">

    <div class="panel-head">
        <div>
            <h2>Configurazione immagini</h2>
            <p class="empty">Generazione immagini da prompt in chat (<?= View::e((string) $provider['note']) ?>).</p>
        </div>
        <label class="toggle" title="Attiva/disattiva la generazione immagini">
            <input type="checkbox" name="enabled" value="1" <?= !empty($masterEnabled) ? 'checked' : '' ?>><span></span>
        </label>
    </div>

    <?php if ($isCloudflare): ?>
        <label>Account ID <small>CLOUDFLARE_ACCOUNT_ID</small>
            <input name="account_id" value="<?= View::e((string) $accountId) ?>" placeholder="ID account Cloudflare" autocomplete="off">
        </label>

        <label>API token <small>CLOUDFLARE_API_TOKEN</small>
            <span class="secret-input">
                <input name="api_token" type="password" value="" placeholder="<?= !empty($cloudflareToken['has_key']) ? View::e((string) $cloudflareToken['masked']) : 'Token Workers AI (Read/Run)' ?>" autocomplete="off" data-secret-input>
                <button class="button small ghost" type="button" data-secret-toggle>Mostra</button>
            </span>
        </label>
    <?php else: ?>
        <label>API key <small>GOOGLE_API_KEY</small>
            <span class="secret-input">
                <input name="api_key" type="password" value="" placeholder="<?= !empty($geminiKey['has_key']) ? View::e((string) $geminiKey['masked']) : 'Chiave Google (condivisa con Gemini)' ?>" autocomplete="off" data-secret-input>
                <button class="button small ghost" type="button" data-secret-toggle>Mostra</button>
            </span>
        </label>
        <p class="empty">Prezzi, quote e requisiti di billing dipendono dal provider e possono cambiare. Verifica le condizioni ufficiali di Gemini e Cloudflare.</p>
    <?php endif; ?>

    <label>Modello <input name="model" value="<?= View::e((string) $provider['model']) ?>"></label>

    <label>Stato <input readonly value="<?= View::e(strtoupper((string) ($provider['status'] ?? 'offline'))) ?><?= !empty($provider['has_key']) ? ' · chiave presente' : ' · chiave mancante' ?>"></label>

    <div class="actions">
        <button class="button" type="submit">Salva</button>
        <button class="button ghost" type="button" data-image-provider-test>Test generazione</button>
        <a class="button ghost" href="/providers">Chiudi</a>
    </div>
    <p class="test-output" aria-live="polite"></p>
</form>
