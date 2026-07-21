<?php

declare(strict_types=1);

namespace App\Core\Cancellation;

final class CancellationToken
{
    public function __construct(
        private readonly CancellationStore $store,
        private readonly string $id,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function isCancelled(): bool
    {
        return connection_aborted() === 1 || ($this->id !== '' && $this->store->isCancelled($this->id));
    }
}
