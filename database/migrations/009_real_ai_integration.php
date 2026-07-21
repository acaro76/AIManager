<?php

use App\Core\Database;

return function (Database $db): void {
    $providerColumns = array_column($db->fetchAll('PRAGMA table_info(provider_configs)'), 'name');
    if (!in_array('top_p', $providerColumns, true)) {
        $db->execute('ALTER TABLE provider_configs ADD COLUMN top_p REAL NOT NULL DEFAULT 1.0');
    }

    $logColumns = array_column($db->fetchAll('PRAGMA table_info(ai_request_logs)'), 'name');
    foreach ([
        'session_id' => 'INTEGER NULL',
        'execution_state_id' => 'INTEGER NULL',
    ] as $name => $definition) {
        if (!in_array($name, $logColumns, true)) {
            $db->execute('ALTER TABLE ai_request_logs ADD COLUMN ' . $name . ' ' . $definition);
        }
    }
};
