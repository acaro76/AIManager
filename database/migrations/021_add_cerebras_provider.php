<?php

use App\Core\Database;

return function (Database $db): void {
    $now = date('c');
    $exists = $db->fetch('SELECT provider FROM provider_configs WHERE provider = ?', ['cerebras']);
    if ($exists) {
        $db->execute(
            'UPDATE provider_configs SET label = ?, base_url = ?, model = ?, updated_at = ? WHERE provider = ? AND (base_url = "" OR model = "")',
            ['Cerebras', 'https://api.cerebras.ai/v1', 'gpt-oss-120b', $now, 'cerebras']
        );
        return;
    }

    $db->execute(
        'INSERT INTO provider_configs (provider, label, base_url, model, enabled, timeout_seconds, temperature, max_tokens, top_p, priority, mode, status, last_error, last_checked_at, last_request_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        ['cerebras', 'Cerebras', 'https://api.cerebras.ai/v1', 'gpt-oss-120b', 0, 30, 0.7, 2048, 1.0, 78, 'auto', 'offline', '', '', '', $now]
    );
};
