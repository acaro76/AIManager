<?php

declare(strict_types=1);

namespace App\Models;

final class Conversation extends BaseModel
{
    public function find(int $id): ?array
    {
        $row = $this->db->fetch('SELECT * FROM conversations WHERE id = ?', [$id]);
        return $row ?: null;
    }

    public function forSession(int $sessionId, int $limit = 80): array
    {
        return array_reverse($this->db->fetchAll(
            'SELECT * FROM conversations WHERE session_id = ? ORDER BY created_at DESC, id DESC LIMIT ?',
            [$sessionId, $limit]
        ));
    }

    public function latestForSessionContext(int $sessionId, int $limit = 12): array
    {
        return $this->forSession($sessionId, $limit);
    }

    public function create(array $data): int
    {
        $this->db->execute(
            'INSERT INTO conversations (project_id, session_id, role, content, provider, model, tokens_input, tokens_output, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $data['project_id'],
                (int) $data['session_id'],
                (string) $data['role'],
                (string) $data['content'],
                (string) $data['provider'],
                (string) $data['model'],
                (int) ($data['tokens_input'] ?? 0),
                (int) ($data['tokens_output'] ?? 0),
                date('c'),
            ]
        );

        return $this->db->lastInsertId();
    }

}
