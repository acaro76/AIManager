<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 3 / F3.1. Limiti DETERMINISTICI del ciclo agente read-only.
 *
 * Sono i tetti del CICLO, distinti da quelli del recupero (RetrievalLimits, che restano i tetti
 * di scansione/ricerca/lettura e continuano a valere dentro ogni strumento). Qui si limita
 * quanto il ciclo può girare, non quanto può leggere:
 *
 *   - iterazioni    : quante decisioni del modello sono ammesse;
 *   - tempo totale  : tetto in secondi sull'intero ciclo (decisioni + strumenti);
 *   - budget        : caratteri cumulativi dei risultati degli strumenti che entrano nel dialogo;
 *   - output invalidi: quante risposte non parsabili di fila si tollerano prima di arrendersi.
 *
 * Value object immutabile e PURO (nessun IO, nessun DB). I valori non validi sono un errore di
 * programmazione: \InvalidArgumentException, non CodeWorkspaceException.
 */
final class CodeAgentLimits
{
    public function __construct(
        /** Decisioni del modello ammesse: oltre questo tetto si risponde con quel che si ha. */
        public readonly int $maxIterations,
        /** Tetto in secondi sull'INTERO ciclo (decisioni del provider incluse). */
        public readonly float $maxSeconds,
        /** Caratteri cumulativi dei risultati degli strumenti ammessi nel dialogo. */
        public readonly int $maxToolChars,
        /** Caratteri di UN singolo risultato di strumento (tagliato, mai rifiutato). */
        public readonly int $maxToolResultChars,
        /** Output non parsabili CONSECUTIVI tollerati prima di abbandonare il ciclo. */
        public readonly int $maxInvalidOutputs,
        /** Tetto della query che il modello può chiedere (difesa da query abnormi). */
        public readonly int $maxQueryChars,
    ) {
        $positiveInts = [
            'maxIterations' => $maxIterations,
            'maxToolChars' => $maxToolChars,
            'maxToolResultChars' => $maxToolResultChars,
            'maxInvalidOutputs' => $maxInvalidOutputs,
            'maxQueryChars' => $maxQueryChars,
        ];
        foreach ($positiveInts as $name => $value) {
            if ($value <= 0) {
                throw new \InvalidArgumentException("CodeAgentLimits: {$name} deve essere > 0 (dato: {$value}).");
            }
        }
        if ($maxSeconds <= 0.0) {
            throw new \InvalidArgumentException("CodeAgentLimits: maxSeconds deve essere > 0 (dato: {$maxSeconds}).");
        }
        // Un budget totale inferiore al singolo risultato renderebbe inutilizzabile il primo
        // strumento: incoerenza di configurazione, non una condizione di runtime.
        if ($maxToolChars < $maxToolResultChars) {
            throw new \InvalidArgumentException(
                "CodeAgentLimits: maxToolChars ({$maxToolChars}) < maxToolResultChars ({$maxToolResultChars})."
            );
        }
    }

    /**
     * Default prudenti: poche iterazioni (il modello locale è un reasoning model, ogni decisione
     * costa), tempo totale compatibile con lo Stop dell'utente e budget ben sotto al contesto
     * finale (RetrievalLimits::contextMaxChars), che resta il tetto vero del pacchetto.
     */
    public static function defaults(): self
    {
        return new self(
            maxIterations: 4,
            maxSeconds: 90.0,
            maxToolChars: 24000,
            maxToolResultChars: 6000,
            maxInvalidOutputs: 2,
            maxQueryChars: 120,
        );
    }
}
