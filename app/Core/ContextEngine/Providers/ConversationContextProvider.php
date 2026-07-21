<?php

declare(strict_types=1);

namespace App\Core\ContextEngine\Providers;

use App\Core\ContextEngine\ContextItem;
use App\Models\Conversation;

final class ConversationContextProvider extends EmptyContextProvider
{
    public function key(): string
    {
        return 'conversations';
    }

    public function label(): string
    {
        return 'Conversations';
    }

    public function priority(): int
    {
        return 50;
    }

    public function provide(array $project, string $userRequest, ?array $session = null, ?\App\Core\Execution\ExecutionState $executionState = null): array
    {
        $sessionId = (int) ($session['id'] ?? 0);
        if ($sessionId <= 0) {
            return [];
        }

        $rows = (new Conversation())->latestForSessionContext($sessionId, $this->isAuditRequest($userRequest) ? 20 : 12);
        if ($this->isAuditRequest($userRequest)) {
            $rows = array_values(array_filter($rows, fn (array $row): bool => !$this->isStaleAssistantAudit($row)));
            $rows = array_slice($rows, -8);
        }

        return array_map(function (array $row): ContextItem {
            return new ContextItem(
                source: $this->key(),
                type: 'conversation',
                title: (string) $row['role'],
                content: (string) $row['content'],
                priority: $this->priority(),
                metadata: [
                    'id' => (int) $row['id'],
                    'role' => (string) $row['role'],
                    'provider' => (string) $row['provider'],
                    'model' => (string) $row['model'],
                    'created_at' => (string) $row['created_at'],
                ],
            );
        }, $rows);
    }

    private function isAuditRequest(string $request): bool
    {
        $request = mb_strtolower($request);
        $auditWords = ['audit', 'analisi', 'analizza', 'controlla', 'verifica'];
        $systemWords = ['sistema', 'aimanager', 'progetto', 'codice', 'architettura'];

        return $this->containsAny($request, $auditWords) && $this->containsAny($request, $systemWords);
    }

    private function isStaleAssistantAudit(array $row): bool
    {
        if (($row['role'] ?? '') !== 'assistant') {
            return false;
        }

        $content = mb_strtolower((string) ($row['content'] ?? ''));
        $staleSignals = [
            '# audit ai manager',
            'nessuna ia connessa',
            'sincronizzazione stato connessione',
            'sincronizzazione dello stato',
            'provider status potrebbe essere desincronizzato',
            'basandomi sul contesto aggiornato',
            'basandomi sulla conversazione',
        ];

        return $this->containsAny($content, $staleSignals);
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
}
