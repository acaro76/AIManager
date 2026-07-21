<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 6. Costruzione DIFENSIVA di una directory sotto una radice `storage/` fidata: OGNI
 * componente sotto la radice è verificato e RIFIUTATO se è un symlink (directory-symlink attack),
 * e il path reale finale deve restare confinato sotto la radice.
 *
 * Perché: `CommandStore` e `CommandRunner` scrivono/leggono sotto `storage/`. Se un componente
 * intermedio (base, `proposals`, `{workspace}`, `{execution}`, `home`, `tmp`) fosse un symlink,
 * la scrittura/lettura potrebbe finire FUORI dalla radice. Qui si nega, per ogni livello.
 *
 * La RADICE (`$anchor`) viene canonicalizzata con realpath (un `storage/` legittimamente symlinkato
 * verso un volume dati resta ammesso); i COMPONENTI sotto di essa, invece, non possono essere symlink.
 */
final class SafeStorageDir
{
    /**
     * Verifica/crea la catena `$anchor/$components...`. Rifiuta qualunque componente symlink e, se
     * `$freshLeaf`, un leaf PREESISTENTE (deve essere creato qui, mai riusato: nega anche una
     * directory runtime già presente o non vuota). Ritorna il path assoluto o null (fail closed).
     *
     * @param list<string> $components
     */
    public static function ensure(string $anchor, array $components, bool $freshLeaf = false): ?string
    {
        $real = realpath($anchor);
        if ($real === false || !is_dir($real)) {
            return null;
        }
        if ($components === []) {
            return $real;
        }

        $current = $real;
        $last = count($components) - 1;
        foreach ($components as $i => $comp) {
            if (!is_string($comp) || $comp === '' || $comp === '.' || $comp === '..'
                || strpos($comp, '/') !== false || strpos($comp, "\0") !== false) {
                return null;
            }
            $next = $current . '/' . $comp;
            $isLeaf = $i === $last;

            if (is_link($next)) {
                return null; // componente symlink → rifiutato a QUALSIASI livello
            }
            if (file_exists($next)) {
                if (!is_dir($next)) {
                    return null; // esiste ma non è una directory
                }
                if ($isLeaf && $freshLeaf) {
                    return null; // leaf runtime già presente (o non vuoto) → rifiutato
                }
            } elseif (!@mkdir($next, 0700) && !is_dir($next)) {
                return null;
            }
            // Ri-verifica DOPO la creazione: nessuna race che abbia inserito un symlink.
            if (is_link($next) || !is_dir($next)) {
                return null;
            }
            $current = $next;
        }

        // Confine finale: il path reale non deve essere sfuggito da sotto la radice.
        $realFinal = realpath($current);
        if ($realFinal === false || ($realFinal !== $real && !str_starts_with($realFinal . '/', $real . '/'))) {
            return null;
        }

        return $current;
    }
}
