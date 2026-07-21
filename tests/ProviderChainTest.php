<?php

declare(strict_types=1);

use App\Core\Providers\ProviderChain;
use App\Core\Providers\ProviderConfigStoreInterface;

/**
 * Catena di fallback dei servizi ausiliari (classificatore web, consolidamento Brain).
 *
 * Prima avevano una catena FISSA di due nomi dall'env (`deepseek,cerebras`): se quei due erano giu'
 * o spenti, la funzione si spegneva — mentre la chat principale cascava su tutti i provider. Con
 * Cerebras a pagamento dal 2026-08-16 il caso diventa concreto: spenta lei, restava un anello solo.
 * Ora la catena esplicita resta prima e in coda entrano gli altri provider utilizzabili.
 */
$store = static function (array $slugs): ProviderConfigStoreInterface {
    return new class ($slugs) implements ProviderConfigStoreInterface {
        public function __construct(private readonly array $slugs) {}
        public function find(string $provider): ?array { return null; }
        public function enabled(): array { return array_map(static fn (string $s): array => ['provider' => $s], $this->slugs); }
        public function updateHealth(string $provider, string $status, string $error = ''): void {}
        public function markRequest(string $provider, string $error = ''): void {}
    };
};

test('catena: la preferenza dell\'env resta PRIMA, la coda si accoda senza duplicare', function () {
    $chain = ProviderChain::resolve('deepseek,cerebras', ['groq', 'cerebras', 'agnes']);
    // L'ordine scelto dall'utente non viene riordinato, e cerebras non compare due volte.
    assertSame(['deepseek', 'cerebras', 'groq', 'agnes'], $chain);
});

test('catena: env sporco (spazi, maiuscole, vuoti, doppioni) normalizzato', function () {
    assertSame(['deepseek', 'groq'], ProviderChain::resolve(' DeepSeek , , groq ,DEEPSEEK, '));
    // Env vuoto: resta solo la coda automatica, la funzione non muore.
    assertSame(['groq', 'agnes'], ProviderChain::resolve('', ['groq', 'agnes']));
    // Nessuna delle due: catena vuota -> il chiamante spegne la feature invece di tentare a vuoto.
    assertSame([], ProviderChain::resolve('   ', []));
});

test('coda: solo provider abilitati E compatibili con la POST OpenAI', function () use ($store) {
    $tail = ProviderChain::fallbackTail($store(['agnes', 'cerebras', 'deepseek', 'gemini', 'groq', 'lmstudio', 'openrouter']));
    // Gemini e Claude parlano un altro protocollo: accodarli sprecherebbe un giro HTTP per un 400.
    assertSame(false, in_array('gemini', $tail, true));
    assertSame(false, in_array('claude', $tail, true));
    // LM Studio ha una classe propria, non e' un OpenAICompatibleProvider: resta fuori.
    assertSame(false, in_array('lmstudio', $tail, true));
    assertSame(['cerebras', 'deepseek', 'groq', 'openrouter', 'agnes'], $tail);
});

test('coda: ordinata per punteggio, i piu\' veloci ed economici per primi', function () use ($store) {
    $tail = ProviderChain::fallbackTail($store(['agnes', 'openrouter', 'groq']));
    // groq (latency 5 + cost 4 = 9) > openrouter (2+5 = 7) > agnes (1+5 = 6): Agnes e' l'ULTIMA
    // risorsa perche' ha ~7s fissi di latenza, misurati.
    assertSame(['groq', 'openrouter', 'agnes'], $tail);
});

test('coda: un provider spento sparisce dalla coda', function () use ($store) {
    // Il caso del 2026-08-16: Cerebras disabilitata.
    $tail = ProviderChain::fallbackTail($store(['agnes', 'deepseek', 'groq', 'openrouter']));
    assertSame(false, in_array('cerebras', $tail, true));
    // Restano comunque QUATTRO anelli veri: la decisione non si spegne.
    assertSame(['deepseek', 'groq', 'openrouter', 'agnes'], $tail);
});

test('catena: senza nessun provider abilitato non si inventa nulla', function () use ($store) {
    assertSame([], ProviderChain::fallbackTail($store([])));
    // Restano solo i nomi dell'env: saranno saltati a runtime perche' non abilitati.
    assertSame(['deepseek', 'cerebras'], ProviderChain::resolve('deepseek,cerebras', ProviderChain::fallbackTail($store([]))));
});

test('catena: il deadline complessivo non cresce col numero dei provider', function () {
    $deadline = ProviderChain::deadline(8, 20, 100.0);
    // Due timeout pieni, non cinque provider moltiplicati per otto secondi.
    assertSame(116.0, $deadline);
    assertSame(8, ProviderChain::remainingTimeout($deadline, 8, 100.0));
    assertSame(3, ProviderChain::remainingTimeout($deadline, 8, 113.2));
    assertSame(0, ProviderChain::remainingTimeout($deadline, 8, 116.0));

    // Anche un timeout configurato enorme resta sotto il tetto esplicito del servizio.
    assertSame(160.0, ProviderChain::deadline(60, 60, 100.0));
});

test('catena: i due servizi ausiliari usano il pezzo condiviso, non piu\' una copia a testa', function () {
    foreach (['WebSearchIntentService', 'BrainConsolidationService'] as $service) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/app/Services/' . $service . '.php');
        assertSame(true, str_contains($src, 'ProviderChain::resolve('), $service);
        assertSame(true, str_contains($src, 'ProviderChain::fallbackTail($this->configs)'), $service);
        assertSame(true, str_contains($src, 'ProviderChain::deadline('), $service);
        assertSame(true, str_contains($src, 'ProviderChain::remainingTimeout('), $service);
        // La catena fissa duplicata non deve tornare per copia-incolla.
        assertSame(false, str_contains($src, "foreach (explode(',', \$raw) as \$name) {"), $service);
    }
});
