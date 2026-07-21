<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 1 / F1.2. Ricerca per NOME file.
 *
 * Opera sui percorsi dell'inventario, che il RepoScanner ha già raccolto percorrendo il
 * workspace SOLO via CodeWorkspace::children() (quindi entro confine, senza symlink, senza
 * sensibili, con le stesse esclusioni di rumore). Lavorare su quell'elenco — invece di
 * ri-percorrere il filesystem — evita un secondo accesso e una configurazione di esclusioni
 * divergente da quella del RepoScanner.
 *
 * È un matcher deterministico e in memoria, ma appartiene alla fase di ricerca: rispetta
 * quindi i limiti del gruppo search* (numero di file esaminati, tempo, match) ed espone i
 * propri contatori, così TargetedRetriever può aggregarli senza contare due volte gli stessi
 * file (nome e contenuto scandiscono insiemi distinti di conteggio).
 */
final class FileNameSearch
{
    /** @var callable(): float */
    private $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * Cerca i token (già normalizzati, minuscoli) nei percorsi. Un percorso è un hit se
     * contiene ALMENO un token (OR). Rispetta searchMaxFilesScanned, searchMaxSeconds
     * (controllato durante l'iterazione) e searchMaxMatches. I match di nome hanno `line = 0`.
     *
     * @param list<string> $paths percorsi relativi dell'inventario
     * @param list<string> $tokens token di ricerca (minuscoli, già filtrati)
     * @return array{
     *   hits: list<array{path: string, line: int, excerpt: string}>,
     *   truncated: bool,
     *   filesScanned: int,
     *   limitsHit: list<string>
     * }
     */
    public function search(array $paths, array $tokens, RetrievalLimits $limits): array
    {
        if ($tokens === []) {
            return ['hits' => [], 'truncated' => false, 'filesScanned' => 0, 'limitsHit' => []];
        }

        sort($paths); // ordine deterministico, indipendente dall'ordine di scoperta

        $matched = [];
        $limitsHit = [];
        $scanned = 0;
        $start = ($this->clock)();

        foreach ($paths as $path) {
            if ($scanned >= $limits->searchMaxFilesScanned) {
                $limitsHit[] = 'search:files';
                break;
            }
            if (($this->clock)() - $start > $limits->searchMaxSeconds) {
                $limitsHit[] = 'search:time';
                break;
            }
            $scanned++;

            $hay = strtolower($path);
            foreach ($tokens as $token) {
                if ($token !== '' && str_contains($hay, $token)) {
                    $matched[] = $path;
                    break;
                }
            }
        }

        $truncated = count($matched) > $limits->searchMaxMatches;
        if ($truncated) {
            $limitsHit[] = 'search:matches';
        }
        $kept = array_slice($matched, 0, $limits->searchMaxMatches);

        $hits = array_map(
            static fn (string $path): array => ['path' => $path, 'line' => 0, 'excerpt' => 'corrispondenza sul nome file'],
            $kept
        );

        return [
            'hits' => $hits,
            'truncated' => $truncated,
            'filesScanned' => $scanned,
            'limitsHit' => array_values(array_unique($limitsHit)),
        ];
    }
}
