<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Orchestrator\OrchestratorStoreInterface;
use App\Models\AIRequestLog;
use App\Models\ChatAttachment;
use App\Models\Conversation;
use App\Models\Session;
use App\Services\AIProviderResult;

final class OrchestratorRepository implements OrchestratorStoreInterface
{
    public function __construct(private readonly Database $db)
    {
    }

    public function saveUserMessage(array $state, string $provider, string $model): int
    {
        return (new Conversation())->create([
            'project_id' => (int) $state['project']['id'],
            'session_id' => (int) $state['session']['id'],
            'role' => 'user',
            'content' => $state['request'],
            'provider' => $provider,
            'model' => $model,
        ]);
    }

    public function attachUserMessageAttachments(array $state, int $conversationId): void
    {
        $attachments = is_array($state['attachments'] ?? null) ? $state['attachments'] : [];
        $attachmentIds = array_map(static fn (array $attachment): int => (int) ($attachment['id'] ?? 0), $attachments);
        (new ChatAttachment())->attachToConversation($attachmentIds, $conversationId);
    }

    public function attachToConversation(array $attachmentIds, int $conversationId): void
    {
        (new ChatAttachment())->attachToConversation($attachmentIds, $conversationId);
    }

    public function saveAssistantMessage(array $state, string $provider, string $model, string $content, int $tokensInput, int $tokensOutput): int
    {
        return (new Conversation())->create([
            'project_id' => (int) $state['project']['id'],
            'session_id' => (int) $state['session']['id'],
            'role' => 'assistant',
            'content' => $content,
            'provider' => $provider,
            'model' => $model,
            'tokens_input' => $tokensInput,
            'tokens_output' => $tokensOutput,
        ]);
    }

    public function logRequest(array $state, ?int $conversationId, string $provider, string $model): void
    {
        $result = $state['ai_result'];
        (new AIRequestLog())->create([
            'project_id' => (int) $state['project']['id'],
            'session_id' => (int) $state['session']['id'],
            'execution_state_id' => (int) $state['execution_state']->id,
            'conversation_id' => $conversationId,
            'provider' => $provider,
            'model' => $model,
            'endpoint' => $result instanceof AIProviderResult ? $result->endpoint : '',
            'response_time_ms' => (int) $state['response_time_ms'],
            'tokens_input' => $result instanceof AIProviderResult ? $result->tokensInput : 0,
            'tokens_output' => $result instanceof AIProviderResult ? $result->tokensOutput : 0,
            'estimated_cost' => $result instanceof AIProviderResult ? $result->estimatedCost : 0,
            'choice_reason' => $result instanceof AIProviderResult ? $result->choiceReason : '',
            'fallback_used' => $result instanceof AIProviderResult && $result->fallbackUsed ? 1 : 0,
            'error' => $result instanceof AIProviderResult ? $result->error : '',
        ]);
    }

    public function touchSession(int $sessionId): void
    {
        (new Session())->touch($sessionId);
    }
}
