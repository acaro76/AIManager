<?php

declare(strict_types=1);

namespace App\Services;

final class AIProviderResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $content = '',
        public readonly int $tokensInput = 0,
        public readonly int $tokensOutput = 0,
        public readonly string $error = '',
        public readonly array $raw = [],
        public readonly string $provider = '',
        public readonly string $model = '',
        public readonly string $endpoint = '',
        public readonly int $responseTimeMs = 0,
        public readonly float $estimatedCost = 0.0,
        public readonly string $choiceReason = '',
        public readonly bool $fallbackUsed = false,
    ) {
    }

    public static function success(string $content, int $tokensInput = 0, int $tokensOutput = 0, array $raw = [], array $meta = []): self
    {
        return new self(true, $content, $tokensInput, $tokensOutput, '', $raw, ...self::meta($meta));
    }

    public static function failure(string $error, array $raw = [], array $meta = []): self
    {
        return new self(false, '', 0, 0, $error, $raw, ...self::meta($meta));
    }

    private static function meta(array $meta): array
    {
        return [
            (string) ($meta['provider'] ?? ''),
            (string) ($meta['model'] ?? ''),
            (string) ($meta['endpoint'] ?? ''),
            (int) ($meta['response_time_ms'] ?? 0),
            (float) ($meta['estimated_cost'] ?? 0),
            (string) ($meta['choice_reason'] ?? ''),
            (bool) ($meta['fallback_used'] ?? false),
        ];
    }
}
