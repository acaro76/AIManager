<?php

declare(strict_types=1);

namespace App\Core\ContextEngine\Providers;

use App\Core\App;
use App\Core\Configuration\ConfigurationManager;
use App\Core\ContextEngine\ContextItem;
use App\Core\ContextEngine\ContextProviderInterface;
use App\Core\Execution\ExecutionState;
use App\Models\Memory;

/**
 * Promozione a "residente" dei fatti del Project Brain (sez. 18.4, Fase 2/3).
 *
 * I fatti consolidati dall'AI (decisioni, problemi, TODO, pattern, ecc.) sopra una
 * soglia di confidenza rientrano SEMPRE nel contesto, a prescindere dalle parole del
 * messaggio corrente. Chiude il ciclo del consolidatore: senza questo, un fatto salvato
 * tornava all'AI solo se il messaggio conteneva per caso le stesse parole (ricerca LIKE),
 * e restava invisibile alla prima parafrasi. Stessa logica gia' usata per la Knowledge
 * residente (documenti caricati), qui applicata alla memoria appresa.
 *
 * Silenzioso e senza UI: e' idraulica di contesto, l'utente non vede/tocca nulla.
 */
final class BrainResidentContextProvider implements ContextProviderInterface
{
    public function key(): string
    {
        return 'brain_resident';
    }

    public function label(): string
    {
        return 'Memoria di progetto';
    }

    public function priority(): int
    {
        // Sopra i provider per keyword (Decision=90, Knowledge=80): in caso di doppione
        // (stesso id di memoria) la dedup del ContextEngine tiene questa copia residente.
        return 95;
    }

    public function provide(array $project, string $userRequest, ?array $session = null, ?ExecutionState $executionState = null): array
    {
        $projectId = (int) ($project['id'] ?? 0);
        if ($projectId <= 0) {
            return [];
        }

        $config = ConfigurationManager::fromRoot(App::get()->root);
        if ($config->get('BRAIN_RESIDENT_ENABLED', '1') === '0') {
            return [];
        }

        $minConfidence = (float) $config->get('BRAIN_RESIDENT_MIN_CONFIDENCE', '0.75');
        $minConfidence = max(0.0, min(1.0, $minConfidence));
        $limit = max(1, min(50, (int) $config->get('BRAIN_RESIDENT_MAX_ITEMS', '12')));

        $rows = (new Memory())->residentBrainItems($projectId, $minConfidence, $limit);

        $items = [];
        foreach ($rows as $row) {
            $metadata = json_decode((string) ($row['metadata_json'] ?? '{}'), true);
            $isConflict = is_array($metadata) && ($metadata['brain_status'] ?? '') === 'conflict';
            $title = (string) $row['title'];
            // Un conflitto va segnalato all'AI, non nascosto: due fatti contraddittori sullo
            // stesso soggetto devono essere entrambi visibili con l'avviso.
            if ($isConflict) {
                $title = '[CONFLITTO] ' . $title;
            }

            $items[] = new ContextItem(
                source: $this->key(),
                type: (string) ($row['brain_category'] ?? $row['memory_type'] ?? 'knowledge'),
                title: $title,
                content: (string) $row['content'],
                priority: $this->priority(),
                metadata: [
                    'id' => (int) $row['id'],
                    'confidence' => (float) ($row['confidence'] ?? 0),
                    'importance' => (int) ($row['importance'] ?? 0),
                ],
            );
        }

        return $items;
    }
}
