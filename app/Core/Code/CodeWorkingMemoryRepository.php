<?php

declare(strict_types=1);

namespace App\Core\Code;

use App\Core\Database;

/**
 * Code — Fase 9. Persistenza della MEMORIA DI LAVORO Code su `code_working_memories`:
 * UNA sola riga CORRENTE per sessione (nessuna versione accumulata). Collegata al runtime dallo
 * Step 3 (riepilogo dopo un turno assistant riuscito) e dallo Step 4 (ripresa del lavoro: la nuova
 * sessione può ereditare come CONTESTO la memoria più recente di un'altra sessione dello stesso
 * workspace, vedi {@see self::latestForWorkspaceExcludingSession()}).
 *
 * Ogni operazione è SCOPED al workspace, come per le altre tabelle Code: non ci si fida del solo id
 * di sessione arrivato dal chiamante, così una root non può leggere né scrivere la memoria di
 * un'altra. Errori controllati (CodeWorkspaceException) su scope errato, sessione inesistente,
 * workspace revocato o conversazione di un'altra sessione.
 */
final class CodeWorkingMemoryRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Salva (UPSERT) la memoria corrente della sessione. Riceve un {@see CodeWorkingMemory} (non JSON
     * libero) e lo serializza con `toJson()`. Lo scope è imposto NELLA STESSA istruzione: workspace
     * ATTIVO, sessione appartenente a quel workspace e ANCORA `active` (chiude la finestra TOCTOU:
     * un'archiviazione avvenuta durante il riepilogatore NON persiste nulla), e `lastConversationId`
     * appartenente a quella sessione. Una seconda scrittura AGGIORNA la stessa riga (nessun duplicato:
     * `code_session_id` è UNIQUE), conservando `created_at` e aggiornando `updated_at`.
     *
     * CUTOFF MONOTÒNO: l'aggiornamento avviene solo se `lastConversationId` è >= a quello già
     * persistito (`>=` così lo stesso cutoff può comunque aggiornare payload/updated_at). Un riepilogo
     * più VECCHIO (cutoff inferiore) è un NO-OP: payload, cutoff e updated_at della memoria più recente
     * restano invariati e NON è un errore. Uno scope realmente errato (workspace revocato/errato,
     * sessione inesistente/archiviata, conversazione di un'altra sessione) resta un errore controllato.
     */
    public function save(
        CodeWorkingMemory $memory,
        int $workspaceId,
        int $codeSessionId,
        int $lastConversationId,
    ): void {
        $now = date('c');
        $affected = $this->db->execute(
            'INSERT INTO code_working_memories
                (workspace_id, code_session_id, last_conversation_id, payload_json, created_at, updated_at)
             SELECT ?, ?, ?, ?, ?, ?
             WHERE EXISTS (SELECT 1 FROM code_workspaces w WHERE w.id = ? AND w.status = \'active\')
               AND EXISTS (SELECT 1 FROM code_sessions s WHERE s.id = ? AND s.workspace_id = ? AND s.status = \'active\')
               AND EXISTS (SELECT 1 FROM code_conversations c WHERE c.id = ? AND c.code_session_id = ?)
             ON CONFLICT (code_session_id) DO UPDATE SET
                last_conversation_id = excluded.last_conversation_id,
                payload_json = excluded.payload_json,
                updated_at = excluded.updated_at
             WHERE excluded.last_conversation_id >= code_working_memories.last_conversation_id',
            [
                $workspaceId, $codeSessionId, $lastConversationId, $memory->toJson(), $now, $now,
                $workspaceId,
                $codeSessionId, $workspaceId,
                $lastConversationId, $codeSessionId,
            ]
        );
        if ($affected >= 1) {
            return;
        }

        // affected == 0 ha DUE cause indistinguibili dal solo conteggio: (a) NO-OP legittimo perché il
        // cutoff regredisce su una riga già presente e con scope PIENAMENTE valido; (b) scope errato
        // (workspace revocato, sessione archiviata/di un'altra root, conversazione estranea): la SELECT
        // dell'upsert non ha prodotto alcuna riga candidata. Una SOLA lettura scoped classifica: il
        // no-op stale è accettato solo se, ASSIEME, la memoria è di questo workspace/sessione, il
        // workspace è `active`, la sessione è `active` e appartiene al workspace, la conversazione
        // proposta appartiene alla sessione, e il cutoff persistito è STRETTAMENTE maggiore di quello
        // proposto (l'uguale passa già dall'upsert). Altrimenti è scope errato → eccezione. La scrittura
        // è già avvenuta in modo atomico: questa lettura decide solo se segnalare, non modifica nulla.
        $staleOk = $this->db->fetch(
            'SELECT 1 FROM code_working_memories m
                JOIN code_workspaces w ON w.id = m.workspace_id
                JOIN code_sessions s ON s.id = m.code_session_id
                JOIN code_conversations c ON c.code_session_id = m.code_session_id
             WHERE m.workspace_id = ? AND m.code_session_id = ?
               AND w.status = \'active\'
               AND s.workspace_id = ? AND s.status = \'active\'
               AND c.id = ?
               AND m.last_conversation_id > ?',
            [$workspaceId, $codeSessionId, $workspaceId, $lastConversationId, $lastConversationId]
        );
        if ($staleOk !== null) {
            return; // stale legittimo con scope pienamente attivo: no-op, memoria più recente invariata
        }

        throw new CodeWorkspaceException(
            'Scope non valido per la memoria di lavoro Code (workspace revocato/errato, sessione inesistente o archiviata, o conversazione di un\'altra sessione).'
        );
    }

    /**
     * Legge la memoria corrente della sessione, SCOPED al workspace (nessun accesso cross-workspace:
     * un workspace diverso restituisce null). Nello STESSO accesso restituisce anche il cutoff
     * (`last_conversation_id`), così lo Step 3 conosce il punto di ripresa senza interrogare la
     * tabella. Ricostruisce il value object con {@see CodeWorkingMemory::fromJson()} e FALLISCE
     * CHIUSA se il payload persistito è incompatibile (versione mancante/diversa o forma non valida).
     *
     * @return array{memory: CodeWorkingMemory, last_conversation_id: int}|null
     */
    public function findForSession(int $workspaceId, int $codeSessionId): ?array
    {
        $row = $this->db->fetch(
            'SELECT payload_json, last_conversation_id FROM code_working_memories WHERE workspace_id = ? AND code_session_id = ?',
            [$workspaceId, $codeSessionId]
        );
        if ($row === null) {
            return null;
        }

        try {
            $memory = CodeWorkingMemory::fromJson((string) $row['payload_json']);
        } catch (\InvalidArgumentException $e) {
            throw new CodeWorkspaceException('Memoria di lavoro Code persistita incompatibile: lettura negata.');
        }

        return ['memory' => $memory, 'last_conversation_id' => (int) $row['last_conversation_id']];
    }

    /**
     * Memoria più RECENTE del workspace ESCLUSA la sessione corrente (Step 4: ripresa del lavoro).
     * Ordinamento DETERMINISTICO `updated_at DESC, id DESC`. SCOPED al solo `workspace_id`: nessuna
     * memoria attraversa workspace diversi. Ricostruisce il value object e FALLISCE CHIUSA se il
     * payload persistito è incompatibile. Il chiamante decide se ereditarla (es. non se
     * `state = completed`); questo metodo non filtra lo stato.
     *
     * @return array{memory: CodeWorkingMemory, last_conversation_id: int}|null
     */
    public function latestForWorkspaceExcludingSession(int $workspaceId, int $excludeSessionId): ?array
    {
        $row = $this->db->fetch(
            'SELECT payload_json, last_conversation_id FROM code_working_memories
             WHERE workspace_id = ? AND code_session_id <> ?
             ORDER BY updated_at DESC, id DESC LIMIT 1',
            [$workspaceId, $excludeSessionId]
        );
        if ($row === null) {
            return null;
        }

        try {
            $memory = CodeWorkingMemory::fromJson((string) $row['payload_json']);
        } catch (\InvalidArgumentException $e) {
            throw new CodeWorkspaceException('Memoria di lavoro Code ereditata incompatibile: lettura negata.');
        }

        return ['memory' => $memory, 'last_conversation_id' => (int) $row['last_conversation_id']];
    }
}
