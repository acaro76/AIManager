<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 1 / F1.2. Ricerca per CONTENUTO (grep confinato).
 *
 * Legge i file candidati ESCLUSIVAMENTE via CodeWorkspace::readLimited(): mai fopen/shell/
 * grep/rg, mai un accesso parallelo. Da readLimited eredita gratis, ad ogni file: il rifiuto
 * di sensibili/symlink/oltre-soglia e la validità della root (PathGuard), rivalutati durante
 * l'iterazione.
 *
 * Applica TUTTI i limiti di ricerca di RetrievalLimits (file scanditi, byte per file, byte
 * TOTALI, match, tempo). Il byte-budget TOTALE non può essere superato: prima di ogni lettura
 * il limite per-file viene ridotto al budget residuo, così non si legge nulla destinato a
 * essere scartato.
 *
 * REVOCA — `$isActive` è rivalutato a ogni iterazione, ma il default (`$workspace->status`)
 * è uno SNAPSHOT immutabile fissato alla costruzione del CodeWorkspace: NON rilegge il DB.
 * Perciò CodeChatService (F1.4) DOVRÀ iniettare un checker basato su CodeWorkspaceRepository
 * (es. `fn() => $repo->findById($id)?->status === 'active'`) prima del retrieval e durante le
 * fasi lunghe; solo così una revoca avvenuta nel DB a metà operazione viene rilevata. L'engine
 * resta puro (nessun accesso al DB qui).
 */
final class ContentSearch
{
    /** @var callable(): float sorgente di tempo (iniettabile per test deterministici sul timeout) */
    private $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * @param list<string> $paths percorsi candidati (dall'inventario)
     * @param list<string> $tokens token di ricerca (minuscoli, già filtrati)
     * @param (callable(): bool)|null $isActive verifica di revoca rivalutata a ogni file
     * @return array{
     *   hits: list<array{path: string, line: int, excerpt: string}>,
     *   filesScanned: int,
     *   bytesRead: int,
     *   limitsHit: list<string>
     * }
     */
    public function search(CodeWorkspace $workspace, array $paths, array $tokens, RetrievalLimits $limits, ?callable $isActive = null): array
    {
        $isActive ??= static fn (): bool => $workspace->status === 'active';

        if ($tokens === []) {
            return ['hits' => [], 'filesScanned' => 0, 'bytesRead' => 0, 'limitsHit' => []];
        }

        sort($paths); // ordine deterministico

        $hits = [];
        $limitsHit = [];
        $filesScanned = 0;
        $bytesRead = 0;
        $start = ($this->clock)();

        foreach ($paths as $path) {
            // Revoca rivalutata DURANTE l'iterazione, non solo all'inizio.
            if (!$isActive()) {
                $limitsHit[] = 'revoked';
                break;
            }
            if ($filesScanned >= $limits->searchMaxFilesScanned) {
                $limitsHit[] = 'search:files';
                break;
            }
            if ($bytesRead >= $limits->searchMaxTotalBytes) {
                $limitsHit[] = 'search:totalBytes';
                break;
            }
            if (($this->clock)() - $start > $limits->searchMaxSeconds) {
                $limitsHit[] = 'search:time';
                break;
            }

            // Mai oltre il budget residuo: il limite per-file è ridotto a ciò che resta, così
            // un file più grande del residuo viene rifiutato da readLimited (non letto).
            $remaining = $limits->searchMaxTotalBytes - $bytesRead;
            $allowed = min($limits->searchMaxBytesPerFile, $remaining);

            try {
                $content = $workspace->readLimited($path, $allowed);
            } catch (CodeWorkspaceException $e) {
                // Ordine: prima la revoca, poi la root, poi il file saltato.
                if (!$isActive()) {
                    $limitsHit[] = 'revoked';
                    break;
                }
                if (!$workspace->rootIsValid()) {
                    $limitsHit[] = 'root';
                    break;
                }
                $filesScanned++;
                // Se il limite era il budget residuo (non la soglia per-file), è il totale a
                // impedire la lettura: segnalalo.
                if ($allowed < $limits->searchMaxBytesPerFile) {
                    $limitsHit[] = 'search:totalBytes';
                }
                continue; // sensibile / mancante / oltre soglia / symlink: salta
            }

            $filesScanned++;
            $bytesRead += strlen($content);

            if ($this->isBinary($content)) {
                continue; // binario: nessun match testuale (già letto entro soglia)
            }

            $lineNo = 0;
            foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
                $lineNo++;
                $hay = strtolower($line);
                foreach ($tokens as $token) {
                    if ($token !== '' && str_contains($hay, $token)) {
                        $hits[] = ['path' => $path, 'line' => $lineNo, 'excerpt' => $this->excerpt($line)];
                        break;
                    }
                }
                if (count($hits) >= $limits->searchMaxMatches) {
                    $limitsHit[] = 'search:matches';
                    break 2;
                }
                if (($this->clock)() - $start > $limits->searchMaxSeconds) {
                    $limitsHit[] = 'search:time';
                    break 2;
                }
            }
        }

        return [
            'hits' => $hits,
            'filesScanned' => $filesScanned,
            'bytesRead' => $bytesRead,
            'limitsHit' => array_values(array_unique($limitsHit)),
        ];
    }

    /**
     * Stessa euristica del RepoScanner (NUL nei primi byte): un file con byte NUL è trattato
     * come binario e non produce match testuali. Replicata qui volutamente (una riga) per non
     * rendere pubblico un dettaglio interno del RepoScanner.
     */
    private function isBinary(string $content): bool
    {
        return strpos(substr($content, 0, 8000), "\0") !== false;
    }

    private function excerpt(string $line): string
    {
        $trimmed = trim($line);
        // Taglio UTF-8 SICURO a 200 byte: la riga proviene da un file e può contenere
        // multibyte o byte non validi; un substr cieco romperebbe la codifica.
        $cut = Utf8::cut($trimmed, 200);
        return strlen($cut) < strlen($trimmed) ? $cut . '…' : $cut;
    }
}
