<?php

declare(strict_types=1);

namespace App\Providers;

final class OpenAIProvider extends OpenAICompatibleProvider
{
    public function key(): string
    {
        return 'openai';
    }

    public function label(): string
    {
        return 'OpenAI';
    }

    protected function baseUrl(): string
    {
        return 'https://api.openai.com/v1';
    }

    protected function defaultModel(): string
    {
        return 'gpt-4.1-mini';
    }
}
