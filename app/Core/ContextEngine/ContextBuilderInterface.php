<?php

declare(strict_types=1);

namespace App\Core\ContextEngine;

use App\Core\Execution\ExecutionState;

interface ContextBuilderInterface
{
    public function build(array $project, string $userRequest, ?array $session = null, ?ExecutionState $executionState = null): ContextInterface;
}
