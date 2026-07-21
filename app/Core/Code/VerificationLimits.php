<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 5 / F5.1. Tetti DETERMINISTICI della verifica del codice.
 *
 * Sono i limiti di UN'esecuzione di verifica (lint/test/sintassi) e del loro numero per turno,
 * distinti dai tetti del ciclo agente (CodeAgentLimits) e del recupero (RetrievalLimits). Un
 * processo di verifica è un effetto collaterale reale: senza questi tetti un comando potrebbe
 * girare all'infinito o riversare output illimitato nel dialogo.
 *
 *   - maxSeconds       : tetto sul singolo processo; oltre, viene TERMINATO (SIGTERM→SIGKILL);
 *   - maxOutputBytes   : output complessivo (stdout+stderr) catturato; oltre, si tronca e si chiude;
 *   - maxRunsPerTurn   : quante verifiche il modello può avviare in un singolo turno.
 *
 * Value object immutabile e PURO (nessun IO). Valori non validi = errore di programmazione
 * (\InvalidArgumentException), mai una condizione di runtime.
 */
final class VerificationLimits
{
    public function __construct(
        /** Tetto in secondi sul singolo processo di verifica. */
        public readonly float $maxSeconds,
        /** Byte di output (stdout+stderr) catturati al massimo: oltre, troncato e chiuso. */
        public readonly int $maxOutputBytes,
        /** Verifiche avviabili in UN turno: oltre, il modello riceve un dato di limite. */
        public readonly int $maxRunsPerTurn,
    ) {
        if ($maxSeconds <= 0.0) {
            throw new \InvalidArgumentException("VerificationLimits: maxSeconds deve essere > 0 (dato: {$maxSeconds}).");
        }
        $positiveInts = ['maxOutputBytes' => $maxOutputBytes, 'maxRunsPerTurn' => $maxRunsPerTurn];
        foreach ($positiveInts as $name => $value) {
            if ($value <= 0) {
                throw new \InvalidArgumentException("VerificationLimits: {$name} deve essere > 0 (dato: {$value}).");
            }
        }
    }

    /**
     * Default prudenti: una verifica breve (lint/sintassi/test contenuti), output limitato a pochi
     * KiB (basta per gli errori, non per un dump enorme), poche verifiche per turno.
     */
    public static function defaults(): self
    {
        return new self(
            maxSeconds: 20.0,
            maxOutputBytes: 32768,
            maxRunsPerTurn: 3,
        );
    }
}
