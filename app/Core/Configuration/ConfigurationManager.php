<?php

declare(strict_types=1);

namespace App\Core\Configuration;

use App\Core\Security\LocalPermissions;

final class ConfigurationManager
{
    private const DEFAULTS = [
        'OPENAI_API_KEY' => '',
        'ANTHROPIC_API_KEY' => '',
        'DEEPSEEK_API_KEY' => '',
        'GOOGLE_API_KEY' => '',
        'GROQ_API_KEY' => '',
        'OPENROUTER_API_KEY' => '',
        'CEREBRAS_API_KEY' => '',
        'AGNES_API_KEY' => '',
        'CEREBRAS_DEFAULT_MODEL' => 'gpt-oss-120b',
        'DEEPSEEK_DEFAULT_MODEL' => 'deepseek-v4-flash',
        'AGNES_DEFAULT_MODEL' => 'agnes-2.0-flash',
        'LMSTUDIO_ENDPOINT' => 'http://localhost:1234/v1',
        'LMSTUDIO_DEFAULT_MODEL' => '',
        'LMSTUDIO_FAST_MODEL' => '',
        'LMSTUDIO_CODE_MODEL' => '',
        'LMSTUDIO_VISION_MODEL' => '',
        'LMSTUDIO_EMBED_MODEL' => '',
        'WEB_SEARCH_ENABLED' => '1',
        'WEB_SEARCH_PROVIDER' => 'tavily',
        'WEB_SEARCH_MAX_RESULTS' => '5',
        'WEB_SEARCH_TIMEOUT' => '8',
        'TAVILY_API_KEY' => '',
        // Classificatore AI della ricerca web (Ipotesi B, sez. 44): decide se serve il web
        // in modo indipendente dalla lingua. WEB_INTENT_PROVIDERS = catena di fallback.
        'WEB_INTENT_ENABLED' => '1',
        'WEB_INTENT_PROVIDERS' => 'deepseek,cerebras',
        'WEB_INTENT_TIMEOUT' => '8',
        // Consolidatore AI del Project Brain (Fase 1, sez. 18.4): a fine sessione un modello
        // veloce estrae solo i fatti salienti/riusabili (niente rumore) e li confronta col
        // gia' noto (new/duplicate/conflict/refine). Gira una volta per archiviazione, non per
        // messaggio: costo trascurabile. Se i provider falliscono -> fallback parole-chiave.
        'BRAIN_CONSOLIDATION_ENABLED' => '1',
        'BRAIN_CONSOLIDATION_PROVIDERS' => 'deepseek,cerebras',
        'BRAIN_CONSOLIDATION_TIMEOUT' => '25',
        'BRAIN_CONSOLIDATION_MAX_MESSAGES' => '60',
        // Promozione a "residente": i fatti del brain sopra questa confidenza rientrano
        // SEMPRE nel contesto (non solo per keyword). MAX_ITEMS = tetto per non inondare.
        'BRAIN_RESIDENT_ENABLED' => '1',
        'BRAIN_RESIDENT_MIN_CONFIDENCE' => '0.75',
        'BRAIN_RESIDENT_MAX_ITEMS' => '12',
        // Generazione immagini (handoff sez. 46). Catena di fallback cloudflare->gemini.
        // Entrambi sono servizi esterni: prezzi, quote e requisiti di billing vanno verificati
        // presso i provider e non sono codificati come promessa del prodotto.
        'IMAGE_GEN_ENABLED' => '1',
        'IMAGE_GEN_PROVIDERS' => 'cloudflare,gemini',
        'IMAGE_GEN_TIMEOUT' => '60',
        'GEMINI_IMAGE_MODEL' => 'gemini-2.5-flash-image',
        'CLOUDFLARE_ACCOUNT_ID' => '',
        'CLOUDFLARE_API_TOKEN' => '',
        'CLOUDFLARE_IMAGE_MODEL' => '@cf/black-forest-labs/flux-1-schnell',
    ];

    private const PROVIDER_KEYS = [
        'openai' => 'OPENAI_API_KEY',
        'claude' => 'ANTHROPIC_API_KEY',
        'deepseek' => 'DEEPSEEK_API_KEY',
        'gemini' => 'GOOGLE_API_KEY',
        'groq' => 'GROQ_API_KEY',
        'openrouter' => 'OPENROUTER_API_KEY',
        'cerebras' => 'CEREBRAS_API_KEY',
        'agnes' => 'AGNES_API_KEY',
    ];

    public function __construct(
        private readonly string $path,
        private readonly array $overrides = [],
    )
    {
        $this->ensure();
    }

    public static function fromRoot(string $root): self
    {
        return new self(rtrim($root, '/') . '/.env');
    }

    public function all(): array
    {
        return array_replace($this->baseValues(), $this->overrides);
    }

    public function withOverrides(array $overrides): self
    {
        $values = [];
        foreach ($overrides as $key => $value) {
            $values[(string) $key] = (string) $value;
        }

        return new self($this->path, array_replace($this->overrides, $values));
    }

    private function baseValues(): array
    {
        $values = self::DEFAULTS;
        if (!is_file($this->path)) {
            return $values;
        }

        foreach (file($this->path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = $this->decode(trim($value));
        }

        return $values;
    }

    public function get(string $key, string $default = ''): string
    {
        return (string) ($this->all()[$key] ?? $default);
    }

    public function set(string $key, string $value): void
    {
        $values = $this->baseValues();
        $values[$key] = $value;
        $this->write($values);
    }

    public function setMany(array $updates): void
    {
        $values = $this->baseValues();
        foreach ($updates as $key => $value) {
            $values[(string) $key] = (string) $value;
        }
        $this->write($values);
    }

    public function apiKeyForProvider(string $provider): string
    {
        $envKey = self::PROVIDER_KEYS[$provider] ?? '';
        return $envKey === '' ? '' : $this->get($envKey);
    }

    public function envKeyForProvider(string $provider): string
    {
        return self::PROVIDER_KEYS[$provider] ?? '';
    }

    public function masked(string $key): string
    {
        $value = $this->get($key);
        if ($value === '') {
            return '';
        }

        return str_repeat('*', max(8, strlen($value) - 4)) . substr($value, -4);
    }

    public function runtimeConfig(string $provider, array $config): array
    {
        $config['api_key'] = $this->apiKeyForProvider($provider);
        if ($provider === 'cerebras') {
            $model = $this->get('CEREBRAS_DEFAULT_MODEL');
            if ($model !== '') {
                $config['model'] = $model;
            }
        }
        if ($provider === 'deepseek') {
            $model = $this->get('DEEPSEEK_DEFAULT_MODEL');
            if ($model !== '') {
                $config['model'] = $model;
            }
        }
        if ($provider === 'agnes') {
            $model = $this->get('AGNES_DEFAULT_MODEL');
            if ($model !== '') {
                $config['model'] = $model;
            }
        }
        if ($provider === 'lmstudio') {
            $endpoint = $this->get('LMSTUDIO_ENDPOINT');
            $model = $this->get('LMSTUDIO_DEFAULT_MODEL');
            $fastModel = $this->get('LMSTUDIO_FAST_MODEL');
            $codeModel = $this->get('LMSTUDIO_CODE_MODEL');
            $visionModel = $this->get('LMSTUDIO_VISION_MODEL');
            if ($endpoint !== '') {
                $config['base_url'] = $endpoint;
            }
            if ($model !== '') {
                $config['model'] = $model;
            }
            if ($fastModel !== '') {
                $config['fast_model'] = $fastModel;
            }
            if ($codeModel !== '') {
                $config['code_model'] = $codeModel;
            }
            if ($visionModel !== '') {
                $config['vision_model'] = $visionModel;
            }
        }

        return $config;
    }

    private function ensure(): void
    {
        if (is_file($this->path)) {
            $values = $this->baseValues();
            $missing = array_diff_key(self::DEFAULTS, $values);
            if ($missing) {
                $this->write($values + $missing);
            }
            return;
        }

        $this->write(self::DEFAULTS);
    }

    private function write(array $values): void
    {
        $ordered = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $ordered[$key] = (string) ($values[$key] ?? '');
        }
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $ordered)) {
                $ordered[$key] = (string) $value;
            }
        }

        $lines = [];
        foreach ($ordered as $key => $value) {
            $lines[] = $key . '=' . $this->encode($value);
        }

        if (file_put_contents($this->path, implode("\n", $lines) . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Impossibile scrivere la configurazione (.env).');
        }
        // Fase 10 / Step 2 — il .env contiene segreti: permessi privati subito dopo la scrittura.
        LocalPermissions::secureEnv($this->path);
    }

    private function encode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_:\\/\\.\\-]+$/', $value)) {
            return $value;
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private function decode(string $value): string
    {
        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return str_replace(['\\"', '\\\\'], ['"', '\\'], substr($value, 1, -1));
        }

        return $value;
    }
}
