<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 8 / esecutore staging. Esito IMMUTABILE e a vocabolario CHIUSO dell'esecuzione dello
 * staging selettivo. Contiene SOLO metadati sicuri: esito, conteggi, digest/fingerprint del piano,
 * percorsi selezionati già ammessi (non sensibili) e un messaggio controllato. MAI stdout/stderr Git
 * grezzo, mai nomi o contenuti sensibili/runtime.
 */
final class GitStageResult
{
    /** Staging completato: l'indice reale è stato sostituito atomicamente. */
    public const STAGED = 'staged';
    /** Rivalidazione di sicurezza fallita (digest, workspace, top-level, symlink, revoca): nessuna mutazione. */
    public const REJECTED = 'rejected';
    /** Lo stato reale è cambiato dopo la proposta (fingerprint diversa): nessuna mutazione. */
    public const STALE = 'stale';
    /** Errore/timeout/output troncato: fail closed, indice reale invariato. */
    public const ERROR = 'error';

    /** @var list<string> */
    public const OUTCOMES = [self::STAGED, self::REJECTED, self::STALE, self::ERROR];

    /**
     * @param list<string> $stagedPaths percorsi effettivamente messi in stage (solo in caso STAGED);
     *        sono i percorsi del piano già validati e non sensibili. Vuoto negli altri esiti.
     */
    private function __construct(
        public readonly string $outcome,
        public readonly int $stagedCount,
        public readonly array $stagedPaths,
        public readonly int $excludedCount,
        public readonly string $digest,
        public readonly string $fingerprint,
        public readonly string $message,
    ) {
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new \InvalidArgumentException('GitStageResult: esito non ammesso.');
        }
    }

    /** @param list<string> $stagedPaths */
    public static function staged(array $stagedPaths, int $excludedCount, string $digest, string $fingerprint): self
    {
        return new self(self::STAGED, count($stagedPaths), array_values($stagedPaths), max(0, $excludedCount), $digest, $fingerprint, 'Percorsi messi in stage.');
    }

    public static function rejected(string $message, string $digest = '', string $fingerprint = ''): self
    {
        return new self(self::REJECTED, 0, [], 0, $digest, $fingerprint, $message);
    }

    public static function stale(string $message, string $digest = '', string $fingerprint = ''): self
    {
        return new self(self::STALE, 0, [], 0, $digest, $fingerprint, $message);
    }

    public static function error(string $message, string $digest = '', string $fingerprint = ''): self
    {
        return new self(self::ERROR, 0, [], 0, $digest, $fingerprint, $message);
    }

    public function isStaged(): bool
    {
        return $this->outcome === self::STAGED;
    }
}
