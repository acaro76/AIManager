<?php

declare(strict_types=1);

use App\Core\Providers\LmStudioCatalog;
use App\Core\Providers\ProviderIntent;
use App\Providers\LmStudioProvider;

/**
 * Selezione automatica del modello LM Studio.
 *
 * Il difetto corretto: su un'installazione nuova i campi modello sono vuoti, il provider
 * inviava un nome vuoto e LM Studio rispondeva "No models loaded". Ora il nome si risolve
 * al momento della richiesta leggendo l'API nativa `/api/v1/models`.
 *
 * Nessuna rete in questi test: il lettore dell'API e' iniettato. LM Studio non viene mai
 * avviato, risvegliato o terminato.
 */

/** Risposta sintetica dell'API nativa, nella forma reale di LM Studio. */
function lmCatalogoFinto(array $override = []): string
{
    $models = $override ?: [
        ['type' => 'embedding', 'key' => 'text-embedding-bge-m3'],
        ['type' => 'llm', 'key' => 'qwen2.5-7b-instruct-mlx', 'loaded_instances' => [],
         'capabilities' => ['vision' => false]],
        ['type' => 'llm', 'key' => 'alfa-testuale', 'loaded_instances' => [],
         'capabilities' => ['vision' => false]],
        ['type' => 'llm', 'key' => 'zeta-visione', 'loaded_instances' => [],
         'capabilities' => ['vision' => true]],
        ['type' => 'embedding', 'key' => 'text-embedding-nomic-embed-text-v1.5'],
    ];

    return json_encode(['models' => $models], JSON_UNESCAPED_UNICODE) ?: '{}';
}

function lmProvider(string|false $risposta, ?int &$chiamate = null): LmStudioProvider
{
    $provider = new LmStudioProvider();
    $chiamate = 0;
    $provider->setCatalogFetcher(static function (string $url) use ($risposta, &$chiamate) {
        $chiamate++;
        return $risposta;
    });

    return $provider;
}

function lmConfig(array $extra = []): array
{
    return array_merge([
        'base_url' => 'http://127.0.0.1:1234/v1',
        'model' => '',
        'fast_model' => '',
        'code_model' => '',
        'vision_model' => '',
        'timeout_seconds' => 3,
    ], $extra);
}

test('lmstudio: configurazione completamente vuota -> sceglie un modello linguistico', function () {
    $provider = lmProvider(lmCatalogoFinto());
    $scelto = $provider->resolveModel(lmConfig());
    // deterministico: nessuno caricato, quindi il primo per chiave crescente
    assertSame('alfa-testuale', $scelto);
});

test('lmstudio: i modelli per incorporamenti non vengono mai scelti', function () {
    $soloEmbedding = [
        ['type' => 'embedding', 'key' => 'text-embedding-bge-m3'],
        ['type' => 'embedding', 'key' => 'text-embedding-nomic-embed-text-v1.5'],
    ];
    $provider = lmProvider(lmCatalogoFinto($soloEmbedding));
    assertSame('', $provider->resolveModel(lmConfig()));

    // e anche col catalogo completo, la scelta non cade mai su un embedding
    $catalogo = LmStudioCatalog::fromPayload(json_decode(lmCatalogoFinto(), true));
    foreach ($catalogo->llms() as $modello) {
        assertSame('llm', $modello['type']);
    }
    assertSame(3, count($catalogo->llms()));
});

test('lmstudio: preferisce un modello gia caricato', function () {
    $models = [
        ['type' => 'llm', 'key' => 'alfa-testuale', 'loaded_instances' => [],
         'capabilities' => ['vision' => false]],
        ['type' => 'llm', 'key' => 'zeta-gia-caricato', 'loaded_instances' => [['identifier' => 'x']],
         'capabilities' => ['vision' => false]],
    ];
    $provider = lmProvider(lmCatalogoFinto($models));
    // 'alfa' verrebbe prima in ordine alfabetico: vince quello caricato
    assertSame('zeta-gia-caricato', $provider->resolveModel(lmConfig()));
});

test('lmstudio: se nessuno e caricato la scelta e deterministica', function () {
    $provider = lmProvider(lmCatalogoFinto());
    $primo = $provider->resolveModel(lmConfig());
    for ($i = 0; $i < 5; $i++) {
        $altro = lmProvider(lmCatalogoFinto())->resolveModel(lmConfig());
        assertSame($primo, $altro, 'scelta non deterministica');
    }
    // ed e' il campo `key`, non il nome visualizzato
    assertSame('alfa-testuale', $primo);
});

test('lmstudio: una richiesta con immagini sceglie solo un modello con vision', function () {
    $provider = lmProvider(lmCatalogoFinto());
    $config = lmConfig(['_attachments' => [
        ['name' => 'schema.png', 'mime' => 'image/png', 'is_image' => true, 'absolute_path' => '/tmp/x.png'],
    ]]);
    assertSame('zeta-visione', $provider->resolveModel($config));

    // stessa cosa quando e' l'intento a richiedere vision
    $provider2 = lmProvider(lmCatalogoFinto());
    $intent = ProviderIntent::neutral();
    if (property_exists($intent, 'requiresVision')) {
        $conVisione = new ProviderIntent(...array_replace(
            get_object_vars($intent),
            ['requiresVision' => true]
        ));
        assertSame('zeta-visione', $provider2->resolveModel(lmConfig(['_intent' => $conVisione])));
    }
});

test('lmstudio: un modello configurato ancora disponibile viene mantenuto', function () {
    $provider = lmProvider(lmCatalogoFinto());
    $config = lmConfig(['model' => 'qwen2.5-7b-instruct-mlx', 'fast_model' => 'qwen2.5-7b-instruct-mlx']);
    assertSame('qwen2.5-7b-instruct-mlx', $provider->resolveModel($config));
});

test('lmstudio: un modello configurato non piu disponibile viene sostituito', function () {
    $provider = lmProvider(lmCatalogoFinto());
    $config = lmConfig(['model' => 'modello-rimosso', 'fast_model' => 'modello-rimosso']);
    $scelto = $provider->resolveModel($config);
    assertSame('alfa-testuale', $scelto);
    assertSame(false, $scelto === 'modello-rimosso');
});

test('lmstudio: nessun modello linguistico disponibile -> errore chiaro, nessuna chiamata', function () {
    $provider = lmProvider(lmCatalogoFinto([['type' => 'embedding', 'key' => 'solo-embedding']]));
    assertSame('', $provider->resolveModel(lmConfig()));

    $context = new class implements \App\Core\ContextEngine\ContextInterface {
        public function project(): array { return ['id' => 1, 'name' => 'P', 'is_system' => 1]; }
        public function session(): ?array { return null; }
        public function executionState(): ?\App\Core\Execution\ExecutionState { return null; }
        public function userRequest(): string { return 'ciao'; }
        public function items(): array { return []; }
        public function history(): array { return []; }
        public function toArray(): array { return []; }
    };
    $chiamato = false;
    $result = $provider->stream('ciao', $context, lmConfig(), static function () use (&$chiamato): void {
        $chiamato = true;
    });
    assertSame(false, $result->ok);
    assertSame('Nessun modello linguistico disponibile in LM Studio.', $result->error);
    assertSame(false, $chiamato, 'la richiesta e partita comunque');
});

test('lmstudio: API nativa non raggiungibile -> nessuna scelta inventata', function () {
    $provider = lmProvider(false);
    // senza catalogo si resta su cio' che dice la configurazione
    assertSame('', $provider->resolveModel(lmConfig()));
    assertSame('modello-configurato', $provider->resolveModel(
        lmConfig(['model' => 'modello-configurato', 'fast_model' => 'modello-configurato'])
    ));
});

test('lmstudio: una sola interrogazione dell API per richiesta', function () {
    $chiamate = 0;
    $provider = lmProvider(lmCatalogoFinto(), $chiamate);
    $provider->resolveModel(lmConfig());
    $provider->resolveModel(lmConfig());
    $provider->resolveModel(lmConfig());
    assertSame(1, $chiamate, 'interrogazioni duplicate sullo stesso endpoint');
});

test('lmstudio: URL nativo ricavato dall endpoint OpenAI-compatibile', function () {
    assertSame('http://127.0.0.1:1234/api/v1/models', LmStudioCatalog::nativeUrl('http://127.0.0.1:1234/v1'));
    assertSame('http://127.0.0.1:1234/api/v1/models', LmStudioCatalog::nativeUrl('http://127.0.0.1:1234/v1/'));
    assertSame('http://127.0.0.1:1234/api/v1/models', LmStudioCatalog::nativeUrl('http://127.0.0.1:1234'));
    assertSame('', LmStudioCatalog::nativeUrl(''));
});

test('lmstudio: la configurazione non viene modificata dalla risoluzione', function () {
    $provider = lmProvider(lmCatalogoFinto());
    $config = lmConfig();
    $prima = $config;
    $provider->resolveModel($config);
    assertSame($prima, $config, 'la configurazione e stata modificata');
});
