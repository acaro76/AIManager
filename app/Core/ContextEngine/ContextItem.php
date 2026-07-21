<?php

declare(strict_types=1);

namespace App\Core\ContextEngine;

final class ContextItem
{
    public function __construct(
        public readonly string $source,
        public readonly string $type,
        public readonly string $title,
        public readonly string $content,
        public readonly int $priority,
        public readonly array $metadata = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'type' => $this->type,
            'title' => $this->title,
            'content' => $this->content,
            'priority' => $this->priority,
            'metadata' => $this->metadata,
        ];
    }
}
