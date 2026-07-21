<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 8 / base Git read-only. Un diff unificato, come DATO non fidato.
 *
 *   - `staged`    : true = diff INDICE↔HEAD (`git diff --cached`); false = diff WORKTREE↔INDICE (unstaged);
 *   - `text`      : il diff unificato, già ripulito UTF-8. È testo da MOSTRARE, mai da eseguire;
 *   - `truncated` : l'output è stato cappato al tetto (diff PARZIALE).
 *
 * External diff e textconv sono disattivati a monte (vedi GitService/GitInvoker): il testo proviene
 * dal solo motore diff interno di git, non da programmi esterni o filtri di config.
 */
final class GitDiff
{
    public function __construct(
        public readonly bool $staged,
        public readonly string $text,
        public readonly bool $truncated,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->text === '';
    }
}
