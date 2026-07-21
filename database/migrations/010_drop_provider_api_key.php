<?php

use App\Core\Database;

return function (Database $db): void {
    $columns = array_column($db->fetchAll('PRAGMA table_info(provider_configs)'), 'name');
    if (!in_array('api_key', $columns, true)) {
        return;
    }

    $db->execute('ALTER TABLE provider_configs DROP COLUMN api_key');
};
