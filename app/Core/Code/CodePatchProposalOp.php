<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 4 / F4.2. UNA operazione della proposta grezza (draft) del modello: la forma
 * richiesta, senza hash e senza contatto col filesystem. Immutabile.
 *
 * `update` porta una lista di modifiche esatte (old/new); `create` porta il contenuto del nuovo
 * file. I due sono mutuamente esclusivi per costruzione (li produce solo CodePatchProposal).
 */
final class CodePatchProposalOp
{
    /**
     * @param 'update'|'create' $kind
     * @param list<array{old: string, new: string}> $edits vuoto per `create`
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $path,
        public readonly array $edits,
        public readonly ?string $content,
    ) {
    }

    public function isCreate(): bool
    {
        return $this->kind === CodePatchProposal::OP_CREATE;
    }
}
