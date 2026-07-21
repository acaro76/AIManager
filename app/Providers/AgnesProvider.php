<?php

declare(strict_types=1);

namespace App\Providers;

/**
 * Agnes AI (https://agnes-ai.com), gateway OpenAI-compatibile su https://apihub.agnes-ai.com/v1.
 *
 * Verificato sull'API reale (2026-07-17): `GET /v1/models` elenca due modelli di testo
 * (`agnes-2.0-flash`, `agnes-1.5-flash`) più immagini e video, che qui NON si usano — questo
 * provider è solo testo. `/chat/completions` risponde nella forma canonica OpenAI, con `usage`, e
 * lo streaming SSE funziona (`delta.content`, chiusura `data: [DONE]`, `usage` nell'ultimo chunk):
 * quindi basta la base OpenAI-compatibile, senza adattatori.
 *
 * `baseUrl` include `/v1` perché l'endpoint viene composto come base + `/chat/completions`.
 *
 * LENTO: ~7s fissi per risposta, misurati, indipendenti dalla lunghezza (anche 1 token) e uguali sui
 * due modelli — è overhead del gateway, non generazione. Per questo il profilo di scoring gli dà la
 * latenza più bassa: non deve mai vincere un fast-path.
 */
final class AgnesProvider extends OpenAICompatibleProvider
{
    public function key(): string
    {
        return 'agnes';
    }

    public function label(): string
    {
        return 'Agnes AI';
    }

    protected function baseUrl(): string
    {
        return 'https://apihub.agnes-ai.com/v1';
    }

    protected function defaultModel(): string
    {
        return 'agnes-2.0-flash';
    }
}
