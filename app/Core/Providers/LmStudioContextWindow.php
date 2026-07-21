<?php

declare(strict_types=1);

namespace App\Core\Providers;

/**
 * Logica condivisa per leggere la finestra di contesto REALE di LM Studio.
 *
 * LM Studio carica spesso i modelli con una finestra molto piu' piccola del massimo
 * teorico (es. 8192 su qwen3.5-9b che ne supporta 262144). Sia il routing dei provider
 * (ProviderManager) sia la selezione del documento residente (LocalDocumentRetriever)
 * devono conoscere la capacita' EFFETTIVA, non quella dichiarata dal modello.
 *
 * Qui vivono le due parti pure e identiche che prima erano duplicate nei due chiamanti:
 * il calcolo della base URL dall'endpoint e la lettura del `loaded_context_length` dalla
 * risposta di `/api/v0/models`. La chiamata HTTP resta nei chiamanti perche' differisce
 * (cancellazione vs memoizzazione, timeout diversi).
 */
final class LmStudioContextWindow
{
    /**
     * Da "http://host:1234/v1" (endpoint OpenAI-compatibile) ricava la base
     * "http://host:1234" su cui vive l'API REST v0 di LM Studio.
     * Stringa vuota se l'endpoint non e' valido.
     */
    public static function apiBase(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return '';
        }
        $parts = parse_url($endpoint);
        if (empty($parts['host'])) {
            return '';
        }
        $scheme = $parts['scheme'] ?? 'http';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return $scheme . '://' . $parts['host'] . $port;
    }

    /**
     * Dalla risposta JSON decodificata di `/api/v0/models` ricava la finestra reale:
     * il massimo `loaded_context_length` tra i modelli in stato "loaded".
     * Restituisce null se la risposta non e' valida o nessun modello e' caricato.
     *
     * @param mixed $json risposta gia' decodificata (json_decode(..., true))
     */
    public static function fromModelsResponse($json): ?int
    {
        if (!is_array($json) || empty($json['data']) || !is_array($json['data'])) {
            return null;
        }

        $loaded = 0;
        foreach ($json['data'] as $model) {
            if (is_array($model)
                && ($model['state'] ?? '') === 'loaded'
                && (int) ($model['loaded_context_length'] ?? 0) > 0) {
                $loaded = max($loaded, (int) $model['loaded_context_length']);
            }
        }

        return $loaded > 0 ? $loaded : null;
    }
}
