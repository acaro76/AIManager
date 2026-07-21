<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 6 / F6.6. Bind TIPIZZATO dei path del piano (correzione #4): ogni operando-path viene
 * RIVALIDATO via CodeWorkspace/PathGuard/SensitivePathPolicy e risolto ad ASSOLUTO. Un path fuori
 * root, symlink, sensibile o non regolare fa fallire l'intero bind (CodeWorkspaceException).
 *
 * Viene invocato PIÙ VOLTE: alla conferma (rivalidazione) e — soprattutto — dal CommandRunner
 * IMMEDIATAMENTE PRIMA di proc_open («ultimo bind»). Resta però un TOCTOU residuo (correzione #3):
 * tra questo bind e l'apertura del file da parte dell'utility esiste una finestra minima non
 * chiudibile senza una sandbox del sistema operativo. Non pretendiamo di eliminarla: la
 * riduciamo al minimo e la documentiamo. Un symlink-swap PRIMA della conferma è comunque intercettato.
 */
final class CommandPathBinder
{
    /**
     * @param list<string> $relPaths percorsi relativi validati nella forma
     * @return list<string> percorsi assoluti, stesso ordine
     * @throws CodeWorkspaceException se un path non è (più) dentro il confine o è sensibile/non regolare
     */
    public function bind(CodeWorkspace $workspace, array $relPaths): array
    {
        $abs = [];
        foreach ($relPaths as $rel) {
            if ($workspace->isSensitive($rel)) {
                throw new CodeWorkspaceException('Percorso sensibile: comando negato.');
            }
            // resolve() applica PathGuard (confine, no-symlink per componente, revoca) e lancia
            // CodeWorkspaceException su qualunque violazione.
            $path = $workspace->resolve($rel);
            if (is_link($path) || !is_file($path) || !is_readable($path)) {
                throw new CodeWorkspaceException('Percorso non regolare o non leggibile: comando negato.');
            }
            $abs[] = $path;
        }

        return array_values($abs);
    }
}
