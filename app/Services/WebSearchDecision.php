<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Parsing puro della risposta del router (provider) che decide l'azione per
 * l'ultimo messaggio: chat / web / image. Estratto da WebSearchIntentService per
 * testarlo in isolamento: il parsing e' la parte fragile (modelli che sporcano il
 * JSON con ragionamento inline o fence markdown), ed e' quella che se si rompe fa
 * spegnere la ricerca web in silenzio.
 */
final class WebSearchDecision
{
    /**
     * @return array{decided: bool, action: string, query: string, image_prompt: string}|null
     *   null se la risposta non contiene una decisione valida.
     */
    public static function parse(string $content): ?array
    {
        // Pulizia/estrazione JSON condivisa (blocchi <think>, fence, prosa attorno).
        $data = LlmJsonExtractor::extractObject($content);
        if ($data === null || !array_key_exists('action', $data)) {
            return null;
        }

        $action = strtolower(trim((string) $data['action']));
        if (!in_array($action, ['chat', 'web', 'image'], true)) {
            return null;
        }

        return [
            'decided' => true,
            'action' => $action,
            'query' => trim((string) ($data['query'] ?? '')),
            'image_prompt' => trim((string) ($data['image_prompt'] ?? '')),
        ];
    }
}
