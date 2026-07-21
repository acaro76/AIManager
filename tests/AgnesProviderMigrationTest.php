<?php

declare(strict_types=1);

use App\Core\Database;

/** @return array{0: Database, 1: string} */
$agnesMigrationDb = static function (): array {
    $path = sys_get_temp_dir() . '/aim_agnes_migration_' . uniqid('', true) . '.sqlite';
    $db = new Database($path);
    $db->execute('CREATE TABLE provider_configs (
        provider TEXT PRIMARY KEY,
        label TEXT NOT NULL,
        base_url TEXT NOT NULL,
        model TEXT NOT NULL,
        enabled INTEGER NOT NULL,
        timeout_seconds INTEGER NOT NULL,
        temperature REAL NOT NULL,
        max_tokens INTEGER NOT NULL,
        top_p REAL NOT NULL,
        priority INTEGER NOT NULL,
        mode TEXT NOT NULL,
        status TEXT NOT NULL,
        last_error TEXT NOT NULL,
        last_checked_at TEXT NOT NULL,
        last_request_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');

    return [$db, $path];
};

test('037: crea Agnes abilitata con endpoint e modello canonici', function () use ($agnesMigrationDb) {
    [$db, $path] = $agnesMigrationDb();
    try {
        $migration = require dirname(__DIR__) . '/database/migrations/037_add_agnes_provider.php';
        $db->transaction(static fn () => $migration($db));
        $row = $db->fetch('SELECT * FROM provider_configs WHERE provider = ?', ['agnes']);
        assertSame('Agnes AI', $row['label']);
        assertSame('https://apihub.agnes-ai.com/v1', $row['base_url']);
        assertSame('agnes-2.0-flash', $row['model']);
        assertSame(1, (int) $row['enabled']);
    } finally {
        @unlink($path);
    }
});
test('037: completa solo i campi vuoti senza sovrascrivere scelte esistenti', function () use ($agnesMigrationDb) {
    [$db, $path] = $agnesMigrationDb();
    try {
        $db->execute(
            'INSERT INTO provider_configs VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ['agnes', 'Nome personale', 'https://gateway.example/v1', '', 0, 9, 0.2, 999, 0.8, 12, 'agnes', 'online', 'x', 'a', 'b', 'old']
        );
        $migration = require dirname(__DIR__) . '/database/migrations/037_add_agnes_provider.php';
        $db->transaction(static fn () => $migration($db));
        $row = $db->fetch('SELECT * FROM provider_configs WHERE provider = ?', ['agnes']);

        assertSame('Nome personale', $row['label']);
        assertSame('https://gateway.example/v1', $row['base_url']);
        assertSame('agnes-2.0-flash', $row['model']);
        assertSame(0, (int) $row['enabled']);
        assertSame(12, (int) $row['priority']);
        assertSame(999, (int) $row['max_tokens']);
    } finally {
        @unlink($path);
    }
});
