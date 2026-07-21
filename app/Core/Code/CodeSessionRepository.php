<?php

declare(strict_types=1);

namespace App\Core\Code;

use App\Core\Database;

/**
 * Code — Fase 1 / F1.3. Persistenza delle sessioni di chat Code (tabella `code_sessions`),
 * legate al WORKSPACE (cartella), mai a `projects`/`sessions` LLM.
 *
 * Ogni lettura/aggiornamento è SCOPED al workspace: non ci si fida del solo id di sessione
 * arrivato dal controller, così una root non può accedere alle sessioni di un'altra. Tutte le
 * query usano prepared statement; errori controllati (CodeWorkspaceException) su workspace o
 * sessione inesistenti.
 */
final class CodeSessionRepository
{
    private const STATUSES = ['active', 'archived'];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Crea una sessione per un workspace ATTIVO. Fallisce in modo controllato se il workspace
     * non esiste o è revocato (non solo la FK: una root revocata non deve avviare sessioni).
     */
    public function create(int $workspaceId, string $title = ''): int
    {
        if (!$this->activeWorkspaceExists($workspaceId)) {
            throw new CodeWorkspaceException('Workspace inesistente o revocato: impossibile creare la sessione Code.');
        }
        $now = date('c');
        $this->db->execute(
            'INSERT INTO code_sessions (workspace_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$workspaceId, $title, 'active', $now, $now]
        );
        return $this->db->lastInsertId();
    }

    /**
     * Lookup SCOPED: la sessione deve appartenere a QUEL workspace, altrimenti null. È il
     * gate che il controller deve usare prima di leggere la cronologia.
     *
     * @return array<string, mixed>|null
     */
    public function findForWorkspace(int $sessionId, int $workspaceId): ?array
    {
        return $this->db->fetch(
            'SELECT * FROM code_sessions WHERE id = ? AND workspace_id = ?',
            [$sessionId, $workspaceId]
        );
    }

    /**
     * Sessioni di UN workspace, in ordine deterministico (più recenti prima; id come
     * spareggio stabile). Mai sessioni di altre root.
     *
     * @return list<array<string, mixed>>
     */
    public function listByWorkspace(int $workspaceId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM code_sessions WHERE workspace_id = ? ORDER BY updated_at DESC, id DESC',
            [$workspaceId]
        );
    }

    /**
     * Sessioni attive più recenti tra tutte le cartelle ancora autorizzate. Serve soltanto
     * alla navigazione Code per riprendere il lavoro; nessuna tabella delle chat LLM è coinvolta.
     *
     * @return list<array<string, mixed>>
     */
    public function recentActiveAcrossWorkspaces(int $limit = 50): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Il limite deve essere positivo.');
        }
        return $this->db->fetchAll(
            "SELECT s.* FROM code_sessions s
             JOIN code_workspaces w ON w.id = s.workspace_id
             WHERE s.status = 'active' AND w.status = 'active'
             ORDER BY s.updated_at DESC, s.id DESC LIMIT ?",
            [$limit]
        );
    }

    /**
     * Aggiorna lo stato in modo SCOPED. Fallisce in modo controllato se la sessione non esiste
     * in quel workspace (nessuna modifica silenziosa a sessioni altrui).
     */
    public function updateStatusForWorkspace(int $sessionId, int $workspaceId, string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Stato sessione non valido: ' . $status);
        }
        $affected = $this->db->execute(
            'UPDATE code_sessions SET status = ?, updated_at = ? WHERE id = ? AND workspace_id = ?',
            [$status, date('c'), $sessionId, $workspaceId]
        );
        if ($affected < 1) {
            throw new CodeWorkspaceException('Sessione inesistente in questo workspace.');
        }
    }

    public function renameForWorkspace(int $sessionId, int $workspaceId, string $title): void
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 120 || preg_match('/[\x00-\x1F\x7F]/u', $title) === 1) {
            throw new \InvalidArgumentException('Il titolo deve contenere da 1 a 120 caratteri validi.');
        }
        $affected = $this->db->execute(
            'UPDATE code_sessions SET title = ?, updated_at = ?
             WHERE id = ? AND workspace_id = ? AND status = \'active\'',
            [$title, date('c'), $sessionId, $workspaceId]
        );
        if ($affected < 1) {
            throw new CodeWorkspaceException('Sessione inesistente o archiviata in questo workspace.');
        }
    }

    /**
     * Aggiorna updated_at (per far salire la sessione nella lista) in modo SCOPED. Usato quando
     * arriva un nuovo turno. Fallisce in modo controllato se la sessione non è del workspace.
     */
    public function touchForWorkspace(int $sessionId, int $workspaceId): void
    {
        $affected = $this->db->execute(
            'UPDATE code_sessions SET updated_at = ? WHERE id = ? AND workspace_id = ?',
            [date('c'), $sessionId, $workspaceId]
        );
        if ($affected < 1) {
            throw new CodeWorkspaceException('Sessione inesistente in questo workspace.');
        }
    }

    private function activeWorkspaceExists(int $workspaceId): bool
    {
        return $this->db->fetch(
            "SELECT id FROM code_workspaces WHERE id = ? AND status = 'active'",
            [$workspaceId]
        ) !== null;
    }
}
