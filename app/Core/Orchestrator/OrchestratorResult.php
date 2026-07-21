<?php

declare(strict_types=1);

namespace App\Core\Orchestrator;

final class OrchestratorResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly ExecutionPlan $plan,
        public readonly array $data = [],
    ) {
    }
}
