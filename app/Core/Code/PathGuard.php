<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Reparto Code — Fase 0 / F0.2. Confine fisico del workspace: risolve un percorso
 * relativo DENTRO una radice, senza mai seguire symlink (regola trasversale 1).
 *
 * Nota di sicurezza (Codex): realpath() *risolve* i symlink, quindi da solo non basta.
 * Qui i `..` si normalizzano lessicalmente (senza toccare il filesystem) e poi si
 * verifica con is_link()/lstat ogni componente del percorso finale prima di accedervi.
 */
final class PathGuard
{
    private string $root;

    public function __construct(string $root)
    {
        if ($root === '') {
            throw new CodeWorkspaceException('Radice del workspace non valida.');
        }
        // La radice non deve essere un symlink.
        if (is_link($root)) {
            throw new CodeWorkspaceException('La radice del workspace non può essere un symlink.');
        }
        $real = realpath($root);
        if ($real === false || !is_dir($real) || !is_readable($real)) {
            // Radice inesistente / spostata / eliminata / non directory / non leggibile
            // (altrimenti scandir fallirebbe pur sembrando "attiva"): fail closed.
            throw new CodeWorkspaceException('La cartella autorizzata non è più valida.');
        }
        $real = rtrim($real, DIRECTORY_SEPARATOR);
        // La root del filesystem ('/') dopo il rtrim diventa '': mai ammessa come radice.
        if ($real === '') {
            throw new CodeWorkspaceException('La radice del workspace non può essere la root del filesystem.');
        }
        $this->root = $real;
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Percorso assoluto sicuro per $relative dentro la radice, oppure lancia.
     * Non richiede che il file esista (serve anche per scritture future): l'esistenza
     * la verifica chi legge (CodeWorkspace::read).
     */
    public function resolve(string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || $relative === '.') {
            return $this->root;
        }
        if (str_starts_with($relative, '/')) {
            throw new CodeWorkspaceException('Percorso assoluto non ammesso.');
        }

        // Normalizzazione lessicale: risolve '.' e '..' senza filesystem, e rifiuta
        // qualsiasi tentativo di uscire dalla radice.
        $stack = [];
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($stack === []) {
                    throw new CodeWorkspaceException('Il percorso esce dalla radice del workspace.');
                }
                array_pop($stack);
                continue;
            }
            $stack[] = $segment;
        }

        // Verifica ogni componente del percorso finale: nessun symlink ammesso.
        $current = $this->root;
        foreach ($stack as $segment) {
            $current .= '/' . $segment;
            if (is_link($current)) {
                throw new CodeWorkspaceException('Symlink non ammesso nel percorso.');
            }
        }

        return $current;
    }
}
