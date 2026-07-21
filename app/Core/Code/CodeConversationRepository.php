<?php

declare(strict_types=1);

namespace App\Core\Code;

use App\Core\Database;

/**
 * Code — Fase 1 / F1.3. Persistenza dei turni di conversazione (tabella `code_conversations`),
 * legati a una sessione Code, mai a `conversations` LLM.
 *
 * La SCRITTURA è scoped: `appendForWorkspace()` inserisce solo se la sessione appartiene a
 * quel workspace, verificando l'ownership NELLA STESSA query (INSERT…SELECT…WHERE EXISTS), non
 * con un controllo separato seguito dall'insert. È l'API destinata al service.
 *
 * `history()` è la lettura a basso livello per id di sessione: il chiamante DEVE aver già
 * verificato lo scope (via CodeSessionRepository::findForWorkspace). Per sicurezza è offerta
 * anche `historyForWorkspace()`, che verifica lo scope in SQL (JOIN sul workspace) e non può
 * leggere conversazioni di una sessione appartenente a un'altra root. Prepared statement
 * ovunque; errori controllati su sessione inesistente/ownership errata.
 */
final class CodeConversationRepository
{
    private const ROLES = ['user', 'assistant'];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Aggiunge un turno SOLO se, NELLA STESSA istruzione (niente check separato soggetto a
     * race): la sessione appartiene a $workspaceId, la sessione è `active` e il workspace è
     * `active`. Il JOIN con code_workspaces impone anche la root attiva. Se una qualsiasi
     * condizione manca (sessione inesistente/altra root/archiviata, o root revocata) NESSUNA
     * riga viene inserita e si lancia un errore controllato. Il ruolo è validato (oltre al
     * CHECK del DB). Restituisce l'id.
     *
     * L'archiviazione blocca le SCRITTURE, non le letture: historyForWorkspace() resta
     * consultabile anche per sessioni archiviate.
     */
    public function appendForWorkspace(int $codeSessionId, int $workspaceId, string $role, string $content, string $provider = ''): int
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('Ruolo non valido: ' . $role);
        }
        $affected = $this->db->execute(
            'INSERT INTO code_conversations (code_session_id, role, content, provider, created_at)
             SELECT ?, ?, ?, ?, ?
             WHERE EXISTS (
                 SELECT 1 FROM code_sessions s
                 JOIN code_workspaces w ON w.id = s.workspace_id
                 WHERE s.id = ? AND s.workspace_id = ? AND s.status = \'active\' AND w.status = \'active\'
             )',
            [$codeSessionId, $role, $content, $provider, date('c'), $codeSessionId, $workspaceId]
        );
        if ($affected < 1) {
            // sessione inesistente/altra root/archiviata, o workspace revocato: niente riga
            throw new CodeWorkspaceException('Sessione Code non scrivibile (inesistente, archiviata o workspace revocato): turno non salvato.');
        }
        return $this->db->lastInsertId();
    }

    /**
     * Ultimi $limit turni della sessione, in ordine CRONOLOGICO stabile (id crescente:
     * monotòno con l'inserimento). $limit deve essere positivo.
     *
     * SICUREZZA: prende solo l'id di sessione — il chiamante deve aver già verificato lo scope
     * col workspace. Se non l'ha fatto, usare `historyForWorkspace()`.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $codeSessionId, int $limit): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Il limite della cronologia deve essere positivo.');
        }
        // ultimi N per id desc, poi rimessi in ordine cronologico crescente
        $rows = $this->db->fetchAll(
            'SELECT * FROM (
                SELECT * FROM code_conversations WHERE code_session_id = ? ORDER BY id DESC LIMIT ?
             ) ORDER BY id ASC',
            [$codeSessionId, $limit]
        );
        return $rows;
    }

    /**
     * Come history() ma con lo SCOPE verificato in SQL: restituisce righe solo se la sessione
     * appartiene a $workspaceId. Impedisce letture cross-workspace anche se il chiamante non ha
     * verificato prima lo scope.
     *
     * @return list<array<string, mixed>>
     */
    public function historyForWorkspace(int $codeSessionId, int $workspaceId, int $limit): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Il limite della cronologia deve essere positivo.');
        }
        return $this->db->fetchAll(
            'SELECT c.* FROM (
                SELECT cc.* FROM code_conversations cc
                JOIN code_sessions s ON s.id = cc.code_session_id
                WHERE cc.code_session_id = ? AND s.workspace_id = ?
                ORDER BY cc.id DESC LIMIT ?
             ) c ORDER BY c.id ASC',
            [$codeSessionId, $workspaceId, $limit]
        );
    }

    /**
     * Turni NUOVI per il riepilogo della memoria (Fase 9): SOLO i turni con `id` maggiore del cutoff
     * (`last_conversation_id` della memoria precedente) e non oltre `$upToId` (l'assistant appena
     * salvato, limite superiore). Scope verificato in SQL (JOIN sul workspace): mai turni di un'altra
     * root. Al più gli ULTIMI `$limit`, restituiti in ordine CRONOLOGICO stabile (id crescente).
     *
     * @return list<array<string, mixed>>
     */
    public function newTurnsForSummary(int $codeSessionId, int $workspaceId, int $afterId, int $upToId, int $limit): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Il limite dei turni deve essere positivo.');
        }
        return $this->db->fetchAll(
            'SELECT c.* FROM (
                SELECT cc.* FROM code_conversations cc
                JOIN code_sessions s ON s.id = cc.code_session_id
                WHERE cc.code_session_id = ? AND s.workspace_id = ? AND cc.id > ? AND cc.id <= ?
                ORDER BY cc.id DESC LIMIT ?
             ) c ORDER BY c.id ASC',
            [$codeSessionId, $workspaceId, $afterId, $upToId, $limit]
        );
    }
}
