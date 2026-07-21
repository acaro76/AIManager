<?php

declare(strict_types=1);

namespace App\Core\Code;

use App\Core\Database;

/**
 * Code — Fase 1 / F1.3. Fonte di verità dello SCHEMA della chat Code, ISOLATO dagli LLM.
 *
 * Quattro tabelle dedicate, senza `project_id` e senza FK verso `projects`/`sessions`/
 * `conversations`/`execution_states`:
 *  - `code_sessions`       → sessione di chat legata a un workspace (cartella);
 *  - `code_conversations`  → turni user/assistant di una sessione;
 *  - `code_operation_logs` → audit (repository nel Task 6, schema qui);
 *  - `code_response_evidence` → soli metadati delle evidenze associate a una risposta.
 *
 * Coerenza workspace↔sessione garantita a livello di SCHEMA: `code_sessions` ha
 * `UNIQUE(id, workspace_id)` e `code_operation_logs` ha una FK COMPOSITA
 * `(code_session_id, workspace_id) → code_sessions(id, workspace_id)`, così un log non può
 * associare una sessione di un altro workspace (con `code_session_id` NULL ammesso per le
 * operazioni pre-sessione).
 *
 * DDL atteso e verifica STRUTTURALE sono esposti SEPARATAMENTE: la futura 032 usa `applyDdl()`
 * (nessuna transazione: gira dentro quella già atomica del MigrationRunner) e `verify()` per
 * fallire chiusa su schemi omonimi incompatibili. `createForTests()` è l'UNICO metodo che apre
 * una transazione ed è riservato ai test standalone. Qui non si tocca il DB reale.
 */
final class CodeChatSchema
{
    /** Nessuna tabella chat presente: la chat Code non è ancora attivata (stato non interattivo). */
    public const STATE_MISSING = 'missing';

    /** Tabelle presenti solo in parte o strutturalmente divergenti: fail closed, nessuna correzione. */
    public const STATE_INCOMPATIBLE = 'incompatible';

    /** Schema completo e conforme: la chat può essere usata. */
    public const STATE_READY = 'ready';

    /**
     * Stato dello schema chat, da consultare PRIMA di qualunque query alle tabelle dedicate:
     * finché la migrazione 032 non è applicata, le tabelle non esistono e /code deve funzionare
     * senza mai interrogarle.
     */
    public static function state(Database $db): string
    {
        $tables = self::tables();
        $present = 0;
        foreach ($tables as $table) {
            if (self::tableExists($db, $table)) {
                $present++;
            }
        }

        if ($present === 0) {
            return self::STATE_MISSING;
        }
        // Presenza parziale = schema incoerente: non si tenta nulla.
        if ($present < count($tables)) {
            return self::STATE_INCOMPATIBLE;
        }

        return self::verify($db) === [] ? self::STATE_READY : self::STATE_INCOMPATIBLE;
    }

    /**
     * DDL canonico per tabella. Niente IF NOT EXISTS: creare su una tabella già presente deve
     * fallire, non essere ignorato.
     *
     * @return array<string, string>
     */
    public static function tableDdl(): array
    {
        return [
            'code_sessions' => <<<'SQL'
                CREATE TABLE code_sessions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    workspace_id INTEGER NOT NULL,
                    title TEXT NOT NULL DEFAULT '',
                    status TEXT NOT NULL DEFAULT 'active',
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL,
                    UNIQUE (id, workspace_id),
                    CHECK (status IN ('active', 'archived')),
                    FOREIGN KEY (workspace_id) REFERENCES code_workspaces(id)
                )
                SQL,
            'code_conversations' => <<<'SQL'
                CREATE TABLE code_conversations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    code_session_id INTEGER NOT NULL,
                    role TEXT NOT NULL,
                    content TEXT NOT NULL,
                    provider TEXT NOT NULL DEFAULT '',
                    created_at TEXT NOT NULL,
                    CHECK (role IN ('user', 'assistant')),
                    FOREIGN KEY (code_session_id) REFERENCES code_sessions(id)
                )
                SQL,
            'code_operation_logs' => <<<'SQL'
                CREATE TABLE code_operation_logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    workspace_id INTEGER NOT NULL,
                    code_session_id INTEGER,
                    action TEXT NOT NULL,
                    outcome TEXT NOT NULL,
                    rel_path TEXT,
                    limits_json TEXT NOT NULL DEFAULT '[]',
                    metrics_json TEXT NOT NULL DEFAULT '{}',
                    created_at TEXT NOT NULL,
                    CHECK (action IN ('chat', 'retrieval', 'read')),
                    CHECK (outcome IN ('ok', 'denied', 'limited', 'cancelled', 'error')),
                    FOREIGN KEY (workspace_id) REFERENCES code_workspaces(id),
                    FOREIGN KEY (code_session_id, workspace_id) REFERENCES code_sessions(id, workspace_id)
                )
                SQL,
            'code_response_evidence' => <<<'SQL'
                CREATE TABLE code_response_evidence (
                    assistant_conversation_id INTEGER PRIMARY KEY,
                    workspace_id INTEGER NOT NULL,
                    code_session_id INTEGER NOT NULL,
                    files_json TEXT NOT NULL DEFAULT '{"read":[],"found":[]}',
                    limits_json TEXT NOT NULL DEFAULT '[]',
                    metrics_json TEXT NOT NULL DEFAULT '{}',
                    created_at TEXT NOT NULL,
                    FOREIGN KEY (assistant_conversation_id) REFERENCES code_conversations(id),
                    FOREIGN KEY (workspace_id) REFERENCES code_workspaces(id),
                    FOREIGN KEY (code_session_id, workspace_id) REFERENCES code_sessions(id, workspace_id)
                )
                SQL,
        ];
    }

    /**
     * Indici su FK e campi di ordinamento/lookup.
     *
     * @return list<string>
     */
    public static function indexDdl(): array
    {
        return [
            'CREATE INDEX idx_code_sessions_workspace ON code_sessions (workspace_id, updated_at)',
            'CREATE INDEX idx_code_conversations_session ON code_conversations (code_session_id, id)',
            'CREATE INDEX idx_code_operation_logs_workspace ON code_operation_logs (workspace_id, created_at)',
            'CREATE INDEX idx_code_operation_logs_session ON code_operation_logs (code_session_id)',
            'CREATE INDEX idx_code_response_evidence_session ON code_response_evidence (code_session_id, assistant_conversation_id)',
        ];
    }

    /** @return list<string> i nomi delle tabelle dello schema chat Code */
    public static function tables(): array
    {
        return array_keys(self::tableDdl());
    }

    /**
     * Applica il DDL (tabelle + indici) SENZA aprire transazioni. È il metodo che la futura
     * migrazione 032 deve usare, perché il MigrationRunner esegue già dentro una transazione
     * atomica (SQLite/PDO rifiuta le transazioni annidate).
     */
    public static function applyDdl(Database $db): void
    {
        foreach (self::tableDdl() as $ddl) {
            $db->execute($ddl);
        }
        foreach (self::indexDdl() as $ddl) {
            $db->execute($ddl);
        }
    }

    /**
     * SOLO PER I TEST standalone: avvolge applyDdl() in una transazione. NON usare dalla 032
     * (che è già transazionale): là va usato applyDdl().
     */
    public static function createForTests(Database $db): void
    {
        $db->transaction(static fn () => self::applyDdl($db));
    }

    public static function tableExists(Database $db, string $table): bool
    {
        return $db->fetch("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]) !== null;
    }

    /**
     * Verifica STRUTTURALE completa e riutilizzabile (la 032 la userà per fallire chiusa su
     * schemi incompatibili). Controlla, per ogni tabella attesa:
     *  - insieme ESATTO delle colonne (extra incluse) + tipo, NOT NULL, PK e default;
     *  - le foreign key (PRAGMA foreign_key_list), incluse quelle composite;
     *  - i CHECK e i vincoli UNIQUE (SQL in sqlite_master, whitespace normalizzato);
     *  - gli indici attesi (PRAGMA index_list / index_info).
     * Restituisce l'elenco dei problemi; vuoto = compatibile. Non modifica nulla.
     *
     * @return list<string>
     */
    public static function verify(Database $db): array
    {
        $problems = [];
        foreach (self::spec() as $table => $def) {
            if (!self::tableExists($db, $table)) {
                $problems[] = "tabella assente: {$table}";
                continue;
            }

            self::verifyColumns($db, $table, $def['columns'], $problems);
            self::verifyForeignKeys($db, $table, $def['foreignKeys'], $problems);
            self::verifySql($db, $table, $def['checks'], $def['sqlContains'], $problems);
            self::verifyIndexes($db, $table, $def['indexes'], $problems);
        }
        return $problems;
    }

    /**
     * @param array<string, array{0: string, 1: int, 2: int, 3: ?string}> $expected
     * @param list<string> $problems
     */
    private static function verifyColumns(Database $db, string $table, array $expected, array &$problems): void
    {
        $actual = [];
        foreach ($db->fetchAll('PRAGMA table_info(' . $table . ')') as $c) {
            $actual[(string) $c['name']] = $c;
        }

        foreach (array_diff(array_keys($actual), array_keys($expected)) as $extra) {
            $problems[] = "{$table}: colonna extra {$extra}";
        }

        foreach ($expected as $name => [$type, $notnull, $pk, $dflt]) {
            if (!isset($actual[$name])) {
                $problems[] = "{$table}: colonna assente {$name}";
                continue;
            }
            $a = $actual[$name];
            if (strtoupper((string) $a['type']) !== strtoupper($type)) {
                $problems[] = "{$table}: colonna {$name} tipo atteso {$type} trovato " . (string) $a['type'];
            }
            if ((int) $a['notnull'] !== $notnull) {
                $problems[] = "{$table}: colonna {$name} notnull atteso {$notnull} trovato " . (int) $a['notnull'];
            }
            if ((int) $a['pk'] !== $pk) {
                $problems[] = "{$table}: colonna {$name} pk atteso {$pk} trovato " . (int) $a['pk'];
            }
            $adflt = $a['dflt_value'] === null ? null : (string) $a['dflt_value'];
            if ($adflt !== $dflt) {
                $problems[] = "{$table}: colonna {$name} default atteso " . var_export($dflt, true) . ' trovato ' . var_export($adflt, true);
            }
        }
    }

    /**
     * @param list<array{0: string, 1: list<array{0: string, 1: string}>}> $expected
     * @param list<string> $problems
     */
    private static function verifyForeignKeys(Database $db, string $table, array $expected, array &$problems): void
    {
        // Righe raggruppate per id (una FK composita ha più righe con lo stesso id).
        $byId = [];
        foreach ($db->fetchAll('PRAGMA foreign_key_list(' . $table . ')') as $r) {
            $byId[(int) $r['id']][] = $r;
        }
        $actual = [];
        foreach ($byId as $rows) {
            $pairs = [];
            foreach ($rows as $r) {
                $pairs[] = (string) $r['from'] . '>' . (string) $r['to'];
            }
            sort($pairs);
            $actual[] = (string) $rows[0]['table'] . ':' . implode(',', $pairs);
        }

        $want = [];
        foreach ($expected as [$refTable, $pairs]) {
            $p = array_map(static fn (array $x): string => $x[0] . '>' . $x[1], $pairs);
            sort($p);
            $want[] = $refTable . ':' . implode(',', $p);
        }

        sort($actual);
        sort($want);
        foreach (array_diff($want, $actual) as $m) {
            $problems[] = "{$table}: FK attesa mancante: {$m}";
        }
        foreach (array_diff($actual, $want) as $x) {
            $problems[] = "{$table}: FK non attesa: {$x}";
        }
    }

    /**
     * @param list<string> $checks
     * @param list<string> $sqlContains
     * @param list<string> $problems
     */
    private static function verifySql(Database $db, string $table, array $checks, array $sqlContains, array &$problems): void
    {
        $row = $db->fetch("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
        $norm = preg_replace('/\s+/', ' ', (string) ($row['sql'] ?? '')) ?? '';
        foreach ($checks as $frag) {
            if (!str_contains($norm, $frag)) {
                $problems[] = "{$table}: CHECK atteso mancante: {$frag}";
            }
        }
        foreach ($sqlContains as $frag) {
            if (!str_contains($norm, $frag)) {
                $problems[] = "{$table}: vincolo atteso mancante: {$frag}";
            }
        }
    }

    /**
     * @param array<string, list<string>> $expected nome indice → colonne ordinate
     * @param list<string> $problems
     */
    private static function verifyIndexes(Database $db, string $table, array $expected, array &$problems): void
    {
        $present = [];
        foreach ($db->fetchAll('PRAGMA index_list(' . $table . ')') as $il) {
            $present[(string) $il['name']] = true;
        }
        foreach ($expected as $idx => $cols) {
            if (!isset($present[$idx])) {
                $problems[] = "{$table}: indice assente {$idx}";
                continue;
            }
            $actualCols = array_map(
                static fn (array $r): string => (string) $r['name'],
                $db->fetchAll('PRAGMA index_info(' . $idx . ')')
            );
            if ($actualCols !== $cols) {
                $problems[] = "{$table}: indice {$idx} colonne attese [" . implode(',', $cols) . '] trovate [' . implode(',', $actualCols) . ']';
            }
        }
    }

    /**
     * Specifica attesa completa dello schema (base della verifica). Colonna → [tipo, notnull,
     * pk, default]; i default sono le stringhe come restituite da PRAGMA (virgolette incluse).
     *
     * @return array<string, array{
     *   columns: array<string, array{0: string, 1: int, 2: int, 3: ?string}>,
     *   foreignKeys: list<array{0: string, 1: list<array{0: string, 1: string}>}>,
     *   checks: list<string>,
     *   sqlContains: list<string>,
     *   indexes: array<string, list<string>>
     * }>
     */
    private static function spec(): array
    {
        return [
            'code_sessions' => [
                'columns' => [
                    'id' => ['INTEGER', 0, 1, null],
                    'workspace_id' => ['INTEGER', 1, 0, null],
                    'title' => ['TEXT', 1, 0, "''"],
                    'status' => ['TEXT', 1, 0, "'active'"],
                    'created_at' => ['TEXT', 1, 0, null],
                    'updated_at' => ['TEXT', 1, 0, null],
                ],
                'foreignKeys' => [
                    ['code_workspaces', [['workspace_id', 'id']]],
                ],
                'checks' => ["CHECK (status IN ('active', 'archived'))"],
                'sqlContains' => ['UNIQUE (id, workspace_id)'],
                'indexes' => ['idx_code_sessions_workspace' => ['workspace_id', 'updated_at']],
            ],
            'code_conversations' => [
                'columns' => [
                    'id' => ['INTEGER', 0, 1, null],
                    'code_session_id' => ['INTEGER', 1, 0, null],
                    'role' => ['TEXT', 1, 0, null],
                    'content' => ['TEXT', 1, 0, null],
                    'provider' => ['TEXT', 1, 0, "''"],
                    'created_at' => ['TEXT', 1, 0, null],
                ],
                'foreignKeys' => [
                    ['code_sessions', [['code_session_id', 'id']]],
                ],
                'checks' => ["CHECK (role IN ('user', 'assistant'))"],
                'sqlContains' => [],
                'indexes' => ['idx_code_conversations_session' => ['code_session_id', 'id']],
            ],
            'code_operation_logs' => [
                'columns' => [
                    'id' => ['INTEGER', 0, 1, null],
                    'workspace_id' => ['INTEGER', 1, 0, null],
                    'code_session_id' => ['INTEGER', 0, 0, null],
                    'action' => ['TEXT', 1, 0, null],
                    'outcome' => ['TEXT', 1, 0, null],
                    'rel_path' => ['TEXT', 0, 0, null],
                    'limits_json' => ['TEXT', 1, 0, "'[]'"],
                    'metrics_json' => ['TEXT', 1, 0, "'{}'"],
                    'created_at' => ['TEXT', 1, 0, null],
                ],
                'foreignKeys' => [
                    ['code_workspaces', [['workspace_id', 'id']]],
                    ['code_sessions', [['code_session_id', 'id'], ['workspace_id', 'workspace_id']]],
                ],
                'checks' => [
                    "CHECK (action IN ('chat', 'retrieval', 'read'))",
                    "CHECK (outcome IN ('ok', 'denied', 'limited', 'cancelled', 'error'))",
                ],
                'sqlContains' => [],
                'indexes' => [
                    'idx_code_operation_logs_workspace' => ['workspace_id', 'created_at'],
                    'idx_code_operation_logs_session' => ['code_session_id'],
                ],
            ],
            'code_response_evidence' => [
                'columns' => [
                    'assistant_conversation_id' => ['INTEGER', 0, 1, null],
                    'workspace_id' => ['INTEGER', 1, 0, null],
                    'code_session_id' => ['INTEGER', 1, 0, null],
                    'files_json' => ['TEXT', 1, 0, "'{\"read\":[],\"found\":[]}'"],
                    'limits_json' => ['TEXT', 1, 0, "'[]'"],
                    'metrics_json' => ['TEXT', 1, 0, "'{}'"],
                    'created_at' => ['TEXT', 1, 0, null],
                ],
                'foreignKeys' => [
                    ['code_conversations', [['assistant_conversation_id', 'id']]],
                    ['code_workspaces', [['workspace_id', 'id']]],
                    ['code_sessions', [['code_session_id', 'id'], ['workspace_id', 'workspace_id']]],
                ],
                'checks' => [],
                'sqlContains' => [],
                'indexes' => [
                    'idx_code_response_evidence_session' => ['code_session_id', 'assistant_conversation_id'],
                ],
            ],
        ];
    }
}
