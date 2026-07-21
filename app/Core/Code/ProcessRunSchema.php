<?php

declare(strict_types=1);

namespace App\Core\Code;

use App\Core\Database;

/**
 * Code — Fase 7 / F7.8. Schema di `code_processes`: il lifecycle PERSISTENTE dei processi locali
 * (server PHP), ISOLATO dagli LLM e ADDITIVO (la 038 non tocca chat/patch/verifiche/comandi).
 *
 * SOLO METADATI: scope, turno assistant, `process_id` monouso, `digest`, `policy_version`, profilo,
 * programma, un `display_summary` SANIFICATO (mai path assoluti), host FISSO (CHECK), porta,
 * directory RELATIVA, lo stato del lifecycle, `pid`/`pgid`, il fingerprint d'esecuzione
 * (`run_token` + `start_signature`) necessario allo Stop sicuro, un `log_id` OPACO (mai un path
 * assoluto), exit code quando disponibile, e i timestamp. Il contenuto dei log NON è nel DB.
 */
final class ProcessRunSchema
{
    public const TABLE = 'code_processes';

    public const STATE_MISSING = 'missing';
    public const STATE_INCOMPATIBLE = 'incompatible';
    public const STATE_READY = 'ready';

    /** @var list<string> Vocabolario CHIUSO degli stati del lifecycle (coerente col CHECK). */
    public const STATES = [
        'pending', 'starting', 'running', 'stopped',
        'rejected', 'expired', 'denied', 'failed', 'orphaned', 'error',
    ];

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
            CREATE TABLE code_processes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                workspace_id INTEGER NOT NULL,
                code_session_id INTEGER NOT NULL,
                assistant_conversation_id INTEGER,
                process_id TEXT NOT NULL UNIQUE,
                execution_id TEXT,
                digest TEXT NOT NULL,
                policy_version INTEGER NOT NULL,
                profile_id TEXT NOT NULL,
                program TEXT NOT NULL,
                display_summary TEXT NOT NULL,
                host TEXT NOT NULL,
                port INTEGER NOT NULL,
                directory TEXT NOT NULL,
                state TEXT NOT NULL,
                pid INTEGER,
                pgid INTEGER,
                run_token TEXT,
                start_signature TEXT,
                log_id TEXT,
                exit_code INTEGER,
                created_at TEXT NOT NULL,
                confirmed_at TEXT,
                started_at TEXT,
                finished_at TEXT,
                CHECK (state IN ('pending', 'starting', 'running', 'stopped', 'rejected', 'expired', 'denied', 'failed', 'orphaned', 'error')),
                CHECK (host = '127.0.0.1'),
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
            'CREATE INDEX idx_code_processes_scope ON code_processes (workspace_id, code_session_id)',
            'CREATE INDEX idx_code_processes_assistant ON code_processes (assistant_conversation_id)',
            'CREATE INDEX idx_code_processes_state ON code_processes (state)',
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

    /** @return list<string> */
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
            'process_id' => ['TEXT', 1, 0, null],
            'execution_id' => ['TEXT', 0, 0, null],
            'digest' => ['TEXT', 1, 0, null],
            'policy_version' => ['INTEGER', 1, 0, null],
            'profile_id' => ['TEXT', 1, 0, null],
            'program' => ['TEXT', 1, 0, null],
            'display_summary' => ['TEXT', 1, 0, null],
            'host' => ['TEXT', 1, 0, null],
            'port' => ['INTEGER', 1, 0, null],
            'directory' => ['TEXT', 1, 0, null],
            'state' => ['TEXT', 1, 0, null],
            'pid' => ['INTEGER', 0, 0, null],
            'pgid' => ['INTEGER', 0, 0, null],
            'run_token' => ['TEXT', 0, 0, null],
            'start_signature' => ['TEXT', 0, 0, null],
            'log_id' => ['TEXT', 0, 0, null],
            'exit_code' => ['INTEGER', 0, 0, null],
            'created_at' => ['TEXT', 1, 0, null],
            'confirmed_at' => ['TEXT', 0, 0, null],
            'started_at' => ['TEXT', 0, 0, null],
            'finished_at' => ['TEXT', 0, 0, null],
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
            "CHECK (state IN ('pending', 'starting', 'running', 'stopped', 'rejected', 'expired', 'denied', 'failed', 'orphaned', 'error'))",
            "CHECK (host = '127.0.0.1')",
            'process_id TEXT NOT NULL UNIQUE',
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
            'idx_code_processes_scope' => ['workspace_id', 'code_session_id'],
            'idx_code_processes_assistant' => ['assistant_conversation_id'],
            'idx_code_processes_state' => ['state'],
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
