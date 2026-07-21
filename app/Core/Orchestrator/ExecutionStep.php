<?php

declare(strict_types=1);

namespace App\Core\Orchestrator;

final class ExecutionStep
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const COMPLETED = 'completed';
    public const SKIPPED = 'skipped';
    public const FAILED = 'failed';

    public string $status = self::PENDING;
    public int $durationMs = 0;
    public array $errors = [];
    public array $output = [];

    public function __construct(
        public readonly int $number,
        public readonly string $type,
        public readonly string $label,
        public readonly bool $replaceable = true,
        public readonly array $metadata = [],
    ) {
    }

    public function start(): float
    {
        $this->status = self::RUNNING;
        return microtime(true);
    }

    public function complete(float $started, array $output = []): void
    {
        $this->status = self::COMPLETED;
        $this->durationMs = (int) round((microtime(true) - $started) * 1000);
        $this->output = $output;
    }

    public function skip(float $started, array $output = []): void
    {
        $this->status = self::SKIPPED;
        $this->durationMs = (int) round((microtime(true) - $started) * 1000);
        $this->output = $output;
    }

    public function fail(float $started, string $error, array $output = []): void
    {
        $this->status = self::FAILED;
        $this->durationMs = (int) round((microtime(true) - $started) * 1000);
        $this->errors[] = $error;
        $this->output = $output;
    }

    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'type' => $this->type,
            'label' => $this->label,
            'status' => $this->status,
            'duration_ms' => $this->durationMs,
            'errors' => $this->errors,
            'output' => $this->output,
            'replaceable' => $this->replaceable,
            'metadata' => $this->metadata,
        ];
    }
}
