<?php

use App\Core\Database;

return function (Database $db): void {
    $columns = $db->fetchAll('PRAGMA table_info(projects)');
    foreach ($columns as $column) {
        if (($column['name'] ?? '') === 'is_system') {
            return;
        }
    }

    $db->execute('ALTER TABLE projects ADD COLUMN is_system INTEGER NOT NULL DEFAULT 0');
};
