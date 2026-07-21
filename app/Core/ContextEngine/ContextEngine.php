<?php

declare(strict_types=1);

namespace App\Core\ContextEngine;

use App\Core\ContextEngine\Providers\ArtifactContextProvider;
use App\Core\ContextEngine\Providers\BrainResidentContextProvider;
use App\Core\ContextEngine\Providers\ConversationContextProvider;
use App\Core\ContextEngine\Providers\DecisionContextProvider;
use App\Core\ContextEngine\Providers\DocumentContextProvider;
use App\Core\ContextEngine\Providers\KnowledgeContextProvider;
use App\Core\ContextEngine\Providers\NoteContextProvider;
use App\Core\ContextEngine\Providers\SessionContextProvider;
use App\Core\Execution\ExecutionState;

final class ContextEngine implements ContextBuilderInterface
{
    /**
     * @param ContextProviderInterface[] $providers
     */
    public function __construct(private readonly array $providers)
    {
    }

    public static function default(): self
    {
        return new self([
            new SessionContextProvider(),
            new BrainResidentContextProvider(),
            new DecisionContextProvider(),
            new KnowledgeContextProvider(),
            new DocumentContextProvider(),
            new NoteContextProvider(),
            new ArtifactContextProvider(),
            new ConversationContextProvider(),
        ]);
    }

    public function build(array $project, string $userRequest, ?array $session = null, ?ExecutionState $executionState = null): ContextInterface
    {
        $items = [
            new ContextItem(
                source: 'system',
                type: 'runtime',
                title: 'Data e ora correnti',
                content: 'Data corrente: ' . date('d/m/Y') . '. Ora corrente: ' . date('H:i') . '. Usa questa data come riferimento temporale reale della sessione.',
                priority: 300,
                metadata: ['generated_at' => date('c')],
            ),
        ];
        if ($executionState) {
            $items[] = new ContextItem(
                source: 'execution_state',
                type: 'continuation',
                title: 'Execution State',
                content: $executionState->compactPrompt($userRequest),
                priority: 200,
                metadata: ['execution_state_id' => $executionState->id],
            );
        }
        foreach ($this->providers as $provider) {
            array_push($items, ...$provider->provide($project, $userRequest, $session, $executionState));
        }

        usort($items, function (ContextItem $left, ContextItem $right): int {
            return $right->priority <=> $left->priority;
        });

        // Dedup delle memorie: uno stesso fatto puo' arrivare sia come residente sia dal
        // provider per keyword. Dopo l'ordinamento per priorita', tieni la prima copia
        // (quella a priorita' piu' alta) e scarta i doppioni con lo stesso id di memoria.
        $seenMemoryIds = [];
        $items = array_values(array_filter($items, static function (ContextItem $item) use (&$seenMemoryIds): bool {
            $memoryId = (int) ($item->metadata['id'] ?? 0);
            if ($memoryId <= 0) {
                return true;
            }
            if (isset($seenMemoryIds[$memoryId])) {
                return false;
            }
            $seenMemoryIds[$memoryId] = true;
            return true;
        }));

        return new Context($project, $userRequest, $items, $session, $executionState);
    }
}
