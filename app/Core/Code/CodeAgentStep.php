<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 3 / F3.3. Esito PURO di UN passo del ciclo: quel che lo strumento ha prodotto.
 *
 * Porta separati i due destinatari, che non vanno mai confusi:
 *   - `observation` : il testo che torna AL MODELLO. È un DATO, già neutralizzato e tagliato.
 *   - audit/evidenze: `auditAction` + `outcome` (+ `relPath`) e i contributi a hits/read/limiti/
 *                     metriche, tutti nei vocabolari CHIUSI già esistenti (nessuna migrazione).
 *
 * `auditAction` e `outcome` sono ristretti ai valori ammessi dal CHECK di `code_operation_logs`:
 * la Fase 3 non introduce nuove azioni di audit, mappa le proprie su quelle esistenti
 * (ricerca/inventario → `retrieval`, lettura di un file → `read`).
 */
final class CodeAgentStep
{
    /** Coerente col CHECK di code_operation_logs: nessuna azione nuova. */
    public const AUDIT_ACTIONS = ['retrieval', 'read'];

    /** Coerente col CHECK di code_operation_logs. */
    public const OUTCOMES = ['ok', 'denied', 'limited', 'error'];

    /**
     * @param list<array{path: string, line: int, excerpt: string}> $hits
     * @param array{path: string, content: string, bytes: int, truncated: bool}|null $readFile
     * @param list<string> $limits codici di RetrievalResult::LIMIT_CODES
     * @param array<string, int> $metrics chiavi di RetrievalResult::METRIC_KEYS
     */
    public function __construct(
        public readonly string $action,
        public readonly string $auditAction,
        public readonly string $outcome,
        public readonly ?string $relPath,
        public readonly string $observation,
        public readonly array $hits = [],
        public readonly ?array $readFile = null,
        public readonly array $limits = [],
        public readonly array $metrics = [],
    ) {
        if (!in_array($auditAction, self::AUDIT_ACTIONS, true)) {
            throw new \InvalidArgumentException('CodeAgentStep: azione di audit non ammessa.');
        }
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new \InvalidArgumentException('CodeAgentStep: esito non ammesso.');
        }
        if ($relPath !== null) {
            RelativePath::assert($relPath);
        }
        foreach ($limits as $limit) {
            if (!in_array($limit, RetrievalResult::LIMIT_CODES, true)) {
                throw new \InvalidArgumentException('CodeAgentStep: codice di limite non ammesso.');
            }
        }
        foreach ($metrics as $key => $value) {
            if (!in_array($key, RetrievalResult::METRIC_KEYS, true) || !is_int($value) || $value < 0) {
                throw new \InvalidArgumentException('CodeAgentStep: metrica non ammessa.');
            }
        }
    }
}
