<?php

use App\Core\Database;

return function (Database $db): void {
    $now = date('c');
    $exists = $db->fetch('SELECT provider FROM provider_configs WHERE provider = ?', ['openrouter']);
    if ($exists) {
        $db->execute(
            'UPDATE provider_configs SET label = ?, base_url = ?, model = ?, updated_at = ? WHERE provider = ? AND (base_url = "" OR model = "" OR model = ?)',
            ['OpenRouter', 'https://openrouter.ai/api/v1', 'openrouter/free', $now, 'openrouter', 'deepseek/deepseek-chat-v3-0324:free']
        );
        return;
    }

    $db->execute(
        'INSERT INTO provider_configs (provider, label, base_url, model, enabled, timeout_seconds, temperature, max_tokens, top_p, priority, mode, status, last_error, last_checked_at, last_request_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        ['openrouter', 'OpenRouter', 'https://openrouter.ai/api/v1', 'openrouter/free', 0, 30, 0.5, 1024, 1.0, 65, 'auto', 'offline', '', '', '', $now]
    );
};
