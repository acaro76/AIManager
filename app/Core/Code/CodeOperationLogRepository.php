<?php

declare(strict_types=1);

namespace App\Core\Code;

use App\Core\Database;

/**
 * Code — Fase 1 / F1.6. Audit delle operazioni Code (tabella `code_operation_logs`).
 *
 * Dedicato ESCLUSIVAMENTE a Code: nessuna tabella o servizio LLM. Registra solo METADATI —
 * workspace, sessione (scoped), azione, esito, percorso RELATIVO, limiti morsi e metriche —
 * e MAI contenuti: niente contenuto dei file, niente prompt dell'utente, niente risposta del
 * provider, niente messaggi di errore tecnici.
 *
 * Questa non è una promessa dei soli PHPDoc: ogni campo testuale ha un VOCABOLARIO CHIUSO
 * validato a RUNTIME — azione, esito, codici dei limiti e chiavi delle metriche (valori interi
 * non negativi) — e il percorso passa da RelativePath. Non esiste quindi un parametro in cui
 * infilare testo libero: un prompt, un contenuto o un messaggio tecnico viene rifiutato e
 * NESSUNA riga viene scritta.
 *
 * Coerenza workspace↔sessione: garantita dalla FK COMPOSITA
 * `(code_session_id, workspace_id) → code_sessions(id, workspace_id)`; qui l'incoerenza diventa
 * un errore CONTROLLATO invece di una PDOException, verificando lo scope nella stessa INSERT.
 * Le operazioni PRE-SESSIONE sono ammesse con `code_session_id = NULL`.
 */
final class CodeOperationLogRepository
{
    /** Vocabolario chiuso delle azioni (coerente col CHECK dello schema). */
    private const ACTIONS = ['chat', 'retrieval', 'read'];

    /** Coerente col CHECK dello schema. */
    private const OUTCOMES = ['ok', 'denied', 'limited', 'cancelled', 'error'];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Registra un'operazione. `$codeSessionId` null = operazione pre-sessione.
     *
     * @param list<string> $limits limiti morsi (es. 'scan', 'read:files', 'revoked')
     * @param array<string, int> $metrics contatori del retrieval (nessun contenuto)
     */
    public function record(
        int $workspaceId,
        ?int $codeSessionId,
        string $action,
        string $outcome,
        ?string $relPath = null,
        array $limits = [],
        array $metrics = [],
    ): void {
        // Vocabolari CHIUSI, validati a runtime: nessun campo accetta testo libero. I messaggi
        // non riportano il valore ricevuto (potrebbe essere un prompt o un contenuto).
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Azione di audit non ammessa.');
        }
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new \InvalidArgumentException('Esito di audit non ammesso.');
        }
        // Nei log finiscono SOLO percorsi relativi e canonici (stessa regola del retrieval).
        if ($relPath !== null) {
            RelativePath::assert($relPath);
        }
        $this->assertLimits($limits);
        $this->assertMetrics($metrics);

        $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        $limitsJson = json_encode(array_values($limits), $flags);
        $metricsJson = json_encode((object) $metrics, $flags);
        $limitsJson = is_string($limitsJson) ? $limitsJson : '[]';
        $metricsJson = is_string($metricsJson) ? $metricsJson : '{}';
        $now = date('c');

        if ($codeSessionId === null) {
            // Operazione pre-sessione: nessuno scope di sessione da verificare (la FK verso
            // code_sessions non scatta con la colonna NULL); resta la FK sul workspace.
            $this->db->execute(
                'INSERT INTO code_operation_logs (workspace_id, code_session_id, action, outcome, rel_path, limits_json, metrics_json, created_at)
                 VALUES (?, NULL, ?, ?, ?, ?, ?, ?)',
                [$workspaceId, $action, $outcome, $relPath, $limitsJson, $metricsJson, $now]
            );
            return;
        }

        // Scope verificato NELLA STESSA istruzione: la sessione deve appartenere a quel
        // workspace, altrimenti nessuna riga viene inserita ed è un errore controllato.
        $affected = $this->db->execute(
            'INSERT INTO code_operation_logs (workspace_id, code_session_id, action, outcome, rel_path, limits_json, metrics_json, created_at)
             SELECT ?, ?, ?, ?, ?, ?, ?, ?
             WHERE EXISTS (SELECT 1 FROM code_sessions WHERE id = ? AND workspace_id = ?)',
            [$workspaceId, $codeSessionId, $action, $outcome, $relPath, $limitsJson, $metricsJson, $now, $codeSessionId, $workspaceId]
        );
        if ($affected < 1) {
            throw new CodeWorkspaceException('Sessione Code inesistente in questo workspace: audit non registrato.');
        }
    }

    /**
     * I limiti devono essere una LISTA (niente array associativi) di codici presi dal
     * vocabolario chiuso del retrieval: niente stringhe arbitrarie, niente valori annidati.
     *
     * @param array<mixed> $limits
     */
    private function assertLimits(array $limits): void
    {
        if ($limits !== [] && array_keys($limits) !== range(0, count($limits) - 1)) {
            throw new \InvalidArgumentException('I limiti di audit devono essere una lista, non una mappa.');
        }
        foreach ($limits as $limit) {
            if (!is_string($limit) || !in_array($limit, RetrievalResult::LIMIT_CODES, true)) {
                throw new \InvalidArgumentException('Codice di limite non ammesso nell\'audit.');
            }
        }
    }

    /**
     * Le metriche devono avere SOLO chiavi note del RetrievalResult e valori interi non
     * negativi: un valore annidato o non intero viene rifiutato.
     *
     * @param array<mixed> $metrics
     */
    private function assertMetrics(array $metrics): void
    {
        foreach ($metrics as $key => $value) {
            if (!is_string($key) || !in_array($key, RetrievalResult::METRIC_KEYS, true)) {
                throw new \InvalidArgumentException('Chiave di metrica non ammessa nell\'audit.');
            }
            if (!is_int($value) || $value < 0) {
                throw new \InvalidArgumentException('Valore di metrica non ammesso nell\'audit.');
            }
        }
    }

    /**
     * Log di un workspace, più recenti prima (ordine deterministico).
     *
     * @return list<array<string, mixed>>
     */
    public function listForWorkspace(int $workspaceId, int $limit = 100): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Il limite dell\'audit deve essere positivo.');
        }

        return $this->db->fetchAll(
            'SELECT * FROM code_operation_logs WHERE workspace_id = ? ORDER BY id DESC LIMIT ?',
            [$workspaceId, $limit]
        );
    }
}
