<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 6 / F6.2. Policy argv CHIUSA e DICHIARATIVA di UN programma.
 *
 * Non è codice per programma: è una tabella immutabile che elenca ESATTAMENTE ciò che è ammesso.
 * Tutto ciò che non è dichiarato è NEGATO (strict-deny): non esiste una `DefaultArgvPolicy` che
 * conceda argv «generici» a programmi non previsti.
 *
 * Ogni spec IMPONE (correzione finale #4) tetti propri: numero e lunghezza dei path, lunghezza del
 * pattern, range dei flag numerici e cap del display-summary. Sono difese, non ergonomia: senza,
 * un argv formalmente lecito potrebbe comunque essere abusivo (path lunghissimi, pattern enorme).
 *
 * PURO: nessun IO. Decide solo se la FORMA dell'argv è ammissibile; il confine reale sui path
 * (PathGuard/SensitivePathPolicy) è verificato a valle, al bind, subito prima di proc_open.
 */
final class CommandSpec
{
    /**
     * @param string             $program        nome del binario (identità; risolto a valle solo in bin fidate)
     * @param list<string>       $bareFlags      flag ammessi SENZA argomento (es. '-n', '-i'); confronto esatto
     * @param array<string,array{0:int,1:int}> $numericFlags flag che prendono UN intero, con range [min,max]
     * @param bool               $allowsPattern  il primo operando è un pattern testuale (grep)
     * @param bool               $patternRequired il pattern è obbligatorio quando ammesso
     * @param int                $maxPatternLength lunghezza massima del pattern (byte)
     * @param int                $minPaths       numero minimo di operandi path
     * @param int                $maxPaths       numero massimo di operandi path
     * @param int                $maxPathLength  lunghezza massima di UN path (byte)
     * @param int                $displaySummaryMaxChars cap del riepilogo mostrato in card/audit
     */
    public function __construct(
        public readonly string $program,
        public readonly array $bareFlags = [],
        public readonly array $numericFlags = [],
        public readonly bool $allowsPattern = false,
        public readonly bool $patternRequired = false,
        public readonly int $maxPatternLength = 200,
        public readonly int $minPaths = 1,
        public readonly int $maxPaths = 10,
        public readonly int $maxPathLength = 4096,
        public readonly int $displaySummaryMaxChars = 400,
    ) {
        if ($program === '' || preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $program) !== 1) {
            throw new \InvalidArgumentException('CommandSpec: nome programma non valido.');
        }
        if ($minPaths < 0 || $maxPaths < $minPaths) {
            throw new \InvalidArgumentException('CommandSpec: range path non valido.');
        }
        if ($maxPathLength <= 0 || $maxPatternLength <= 0 || $displaySummaryMaxChars <= 0) {
            throw new \InvalidArgumentException('CommandSpec: tetto non valido.');
        }
        foreach ($this->bareFlags as $f) {
            if (!is_string($f) || preg_match('/^-[A-Za-z0-9]+$/', $f) !== 1) {
                throw new \InvalidArgumentException('CommandSpec: flag bare non valido.');
            }
        }
        foreach ($this->numericFlags as $f => $range) {
            if (!is_string($f) || preg_match('/^-[A-Za-z0-9]+$/', $f) !== 1
                || !is_array($range) || count($range) !== 2 || $range[0] > $range[1]) {
                throw new \InvalidArgumentException('CommandSpec: flag numerico non valido.');
            }
        }
    }

    public function isBareFlag(string $token): bool
    {
        return in_array($token, $this->bareFlags, true);
    }

    /** @return array{0:int,1:int}|null range del flag numerico, o null se non è tale */
    public function numericRange(string $token): ?array
    {
        return $this->numericFlags[$token] ?? null;
    }
}
