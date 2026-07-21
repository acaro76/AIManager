<?php

declare(strict_types=1);

namespace App\Models;

final class ChatAttachment extends BaseModel
{
    public function create(array $data): int
    {
        $now = date('c');
        $this->db->execute(
            'INSERT INTO chat_attachments (
                project_id, session_id, conversation_id, name, path, extension, size, mime,
                extracted_text, is_image, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $data['project_id'],
                (int) $data['session_id'],
                !empty($data['conversation_id']) ? (int) $data['conversation_id'] : null,
                (string) $data['name'],
                (string) $data['path'],
                (string) $data['extension'],
                (int) $data['size'],
                (string) $data['mime'],
                (string) ($data['text'] ?? ''),
                !empty($data['is_image']) ? 1 : 0,
                $now,
            ]
        );

        return $this->db->lastInsertId();
    }

    public function find(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM chat_attachments WHERE id = ?', [$id]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM chat_attachments WHERE id = ?', [$id]);
    }

    public function pathsForProject(int $projectId): array
    {
        return array_column(
            $this->db->fetchAll('SELECT path FROM chat_attachments WHERE project_id = ?', [$projectId]),
            'path'
        );
    }

    public function attachToConversation(array $attachmentIds, int $conversationId): void
    {
        $ids = array_values(array_filter(array_map('intval', $attachmentIds), fn (int $id): bool => $id > 0));
        if (!$ids || $conversationId <= 0) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->db->execute(
            'UPDATE chat_attachments SET conversation_id = ? WHERE id IN (' . $placeholders . ')',
            array_merge([$conversationId], $ids)
        );
    }

    public function groupedForConversationIds(array $conversationIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $conversationIds), fn (int $id): bool => $id > 0)));
        if (!$ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->fetchAll(
            'SELECT id, conversation_id, name, extension, size, mime, is_image
             FROM chat_attachments
             WHERE conversation_id IN (' . $placeholders . ')
             ORDER BY created_at ASC, id ASC',
            $ids
        );

        $grouped = [];
        foreach ($rows as $row) {
            $conversationId = (int) ($row['conversation_id'] ?? 0);
            if ($conversationId <= 0) {
                continue;
            }

            $grouped[$conversationId][] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'extension' => (string) $row['extension'],
                'size' => (int) $row['size'],
                'mime' => (string) $row['mime'],
                'is_image' => (int) $row['is_image'] === 1,
            ];
        }

        return $grouped;
    }
}
