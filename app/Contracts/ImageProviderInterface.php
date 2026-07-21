<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Core\Cancellation\CancellationToken;

/**
 * Provider di generazione immagini (text-to-image). Diverso da AIProviderInterface:
 * l'output non e' testo in streaming ma un'immagine (base64). Usato dalla pipeline
 * di generazione immagini (handoff sez. 46), separata dal flusso chat testuale.
 */
interface ImageProviderInterface
{
    public function key(): string;

    public function label(): string;

    /**
     * True se la config ha le credenziali necessarie per tentare (chiave/token).
     */
    public function canAttempt(array $config): bool;

    /**
     * Genera un'immagine dal prompt.
     *
     * @return array{ok: bool, image_base64: string, mime: string, model: string, error: string}
     */
    public function generate(string $prompt, array $config, ?CancellationToken $cancellation = null): array;
}
