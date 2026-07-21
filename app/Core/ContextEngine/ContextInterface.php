<?php

declare(strict_types=1);

namespace App\Core\ContextEngine;

use App\Core\Execution\ExecutionState;

interface ContextInterface
{
    public function project(): array;

    public function session(): ?array;

    public function executionState(): ?ExecutionState;

    public function userRequest(): string;

    /**
     * @return ContextItem[]
     */
    public function items(): array;

    /**
     * Storico conversazionale come messaggi role-tagged per il provider.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function history(): array;

    public function toArray(): array;
}
