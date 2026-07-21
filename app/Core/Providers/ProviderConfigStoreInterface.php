<?php

declare(strict_types=1);

namespace App\Core\Providers;

interface ProviderConfigStoreInterface
{
    public function find(string $provider): ?array;

    public function enabled(): array;

    public function updateHealth(string $provider, string $status, string $error = ''): void;

    public function markRequest(string $provider, string $error = ''): void;
}
