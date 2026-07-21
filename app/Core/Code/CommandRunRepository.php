<?php

declare(strict_types=1);

namespace App\Core\Code;

use App\Core\Database;

/**
 * Code — Fase 6 / F6.8. Lifecycle PERSISTENTE dei comandi locali su `code_command_runs`, scoped al
 * workspace/sessione. SOLI METADATI; nessun output, argv o pattern grezzo (lo schema non ne ha
 * colonne).
 *
 * Transizioni MONOUSO e ATOMICHE (una sola conferma può «reclamare» una proposta):
 *   pending → running    (claimForExecution, guardia su digest+policy_version+scope)
 *   pending → rejected    (reject)
 *   pending → expired     (expirePending, sweep TTL)
 *   running → denied      (markDenied, rivalidazione fallita alla conferma)
 *   running → completed|failed|timed_out|killed|error  (finalize)
 *   running → error       (recoverOrphans, solo DB: nessun segnale tardivo a un PGID non verificabile)
 */
final class CommandRunRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Crea la proposta PENDING legata al turno assistant. Scope imposto nella stessa istruzione (la
     * sessione deve appartenere al workspace), come per patch/verifiche.
     */
    public function createPending(
        int $workspaceId,
        int $codeSessionId,
        int $assistantConversationId,
        string $commandId,
        string $digest,
        int $policyVersion,
        string $program,
        string $displaySummary,
    ): int {
        $now = date('c');
        $affected = $this->db->execute(
            'INSERT INTO code_command_runs
                (workspace_id, code_session_id, assistant_conversation_id, command_id, execution_id,
                 digest, policy_version, program, display_summary, state, truncated, created_at)
             SELECT ?, ?, ?, ?, NULL, ?, ?, ?, ?, \'pending\', 0, ?
             WHERE EXISTS (SELECT 1 FROM code_sessions WHERE id = ? AND workspace_id = ?)',
            [
                $workspaceId, $codeSessionId, $assistantConversationId, $commandId,
                $digest, $policyVersion, $program, $displaySummary, $now,
                $codeSessionId, $workspaceId,
            ]
        );
        if ($affected < 1) {
            throw new CodeWorkspaceException('Sessione Code inesistente in questo workspace: comando non registrato.');
        }

        return $this->db->lastInsertId();
    }

    /** Riga PENDING per la conferma (digest/policy/created_at), nello scope. */
    public function findPending(string $commandId, int $workspaceId, int $codeSessionId): ?array
    {
        return $this->db->fetch(
            'SELECT * FROM code_command_runs
             WHERE command_id = ? AND workspace_id = ? AND code_session_id = ? AND state = \'pending\'',
            [$commandId, $workspaceId, $codeSessionId]
        );
    }

    /**
     * Reclama ATOMICAMENTE la proposta per l'esecuzione (pending→running). Monouso: una seconda
     * conferma, un digest diverso o una policy_version diversa NON reclamano nulla (0 righe).
     */
    public function claimForExecution(
        string $commandId,
        int $workspaceId,
        int $codeSessionId,
        string $digest,
        int $policyVersion,
        string $executionId,
    ): bool {
        // Solo `confirmed_at` qui (sempre valorizzato): `started_at` e `process_group_id` arrivano
        // da markStarted() appena il processo parte, così una riga running porta il pgid.
        $affected = $this->db->execute(
            'UPDATE code_command_runs
                SET state = \'running\', execution_id = ?, confirmed_at = ?
             WHERE command_id = ? AND workspace_id = ? AND code_session_id = ?
               AND state = \'pending\' AND digest = ? AND policy_version = ?',
            [$executionId, date('c'), $commandId, $workspaceId, $codeSessionId, $digest, $policyVersion]
        );

        return $affected > 0;
    }

    /**
     * Persiste il `process_group_id` (e `started_at`) appena il processo è avviato, mentre lo stato
     * è ancora `running` e per la stessa `execution_id` reclamata. È idempotente e scoped: non può
     * toccare una riga di un'altra esecuzione.
     */
    public function markStarted(
        string $commandId,
        int $workspaceId,
        int $codeSessionId,
        string $executionId,
        int $processGroupId,
    ): bool {
        $affected = $this->db->execute(
            'UPDATE code_command_runs
                SET started_at = ?, process_group_id = ?
             WHERE command_id = ? AND workspace_id = ? AND code_session_id = ?
               AND execution_id = ? AND state = \'running\'',
            [date('c'), $processGroupId, $commandId, $workspaceId, $codeSessionId, $executionId]
        );

        return $affected === 1;
    }

    /** pending → rejected (Rifiuta). Nessuna esecuzione. */
    public function reject(string $commandId, int $workspaceId, int $codeSessionId): bool
    {
        $affected = $this->db->execute(
            'UPDATE code_command_runs SET state = \'rejected\', finished_at = ?
             WHERE command_id = ? AND workspace_id = ? AND code_session_id = ? AND state = \'pending\'',
            [date('c'), $commandId, $workspaceId, $codeSessionId]
        );

        return $affected > 0;
    }

    /**
     * pending|running → denied: rivalidazione fallita alla conferma (digest/policy stale, identità
     * dell'eseguibile cambiata, path sensibile/fuori root). Copre sia i controlli PRIMA del claim
     * (stato ancora pending) sia l'ultimo bind fallito dopo il claim (stato running).
     */
    public function markDenied(string $commandId, int $workspaceId, int $codeSessionId): void
    {
        $this->db->execute(
            'UPDATE code_command_runs SET state = \'denied\', finished_at = ?
             WHERE command_id = ? AND workspace_id = ? AND code_session_id = ? AND state IN (\'pending\', \'running\')',
            [date('c'), $commandId, $workspaceId, $codeSessionId]
        );
    }

    /** running → esito terminale d'esecuzione, con metadati e process_group_id. */
    public function finalize(
        string $commandId,
        int $workspaceId,
        int $codeSessionId,
        CommandResult $result,
        ?int $processGroupId,
    ): void {
        $state = match ($result->outcome) {
            CommandResult::COMPLETED => 'completed',
            CommandResult::FAILED => 'failed',
            CommandResult::TIMED_OUT => 'timed_out',
            CommandResult::KILLED => 'killed',
            default => 'error',
        };
        $this->db->execute(
            'UPDATE code_command_runs
                SET state = ?, exit_code = ?, duration_ms = ?, output_bytes = ?, truncated = ?,
                    process_group_id = ?, finished_at = ?
             WHERE command_id = ? AND workspace_id = ? AND code_session_id = ? AND state = \'running\'',
            [
                $state, $result->exitCode, $result->durationMs, $result->outputBytes,
                $result->truncated ? 1 : 0, $processGroupId, date('c'),
                $commandId, $workspaceId, $codeSessionId,
            ]
        );
    }

    /** Sweep TTL: pending troppo vecchie → expired (le loro proposte nello store sono già rimosse). */
    public function expirePending(int $ttlSeconds): int
    {
        $cutoff = date('c', time() - max(1, $ttlSeconds));

        return $this->db->execute(
            'UPDATE code_command_runs SET state = \'expired\', finished_at = ?
             WHERE state = \'pending\' AND created_at < ?',
            [date('c'), $cutoff]
        );
    }

    /**
     * Recovery degli ORFANI: una riga `running` troppo vecchia (host riavviato, richiesta morta a
     * metà) viene riconciliata SOLO nel DB → `error`. NESSUN segnale a `process_group_id`: un PGID
     * stale può essere stato riusato dal sistema e un segnale colpirebbe un processo estraneo. Lo
     * Stop IN-REQUEST resta garantito (là il runner ha il process handle vivo).
     */
    public function recoverOrphans(int $maxRunSeconds): int
    {
        // Il taglio è su `confirmed_at` (SEMPRE valorizzato al claim): una riga appena reclamata,
        // anche se `started_at`/pgid non sono ancora scritti, NON viene spazzata finché è recente.
        $cutoff = date('c', time() - max(1, $maxRunSeconds));

        return $this->db->execute(
            'UPDATE code_command_runs SET state = \'error\', finished_at = ?
             WHERE state = \'running\' AND (confirmed_at IS NULL OR confirmed_at < ?)',
            [date('c'), $cutoff]
        );
    }

    /**
     * Card per turno assistant (dopo refresh): pendenti (con digest per il form di conferma) e
     * terminali. Solo metadati + etichetta derivata.
     *
     * @return array<int, list<array{command_id:string,program:string,display_summary:string,state:string,exit_code:?int,label:string,digest:string}>>
     */
    public function forHistory(int $workspaceId, int $codeSessionId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT c.* FROM code_command_runs c
             JOIN code_sessions s ON s.id = c.code_session_id
             WHERE c.code_session_id = ? AND c.workspace_id = ? AND s.workspace_id = ?
               AND c.assistant_conversation_id IS NOT NULL
             ORDER BY c.assistant_conversation_id, c.id',
            [$codeSessionId, $workspaceId, $workspaceId]
        );

        $out = [];
        foreach ($rows as $row) {
            $assistantId = (int) $row['assistant_conversation_id'];
            $out[$assistantId][] = CommandRunRecord::fromRow($row);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function listForSession(int $workspaceId, int $codeSessionId, int $limit = 100): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Il limite deve essere positivo.');
        }

        return $this->db->fetchAll(
            'SELECT * FROM code_command_runs WHERE workspace_id = ? AND code_session_id = ? ORDER BY id DESC LIMIT ?',
            [$workspaceId, $codeSessionId, $limit]
        );
    }
}
