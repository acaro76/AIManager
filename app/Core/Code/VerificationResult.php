<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 5 / F5.4. Esito PURO di UNA esecuzione di verifica.
 *
 * Distingue due destinatari, come CodeAgentStep:
 *   - `output`  : il testo che torna AL MODELLO (stdout+stderr, già troncato). È un DATO.
 *   - metadati  : `outcome` (vocabolario chiuso), `exitCode`, `durationMs`, `outputBytes`,
 *                 `truncated`, `timedOut` — gli UNICI campi che finiscono nell'audit dedicato
 *                 (`code_verification_runs`). MAI l'output: nel suo schema non c'è una colonna.
 *
 * `outcome` copre solo gli esiti dell'ESECUZIONE. Il rifiuto a monte (profilo non disponibile,
 * file non letto) è deciso dal ciclo, non qui, e ha i suoi codici nell'audit.
 */
final class VerificationResult
{
    /** Il processo è terminato con exit code 0: nessun problema segnalato. */
    public const PASSED = 'passed';
    /** Il processo è terminato con exit code != 0: verifica fallita (dato per il modello). */
    public const FAILED = 'failed';
    /** Superato il tetto di tempo: TERMINATO dal server. */
    public const TIMED_OUT = 'timed_out';
    /** Stop dell'utente durante l'esecuzione: processo TERMINATO. */
    public const KILLED = 'killed';
    /** Avvio fallito o imprevisto: nessun exit code affidabile. */
    public const ERROR = 'error';

    /** @var list<string> */
    public const OUTCOMES = [self::PASSED, self::FAILED, self::TIMED_OUT, self::KILLED, self::ERROR];

    public function __construct(
        public readonly string $outcome,
        public readonly ?int $exitCode,
        public readonly string $output,
        public readonly int $durationMs,
        public readonly int $outputBytes,
        public readonly bool $truncated,
        public readonly bool $timedOut,
    ) {
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new \InvalidArgumentException('VerificationResult: esito non ammesso.');
        }
        if ($durationMs < 0 || $outputBytes < 0) {
            throw new \InvalidArgumentException('VerificationResult: durata/byte non validi.');
        }
    }

    public function passed(): bool
    {
        return $this->outcome === self::PASSED;
    }
}
