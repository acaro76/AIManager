<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Memory\MemoryType;

final class Memory extends BaseModel
{
    public function recent(int $limit = 20): array
    {
        return $this->db->fetchAll(
            'SELECT memories.*, projects.name AS project_name FROM memories LEFT JOIN projects ON projects.id = memories.project_id ORDER BY memories.updated_at DESC LIMIT ?',
            [$limit]
        );
    }

    public function create(array $data): int
    {
        $now = date('c');
        $this->db->execute(
            'INSERT INTO memories (project_id, session_id, memory_type, title, content, tags, importance, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [(int) $data['project_id'], $data['session_id'] ?? null, MemoryType::normalize((string) $data['memory_type']), $data['title'], $data['content'], $data['tags'], (int) $data['importance'], $now, $now]
        );
        return $this->db->lastInsertId();
    }

    public function createBrainItem(array $data): int
    {
        $now = date('c');
        $this->db->execute(
            'INSERT INTO memories (
                project_id, session_id, memory_type, title, content, tags, importance,
                brain_category, canonical_key, confidence, source, metadata_json,
                created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $data['project_id'],
                $data['session_id'] ?? null,
                MemoryType::normalize((string) $data['memory_type']),
                (string) $data['title'],
                (string) $data['content'],
                (string) ($data['tags'] ?? ''),
                (int) ($data['importance'] ?? 3),
                (string) ($data['brain_category'] ?? ''),
                (string) ($data['canonical_key'] ?? ''),
                (float) ($data['confidence'] ?? 0.6),
                (string) ($data['source'] ?? 'project_brain'),
                json_encode($data['metadata'] ?? [], JSON_UNESCAPED_UNICODE),
                $now,
                $now,
            ]
        );

        return $this->db->lastInsertId();
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM memories WHERE id = ?', [$id]);
    }

    public function forProject(int $projectId, int $limit = 20): array
    {
        return $this->db->fetchAll(
            'SELECT memories.*, projects.name AS project_name
             FROM memories INNER JOIN projects ON projects.id = memories.project_id
             WHERE memories.project_id = ?
             ORDER BY memories.updated_at DESC
             LIMIT ?',
            [$projectId, $limit]
        );
    }

    public function searchForProject(int $projectId, string $query): array
    {
        $like = '%' . $query . '%';
        return $this->db->fetchAll(
            'SELECT memories.*, projects.name AS project_name
             FROM memories INNER JOIN projects ON projects.id = memories.project_id
             WHERE memories.project_id = ?
             AND (memories.title LIKE ? OR memories.content LIKE ? OR memories.tags LIKE ?)
             ORDER BY memories.importance DESC, memories.updated_at DESC
             LIMIT 50',
            [$projectId, $like, $like, $like]
        );
    }

    public function forProjectByType(int $projectId, string $type, int $limit = 20): array
    {
        return $this->db->fetchAll(
            'SELECT memories.*, projects.name AS project_name
             FROM memories INNER JOIN projects ON projects.id = memories.project_id
             WHERE memories.project_id = ? AND memories.memory_type = ?
             ORDER BY memories.importance DESC, memories.updated_at DESC
             LIMIT ?',
            [$projectId, $type, $limit]
        );
    }

    public function searchForProjectByType(int $projectId, string $type, string $query, int $limit = 20): array
    {
        $like = '%' . $query . '%';
        return $this->db->fetchAll(
            'SELECT memories.*, projects.name AS project_name
             FROM memories INNER JOIN projects ON projects.id = memories.project_id
             WHERE memories.project_id = ? AND memories.memory_type = ?
             AND (memories.title LIKE ? OR memories.content LIKE ? OR memories.tags LIKE ?)
             ORDER BY memories.importance DESC, memories.updated_at DESC
             LIMIT ?',
            [$projectId, $type, $like, $like, $like, $limit]
        );
    }

    public function findByCanonicalKey(int $projectId, string $canonicalKey): ?array
    {
        return $this->db->fetch(
            'SELECT * FROM memories WHERE project_id = ? AND canonical_key = ? ORDER BY updated_at DESC LIMIT 1',
            [$projectId, $canonicalKey]
        );
    }

    public function recentBrainByCategories(int $projectId, array $categories, int $limit = 20): array
    {
        if (!$categories) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($categories), '?'));
        return $this->db->fetchAll(
            'SELECT memories.*, sessions.title AS session_title
             FROM memories LEFT JOIN sessions ON sessions.id = memories.session_id
             WHERE memories.project_id = ? AND memories.brain_category IN (' . $placeholders . ')
             ORDER BY memories.updated_at DESC
             LIMIT ?',
            array_merge([$projectId], $categories, [$limit])
        );
    }

    public function updateBrainMetadata(int $id, array $metadata): void
    {
        $this->db->execute(
            'UPDATE memories SET metadata_json = ?, updated_at = ? WHERE id = ?',
            [json_encode($metadata, JSON_UNESCAPED_UNICODE), date('c'), $id]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM memories WHERE id = ? LIMIT 1', [$id]);
    }

    /**
     * Fatti del Project Brain "residenti": brain item con confidenza sopra soglia, da
     * iniettare sempre nel contesto a prescindere dal messaggio corrente (sez. 18.4).
     * brain_category != '' isola i soli item del brain (non le memorie/documenti normali).
     */
    public function residentBrainItems(int $projectId, float $minConfidence, int $limit): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM memories
             WHERE project_id = ? AND brain_category != "" AND confidence >= ?
             ORDER BY importance DESC, confidence DESC, updated_at DESC
             LIMIT ?',
            [$projectId, $minConfidence, $limit]
        );
    }

    /**
     * Aggiorna in modo mirato un item del brain (percorso consolidatore AI, sez. 18.4).
     * Accetta solo i campi noti: content, confidence, importance, metadata (json).
     *
     * @param array<string, mixed> $fields
     */
    public function updateBrainItem(int $id, array $fields): void
    {
        $set = [];
        $params = [];
        if (array_key_exists('content', $fields)) {
            $set[] = 'content = ?';
            $params[] = (string) $fields['content'];
        }
        if (array_key_exists('confidence', $fields)) {
            $set[] = 'confidence = ?';
            $params[] = (float) $fields['confidence'];
        }
        if (array_key_exists('importance', $fields)) {
            $set[] = 'importance = ?';
            $params[] = (int) $fields['importance'];
        }
        if (array_key_exists('metadata', $fields)) {
            $set[] = 'metadata_json = ?';
            $params[] = json_encode($fields['metadata'], JSON_UNESCAPED_UNICODE);
        }
        if ($set === []) {
            return;
        }

        $set[] = 'updated_at = ?';
        $params[] = date('c');
        $params[] = $id;

        $this->db->execute('UPDATE memories SET ' . implode(', ', $set) . ' WHERE id = ?', $params);
    }

    public function count(): int
    {
        return (int) ($this->db->fetch('SELECT COUNT(*) AS count FROM memories')['count'] ?? 0);
    }
}
