<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 8 / base Git read-only. Esito PURO di UNA invocazione git.
 *
 * Distingue i destinatari, come VerificationResult:
 *   - `stdout`  : il testo prodotto da git. È un DATO non fidato (nomi file, contenuti diff).
 *   - metadati  : `started` (l'avvio è riuscito?), `exitCode`, `truncated`, `timedOut`.
 *
 * stderr NON è esposto: le diagnosi passano per i metadati, così l'output d'errore di git — anch'esso
 * potenzialmente pilotato dal contenuto del repo — non entra come testo interpretabile.
 */
final class GitCommandResult
{
    public function __construct(
        public readonly bool $started,
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly bool $truncated,
        public readonly bool $timedOut,
    ) {
    }

    /** L'avvio è riuscito e git è uscito con 0, senza timeout: esito pulito. */
    public function ok(): bool
    {
        return $this->started && !$this->timedOut && $this->exitCode === 0;
    }
}
