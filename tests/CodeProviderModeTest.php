<?php

declare(strict_types=1);

use App\Core\Code\CodeContext;
use App\Core\Code\CodeProviderMode;
use App\Core\Configuration\ConfigurationManager;
use App\Core\ContextEngine\ContextItem;
use App\Core\Providers\Policy\AllowAllRoutingPolicy;
use App\Core\Providers\ProviderConfigStoreInterface;
use App\Core\Providers\ProviderIntent;
use App\Core\Providers\ProviderManager;
use App\Services\AIProviderRegistry;

/**
 * F1.8a — provider mode della chat Code, deciso dalla CONFIGURAZIONE del server.
 * Test ISOLATI: config store FAKE (nessun DB reale, nessuna tabella provider toccata) e
 * nessuna chiamata di rete a provider cloud.
 */

$root = dirname(__DIR__);
$configPath = tempnam(sys_get_temp_dir(), 'aimanager_code_provider_');
if ($configPath === false) {
    throw new RuntimeException('File temporaneo per la configurazione non disponibile.');
}
register_shutdown_function(static function () use ($configPath): void {
    @unlink($configPath);
});

// Store fake: LM Studio + provider cloud abilitati, come nell'installazione reale.
$fakeStore = new class implements ProviderConfigStoreInterface {
    public array $enabled = [
        ['provider' => 'lmstudio', 'enabled' => 1],
        ['provider' => 'deepseek', 'enabled' => 1],
        ['provider' => 'gemini', 'enabled' => 1],
        ['provider' => 'openrouter', 'enabled' => 1],
        ['provider' => 'groq', 'enabled' => 1],
        ['provider' => 'cerebras', 'enabled' => 1],
    ];

    public function find(string $provider): ?array
    {
        foreach ($this->enabled as $row) {
            if ($row['provider'] === $provider) {
                return $row;
            }
        }
        return null;
    }

    public function enabled(): array
    {
        return $this->enabled;
    }

    public function updateHealth(string $provider, string $status, string $error = ''): void
    {
        throw new \RuntimeException('il test non deve MAI scrivere sulle configurazioni provider');
    }

    public function markRequest(string $provider, string $error = ''): void
    {
        throw new \RuntimeException('il test non deve MAI scrivere sulle configurazioni provider');
    }
};

$manager = new ProviderManager(
    AIProviderRegistry::fromConfig(),
    $fakeStore,
    new ConfigurationManager($configPath),
    new AllowAllRoutingPolicy()
);

// L'intento esattamente come lo costruisce CodeChatService (requiresVision SEMPRE false).
$codeIntent = new ProviderIntent(
    'code',
    complexity: 4,
    latency: 2,
    cost: 4,
    contextSize: 4,
    requiresTools: true,
    requiresFiles: false,
    requiresReasoning: true,
    requiresVision: false,
    requiresWeb: false,
    requiresKnowledge: false,
    requiresDeepReasoning: false,
);

$ctx = new CodeContext('sys', 'dove sta il login', [new ContextItem('code', 'context', 'Contesto Code', 'x', 100)], [], 1, 1, 'campione');

// Chiama il selettore di candidati REALE (nessuna rete: per mode != auto ritorna subito).
$candidates = static function (string $mode) use ($manager, $codeIntent, $ctx): array {
    $m = new ReflectionMethod($manager, 'candidates');
    $rows = $m->invoke($manager, $mode, $codeIntent, '', $ctx, 'dove sta il login');
    return array_map(static fn (array $r): string => (string) $r['provider'], $rows);
};

// --- risoluzione della configurazione ---

test('CodeProviderMode: configurazione assente => auto (comportamento invariato)', function () {
    assertSame('auto', CodeProviderMode::resolve(null, ''));
    assertSame('auto', CodeProviderMode::resolve('', ''));
    assertSame('auto', CodeProviderMode::resolve('   ', ''));
});

test('CodeProviderMode: la config code.provider_mode viene usata', function () {
    assertSame('lmstudio', CodeProviderMode::resolve('lmstudio', ''));
    assertSame('auto', CodeProviderMode::resolve('auto', ''));
});

test('CodeProviderMode: l\'ambiente ha la precedenza sulla config', function () {
    assertSame('lmstudio', CodeProviderMode::resolve('auto', 'lmstudio'));
    assertSame('auto', CodeProviderMode::resolve('lmstudio', 'auto'));
    // env vuoto/whitespace => si ricade sulla config, non sul default
    assertSame('lmstudio', CodeProviderMode::resolve('lmstudio', '   '));
});

test('CodeProviderMode: normalizza trim e maiuscole', function () {
    assertSame('lmstudio', CodeProviderMode::resolve(null, '  LMStudio  '));
    assertSame('lmstudio', CodeProviderMode::resolve('  LMSTUDIO ', ''));
});

test('CodeProviderMode: un valore MAL FORMATO non ricade su auto (fail closed)', function () {
    foreach (['lm studio', 'lmstudio; drop', "lm\nstudio", 'lmstudio!', str_repeat('a', 33), '../etc'] as $bad) {
        $resolved = CodeProviderMode::resolve(null, $bad);
        assertSame(CodeProviderMode::INVALID, $resolved, $bad);
        assertSame(false, $resolved === 'auto', 'non deve mai ricadere su auto: ' . $bad);
    }
});

// --- effetto REALE sulla selezione dei candidati ---

test('candidati: auto resta INVARIATO (anti-regressione, fallback cloud incluso)', function () use ($candidates) {
    $list = $candidates('auto');
    assertSame(true, count($list) > 1, 'auto deve mantenere la catena di fallback');
    assertSame(true, in_array('lmstudio', $list, true));
    assertSame(true, in_array('deepseek', $list, true)); // i cloud restano candidati, come prima
});

test('candidati: mode=lmstudio => ESCLUSIVAMENTE LM Studio (nessun fallback cloud)', function () use ($candidates) {
    assertSame(['lmstudio'], $candidates('lmstudio'));
});

test('candidati: un valore invalido => ZERO candidati (mai un cloud, mai auto)', function () use ($candidates) {
    assertSame([], $candidates(CodeProviderMode::INVALID));
    assertSame([], $candidates('bogus'));          // ben formato ma non registrato
    assertSame([], $candidates('lm studio'));      // mal formato
});

test('candidati: nessun provider cloud e\' raggiungibile con mode=lmstudio', function () use ($candidates) {
    $list = $candidates('lmstudio');
    foreach (['deepseek', 'gemini', 'openrouter', 'groq', 'cerebras', 'openai', 'claude'] as $cloud) {
        assertSame(false, in_array($cloud, $list, true), $cloud);
    }
});

// --- wiring del controller ---

test('CodeController: passa il provider mode risolto a CodeChatService::stream()', function () use ($root) {
    $c = (string) file_get_contents($root . '/app/Controllers/CodeController.php');
    assertSame(true, str_contains($c, "CodeProviderMode::resolve(\$this->app->config['code']['provider_mode'] ?? null)"));
    assertSame(true, str_contains($c, '$providerMode'));
    // La UI mostra il provider usato, ma non consente di cambiarne la configurazione.
    $view = (string) file_get_contents($root . '/app/Views/code/_chat.php');
    assertSame(true, str_contains($view, 'data-code-provider-badge'));
    assertSame(false, str_contains($view, '<select'));
    assertSame(false, str_contains($view, 'name="provider"'));
});

test('config: code.provider_mode esiste e vale auto di default', function () use ($root) {
    $config = require $root . '/config/app.php';
    assertSame('auto', $config['code']['provider_mode']);
});
