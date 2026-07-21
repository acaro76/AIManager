<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 4 / F4.3. Diff unificato: una VISTA DERIVATA server-side del cambiamento, per
 * rendere la proposta REVISIONABILE. Non è mai un input eseguito: si genera dal contenuto
 * vecchio e nuovo (già verificati dal validator), e serve solo a essere mostrato all'utente.
 *
 * Confronto per RIGHE, con contesto, tramite LCS. Su file molto grandi il confronto riga-per-riga
 * sarebbe costoso: oltre un tetto prudente si ricade su una vista GROSSOLANA (blocco rimosso +
 * blocco aggiunto), che resta corretta e leggibile senza far esplodere il costo.
 */
final class UnifiedDiff
{
    /** Righe di contesto attorno a ogni gruppo di modifiche. */
    private const CONTEXT = 3;

    /** Oltre questo prodotto righe×righe si usa la vista grossolana (difesa da file enormi). */
    private const LCS_CELL_CAP = 2_000_000;

    /**
     * Genera il diff unificato tra $old e $new per il file $path. Per una creazione, $old è ''.
     */
    public static function render(string $path, string $old, string $new): string
    {
        if ($old === $new) {
            return '';
        }

        $a = self::lines($old);
        $b = self::lines($new);

        $header = '--- a/' . $path . "\n" . '+++ b/' . $path . "\n";

        if (count($a) * count($b) > self::LCS_CELL_CAP) {
            return $header . self::coarse($a, $b);
        }

        return $header . self::hunks($a, $b);
    }

    /**
     * Numero di righe aggiunte e rimosse (per un riepilogo compatto nella card). Coerente col
     * diff: usa lo stesso confronto per righe (o la stima grossolana sui file enormi).
     *
     * @return array{added: int, removed: int}
     */
    public static function stat(string $old, string $new): array
    {
        if ($old === $new) {
            return ['added' => 0, 'removed' => 0];
        }
        $a = self::lines($old);
        $b = self::lines($new);
        if (count($a) * count($b) > self::LCS_CELL_CAP) {
            return ['added' => count($b), 'removed' => count($a)];
        }

        $script = self::editScript($a, $b);
        $added = 0;
        $removed = 0;
        foreach ($script as [$kind]) {
            if ($kind === '+') {
                $added++;
            } elseif ($kind === '-') {
                $removed++;
            }
        }

        return ['added' => $added, 'removed' => $removed];
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        if ($text === '') {
            return [];
        }

        return explode("\n", $text);
    }

    /**
     * Script di edit riga-per-riga: sequenza di [kind, text] con kind in {' ','-','+'}.
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{0: string, 1: string}>
     */
    private static function editScript(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        // LCS DP: lcs[i][j] = lunghezza della LCS di a[i..] e b[j..].
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $script = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $script[] = [' ', $a[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $script[] = ['-', $a[$i]];
                $i++;
            } else {
                $script[] = ['+', $b[$j]];
                $j++;
            }
        }
        for (; $i < $n; $i++) {
            $script[] = ['-', $a[$i]];
        }
        for (; $j < $m; $j++) {
            $script[] = ['+', $b[$j]];
        }

        return $script;
    }

    /**
     * Raggruppa lo script in hunk unificati con contesto.
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function hunks(array $a, array $b): string
    {
        $script = self::editScript($a, $b);

        // Indici di riga (1-based) mentre si percorre lo script.
        $out = '';
        $count = count($script);
        $k = 0;
        $lineA = 1;
        $lineB = 1;

        while ($k < $count) {
            if ($script[$k][0] === ' ') {
                $lineA++;
                $lineB++;
                $k++;
                continue;
            }

            // Inizio di un gruppo di modifiche: includi fino a CONTEXT righe di contesto prima.
            $groupStart = $k;
            $ctxBefore = 0;
            while ($groupStart > 0 && $script[$groupStart - 1][0] === ' ' && $ctxBefore < self::CONTEXT) {
                $groupStart--;
                $ctxBefore++;
            }

            // Estendi il gruppo fino a quando non ci sono più di CONTEXT righe di contesto di fila.
            $end = $k;
            while ($end < $count) {
                if ($script[$end][0] !== ' ') {
                    $end++;
                    continue;
                }
                // conta il contesto consecutivo
                $run = 0;
                while ($end + $run < $count && $script[$end + $run][0] === ' ') {
                    $run++;
                }
                if ($run > self::CONTEXT * 2 || $end + $run >= $count) {
                    $end += min($run, self::CONTEXT);
                    break;
                }
                $end += $run;
            }

            $out .= self::hunk($script, $groupStart, $end, $lineA - $ctxBefore, $lineB - $ctxBefore);

            // Avanza i contatori di riga fino a $end.
            for (; $k < $end; $k++) {
                $kind = $script[$k][0];
                if ($kind !== '+') {
                    $lineA++;
                }
                if ($kind !== '-') {
                    $lineB++;
                }
            }
        }

        return $out;
    }

    /**
     * @param list<array{0: string, 1: string}> $script
     */
    private static function hunk(array $script, int $start, int $end, int $startA, int $startB): string
    {
        $lenA = 0;
        $lenB = 0;
        $body = '';
        for ($k = $start; $k < $end; $k++) {
            [$kind, $text] = $script[$k];
            $body .= $kind . $text . "\n";
            if ($kind !== '+') {
                $lenA++;
            }
            if ($kind !== '-') {
                $lenB++;
            }
        }

        $headA = $lenA === 0 ? ($startA - 1) . ',0' : $startA . ',' . $lenA;
        $headB = $lenB === 0 ? ($startB - 1) . ',0' : $startB . ',' . $lenB;

        return '@@ -' . $headA . ' +' . $headB . " @@\n" . $body;
    }

    /**
     * Vista grossolana per file enormi: tutte le righe vecchie rimosse, tutte le nuove aggiunte.
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function coarse(array $a, array $b): string
    {
        $headA = $a === [] ? '0,0' : '1,' . count($a);
        $headB = $b === [] ? '0,0' : '1,' . count($b);
        $body = '';
        foreach ($a as $line) {
            $body .= '-' . $line . "\n";
        }
        foreach ($b as $line) {
            $body .= '+' . $line . "\n";
        }

        return '@@ -' . $headA . ' +' . $headB . " @@\n" . $body;
    }
}
