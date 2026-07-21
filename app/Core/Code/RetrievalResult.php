<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 1 / F1.1. Esito PURO del recupero mirato (single-shot): il DTO che il
 * TargetedRetriever (F1.2) produce e che CodeChatService (F1.4) consuma per impacchettare
 * il contesto e — separatamente — per registrare l'audit.
 *
 * Porta tre livelli distinti di evidenza, che NON vanno confusi in risposta:
 *   - inventory     : l'inventario leggero (RepoMap) — cosa esiste;
 *   - searchHits    : i match di ricerca (nome/testo) — dove potrebbe essere rilevante;
 *   - readFiles     : i file EFFETTIVAMENTE letti — l'unica evidenza di contenuto.
 * Per questo `filesConsulted()` distingue i file letti dai soli trovati.
 *
 * Espone anche `limitsHit()` e `metrics()`: quel che serve a CodeChatService per l'audit,
 * SENZA che il motore dipenda da un repository DB (retrieval puro). Tutti i percorsi sono
 * RELATIVI alla root: l'invariante è verificata qui, al confine del DTO.
 *
 * @phpstan-type SearchHit array{path: string, line: int, excerpt: string}
 * @phpstan-type ReadFile array{path: string, content: string, bytes: int, truncated: bool}
 */
final class RetrievalResult
{
    /**
     * Vocabolario CHIUSO dei limiti che il retrieval può segnalare. È la fonte di verità: chi
     * li consuma (l'audit) valida contro questa lista, così nei log non può finire testo libero.
     *
     * @var list<string>
     */
    public const LIMIT_CODES = [
        'scan',
        'search:files', 'search:totalBytes', 'search:time', 'search:matches',
        'read:files', 'read:totalBytes', 'read:skipped',
        'root', 'revoked',
    ];

    /**
     * Vocabolario CHIUSO delle metriche esposte da metrics(). Stessa ragione: l'audit accetta
     * solo queste chiavi, con valori interi non negativi.
     *
     * @var list<string>
     */
    public const METRIC_KEYS = [
        'inventoryFiles', 'nameFilesScanned', 'contentFilesScanned',
        'searchBytesRead', 'searchMatches', 'filesRead', 'readBytes',
    ];

    /** @var list<array{path: string, line: int, excerpt: string}> */
    private readonly array $searchHits;

    /** @var list<array{path: string, content: string, bytes: int, truncated: bool}> */
    private readonly array $readFiles;

    /** @var list<string> */
    private readonly array $limitsHit;

    /** @var array<string, int> */
    private readonly array $metrics;

    /**
     * @param list<array{path: string, line: int, excerpt: string}> $searchHits
     * @param list<array{path: string, content: string, bytes: int, truncated: bool}> $readFiles
     * @param list<string> $limitsHit i limiti morsi (es. 'scan', 'search', 'read')
     * @param array<string, int> $metrics contatori per l'audit (es. filesScanned, searchMatches, filesRead, bytesRead)
     */
    public function __construct(
        public readonly string $query,
        public readonly RepoMap $inventory,
        array $searchHits = [],
        array $readFiles = [],
        array $limitsHit = [],
        array $metrics = [],
    ) {
        foreach ($searchHits as $hit) {
            self::assertRelative($hit['path'] ?? '');
        }
        foreach ($readFiles as $file) {
            self::assertRelative($file['path'] ?? '');
        }
        // Anche i percorsi dell'inventario finiscono in output (property `inventory`): un
        // RepoMap costruito male non deve poter far uscire un path assoluto/traversal.
        foreach ($inventory->files() as $file) {
            self::assertRelative((string) ($file['path'] ?? ''));
        }

        $this->searchHits = array_values($searchHits);
        $this->readFiles = array_values($readFiles);
        // I vocabolari sono applicati QUI, non solo dichiarati: un RetrievalResult malformato
        // non deve poter esistere e arrivare al service (l'audit lo rifiuterebbe e l'operazione
        // proseguirebbe senza tracciamento). Nessuna conversione permissiva: si rifiuta.
        $this->limitsHit = self::validatedLimits($limitsHit);
        $this->metrics = self::validatedMetrics($metrics);
    }

    /**
     * Lista (mai una mappa) di codici appartenenti a LIMIT_CODES, deduplicata mantenendo
     * l'ordine di prima comparsa.
     *
     * @param array<mixed> $limits
     * @return list<string>
     */
    private static function validatedLimits(array $limits): array
    {
        if ($limits !== [] && array_keys($limits) !== range(0, count($limits) - 1)) {
            throw new \InvalidArgumentException('RetrievalResult: i limiti devono essere una lista, non una mappa.');
        }

        $out = [];
        foreach ($limits as $limit) {
            if (!is_string($limit) || !in_array($limit, self::LIMIT_CODES, true)) {
                throw new \InvalidArgumentException('RetrievalResult: codice di limite non ammesso.');
            }
            if (!in_array($limit, $out, true)) {
                $out[] = $limit;
            }
        }

        return $out;
    }

    /**
     * Solo chiavi di METRIC_KEYS, con valori interi non negativi (niente annidamenti).
     *
     * @param array<mixed> $metrics
     * @return array<string, int>
     */
    private static function validatedMetrics(array $metrics): array
    {
        foreach ($metrics as $key => $value) {
            if (!is_string($key) || !in_array($key, self::METRIC_KEYS, true)) {
                throw new \InvalidArgumentException('RetrievalResult: chiave di metrica non ammessa.');
            }
            if (!is_int($value) || $value < 0) {
                throw new \InvalidArgumentException('RetrievalResult: valore di metrica non ammesso.');
            }
        }

        /** @var array<string, int> $metrics */
        return $metrics;
    }

    /** @return list<array{path: string, line: int, excerpt: string}> */
    public function searchHits(): array
    {
        return $this->searchHits;
    }

    /** @return list<array{path: string, content: string, bytes: int, truncated: bool}> */
    public function readFiles(): array
    {
        return $this->readFiles;
    }

    /** @return list<string> */
    public function limitsHit(): array
    {
        return $this->limitsHit;
    }

    public function anyLimitHit(): bool
    {
        return $this->limitsHit !== [];
    }

    /** @return array<string, int> */
    public function metrics(): array
    {
        return $this->metrics;
    }

    /**
     * Percorsi consultati, con la distinzione che deve arrivare fino alla risposta:
     *   - 'read'  : i file letti davvero (evidenza di contenuto);
     *   - 'found' : i file emersi dalla RICERCA ma NON letti (solo indizi).
     * I letti non compaiono tra i 'found'. L'inventario NON confluisce qui: su un repo da
     * migliaia di file darebbe una lista enorme e semanticamente scorretta ("consultato" =
     * cercato o letto, non "esiste"). L'inventario resta disponibile a parte via `inventory`.
     * Entrambe le liste sono uniche, ordinate e derivano dal retrieval (mai dal testo
     * generato dal modello). Percorsi relativi.
     *
     * @return array{read: list<string>, found: list<string>}
     */
    public function filesConsulted(): array
    {
        $read = [];
        foreach ($this->readFiles as $file) {
            $read[$file['path']] = true;
        }

        $found = [];
        foreach ($this->searchHits as $hit) {
            if (!isset($read[$hit['path']])) {
                $found[$hit['path']] = true;
            }
        }

        $readPaths = array_keys($read);
        $foundPaths = array_keys($found);
        sort($readPaths);
        sort($foundPaths);

        return ['read' => $readPaths, 'found' => $foundPaths];
    }

    /**
     * L'invariante trasversale (percorso relativo e canonico) vive in RelativePath: unico punto
     * di verità condiviso con l'audit, così non esistono due controlli divergenti. Un path fuori
     * norma qui è un bug del chiamante: si fallisce subito, prima che arrivi a UI, citazioni o log.
     */
    private static function assertRelative(string $path): void
    {
        RelativePath::assert($path);
    }
}
