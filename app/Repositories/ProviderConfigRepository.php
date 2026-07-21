<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Providers\ProviderConfigStoreInterface;
use App\Models\ProviderConfig;

final class ProviderConfigRepository implements ProviderConfigStoreInterface
{
    public function __construct(private readonly ProviderConfig $model = new ProviderConfig())
    {
    }

    public function find(string $provider): ?array
    {
        return $this->model->find($provider);
    }

    public function enabled(): array
    {
        return $this->model->enabled();
    }

    public function updateHealth(string $provider, string $status, string $error = ''): void
    {
        $this->model->updateHealth($provider, $status, $error);
    }

    public function markRequest(string $provider, string $error = ''): void
    {
        $this->model->markRequest($provider, $error);
    }
}
