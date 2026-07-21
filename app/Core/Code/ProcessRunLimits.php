<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 7 / F7.1. Tetti DETERMINISTICI del ciclo di vita di un processo persistente.
 *
 * A differenza di un comando breve (Fase 6), un server resta VIVO tra le richieste: qui i tetti non
 * riguardano la durata (un server può girare a lungo) ma l'AVVIO, l'arresto e l'esposizione dei log.
 *
 *   - startTimeoutSeconds : quanto si attende il pidfile del server prima di dichiarare fallito l'avvio;
 *   - startStabilitySeconds : finestra minima in cui il processo deve restare vivo dopo il pidfile;
 *   - stopGraceSeconds    : finestra tra SIGTERM e SIGKILL sull'intero gruppo, all'arresto;
 *   - startingStaleSeconds : una riga `starting` più vecchia di così, senza processo identificabile,
 *                            è considerata orfana (avvio mai completato);
 *   - maxLogFileBytes     : limite OS reale del file stdout/stderr; raggiungerlo può terminare il server;
 *   - maxLogExcerptBytes  : quanti byte di log si ESPONGONO (coda), come dato non fidato e bounded.
 *
 * Value object immutabile e PURO. Valori non validi = errore di programmazione.
 */
final class ProcessRunLimits
{
    public function __construct(
        public readonly float $startTimeoutSeconds,
        public readonly float $stopGraceSeconds,
        public readonly int $startingStaleSeconds,
        public readonly int $maxLogFileBytes,
        public readonly int $maxLogExcerptBytes,
        public readonly float $startStabilitySeconds,
    ) {
        if ($startTimeoutSeconds <= 0.0 || $stopGraceSeconds <= 0.0 || $startStabilitySeconds <= 0.0) {
            throw new \InvalidArgumentException('ProcessRunLimits: timeout/grace devono essere > 0.');
        }
        if ($startingStaleSeconds <= 0 || $maxLogFileBytes <= 0 || $maxLogExcerptBytes <= 0
            || $maxLogExcerptBytes > $maxLogFileBytes) {
            throw new \InvalidArgumentException('ProcessRunLimits: stale/log devono essere > 0.');
        }
    }

    public static function defaults(): self
    {
        return new self(
            startTimeoutSeconds: 5.0,
            stopGraceSeconds: 3.0,
            startingStaleSeconds: 30,
            maxLogFileBytes: 1048576, // 1 MiB reale: imposto con RLIMIT_FSIZE nel launcher
            maxLogExcerptBytes: 16384, // 16 KiB di coda esposti alla UI
            startStabilitySeconds: 0.25, // intercetta exec/bind falliti subito dopo il pidfile
        );
    }
}
