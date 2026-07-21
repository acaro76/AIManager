<?php

declare(strict_types=1);

namespace App\Core\Orchestrator;

final class ExecutionPlan
{
    /** @var ExecutionStep[] */
    private array $steps = [];

    public function add(ExecutionStep $step): self
    {
        $this->steps[] = $step;
        return $this;
    }

    /**
     * @return ExecutionStep[]
     */
    public function steps(): array
    {
        return $this->steps;
    }

    public function hasFailures(): bool
    {
        foreach ($this->steps as $step) {
            if ($step->status === ExecutionStep::FAILED) {
                return true;
            }
        }

        return false;
    }

    public function toArray(): array
    {
        return array_map(fn (ExecutionStep $step): array => $step->toArray(), $this->steps);
    }
}
