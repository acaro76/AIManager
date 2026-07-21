<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — invariante trasversale: tutto ciò che esce verso UI, citazioni e AUDIT è un percorso
 * RELATIVO e CANONICO alla root del workspace.
 *
 * Unico punto di verità della regola (usato da RetrievalResult e da CodeOperationLogRepository),
 * così non esistono due controlli divergenti.
 *
 * FORMA CANONICA UNICA: l'unico separatore ammesso è `/`. Un backslash viene RIFIUTATO, non
 * normalizzato: normalizzarlo sarebbe sbagliato (su POSIX `\` è un carattere legale in un nome
 * di file, quindi `a\b.php` è UN file, non due segmenti) e accettarlo così com'è darebbe due
 * rappresentazioni diverse dello stesso concetto — esattamente ciò che una forma canonica deve
 * escludere. Un nome di file con backslash è patologico: si fallisce chiuso.
 *
 * Rifiutati inoltre: stringa vuota, byte NUL, percorsi assoluti, drive Windows, segmenti vuoti
 * o `.`/`..`. I messaggi d'errore NON riportano il valore ricevuto (è controllato dal chiamante
 * e potrebbe finire nei log).
 */
final class RelativePath
{
    /** Lancia se il percorso non è relativo e canonico. */
    public static function assert(string $path): void
    {
        if ($path === '') {
            throw new \InvalidArgumentException('Percorso vuoto non ammesso.');
        }
        if (strpos($path, "\0") !== false) {
            throw new \InvalidArgumentException('Percorso con byte NUL non ammesso.');
        }
        // Unico separatore ammesso: '/'. Nessuna normalizzazione: fail closed.
        if (strpos($path, '\\') !== false) {
            throw new \InvalidArgumentException('Percorso non canonico: il backslash non è ammesso.');
        }
        if ($path[0] === '/') {
            throw new \InvalidArgumentException('Percorso non relativo.');
        }
        // Drive Windows (C:/...): comunque non relativo.
        if (preg_match('#^[A-Za-z]:/#', $path) === 1) {
            throw new \InvalidArgumentException('Percorso non relativo.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Percorso non canonico (segmento vuoto, "." o "..").');
            }
        }
    }

    public static function isValid(string $path): bool
    {
        try {
            self::assert($path);
            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }
}
