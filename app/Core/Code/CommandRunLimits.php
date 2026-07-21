<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 6 / F6.1. Tetti DETERMINISTICI di UNA esecuzione di comando locale.
 *
 * Distinti da VerificationLimits (Fase 5, INTOCCATA): un comando F6 è un effetto collaterale reale
 * (un processo figlio isolato in un process group), quindi ha i suoi tetti — tempo, output — e la
 * conferma è SEMPRE richiesta (nessun livello «read automatico» in questa fase).
 *
 *   - maxSeconds     : tetto sul singolo processo; oltre, viene TERMINATO (SIGTERM→SIGKILL);
 *   - maxOutputBytes : output complessivo (stdout+stderr) catturato; oltre, si tronca e si chiude.
 *
 * Value object immutabile e PURO. Valori non validi = errore di programmazione.
 */
final class CommandRunLimits
{
    public function __construct(
        public readonly float $maxSeconds,
        public readonly int $maxOutputBytes,
    ) {
        if ($maxSeconds <= 0.0) {
            throw new \InvalidArgumentException("CommandRunLimits: maxSeconds deve essere > 0 (dato: {$maxSeconds}).");
        }
        if ($maxOutputBytes <= 0) {
            throw new \InvalidArgumentException("CommandRunLimits: maxOutputBytes deve essere > 0 (dato: {$maxOutputBytes}).");
        }
    }

    /** Default prudenti: un'utility di lettura è breve; l'output basta per il contenuto richiesto. */
    public static function defaults(): self
    {
        return new self(
            maxSeconds: 60.0,
            maxOutputBytes: 262144, // 256 KiB
        );
    }
}
