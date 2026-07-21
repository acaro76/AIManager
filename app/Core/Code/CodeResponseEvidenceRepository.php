<?php

declare(strict_types=1);

namespace App\Core\Code;

use App\Core\Database;

/** Metadati strutturati delle evidenze: mai contenuto o estratti dei file. */
final class CodeResponseEvidenceRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param list<array{path:string,kind:string,line:?int}> $citations
     * @param list<string> $limits
     * @param array<string, int> $metrics
     */
    public function storeForAssistant(
        int $assistantConversationId,
        int $sessionId,
        int $workspaceId,
        array $citations,
        array $limits,
        array $metrics,
    ): void {
        $citations = $this->validateCitations($citations);
        $limits = $this->validateLimits($limits);
        $metrics = $this->validateMetrics($metrics);
        $json = static fn (mixed $value): string => (string) json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );

        $affected = $this->db->execute(
            'INSERT INTO code_response_evidence
                (assistant_conversation_id, workspace_id, code_session_id, files_json, limits_json, metrics_json, created_at)
             SELECT c.id, ?, s.id, ?, ?, ?, ?
             FROM code_conversations c
             JOIN code_sessions s ON s.id = c.code_session_id
             WHERE c.id = ? AND c.role = \'assistant\' AND s.id = ? AND s.workspace_id = ?',
            [$workspaceId, $json($citations), $json($limits), $json((object) $metrics), date('c'),
             $assistantConversationId, $sessionId, $workspaceId]
        );
        if ($affected < 1) {
            throw new CodeWorkspaceException('Risposta assistant inesistente in questa sessione Code.');
        }
    }

    /** @return array<int, array{citations:list<array{path:string,kind:string,line:?int}>, limits:list<string>, metrics:array<string,int>}> */
    public function forHistory(int $sessionId, int $workspaceId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT e.* FROM code_response_evidence e
             JOIN code_sessions s ON s.id = e.code_session_id
             WHERE e.code_session_id = ? AND e.workspace_id = ? AND s.workspace_id = ?
             ORDER BY e.assistant_conversation_id',
            [$sessionId, $workspaceId, $workspaceId]
        );
        $out = [];
        foreach ($rows as $row) {
            $citations = json_decode((string) $row['files_json'], true);
            $limits = json_decode((string) $row['limits_json'], true);
            $metrics = json_decode((string) $row['metrics_json'], true);
            $out[(int) $row['assistant_conversation_id']] = [
                'citations' => $this->validateCitations(is_array($citations) ? $citations : []),
                'limits' => $this->validateLimits(is_array($limits) ? $limits : []),
                'metrics' => $this->validateMetrics(is_array($metrics) ? $metrics : []),
            ];
        }
        return $out;
    }

    /** @return list<array{path:string,kind:string,line:?int}> */
    private function validateCitations(array $citations): array
    {
        if ($citations !== [] && array_keys($citations) !== range(0, count($citations) - 1)) {
            throw new \InvalidArgumentException('Citazioni evidenza non valide.');
        }
        $out = [];
        foreach ($citations as $citation) {
            if (!is_array($citation) || !is_string($citation['path'] ?? null)
                || !in_array($citation['kind'] ?? null, ['read', 'found'], true)) {
                throw new \InvalidArgumentException('Citazione evidenza non valida.');
            }
            $path = (string) $citation['path'];
            RelativePath::assert($path);
            $line = $citation['line'] ?? null;
            if ($line !== null && (!is_int($line) || $line < 1)) {
                throw new \InvalidArgumentException('Linea evidenza non valida.');
            }
            $key = (string) $citation['kind'] . "\0" . $path;
            $out[$key] = ['path' => $path, 'kind' => (string) $citation['kind'], 'line' => $line];
        }
        return array_values($out);
    }

    /** @return list<string> */
    private function validateLimits(array $limits): array
    {
        if ($limits !== [] && array_keys($limits) !== range(0, count($limits) - 1)) {
            throw new \InvalidArgumentException('Limiti evidenza non validi.');
        }
        foreach ($limits as $limit) {
            if (!is_string($limit) || !in_array($limit, RetrievalResult::LIMIT_CODES, true)) {
                throw new \InvalidArgumentException('Limite evidenza non ammesso.');
            }
        }
        return array_values(array_unique($limits));
    }

    /** @return array<string,int> */
    private function validateMetrics(array $metrics): array
    {
        foreach ($metrics as $key => $value) {
            if (!is_string($key) || !in_array($key, RetrievalResult::METRIC_KEYS, true) || !is_int($value) || $value < 0) {
                throw new \InvalidArgumentException('Metrica evidenza non ammessa.');
            }
        }
        return $metrics;
    }
}
