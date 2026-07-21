<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 8 / base Git read-only. UNA voce dello stato del worktree, derivata da
 * `git status --porcelain=v2 -z`.
 *
 * `path`/`origPath` sono DATI NON FIDATI: nomi file scelti dal contenuto del repo, mai usati per
 * accedere al filesystem (nessuna risoluzione, nessuna lettura). Servono solo alla presentazione.
 *
 * Lo stato è separato in due colonne, esattamente come git:
 *   - `index`    : stato lato INDICE (staged) — 1 carattere XY[0];
 *   - `worktree` : stato lato WORKTREE (unstaged) — 1 carattere XY[1].
 * Per gli untracked entrambe valgono '?'; per gli unmerged `unmerged=true` e le colonne riportano XY.
 */
final class GitStatusEntry
{
    public function __construct(
        public readonly string $path,
        public readonly ?string $origPath,
        public readonly string $index,
        public readonly string $worktree,
        public readonly bool $untracked,
        public readonly bool $unmerged,
    ) {
    }

    /** C'è una modifica messa in stage (colonna indice diversa da '.' e non untracked)? */
    public function isStaged(): bool
    {
        return !$this->untracked && $this->index !== '.' && $this->index !== ' ';
    }

    /** C'è una modifica non in stage (colonna worktree "attiva", oppure untracked)? */
    public function isUnstaged(): bool
    {
        return $this->untracked || ($this->worktree !== '.' && $this->worktree !== ' ');
    }
}
