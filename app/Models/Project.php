<?php

declare(strict_types=1);

namespace App\Models;

final class Project extends BaseModel
{
    public function all(): array
    {
        return $this->db->fetchAll('SELECT * FROM projects WHERE is_system = 0 ORDER BY updated_at DESC');
    }

    public function active(): array
    {
        return $this->db->fetchAll('SELECT * FROM projects WHERE is_system = 0 AND status = "active" ORDER BY updated_at DESC');
    }

    public function archived(): array
    {
        return $this->db->fetchAll('SELECT * FROM projects WHERE is_system = 0 AND status = "archived" ORDER BY updated_at DESC');
    }

    public function find(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM projects WHERE id = ?', [$id]);
    }

    public function create(array $data): int
    {
        $now = date('c');
        $this->db->execute(
            'INSERT INTO projects (name, description, status, provider, system_prompt, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$data['name'], $data['description'], $data['status'], $data['provider'], $data['system_prompt'], $now, $now]
        );
        return $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE projects SET name = ?, description = ?, status = ?, provider = ?, system_prompt = ?, updated_at = ? WHERE id = ?',
            [$data['name'], $data['description'], $data['status'], $data['provider'], $data['system_prompt'], date('c'), $id]
        );
    }

    public function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['active', 'archived'], true)) {
            throw new \InvalidArgumentException('Stato progetto non valido.');
        }
        $this->db->execute(
            'UPDATE projects SET status = ?, updated_at = ? WHERE id = ? AND is_system = 0',
            [$status, date('c'), $id]
        );
    }

    public static function isArchived(array $project): bool
    {
        return (string) ($project['status'] ?? 'active') === 'archived';
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM projects WHERE id = ? AND is_system = 0', [$id]);
    }

    public function genericChatProject(): array
    {
        $project = $this->db->fetch('SELECT * FROM projects WHERE is_system = 1 LIMIT 1');
        if ($project) {
            return $project;
        }

        $now = date('c');
        $this->db->execute(
            'INSERT INTO projects (name, description, status, provider, system_prompt, created_at, updated_at, is_system) VALUES (?, ?, ?, ?, ?, ?, ?, 1)',
            ['Chat libera', 'Spazio interno per conversazioni senza contesto di progetto.', 'active', 'auto', '', $now, $now]
        );

        return $this->find($this->db->lastInsertId());
    }

    public function stats(): array
    {
        return [
            'total' => (int) ($this->db->fetch('SELECT COUNT(*) AS count FROM projects WHERE is_system = 0')['count'] ?? 0),
            'active' => (int) ($this->db->fetch("SELECT COUNT(*) AS count FROM projects WHERE status = 'active' AND is_system = 0")['count'] ?? 0),
            'archived' => (int) ($this->db->fetch("SELECT COUNT(*) AS count FROM projects WHERE status = 'archived' AND is_system = 0")['count'] ?? 0),
        ];
    }
}
