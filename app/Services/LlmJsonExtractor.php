<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Estrazione pura di un oggetto JSON da una risposta LLM "sporca": blocchi <think>
 * dei reasoning model (anche NON chiusi), fence markdown, prosa attorno al JSON.
 *
 * Unico punto per la pulizia, condiviso da chi legge JSON dai modelli (router web,
 * consolidatore del Project Brain): la validazione dello schema resta al chiamante.
 * Prima questa logica era duplicata e aveva gia' iniziato a divergere (il router
 * recuperava il JSON dopo un <think> non chiuso, il Brain no).
 */
final class LlmJsonExtractor
{
    /**
     * Ritorna l'oggetto JSON decodificato (array associativo) o null se non se ne
     * trova uno valido.
     *
     * @return array<string, mixed>|null
     */
    public static function extractObject(string $content): ?array
    {
        // Blocchi <think>...</think> chiusi: via del tutto.
        $clean = preg_replace('/<think>.*?<\/think>/is', '', $content) ?? $content;
        // Tag <think>/</think> spaiati (ragionamento non chiuso): togli SOLO i tag, non
        // il contenuto che segue, cosi' un JSON dopo un <think> non chiuso non va perso.
        $clean = preg_replace('/<\/?think>/i', '', $clean) ?? $clean;
        // Fence markdown.
        $clean = preg_replace('/```[a-zA-Z]*\s*/', '', $clean) ?? $clean;
        $clean = str_replace('```', '', $clean);

        $offset = 0;
        while (($start = strpos($clean, '{', $offset)) !== false) {
            $candidate = self::balancedObjectAt($clean, $start);
            if ($candidate !== null) {
                $data = json_decode($candidate, true);
                if (is_array($data)) {
                    return $data;
                }
            }
            // Il primo blocco tra graffe puo' essere solo prosa/esempio non JSON:
            // prova anche gli oggetti successivi invece di inglobare tutto fino all'ultima }.
            $offset = $start + 1;
        }

        return null;
    }

    /** Estrae l'oggetto bilanciato che inizia a $start, rispettando stringhe ed escape JSON. */
    private static function balancedObjectAt(string $text, int $start): ?string
    {
        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($text);

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }
}
