<?php

use App\Core\Database;

return function (Database $db): void {
    $db->execute(
        'UPDATE provider_configs SET model = ?, updated_at = ? WHERE provider = ? AND (model = ? OR model = "")',
        ['gemini-2.5-flash-lite', date('c'), 'gemini', 'gemini-1.5-flash']
    );
};
