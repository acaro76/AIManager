<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 1 / F1.2. Recupero mirato SINGLE-SHOT (nessun ciclo agente): dato un
 * workspace e una domanda, prepara il contesto in un solo passaggio deterministico:
 *
 *   inventario leggero (RepoScanner) → ricerca (nome + contenuto) → selezione dei file
 *   rilevanti → lettura mirata (readLimited) → RetrievalResult
 *
 * Tutto l'accesso al filesystem passa da RepoScanner (che usa children()) e da
 * readLimited(): niente shell/grep/rg, niente accesso parallelo. I limiti `scan*` di
 * RetrievalLimits sono passati ESPLICITAMENTE al RepoScanner, così non esistono due
 * configurazioni divergenti. Nessun provider, DB, UI: solo il DTO puro.
 *
 * REVOCA — `$isActive` è rivalutato durante le fasi lunghe (ricerca e lettura), ma il default
 * (`$workspace->status`) è uno SNAPSHOT immutabile fissato alla costruzione del CodeWorkspace:
 * NON rilegge il DB. CodeChatService (F1.4) DOVRÀ quindi iniettare un checker basato su
 * CodeWorkspaceRepository, così una revoca avvenuta nel DB a metà operazione viene rilevata.
 * L'engine resta puro (nessun accesso al DB qui).
 */
final class TargetedRetriever
{
    private readonly RetrievalLimits $limits;
    private readonly FileNameSearch $nameSearch;
    private readonly ContentSearch $contentSearch;
    /** @var (callable(): bool)|null */
    private $isActiveOverride;

    public function __construct(
        ?RetrievalLimits $limits = null,
        ?FileNameSearch $nameSearch = null,
        ?ContentSearch $contentSearch = null,
        ?callable $isActive = null,
    ) {
        $this->limits = $limits ?? RetrievalLimits::defaults();
        $this->nameSearch = $nameSearch ?? new FileNameSearch();
        $this->contentSearch = $contentSearch ?? new ContentSearch();
        $this->isActiveOverride = $isActive;
    }

    public function retrieve(CodeWorkspace $workspace, string $query): RetrievalResult
    {
        $isActive = $this->isActiveOverride ?? static fn (): bool => $workspace->status === 'active';
        $tokens = self::tokenize($query);

        // 1) Inventario leggero: i limiti scan* arrivano dal SOLO RetrievalLimits.
        $inventory = (new RepoScanner(
            $this->limits->scanMaxDepth,
            $this->limits->scanMaxFiles,
            $this->limits->scanMaxReadBytes,
            $this->limits->scanMaxSeconds,
        ))->scan($workspace);

        $paths = array_map(static fn (array $f): string => (string) $f['path'], $inventory->files());

        $limitsHit = [];
        if ($inventory->isTruncated()) {
            $limitsHit[] = 'scan';
        }

        // 2) Ricerca mirata (solo con token utili). Nome sull'inventario, contenuto con
        //    lettura confinata. I contatori dei file restano SEPARATI (nessun doppio conteggio).
        $nameHits = [];
        $contentHits = [];
        $nameFilesScanned = 0;
        $contentFilesScanned = 0;
        $searchBytes = 0;
        if ($tokens !== []) {
            $name = $this->nameSearch->search($paths, $tokens, $this->limits);
            $nameHits = $name['hits'];
            $nameFilesScanned = $name['filesScanned'];
            $limitsHit = array_merge($limitsHit, $name['limitsHit']);

            $content = $this->contentSearch->search($workspace, $paths, $tokens, $this->limits, $isActive);
            $contentHits = $content['hits'];
            $contentFilesScanned = $content['filesScanned'];
            $searchBytes = $content['bytesRead'];
            $limitsHit = array_merge($limitsHit, $content['limitsHit']);
        }

        // 3) searchHits combinati: prima i match di contenuto, poi i match di solo-nome per
        //    percorsi non già coperti. Cap deterministico a searchMaxMatches.
        [$searchHits, $capHit] = $this->combineHits($contentHits, $nameHits, $this->limits->searchMaxMatches);
        if ($capHit) {
            $limitsHit[] = 'search:matches';
        }

        // 4) Selezione DETERMINISTICA dei file da leggere e 5) lettura mirata confinata.
        $selected = $this->selectFilesToRead($contentHits, $nameHits);
        [$readFiles, $readLimitsHit] = $this->readSelected($workspace, $selected, $isActive);
        $limitsHit = array_merge($limitsHit, $readLimitsHit);

        $metrics = [
            'inventoryFiles' => count($paths),
            'nameFilesScanned' => $nameFilesScanned,
            'contentFilesScanned' => $contentFilesScanned,
            'searchBytesRead' => $searchBytes,
            'searchMatches' => count($searchHits),
            'filesRead' => count($readFiles),
            'readBytes' => array_sum(array_map(static fn (array $f): int => $f['bytes'], $readFiles)),
        ];

        return new RetrievalResult(
            query: $query,
            inventory: $inventory,
            searchHits: $searchHits,
            readFiles: $readFiles,
            limitsHit: array_values(array_unique($limitsHit)),
            metrics: $metrics,
        );
    }

    /**
     * Tokenizzazione DICHIARATA (unico punto, condiviso dai due searcher): minuscole, split
     * sui non alfanumerici, token unici di almeno 3 caratteri (scarta rumore come "il"/"di").
     *
     * @return list<string>
     */
    public static function tokenize(string $query): array
    {
        $parts = preg_split('/[^a-z0-9_]+/', strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_filter($parts, static fn (string $t): bool => strlen($t) >= 3);
        return array_values(array_unique($tokens));
    }

    /**
     * @param list<array{path: string, line: int, excerpt: string}> $contentHits
     * @param list<array{path: string, line: int, excerpt: string}> $nameHits
     * @return array{0: list<array{path: string, line: int, excerpt: string}>, 1: bool} [hits, capRaggiunto]
     */
    private function combineHits(array $contentHits, array $nameHits, int $maxMatches): array
    {
        $contentPaths = [];
        foreach ($contentHits as $h) {
            $contentPaths[$h['path']] = true;
        }
        $nameOnly = array_values(array_filter(
            $nameHits,
            static fn (array $h): bool => !isset($contentPaths[$h['path']])
        ));

        $combined = array_merge($contentHits, $nameOnly);
        $cap = count($combined) > $maxMatches;
        return [array_slice($combined, 0, $maxMatches), $cap];
    }

    /**
     * File da leggere, ordinati in modo DETERMINISTICO: prima i file con più match di
     * contenuto (punteggio desc, path asc a parità), poi i file di solo-nome (path asc).
     *
     * @param list<array{path: string, line: int, excerpt: string}> $contentHits
     * @param list<array{path: string, line: int, excerpt: string}> $nameHits
     * @return list<string>
     */
    private function selectFilesToRead(array $contentHits, array $nameHits): array
    {
        $score = [];
        foreach ($contentHits as $h) {
            $score[$h['path']] = ($score[$h['path']] ?? 0) + 1;
        }
        $contentPaths = array_keys($score);
        usort($contentPaths, static fn (string $a, string $b): int => [$score[$b], $a] <=> [$score[$a], $b]);

        $namePaths = array_values(array_filter(
            array_map(static fn (array $h): string => $h['path'], $nameHits),
            static fn (string $p): bool => !isset($score[$p])
        ));
        $namePaths = array_values(array_unique($namePaths));
        sort($namePaths);

        return array_merge($contentPaths, $namePaths);
    }

    /**
     * Lettura mirata confinata dei file selezionati, entro i limiti read*: numero massimo di
     * file, byte per file e byte totali. Come per la ricerca, il limite per-file è ridotto al
     * budget residuo prima di leggere, così non si carica nulla da scartare. La revoca è
     * rivalutata a ogni file; su root non valida ci si ferma. I binari non entrano.
     *
     * @param list<string> $selected
     * @param callable(): bool $isActive
     * @return array{0: list<array{path: string, content: string, bytes: int, truncated: bool}>, 1: list<string>}
     */
    private function readSelected(CodeWorkspace $workspace, array $selected, callable $isActive): array
    {
        $readFiles = [];
        $limitsHit = [];
        $total = 0;

        foreach ($selected as $path) {
            if (!$isActive()) {
                $limitsHit[] = 'revoked';
                break;
            }
            if (count($readFiles) >= $this->limits->readMaxFiles) {
                $limitsHit[] = 'read:files';
                break;
            }
            $remaining = $this->limits->readMaxTotalBytes - $total;
            if ($remaining <= 0) {
                $limitsHit[] = 'read:totalBytes';
                break;
            }
            $allowed = min($this->limits->readMaxBytesPerFile, $remaining);

            try {
                $content = $workspace->readLimited($path, $allowed);
            } catch (CodeWorkspaceException $e) {
                if (!$isActive()) {
                    $limitsHit[] = 'revoked';
                    break;
                }
                if (!$workspace->rootIsValid()) {
                    $limitsHit[] = 'root';
                    break;
                }
                // budget residuo vincolante → totale; altrimenti file troppo grande/sensibile.
                $limitsHit[] = $allowed < $this->limits->readMaxBytesPerFile ? 'read:totalBytes' : 'read:skipped';
                continue;
            }

            if ($this->isBinary($content)) {
                continue; // niente binari nel contesto
            }

            $len = strlen($content);
            $readFiles[] = ['path' => $path, 'content' => $content, 'bytes' => $len, 'truncated' => false];
            $total += $len;
        }

        return [$readFiles, array_values(array_unique($limitsHit))];
    }

    private function isBinary(string $content): bool
    {
        return strpos(substr($content, 0, 8000), "\0") !== false;
    }
}
