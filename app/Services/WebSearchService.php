<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Cancellation\CancellationToken;
use App\Core\Configuration\ConfigurationManager;
use App\Core\ContextEngine\ContextItem;

/**
 * Web search grounding: recupera risultati dal web per ancorare le risposte AI a
 * fonti reali e ridurre le allucinazioni su fatti recenti/verificabili.
 *
 * Backend web:
 *  - tavily: API ottimizzata per LLM (chiave TAVILY_API_KEY)
 *
 * Il servizio fallisce in modo morbido: se la ricerca non va a buon fine restituisce
 * un risultato vuoto con un messaggio, e l'AI prosegue senza fonti web.
 */
final class WebSearchService
{
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    public function __construct(private readonly ConfigurationManager $config)
    {
    }

    public static function fromRoot(string $root): self
    {
        return new self(ConfigurationManager::fromRoot($root));
    }

    public function enabled(): bool
    {
        return $this->config->get('WEB_SEARCH_ENABLED', '1') !== '0';
    }

    /**
     * Esegue la ricerca e restituisce risultati normalizzati.
     *
     * @return array{ok: bool, provider: string, query: string, results: array<int, array{title: string, url: string, snippet: string}>, error: string}
     */
    public function search(string $query, ?CancellationToken $cancellation = null): array
    {
        $query = $this->cleanQuery($query);
        $base = ['ok' => false, 'provider' => '', 'query' => $query, 'results' => [], 'error' => ''];

        if ($cancellation?->isCancelled()) {
            return ['error' => 'Richiesta interrotta.'] + $base;
        }
        if (!$this->enabled()) {
            return ['error' => 'Ricerca web disabilitata.'] + $base;
        }

        if ($query === '') {
            return ['error' => 'Query vuota.'] + $base;
        }

        $max = max(1, min(10, (int) $this->config->get('WEB_SEARCH_MAX_RESULTS', '5')));
        return ['provider' => 'tavily'] + $this->searchTavily($query, $max, $cancellation) + $base;
    }

    /**
     * Converte i risultati in ContextItem ad alta priorita' da iniettare nel Context.
     *
     * @param array<int, array{title: string, url: string, snippet: string}> $results
     * @return ContextItem[]
     */
    public function toContextItems(array $results): array
    {
        $items = [];
        $priority = 96;
        foreach ($results as $index => $result) {
            $title = trim((string) ($result['title'] ?? ''));
            $url = trim((string) ($result['url'] ?? ''));
            $snippet = trim((string) ($result['snippet'] ?? ''));
            if ($url === '' && $snippet === '') {
                continue;
            }

            $items[] = new ContextItem(
                source: 'web',
                type: 'search_result',
                title: ($title !== '' ? $title : 'Risultato web') . ' (' . $url . ')',
                content: $snippet !== '' ? $snippet : $title,
                priority: max(80, $priority - $index),
                metadata: ['url' => $url, 'rank' => $index + 1],
            );
        }

        return $items;
    }

    /**
     * Sceglie la query utile. Se il prompt corrente, tolti i trigger ("cerca su internet",
     * "/web"...), resta vuoto o troppo corto (su un follow-up e' solo un comando), usa il
     * fallback (tipicamente il messaggio utente del turno precedente, dove sta il soggetto).
     */
    public function meaningfulQuery(string $current, string $fallback = ''): string
    {
        $cleanCurrent = $this->cleanQuery($current);
        if ($fallback !== '' && $this->isUnderspecifiedFollowUp($cleanCurrent)) {
            return $fallback;
        }

        if (mb_strlen($cleanCurrent) >= 3) {
            return $current;
        }

        return $fallback !== '' ? $fallback : $current;
    }

    public function queryWasResolvedFromFallback(string $current, string $fallback, string $query): bool
    {
        return $fallback !== ''
            && trim($query) === trim($fallback)
            && $this->isUnderspecifiedFollowUp($this->cleanQuery($current));
    }

    /**
     * Il messaggio corrente e' solo un comando nudo/sottospecificato (es. "cerca online",
     * "verifica", "approfondisci") senza un soggetto proprio? In tal caso il soggetto vero
     * sta nei turni precedenti e la risposta va guidata con un'interpretazione contestuale.
     */
    public function isBareCommand(string $current): bool
    {
        return $this->isUnderspecifiedFollowUp($this->cleanQuery($current));
    }

    private function cleanQuery(string $query): string
    {
        $query = trim($query);
        // Rimuove i trigger espliciti per non sporcare la query di ricerca.
        $query = preg_replace('/^\s*\/web\s+/i', '', $query) ?? $query;
        $query = preg_replace('/\b(cerca(?:mi)?(?:\s+(?:sul|sul\s+web|online|su\s+internet|su\s+google))?|sul\s+web|su\s+internet|online)\b[:,]?/iu', ' ', $query) ?? $query;
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? $query);

        return mb_substr($query, 0, 220);
    }

    private function isUnderspecifiedFollowUp(string $query): bool
    {
        $query = mb_strtolower(trim($query));
        if ($query === '') {
            return true;
        }

        $generic = [
            'esiste',
            'esiste?',
            'vero',
            'vero?',
            'conferma',
            'controlla',
            'verifica',
            'approfondisci',
            'cerca',
            'trova',
            'si',
            'sì',
            'no',
            'questo',
            'questa',
            'quello',
            'quella',
        ];

        if (in_array($query, $generic, true)) {
            return true;
        }

        $words = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return count($words) <= 2 && $this->containsAny($words, $generic);
    }

    private function containsAny(array $words, array $needles): bool
    {
        foreach ($words as $word) {
            if (in_array($word, $needles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{ok: bool, results: array<int, array{title: string, url: string, snippet: string}>, error: string}
     */
    private function searchTavily(string $query, int $max, ?CancellationToken $cancellation = null): array
    {
        $key = $this->config->get('TAVILY_API_KEY');
        if ($key === '') {
            return ['ok' => false, 'results' => [], 'error' => 'Tavily API key mancante.'];
        }
        if ($cancellation?->isCancelled()) {
            return ['ok' => false, 'results' => [], 'error' => 'Richiesta interrotta.'];
        }

        $response = $this->httpJson('POST', 'https://api.tavily.com/search', [
            'Content-Type: application/json',
        ], json_encode([
            'api_key' => $key,
            'query' => $query,
            'search_depth' => 'basic',
            'max_results' => $max,
            'include_answer' => false,
        ], JSON_UNESCAPED_UNICODE) ?: '{}', $cancellation);

        if (!$response['ok']) {
            return ['ok' => false, 'results' => [], 'error' => 'Tavily: ' . $response['error']];
        }

        $data = json_decode($response['body'], true);
        $results = [];
        foreach ((is_array($data['results'] ?? null) ? $data['results'] : []) as $row) {
            $results[] = [
                'title' => (string) ($row['title'] ?? ''),
                'url' => (string) ($row['url'] ?? ''),
                'snippet' => mb_substr((string) ($row['content'] ?? ''), 0, 600),
            ];
            if (count($results) >= $max) {
                break;
            }
        }

        return ['ok' => $results !== [], 'results' => $results, 'error' => $results === [] ? 'Nessun risultato Tavily.' : ''];
    }

    /**
     * @return array{ok: bool, body: string, error: string, status: int}
     */
    private function httpJson(string $method, string $url, array $headers, ?string $body, ?CancellationToken $cancellation = null): array
    {
        if ($cancellation?->isCancelled()) {
            return ['ok' => false, 'body' => '', 'error' => 'Richiesta interrotta.', 'status' => 0];
        }
        $timeout = max(3, min(20, (int) $this->config->get('WEB_SEARCH_TIMEOUT', '8')));
        $curl = curl_init($url);
        if ($curl === false) {
            return ['ok' => false, 'body' => '', 'error' => 'init fallita', 'status' => 0];
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($cancellation !== null) {
            curl_setopt($curl, CURLOPT_NOPROGRESS, false);
            curl_setopt($curl, CURLOPT_PROGRESSFUNCTION, static function (...$_) use ($cancellation): int {
                return $cancellation->isCancelled() ? 1 : 0;
            });
        }
        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, (string) $body);
        }

        $responseBody = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if ($cancellation?->isCancelled()) {
            return ['ok' => false, 'body' => (string) $responseBody, 'error' => 'Richiesta interrotta.', 'status' => $status];
        }
        if ($responseBody === false) {
            return ['ok' => false, 'body' => '', 'error' => $error !== '' ? $error : 'connessione fallita', 'status' => $status];
        }

        if ($status >= 400) {
            return ['ok' => false, 'body' => (string) $responseBody, 'error' => 'HTTP ' . $status, 'status' => $status];
        }

        return ['ok' => true, 'body' => (string) $responseBody, 'error' => '', 'status' => $status];
    }
}
