<?php

declare(strict_types=1);

namespace App\Providers;

final class GroqProvider extends OpenAICompatibleProvider
{
    public function key(): string
    {
        return 'groq';
    }

    public function label(): string
    {
        return 'Groq';
    }

    protected function baseUrl(): string
    {
        return 'https://api.groq.com/openai/v1';
    }

    protected function defaultModel(): string
    {
        return 'llama-3.1-8b-instant';
    }
}
