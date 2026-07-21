<?php

declare(strict_types=1);

use App\Core\Code\CodeChatSchema;
use App\Core\Code\CodePatchSchema;
use App\Core\Code\CodeVerificationSchema;
use App\Core\Code\CodeWorkingMemorySchema;
use App\Core\Code\CommandRunSchema;
use App\Core\Code\ProcessRunSchema;
use App\Core\Database;
use App\Services\MigrationRunner;

// Fase 9 / Step 2 — la migrazione 040 (code_working_memories) su SQLite TEMPORANEO. Mai il DB reale.
// Copre: catena 001→040 (schema READY, verify vuoto, FK attese), additività (le tabelle Code
// esistenti restano verificate), nessuna FK verso tabelle LLM, e rifiuto fail-closed di una
// presenza incompatibile SENZA alterazioni.

$makeChainDb = static function (): array {
    $path = sys_get_temp_dir() . '/aimanager_mig040_' . uniqid('', true) . '.sqlite';
    $db = new Database($path);
    $root = dirname(__DIR__);
    (new MigrationRunner($db, $root . '/database/migrations', $root . '/database/seeds'))->run();
    $cleanup = static function () use ($path): void {
        foreach ([$path, $path . '-wal', $path . '-shm'] as $f) { if (is_file($f)) { @unlink($f); } }
    };
    return [$db, $cleanup];
};

$throws = static function (callable $fn): bool {
    try { $fn(); return false; } catch (\Throwable) { return true; }
};

test('040: catena 001→040 crea code_working_memories, schema READY', function () use ($makeChainDb) {
    [$db, $cleanup] = $makeChainDb();
    try {
        assertSame(true, CodeWorkingMemorySchema::tableExists($db, CodeWorkingMemorySchema::TABLE));
        assertSame([], CodeWorkingMemorySchema::verify($db));
        assertSame(CodeWorkingMemorySchema::STATE_READY, CodeWorkingMemorySchema::state($db));
    } finally { $cleanup(); }
});

test('040: additiva — chat, patch, verifiche, comandi e processi restano verificati', function () use ($makeChainDb) {
    [$db, $cleanup] = $makeChainDb();
    try {
        assertSame([], CodeChatSchema::verify($db));
        assertSame([], CodePatchSchema::verify($db));
        assertSame([], CodeVerificationSchema::verify($db));
        assertSame([], CommandRunSchema::verify($db));
        assertSame([], ProcessRunSchema::verify($db));
    } finally { $cleanup(); }
});

test('040: nessuna FK verso tabelle LLM (solo tabelle code_*)', function () use ($makeChainDb) {
    [$db, $cleanup] = $makeChainDb();
    try {
        $fks = $db->fetchAll('PRAGMA foreign_key_list(code_working_memories)');
        assertSame(true, count($fks) >= 3);
        foreach ($fks as $fk) {
            assertSame(true, str_starts_with((string) $fk['table'], 'code_'), 'FK verso ' . (string) $fk['table']);
        }
    } finally { $cleanup(); }
});

test('040: integrità e foreign_key_check puliti dopo la migrazione', function () use ($makeChainDb) {
    [$db, $cleanup] = $makeChainDb();
    try {
        $ic = $db->fetchAll('PRAGMA integrity_check');
        assertSame('ok', (string) ($ic[0]['integrity_check'] ?? ''));
        assertSame(0, count($db->fetchAll('PRAGMA foreign_key_check')));
    } finally { $cleanup(); }
});

test('040: idempotente — una seconda esecuzione non altera lo schema', function () use ($makeChainDb) {
    [$db, $cleanup] = $makeChainDb();
    try {
        $root = dirname(__DIR__);
        (new MigrationRunner($db, $root . '/database/migrations', $root . '/database/seeds'))->run();
        assertSame([], CodeWorkingMemorySchema::verify($db));
    } finally { $cleanup(); }
});

test('040: presenza INCOMPATIBILE rifiutata PRIMA del DDL, senza alterazioni', function () use ($throws) {
    $path = sys_get_temp_dir() . '/aimanager_mig040_bad_' . uniqid('', true) . '.sqlite';
    $db = new Database($path);
    try {
        // Tabella omonima ma divergente: la migrazione deve fallire chiusa e NON toccarla.
        $db->execute('CREATE TABLE code_working_memories (id INTEGER PRIMARY KEY, foo TEXT)');
        assertSame(CodeWorkingMemorySchema::STATE_INCOMPATIBLE, CodeWorkingMemorySchema::state($db));

        $migration = require dirname(__DIR__) . '/database/migrations/040_create_code_working_memories.php';
        assertSame(true, $throws(static fn () => $migration($db)));

        // La tabella divergente è rimasta esattamente com'era (nessuna colonna canonica aggiunta).
        $cols = array_map(static fn (array $c): string => (string) $c['name'], $db->fetchAll('PRAGMA table_info(code_working_memories)'));
        assertSame(['id', 'foo'], $cols);
    } finally {
        foreach ([$path, $path . '-wal', $path . '-shm'] as $f) { if (is_file($f)) { @unlink($f); } }
    }
});
