<?php

declare(strict_types=1);

namespace App\Providers;

/**
 * Cerebras Inference, free tier (1M token/giorno, nessuna carta). API OpenAI-compatibile.
 *
 * Punto forte: throughput altissimo (~2600 token/s) -> fast-path per risposte brevi,
 * piu' veloce di Groq. Limiti free: context cap ~8k token e modelli open (Llama/Qwen),
 * quindi non adatto al lavoro lungo/heavy e con la stessa tendenza ad allucinare di Groq
 * (mitigata da web grounding + prompt anti-allucinazione).
 */
final class CerebrasProvider extends OpenAICompatibleProvider
{
    public function key(): string
    {
        return 'cerebras';
    }

    public function label(): string
    {
        return 'Cerebras';
    }

    protected function baseUrl(): string
    {
        return 'https://api.cerebras.ai/v1';
    }

    protected function defaultModel(): string
    {
        return 'gpt-oss-120b';
    }
}
