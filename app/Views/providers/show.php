<?php use App\Core\View; ?>

<section class="provider-detail-head">
    <a class="button ghost small" href="/providers">Provider</a>
    <h2><?= View::e($defaults['label']) ?></h2>
</section>

<section class="provider-start-note" aria-label="Istruzioni per <?= View::e($defaults['label']) ?>">
    <?php if ($providerKey === 'lmstudio'): ?>
        <strong>Provider locale.</strong> Installa e avvia LM Studio separatamente, carica un modello e abilita il server locale. Poi recupera i modelli, esegui il test e salva.
    <?php else: ?>
        <strong>Provider cloud.</strong> Usa una chiave API personale ottenuta dal sito del provider. La chiave viene conservata solo nel file locale <code>.env</code> e qui resta mascherata.
    <?php endif; ?>
</section>

<form class="panel form provider-detail-card" method="post" action="/providers/save" data-provider-form>
    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
    <input type="hidden" name="provider" value="<?= View::e($providerKey) ?>">
    <input type="hidden" name="label" value="<?= View::e($defaults['label']) ?>">
    <input type="hidden" name="return_to" value="/providers/show?provider=<?= View::e($providerKey) ?>">

    <div class="panel-head">
        <div>
            <h2>Configurazione</h2>
            <p class="empty">Impostazioni specifiche del provider.</p>
        </div>
        <label class="provider-enabled-toggle">Attivo <span class="toggle"><input type="checkbox" name="enabled" value="1" <?= !empty($config['enabled']) ? 'checked' : '' ?>><span></span></span></label>
    </div>

    <label>Endpoint <input name="base_url" value="<?= View::e($config['base_url'] ?? $defaults['base_url']) ?>"></label>
    <label>Modello
        <span class="model-picker">
            <input name="model" list="models-<?= View::e($providerKey) ?>" value="<?= View::e($config['model'] ?? $defaults['model']) ?>">
            <button class="button small ghost" type="button" data-provider-models>Modelli</button>
        </span>
        <datalist id="models-<?= View::e($providerKey) ?>"></datalist>
    </label>
    <label>API key <?= $secret['env_key'] ? '<small>' . View::e($secret['env_key']) . '</small>' : '' ?>
        <span class="secret-input">
            <input name="api_key" type="password" value="" placeholder="<?= $secret['has_key'] ? View::e($secret['masked']) : ($defaults['requires_key'] ? 'Richiesta' : 'Opzionale') ?>" autocomplete="off" data-secret-input>
            <button class="button small ghost" type="button" data-secret-toggle>Mostra</button>
        </span>
    </label>

    <div class="form-grid">
        <label>Timeout <input name="timeout_seconds" type="number" min="1" max="180" value="<?= View::e((string) ($config['timeout_seconds'] ?? $defaults['timeout_seconds'] ?? 30)) ?>"></label>
        <label>Temperatura <input name="temperature" type="number" min="0" max="2" step="0.1" value="<?= View::e((string) ($config['temperature'] ?? $defaults['temperature'] ?? 0.7)) ?>"></label>
        <label>Max token <input name="max_tokens" type="number" min="1" max="200000" value="<?= View::e((string) ($config['max_tokens'] ?? $defaults['max_tokens'] ?? 2048)) ?>"></label>
    </div>

    <div class="form-grid">
        <label>Top P <input name="top_p" type="number" min="0" max="1" step="0.05" value="<?= View::e((string) ($config['top_p'] ?? $defaults['top_p'] ?? 1.0)) ?>"></label>
        <label>Priorita <input name="priority" type="number" min="0" max="999" value="<?= View::e((string) ($config['priority'] ?? $defaults['priority'] ?? 50)) ?>"></label>
        <label>Modalita
            <select name="mode">
                <?php foreach (['auto' => 'AUTO', 'lmstudio' => 'LM STUDIO', 'openai' => 'OPENAI', 'claude' => 'CLAUDE', 'deepseek' => 'DEEPSEEK', 'gemini' => 'GEMINI', 'groq' => 'GROQ', 'openrouter' => 'OPENROUTER', 'cerebras' => 'CEREBRAS', 'agnes' => 'AGNES'] as $mode => $label): ?>
                    <option value="<?= $mode ?>" <?= ($config['mode'] ?? 'auto') === $mode ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>

    <label>Stato <input readonly value="<?= View::e(strtoupper((string) ($config['status'] ?? 'offline'))) ?><?= !empty($config['last_checked_at']) ? ' · test ' . View::e($config['last_checked_at']) : '' ?>"></label>

    <?php if (!empty($balance)): ?>
        <label>Saldo reale (da <?= View::e($providerKey) ?>) <input readonly value="<?= View::e(($balance['total'] ?? '?') . ' ' . ($balance['currency'] ?? '')) ?><?= empty($balance['available']) ? ' · account non disponibile' : '' ?>"></label>
    <?php endif; ?>

    <div class="provider-stats">
        <span>Richieste <strong><?= (int) ($stat['request_count'] ?? 0) ?></strong></span>
        <span>Tempo medio <strong><?= (int) ($stat['avg_response_time'] ?? 0) ?> ms</strong></span>
        <span>Token in <strong><?= (int) ($stat['tokens_input'] ?? 0) ?></strong></span>
        <span>Token out <strong><?= (int) ($stat['tokens_output'] ?? 0) ?></strong></span>
        <span>Costo stimato <strong><?= number_format((float) ($stat['estimated_cost'] ?? 0), 6) ?></strong></span>
        <span>Ultima <strong><?= View::e($stat['last_request'] ?? '-') ?></strong></span>
    </div>

    <?php if (!empty($config['last_error'])): ?><p class="empty">Ultimo errore: <?= View::e($config['last_error']) ?></p><?php endif; ?>

    <div class="actions">
        <button class="button" type="submit">Salva</button>
        <button class="button ghost" type="button" data-provider-test>Test</button>
        <a class="button ghost" href="/providers">Chiudi</a>
    </div>
    <p class="empty">Il test usa anche i valori appena inseriti senza salvarli. Dopo un test riuscito premi Salva.</p>
    <p class="test-output" aria-live="polite"></p>
</form>
