<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 1 / F1.2. Taglio/sanificazione UTF-8 SICURO.
 *
 * Il contratto del contesto è in BYTE (`strlen(output) <= budget`), ma un `substr()` cieco
 * può cadere in mezzo a un carattere multibyte (`è`, emoji, alfabeti non latini) o lasciar
 * passare byte non validi presenti nel file sorgente: il risultato sarebbe UTF-8 invalido e
 * farebbe fallire `json_encode()` quando il contesto viene inviato al provider.
 *
 * Questo helper, senza dipendere da configurazioni globali di mbstring/iconv, decodifica il
 * testo byte per byte e tiene SOLO le sequenze UTF-8 complete e in FORMA CANONICA:
 *  - il limite è in byte e non viene mai superato;
 *  - non restituisce mai UTF-8 spezzato;
 *  - rifiuta overlong (C0/C1, E0 80.., F0 80..), surrogati (ED A0..BF) e fuori-range
 *    (F4 90.., F5..F7), oltre a leading errati e continuazioni mancanti/incomplete;
 *  - i byte non validi vengono SCARTATI resincronizzando di UN byte (deterministico).
 *
 * Regole sul secondo byte per lead: C2–DF → 80–BF; E0 → A0–BF; ED → 80–9F; altri E→ 80–BF;
 * F0 → 90–BF; F1–F3 → 80–BF; F4 → 80–8F. I byte di continuazione successivi sono 80–BF.
 */
final class Utf8
{
    /** Tiene solo UTF-8 valido e canonico, entro $maxBytes byte. */
    public static function cut(string $text, int $maxBytes): string
    {
        if ($maxBytes <= 0) {
            return '';
        }

        $out = '';
        $len = strlen($text);
        $i = 0;

        while ($i < $len) {
            $c = ord($text[$i]);

            if ($c < 0x80) {
                if (strlen($out) + 1 > $maxBytes) {
                    break;
                }
                $out .= $text[$i];
                $i++;
                continue;
            }

            // Determina lunghezza e il range AMMESSO per il SECONDO byte ($lo..$hi); i byte
            // di continuazione seguenti sono sempre 80..BF.
            $lo = 0x80;
            $hi = 0xBF;
            if ($c >= 0xC2 && $c <= 0xDF) {
                $seqLen = 2;
            } elseif ($c === 0xE0) {
                $seqLen = 3;
                $lo = 0xA0; // no overlong
            } elseif ($c >= 0xE1 && $c <= 0xEC) {
                $seqLen = 3;
            } elseif ($c === 0xED) {
                $seqLen = 3;
                $hi = 0x9F; // no surrogati (D800..DFFF)
            } elseif ($c >= 0xEE && $c <= 0xEF) {
                $seqLen = 3;
            } elseif ($c === 0xF0) {
                $seqLen = 4;
                $lo = 0x90; // no overlong
            } elseif ($c >= 0xF1 && $c <= 0xF3) {
                $seqLen = 4;
            } elseif ($c === 0xF4) {
                $seqLen = 4;
                $hi = 0x8F; // <= U+10FFFF
            } else {
                $i++; // C0/C1, F5..FF, o continuazione vagante: scarta e resincronizza
                continue;
            }

            if ($i + $seqLen > $len) {
                break; // sequenza incompleta a fine stringa
            }

            $b1 = ord($text[$i + 1]);
            if ($b1 < $lo || $b1 > $hi) {
                $i++; // secondo byte fuori dal range canonico
                continue;
            }
            $valid = true;
            for ($k = 2; $k < $seqLen; $k++) {
                $b = ord($text[$i + $k]);
                if ($b < 0x80 || $b > 0xBF) {
                    $valid = false;
                    break;
                }
            }
            if (!$valid) {
                $i++; // continuazione successiva non valida
                continue;
            }

            if (strlen($out) + $seqLen > $maxBytes) {
                break; // supererebbe il budget: fermati su un confine di carattere
            }

            $out .= substr($text, $i, $seqLen);
            $i += $seqLen;
        }

        return $out;
    }

    /**
     * Rimuove i byte non validi senza tagliare per lunghezza. Scartare non può far crescere la
     * stringa, quindi usare strlen come tetto è sicuro (il break sul budget non scatta mai
     * prima del dovuto).
     */
    public static function clean(string $text): string
    {
        return self::cut($text, strlen($text));
    }
}
