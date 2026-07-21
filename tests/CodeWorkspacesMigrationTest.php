<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\MigrationRunner;

/**
 * F0.b — verifica la migrazione 031 (code_workspaces AUTONOMA) su SQLite TEMPORANEO.
 * Non tocca mai storage/database/aimanager.sqlite.
 *
 * Copre: catena completa 001→031 (schema finale corretto, niente project_id, niente FK,
 * root_path UNIQUE, CHECK status); + esecuzione DIRETTA della 031 sulla tabella legacy
 * (vuota → DROP+CREATE; con una riga → eccezione fail-closed e dati intatti).
 */

$makeChainDb = static function (): array {
    $path = sys_get_temp_dir() . '/aimanager_mig031_' . uniqid('', true) . '.sqlite';
    $db = new Database($path);
    $root = dirname(__DIR__);
    (new MigrationRunner($db, $root . '/database/migrations', $root . '/database/seeds'))->run();
    $cleanup = static function () use ($path): void {
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    };
    return [$db, $cleanup];
};

$makeBareDb = static function (): array {
    $path = sys_get_temp_dir() . '/aimanager_mig031_bare_' . uniqid('', true) . '.sqlite';
    $db = new Database($path);
    $cleanup = static function () use ($path): void {
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    };
    return [$db, $cleanup];
};

// Crea la tabella legacy (schema 030: con project_id). Senza FK a projects: qui si prova
// solo la logica count/drop/throw della 031, che non dipende dalla FK.
$createLegacy = static function (Database $db): void {
    $db->execute('CREATE TABLE code_workspaces (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        root_path TEXT NOT NULL,
        name TEXT NOT NULL DEFAULT \'\',
        status TEXT NOT NULL DEFAULT \'active\',
        authorized_at TEXT NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
};

$run031 = static function (Database $db): void {
    $migration = require dirname(__DIR__) . '/database/migrations/031_rebuild_code_workspaces_standalone.php';
    $migration($db);
};

$columns = static function (Database $db): array {
    return array_map(
        static fn (array $c): string => (string) $c['name'],
        $db->fetchAll('PRAGMA table_info(code_workspaces)')
    );
};

$throws = static function (callable $fn): bool {
    try {
        $fn();
        return false;
    } catch (\Throwable $e) {
        return true;
    }
};

test('031: catena 001→031 produce lo schema standalone (niente project_id, niente FK)', function () use ($makeChainDb, $columns) {
    [$db, $cleanup] = $makeChainDb();
    try {
        assertSame(
            ['id', 'root_path', 'name', 'status', 'authorized_at', 'created_at', 'updated_at'],
            $columns($db)
        );
        assertSame(false, in_array('project_id', $columns($db), true));
        // Nessuna foreign key.
        assertSame([], $db->fetchAll('PRAGMA foreign_key_list(code_workspaces)'));
    } finally {
        $cleanup();
    }
});

test('031: root_path e\' UNIQUE', function () use ($makeChainDb, $throws) {
    [$db, $cleanup] = $makeChainDb();
    try {
        $now = date('c');
        $db->execute('INSERT INTO code_workspaces (root_path, authorized_at, created_at, updated_at) VALUES (?, ?, ?, ?)', ['/tmp/a', $now, $now, $now]);
        $rejected = $throws(static function () use ($db, $now): void {
            $db->execute('INSERT INTO code_workspaces (root_path, authorized_at, created_at, updated_at) VALUES (?, ?, ?, ?)', ['/tmp/a', $now, $now, $now]);
        });
        assertSame(true, $rejected);
    } finally {
        $cleanup();
    }
});

test('031: CHECK(status) accetta active e revoked, rifiuta il resto', function () use ($makeChainDb, $throws) {
    [$db, $cleanup] = $makeChainDb();
    try {
        $now = date('c');
        $db->execute('INSERT INTO code_workspaces (root_path, status, authorized_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', ['/tmp/act', 'active', $now, $now, $now]);
        $db->execute('INSERT INTO code_workspaces (root_path, status, authorized_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', ['/tmp/rev', 'revoked', $now, $now, $now]);
        assertSame(2, (int) $db->fetch('SELECT COUNT(*) c FROM code_workspaces')['c']);

        $rejected = $throws(static function () use ($db, $now): void {
            $db->execute('INSERT INTO code_workspaces (root_path, status, authorized_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', ['/tmp/bad', 'bogus', $now, $now, $now]);
        });
        assertSame(true, $rejected);
    } finally {
        $cleanup();
    }
});

test('031 diretta su tabella legacy VUOTA: DROP + CREATE nuovo schema', function () use ($makeBareDb, $createLegacy, $run031, $columns) {
    [$db, $cleanup] = $makeBareDb();
    try {
        $createLegacy($db); // legacy con project_id, 0 righe
        assertSame(true, in_array('project_id', $columns($db), true));
        $run031($db);
        assertSame(false, in_array('project_id', $columns($db), true)); // ricostruita standalone
        assertSame(true, in_array('root_path', $columns($db), true));
    } finally {
        $cleanup();
    }
});

test('031 diretta su legacy con UNA RIGA: eccezione fail-closed, tabella e riga intatte', function () use ($makeBareDb, $createLegacy, $run031, $columns, $throws) {
    [$db, $cleanup] = $makeBareDb();
    try {
        $createLegacy($db);
        $now = date('c');
        $db->execute('INSERT INTO code_workspaces (project_id, root_path, authorized_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [1, '/tmp/legacy', $now, $now, $now]);

        $caught = null;
        try {
            $run031($db);
        } catch (\Throwable $e) {
            $caught = $e;
        }
        // Deve essere una RuntimeException, con messaggio che cita '031:' e il n. di righe.
        assertSame(true, $caught instanceof \RuntimeException);
        assertSame(true, str_contains((string) $caught?->getMessage(), '031:'));
        assertSame(true, str_contains((string) $caught?->getMessage(), '1 righe'));
        // La 031 lancia PRIMA del DROP: schema legacy e riga ancora presenti.
        assertSame(true, in_array('project_id', $columns($db), true));
        assertSame(1, (int) $db->fetch('SELECT COUNT(*) c FROM code_workspaces')['c']);
    } finally {
        $cleanup();
    }
});
