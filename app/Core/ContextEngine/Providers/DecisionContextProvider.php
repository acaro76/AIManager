<?php

declare(strict_types=1);

namespace App\Core\ContextEngine\Providers;

use App\Domain\Memory\MemoryType;

final class DecisionContextProvider extends AbstractMemoryContextProvider
{
    public function key(): string
    {
        return 'decisions';
    }

    public function label(): string
    {
        return 'Decisions';
    }

    public function priority(): int
    {
        return 90;
    }

    protected function memoryType(): string
    {
        return MemoryType::DECISION;
    }
}
