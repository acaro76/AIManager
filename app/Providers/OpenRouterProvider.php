<?php

declare(strict_types=1);

namespace App\Providers;

final class OpenRouterProvider extends OpenAICompatibleProvider
{
    public function key(): string
    {
        return 'openrouter';
    }

    public function label(): string
    {
        return 'OpenRouter';
    }

    protected function baseUrl(): string
    {
        return 'https://openrouter.ai/api/v1';
    }

    protected function defaultModel(): string
    {
        return 'openrouter/free';
    }

    protected function headers(array $config): array
    {
        return [
            'Authorization: Bearer ' . (string) ($config['api_key'] ?? ''),
            'HTTP-Referer: http://127.0.0.1:8081',
            'X-OpenRouter-Title: AIManager',
        ];
    }
}
