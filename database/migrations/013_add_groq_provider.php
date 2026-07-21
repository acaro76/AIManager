<?php

use App\Core\Database;

return function (Database $db): void {
    $now = date('c');
    $exists = $db->fetch('SELECT provider FROM provider_configs WHERE provider = ?', ['groq']);
    if ($exists) {
        $db->execute(
            'UPDATE provider_configs SET label = ?, base_url = ?, model = ?, updated_at = ? WHERE provider = ? AND (base_url = "" OR model = "")',
            ['Groq', 'https://api.groq.com/openai/v1', 'llama-3.1-8b-instant', $now, 'groq']
        );
        return;
    }

    $db->execute(
        'INSERT INTO provider_configs (provider, label, base_url, model, enabled, timeout_seconds, temperature, max_tokens, top_p, priority, mode, status, last_error, last_checked_at, last_request_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        ['groq', 'Groq', 'https://api.groq.com/openai/v1', 'llama-3.1-8b-instant', 0, 20, 0.5, 1024, 1.0, 75, 'auto', 'offline', '', '', '', $now]
    );
};
