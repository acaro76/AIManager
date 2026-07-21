<?php

declare(strict_types=1);

namespace App\Core\ContextEngine\Providers;

use App\Domain\Memory\MemoryType;

final class ArtifactContextProvider extends AbstractMemoryContextProvider
{
    public function key(): string
    {
        return 'artifacts';
    }

    public function label(): string
    {
        return 'Artifacts';
    }

    public function priority(): int
    {
        return 40;
    }

    protected function memoryType(): string
    {
        return MemoryType::ARTIFACT;
    }
}
