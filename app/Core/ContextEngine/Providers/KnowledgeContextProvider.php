<?php

declare(strict_types=1);

namespace App\Core\ContextEngine\Providers;

use App\Domain\Memory\MemoryType;

final class KnowledgeContextProvider extends AbstractMemoryContextProvider
{
    public function key(): string
    {
        return 'knowledge';
    }

    public function label(): string
    {
        return 'Knowledge';
    }

    public function priority(): int
    {
        return 80;
    }

    protected function memoryType(): string
    {
        return MemoryType::KNOWLEDGE;
    }

    protected function alwaysResident(): bool
    {
        return true;
    }
}
