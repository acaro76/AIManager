<?php

declare(strict_types=1);

namespace App\Core\Code;

use App\Core\Database;

/**
 * Code — Fase 5 / F5.6. Schema della tabella `code_verification_runs`: il registro delle verifiche
 * del codice, ISOLATO dagli LLM e ADDITIVO rispetto allo schema chat/patch.
 *
 * SOLO METADATI (regola d'audit): scope workspace/sessione, turno assistant che l'ha originata,
 * profilo/linguaggio/tipo (vocabolari chiusi), file bersaglio relativo, esito, exit code, durata,
 * byte di output e troncamento. MAI l'output del comando, il prompt o messaggi tecnici: nello
 * schema non esiste una colonna dove infilarli.
 *
 * Volutamente SEPARATO da CodeChatSchema/CodePatchSchema: la 035 NON tocca le tabelle esistenti (la
 * loro impronta strutturale resta identica), aggiunge solo la sesta tabella. La semantica di
 * `code_operation_logs` non è alterata: le verifiche NON ci finiscono.
 */
final class CodeVerificationSchema
{
    public const TABLE = 'code_verification_runs';

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
            CREATE TABLE code_verification_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                workspace_id INTEGER NOT NULL,
                code_session_id INTEGER NOT NULL,
                assistant_conversation_id INTEGER,
                profile_id TEXT NOT NULL,
                language TEXT NOT NULL,
                kind TEXT NOT NULL,
                rel_path TEXT,
                outcome TEXT NOT NULL,
                exit_code INTEGER,
                duration_ms INTEGER NOT NULL DEFAULT 0,
                output_bytes INTEGER NOT NULL DEFAULT 0,
                truncated INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                CHECK (language IN ('php', 'javascript', 'python')),
                CHECK (kind IN ('lint', 'test', 'syntax')),
                CHECK (outcome IN ('passed', 'failed', 'timed_out', 'killed', 'error', 'denied', 'unavailable')),
                CHECK (truncated IN (0, 1)),
                FOREIGN KEY (workspace_id) REFERENCES code_workspaces(id),
                FOREIGN KEY (assistant_conversation_id) REFERENCES code_conversations(id),
                FOREIGN KEY (code_session_id, workspace_id) REFERENCES code_sessions(id, workspace_id)
            )
            SQL;
    }

    /** @return list<string> */
    public static function indexDdl(): array
    {
        return [
            'CREATE INDEX idx_code_verification_runs_scope ON code_verification_runs (workspace_id, code_session_id)',
            'CREATE INDEX idx_code_verification_runs_assistant ON code_verification_runs (assistant_conversation_id)',
        ];
    }

    /** Applica il DDL SENZA transazioni (la 035 gira già dentro quella atomica del runner). */
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

    /**
     * Verifica STRUTTURALE della sola tabella `code_verification_runs`: colonne, foreign key
     * (comprese le composite), CHECK e indici. Vuoto = compatibile.
     *
     * @return list<string>
     */
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
            'assistant_conversation_id' => ['INTEGER', 0, 0, null],
            'profile_id' => ['TEXT', 1, 0, null],
            'language' => ['TEXT', 1, 0, null],
            'kind' => ['TEXT', 1, 0, null],
            'rel_path' => ['TEXT', 0, 0, null],
            'outcome' => ['TEXT', 1, 0, null],
            'exit_code' => ['INTEGER', 0, 0, null],
            'duration_ms' => ['INTEGER', 1, 0, '0'],
            'output_bytes' => ['INTEGER', 1, 0, '0'],
            'truncated' => ['INTEGER', 1, 0, '0'],
            'created_at' => ['TEXT', 1, 0, null],
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
            'code_conversations:assistant_conversation_id>id',
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
        $fragments = [
            "CHECK (outcome IN ('passed', 'failed', 'timed_out', 'killed', 'error', 'denied', 'unavailable'))",
            "CHECK (language IN ('php', 'javascript', 'python'))",
            "CHECK (kind IN ('lint', 'test', 'syntax'))",
        ];
        foreach ($fragments as $frag) {
            if (!str_contains($norm, $frag)) {
                $problems[] = "{$table}: vincolo atteso mancante: {$frag}";
            }
        }
    }

    /** @param list<string> $problems */
    private static function verifyIndexes(Database $db, string $table, array &$problems): void
    {
        $expected = [
            'idx_code_verification_runs_scope' => ['workspace_id', 'code_session_id'],
            'idx_code_verification_runs_assistant' => ['assistant_conversation_id'],
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
