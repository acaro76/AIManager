<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 6 / F6.4. Il piano di comando VALIDATO e TIPIZZATO: l'unica cosa che, superata la
 * policy, può essere confermata ed eseguita.
 *
 * Operandi TAGGATI per ruolo (correzione #4 — path tipizzati e rivalidati a valle):
 *   - `flags`   : token di flag ammessi (bare o numerici già validati), vanno PRIMA di `--`;
 *   - `pattern` : eventuale pattern testuale (grep), primo operando dopo `--`;
 *   - `relPaths`: percorsi RELATIVI alla root, rivalidati via CodeWorkspace subito prima dell'exec.
 *
 * L'argv eseguibile inserisce SEMPRE `--` server-side prima di pattern/path (anti option-injection):
 *   [exeAssoluto, ...flags, '--', pattern?, ...pathAssoluti]
 *
 * Il DB NON conserva questo piano: vive nel CommandStore protetto fino alla conferma. Il DB tiene
 * solo `digest` e un `displaySummary()` sanificato (mai path assoluti, mai il pattern grezzo).
 */
final class CommandPlan
{
    /**
     * @param string       $program  nome del programma (identità; non un path)
     * @param list<string> $flags    token di flag validati, nell'ordine dato
     * @param ?string      $pattern  pattern testuale (grep) o null
     * @param list<string> $relPaths percorsi relativi validati (forma; il confine è a valle)
     */
    public function __construct(
        public readonly string $program,
        public readonly array $flags,
        public readonly ?string $pattern,
        public readonly array $relPaths,
    ) {
    }

    /**
     * Coda argv PER L'ESECUZIONE, con `--` inserito dal server. I path relativi vanno sostituiti con
     * gli ASSOLUTI ri-bindati dal chiamante (subito prima di proc_open): qui restano i relativi come
     * segnaposto d'ordine.
     *
     * @param list<string> $absPaths percorsi assoluti già ri-bindati (stesso ordine di relPaths)
     * @return list<string>
     */
    public function execTail(array $absPaths): array
    {
        $tail = $this->flags;
        $tail[] = '--';
        if ($this->pattern !== null) {
            $tail[] = $this->pattern;
        }
        foreach ($absPaths as $p) {
            $tail[] = $p;
        }

        return array_values($tail);
    }

    /**
     * Riepilogo SANIFICATO per card e audit: programma, flag, pattern bounded+delimitato, path
     * RELATIVI. Mai path assoluti; il pattern è tagliato e i delimitatori neutralizzati, così un
     * pattern ostile non può rompere la card. È «metadato», non l'argv eseguibile.
     */
    public function displaySummary(int $maxChars): string
    {
        $parts = [$this->program];
        foreach ($this->flags as $f) {
            $parts[] = $f;
        }
        if ($this->pattern !== null) {
            $safe = str_replace(['«', '»', "\n", "\r", "\t"], ['<', '>', ' ', ' ', ' '], $this->pattern);
            $parts[] = '«' . Utf8::cut(Utf8::clean($safe), 80) . '»';
        }
        foreach ($this->relPaths as $p) {
            $parts[] = $p;
        }

        return Utf8::cut(implode(' ', $parts), max(1, $maxChars));
    }

    /** Etichetta breve (solo programma + flag + n. path), senza pattern né contenuti. */
    public function shortLabel(): string
    {
        $suffix = $this->relPaths === [] ? '' : ' (' . count($this->relPaths) . ' file)';

        return trim($this->program . ' ' . implode(' ', $this->flags)) . $suffix;
    }

    /**
     * Payload canonico per il CommandStore (JSON). Contiene il piano completo, pattern incluso: il
     * file è protetto (0600, no-symlink, TTL) e mai versionato/serializzato nel DB.
     *
     * @return array{program:string,flags:list<string>,pattern:?string,rel_paths:list<string>}
     */
    public function toStore(): array
    {
        return [
            'program' => $this->program,
            'flags' => array_values($this->flags),
            'pattern' => $this->pattern,
            'rel_paths' => array_values($this->relPaths),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromStore(array $data): ?self
    {
        $program = $data['program'] ?? null;
        $flags = $data['flags'] ?? null;
        $relPaths = $data['rel_paths'] ?? null;
        $pattern = $data['pattern'] ?? null;
        if (!is_string($program) || !is_array($flags) || !is_array($relPaths)
            || ($pattern !== null && !is_string($pattern))) {
            return null;
        }
        foreach ($flags as $f) {
            if (!is_string($f)) {
                return null;
            }
        }
        foreach ($relPaths as $p) {
            if (!is_string($p)) {
                return null;
            }
        }

        return new self($program, array_values($flags), $pattern, array_values($relPaths));
    }

    /**
     * Digest canonico e stabile del piano nello scope: lega programma, flag, pattern, path, root e
     * scope. Alla conferma si ricalcola e si confronta col digest persistito: qualunque divergenza
     * (piano manomesso, registro cambiato) → conferma negata.
     */
    public function digest(string $rootPath, int $workspaceId, int $sessionId, int $policyVersion): string
    {
        $canonical = json_encode([
            'program' => $this->program,
            'flags' => array_values($this->flags),
            'pattern' => $this->pattern,
            'rel_paths' => array_values($this->relPaths),
            'root' => $rootPath,
            'workspace' => $workspaceId,
            'session' => $sessionId,
            'policy_version' => $policyVersion,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', (string) $canonical);
    }
}
