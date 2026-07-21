<?php

declare(strict_types=1);

use App\Core\Configuration\ConfigurationManager;
use App\Core\Providers\ModelRegistry;
use App\Providers\AgnesProvider;
use App\Services\AIProviderRegistry;

/**
 * Agnes AI: gateway OpenAI-compatibile su https://apihub.agnes-ai.com/v1, solo testo.
 *
 * Endpoint, autenticazione, modelli e streaming sono stati verificati sull'API reale il 2026-07-17
 * (`GET /v1/models` -> 5 modelli; `/chat/completions` in forma canonica con `usage`; SSE con
 * `delta.content` e `data: [DONE]`). Qui NON si tocca la rete: si verifica solo il cablaggio, che è
 * ciò che può rompersi in silenzio.
 */
test('agnes: registrato nel catalogo con endpoint e modello verificati', function () {
    $provider = AIProviderRegistry::fromConfig()->get('agnes');
    assertSame(true, $provider instanceof AgnesProvider, 'AgnesProvider assente da config/providers.php');
    assertSame('agnes', $provider->key());
    assertSame('Agnes AI', $provider->label());

    $defaults = $provider->defaults();
    // `/v1` sta nella base perché l'endpoint si compone come base . '/chat/completions'.
    assertSame('https://apihub.agnes-ai.com/v1', $defaults['base_url']);
    assertSame('agnes-2.0-flash', $defaults['model']);
    // Richiede una chiave e nasce SPENTA: si accende da /providers, come Cerebras.
    assertSame(true, $defaults['requires_key']);
    assertSame(false, $defaults['enabled']);
});

test('agnes: senza chiave non tenta nemmeno la chiamata', function () {
    $provider = new AgnesProvider();
    $config = $provider->defaults();
    $blocked = $provider->canAttempt(['api_key' => ''] + $config);
    assertSame(false, $blocked['ok']);
    assertSame('Chiave API mancante.', $blocked['message']);
    $allowed = $provider->canAttempt(['api_key' => 'sk-finta'] + $config);
    assertSame(true, $allowed['ok']);
});

test('agnes: la chiave viene dal suo AGNES_API_KEY e il modello da AGNES_DEFAULT_MODEL', function () {
    $path = sys_get_temp_dir() . '/aim_agnes_' . uniqid('', true) . '.env';
    file_put_contents($path, "AGNES_API_KEY=sk-chiave-di-prova\nAGNES_DEFAULT_MODEL=agnes-1.5-flash\n");
    try {
        $config = new ConfigurationManager($path);
        $runtime = $config->runtimeConfig('agnes', (new AgnesProvider())->defaults());
        assertSame('sk-chiave-di-prova', $runtime['api_key']);
        // L'env sovrascrive il default della classe: si cambia modello senza toccare il codice.
        assertSame('agnes-1.5-flash', $runtime['model']);
        // Nessuna contaminazione fra provider: la chiave di Agnes non finisce ad altri.
        assertSame('', $config->runtimeConfig('deepseek', ['model' => 'x'])['api_key']);
    } finally {
        @unlink($path);
    }
});

test('agnes: profilo di routing coerente con la lentezza misurata', function () {
    // ~7s FISSI a risposta, misurati sull'API reale anche per un solo token: non deve mai vincere
    // un fast-path. Se un domani diventasse veloce, questo test va aggiornato apposta.
    assertSame(1, ModelRegistry::profile('agnes')['latency']);
    // Gratuita: costo pieno in favore, tariffa zero.
    assertSame(5, ModelRegistry::profile('agnes')['cost']);
    assertSame([0.0, 0.0], ModelRegistry::costRate('agnes'));
    // È più lenta di ogni fast-path dichiarato.
    assertSame(true, ModelRegistry::profile('agnes')['latency'] < ModelRegistry::profile('groq')['latency']);
    assertSame(true, ModelRegistry::profile('agnes')['latency'] < ModelRegistry::profile('cerebras')['latency']);
    // Solo testo: i modelli immagine/video di Agnes non passano da questo provider.
    assertSame(0, ModelRegistry::profile('agnes')['vision']);
    // Finestra non documentata: default prudente, mai un valore inventato più grande.
    assertSame(32000, ModelRegistry::contextWindow('agnes'));
});
