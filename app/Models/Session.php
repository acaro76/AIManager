<?php

declare(strict_types=1);

namespace App\Models;

final class Session extends BaseModel
{
    public function forProject(int $projectId): array
    {
        return $this->db->fetchAll(
            'SELECT sessions.*,
                (SELECT COUNT(*) FROM conversations WHERE conversations.session_id = sessions.id) AS message_count
             FROM sessions
             WHERE project_id = ?
             ORDER BY status = "active" DESC, last_activity DESC',
            [$projectId]
        );
    }

    public function recentForProject(int $projectId, int $limit = 8): array
    {
        return $this->db->fetchAll(
            'SELECT sessions.*,
                (SELECT COUNT(*) FROM conversations WHERE conversations.session_id = sessions.id) AS message_count
             FROM sessions
             WHERE project_id = ?
             ORDER BY last_activity DESC, id DESC
             LIMIT ?',
            [$projectId, $limit]
        );
    }

    public function activeForProject(int $projectId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM sessions WHERE project_id = ? AND status = "active" ORDER BY last_activity ASC, id ASC',
            [$projectId]
        );
    }

    public function find(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM sessions WHERE id = ?', [$id]);
    }

    public function firstForProject(int $projectId): ?array
    {
        return $this->db->fetch(
            'SELECT * FROM sessions WHERE project_id = ? ORDER BY status = "active" DESC, last_activity DESC LIMIT 1',
            [$projectId]
        );
    }

    public function create(array $data): int
    {
        $now = date('c');
        $this->db->execute(
            'INSERT INTO sessions (project_id, title, description, status, created_at, updated_at, last_activity)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [(int) $data['project_id'], (string) $data['title'], (string) ($data['description'] ?? ''), 'active', $now, $now, $now]
        );

        return $this->db->lastInsertId();
    }

    public function rename(int $id, string $title, string $description): void
    {
        $this->db->execute(
            'UPDATE sessions SET title = ?, description = ?, updated_at = ? WHERE id = ?',
            [$title, $description, date('c'), $id]
        );
    }

    public function updateTitle(int $id, string $title): void
    {
        $this->db->execute(
            'UPDATE sessions SET title = ?, updated_at = ? WHERE id = ?',
            [$title, date('c'), $id]
        );
    }

    public function archive(int $id): void
    {
        $this->db->execute(
            'UPDATE sessions SET status = "archived", updated_at = ? WHERE id = ?',
            [date('c'), $id]
        );
    }

    public function touch(int $id): void
    {
        $now = date('c');
        $this->db->execute('UPDATE sessions SET updated_at = ?, last_activity = ? WHERE id = ?', [$now, $now, $id]);
    }
}
