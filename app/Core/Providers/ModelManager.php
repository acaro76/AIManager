<?php

declare(strict_types=1);

namespace App\Core\Providers;

final class ModelManager
{
    public function __construct(private readonly ProviderManager $providers)
    {
    }

    public static function default(): self
    {
        return new self(ProviderManager::default());
    }

    public function forProvider(string $provider): array
    {
        return $this->providers->models($provider);
    }
}
