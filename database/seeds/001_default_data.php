<?php

use App\Core\Database;

return function (Database $db): void {
    $now = date('c');
    $providers = App\Services\AIProviderRegistry::fromConfig()->catalog();

    foreach ($providers as $key => $provider) {
        $db->execute(
            'INSERT OR IGNORE INTO provider_configs (provider, label, base_url, model, enabled, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$key, $provider['label'], $provider['base_url'], $provider['model'], (int) ($provider['enabled'] ?? false), $now]
        );
    }

    $defaultProvider = array_key_first(array_filter($providers, fn (array $provider): bool => (bool) ($provider['enabled'] ?? false)))
        ?? array_key_first($providers)
        ?? '';

    $db->execute(
        'INSERT INTO projects (name, description, status, provider, system_prompt, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        ['Assistente locale', 'Area di lavoro per agenti e prompt locali.', 'active', 'auto', 'Rispondi in modo chiaro, operativo e contestuale.', $now, $now]
    );

    $projectId = $db->lastInsertId();
    $db->execute(
        'INSERT INTO memories (project_id, memory_type, title, content, tags, importance, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$projectId, 'knowledge', 'Preferenze operative', 'Conservare decisioni, vincoli tecnici e note utili per i progetti AI.', 'preferenze,workflow', 4, $now, $now]
    );

    foreach ([
        'app_theme' => 'light',
    ] as $key => $value) {
        $db->execute('INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?)', [$key, $value, $now]);
    }
};
