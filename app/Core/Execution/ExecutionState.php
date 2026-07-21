<?php

declare(strict_types=1);

namespace App\Core\Execution;

final class ExecutionState
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $projectId,
        public readonly int $sessionId,
        public string $objective,
        public string $currentState,
        public array $executionPlan = [],
        public array $completedTasks = [],
        public array $remainingTasks = [],
        public string $currentProvider = '',
        public array $previousProviders = [],
        public array $providerChangeReasons = [],
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['project_id'],
            (int) $row['session_id'],
            (string) $row['objective'],
            (string) $row['current_state'],
            self::json((string) $row['execution_plan_json']),
            self::json((string) $row['completed_tasks_json']),
            self::json((string) $row['remaining_tasks_json']),
            (string) ($row['current_provider'] ?? ''),
            self::json((string) $row['previous_providers_json']),
            self::json((string) $row['provider_change_reasons_json']),
        );
    }

    public function toPersistence(): array
    {
        return [
            'objective' => $this->objective,
            'current_state' => $this->currentState,
            'execution_plan_json' => json_encode($this->executionPlan, JSON_UNESCAPED_UNICODE),
            'completed_tasks_json' => json_encode(array_values(array_unique($this->completedTasks)), JSON_UNESCAPED_UNICODE),
            'remaining_tasks_json' => json_encode(array_values(array_unique($this->remainingTasks)), JSON_UNESCAPED_UNICODE),
            'current_provider' => $this->currentProvider,
            'previous_providers_json' => json_encode($this->previousProviders, JSON_UNESCAPED_UNICODE),
            'provider_change_reasons_json' => json_encode($this->providerChangeReasons, JSON_UNESCAPED_UNICODE),
        ];
    }

    public function recordProvider(string $provider, string $reason): void
    {
        if ($provider === '') {
            return;
        }

        if ($this->currentProvider !== '' && $this->currentProvider !== $provider) {
            $this->previousProviders[] = $this->currentProvider;
            $this->providerChangeReasons[] = [
                'from' => $this->currentProvider,
                'to' => $provider,
                'reason' => $reason,
                'at' => date('c'),
            ];
        }

        $this->currentProvider = $provider;
    }

    public function compactPrompt(string $request): string
    {
        return (new ExecutionContinuation($this))->prompt($request);
    }

    private static function json(string $json): array
    {
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }
}
