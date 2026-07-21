<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Memory\MemoryType;
use App\Models\Conversation;
use App\Models\Memory;

final class ProjectBrainService
{
    public function __construct(
        private readonly ?BrainConsolidationService $consolidator = null,
    ) {
    }

    public function analyzeSession(array $project, array $session): array
    {
        $messages = (new Conversation())->forSession((int) $session['id'], 200);

        // Percorso principale: consolidatore AI (Fase 1, sez. 18.4). Legge il transcript +
        // le memorie gia' note e restituisce solo i fatti salienti, tipizzati e con la
        // relazione col gia' noto. Se non e' disponibile o il provider fallisce, si ripiega
        // sul vecchio estrattore a parole-chiave: nessuna regressione.
        if ($this->consolidator !== null) {
            $decision = $this->consolidator->consolidate($messages, $this->existingBrief($project));
            if ($decision['decided']) {
                return $this->applyAiItems($project, $session, $decision['items']);
            }
        }

        return $this->analyzeSessionByKeywords($project, $session, $messages);
    }

    /**
     * Applica gli item del consolidatore AI, rispettando la relazione col gia' noto
     * (new / duplicate / conflict / refine).
     *
     * @param array<int, array{type: string, title: string, content: string, importance: int, confidence: float, subject: string, polarity: string, relation: string, relation_id: int}> $items
     */
    private function applyAiItems(array $project, array $session, array $items): array
    {
        $memory = new Memory();
        $projectId = (int) $project['id'];
        $result = ['new' => 0, 'known' => 0, 'conflicts' => 0, 'items' => []];

        foreach ($items as $item) {
            $relation = $item['relation'];
            $existing = null;
            if (in_array($relation, ['duplicate', 'conflict', 'refine'], true) && $item['relation_id'] > 0) {
                $candidate = $memory->findById($item['relation_id']);
                // L'id deve appartenere a questo progetto: altrimenti la relazione non e' valida.
                if ($candidate !== null && (int) ($candidate['project_id'] ?? 0) === $projectId) {
                    $existing = $candidate;
                } else {
                    $relation = 'new';
                }
            } elseif ($relation !== 'new') {
                $relation = 'new';
            }

            if ($relation === 'duplicate' && $existing !== null) {
                $this->reinforceExisting($memory, $existing, $session, (float) $item['confidence']);
                $result['known']++;
                $result['items'][] = ['status' => 'known', 'title' => $item['title'], 'category' => $item['type']];
                continue;
            }

            if ($relation === 'refine' && $existing !== null) {
                $metadata = $this->decodeMetadata((string) ($existing['metadata_json'] ?? '{}'));
                $metadata['brain_status'] = 'known';
                $metadata['refined_session_id'] = (int) $session['id'];
                $memory->updateBrainItem((int) $existing['id'], [
                    'content' => mb_substr($item['content'], 0, 500),
                    'confidence' => max((float) ($existing['confidence'] ?? 0.6), (float) $item['confidence']),
                    'importance' => max((int) ($existing['importance'] ?? 3), (int) $item['importance']),
                    'metadata' => $metadata,
                ]);
                $result['known']++;
                $result['items'][] = ['status' => 'refined', 'title' => $item['title'], 'category' => $item['type']];
                continue;
            }

            // 'new' + eventuale guardia canonica per non duplicare anche se l'AI dice new.
            $canonicalKey = $this->canonicalKey($item['type'], $item['title']);
            $canonical = $memory->findByCanonicalKey($projectId, $canonicalKey);
            if ($relation === 'new' && $canonical !== null) {
                $this->reinforceExisting($memory, $canonical, $session, (float) $item['confidence']);
                $result['known']++;
                $result['items'][] = ['status' => 'known', 'title' => $item['title'], 'category' => $item['type']];
                continue;
            }

            $isConflict = $relation === 'conflict' && $existing !== null;
            $metadata = [
                'brain_status' => $isConflict ? 'conflict' : 'new',
                'session_title' => (string) $session['title'],
                'subject_key' => $item['subject'],
                'polarity' => $item['polarity'],
                'source' => 'ai_consolidation',
            ];
            if ($isConflict) {
                $metadata['conflicts_with'] = (int) $existing['id'];
                $this->markConflicted($memory, $existing, (int) $session['id']);
                $result['conflicts']++;
            } else {
                $result['new']++;
            }

            $memory->createBrainItem([
                'project_id' => $projectId,
                'session_id' => (int) $session['id'],
                'memory_type' => MemoryType::normalize($item['type']),
                'title' => $item['title'],
                'content' => $item['content'],
                'tags' => 'project-brain,ai,' . $item['type'],
                'importance' => $item['importance'],
                'brain_category' => $item['type'],
                'canonical_key' => $canonicalKey,
                'confidence' => $item['confidence'],
                'source' => 'ai_consolidation',
                'metadata' => $metadata,
            ]);

            $result['items'][] = ['status' => $metadata['brain_status'], 'title' => $item['title'], 'category' => $item['type']];
        }

        return $result;
    }

    /**
     * Un item gia' noto: alza seen_count e la confidence (senza mai scenderla), marca known.
     */
    private function reinforceExisting(Memory $memory, array $existing, array $session, float $confidence): void
    {
        $metadata = $this->decodeMetadata((string) ($existing['metadata_json'] ?? '{}'));
        $metadata['seen_count'] = (int) ($metadata['seen_count'] ?? 1) + 1;
        $metadata['last_seen_session_id'] = (int) $session['id'];
        $metadata['brain_status'] = 'known';
        $memory->updateBrainItem((int) $existing['id'], [
            'confidence' => max((float) ($existing['confidence'] ?? 0.6), $confidence),
            'metadata' => $metadata,
        ]);
    }

    private function markConflicted(Memory $memory, array $existing, int $sessionId): void
    {
        $metadata = $this->decodeMetadata((string) ($existing['metadata_json'] ?? '{}'));
        $metadata['brain_status'] = 'conflict';
        $metadata['conflict_seen_session_id'] = $sessionId;
        $memory->updateBrainMetadata((int) $existing['id'], $metadata);
    }

    /**
     * Estratto compatto delle memorie brain gia' presenti, per il confronto AI.
     *
     * @return array<int, array{id: int, category: string, title: string}>
     */
    private function existingBrief(array $project): array
    {
        $categories = ['decision', 'knowledge', 'problem', 'todo', 'hypothesis_confirmed', 'hypothesis_rejected', 'pattern', 'change', 'api', 'endpoint', 'database_table'];
        $rows = (new Memory())->recentBrainByCategories((int) $project['id'], $categories, 60);

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'category' => (string) ($row['brain_category'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
            ];
        }, $rows);
    }

    private function analyzeSessionByKeywords(array $project, array $session, array $messages): array
    {
        $items = $this->extractItems($messages);
        $memory = new Memory();
        $result = ['new' => 0, 'known' => 0, 'conflicts' => 0, 'items' => []];

        foreach ($items as $item) {
            $canonicalKey = $this->canonicalKey($item['category'], $item['title']);
            $existing = $memory->findByCanonicalKey((int) $project['id'], $canonicalKey);
            if ($existing) {
                $metadata = $this->decodeMetadata((string) ($existing['metadata_json'] ?? '{}'));
                $metadata['seen_count'] = (int) ($metadata['seen_count'] ?? 1) + 1;
                $metadata['last_seen_session_id'] = (int) $session['id'];
                $metadata['brain_status'] = 'known';
                $memory->updateBrainMetadata((int) $existing['id'], $metadata);
                $result['known']++;
                $result['items'][] = ['status' => 'known', 'title' => $item['title'], 'category' => $item['category']];
                continue;
            }

            $conflict = $this->findConflict((int) $project['id'], $item);
            $metadata = [
                'brain_status' => $conflict ? 'conflict' : 'new',
                'session_title' => (string) $session['title'],
                'subject_key' => $item['subject_key'],
                'polarity' => $item['polarity'],
            ];

            if ($conflict) {
                $metadata['conflicts_with'] = (int) $conflict['id'];
                $result['conflicts']++;
            } else {
                $result['new']++;
            }

            $memory->createBrainItem([
                'project_id' => (int) $project['id'],
                'session_id' => (int) $session['id'],
                'memory_type' => $item['type'],
                'title' => $item['title'],
                'content' => $item['content'],
                'tags' => 'project-brain,' . $item['category'],
                'importance' => $item['importance'],
                'brain_category' => $item['category'],
                'canonical_key' => $canonicalKey,
                'confidence' => $item['confidence'],
                'metadata' => $metadata,
            ]);

            $result['items'][] = [
                'status' => $metadata['brain_status'],
                'title' => $item['title'],
                'category' => $item['category'],
            ];
        }

        return $result;
    }

    private function extractItems(array $messages): array
    {
        $items = [];
        foreach ($messages as $message) {
            $sentences = preg_split('/[\r\n]+|(?<=[.!?])\s+/', (string) $message['content']) ?: [];
            foreach ($sentences as $sentence) {
                $sentence = trim($sentence);
                if ($sentence === '' || strlen($sentence) < 12) {
                    continue;
                }

                foreach ($this->classify($sentence) as $item) {
                    $key = $item['category'] . ':' . $this->normalize($item['title']);
                    $items[$key] = $item;
                }
            }
        }

        return array_values($items);
    }

    private function classify(string $text): array
    {
        $items = [];
        $lower = mb_strtolower($text);

        if (preg_match_all('/\b(GET|POST|PUT|PATCH|DELETE)\s+([\/a-z0-9_.:{}?=&-]+)/i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $items[] = $this->item('endpoint', MemoryType::ENDPOINT, $match[1] . ' ' . rtrim($match[2], '.,;:)'), $text, 4, 0.85);
            }
        }

        if (preg_match_all('/https?:\/\/[^\s)]+/i', $text, $matches)) {
            foreach ($matches[0] as $url) {
                $items[] = $this->item('endpoint', MemoryType::ENDPOINT, rtrim($url, '.,;:)'), $text, 4, 0.8);
            }
        }

        if (preg_match_all('/\btabella\s+([A-Z0-9_]+)/i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $items[] = $this->item('database_table', MemoryType::DATABASE_TABLE, 'Tabella ' . strtoupper($match[1]), $text, 4, 0.8);
            }
        }

        if (str_contains($lower, 'api') || str_contains($lower, 'oauth')) {
            $items[] = $this->item('api', MemoryType::API, $this->shortTitle($text), $text, 4, 0.65);
        }

        if ($this->containsAny($lower, ['decisione', 'deciso', 'scegliamo', 'scelta', 'non usare', 'usare ', 'preferire'])) {
            $items[] = $this->item('decision', MemoryType::DECISION, $this->shortTitle($text), $text, 5, 0.7);
        }

        if ($this->containsAny($lower, ['ipotesi confermata', 'confermato', 'validato', 'funziona'])) {
            $items[] = $this->item('hypothesis_confirmed', MemoryType::HYPOTHESIS_CONFIRMED, $this->shortTitle($text), $text, 4, 0.65);
        }

        if ($this->containsAny($lower, ['ipotesi scartata', 'scartato', 'non funziona', 'falso'])) {
            $items[] = $this->item('hypothesis_rejected', MemoryType::HYPOTHESIS_REJECTED, $this->shortTitle($text), $text, 4, 0.65);
        }

        if ($this->containsAny($lower, ['problema', 'bug', 'errore', 'fallisce', 'restituisce'])) {
            $items[] = $this->item('problem', MemoryType::PROBLEM, $this->shortTitle($text), $text, 4, 0.7);
        }

        if ($this->containsAny($lower, ['pattern', 'sempre', 'usa sempre'])) {
            $items[] = $this->item('pattern', MemoryType::PATTERN, $this->shortTitle($text), $text, 4, 0.65);
        }

        if ($this->containsAny($lower, ['todo', 'da fare', 'rimane aperto', 'resta da'])) {
            $items[] = $this->item('todo', MemoryType::TODO, $this->shortTitle($text), $text, 3, 0.7);
        }

        if ($this->containsAny($lower, ['modificato', 'aggiunto', 'implementato', 'refactoring', 'cambiato'])) {
            $items[] = $this->item('change', MemoryType::CHANGE, $this->shortTitle($text), $text, 3, 0.6);
        }

        return $items;
    }

    private function item(string $category, string $type, string $title, string $content, int $importance, float $confidence): array
    {
        return [
            'category' => $category,
            'type' => $type,
            'title' => $title,
            'content' => mb_substr($content, 0, 500),
            'importance' => $importance,
            'confidence' => $confidence,
            'subject_key' => $this->subjectKey($content),
            'polarity' => $this->polarity($content),
        ];
    }

    private function findConflict(int $projectId, array $item): ?array
    {
        if (!in_array($item['category'], ['decision', 'hypothesis_confirmed', 'hypothesis_rejected'], true)) {
            return null;
        }

        $existing = (new Memory())->recentBrainByCategories($projectId, ['decision', 'hypothesis_confirmed', 'hypothesis_rejected'], 50);
        foreach ($existing as $row) {
            $metadata = $this->decodeMetadata((string) ($row['metadata_json'] ?? '{}'));
            if (($metadata['subject_key'] ?? '') === $item['subject_key'] && ($metadata['polarity'] ?? '') !== $item['polarity']) {
                return $row;
            }
        }

        return null;
    }

    private function canonicalKey(string $category, string $title): string
    {
        return $category . ':' . $this->normalize($title);
    }

    private function subjectKey(string $text): string
    {
        $normalized = $this->normalize($text);
        return trim(str_replace([' non ', ' no ', ' mai ', ' usare ', ' usa ', ' preferire ', ' preferiamo ', ' funziona ', ' scartato '], ' ', ' ' . $normalized . ' '));
    }

    private function polarity(string $text): string
    {
        $lower = mb_strtolower($text);
        return $this->containsAny($lower, ['non ', 'mai ', 'scartat', 'falso', 'fallisce']) ? 'negative' : 'positive';
    }

    private function shortTitle(string $text): string
    {
        return rtrim(mb_substr(trim($text), 0, 120), " \t\n\r\0\x0B.,;:");
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^a-z0-9_\/]+/i', ' ', $text) ?? '';
        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function decodeMetadata(string $json): array
    {
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }
}
