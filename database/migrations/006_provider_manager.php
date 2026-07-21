<?php

use App\Core\Database;

return function (Database $db): void {
    $providerColumns = array_column($db->fetchAll('PRAGMA table_info(provider_configs)'), 'name');
    $columns = [
        'timeout_seconds' => 'INTEGER NOT NULL DEFAULT 30',
        'temperature' => 'REAL NOT NULL DEFAULT 0.7',
        'max_tokens' => 'INTEGER NOT NULL DEFAULT 2048',
        'priority' => 'INTEGER NOT NULL DEFAULT 100',
        'mode' => 'TEXT NOT NULL DEFAULT "auto"',
        'status' => 'TEXT NOT NULL DEFAULT "offline"',
        'last_error' => 'TEXT DEFAULT ""',
        'last_checked_at' => 'TEXT DEFAULT ""',
        'last_request_at' => 'TEXT DEFAULT ""',
    ];

    foreach ($columns as $name => $definition) {
        if (!in_array($name, $providerColumns, true)) {
            $db->execute('ALTER TABLE provider_configs ADD COLUMN ' . $name . ' ' . $definition);
        }
    }

    $logColumns = array_column($db->fetchAll('PRAGMA table_info(ai_request_logs)'), 'name');
    $logAdditions = [
        'endpoint' => 'TEXT DEFAULT ""',
        'estimated_cost' => 'REAL DEFAULT 0',
        'choice_reason' => 'TEXT DEFAULT ""',
        'fallback_used' => 'INTEGER DEFAULT 0',
    ];

    foreach ($logAdditions as $name => $definition) {
        if (!in_array($name, $logColumns, true)) {
            $db->execute('ALTER TABLE ai_request_logs ADD COLUMN ' . $name . ' ' . $definition);
        }
    }

    $db->execute('UPDATE projects SET provider = "auto" WHERE provider = "" OR provider = "lmstudio"');
    $db->execute('UPDATE provider_configs SET priority = 100 WHERE provider = "lmstudio" AND priority = 100');
    $db->execute('UPDATE provider_configs SET priority = 90 WHERE provider = "claude" AND priority = 100');
    $db->execute('UPDATE provider_configs SET priority = 80 WHERE provider = "openai" AND priority = 100');
    $db->execute('UPDATE provider_configs SET priority = 70 WHERE provider = "gemini" AND priority = 100');
};
