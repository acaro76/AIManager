<?php

declare(strict_types=1);

namespace App\Models;

final class AIRequestLog extends BaseModel
{
    public function create(array $data): int
    {
        $this->db->execute(
            'INSERT INTO ai_request_logs (project_id, session_id, execution_state_id, conversation_id, provider, model, endpoint, response_time_ms, tokens_input, tokens_output, estimated_cost, choice_reason, fallback_used, error, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $data['project_id'],
                $data['session_id'] ?? null,
                $data['execution_state_id'] ?? null,
                $data['conversation_id'] ?? null,
                (string) $data['provider'],
                (string) $data['model'],
                (string) ($data['endpoint'] ?? ''),
                (int) ($data['response_time_ms'] ?? 0),
                (int) ($data['tokens_input'] ?? 0),
                (int) ($data['tokens_output'] ?? 0),
                (float) ($data['estimated_cost'] ?? 0),
                (string) ($data['choice_reason'] ?? ''),
                (int) ($data['fallback_used'] ?? 0),
                (string) ($data['error'] ?? ''),
                date('c'),
            ]
        );

        return $this->db->lastInsertId();
    }

    public function statsByProvider(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT provider,
                COUNT(*) AS request_count,
                AVG(response_time_ms) AS avg_response_time,
                SUM(tokens_input) AS tokens_input,
                SUM(tokens_output) AS tokens_output,
                SUM(estimated_cost) AS estimated_cost,
                MAX(created_at) AS last_request
             FROM ai_request_logs
             GROUP BY provider'
        );

        return array_column($rows, null, 'provider');
    }

    public function latestForSession(int $sessionId): ?array
    {
        $row = $this->db->fetch(
            'SELECT * FROM ai_request_logs WHERE session_id = ? ORDER BY created_at DESC, id DESC LIMIT 1',
            [$sessionId]
        );

        return $row ?: null;
    }

    public function latestForConversationIds(array $conversationIds): ?array
    {
        $conversationIds = array_values(array_filter(array_map('intval', $conversationIds)));
        if (!$conversationIds) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($conversationIds), '?'));
        return $this->db->fetch(
            'SELECT * FROM ai_request_logs WHERE conversation_id IN (' . $placeholders . ') ORDER BY created_at DESC, id DESC LIMIT 1',
            $conversationIds
        );
    }
}
