<?php use App\Core\View; ?>
<?php
$sourceFor = static fn (string $key): string => [
    'lmstudio' => 'Locale',
    'openai' => 'OpenAI',
    'claude' => 'Anthropic',
    'gemini' => 'Google',
    'groq' => 'Groq',
    'openrouter' => 'OpenRouter',
    'cerebras' => 'Cerebras',
    'deepseek' => 'DeepSeek',
    'agnes' => 'Agnes AI',
][$key] ?? 'API';

$orderedKeys = array_keys($catalog);
usort($orderedKeys, static function (string $a, string $b) use ($configs, $catalog): int {
    $statusOf = static function (string $key) use ($configs, $catalog): string {
        $config = $configs[$key] ?? $catalog[$key] ?? [];
        return strtolower((string) ($config['status'] ?? 'offline'));
    };
    $rankOf = static fn (string $status): int => $status === 'online' ? 0 : 1;
    return $rankOf($statusOf($a)) <=> $rankOf($statusOf($b));
});
?>

<section class="provider-section-head">
    <h2>Provider AI</h2>
</section>

<section class="provider-start-note" aria-label="Guida rapida Provider">
    <strong>Da dove iniziare:</strong>
    usa LM Studio per lavorare in locale, oppure un provider cloud con una tua chiave API.
    Apri una scheda, attivala, esegui il test e salva. Basta un solo provider funzionante.
</section>

<section class="panel provider-compact-panel">
    <div class="table-wrap">
        <table class="provider-compact-table">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Modello</th>
                    <th>Fonte</th>
                    <th>Toggle</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orderedKeys as $key): ?>
                    <?php $defaults = $catalog[$key]; ?>
                    <?php $config = $configs[$key] ?? $defaults; ?>
                    <?php $href = '/providers/show?provider=' . rawurlencode((string) $key); ?>
                    <?php $status = strtolower((string) ($config['status'] ?? 'offline')); ?>
                    <tr>
                        <td>
                            <a class="provider-row-link" href="<?= View::e($href) ?>"><?= View::e($defaults['label']) ?></a>
                            <small class="provider-row-status status-<?= View::e($status) ?>"><?= View::e(strtoupper($status)) ?></small>
                        </td>
                        <td><a class="provider-row-link muted" href="<?= View::e($href) ?>"><?= View::e((string) ($config['model'] ?? $defaults['model'] ?? '-')) ?></a></td>
                        <td><a class="provider-row-link muted" href="<?= View::e($href) ?>"><?= View::e($sourceFor((string) $key)) ?></a></td>
                        <td>
                            <form method="post" action="/providers/toggle" class="provider-toggle-form">
                                <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                                <input type="hidden" name="provider" value="<?= View::e($key) ?>">
                                <input type="hidden" name="enabled" value="<?= empty($config['enabled']) ? '1' : '0' ?>">
                                <label class="toggle" title="<?= empty($config['enabled']) ? 'Attiva' : 'Disattiva' ?>">
                                    <input type="checkbox" <?= !empty($config['enabled']) ? 'checked' : '' ?> onchange="this.form.submit()">
                                    <span></span>
                                </label>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="provider-section-head">
    <h2>Provider immagini</h2>
</section>

<section class="panel provider-compact-panel">
    <div class="table-wrap">
        <table class="provider-compact-table">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Modello</th>
                    <th>Fonte</th>
                    <th>Toggle</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (($imageProviders ?? []) as $key => $provider): ?>
                    <?php $href = '/providers/image/show?provider=' . rawurlencode((string) $key); ?>
                    <?php $status = strtolower((string) ($provider['status'] ?? 'offline')); ?>
                    <tr>
                        <td>
                            <a class="provider-row-link" href="<?= View::e($href) ?>"><?= View::e((string) $provider['label']) ?></a>
                            <small class="provider-row-status status-<?= View::e($status) ?>"><?= View::e(strtoupper($status)) ?></small>
                        </td>
                        <td><a class="provider-row-link muted" href="<?= View::e($href) ?>"><?= View::e((string) ($provider['model'] ?? '-')) ?> · <?= View::e((string) ($provider['note'] ?? '')) ?></a></td>
                        <td><a class="provider-row-link muted" href="<?= View::e($href) ?>"><?= View::e((string) ($provider['source'] ?? 'Immagini')) ?></a></td>
                        <td>
                            <form method="post" action="/providers/image/toggle" class="provider-toggle-form">
                                <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                                <input type="hidden" name="provider" value="<?= View::e($key) ?>">
                                <input type="hidden" name="enabled" value="<?= empty($provider['in_chain']) ? '1' : '0' ?>">
                                <label class="toggle" title="<?= empty($provider['in_chain']) ? 'Usa per le immagini' : 'Escludi dalle immagini' ?>">
                                    <input type="checkbox" <?= !empty($provider['in_chain']) ? 'checked' : '' ?> onchange="this.form.submit()">
                                    <span></span>
                                </label>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="provider-section-head">
    <h2>Provider web</h2>
</section>

<section class="panel provider-compact-panel">
    <div class="table-wrap">
        <table class="provider-compact-table">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Uso</th>
                    <th>Fonte</th>
                    <th>Toggle</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (($webProviders ?? []) as $key => $provider): ?>
                    <?php $href = '/providers/web/show?provider=' . rawurlencode((string) $key); ?>
                    <?php $status = strtolower((string) ($provider['status'] ?? 'offline')); ?>
                    <tr>
                        <td>
                            <a class="provider-row-link" href="<?= View::e($href) ?>"><?= View::e((string) $provider['label']) ?></a>
                            <small class="provider-row-status status-<?= View::e($status) ?>"><?= View::e(strtoupper($status)) ?></small>
                        </td>
                        <td><a class="provider-row-link muted" href="<?= View::e($href) ?>"><?= !empty($provider['active']) ? 'Attivo' : 'Disponibile' ?> · <?= (int) ($provider['max_results'] ?? 5) ?> risultati</a></td>
                        <td><a class="provider-row-link muted" href="<?= View::e($href) ?>"><?= View::e((string) ($provider['source'] ?? 'Web')) ?></a></td>
                        <td>
                            <form method="post" action="/providers/web/toggle" class="provider-toggle-form">
                                <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                                <input type="hidden" name="provider" value="<?= View::e($key) ?>">
                                <input type="hidden" name="enabled" value="<?= empty($provider['enabled']) ? '1' : '0' ?>">
                                <label class="toggle" title="<?= empty($provider['enabled']) ? 'Attiva' : 'Disattiva' ?>">
                                    <input type="checkbox" <?= !empty($provider['enabled']) ? 'checked' : '' ?> onchange="this.form.submit()">
                                    <span></span>
                                </label>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
