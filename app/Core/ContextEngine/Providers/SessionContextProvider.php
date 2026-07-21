<?php

declare(strict_types=1);

namespace App\Core\ContextEngine\Providers;

use App\Core\ContextEngine\ContextItem;
use App\Core\ContextEngine\ContextProviderInterface;

final class SessionContextProvider implements ContextProviderInterface
{
    public function key(): string
    {
        return 'session';
    }

    public function label(): string
    {
        return 'Session';
    }

    public function priority(): int
    {
        return 120;
    }

    public function provide(array $project, string $userRequest, ?array $session = null, ?\App\Core\Execution\ExecutionState $executionState = null): array
    {
        if (!$session) {
            return [];
        }

        return [
            new ContextItem(
                source: $this->key(),
                type: 'session',
                title: (string) $session['title'],
                content: (string) ($session['description'] ?? ''),
                priority: $this->priority(),
                metadata: [
                    'id' => (int) $session['id'],
                    'status' => (string) $session['status'],
                    'last_activity' => (string) $session['last_activity'],
                ],
            ),
        ];
    }
}
