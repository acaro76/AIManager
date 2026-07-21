<?php

declare(strict_types=1);

namespace App\Core\ContextEngine\Providers;

use App\Domain\Memory\MemoryType;

final class NoteContextProvider extends AbstractMemoryContextProvider
{
    public function key(): string
    {
        return 'notes';
    }

    public function label(): string
    {
        return 'Notes';
    }

    public function priority(): int
    {
        return 50;
    }

    protected function memoryType(): string
    {
        return MemoryType::NOTE;
    }
}
