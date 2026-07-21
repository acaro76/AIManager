<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 4 / F4.2. UNA operazione della patch CANONICA e applicabile: la forma verificata,
 * con gli hash calcolati dal SERVER (mai dal modello) e il contenuto risultante pronto per la
 * scrittura atomica. Immutabile. La produce SOLO CodePatchValidator.
 *
 * Distinzione fondamentale rispetto a CodePatchProposalOp (il draft del modello):
 *  - `baseSha256`   : hash del file ESISTENTE al momento della proposta (null per `create`);
 *  - `resultSha256` : hash del contenuto RISULTANTE (verificato dopo l'applicazione);
 *  - `newContent`   : il contenuto finale del file (runtime, MAI serializzato nel digest/audit);
 *  - `oldContent`   : il contenuto attuale del file (runtime, per il diff; '' per `create`).
 *
 * Gli hash e i percorsi entrano nella forma canonica (e quindi nel digest e nei metadati di
 * audit). Il CONTENUTO (newContent/oldContent) NON vi entra mai: resta un dettaglio di runtime.
 */
final class CodePatchOp
{
    /**
     * @param 'update'|'create' $kind
     * @param list<array{old: string, new: string}> $edits vuoto per `create`
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $path,
        public readonly ?string $baseSha256,
        public readonly array $edits,
        public readonly ?string $content,
        public readonly string $resultSha256,
        public readonly string $newContent,
        public readonly string $oldContent,
    ) {
    }

    public function isCreate(): bool
    {
        return $this->kind === CodePatchProposal::OP_CREATE;
    }

    /**
     * Forma CANONICA e deterministica dell'operazione: entra nel digest della patch e nei
     * metadati. Solo struttura, percorso e HASH — mai il contenuto o il testo delle modifiche.
     *
     * Il digest si ancora agli hash `base_sha256 → result_sha256`, NON al testo grezzo delle
     * modifiche: due sequenze di `edits` che producono lo STESSO risultato dallo STESSO file di
     * partenza sono la stessa trasformazione, e mostrano lo stesso diff. È esattamente ciò che
     * l'utente conferma guardando la card. Così la forma canonica è ricomputabile dai soli
     * metadati (`files_json`), senza conservare contenuti.
     *
     * @return array<string, mixed>
     */
    public function toCanonical(): array
    {
        if ($this->isCreate()) {
            return [
                'op' => CodePatchProposal::OP_CREATE,
                'path' => $this->path,
                'result_sha256' => $this->resultSha256,
            ];
        }

        return [
            'op' => CodePatchProposal::OP_UPDATE,
            'path' => $this->path,
            'base_sha256' => $this->baseSha256,
            'result_sha256' => $this->resultSha256,
        ];
    }

    /**
     * Metadati per l'audit e il repository delle operazioni: SOLO percorso relativo, tipo e hash.
     * Mai contenuto, mai le modifiche testuali.
     *
     * @return array{path: string, op: string, base_sha256: ?string, result_sha256: string}
     */
    public function toMetadata(): array
    {
        return [
            'path' => $this->path,
            'op' => $this->kind,
            'base_sha256' => $this->baseSha256,
            'result_sha256' => $this->resultSha256,
        ];
    }
}
