<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Reparto Code — Fase 0 / F0.3. Mappa compatta del progetto (Idea 2 / repo-map):
 * elenco file + simboli, resa in testo entro un budget GARANTITO. Non tocca il
 * filesystem: la costruisce il RepoScanner.
 */
final class RepoMap
{
    /**
     * @param array<int, array{path: string, symbols: array<int, string>}> $files ordinati per path
     */
    public function __construct(
        private readonly array $files,
        private readonly bool $truncated = false,
    ) {
    }

    /** @return array<int, array{path: string, symbols: array<int, string>}> */
    public function files(): array
    {
        return $this->files;
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    /**
     * Testo compatto della mappa, con la garanzia strlen(output) <= $budget per QUALSIASI
     * budget (anche minuscolo). Se la mappa è tagliata — per limiti di scansione o per il
     * budget stesso — lo segnala con un marcatore.
     */
    public function toPrompt(int $budget): string
    {
        if ($budget <= 0) {
            return '';
        }

        $lines = ['Mappa del progetto:'];
        foreach ($this->files as $file) {
            $lines[] = $file['path'];
            foreach ($file['symbols'] as $symbol) {
                $lines[] = '  ' . $symbol;
            }
        }
        $body = implode("\n", $lines);

        $marker = '[mappa troncata]';
        $suffix = $this->truncated ? "\n" . $marker : '';

        // Caso pieno: tutto entra (con l'eventuale nota di troncamento strutturale).
        if (strlen($body) + strlen($suffix) <= $budget) {
            return $body . $suffix;
        }

        // Va tagliato per budget: si segnala comunque il troncamento e si resta <= budget.
        if (strlen($marker) >= $budget) {
            return substr($marker, 0, $budget);
        }
        $room = $budget - strlen($marker) - 1; // -1 per il newline separatore
        $cut = substr($body, 0, max(0, $room));
        $nl = strrpos($cut, "\n");
        if ($nl !== false) {
            $cut = substr($cut, 0, $nl); // evita una riga tagliata a metà
        }
        $result = $cut === '' ? $marker : $cut . "\n" . $marker;

        return substr($result, 0, $budget); // clamp finale di sicurezza
    }
}
