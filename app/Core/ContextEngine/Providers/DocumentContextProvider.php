<?php

declare(strict_types=1);

namespace App\Core\ContextEngine\Providers;

use App\Domain\Memory\MemoryType;

final class DocumentContextProvider extends AbstractMemoryContextProvider
{
    public function key(): string
    {
        return 'documents';
    }

    public function label(): string
    {
        return 'Documents';
    }

    public function priority(): int
    {
        return 60;
    }

    protected function memoryType(): string
    {
        return MemoryType::DOCUMENT;
    }
}
