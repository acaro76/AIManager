<?php

declare(strict_types=1);

namespace App\Core\Code;

use App\Core\Database;

/**
 * Code — Fase 7 / F7.8. Lifecycle PERSISTENTE dei processi locali su `code_processes`, scoped al
 * workspace/sessione. SOLI METADATI + identità d'esecuzione (per lo Stop sicuro dopo un refresh).
 *
 * Transizioni MONOUSO e ATOMICHE (una sola conferma può «reclamare» una proposta):
 *   pending  → starting   (claimForExecution, guardia su digest+policy_version+scope)
 *   starting → running    (markRunning, con pid/pgid/fingerprint/log, per la stessa execution_id)
 *   running  → stopped     (markStopped, arresto verificato dell'intero gruppo)
 *   pending  → rejected    (reject)
 *   pending  → expired     (expirePending, sweep TTL)
 *   pending|starting → denied  (markDenied, rivalidazione fallita alla conferma)
 *   starting|running → failed  (markFailed, avvio fallito o race di scope)
 *   starting|running → orphaned (markOrphaned, identità NON verificabile: nessun segnale a un PID stale)
 */
final class ProcessRunRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Crea la proposta PENDING legata al turno assistant. Scope imposto nella stessa istruzione (la
     * sessione deve appartenere al workspace), come per patch/verifiche/comandi.
     */
    public function createPending(
        int $workspaceId,
        int $codeSessionId,
        int $assistantConversationId,
        string $processId,
        string $digest,
        int $policyVersion,
        string $profileId,
        string $program,
        string $displaySummary,
        string $host,
        int $port,
        string $directory,
    ): int {
        $now = date('c');
        $affected = $this->db->execute(
            'INSERT INTO code_processes
                (workspace_id, code_session_id, assistant_conversation_id, process_id, execution_id,
                 digest, policy_version, profile_id, program, display_summary, host, port, directory,
                 state, created_at)
             SELECT ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?
             WHERE EXISTS (SELECT 1 FROM code_sessions WHERE id = ? AND workspace_id = ?)',
            [
                $workspaceId, $codeSessionId, $assistantConversationId, $processId,
                $digest, $policyVersion, $profileId, $program, $displaySummary, $host, $port, $directory, $now,
                $codeSessionId, $workspaceId,
            ]
        );
        if ($affected < 1) {
            throw new CodeWorkspaceException('Sessione Code inesistente in questo workspace: processo non registrato.');
        }

        return $this->db->lastInsertId();
    }

    /** Riga PENDING per la conferma (digest/policy/campi del piano), nello scope. */
    public function findPending(string $processId, int $workspaceId, int $codeSessionId): ?array
    {
        return $this->db->fetch(
            'SELECT * FROM code_processes
             WHERE process_id = ? AND workspace_id = ? AND code_session_id = ? AND state = \'pending\'',
            [$processId, $workspaceId, $codeSessionId]
        );
    }

    /** Riga nello scope, qualunque stato (per lo Stop idempotente e la ricostruzione della card). */
    public function find(string $processId, int $workspaceId, int $codeSessionId): ?array
    {
        return $this->db->fetch(
            'SELECT * FROM code_processes WHERE process_id = ? AND workspace_id = ? AND code_session_id = ?',
            [$processId, $workspaceId, $codeSessionId]
        );
    }

    /**
     * Reclama ATOMICAMENTE la proposta per l'avvio (pending→starting). Monouso: una seconda conferma,
     * un digest diverso o una policy_version diversa NON reclamano nulla (0 righe).
     */
    public function claimForExecution(
        string $processId,
        int $workspaceId,
        int $codeSessionId,
        string $digest,
        int $policyVersion,
        string $executionId,
    ): bool {
        $affected = $this->db->execute(
            'UPDATE code_processes
                SET state = \'starting\', execution_id = ?, confirmed_at = ?
             WHERE process_id = ? AND workspace_id = ? AND code_session_id = ?
               AND state = \'pending\' AND digest = ? AND policy_version = ?',
            [$executionId, date('c'), $processId, $workspaceId, $codeSessionId, $digest, $policyVersion]
        );

        return $affected > 0;
    }

    /**
     * starting → running: il server è partito e vive. Persiste pid/pgid e il FINGERPRINT d'esecuzione
     * (`run_token` + `start_signature`) e il `log_id` OPACO. Scoped alla stessa execution_id reclamata.
     */
    public function markRunning(
        string $processId,
        int $workspaceId,
        int $codeSessionId,
        string $executionId,
        int $pid,
        int $pgid,
        string $runToken,
        string $startSignature,
        string $logId,
    ): bool {
        $affected = $this->db->execute(
            'UPDATE code_processes
                SET state = \'running\', pid = ?, pgid = ?, run_token = ?, start_signature = ?,
                    log_id = ?, started_at = ?
             WHERE process_id = ? AND workspace_id = ? AND code_session_id = ?
               AND execution_id = ? AND state = \'starting\'',
            [$pid, $pgid, $runToken, $startSignature, $logId, date('c'), $processId, $workspaceId, $codeSessionId, $executionId]
        );

        return $affected === 1;
    }

    /** running → stopped: arresto verificato dell'intero gruppo (o processo già uscito). */
    public function markStopped(string $processId, int $workspaceId, int $codeSessionId, ?int $exitCode = null): bool
    {
        $affected = $this->db->execute(
            'UPDATE code_processes SET state = \'stopped\', exit_code = ?, finished_at = ?
             WHERE process_id = ? AND workspace_id = ? AND code_session_id = ? AND state IN (\'starting\', \'running\')',
            [$exitCode, date('c'), $processId, $workspaceId, $codeSessionId]
        );

        return $affected > 0;
    }

    /** starting|running → failed: avvio fallito o race di scope. */
    public function markFailed(string $processId, int $workspaceId, int $codeSessionId, ?int $exitCode = null): void
    {
        $this->db->execute(
            'UPDATE code_processes SET state = \'failed\', exit_code = ?, finished_at = ?
             WHERE process_id = ? AND workspace_id = ? AND code_session_id = ? AND state IN (\'starting\', \'running\')',
            [$exitCode, date('c'), $processId, $workspaceId, $codeSessionId]
        );
    }

    /**
     * starting|running → orphaned: l'identità del processo NON è verificabile (pidfile assente,
     * processo morto o fingerprint divergente = PID riciclato). NESSUN segnale a `pgid`: un PID stale
     * può essere stato riusato dal sistema e un segnale colpirebbe un processo estraneo. Solo DB.
     */
    public function markOrphaned(string $processId, int $workspaceId, int $codeSessionId): void
    {
        $this->db->execute(
            'UPDATE code_processes SET state = \'orphaned\', finished_at = ?
             WHERE process_id = ? AND workspace_id = ? AND code_session_id = ? AND state IN (\'starting\', \'running\')',
            [date('c'), $processId, $workspaceId, $codeSessionId]
        );
    }

    /** pending → rejected (Rifiuta). Nessun avvio. */
    public function reject(string $processId, int $workspaceId, int $codeSessionId): bool
    {
        $affected = $this->db->execute(
            'UPDATE code_processes SET state = \'rejected\', finished_at = ?
             WHERE process_id = ? AND workspace_id = ? AND code_session_id = ? AND state = \'pending\'',
            [date('c'), $processId, $workspaceId, $codeSessionId]
        );

        return $affected > 0;
    }

    /** pending|starting → denied: rivalidazione fallita alla conferma. */
    public function markDenied(string $processId, int $workspaceId, int $codeSessionId): void
    {
        $this->db->execute(
            'UPDATE code_processes SET state = \'denied\', finished_at = ?
             WHERE process_id = ? AND workspace_id = ? AND code_session_id = ? AND state IN (\'pending\', \'starting\')',
            [date('c'), $processId, $workspaceId, $codeSessionId]
        );
    }

    /** Sweep TTL: pending troppo vecchie → expired. */
    public function expirePending(int $ttlSeconds): int
    {
        $cutoff = date('c', time() - max(1, $ttlSeconds));

        return $this->db->execute(
            'UPDATE code_processes SET state = \'expired\', finished_at = ?
             WHERE state = \'pending\' AND created_at < ?',
            [date('c'), $cutoff]
        );
    }

    /**
     * Righe da RICONCILIARE nello scope: `starting`/`running`. La riconciliazione (processo ancora
     * vivo? identità coerente?) NON avviene in SQL — richiede l'inspector — quindi è compito del
     * servizio; qui si forniscono solo le righe attive con la loro identità.
     *
     * @return list<array<string, mixed>>
     */
    public function listActive(int $workspaceId, int $codeSessionId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM code_processes
             WHERE workspace_id = ? AND code_session_id = ? AND state IN (\'starting\', \'running\')
             ORDER BY id',
            [$workspaceId, $codeSessionId]
        );
    }

    /**
     * Tutti i processi attivi gestiti da AIManager. Uso intenzionalmente globale solo durante
     * l'arresto dell'applicazione, che deve ripulire ogni chat prima di terminare.
     *
     * @return list<array<string, mixed>>
     */
    public function listAllActive(): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM code_processes
             WHERE state IN (\'starting\', \'running\')
             ORDER BY id'
        );
    }

    /**
     * Card per turno assistant (dopo refresh): pendenti (con digest per la conferma) e terminali.
     * Solo metadati + etichetta derivata.
     *
     * @return array<int, list<array{process_id:string,profile_id:string,display_summary:string,host:string,port:int,state:string,exit_code:?int,label:string,digest:string,can_stop:bool}>>
     */
    public function forHistory(int $workspaceId, int $codeSessionId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT p.* FROM code_processes p
             JOIN code_sessions s ON s.id = p.code_session_id
             WHERE p.code_session_id = ? AND p.workspace_id = ? AND s.workspace_id = ?
               AND p.assistant_conversation_id IS NOT NULL
             ORDER BY p.assistant_conversation_id, p.id',
            [$codeSessionId, $workspaceId, $workspaceId]
        );

        $out = [];
        foreach ($rows as $row) {
            $assistantId = (int) $row['assistant_conversation_id'];
            $out[$assistantId][] = ProcessRunRecord::fromRow($row);
        }

        return $out;
    }
}
