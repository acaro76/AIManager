<?php

declare(strict_types=1);

namespace App\Core\Code;

use App\Core\ContextEngine\ContextItem;
use App\Core\ContextEngine\SystemPromptContextInterface;
use App\Core\Execution\ExecutionState;

/**
 * Code — Fase 1 / F1.4. Il contesto della chat Code: implementa SystemPromptContextInterface,
 * quindi porta il proprio system prompt DEDICATO e non passa dal ramo "progetto LLM" di
 * AbstractProvider.
 *
 * È un value object PURO: costruito da CodeChatService, non interroga nessuna tabella (né
 * LLM né Code). `project()`/`session()` restituiscono i dati MINIMI richiesti dal contratto,
 * chiaramente Code-specifici (nessun `is_system`, nessun id di progetto LLM), e
 * `executionState()` è sempre `null`: Code non ha stato di esecuzione.
 */
final class CodeContext implements SystemPromptContextInterface
{
    /**
     * @param ContextItem[] $items contesto Code già impacchettato (serve anche alla stima token)
     * @param array<int, array{role: string, content: string}> $history turni da code_conversations
     */
    public function __construct(
        private readonly string $systemPrompt,
        private readonly string $userRequest,
        private readonly array $items,
        private readonly array $history,
        private readonly int $workspaceId,
        private readonly int $codeSessionId,
        private readonly string $workspaceName,
    ) {
    }

    public function systemPrompt(): string
    {
        return $this->systemPrompt;
    }

    /** Dati minimi Code-specifici: NON è un progetto LLM e non deriva da alcuna query. */
    public function project(): array
    {
        return [
            'surface' => 'code',
            'workspace_id' => $this->workspaceId,
            'name' => $this->workspaceName,
        ];
    }

    /** Sessione Code (code_sessions), mai una sessione LLM. */
    public function session(): ?array
    {
        return [
            'surface' => 'code',
            'code_session_id' => $this->codeSessionId,
        ];
    }

    /** Code non ha stato di esecuzione: nessun ExecutionState, nemmeno fittizio. */
    public function executionState(): ?ExecutionState
    {
        return null;
    }

    public function userRequest(): string
    {
        return $this->userRequest;
    }

    /** @return ContextItem[] */
    public function items(): array
    {
        return $this->items;
    }

    /** @return array<int, array{role: string, content: string}> */
    public function history(): array
    {
        return $this->history;
    }

    public function toArray(): array
    {
        return [
            'surface' => 'code',
            'workspace_id' => $this->workspaceId,
            'code_session_id' => $this->codeSessionId,
            'user_request' => $this->userRequest,
            'items' => array_map(static fn (ContextItem $i): array => $i->toArray(), $this->items),
            'history' => $this->history,
        ];
    }
}
