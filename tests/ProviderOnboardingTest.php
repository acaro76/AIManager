<?php

declare(strict_types=1);

use App\Core\Configuration\ConfigurationManager;
use App\Core\ContextEngine\ContextInterface;
use App\Core\Providers\Policy\AllowAllRoutingPolicy;
use App\Core\Providers\ProviderConfigStoreInterface;
use App\Core\Providers\ProviderManager;
use App\Providers\AbstractProvider;
use App\Services\AIProviderResult;
use App\Services\AIProviderRegistry;

final class ProviderOnboardingTestProvider extends AbstractProvider
{
    public function key(): string { return 'onboarding-test'; }
    public function label(): string { return 'Onboarding test'; }
    protected function baseUrl(): string { return 'https://saved.invalid'; }
    protected function defaultModel(): string { return 'saved-model'; }
    public function stream(string $prompt, ContextInterface $context, array $config, callable $onDelta): AIProviderResult
    {
        return AIProviderResult::failure('Non usato dal test.');
    }

    public function healthCheck(array $config): array
    {
        return [
            'ok' => ($config['api_key'] ?? '') === 'unsaved-key'
                && ($config['base_url'] ?? '') === 'https://form.invalid'
                && ($config['model'] ?? '') === 'form-model'
                && ($config['timeout_seconds'] ?? 0) === 12,
            'message' => 'Valori del form ricevuti.',
        ];
    }
}

test('onboarding Provider: Test usa i valori correnti del form senza salvarli', function () {
    $envPath = tempnam(sys_get_temp_dir(), 'aimanager-onboarding-');
    if ($envPath === false) {
        throw new RuntimeException('File temporaneo non disponibile.');
    }

    try {
        $store = new class implements ProviderConfigStoreInterface {
            public string $status = '';
            public function find(string $provider): ?array
            {
                return [
                    'provider' => $provider,
                    'base_url' => 'https://saved.invalid',
                    'model' => 'saved-model',
                    'timeout_seconds' => 30,
                    'enabled' => 1,
                ];
            }
            public function enabled(): array { return []; }
            public function updateHealth(string $provider, string $status, string $error = ''): void { $this->status = $status; }
            public function markRequest(string $provider, string $error = ''): void {}
        };

        $manager = new ProviderManager(
            new AIProviderRegistry([ProviderOnboardingTestProvider::class]),
            $store,
            new ConfigurationManager($envPath),
            new AllowAllRoutingPolicy()
        );
        $result = $manager->healthCheck('onboarding-test', [
            'api_key' => 'unsaved-key',
            'base_url' => 'https://form.invalid',
            'model' => 'form-model',
            'timeout_seconds' => 12,
            'enabled' => 0,
        ]);

        assertSame(true, $result['ok']);
        assertSame('online', $store->status);
    } finally {
        @unlink($envPath);
    }
});

test('onboarding essenziale resta visibile in Dashboard, Provider e Code', function () {
    $root = dirname(__DIR__);
    $dashboard = (string) file_get_contents($root . '/app/Views/dashboard/index.php');
    $provider = (string) file_get_contents($root . '/app/Views/providers/show.php');
    $code = (string) file_get_contents($root . '/app/Views/code/index.php');

    assertSame(true, str_contains($dashboard, 'Collega una IA e prova la prima chat'));
    assertSame(true, str_contains($provider, 'Dopo un test riuscito premi Salva'));
    assertSame(true, str_contains($code, 'Code non è una sandbox del sistema operativo'));
});
