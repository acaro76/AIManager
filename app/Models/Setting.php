<?php

declare(strict_types=1);

namespace App\Models;

final class Setting extends BaseModel
{
    public function get(string $key, ?string $default = null): ?string
    {
        return $this->db->fetch('SELECT value FROM settings WHERE key = ?', [$key])['value'] ?? $default;
    }

    public function set(string $key, string $value): void
    {
        $this->db->execute(
            'INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
            [$key, $value, date('c')]
        );
    }
}
