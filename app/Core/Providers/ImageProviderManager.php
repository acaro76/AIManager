<?php

declare(strict_types=1);

namespace App\Core\Providers;

use App\Contracts\ImageProviderInterface;
use App\Core\App;
use App\Core\Cancellation\CancellationToken;
use App\Core\Configuration\ConfigurationManager;
use App\Providers\Image\CloudflareImageProvider;
use App\Providers\Image\GeminiImageProvider;

/**
 * Orchestrazione della generazione immagini con catena di fallback (handoff sez. 46):
 * per default Cloudflare (FLUX schnell) -> Gemini; costi e quote restano esterni al routing.
 * Se un provider fallisce o non e' configurato, si passa al successivo. Analogo a
 * ProviderManager ma per l'output immagine.
 */
final class ImageProviderManager
{
    public function __construct(private readonly ConfigurationManager $config)
    {
    }

    public static function default(): self
    {
        return new self(ConfigurationManager::fromRoot(App::get()->root));
    }

    public function enabled(): bool
    {
        return $this->config->get('IMAGE_GEN_ENABLED', '1') !== '0' && $this->chain() !== [];
    }

    /**
     * @return string[]
     */
    public function chain(): array
    {
        $chain = [];
        foreach (explode(',', $this->config->get('IMAGE_GEN_PROVIDERS', 'cloudflare,gemini')) as $name) {
            $name = strtolower(trim($name));
            if ($name !== '' && !in_array($name, $chain, true)) {
                $chain[] = $name;
            }
        }

        return $chain;
    }

    /**
     * Genera l'immagine provando i provider in catena.
     *
     * @return array{ok: bool, image_base64: string, mime: string, model: string, provider: string, error: string}
     */
    public function generate(string $prompt, ?CancellationToken $cancellation = null): array
    {
        $prompt = trim($prompt);
        $base = ['ok' => false, 'image_base64' => '', 'mime' => '', 'model' => '', 'provider' => '', 'error' => ''];
        if ($prompt === '') {
            return ['error' => 'Prompt immagine vuoto.'] + $base;
        }
        if ($cancellation?->isCancelled()) {
            return ['error' => 'Richiesta interrotta.'] + $base;
        }
        if (!$this->enabled()) {
            return ['error' => 'Generazione immagini disabilitata.'] + $base;
        }

        $errors = [];
        foreach ($this->chain() as $name) {
            if ($cancellation?->isCancelled()) {
                return ['error' => 'Richiesta interrotta.'] + $base;
            }
            $provider = $this->provider($name);
            if ($provider === null) {
                continue;
            }
            $config = $this->configFor($name);
            if (!$provider->canAttempt($config)) {
                $errors[] = $name . ': non configurato';
                continue;
            }

            $outcome = $provider->generate($prompt, $config, $cancellation);
            if (!empty($outcome['ok'])) {
                return [
                    'ok' => true,
                    'image_base64' => (string) $outcome['image_base64'],
                    'mime' => (string) $outcome['mime'],
                    'model' => (string) $outcome['model'],
                    'provider' => $name,
                    'error' => '',
                ];
            }
            $errors[] = (string) ($outcome['error'] ?? ($name . ': errore'));
        }

        return ['error' => $errors !== [] ? implode(' | ', $errors) : 'Nessun provider immagine disponibile.'] + $base;
    }

    /**
     * Prova un singolo provider immagine (per il pulsante Test nella scheda). Bypassa il
     * master switch cosi' si puo' verificare la configurazione anche a feature disattivata.
     *
     * @return array{ok: bool, error: string, model: string, provider: string}
     */
    public function test(string $name): array
    {
        $name = strtolower(trim($name));
        $provider = $this->provider($name);
        if ($provider === null) {
            return ['ok' => false, 'error' => 'Provider immagine sconosciuto.', 'model' => '', 'provider' => $name];
        }

        $config = $this->configFor($name);
        if (!$provider->canAttempt($config)) {
            return ['ok' => false, 'error' => 'Non configurato: chiavi mancanti.', 'model' => '', 'provider' => $name];
        }

        // Il filtro di sicurezza di FLUX ha falsi positivi saltuari anche su prompt neutri:
        // per il Test si riprova una volta cosi' non fallisce per un flag casuale.
        $outcome = ['ok' => false, 'error' => '', 'model' => ''];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $outcome = $provider->generate('a peaceful landscape with green hills and a blue sky', $config);
            if (!empty($outcome['ok'])) {
                break;
            }
        }

        return [
            'ok' => !empty($outcome['ok']),
            'error' => (string) ($outcome['error'] ?? ''),
            'model' => (string) ($outcome['model'] ?? ''),
            'provider' => $name,
        ];
    }

    private function provider(string $name): ?ImageProviderInterface
    {
        return match ($name) {
            'gemini' => new GeminiImageProvider(),
            'cloudflare' => new CloudflareImageProvider(),
            default => null,
        };
    }

    private function configFor(string $name): array
    {
        $timeout = max(10, min(120, (int) $this->config->get('IMAGE_GEN_TIMEOUT', '60')));

        return match ($name) {
            'gemini' => [
                'api_key' => $this->config->apiKeyForProvider('gemini'),
                'model' => $this->config->get('GEMINI_IMAGE_MODEL', 'gemini-2.5-flash-image'),
                'timeout' => $timeout,
            ],
            'cloudflare' => [
                'account_id' => $this->config->get('CLOUDFLARE_ACCOUNT_ID'),
                'api_token' => $this->config->get('CLOUDFLARE_API_TOKEN'),
                'model' => $this->config->get('CLOUDFLARE_IMAGE_MODEL', '@cf/black-forest-labs/flux-1-schnell'),
                'timeout' => $timeout,
            ],
            default => [],
        };
    }
}
