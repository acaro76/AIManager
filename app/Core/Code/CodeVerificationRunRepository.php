<?php

declare(strict_types=1);

namespace App\Core\Code;

use App\Core\Database;

/**
 * Code — Fase 5 / F5.6. Persistenza dell'audit delle verifiche (`code_verification_runs`), scoped
 * al workspace/sessione, mai alle tabelle LLM e mai a `code_operation_logs`.
 *
 * Registra SOLO METADATI a VOCABOLARIO CHIUSO: profilo (deve essere un id curato), linguaggio,
 * tipo, esito, exit code, durata, byte di output, troncamento e percorso relativo. Ogni campo è
 * validato a RUNTIME: non esiste un parametro in cui infilare l'output di un comando, un prompt o
 * un messaggio tecnico. Se un valore non è ammesso, NESSUNA riga viene scritta.
 *
 * Coerenza workspace↔sessione imposta come per patch/audit: l'INSERT avviene solo se la sessione
 * appartiene a quel workspace (verifica nella stessa istruzione, FK composita a valle).
 */
final class CodeVerificationRunRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly VerificationProfileRegistry $registry = new VerificationProfileRegistry(),
    ) {
    }

    /**
     * Registra una verifica tentata. `$assistantConversationId` può essere null: un processo può
     * essere stato avviato in un turno che poi fallisce/viene annullato prima del turno assistant,
     * e va comunque tracciato.
     */
    public function record(
        int $workspaceId,
        int $codeSessionId,
        ?int $assistantConversationId,
        CodeVerificationRunRecord $run,
    ): int {
        // L'id del profilo deve essere uno curato: nessun testo libero nella colonna profile_id.
        if ($this->registry->find($run->profileId) === null) {
            throw new \InvalidArgumentException('Profilo di verifica non ammesso nell\'audit.');
        }
        // language/kind/outcome e rel_path sono già validati dal costruttore del record; qui resta
        // solo lo scope. I vocabolari chiusi sono imposti anche dal CHECK dello schema.
        if ($run->relPath !== null) {
            RelativePath::assert($run->relPath);
        }

        $now = date('c');
        $affected = $this->db->execute(
            'INSERT INTO code_verification_runs
                (workspace_id, code_session_id, assistant_conversation_id, profile_id, language, kind, rel_path, outcome, exit_code, duration_ms, output_bytes, truncated, created_at)
             SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
             WHERE EXISTS (SELECT 1 FROM code_sessions WHERE id = ? AND workspace_id = ?)',
            [
                $workspaceId,
                $codeSessionId,
                $assistantConversationId,
                $run->profileId,
                $run->language,
                $run->kind,
                $run->relPath,
                $run->outcome,
                $run->exitCode,
                $run->durationMs,
                $run->outputBytes,
                $run->truncated ? 1 : 0,
                $now,
                $codeSessionId,
                $workspaceId,
            ]
        );
        if ($affected < 1) {
            throw new CodeWorkspaceException('Sessione Code inesistente in questo workspace: verifica non registrata.');
        }

        return $this->db->lastInsertId();
    }

    /**
     * Collega le verifiche del turno al loro turno assistant, DOPO che è stato persistito. Solo le
     * righe ancora scollegate (assistant NULL) e nello scope corretto: idempotente e non può
     * rubare le verifiche di un altro turno. Serve al rendering della cronologia (dopo refresh).
     *
     * @param list<int> $ids
     */
    public function linkAssistant(array $ids, int $assistantConversationId, int $workspaceId, int $codeSessionId): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));
        if ($ids === []) {
            return;
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $this->db->execute(
            "UPDATE code_verification_runs SET assistant_conversation_id = ?
             WHERE workspace_id = ? AND code_session_id = ? AND assistant_conversation_id IS NULL AND id IN ({$placeholders})",
            array_merge([$assistantConversationId, $workspaceId, $codeSessionId], $ids)
        );
    }

    /**
     * Card di verifica per turno assistant (dopo refresh), keyed per assistant_conversation_id.
     * Soli metadati + etichetta derivata; l'esito NON deriva mai dal testo del modello.
     *
     * @return array<int, list<array{profile:string,kind:string,outcome:string,exit_code:?int,path:?string,label:string}>>
     */
    public function forHistory(int $sessionId, int $workspaceId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT v.* FROM code_verification_runs v
             JOIN code_sessions s ON s.id = v.code_session_id
             WHERE v.code_session_id = ? AND v.workspace_id = ? AND s.workspace_id = ?
               AND v.assistant_conversation_id IS NOT NULL
             ORDER BY v.assistant_conversation_id, v.id',
            [$sessionId, $workspaceId, $workspaceId]
        );

        $out = [];
        foreach ($rows as $row) {
            $assistantId = (int) $row['assistant_conversation_id'];
            $outcome = (string) $row['outcome'];
            if (!in_array($outcome, CodeVerificationRunRecord::OUTCOMES, true)) {
                continue; // difesa: mai fidarsi ciecamente della riga
            }
            $exit = $row['exit_code'] === null ? null : (int) $row['exit_code'];
            $path = $row['rel_path'] === null || (string) $row['rel_path'] === '' ? null : (string) $row['rel_path'];
            $out[$assistantId][] = [
                'profile' => (string) $row['profile_id'],
                'kind' => (string) $row['kind'],
                'outcome' => $outcome,
                'exit_code' => $exit,
                'path' => $path,
                'label' => CodeVerificationRunRecord::label($outcome, $exit),
            ];
        }

        return $out;
    }

    /**
     * Verifiche di una sessione, più recenti prima (ordine deterministico).
     *
     * @return list<array<string, mixed>>
     */
    public function listForSession(int $workspaceId, int $codeSessionId, int $limit = 100): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Il limite deve essere positivo.');
        }

        return $this->db->fetchAll(
            'SELECT * FROM code_verification_runs WHERE workspace_id = ? AND code_session_id = ? ORDER BY id DESC LIMIT ?',
            [$workspaceId, $codeSessionId, $limit]
        );
    }

    /** Una verifica post-applicazione non va ripetuta a ogni caricamento della pagina. */
    public function findForAssistantProfilePath(
        int $workspaceId,
        int $codeSessionId,
        int $assistantConversationId,
        string $profileId,
        string $relPath,
    ): ?array {
        return $this->db->fetch(
            'SELECT * FROM code_verification_runs
             WHERE workspace_id = ? AND code_session_id = ? AND assistant_conversation_id = ?
               AND profile_id = ? AND rel_path = ?
             ORDER BY id DESC LIMIT 1',
            [$workspaceId, $codeSessionId, $assistantConversationId, $profileId, $relPath]
        );
    }
}
