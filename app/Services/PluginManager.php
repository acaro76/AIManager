<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Services\Plugin\CoreContext;

final class PluginManager
{
    private array $plugins = [];

    public function __construct(
        private readonly Database $db,
        private readonly string $pluginPath,
    ) {
    }

    public function discover(): void
    {
        foreach (glob(rtrim($this->pluginPath, '/') . '/*/plugin.json') ?: [] as $manifest) {
            $data = json_decode((string) file_get_contents($manifest), true);
            if (!is_array($data) || empty($data['slug'])) {
                continue;
            }

            $data['path'] = dirname($manifest);
            $stored = $this->db->fetch('SELECT enabled FROM plugins WHERE slug = ?', [$data['slug']]);
            $enabled = $stored ? (int) $stored['enabled'] : (int) ($data['enabled'] ?? 0);

            $this->db->execute(
                'INSERT INTO plugins (slug, name, version, description, enabled, path, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT(slug) DO UPDATE SET name = excluded.name, version = excluded.version, description = excluded.description, path = excluded.path, updated_at = excluded.updated_at',
                [
                    $data['slug'],
                    $data['name'] ?? $data['slug'],
                    $data['version'] ?? '0.1.0',
                    $data['description'] ?? '',
                    $enabled,
                    $data['path'],
                    date('c'),
                ]
            );

            $data['enabled'] = (bool) $enabled;
            $this->plugins[$data['slug']] = $data;
        }
    }

    public function all(): array
    {
        return $this->db->fetchAll('SELECT * FROM plugins ORDER BY name ASC');
    }

    public function toggle(string $slug): void
    {
        $row = $this->db->fetch('SELECT enabled FROM plugins WHERE slug = ?', [$slug]);
        if (!$row) {
            return;
        }

        $this->db->execute('UPDATE plugins SET enabled = ?, updated_at = ? WHERE slug = ?', [(int) !$row['enabled'], date('c'), $slug]);
    }

    public function hook(string $hook, array $context = []): array
    {
        $outputs = [];
        foreach ($this->plugins as $plugin) {
            if (!$plugin['enabled'] || empty($plugin['hooks'][$hook])) {
                continue;
            }

            $file = $plugin['path'] . '/' . ltrim($plugin['hooks'][$hook], '/');
            if (!is_file($file)) {
                continue;
            }

            $outputs[] = (static function (array $plugin, array $context) use ($file) {
                $core = $context['core'] ?? null;
                return require $file;
            })($plugin, array_merge(['core' => new CoreContext()], $context));
        }

        return $outputs;
    }
}
