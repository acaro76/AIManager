<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 4 / F4.2. Validazione di SANDBOX di una proposta di patch: read-only, sempre
 * mediata da CodeWorkspace (PathGuard + SensitivePathPolicy + revoca valgono per costruzione).
 *
 * Trasforma un draft del modello (CodePatchProposal) in una patch CANONICA e applicabile
 * (CodePatch), calcolando gli hash LATO SERVER (mai dal modello) e verificando, per ogni file:
 *  - percorso relativo, dentro la root, non sensibile, non symlink;
 *  - per `update`: il file ESISTE, è testo, sta entro il tetto, e ogni `old` compare ESATTAMENTE
 *    UNA volta (nessun matching fuzzy);
 *  - per `create`: il percorso è LIBERO e il contenuto è testo entro il tetto;
 *  - byte cumulativi entro il tetto complessivo.
 *
 * NON scrive nulla: calcola il contenuto risultante e il diff, che restano una PROPOSTA. La
 * scrittura avviene solo dopo conferma esplicita, in CodePatchMutationService, che RIVALIDA tutto
 * da capo sotto lock. Rieseguendo questo validator contro il filesystem VIVO si ottiene lo stesso
 * digest solo se nulla è cambiato: è così che un worktree già sporco viene preservato (le
 * precondizioni di hash non tornano e l'applicazione viene negata come `stale`).
 */
final class CodePatchValidator
{
    public function __construct(private readonly CodePatchLimits $limits)
    {
    }

    /**
     * Valida una proposta contro lo stato ATTUALE del workspace. Non lancia sugli errori ATTESI
     * (fuori confine, file mancante, testo non trovato…): li traduce in un esito `invalid` con
     * motivo a vocabolario chiuso. Lancia solo su un imprevisto vero (bug), come il resto del motore.
     */
    public function validate(CodeWorkspace $workspace, CodePatchProposal $proposal): CodePatchValidation
    {
        if (count($proposal->operations) > $this->limits->maxOperations) {
            return CodePatchValidation::invalid(CodePatchValidation::TOO_MANY_OPS);
        }

        $ops = [];
        $entries = [];
        $totalBytes = 0;

        foreach ($proposal->operations as $draft) {
            $result = $draft->isCreate()
                ? $this->create($workspace, $draft)
                : $this->update($workspace, $draft);

            if (!$result instanceof CodePatchOp) {
                return CodePatchValidation::invalid($result); // $result è il motivo (string)
            }

            $totalBytes += strlen($result->newContent);
            if ($totalBytes > $this->limits->maxTotalBytes) {
                return CodePatchValidation::invalid(CodePatchValidation::TOO_LARGE);
            }

            $stat = UnifiedDiff::stat($result->oldContent, $result->newContent);
            $ops[] = $result;
            $entries[] = [
                'path' => $result->path,
                'op' => $result->kind,
                'diff' => UnifiedDiff::render($result->path, $result->oldContent, $result->newContent),
                'added' => $stat['added'],
                'removed' => $stat['removed'],
            ];
        }

        return CodePatchValidation::valid(new CodePatch($ops), $entries);
    }

    /**
     * @return CodePatchOp|string operazione verificata, oppure il motivo (vocabolario chiuso)
     */
    private function update(CodeWorkspace $workspace, CodePatchProposalOp $draft): CodePatchOp|string
    {
        if ($workspace->isSensitive($draft->path)) {
            return CodePatchValidation::SENSITIVE;
        }
        try {
            $abs = $workspace->resolve($draft->path);
        } catch (CodeWorkspaceException) {
            return CodePatchValidation::BLOCKED;
        }
        if (is_link($abs)) {
            return CodePatchValidation::SYMLINK;
        }
        if (!is_file($abs)) {
            return CodePatchValidation::NOT_FOUND;
        }

        try {
            $old = $workspace->readLimited($draft->path, $this->limits->maxFileBytes);
        } catch (CodeWorkspaceException) {
            // Esistenza/symlink/sensibile già escluse sopra: qui resta l'oltre-soglia (o una
            // sparizione in corsa, comunque non applicabile ora).
            return CodePatchValidation::TOO_LARGE;
        }
        if (strpos($old, "\0") !== false) {
            return CodePatchValidation::BINARY;
        }

        $buffer = $old;
        foreach ($draft->edits as $edit) {
            $occurrences = substr_count($buffer, $edit['old']);
            if ($occurrences === 0) {
                return CodePatchValidation::NO_MATCH;
            }
            if ($occurrences > 1) {
                return CodePatchValidation::AMBIGUOUS;
            }
            // Esattamente una: str_replace sostituisce quell'unica occorrenza.
            $buffer = str_replace($edit['old'], $edit['new'], $buffer);
        }

        if (strpos($buffer, "\0") !== false) {
            return CodePatchValidation::BINARY;
        }
        if (strlen($buffer) > $this->limits->maxFileBytes) {
            return CodePatchValidation::TOO_LARGE;
        }

        return new CodePatchOp(
            kind: CodePatchProposal::OP_UPDATE,
            path: $draft->path,
            baseSha256: hash('sha256', $old),
            edits: $draft->edits,
            content: null,
            resultSha256: hash('sha256', $buffer),
            newContent: $buffer,
            oldContent: $old,
        );
    }

    /**
     * @return CodePatchOp|string
     */
    private function create(CodeWorkspace $workspace, CodePatchProposalOp $draft): CodePatchOp|string
    {
        if ($workspace->isSensitive($draft->path)) {
            return CodePatchValidation::SENSITIVE;
        }
        try {
            $abs = $workspace->resolve($draft->path);
        } catch (CodeWorkspaceException) {
            return CodePatchValidation::BLOCKED;
        }
        // Un symlink o un file/dir già presente occupano il percorso: la creazione non sovrascrive.
        if (is_link($abs) || file_exists($abs)) {
            return CodePatchValidation::EXISTS;
        }

        $content = (string) $draft->content;
        if (strpos($content, "\0") !== false) {
            return CodePatchValidation::BINARY;
        }
        if (strlen($content) > $this->limits->maxFileBytes) {
            return CodePatchValidation::TOO_LARGE;
        }

        return new CodePatchOp(
            kind: CodePatchProposal::OP_CREATE,
            path: $draft->path,
            baseSha256: null,
            edits: [],
            content: $content,
            resultSha256: hash('sha256', $content),
            newContent: $content,
            oldContent: '',
        );
    }
}
