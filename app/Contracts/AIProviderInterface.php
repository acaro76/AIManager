<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Core\ContextEngine\ContextInterface;
use App\Services\AIProviderResult;

interface AIProviderInterface
{
    public function key(): string;

    public function label(): string;

    public function defaults(): array;

    public function healthCheck(array $config): array;

    public function canAttempt(array $config): array;

    public function models(array $config): array;

    public function createRequest(string $prompt, ContextInterface $context): array;

    public function stream(string $prompt, ContextInterface $context, array $config, callable $onDelta): AIProviderResult;

    public function accountBalance(array $config): ?array;
}
