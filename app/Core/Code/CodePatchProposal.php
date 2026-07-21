<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 4 / F4.2. La proposta di modifica COSÌ COME LA CHIEDE IL MODELLO: un DRAFT
 * strutturale, a vocabolario chiuso, SENZA hash e SENZA alcun contatto col filesystem.
 *
 * È l'unica cosa che l'azione `propose_patch` può trasportare: il testo del modello NON è un
 * comando, è un DATO da validare. Qui si controlla solo la FORMA (operazioni ammesse, percorsi
 * relativi e canonici, modifiche ben formate, niente NUL, unicità dei file); la validazione di
 * SANDBOX (confine, sensibili, symlink, esistenza, hash, corrispondenza esatta) e il calcolo
 * degli hash avvengono dopo, in CodePatchValidator, con la mediazione di CodeWorkspace.
 *
 * REGOLA DI SICUREZZA come per CodeAgentAction: i messaggi d'errore sono a TESTO FISSO e non
 * riportano mai il valore ricevuto (potrebbe essere contenuto ostile letto da un file).
 *
 * Vocabolario CHIUSO delle operazioni:
 *   - update  {path, edits:[{old,new}, …]}  → modifica di un file di testo ESISTENTE
 *   - create  {path, content}               → creazione di un NUOVO file di testo
 * Nessun'altra operazione esiste: delete, rename, chmod, mkdir e simili non sono operazioni,
 * sono output non validi.
 */
final class CodePatchProposal
{
    public const OP_UPDATE = 'update';
    public const OP_CREATE = 'create';

    /** @var list<CodePatchProposalOp> */
    public readonly array $operations;

    /** @param list<CodePatchProposalOp> $operations */
    private function __construct(array $operations)
    {
        $this->operations = $operations;
    }

    /**
     * Costruisce e VALIDA (forma) la proposta dal payload dell'azione `propose_patch`.
     *
     * @param array<string, mixed> $data payload dell'azione (già estratto come oggetto JSON)
     * @throws \InvalidArgumentException messaggio a testo fisso, riproponibile
     */
    public static function fromActionData(array $data, CodePatchLimits $limits): self
    {
        $changes = $data['changes'] ?? null;
        if (!is_array($changes) || $changes === [] || array_keys($changes) !== range(0, count($changes) - 1)) {
            throw new \InvalidArgumentException('Il campo "changes" deve essere una lista non vuota di operazioni.');
        }
        if (count($changes) > $limits->maxOperations) {
            throw new \InvalidArgumentException('Troppe operazioni nella proposta: riducile.');
        }

        $operations = [];
        $seenPaths = [];
        foreach ($changes as $change) {
            if (!is_array($change)) {
                throw new \InvalidArgumentException('Ogni operazione deve essere un oggetto JSON.');
            }
            $op = self::operation($change, $limits);
            if (isset($seenPaths[$op->path])) {
                throw new \InvalidArgumentException('Due operazioni sullo stesso file: unisci le modifiche in una sola.');
            }
            $seenPaths[$op->path] = true;
            $operations[] = $op;
        }

        return new self($operations);
    }

    public static function fromWholeFile(string $path, string $content, ?string $oldContent, CodePatchLimits $limits): self
    {
        if ($oldContent === '') {
            throw new \InvalidArgumentException('Un file esistente vuoto richiede il protocollo old/new.');
        }

        return self::fromActionData([
            'changes' => [$oldContent === null
                ? ['op' => self::OP_CREATE, 'path' => $path, 'content' => $content]
                : ['op' => self::OP_UPDATE, 'path' => $path, 'edits' => [['old' => $oldContent, 'new' => $content]]]],
        ], $limits);
    }

    /**
     * @param array<string, mixed> $change
     */
    private static function operation(array $change, CodePatchLimits $limits): CodePatchProposalOp
    {
        $kind = $change['op'] ?? null;
        if ($kind !== self::OP_UPDATE && $kind !== self::OP_CREATE) {
            throw new \InvalidArgumentException('Operazione sconosciuta. Usa esattamente "update" o "create".');
        }
        $path = self::path($change);

        if ($kind === self::OP_CREATE) {
            return new CodePatchProposalOp(self::OP_CREATE, $path, [], self::content($change));
        }

        return new CodePatchProposalOp(self::OP_UPDATE, $path, self::edits($change, $limits), null);
    }

    /**
     * @param array<string, mixed> $change
     */
    private static function path(array $change): string
    {
        $raw = $change['path'] ?? null;
        if (!is_string($raw)) {
            throw new \InvalidArgumentException('Manca il campo "path" (stringa) dell\'operazione.');
        }
        $clean = trim(Utf8::clean($raw));
        while (str_starts_with($clean, './')) {
            $clean = substr($clean, 2);
        }
        $clean = rtrim($clean, '/');
        try {
            RelativePath::assert($clean);
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException(
                'Percorso non valido: usa un percorso RELATIVO alla cartella, senza "..", senza "/" iniziale.'
            );
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $change
     * @return list<array{old: string, new: string}>
     */
    private static function edits(array $change, CodePatchLimits $limits): array
    {
        $edits = $change['edits'] ?? null;
        if (!is_array($edits) || $edits === [] || array_keys($edits) !== range(0, count($edits) - 1)) {
            throw new \InvalidArgumentException('Il campo "edits" deve essere una lista non vuota di modifiche.');
        }
        if (count($edits) > $limits->maxEditsPerOp) {
            throw new \InvalidArgumentException('Troppe modifiche in un solo file: riducile.');
        }

        $out = [];
        foreach ($edits as $edit) {
            if (!is_array($edit)) {
                throw new \InvalidArgumentException('Ogni modifica deve essere un oggetto con "old" e "new".');
            }
            $old = $edit['old'] ?? null;
            $new = $edit['new'] ?? null;
            if (!is_string($old) || !is_string($new)) {
                throw new \InvalidArgumentException('"old" e "new" devono essere stringhe.');
            }
            if ($old === '') {
                throw new \InvalidArgumentException('"old" non può essere vuoto: deve indicare il testo esatto da sostituire.');
            }
            if (self::hasNul($old) || self::hasNul($new)) {
                throw new \InvalidArgumentException('Le modifiche non possono contenere byte NUL.');
            }
            $out[] = ['old' => $old, 'new' => $new];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $change
     */
    private static function content(array $change): string
    {
        $content = $change['content'] ?? null;
        if (!is_string($content)) {
            throw new \InvalidArgumentException('Manca il campo "content" (stringa) per la creazione del file.');
        }
        if (self::hasNul($content)) {
            throw new \InvalidArgumentException('Il contenuto non può contenere byte NUL: i file binari non sono ammessi.');
        }

        return $content;
    }

    private static function hasNul(string $text): bool
    {
        return strpos($text, "\0") !== false;
    }
}
