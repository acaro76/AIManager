<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 6 / F6.4. Validatore argv PURO e STRICT-DENY.
 *
 * Applica le regole comuni (NUL, vuoto, lunghezza, conteggio) e POI la CommandSpec del programma.
 * Tutto ciò che la spec non ammette è NEGATO: flag non in whitelist, ricorsione, `--exec`, combinati
 * (`-ni`), stdin (`-`), un `--` dal modello, pattern/path oltre i tetti. Non esiste fallback permissivo.
 *
 * I messaggi d'errore sono a TESTO FISSO: non riportano mai il valore ricevuto (potrebbe essere
 * testo ostile letto da un file). Il chiamante li tratta come DATO da restituire al modello.
 *
 * NON tocca il filesystem: i path sono validati solo nella FORMA (relativi, canonici, entro i tetti).
 * Il confine reale (PathGuard/SensitivePathPolicy) è al bind, subito prima di proc_open.
 */
final class CommandArgvValidator
{
    /** Tetto duro sulla lunghezza complessiva dell'argv (byte) e sul numero di token. */
    private const MAX_TOTAL_BYTES = 8192;
    private const MAX_TOKENS = 64;

    /**
     * @param list<string> $args argomenti proposti dal modello (senza il programma, senza `--`)
     * @throws \InvalidArgumentException messaggio a testo fisso, riproponibile al modello
     */
    public function validate(CommandSpec $spec, array $args): CommandPlan
    {
        $this->assertCommonRules($args);

        $i = 0;
        $n = count($args);
        $flags = [];

        // --- Fase flag: solo in testa, solo whitelist. Al primo non-flag inizia gli operandi. ---
        while ($i < $n && str_starts_with($args[$i], '-')) {
            $token = $args[$i];
            if ($spec->isBareFlag($token)) {
                $flags[] = $token;
                $i++;
                continue;
            }
            $range = $spec->numericRange($token);
            if ($range !== null) {
                if ($i + 1 >= $n) {
                    throw new \InvalidArgumentException('Manca il valore numerico per un\'opzione.');
                }
                $value = $args[$i + 1];
                if (preg_match('/^\d{1,10}$/', $value) !== 1) {
                    throw new \InvalidArgumentException('Il valore di un\'opzione numerica non è valido.');
                }
                $num = (int) $value;
                if ($num < $range[0] || $num > $range[1]) {
                    throw new \InvalidArgumentException('Il valore di un\'opzione numerica è fuori dai limiti.');
                }
                $flags[] = $token;
                $flags[] = $value;
                $i += 2;
                continue;
            }
            // Include '-', '--', flag ricorsivi/exec, combinati e qualunque opzione non prevista.
            throw new \InvalidArgumentException('Opzione non ammessa per questo programma.');
        }

        // --- Operandi: eventuale pattern (grep), poi i path. ---
        $pattern = null;
        if ($spec->allowsPattern) {
            if ($i < $n) {
                $pattern = $this->validatePattern($args[$i], $spec->maxPatternLength);
                $i++;
            } elseif ($spec->patternRequired) {
                throw new \InvalidArgumentException('Manca il testo da cercare (pattern).');
            }
        }

        $relPaths = [];
        for (; $i < $n; $i++) {
            $relPaths[] = $this->validatePath($args[$i], $spec->maxPathLength);
        }

        $count = count($relPaths);
        if ($count < $spec->minPaths) {
            throw new \InvalidArgumentException('Servono più file (percorsi) per questo comando.');
        }
        if ($count > $spec->maxPaths) {
            throw new \InvalidArgumentException('Troppi file per questo comando.');
        }

        return new CommandPlan($spec->program, array_values($flags), $pattern, array_values($relPaths));
    }

    /** @param list<string> $args */
    private function assertCommonRules(array $args): void
    {
        if (count($args) > self::MAX_TOKENS) {
            throw new \InvalidArgumentException('Troppi argomenti.');
        }
        $total = 0;
        foreach ($args as $arg) {
            if (!is_string($arg)) {
                throw new \InvalidArgumentException('Argomento non valido.');
            }
            if ($arg === '') {
                throw new \InvalidArgumentException('Un argomento vuoto non è ammesso.');
            }
            if (str_contains($arg, "\0")) {
                throw new \InvalidArgumentException('Un argomento contiene un byte NUL.');
            }
            $total += strlen($arg);
        }
        if ($total > self::MAX_TOTAL_BYTES) {
            throw new \InvalidArgumentException('Argomenti troppo lunghi.');
        }
    }

    private function validatePattern(string $pattern, int $maxLength): string
    {
        // Nessun carattere di controllo: un pattern multilinea/con NUL sarebbe un tentativo di
        // iniettare struttura nel dialogo o nell'argv.
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $pattern) === 1) {
            throw new \InvalidArgumentException('Il pattern contiene caratteri di controllo.');
        }
        if (strlen($pattern) > $maxLength) {
            throw new \InvalidArgumentException('Il pattern è troppo lungo.');
        }

        return $pattern;
    }

    private function validatePath(string $path, int $maxLength): string
    {
        if (strlen($path) > $maxLength) {
            throw new \InvalidArgumentException('Un percorso è troppo lungo.');
        }
        try {
            RelativePath::assert($path);
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException(
                'Percorso non valido: usa un percorso RELATIVO alla cartella, senza "..", senza "/" iniziale.'
            );
        }

        return $path;
    }
}
