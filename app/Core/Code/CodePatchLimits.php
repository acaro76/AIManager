<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 4 / F4.1. Limiti DETERMINISTICI della modifica sicura dei file.
 *
 * Sono i tetti della PROPOSTA e dell'APPLICAZIONE di una patch, distinti dai tetti del recupero
 * (RetrievalLimits) e del ciclo (CodeAgentLimits). Qui si limita quanto una singola proposta può
 * toccare: quante operazioni, quante modifiche per file, quanti byte per file e complessivi, e
 * per quanto tempo una proposta resta applicabile prima di scadere.
 *
 * Value object immutabile e PURO (nessun IO, nessun DB). I valori non validi sono un errore di
 * programmazione: \InvalidArgumentException, non CodeWorkspaceException.
 */
final class CodePatchLimits
{
    public function __construct(
        /** Operazioni (file) ammesse in UNA proposta. */
        public readonly int $maxOperations,
        /** Modifiche (coppie old/new) ammesse in UN update. */
        public readonly int $maxEditsPerOp,
        /** Byte massimi di UN file (esistente da leggere o nuovo contenuto). */
        public readonly int $maxFileBytes,
        /** Byte cumulativi del contenuto risultante di tutti i file della proposta. */
        public readonly int $maxTotalBytes,
        /** Secondi di validità di una proposta prima di scadere (non più applicabile). */
        public readonly int $ttlSeconds,
    ) {
        $positive = [
            'maxOperations' => $maxOperations,
            'maxEditsPerOp' => $maxEditsPerOp,
            'maxFileBytes' => $maxFileBytes,
            'maxTotalBytes' => $maxTotalBytes,
            'ttlSeconds' => $ttlSeconds,
        ];
        foreach ($positive as $name => $value) {
            if ($value <= 0) {
                throw new \InvalidArgumentException("CodePatchLimits: {$name} deve essere > 0 (dato: {$value}).");
            }
        }
        if ($maxTotalBytes < $maxFileBytes) {
            throw new \InvalidArgumentException(
                "CodePatchLimits: maxTotalBytes ({$maxTotalBytes}) < maxFileBytes ({$maxFileBytes})."
            );
        }
    }

    /**
     * Default prudenti: poche operazioni per proposta, file testuali entro 256 KiB (come la
     * lettura confinata), 1 MiB complessivo e proposta valida mezz'ora — abbastanza per
     * rivedere e confermare, non tanto da diventare "stantia" rispetto a un worktree che cambia.
     */
    public static function defaults(): self
    {
        return new self(
            maxOperations: 20,
            maxEditsPerOp: 100,
            maxFileBytes: 262144,
            maxTotalBytes: 1048576,
            ttlSeconds: 1800,
        );
    }
}
