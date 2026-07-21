<?php

use App\Core\Database;

return function (Database $db): void {
    $now = date('c');
    $exists = $db->fetch('SELECT provider FROM provider_configs WHERE provider = ?', ['deepseek']);
    if ($exists) {
        $db->execute(
            'UPDATE provider_configs SET label = ?, base_url = ?, model = ?, updated_at = ? WHERE provider = ? AND (base_url = "" OR model = "")',
            ['DeepSeek', 'https://api.deepseek.com', 'deepseek-chat', $now, 'deepseek']
        );
        return;
    }

    $db->execute(
        'INSERT INTO provider_configs (provider, label, base_url, model, enabled, timeout_seconds, temperature, max_tokens, top_p, priority, mode, status, last_error, last_checked_at, last_request_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        ['deepseek', 'DeepSeek', 'https://api.deepseek.com', 'deepseek-chat', 0, 60, 0.7, 2048, 1.0, 74, 'auto', 'offline', '', '', '', $now]
    );
};
