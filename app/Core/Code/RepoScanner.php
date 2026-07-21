<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Reparto Code — Fase 0 / F0.3. Costruisce la RepoMap di un CodeWorkspace percorrendolo
 * SOLO tramite le sue operazioni confinate (children/read): eredita quindi revoca,
 * PathGuard e SensitivePathPolicy. Non tocca mai rootPath direttamente.
 *
 * Separazione inventario / estrazione (regola F0.3):
 *  - tutti i file ammessi entrano nell'inventario (path);
 *  - il contenuto viene letto per estrarre i simboli solo per linguaggi supportati,
 *    sotto la soglia di dimensione e non binari.
 * Limiti deterministici: profondità, numero file, byte letti per file, tempo massimo.
 */
final class RepoScanner
{
    /** @var array<string, string> estensione (minuscola) -> linguaggio */
    private const LANGUAGES = [
        'php' => 'php',
        'js' => 'js', 'jsx' => 'js', 'ts' => 'js', 'tsx' => 'js', 'mjs' => 'js', 'cjs' => 'js',
        'py' => 'py',
    ];

    /** @var string[] directory di rumore sempre escluse */
    private const DEFAULT_EXCLUDED_DIRS = ['.git', 'vendor', 'node_modules', 'storage'];

    /** @var string[] file di rumore sempre esclusi (basename minuscolo) */
    private const DEFAULT_EXCLUDED_FILES = ['.ds_store'];

    /** @var array<int, string> directory escluse (basename minuscolo), a qualsiasi profondità */
    private readonly array $excludedDirs;

    /** @var array<int, string> file di rumore esclusi (basename minuscolo) */
    private readonly array $excludedFiles;

    /**
     * @param string[] $excludedDirs directory extra: si SOMMANO ai default (merge), così una
     *   lista custom non riabilita per sbaglio vendor/node_modules/storage/.git
     * @param string[] $excludedFiles file di rumore extra: si sommano ai default (.DS_Store)
     */
    public function __construct(
        private readonly int $maxDepth = 12,
        private readonly int $maxFiles = 2000,
        private readonly int $maxReadBytes = 262144,
        private readonly float $maxSeconds = 5.0,
        array $excludedDirs = [],
        array $excludedFiles = [],
    ) {
        $this->excludedDirs = array_values(array_unique(
            array_map('strtolower', array_merge(self::DEFAULT_EXCLUDED_DIRS, $excludedDirs))
        ));
        $this->excludedFiles = array_values(array_unique(
            array_map('strtolower', array_merge(self::DEFAULT_EXCLUDED_FILES, $excludedFiles))
        ));
    }

    public function scan(CodeWorkspace $workspace): RepoMap
    {
        $start = microtime(true);
        $truncated = false;
        /** @var array<int, array{path: string, symbols: array<int, string>}> $files */
        $files = [];

        $queue = [['path' => '', 'depth' => 0]];
        while ($queue !== []) {
            if (microtime(true) - $start > $this->maxSeconds) {
                $truncated = true;
                break;
            }
            $dir = array_shift($queue);
            foreach ($workspace->children($dir['path']) as $entry) {
                // Timeout controllato ANCHE per-entry, prima delle operazioni costose.
                if (microtime(true) - $start > $this->maxSeconds) {
                    $truncated = true;
                    break 2;
                }
                if ($entry['type'] === 'dir') {
                    $base = strtolower(basename($entry['path']));
                    if (in_array($base, $this->excludedDirs, true)) {
                        continue; // directory esclusa, a qualsiasi profondità
                    }
                    if ($dir['depth'] + 1 <= $this->maxDepth) {
                        $queue[] = ['path' => $entry['path'], 'depth' => $dir['depth'] + 1];
                    } else {
                        $truncated = true; // profondità oltre il limite: non si scende
                    }
                    continue;
                }
                // File di rumore (es. .DS_Store): fuori dall'inventario.
                if (in_array(strtolower(basename($entry['path'])), $this->excludedFiles, true)) {
                    continue;
                }
                if (count($files) >= $this->maxFiles) {
                    $truncated = true;
                    break 2;
                }
                $files[] = [
                    'path' => $entry['path'],
                    'symbols' => $this->symbolsFor($workspace, $entry),
                ];
                // Ricontrollo dopo l'estrazione: un ultimo parsing che sfora il tempo
                // marca comunque la mappa come troncata.
                if (microtime(true) - $start > $this->maxSeconds) {
                    $truncated = true;
                    break 2;
                }
            }
        }

        usort($files, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        return new RepoMap($files, $truncated);
    }

    /**
     * Estrazione ammessa solo con dimensione NOTA (>= 0) e sotto soglia. Una dimensione
     * sconosciuta (-1, es. filesize fallita) NON autorizza la lettura.
     */
    public static function extractionAllowed(int $size, int $maxReadBytes): bool
    {
        return $size >= 0 && $size <= $maxReadBytes;
    }

    /**
     * @param array{path: string, type: string, size: int} $entry
     * @return array<int, string>
     */
    private function symbolsFor(CodeWorkspace $workspace, array $entry): array
    {
        $language = self::LANGUAGES[strtolower(pathinfo($entry['path'], PATHINFO_EXTENSION))] ?? null;
        if ($language === null || !self::extractionAllowed($entry['size'], $this->maxReadBytes)) {
            return []; // resta in inventario, senza estrazione
        }
        try {
            // Lettura LIMITATA nel confine: mai caricare un file oltre soglia.
            $content = $workspace->readLimited($entry['path'], $this->maxReadBytes);
        } catch (CodeWorkspaceException $e) {
            // SOLO l'errore atteso (sparito/illeggibile/sensibile/oltre soglia) → solo
            // inventario. Bug o errori di parsing NON vengono silenziati: si propagano.
            return [];
        }
        if ($this->isBinary($content)) {
            return [];
        }

        $symbols = match ($language) {
            'php' => $this->phpSymbols($content),
            'js' => $this->jsSymbols($content),
            'py' => $this->pySymbols($content),
            default => [],
        };

        $symbols = array_values(array_unique($symbols));
        sort($symbols); // deterministico
        return $symbols;
    }

    private function isBinary(string $content): bool
    {
        return strpos(substr($content, 0, 8000), "\0") !== false;
    }

    /**
     * PHP con parsing reale via token_get_all. Cattura function/class/interface/trait
     * di primo riferimento; casi di bordo (arrow fn, return-by-ref) non sono garantiti.
     *
     * @return array<int, string>
     */
    private function phpSymbols(string $content): array
    {
        $out = [];
        $tokens = @token_get_all($content);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }
            $kind = match ($token[0]) {
                T_FUNCTION => 'function',
                T_CLASS => 'class',
                T_INTERFACE => 'interface',
                T_TRAIT => 'trait',
                T_ENUM => 'enum',
                default => null,
            };
            if ($kind === null) {
                continue;
            }
            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];
                if (is_array($next) && $next[0] === T_WHITESPACE) {
                    continue;
                }
                if ($next === '&') {
                    continue; // function &getRef()
                }
                if (is_array($next) && $next[0] === T_STRING) {
                    $out[] = $kind . ' ' . $next[1];
                }
                break; // '(' => funzione anonima: nessun nome
            }
        }
        return $out;
    }

    /**
     * JS/TS: euristica DICHIARATA (regex), non parsing completo.
     *
     * @return array<int, string>
     */
    private function jsSymbols(string $content): array
    {
        $out = [];
        if (preg_match_all('/\bclass\s+([A-Za-z_$][\w$]*)/', $content, $m)) {
            foreach ($m[1] as $name) {
                $out[] = 'class ' . $name;
            }
        }
        if (preg_match_all('/\bfunction\s+([A-Za-z_$][\w$]*)/', $content, $m)) {
            foreach ($m[1] as $name) {
                $out[] = 'function ' . $name;
            }
        }
        return $out;
    }

    /**
     * Python: euristica DICHIARATA (regex), non parsing completo.
     *
     * @return array<int, string>
     */
    private function pySymbols(string $content): array
    {
        $out = [];
        if (preg_match_all('/^\s*class\s+([A-Za-z_]\w*)/m', $content, $m)) {
            foreach ($m[1] as $name) {
                $out[] = 'class ' . $name;
            }
        }
        if (preg_match_all('/^\s*def\s+([A-Za-z_]\w*)/m', $content, $m)) {
            foreach ($m[1] as $name) {
                $out[] = 'def ' . $name;
            }
        }
        return $out;
    }
}
