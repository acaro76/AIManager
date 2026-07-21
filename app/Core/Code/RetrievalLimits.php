<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 1 / F1.1. Limiti DETERMINISTICI e SEPARATI del recupero mirato (single-shot).
 *
 * Quattro gruppi indipendenti, uno per ogni fase del flusso, così che ognuna abbia il suo
 * tetto e un limite morso in una fase non "rubi" budget alle altre:
 *   - scansione (inventario leggero)         → riusa i default del RepoScanner (F0.3);
 *   - match (ricerca per nome + per testo)    → quanti file scandire, quanti match tenere;
 *   - lettura mirata (readLimited)            → quanti file leggere e con che soglie;
 *   - contesto finale (pacchetto per il modello) → tetto in caratteri del testo assemblato.
 *
 * È un value object immutabile e PURO: nessun IO, nessun DB. La validazione è una
 * precondizione di programmazione (valori > 0), quindi usa \InvalidArgumentException e NON
 * CodeWorkspaceException, che è riservata agli errori "attesi" del filesystem confinato.
 */
final class RetrievalLimits
{
    public function __construct(
        // --- scansione / inventario leggero (allineati ai default del RepoScanner) ---
        public readonly int $scanMaxDepth,
        public readonly int $scanMaxFiles,
        public readonly int $scanMaxReadBytes,
        public readonly float $scanMaxSeconds,
        // --- match: ricerca per nome file e per testo ---
        public readonly int $searchMaxFilesScanned,
        public readonly int $searchMaxMatches,
        public readonly int $searchMaxBytesPerFile,
        public readonly int $searchMaxTotalBytes,
        public readonly float $searchMaxSeconds,
        // --- lettura mirata dei file rilevanti ---
        public readonly int $readMaxFiles,
        public readonly int $readMaxBytesPerFile,
        public readonly int $readMaxTotalBytes,
        // --- contesto finale impacchettato per il modello ---
        public readonly int $contextMaxChars,
    ) {
        // Ogni tetto deve essere strettamente positivo: uno zero disattiverebbe in silenzio
        // una fase, un negativo renderebbe indefiniti i confronti "supera la soglia".
        $positiveInts = [
            'scanMaxDepth' => $scanMaxDepth,
            'scanMaxFiles' => $scanMaxFiles,
            'scanMaxReadBytes' => $scanMaxReadBytes,
            'searchMaxFilesScanned' => $searchMaxFilesScanned,
            'searchMaxMatches' => $searchMaxMatches,
            'searchMaxBytesPerFile' => $searchMaxBytesPerFile,
            'searchMaxTotalBytes' => $searchMaxTotalBytes,
            'readMaxFiles' => $readMaxFiles,
            'readMaxBytesPerFile' => $readMaxBytesPerFile,
            'readMaxTotalBytes' => $readMaxTotalBytes,
            'contextMaxChars' => $contextMaxChars,
        ];
        foreach ($positiveInts as $name => $value) {
            if ($value <= 0) {
                throw new \InvalidArgumentException("RetrievalLimits: {$name} deve essere > 0 (dato: {$value}).");
            }
        }
        foreach (['scanMaxSeconds' => $scanMaxSeconds, 'searchMaxSeconds' => $searchMaxSeconds] as $name => $value) {
            if ($value <= 0.0) {
                throw new \InvalidArgumentException("RetrievalLimits: {$name} deve essere > 0 (dato: {$value}).");
            }
        }

        // Coerenza fra il totale e il per-file, in ricerca e in lettura: il totale non può
        // essere inferiore al limite per singolo file, altrimenti nessun file entrerebbe
        // mai per intero.
        if ($searchMaxTotalBytes < $searchMaxBytesPerFile) {
            throw new \InvalidArgumentException(
                "RetrievalLimits: searchMaxTotalBytes ({$searchMaxTotalBytes}) < searchMaxBytesPerFile ({$searchMaxBytesPerFile})."
            );
        }
        if ($readMaxTotalBytes < $readMaxBytesPerFile) {
            throw new \InvalidArgumentException(
                "RetrievalLimits: readMaxTotalBytes ({$readMaxTotalBytes}) < readMaxBytesPerFile ({$readMaxBytesPerFile})."
            );
        }
    }

    /**
     * Default prudenti per il single-shot. Lo scan riusa i default del RepoScanner (F0.3)
     * per non divergere; gli altri gruppi tengono il contesto ben entro le finestre reali
     * dei provider (il tetto vero della finestra è imposto a valle dal CodeContextPacker).
     */
    public static function defaults(): self
    {
        return new self(
            scanMaxDepth: 12,
            scanMaxFiles: 2000,
            scanMaxReadBytes: 262144,
            scanMaxSeconds: 5.0,
            searchMaxFilesScanned: 2000,
            searchMaxMatches: 100,
            searchMaxBytesPerFile: 262144,
            searchMaxTotalBytes: 4194304,
            searchMaxSeconds: 5.0,
            readMaxFiles: 12,
            readMaxBytesPerFile: 65536,
            readMaxTotalBytes: 262144,
            contextMaxChars: 48000,
        );
    }
}
