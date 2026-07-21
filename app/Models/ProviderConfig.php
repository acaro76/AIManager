<?php

declare(strict_types=1);

namespace App\Models;

final class ProviderConfig extends BaseModel
{
    public function all(): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM provider_configs ORDER BY priority DESC, provider ASC');
        $byProvider = [];
        foreach ($rows as $row) {
            $byProvider[$row['provider']] = $row;
        }
        return $byProvider;
    }

    public function find(string $provider): ?array
    {
        return $this->db->fetch('SELECT * FROM provider_configs WHERE provider = ?', [$provider]);
    }

    public function defaultKey(): string
    {
        return 'auto';
    }

    public function save(string $provider, array $data): void
    {
        $existing = $this->find($provider);
        if ($existing) {
            $this->db->execute(
                'UPDATE provider_configs SET label = ?, base_url = ?, model = ?, enabled = ?, timeout_seconds = ?, temperature = ?, max_tokens = ?, top_p = ?, priority = ?, mode = ?, updated_at = ? WHERE provider = ?',
                [$data['label'], $data['base_url'], $data['model'], (int) ($data['enabled'] ?? 0), (int) $data['timeout_seconds'], (float) $data['temperature'], (int) $data['max_tokens'], (float) ($data['top_p'] ?? 1.0), (int) $data['priority'], (string) $data['mode'], date('c'), $provider]
            );
            return;
        }

        $this->db->execute(
            'INSERT INTO provider_configs (provider, label, base_url, model, enabled, timeout_seconds, temperature, max_tokens, top_p, priority, mode, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$provider, $data['label'], $data['base_url'], $data['model'], (int) ($data['enabled'] ?? 0), (int) $data['timeout_seconds'], (float) $data['temperature'], (int) $data['max_tokens'], (float) ($data['top_p'] ?? 1.0), (int) $data['priority'], (string) $data['mode'], date('c')]
        );
    }

    public function setEnabled(string $provider, bool $enabled): void
    {
        $this->db->execute(
            'UPDATE provider_configs SET enabled = ?, updated_at = ? WHERE provider = ?',
            [$enabled ? 1 : 0, date('c'), $provider]
        );
    }

    public function enabled(): array
    {
        return $this->db->fetchAll('SELECT * FROM provider_configs WHERE enabled = 1 ORDER BY priority DESC, provider ASC');
    }

    public function updateHealth(string $provider, string $status, string $error = ''): void
    {
        $this->db->execute(
            'UPDATE provider_configs SET status = ?, last_error = ?, last_checked_at = ?, updated_at = ? WHERE provider = ?',
            [$status, $error, date('c'), date('c'), $provider]
        );
    }

    public function markRequest(string $provider, string $error = ''): void
    {
        $this->db->execute(
            'UPDATE provider_configs SET last_request_at = ?, last_error = ?, updated_at = ? WHERE provider = ?',
            [date('c'), $error, date('c'), $provider]
        );
    }
}
