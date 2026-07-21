<?php

declare(strict_types=1);

namespace App\Core\ContextEngine\Providers;

use App\Core\ContextEngine\ContextProviderInterface;

abstract class EmptyContextProvider implements ContextProviderInterface
{
    public function provide(array $project, string $userRequest, ?array $session = null, ?\App\Core\Execution\ExecutionState $executionState = null): array
    {
        return [];
    }
}
