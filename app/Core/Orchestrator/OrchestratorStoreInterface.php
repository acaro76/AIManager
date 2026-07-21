<?php

declare(strict_types=1);

namespace App\Core\Orchestrator;

interface OrchestratorStoreInterface
{
    public function saveUserMessage(array $state, string $provider, string $model): int;

    public function attachUserMessageAttachments(array $state, int $conversationId): void;

    /**
     * @param int[] $attachmentIds
     */
    public function attachToConversation(array $attachmentIds, int $conversationId): void;

    public function saveAssistantMessage(array $state, string $provider, string $model, string $content, int $tokensInput, int $tokensOutput): int;

    public function logRequest(array $state, ?int $conversationId, string $provider, string $model): void;

    public function touchSession(int $sessionId): void;
}
