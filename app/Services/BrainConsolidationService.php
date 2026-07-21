<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Configuration\ConfigurationManager;
use App\Core\Providers\ProviderChain;
use App\Core\Providers\ProviderConfigStoreInterface;
use App\Repositories\ProviderConfigRepository;

/**
 * Consolidatore AI del Project Brain (Fase 1, sez. 18.4 + doc di continuita').
 *
 * Sostituisce come estrattore PRINCIPALE il vecchio classificatore a parole-chiave
 * italiane di ProjectBrainService (fragile su lingue/parafrasi/refusi e generatore di
 * rumore: ogni frase con una keyword diventava una memoria). Un modello veloce ed
 * economico legge il transcript della sessione + le memorie brain gia' esistenti e
 * restituisce SOLO i fatti salienti e riusabili, tipizzati, con la relazione rispetto
 * al gia' noto: new / duplicate / conflict / refine.
 *
 * Stesso schema di WebSearchIntentService (sez. 45): catena di fallback dei provider da
 * .env (default deepseek -> cerebras), parsing JSON robusto (toglie <think>, code-fence,
 * estrae il primo {...}). Se tutti i provider falliscono, consolidate() torna
 * decided=false e il chiamante (ProjectBrainService) ripiega sul vecchio percorso a
 * parole-chiave: nessuna regressione possibile.
 */
final class BrainConsolidationService
{
    /** Tipi ammessi per un item (allineati a MemoryType / brain_category). */
    private const ALLOWED_TYPES = [
        'decision', 'knowledge', 'problem', 'todo',
        'hypothesis_confirmed', 'hypothesis_rejected', 'pattern',
        'change', 'api', 'endpoint', 'database_table',
    ];

    public function __construct(
        private readonly ConfigurationManager $config,
        private readonly ProviderConfigStoreInterface $configs,
    ) {
    }

    public static function fromRoot(string $root): self
    {
        return new self(ConfigurationManager::fromRoot($root), new ProviderConfigRepository());
    }

    public function enabled(): bool
    {
        return $this->config->get('BRAIN_CONSOLIDATION_ENABLED', '1') !== '0' && $this->providerChain() !== [];
    }

    /**
     * Catena del consolidamento: i provider scelti nell'env PRIMA, poi in coda tutti gli altri
     * abilitati e compatibili, ordinati per punteggio. Cosi' il consolidamento non si spegne quando
     * i due nomi espliciti sono giu' o vengono disabilitati.
     *
     * @return string[] catena ordinata (es. ['deepseek','cerebras','groq','openrouter','agnes'])
     */
    public function providerChain(): array
    {
        return ProviderChain::resolve(
            $this->config->get('BRAIN_CONSOLIDATION_PROVIDERS', 'deepseek,cerebras'),
            ProviderChain::fallbackTail($this->configs)
        );
    }

    /**
     * Consolida una sessione in item di memoria salienti.
     *
     * @param array<int, array{role?: string, content?: string}> $messages transcript (piu' vecchio -> piu' recente)
     * @param array<int, array{id: int, category: string, title: string}> $existing memorie brain gia' presenti nel progetto
     * @return array{decided: bool, provider: string, items: array<int, array{type: string, title: string, content: string, importance: int, confidence: float, subject: string, polarity: string, relation: string, relation_id: int}>}
     */
    public function consolidate(array $messages, array $existing): array
    {
        $undecided = ['decided' => false, 'provider' => '', 'items' => []];
        if (!$this->enabled()) {
            return $undecided;
        }

        $transcript = $this->buildTranscript($messages);
        if ($transcript === '') {
            // Nessun messaggio utile: nulla da consolidare, ma NON e' un fallimento
            // del provider -> decided=true con lista vuota (non attivare il fallback).
            return ['decided' => true, 'provider' => 'none', 'items' => []];
        }

        $prompt = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $this->userPrompt($transcript, $existing)],
        ];

        $perAttemptTimeout = $this->requestTimeout();
        $deadline = ProviderChain::deadline($perAttemptTimeout, 60);
        foreach ($this->providerChain() as $provider) {
            $timeout = ProviderChain::remainingTimeout($deadline, $perAttemptTimeout);
            if ($timeout === 0) {
                break;
            }
            $items = $this->askProvider($provider, $prompt, $timeout);
            if ($items !== null) {
                return ['decided' => true, 'provider' => $provider, 'items' => $items];
            }
        }

        return $undecided;
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array<int, array<string, mixed>>|null null = provider fallito o JSON illeggibile
     */
    private function askProvider(string $provider, array $messages, ?int $timeout = null): ?array
    {
        $config = $this->configs->find($provider);
        if ($config === null || (int) ($config['enabled'] ?? 0) !== 1) {
            return null;
        }

        $config = $this->config->runtimeConfig($provider, $config);
        $apiKey = (string) ($config['api_key'] ?? '');
        $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        $model = (string) ($config['model'] ?? '');
        if ($apiKey === '' || $baseUrl === '' || $model === '') {
            return null;
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.0,
            'max_tokens' => 1800,
        ];
        // DeepSeek V4 parte in modalita' ragionamento: la spegniamo per JSON pulito e veloce.
        if (str_starts_with($model, 'deepseek-v4')) {
            $payload['thinking'] = ['type' => 'disabled'];
        }

        $response = $this->httpJson(
            $baseUrl . '/chat/completions',
            ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}',
            $timeout,
        );
        if (!$response['ok']) {
            return null;
        }

        $body = json_decode($response['body'], true);
        $content = (string) ($body['choices'][0]['message']['content'] ?? '');
        if ($content === '') {
            return null;
        }

        return $this->parseItems($content);
    }

    /**
     * Estrae {"items":[...]} da una risposta potenzialmente sporca (code-fence, blocchi
     * <think> dei reasoning model, prosa attorno al JSON).
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function parseItems(string $content): ?array
    {
        // Pulizia/estrazione JSON condivisa col router web (blocchi <think> anche non
        // chiusi, fence, prosa attorno): un solo punto, niente piu' divergenza.
        $data = LlmJsonExtractor::extractObject($content);
        if ($data === null || !array_key_exists('items', $data) || !is_array($data['items'])) {
            return null;
        }

        $items = [];
        foreach ($data['items'] as $raw) {
            $item = $this->sanitizeItem(is_array($raw) ? $raw : []);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{type: string, title: string, content: string, importance: int, confidence: float, subject: string, polarity: string, relation: string, relation_id: int}|null
     */
    private function sanitizeItem(array $raw): ?array
    {
        $type = strtolower(trim((string) ($raw['type'] ?? '')));
        $title = trim((string) ($raw['title'] ?? ''));
        $content = trim((string) ($raw['content'] ?? ''));
        if (!in_array($type, self::ALLOWED_TYPES, true) || $title === '' || $content === '') {
            return null;
        }

        $relation = strtolower(trim((string) ($raw['relation'] ?? 'new')));
        if (!in_array($relation, ['new', 'duplicate', 'conflict', 'refine'], true)) {
            $relation = 'new';
        }

        $polarity = strtolower(trim((string) ($raw['polarity'] ?? 'positive')));
        if (!in_array($polarity, ['positive', 'negative'], true)) {
            $polarity = 'positive';
        }

        $importance = (int) ($raw['importance'] ?? 3);
        $importance = max(1, min(5, $importance));

        $confidence = (float) ($raw['confidence'] ?? 0.7);
        $confidence = max(0.0, min(1.0, $confidence));

        return [
            'type' => $type,
            'title' => mb_substr($title, 0, 120),
            'content' => mb_substr($content, 0, 500),
            'importance' => $importance,
            'confidence' => $confidence,
            'subject' => mb_substr(trim((string) ($raw['subject'] ?? $title)), 0, 120),
            'polarity' => $polarity,
            'relation' => $relation,
            'relation_id' => max(0, (int) ($raw['relation_id'] ?? 0)),
        ];
    }

    /**
     * @param array<int, array{role?: string, content?: string}> $messages
     */
    private function buildTranscript(array $messages): string
    {
        $max = max(10, min(200, (int) $this->config->get('BRAIN_CONSOLIDATION_MAX_MESSAGES', '60')));
        $recent = array_slice($messages, -$max);

        $lines = [];
        foreach ($recent as $message) {
            $role = ($message['role'] ?? '') === 'assistant' ? 'Assistente' : 'Utente';
            $text = trim((string) ($message['content'] ?? ''));
            if ($text === '') {
                continue;
            }
            $lines[] = $role . ': ' . mb_substr($text, 0, 600);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{id: int, category: string, title: string}> $existing
     */
    private function userPrompt(string $transcript, array $existing): string
    {
        $lines = ['MEMORIE GIA\' PRESENTI nel progetto (id | tipo | titolo):'];
        if ($existing === []) {
            $lines[] = '(nessuna)';
        } else {
            foreach ($existing as $row) {
                $lines[] = (int) $row['id'] . ' | ' . (string) $row['category'] . ' | ' . mb_substr((string) $row['title'], 0, 100);
            }
        }

        $lines[] = '';
        $lines[] = 'TRANSCRIPT della sessione da consolidare:';
        $lines[] = $transcript;
        $lines[] = '';
        $lines[] = 'JSON:';

        return implode("\n", $lines);
    }

    private function systemPrompt(): string
    {
        return implode("\n", [
            'Sei un consolidatore di memoria per un progetto software. Leggi il transcript della sessione ed estrai SOLO i fatti salienti e RIUSABILI in futuro.',
            '',
            'SALVA (se realmente presenti): decisioni prese; ipotesi confermate o smentite; problemi/bug rilevanti; TODO ancora aperti; pattern/convenzioni stabili; cambiamenti importanti al progetto; API/endpoint/tabelle di database rilevanti.',
            'NON SALVARE MAI: convenevoli, conferme, ringraziamenti, domande, ragionamenti intermedi, passaggi di servizio, dettagli transitori o qualunque cosa non sia utile in una sessione futura. Nel dubbio, NON salvare. Meglio poche memorie di qualita\' che tanto rumore.',
            'Lavora in QUALSIASI lingua: estrai il fatto a prescindere dalla lingua del transcript.',
            '',
            'Per OGNI item confrontalo con le MEMORIE GIA\' PRESENTI e imposta "relation":',
            '- "new": non e\' gia\' presente. relation_id=0.',
            '- "duplicate": lo stesso fatto e\' gia\' memorizzato. relation_id = id della memoria esistente (la rinforzeremo, non duplicheremo).',
            '- "conflict": CONTRADDICE una memoria esistente (es. decisione opposta sullo stesso soggetto). relation_id = id della memoria in conflitto.',
            '- "refine": aggiorna/espande una memoria esistente senza contraddirla. relation_id = id della memoria esistente.',
            '',
            'Rispondi SOLO con JSON valido, senza testo prima o dopo, nel formato esatto:',
            '{"items":[{"type":"...","title":"...","content":"...","importance":1-5,"confidence":0.0-1.0,"subject":"...","polarity":"positive|negative","relation":"new|duplicate|conflict|refine","relation_id":0}]}',
            'type ammessi: decision, knowledge, problem, todo, hypothesis_confirmed, hypothesis_rejected, pattern, change, api, endpoint, database_table.',
            'title = frase breve; content = il fatto (max ~2 frasi); subject = soggetto del fatto (per rilevare conflitti); polarity = positive salvo negazioni/rifiuti/fallimenti.',
            'Se non c\'e\' NULLA di saliente da salvare, rispondi {"items":[]}.',
        ]);
    }

    /**
     * @return array{ok: bool, body: string, error: string}
     */
    private function httpJson(string $url, array $headers, string $body, ?int $timeout = null): array
    {
        $timeout = $timeout ?? $this->requestTimeout();
        $curl = curl_init($url);
        if ($curl === false) {
            return ['ok' => false, 'body' => '', 'error' => 'init fallita'];
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $responseBody = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if ($responseBody === false || $status >= 400) {
            return ['ok' => false, 'body' => (string) $responseBody, 'error' => $error !== '' ? $error : ('HTTP ' . $status)];
        }

        return ['ok' => true, 'body' => (string) $responseBody, 'error' => ''];
    }

    private function requestTimeout(): int
    {
        return max(5, min(60, (int) $this->config->get('BRAIN_CONSOLIDATION_TIMEOUT', '25')));
    }
}
