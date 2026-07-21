<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 6 / F6.7. Esito PURO di UNA esecuzione di comando locale.
 *
 * Due destinatari, come VerificationResult:
 *   - `output`  : il testo che torna AL MODELLO e alla card live (stdout+stderr troncato). È un DATO,
 *                 NON persistito nel DB (nello schema non esiste una colonna dove infilarlo).
 *   - metadati  : `outcome` (vocabolario chiuso), `exitCode`, `durationMs`, `outputBytes`,
 *                 `truncated`, `timedOut` — gli unici campi che finiscono nell'audit.
 *
 * `outcome` copre SOLO gli esiti dell'ESECUZIONE. I rifiuti a monte del lifecycle
 * (`rejected`/`expired`/`denied`) sono decisi dal servizio di conferma, non qui.
 */
final class CommandResult
{
    /** Exit code 0. */
    public const COMPLETED = 'completed';
    /** Exit code != 0. */
    public const FAILED = 'failed';
    /** Superato il tetto di tempo: TERMINATO. */
    public const TIMED_OUT = 'timed_out';
    /** Stop dell'utente o output oltre il tetto: processo TERMINATO (albero). */
    public const KILLED = 'killed';
    /** Avvio impossibile o imprevisto: nessun exit code affidabile. */
    public const ERROR = 'error';

    /** @var list<string> Esiti di ESECUZIONE (sottoinsieme degli stati del lifecycle). */
    public const OUTCOMES = [self::COMPLETED, self::FAILED, self::TIMED_OUT, self::KILLED, self::ERROR];

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
            throw new \InvalidArgumentException('CommandResult: esito non ammesso.');
        }
        if ($durationMs < 0 || $outputBytes < 0) {
            throw new \InvalidArgumentException('CommandResult: durata/byte non validi.');
        }
    }
}
