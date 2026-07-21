<?php

declare(strict_types=1);

namespace App\Core\Code;

use App\Core\Database;

/**
 * Code — Fase 9 / Step 2. Schema di `code_working_memories`: UNA SOLA memoria di lavoro CORRENTE per
 * sessione Code (nessuna versione accumulata), ISOLATA dagli LLM e ADDITIVA (la 040 non tocca le
 * tabelle chat/patch/verifiche/comandi/processi/git).
 *
 * SOLO METADATI CURATI: lo scope (workspace + sessione), il turno di conversazione a cui la memoria
 * è aggiornata (`last_conversation_id`), e il `payload_json` che è la serializzazione canonica di un
 * {@see CodeWorkingMemory} (contratto dello Step 1). Nessun contenuto di file, diff, output o log.
 *
 * Coerenza SCOPE garantita a livello di SCHEMA, come per le altre tabelle Code:
 *  - `code_session_id` UNIQUE ⇒ una e una sola riga per sessione (upsert, mai duplicati);
 *  - FK COMPOSITA `(code_session_id, workspace_id) → code_sessions(id, workspace_id)` ⇒ la memoria
 *    non può associare una sessione di un altro workspace;
 *  - FK `last_conversation_id → code_conversations(id)`.
 *
 * DDL e verifica STRUTTURALE sono esposti separatamente: la 040 usa `applyDdl()` (nessuna
 * transazione: gira dentro quella già atomica del MigrationRunner) e `verify()` per fallire chiusa
 * su schemi omonimi incompatibili. `createForTests()` è l'UNICO metodo che apre una transazione ed è
 * riservato ai test standalone. Qui non si tocca il DB reale.
 */
final class CodeWorkingMemorySchema
{
    public const TABLE = 'code_working_memories';

    public const STATE_MISSING = 'missing';
    public const STATE_INCOMPATIBLE = 'incompatible';
    public const STATE_READY = 'ready';

    public static function state(Database $db): string
    {
        if (!self::tableExists($db, self::TABLE)) {
            return self::STATE_MISSING;
        }

        return self::verify($db) === [] ? self::STATE_READY : self::STATE_INCOMPATIBLE;
    }

    public static function tableDdl(): string
    {
        return <<<'SQL'
            CREATE TABLE code_working_memories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                workspace_id INTEGER NOT NULL,
                code_session_id INTEGER NOT NULL UNIQUE,
                last_conversation_id INTEGER NOT NULL,
                payload_json TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (workspace_id) REFERENCES code_workspaces(id),
                FOREIGN KEY (last_conversation_id) REFERENCES code_conversations(id),
                FOREIGN KEY (code_session_id, workspace_id) REFERENCES code_sessions(id, workspace_id)
            )
            SQL;
    }

    /** @return list<string> */
    public static function indexDdl(): array
    {
        return [
            'CREATE INDEX idx_code_working_memories_workspace ON code_working_memories (workspace_id, updated_at)',
        ];
    }

    public static function applyDdl(Database $db): void
    {
        $db->execute(self::tableDdl());
        foreach (self::indexDdl() as $ddl) {
            $db->execute($ddl);
        }
    }

    /** SOLO PER I TEST standalone: avvolge applyDdl() in una transazione. */
    public static function createForTests(Database $db): void
    {
        $db->transaction(static fn () => self::applyDdl($db));
    }

    public static function tableExists(Database $db, string $table): bool
    {
        return $db->fetch("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]) !== null;
    }

    /** @return list<string> elenco dei problemi; vuoto = compatibile. Non modifica nulla. */
    public static function verify(Database $db): array
    {
        $problems = [];
        $table = self::TABLE;
        if (!self::tableExists($db, $table)) {
            return ["tabella assente: {$table}"];
        }

        self::verifyColumns($db, $table, $problems);
        self::verifyForeignKeys($db, $table, $problems);
        self::verifySql($db, $table, $problems);
        self::verifyIndexes($db, $table, $problems);

        return $problems;
    }

    /** @param list<string> $problems */
    private static function verifyColumns(Database $db, string $table, array &$problems): void
    {
        $expected = [
            'id' => ['INTEGER', 0, 1, null],
            'workspace_id' => ['INTEGER', 1, 0, null],
            'code_session_id' => ['INTEGER', 1, 0, null],
            'last_conversation_id' => ['INTEGER', 1, 0, null],
            'payload_json' => ['TEXT', 1, 0, null],
            'created_at' => ['TEXT', 1, 0, null],
            'updated_at' => ['TEXT', 1, 0, null],
        ];

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
                $problems[] = "{$table}: colonna {$name} notnull atteso {$notnull}";
            }
            if ((int) $a['pk'] !== $pk) {
                $problems[] = "{$table}: colonna {$name} pk atteso {$pk}";
            }
            $adflt = $a['dflt_value'] === null ? null : (string) $a['dflt_value'];
            if ($adflt !== $dflt) {
                $problems[] = "{$table}: colonna {$name} default atteso " . var_export($dflt, true) . ' trovato ' . var_export($adflt, true);
            }
        }
    }

    /** @param list<string> $problems */
    private static function verifyForeignKeys(Database $db, string $table, array &$problems): void
    {
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

        $want = [
            'code_workspaces:workspace_id>id',
            'code_conversations:last_conversation_id>id',
            'code_sessions:code_session_id>id,workspace_id>workspace_id',
        ];
        sort($actual);
        sort($want);
        foreach (array_diff($want, $actual) as $m) {
            $problems[] = "{$table}: FK attesa mancante: {$m}";
        }
        foreach (array_diff($actual, $want) as $x) {
            $problems[] = "{$table}: FK non attesa: {$x}";
        }
    }

    /** @param list<string> $problems */
    private static function verifySql(Database $db, string $table, array &$problems): void
    {
        $row = $db->fetch("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
        $norm = preg_replace('/\s+/', ' ', (string) ($row['sql'] ?? '')) ?? '';
        if (!str_contains($norm, 'code_session_id INTEGER NOT NULL UNIQUE')) {
            $problems[] = "{$table}: vincolo atteso mancante: code_session_id INTEGER NOT NULL UNIQUE";
        }
    }

    /** @param list<string> $problems */
    private static function verifyIndexes(Database $db, string $table, array &$problems): void
    {
        $expected = [
            'idx_code_working_memories_workspace' => ['workspace_id', 'updated_at'],
        ];
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
}
