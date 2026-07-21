<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AIProviderInterface;

final class AIProviderRegistry
{
    /** @var array<string, AIProviderInterface> */
    private array $providers = [];

    /**
     * @param class-string<AIProviderInterface>[] $providerClasses
     */
    public function __construct(array $providerClasses)
    {
        foreach ($providerClasses as $providerClass) {
            $provider = new $providerClass();
            $this->providers[$provider->key()] = $provider;
        }
    }

    public static function fromConfig(): self
    {
        return new self(require dirname(__DIR__, 2) . '/config/providers.php');
    }

    public function get(string $key): ?AIProviderInterface
    {
        return $this->providers[$key] ?? null;
    }

    public function catalog(): array
    {
        $catalog = [];
        foreach ($this->providers as $key => $provider) {
            $catalog[$key] = $provider->defaults();
        }

        return $catalog;
    }
}
