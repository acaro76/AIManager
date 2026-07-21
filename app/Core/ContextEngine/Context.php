<?php

declare(strict_types=1);

namespace App\Core\ContextEngine;

use App\Core\Execution\ExecutionState;

final class Context implements ContextInterface
{
    /**
     * @param ContextItem[] $items
     */
    /**
     * @param array<int, array{role: string, content: string}> $history
     */
    public function __construct(
        private readonly array $project,
        private readonly string $userRequest,
        private readonly array $items,
        private readonly ?array $session = null,
        private readonly ?ExecutionState $executionState = null,
        private readonly array $history = [],
    ) {
    }

    public function project(): array
    {
        return $this->project;
    }

    public function userRequest(): string
    {
        return $this->userRequest;
    }

    public function session(): ?array
    {
        return $this->session;
    }

    public function executionState(): ?ExecutionState
    {
        return $this->executionState;
    }

    public function items(): array
    {
        return $this->items;
    }

    public function history(): array
    {
        return $this->history;
    }

    public function toArray(): array
    {
        return [
            'project' => [
                'id' => $this->project['id'] ?? null,
                'name' => $this->project['name'] ?? '',
                'status' => $this->project['status'] ?? '',
            ],
            'session' => $this->session ? [
                'id' => $this->session['id'] ?? null,
                'title' => $this->session['title'] ?? '',
                'status' => $this->session['status'] ?? '',
            ] : null,
            'execution_state' => $this->executionState ? [
                'id' => $this->executionState->id,
                'objective' => $this->executionState->objective,
                'current_state' => $this->executionState->currentState,
                'current_provider' => $this->executionState->currentProvider,
            ] : null,
            'user_request' => $this->userRequest,
            'items' => array_map(fn (ContextItem $item): array => $item->toArray(), $this->items),
        ];
    }

    public function withAdditionalItems(array $additionalItems): self
    {
        return new self(
            $this->project,
            $this->userRequest,
            array_merge($additionalItems, $this->items),
            $this->session,
            $this->executionState,
            $this->history,
        );
    }

    /**
     * @param ContextItem[] $items
     */
    public function withItems(array $items): self
    {
        return new self(
            $this->project,
            $this->userRequest,
            $items,
            $this->session,
            $this->executionState,
            $this->history,
        );
    }
}
