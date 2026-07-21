<?php

declare(strict_types=1);

namespace App\Core\Providers;

/**
 * Scelta pura del modello locale LM Studio in base al ruolo richiesto dall'intento.
 * Estratta da LmStudioProvider::resolveModel per poterla testare in isolamento.
 * JIT auto-evict in LM Studio garantisce che resti caricato un solo modello.
 *
 *  - vision  -> modello vlm (o il ragionante se il compito e' pesante): il fast/code
 *               possono essere solo-testo, quindi non devono mai ricevere immagini.
 *  - codice  -> modello coder specializzato (veloce, non ragionante).
 *  - pesante -> modello ragionante (qwen) per lavori lunghi/complessi non-codice.
 *  - breve   -> modello veloce non-ragionante.
 */
final class LmStudioModelChoice
{
    public static function resolve(
        string $reasoning,
        string $fast,
        string $code,
        string $vision,
        ?ProviderIntent $intent
    ): string {
        // Il modello codice ripiega sul veloce quando non configurato.
        $code = $code !== '' ? $code : $fast;

        if (!$intent instanceof ProviderIntent) {
            return $fast !== '' ? $fast : $reasoning;
        }

        if ($intent->requiresVision) {
            if ($intent->isHeavy() && $reasoning !== '') {
                return $reasoning;
            }
            return $vision !== '' ? $vision : ($reasoning !== '' ? $reasoning : $fast);
        }

        if ($intent->taskType === 'code' && $code !== '') {
            return $code;
        }

        if ($intent->isHeavy()) {
            return $reasoning !== '' ? $reasoning : $fast;
        }

        return $fast !== '' ? $fast : $reasoning;
    }
}
