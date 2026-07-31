<?php

declare(strict_types=1);

namespace App\Core\Providers;

/**
 * Catalogo dei modelli locali letto dall'API nativa di LM Studio (`/api/v1/models`).
 *
 * Serve a un caso preciso: su un'installazione nuova i campi modello sono vuoti, il
 * provider manda un nome vuoto e LM Studio risponde "No models loaded". Qui il nome si
 * risolve al momento della richiesta, senza salvare nulla in configurazione.
 *
 * Regole:
 *  - contano solo gli elementi con `type: "llm"`; gli embedding non sono mai una risposta;
 *  - una richiesta con immagini accetta solo `capabilities.vision: true`;
 *  - a parita' di condizioni si preferisce un modello gia' caricato (`loaded_instances`
 *    non vuoto), altrimenti si sceglie in modo deterministico per chiave crescente;
 *  - LM Studio non viene mai avviato, risvegliato o terminato: e' un servizio gia' in
 *    ascolto e questa classe lo interroga in sola lettura.
 */
final class LmStudioCatalog
{
    /** @param array<int, array<string, mixed>> $models elementi grezzi dell'API nativa */
    private function __construct(private readonly array $models)
    {
    }

    /**
     * @param array<string, mixed> $body corpo JSON gia' decodificato
     */
    public static function fromPayload(array $body): self
    {
        $raw = $body['models'] ?? ($body['data'] ?? []);

        return new self(is_array($raw) ? array_values(array_filter($raw, 'is_array')) : []);
    }

    /**
     * `base_url` punta all'API OpenAI-compatibile (…/v1): l'API nativa vive sullo stesso
     * host, sotto /api/v1. Qui si ricava l'una dall'altra senza nuove impostazioni.
     */
    public static function nativeUrl(string $baseUrl): string
    {
        $trimmed = rtrim(trim($baseUrl), '/');
        if ($trimmed === '') {
            return '';
        }
        if (str_ends_with($trimmed, '/api/v1')) {
            return $trimmed . '/models';
        }
        if (str_ends_with($trimmed, '/v1')) {
            $trimmed = substr($trimmed, 0, -3);
        }

        return rtrim($trimmed, '/') . '/api/v1/models';
    }

    /**
     * @param callable(string): (string|false) $fetcher legge l'URL; iniettabile nei test
     */
    public static function fetch(string $baseUrl, int $timeoutSeconds = 5, ?callable $fetcher = null): ?self
    {
        $url = self::nativeUrl($baseUrl);
        if ($url === '') {
            return null;
        }

        if ($fetcher === null) {
            $fetcher = static function (string $target) use ($timeoutSeconds) {
                $context = stream_context_create(['http' => [
                    'method' => 'GET',
                    'timeout' => max(1, $timeoutSeconds),
                    'ignore_errors' => true,
                ]]);

                return @file_get_contents($target, false, $context);
            };
        }

        $raw = $fetcher($url);
        if (!is_string($raw) || $raw === '') {
            return null;                       // API non raggiungibile: nessun catalogo
        }
        $body = json_decode($raw, true);

        return is_array($body) ? self::fromPayload($body) : null;
    }

    /**
     * Modelli linguistici, esclusi gli embedding, filtrati per capacita' se serve vision.
     *
     * @return array<int, array<string, mixed>>
     */
    public function llms(bool $visionRequired = false): array
    {
        $out = [];
        foreach ($this->models as $model) {
            if (($model['type'] ?? '') !== 'llm') {
                continue;                      // embedding e altri tipi: mai
            }
            $key = trim((string) ($model['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            if ($visionRequired && !$this->hasVision($model)) {
                continue;
            }
            $out[] = $model;
        }

        usort($out, static fn (array $a, array $b): int => strcmp((string) $a['key'], (string) $b['key']));

        return $out;
    }

    /** Il modello indicato esiste ancora ed e' adatto al ruolo richiesto? */
    public function has(string $key, bool $visionRequired = false): bool
    {
        $key = trim($key);
        if ($key === '') {
            return false;
        }
        foreach ($this->llms($visionRequired) as $model) {
            if ((string) $model['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Scelta deterministica: prima i modelli gia' caricati, poi il primo per chiave.
     * Stringa vuota se non esiste nulla di compatibile.
     */
    public function choose(bool $visionRequired = false): string
    {
        $candidates = $this->llms($visionRequired);
        if ($candidates === []) {
            return '';
        }

        foreach ($candidates as $model) {
            if ($this->isLoaded($model)) {
                return (string) $model['key'];
            }
        }

        return (string) $candidates[0]['key'];
    }

    public function hasAnyLlm(bool $visionRequired = false): bool
    {
        return $this->llms($visionRequired) !== [];
    }

    /** @param array<string, mixed> $model */
    private function hasVision(array $model): bool
    {
        $capabilities = $model['capabilities'] ?? null;

        return is_array($capabilities) && ($capabilities['vision'] ?? false) === true;
    }

    /** @param array<string, mixed> $model */
    private function isLoaded(array $model): bool
    {
        $instances = $model['loaded_instances'] ?? null;
        if (is_array($instances) && $instances !== []) {
            return true;
        }

        return ($model['state'] ?? '') === 'loaded';
    }
}
