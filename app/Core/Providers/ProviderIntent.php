<?php

declare(strict_types=1);

namespace App\Core\Providers;

final class ProviderIntent
{
    public function __construct(
        public readonly string $taskType,
        public readonly int $complexity,
        public readonly int $latency,
        public readonly int $cost,
        public readonly int $contextSize,
        public readonly bool $requiresTools = false,
        public readonly bool $requiresFiles = false,
        public readonly bool $requiresReasoning = false,
        public readonly bool $experimental = false,
        public readonly bool $requiresVision = false,
        public readonly bool $requiresWeb = false,
        public readonly bool $requiresKnowledge = false,
        public readonly bool $requiresDeepReasoning = false,
    ) {
    }

    public static function neutral(): self
    {
        return new self('general', 2, 3, 3, 1);
    }

    /**
     * Richiesta "pesante": lavoro lungo/complesso dove conviene il modello locale
     * ragionante (qwen) e dove lo streaming maschera la latenza. Le richieste brevi
     * vanno invece sui provider cloud veloci.
     */
    public function isHeavy(): bool
    {
        return $this->complexity >= 4
            || $this->contextSize >= 4
            || $this->requiresReasoning
            || $this->requiresFiles
            || in_array($this->taskType, ['analysis', 'code'], true);
    }

    public function toArray(): array
    {
        return [
            'task_type' => $this->taskType,
            'complexity' => $this->complexity,
            'latency' => $this->latency,
            'cost' => $this->cost,
            'context_size' => $this->contextSize,
            'requires_tools' => $this->requiresTools,
            'requires_files' => $this->requiresFiles,
            'requires_reasoning' => $this->requiresReasoning,
            'experimental' => $this->experimental,
            'requires_vision' => $this->requiresVision,
            'requires_web' => $this->requiresWeb,
            'requires_knowledge' => $this->requiresKnowledge,
            'requires_deep_reasoning' => $this->requiresDeepReasoning,
        ];
    }
}
