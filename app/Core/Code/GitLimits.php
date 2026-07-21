<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 8 / base Git read-only. Tetti DETERMINISTICI di UNA invocazione git:
 *   - `maxSeconds`     : oltre il tetto il processo è TERMINATO (nessun comando appeso);
 *   - `maxOutputBytes` : oltre il tetto si smette di leggere, si tronca e si chiude (nessuna
 *                        crescita illimitata su un diff enorme).
 *
 * DTO immutabile, come VerificationLimits/CommandRunLimits.
 */
final class GitLimits
{
    public function __construct(
        public readonly float $maxSeconds = 15.0,
        public readonly int $maxOutputBytes = 1048576, // 1 MiB
    ) {
        if ($maxSeconds <= 0.0 || $maxOutputBytes <= 0) {
            throw new \InvalidArgumentException('GitLimits: tetti non validi.');
        }
    }

    public static function defaults(): self
    {
        return new self();
    }
}
