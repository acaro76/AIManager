<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 8 / staging selettivo (proposta). Piano IMMUTABILE e revisionabile dei soli percorsi
 * che POTREBBERO essere messi in stage. NON esegue nulla: non tocca indice, HEAD o working tree; non
 * è persistito né confermato in questa tranche.
 *
 * Distingue le tre categorie richieste:
 *   - `selected`           : voci ammesse E scelte (path, origPath opzionale per rename/copy, stato sintetico);
 *   - `allowedNotSelected` : voci ammissibili allo staging ma NON scelte (path + origPath opzionale);
 *   - `excludedCount`      : sole modifiche escluse (sensibili/runtime), conteggio ANONIMO (mai nomi).
 *
 * `fingerprint` è un'impronta READ-ONLY, deterministica, dello STATO EFFETTIVO (contenuto unstaged e
 * staged + struttura dei percorsi): cambia se il contenuto unstaged/index muta, se un file passa
 * tracked↔untracked o se cambia un rename/copy — anche a parità di percorsi selezionati. Non contiene
 * mai diff o contenuti: soltanto hash. `digest` lega il piano allo scope E alla fingerprint.
 */
final class GitStagePlan
{
    /**
     * @param list<array{path:string,orig_path:?string,status:string}> $selected
     * @param list<array{path:string,orig_path:?string}>               $allowedNotSelected
     */
    private function __construct(
        public readonly int $workspaceId,
        public readonly array $selected,
        public readonly array $allowedNotSelected,
        public readonly int $excludedCount,
        public readonly string $fingerprint,
        public readonly string $digest,
    ) {
    }

    /**
     * Costruisce il piano ordinando in modo DETERMINISTICO (per percorso) e calcolando il digest, che
     * include la `fingerprint` dello stato reale. La selezione è già validata dal chiamante
     * (CodeGitTool): qui si canonicalizza soltanto.
     *
     * @param list<array{path:string,orig_path:?string,status:string}> $selected
     * @param list<array{path:string,orig_path:?string}>               $allowedNotSelected
     */
    public static function create(
        int $workspaceId,
        array $selected,
        array $allowedNotSelected,
        int $excludedCount,
        string $fingerprint,
        string $rootPath,
    ): self {
        usort($selected, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        usort($allowedNotSelected, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        $selected = array_values($selected);
        $allowedNotSelected = array_values($allowedNotSelected);

        $digest = hash('sha256', (string) json_encode([
            'workspace' => $workspaceId,
            'root' => $rootPath,
            'selected' => array_map(
                static fn (array $e): array => [$e['path'], $e['orig_path'], $e['status']],
                $selected
            ),
            'fingerprint' => $fingerprint,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return new self($workspaceId, $selected, $allowedNotSelected, max(0, $excludedCount), $fingerprint, $digest);
    }

    /** @return list<string> percorsi selezionati (destinazione per i rename), ordine deterministico */
    public function paths(): array
    {
        return array_map(static fn (array $e): string => $e['path'], $this->selected);
    }

    public function selectedCount(): int
    {
        return count($this->selected);
    }

    public function allowedNotSelectedCount(): int
    {
        return count($this->allowedNotSelected);
    }

    /**
     * Messaggio di commit breve proposto da AIManager a partire dal piano già validato.
     * L'utente può correggerlo prima di creare la proposta di commit.
     *
     * @param list<array{path:string,orig_path:?string,status:string}> $selected
     */
    public static function suggestedCommitMessage(array $selected): string
    {
        if (count($selected) !== 1) {
            return 'Aggiorna ' . count($selected) . ' file';
        }

        $entry = $selected[0];
        $verb = match ((string) ($entry['status'] ?? '')) {
            'non tracciato' => 'Aggiungi',
            'rinominato' => 'Rinomina',
            default => 'Aggiorna',
        };

        return $verb . ' ' . (string) ($entry['path'] ?? 'file');
    }

    public function suggestedCommitMessageForPlan(): string
    {
        return self::suggestedCommitMessage($this->selected);
    }
}
