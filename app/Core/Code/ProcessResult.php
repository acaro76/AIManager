<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 7. Esito PURO di UN tentativo di AVVIO di un processo persistente.
 *
 * A differenza di CommandResult (Fase 6), non riporta un output: un server è persistente, quindi
 * l'esito dell'avvio è «è partito e vive» oppure «non è partito». L'identità (pid/pgid + fingerprint)
 * serve allo Stop sicuro dopo un refresh; i log vivono su file, non qui.
 */
final class ProcessResult
{
    /** Il server è partito ed è vivo, con identità catturata. */
    public const STARTED = 'started';
    /** Avvio impossibile o processo morto subito: nessuna identità affidabile. */
    public const ERROR = 'error';

    /** @var list<string> */
    public const OUTCOMES = [self::STARTED, self::ERROR];

    public function __construct(
        public readonly string $outcome,
        public readonly ?int $pid = null,
        public readonly ?int $pgid = null,
        public readonly string $runToken = '',
        public readonly string $startSignature = '',
        public readonly string $logId = '',
    ) {
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new \InvalidArgumentException('ProcessResult: esito non ammesso.');
        }
    }

    public function started(): bool
    {
        return $this->outcome === self::STARTED
            && $this->pid !== null && $this->pid > 1
            && $this->pgid !== null && $this->pgid > 1
            && $this->runToken !== '';
    }

    public static function error(): self
    {
        return new self(self::ERROR);
    }
}
