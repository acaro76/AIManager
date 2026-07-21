<?php

declare(strict_types=1);

namespace App\Core\ContextEngine;

use App\Core\Execution\ExecutionState;

interface ContextProviderInterface
{
    public function key(): string;

    public function label(): string;

    public function priority(): int;

    /**
     * @return ContextItem[]
     */
    public function provide(array $project, string $userRequest, ?array $session = null, ?ExecutionState $executionState = null): array;
}
