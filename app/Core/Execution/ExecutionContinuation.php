<?php

declare(strict_types=1);

namespace App\Core\Execution;

final class ExecutionContinuation
{
    public function __construct(private readonly ExecutionState $state)
    {
    }

    public function prompt(string $request): string
    {
        $lines = [
            'Contesto sintetico per continuare il lavoro.',
            'Usa queste informazioni solo come memoria operativa: non citarne i nomi tecnici nella risposta.',
            'Obiettivo della sessione: ' . $this->state->objective,
        ];

        if (trim($request) !== '') {
            $lines[] = 'Richiesta corrente: ' . trim($request);
        }

        return implode("\n", $lines);
    }
}
