<?php

declare(strict_types=1);

namespace App\Core\ContextEngine\Providers;

use App\Core\ContextEngine\ContextItem;
use App\Core\ContextEngine\ContextProviderInterface;
use App\Models\Memory;

abstract class AbstractMemoryContextProvider implements ContextProviderInterface
{
    public function provide(array $project, string $userRequest, ?array $session = null, ?\App\Core\Execution\ExecutionState $executionState = null): array
    {
        $projectId = (int) ($project['id'] ?? 0);
        if ($projectId <= 0) {
            return [];
        }

        // La memoria residente di progetto (es. Knowledge: documenti caricati) deve
        // essere SEMPRE presente nel contesto, non filtrata per keyword sul messaggio
        // corrente: una domanda in linguaggio naturale non farebbe quasi mai match
        // sul LIKE della frase intera e il documento verrebbe escluso in silenzio.
        $rows = ($this->alwaysResident() || trim($userRequest) === '')
            ? (new Memory())->forProjectByType($projectId, $this->memoryType(), 10)
            : (new Memory())->searchForProjectByType($projectId, $this->memoryType(), $userRequest, 10);

        return array_map(function (array $row): ContextItem {
            return new ContextItem(
                source: $this->key(),
                type: $this->memoryType(),
                title: (string) $row['title'],
                content: (string) $row['content'],
                priority: $this->priority(),
                metadata: [
                    'id' => (int) $row['id'],
                    'tags' => (string) ($row['tags'] ?? ''),
                    'importance' => (int) ($row['importance'] ?? 0),
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
                ],
            );
        }, $rows);
    }

    abstract protected function memoryType(): string;

    /**
     * I tipi di memoria "residenti" di progetto (stabili/promossi, es. Knowledge)
     * vanno sempre inclusi nel contesto, a prescindere dal messaggio corrente.
     * Gli altri tipi restano filtrati per pertinenza (override a true dove serve).
     */
    protected function alwaysResident(): bool
    {
        return false;
    }
}
