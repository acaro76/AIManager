<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Configuration\ConfigurationManager;
use App\Core\Providers\ModelManager;
use App\Models\ProviderConfig;
use App\Models\AIRequestLog;
use App\Core\Providers\ProviderManager;
use App\Core\Providers\ImageProviderManager;
use App\Services\AIProviderRegistry;
use App\Services\WebSearchService;

final class ProviderController extends BaseController
{
    public function index(Request $request): void
    {
        $configuration = ConfigurationManager::fromRoot($this->app->root);
        $catalog = AIProviderRegistry::fromConfig()->catalog();
        $this->view('providers/index', [
            'title' => 'Provider',
            'catalog' => $catalog,
            'configs' => (new ProviderConfig())->all(),
            'stats' => (new AIRequestLog())->statsByProvider(),
            'secrets' => $this->secretView($catalog, $configuration),
            'webProviders' => $this->webProviders($configuration),
            'imageProviders' => $this->imageProviders($configuration),
        ]);
    }

    public function show(Request $request): void
    {
        $configuration = ConfigurationManager::fromRoot($this->app->root);
        $catalog = AIProviderRegistry::fromConfig()->catalog();
        $provider = strtolower((string) $request->input('provider', ''));
        if (!isset($catalog[$provider])) {
            Response::redirect('/providers');
        }

        $configs = (new ProviderConfig())->all();

        // Saldo reale dell'account, solo per i provider che espongono un'API di saldo (es.
        // DeepSeek /user/balance). Per gli altri resta null e la scheda non mostra nulla.
        $balance = null;
        $providerInstance = AIProviderRegistry::fromConfig()->get($provider);
        if ($providerInstance) {
            $balance = $providerInstance->accountBalance($configuration->runtimeConfig($provider, $configs[$provider] ?? $catalog[$provider]));
        }

        $this->view('providers/show', [
            'title' => $catalog[$provider]['label'] ?? 'Provider',
            'providerKey' => $provider,
            'defaults' => $catalog[$provider],
            'config' => $configs[$provider] ?? $catalog[$provider],
            'stat' => (new AIRequestLog())->statsByProvider()[$provider] ?? [],
            'secret' => $this->secretView($catalog, $configuration)[$provider] ?? ['masked' => '', 'has_key' => false, 'env_key' => ''],
            'balance' => $balance,
        ]);
    }

    public function save(Request $request): never
    {
        $this->guard($request);
        $provider = (string) $request->input('provider');
        // Il provider deve esistere nel catalogo: i provider si aggiungono da codice,
        // non da form. Senza questo check un POST anomalo creerebbe una riga fantasma in
        // provider_configs che poi il router salta sempre (audit).
        if (!isset(AIProviderRegistry::fromConfig()->catalog()[$provider])) {
            $this->flash('Provider non riconosciuto.', '/providers');
        }
        (new ProviderConfig())->save($provider, [
            'label' => (string) $request->input('label'),
            'base_url' => trim((string) $request->input('base_url')),
            'model' => trim((string) $request->input('model')),
            'enabled' => $request->input('enabled') ? 1 : 0,
            'timeout_seconds' => (int) $request->input('timeout_seconds', 30),
            'temperature' => (float) $request->input('temperature', 0.7),
            'max_tokens' => (int) $request->input('max_tokens', 2048),
            'top_p' => (float) $request->input('top_p', 1.0),
            'priority' => (int) $request->input('priority', 50),
            'mode' => (string) $request->input('mode', 'auto'),
        ]);

        $configuration = ConfigurationManager::fromRoot($this->app->root);
        $updates = [];
        $envKey = $configuration->envKeyForProvider($provider);
        $apiKey = trim((string) $request->input('api_key'));
        if ($envKey !== '' && $apiKey !== '') {
            $updates[$envKey] = $apiKey;
        }
        if ($provider === 'lmstudio') {
            $updates['LMSTUDIO_ENDPOINT'] = trim((string) $request->input('base_url'));
            $updates['LMSTUDIO_DEFAULT_MODEL'] = trim((string) $request->input('model'));
        }
        if ($updates) {
            $configuration->setMany($updates);
        }

        $returnTo = (string) $request->input('return_to', '/providers');
        $this->flash('Provider salvato.', str_starts_with($returnTo, '/providers') ? $returnTo : '/providers');
    }

    public function showWeb(Request $request): void
    {
        $provider = strtolower((string) $request->input('provider', 'tavily'));
        $configuration = ConfigurationManager::fromRoot($this->app->root);
        $webProviders = $this->webProviders($configuration);
        if (!isset($webProviders[$provider])) {
            Response::redirect('/providers');
        }

        $this->view('providers/web_show', [
            'title' => $webProviders[$provider]['label'],
            'providerKey' => $provider,
            'provider' => $webProviders[$provider],
            'config' => $configuration->all(),
            'secret' => [
                'env_key' => 'TAVILY_API_KEY',
                'masked' => $configuration->masked('TAVILY_API_KEY'),
                'has_key' => $configuration->get('TAVILY_API_KEY') !== '',
            ],
        ]);
    }

    public function saveWeb(Request $request): never
    {
        $this->guard($request);
        $provider = strtolower((string) $request->input('provider', 'tavily'));
        if ($provider !== 'tavily') {
            $this->flash('Provider web non riconosciuto.', '/providers');
        }

        ConfigurationManager::fromRoot($this->app->root)->setMany($this->webSearchUpdates($request));
        $this->flash('Provider web salvato.', '/providers/web/show?provider=tavily');
    }

    public function toggleWeb(Request $request): never
    {
        $this->guard($request);
        $provider = strtolower((string) $request->input('provider', ''));
        if ($provider === 'tavily') {
            ConfigurationManager::fromRoot($this->app->root)->set('WEB_SEARCH_ENABLED', $request->input('enabled') ? '1' : '0');
        }

        Response::redirect('/providers');
    }

    public function toggle(Request $request): never
    {
        $this->guard($request);
        $provider = strtolower((string) $request->input('provider'));
        if ($provider !== '') {
            (new ProviderConfig())->setEnabled($provider, (bool) $request->input('enabled'));
        }

        Response::redirect('/providers');
    }

    public function showImage(Request $request): void
    {
        $provider = strtolower((string) $request->input('provider', 'cloudflare'));
        $configuration = ConfigurationManager::fromRoot($this->app->root);
        $providers = $this->imageProviders($configuration);
        if (!isset($providers[$provider])) {
            Response::redirect('/providers');
        }

        $this->view('providers/image_show', [
            'title' => $providers[$provider]['label'],
            'providerKey' => $provider,
            'provider' => $providers[$provider],
            'masterEnabled' => $configuration->get('IMAGE_GEN_ENABLED', '1') !== '0',
            'accountId' => $configuration->get('CLOUDFLARE_ACCOUNT_ID'),
            'cloudflareToken' => [
                'masked' => $configuration->masked('CLOUDFLARE_API_TOKEN'),
                'has_key' => $configuration->get('CLOUDFLARE_API_TOKEN') !== '',
            ],
            'geminiKey' => [
                'masked' => $configuration->masked('GOOGLE_API_KEY'),
                'has_key' => $configuration->get('GOOGLE_API_KEY') !== '',
            ],
        ]);
    }

    public function saveImage(Request $request): never
    {
        $this->guard($request);
        $provider = strtolower((string) $request->input('provider', 'cloudflare'));
        if (!in_array($provider, ['cloudflare', 'gemini'], true)) {
            $this->flash('Provider immagine non riconosciuto.', '/providers');
        }

        $updates = ['IMAGE_GEN_ENABLED' => $request->input('enabled') ? '1' : '0'];
        if ($provider === 'cloudflare') {
            $accountId = trim((string) $request->input('account_id'));
            if ($accountId !== '') {
                $updates['CLOUDFLARE_ACCOUNT_ID'] = $accountId;
            }
            $token = trim((string) $request->input('api_token'));
            if ($token !== '') {
                $updates['CLOUDFLARE_API_TOKEN'] = $token;
            }
            $model = trim((string) $request->input('model'));
            if ($model !== '') {
                $updates['CLOUDFLARE_IMAGE_MODEL'] = $model;
            }
        } else {
            $model = trim((string) $request->input('model'));
            if ($model !== '') {
                $updates['GEMINI_IMAGE_MODEL'] = $model;
            }
            $key = trim((string) $request->input('api_key'));
            if ($key !== '') {
                $updates['GOOGLE_API_KEY'] = $key;
            }
        }

        ConfigurationManager::fromRoot($this->app->root)->setMany($updates);
        $this->flash('Provider immagine salvato.', '/providers/image/show?provider=' . rawurlencode($provider));
    }

    public function toggleImage(Request $request): never
    {
        $this->guard($request);
        $provider = strtolower((string) $request->input('provider', ''));
        if (!in_array($provider, ['cloudflare', 'gemini'], true)) {
            Response::redirect('/providers');
        }

        $configuration = ConfigurationManager::fromRoot($this->app->root);
        $chain = array_values(array_filter(array_map(
            'trim',
            explode(',', strtolower($configuration->get('IMAGE_GEN_PROVIDERS', 'cloudflare,gemini')))
        )));
        $chain = array_values(array_filter($chain, static fn (string $p): bool => $p !== $provider));
        if ($request->input('enabled')) {
            $chain[] = $provider;
        }

        $configuration->set('IMAGE_GEN_PROVIDERS', implode(',', $chain));
        Response::redirect('/providers');
    }

    public function testImage(Request $request): never
    {
        $this->guard($request);
        $provider = strtolower((string) $request->input('provider', 'cloudflare'));
        // "Test" prova i valori del form senza salvarli (override temporanei in memoria).
        $configuration = ConfigurationManager::fromRoot($this->app->root)
            ->withOverrides($this->imageOverrides($request, $provider));

        $result = (new ImageProviderManager($configuration))->test($provider);
        Response::json([
            'ok' => !empty($result['ok']),
            'message' => !empty($result['ok'])
                ? 'Immagine generata con ' . strtoupper($provider) . ' (' . (string) ($result['model'] ?? '') . ').'
                : ('Generazione non riuscita. ' . (string) ($result['error'] ?? '')),
        ]);
    }

    public function test(Request $request): never
    {
        $this->guard($request);
        $provider = strtolower((string) $request->input('provider'));
        $overrides = [
            'base_url' => trim((string) $request->input('base_url')),
            'model' => trim((string) $request->input('model')),
            'timeout_seconds' => max(1, min(180, (int) $request->input('timeout_seconds', 30))),
        ];
        $apiKey = trim((string) $request->input('api_key'));
        if ($apiKey !== '') {
            $overrides['api_key'] = $apiKey;
        }

        $result = ProviderManager::default()->healthCheck($provider, $overrides);
        if (!empty($result['ok'])) {
            $result['message'] = trim((string) ($result['message'] ?? 'Test riuscito.'))
                . ' Salva per usare questa configurazione.';
        } elseif (trim((string) ($result['message'] ?? '')) === '') {
            $result['message'] = 'Test non riuscito. Controlla endpoint, modello e credenziale.';
        }
        Response::json($result);
    }

    public function testWeb(Request $request): never
    {
        $this->guard($request);
        $configuration = ConfigurationManager::fromRoot($this->app->root)
            ->withOverrides($this->webSearchUpdates($request));

        $query = trim((string) $request->input('query', 'OpenAI'));
        $query = $query !== '' ? $query : 'OpenAI';
        $result = (new WebSearchService($configuration))->search($query);
        $count = count($result['results'] ?? []);

        Response::json([
            'ok' => !empty($result['ok']),
            'message' => !empty($result['ok'])
                ? 'Ricerca riuscita: ' . $count . ' risultati da ' . strtoupper((string) ($result['provider'] ?? 'web')) . '.'
                : ((string) ($result['error'] ?? 'Ricerca non riuscita.')),
            'provider' => (string) ($result['provider'] ?? ''),
            'results' => $count,
        ]);
    }

    public function models(Request $request): never
    {
        $provider = (string) $request->input('provider');
        Response::json(['ok' => true, 'models' => ModelManager::default()->forProvider($provider)]);
    }

    private function secretView(array $catalog, ConfigurationManager $configuration): array
    {
        $secrets = [];
        foreach (array_keys($catalog) as $provider) {
            $envKey = $configuration->envKeyForProvider($provider);
            $secrets[$provider] = [
                'env_key' => $envKey,
                'masked' => $envKey === '' ? '' : $configuration->masked($envKey),
                'has_key' => $envKey !== '' && $configuration->get($envKey) !== '',
            ];
        }

        return $secrets;
    }

    private function webProviders(ConfigurationManager $configuration): array
    {
        $enabled = $configuration->get('WEB_SEARCH_ENABLED', '1') !== '0';
        $selected = strtolower($configuration->get('WEB_SEARCH_PROVIDER', 'tavily'));
        $hasTavilyKey = $configuration->get('TAVILY_API_KEY') !== '';
        $active = $selected === 'tavily' && $hasTavilyKey;

        return [
            'tavily' => [
                'label' => 'Tavily',
                'source' => 'Web search',
                'status' => $enabled && $hasTavilyKey ? 'online' : ($enabled ? 'missing-key' : 'offline'),
                'enabled' => $enabled,
                'selected' => $selected,
                'active' => $active,
                'has_key' => $hasTavilyKey,
                'max_results' => (int) $configuration->get('WEB_SEARCH_MAX_RESULTS', '5'),
            ],
        ];
    }

    private function imageProviders(ConfigurationManager $configuration): array
    {
        $enabled = $configuration->get('IMAGE_GEN_ENABLED', '1') !== '0';
        $chain = array_filter(array_map(
            'trim',
            explode(',', strtolower($configuration->get('IMAGE_GEN_PROVIDERS', 'cloudflare,gemini')))
        ));
        $hasCloudflare = $configuration->get('CLOUDFLARE_ACCOUNT_ID') !== '' && $configuration->get('CLOUDFLARE_API_TOKEN') !== '';
        $hasGemini = $configuration->get('GOOGLE_API_KEY') !== '';

        $status = static function (bool $inChain, bool $hasKey) use ($enabled): string {
            if (!$enabled || !$inChain) {
                return 'offline';
            }

            return $hasKey ? 'online' : 'missing-key';
        };

        return [
            'cloudflare' => [
                'label' => 'Cloudflare (FLUX)',
                'source' => 'Workers AI',
                'model' => $configuration->get('CLOUDFLARE_IMAGE_MODEL', '@cf/black-forest-labs/flux-1-schnell'),
                'status' => $status(in_array('cloudflare', $chain, true), $hasCloudflare),
                'has_key' => $hasCloudflare,
                'in_chain' => in_array('cloudflare', $chain, true),
                'note' => 'Verifica piano e quote',
            ],
            'gemini' => [
                'label' => 'Gemini image',
                'source' => 'Google',
                'model' => $configuration->get('GEMINI_IMAGE_MODEL', 'gemini-2.5-flash-image'),
                'status' => $status(in_array('gemini', $chain, true), $hasGemini),
                'has_key' => $hasGemini,
                'in_chain' => in_array('gemini', $chain, true),
                'note' => 'Verifica piano e quote',
            ],
        ];
    }

    private function imageOverrides(Request $request, string $provider): array
    {
        $overrides = ['IMAGE_GEN_ENABLED' => '1'];
        if ($provider === 'cloudflare') {
            $accountId = trim((string) $request->input('account_id'));
            if ($accountId !== '') {
                $overrides['CLOUDFLARE_ACCOUNT_ID'] = $accountId;
            }
            $token = trim((string) $request->input('api_token'));
            if ($token !== '') {
                $overrides['CLOUDFLARE_API_TOKEN'] = $token;
            }
            $model = trim((string) $request->input('model'));
            if ($model !== '') {
                $overrides['CLOUDFLARE_IMAGE_MODEL'] = $model;
            }
        } else {
            $key = trim((string) $request->input('api_key'));
            if ($key !== '') {
                $overrides['GOOGLE_API_KEY'] = $key;
            }
            $model = trim((string) $request->input('model'));
            if ($model !== '') {
                $overrides['GEMINI_IMAGE_MODEL'] = $model;
            }
        }

        return $overrides;
    }

    private function webSearchUpdates(Request $request): array
    {
        $updates = [
            'WEB_SEARCH_ENABLED' => $request->input('enabled') ? '1' : '0',
            'WEB_SEARCH_PROVIDER' => 'tavily',
            'WEB_SEARCH_MAX_RESULTS' => (string) max(1, min(10, (int) $request->input('max_results', 5))),
            'WEB_SEARCH_TIMEOUT' => (string) max(3, min(20, (int) $request->input('timeout', 8))),
        ];

        $apiKey = trim((string) $request->input('api_key'));
        if ($apiKey !== '') {
            $updates['TAVILY_API_KEY'] = $apiKey;
        }

        return $updates;
    }
}
