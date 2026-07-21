<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 8 / base Git read-only. Stato STRUTTURATO del worktree.
 *
 * Metadati del ramo (dall'header di `git status --porcelain=v2 --branch`):
 *   - `branch`   : nome del ramo, o null se `detached`/iniziale;
 *   - `upstream` : ramo di tracciamento, o null;
 *   - `ahead`/`behind` : distanza dall'upstream (0 se assente);
 *   - `detached` : HEAD staccato;
 *   - `initial`  : repository senza commit (HEAD iniziale, nessun oid).
 *
 * `entries` sono le voci modificate/untracked/unmerged (vedi GitStatusEntry), con i nomi trattati come
 * DATI. `truncated` segnala che l'output di git è stato cappato: lo stato potrebbe essere PARZIALE.
 *
 * `excludedCount` è il numero AGGREGATO di modifiche scartate perché sensibili o runtime: un metadato
 * di sola conta, SENZA nomi né contenuti. Evita il falso «worktree pulito» quando le uniche modifiche
 * presenti sono escluse: il repository NON è pulito, pur non esponendo cosa è cambiato.
 */
final class GitStatus
{
    /**
     * @param list<GitStatusEntry> $entries
     */
    public function __construct(
        public readonly ?string $branch,
        public readonly ?string $upstream,
        public readonly int $ahead,
        public readonly int $behind,
        public readonly bool $detached,
        public readonly bool $initial,
        public readonly array $entries,
        public readonly bool $truncated,
        public readonly int $excludedCount = 0,
    ) {
    }

    /** Ci sono modifiche escluse (sensibili/runtime), senza esporne nomi o contenuti? */
    public function hasExcludedChanges(): bool
    {
        return $this->excludedCount > 0;
    }

    /**
     * Worktree pulito: nessuna voce ammessa, NESSUNA modifica esclusa e output non troncato. Anche
     * una sola modifica esclusa (es. solo `.env` o solo `storage/`) rende lo stato NON pulito.
     */
    public function isClean(): bool
    {
        return $this->entries === [] && $this->excludedCount === 0 && !$this->truncated;
    }

    /** @return list<GitStatusEntry> */
    public function staged(): array
    {
        return array_values(array_filter($this->entries, static fn (GitStatusEntry $e): bool => $e->isStaged()));
    }

    /** @return list<GitStatusEntry> */
    public function unstaged(): array
    {
        return array_values(array_filter($this->entries, static fn (GitStatusEntry $e): bool => $e->isUnstaged()));
    }
}
