<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Cancellation\CancellationToken;
use App\Core\Configuration\ConfigurationManager;
use App\Core\Providers\ProviderChain;
use App\Core\Providers\ProviderConfigStoreInterface;
use App\Repositories\ProviderConfigRepository;

/**
 * Router di intento AI (Ipotesi B sez. 44 + generazione immagini sez. 46).
 *
 * Un modello veloce ed economico legge la conversazione e decide, in modo
 * indipendente dalla lingua, l'AZIONE per il messaggio corrente:
 *   - "chat"  : rispondi normalmente a parole;
 *   - "web"   : servono fonti web (query nel campo `query`);
 *   - "image" : l'utente vuole generare un'immagine (prompt in `image_prompt`).
 *
 * Sostituisce come rilevatore PRINCIPALE i vecchi trigger a parole-chiave (fragili
 * su lingue/refusi/follow-up). Catena di fallback dei provider (default):
 * deepseek -> cerebras. Se tutti falliscono (provider giu', JSON illeggibile),
 * classify() torna decided=false e il chiamante (Orchestrator) ripiega sul vecchio
 * requiresWeb a parole-chiave SOLO per il web (l'immagine non parte mai da keyword,
 * serve una decisione positiva): nessuna regressione possibile.
 */
final class WebSearchIntentService
{
    private const MAX_HISTORY_TURNS = 6;

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
        return $this->config->get('WEB_INTENT_ENABLED', '1') !== '0' && $this->providerChain() !== [];
    }

    /**
     * Catena del classificatore: i provider scelti nell'env PRIMA, poi in coda tutti gli altri
     * abilitati e compatibili, ordinati per punteggio. Cosi' la decisione non si spegne quando i
     * due nomi espliciti sono giu' o vengono disabilitati.
     *
     * @return string[] catena ordinata (es. ['deepseek','cerebras','groq','openrouter','agnes'])
     */
    public function providerChain(): array
    {
        return ProviderChain::resolve(
            $this->config->get('WEB_INTENT_PROVIDERS', 'deepseek,cerebras'),
            ProviderChain::fallbackTail($this->configs)
        );
    }

    /**
     * Decide l'azione (chat|web|image) per il messaggio corrente.
     *
     * @param array<int, array{role: string, content: string}> $history storico precedente (piu' vecchio -> piu' recente)
     * @return array{decided: bool, action: string, query: string, image_prompt: string, provider: string}
     */
    public function classify(string $currentMessage, array $history, bool $hasResidentKnowledge, ?CancellationToken $cancellation = null): array
    {
        $undecided = ['decided' => false, 'action' => 'chat', 'query' => '', 'image_prompt' => '', 'provider' => ''];
        if (!$this->enabled() || trim($currentMessage) === '' || $cancellation?->isCancelled()) {
            return $undecided;
        }

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $this->userPrompt($currentMessage, $history, $hasResidentKnowledge)],
        ];

        $perAttemptTimeout = $this->requestTimeout();
        $deadline = ProviderChain::deadline($perAttemptTimeout, 20);
        foreach ($this->providerChain() as $provider) {
            if ($cancellation?->isCancelled()) {
                return $undecided;
            }
            $timeout = ProviderChain::remainingTimeout($deadline, $perAttemptTimeout);
            if ($timeout === 0) {
                break;
            }
            $decision = $this->askProvider($provider, $messages, $cancellation, $timeout);
            if ($decision !== null) {
                return $decision + ['provider' => $provider];
            }
        }

        return $undecided;
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array{decided: bool, action: string, query: string, image_prompt: string}|null null = provider fallito o JSON illeggibile
     */
    private function askProvider(string $provider, array $messages, ?CancellationToken $cancellation = null, ?int $timeout = null): ?array
    {
        $config = $this->configs->find($provider);
        if ($config === null) {
            return null;
        }
        if ((int) ($config['enabled'] ?? 0) !== 1) {
            return null;
        }

        $config = $this->config->runtimeConfig($provider, $config);
        $apiKey = (string) ($config['api_key'] ?? '');
        $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        $model = (string) ($config['model'] ?? '');
        if ($apiKey === '' || $baseUrl === '' || $model === '' || $cancellation?->isCancelled()) {
            return null;
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.0,
            'max_tokens' => 300,
        ];
        // DeepSeek V4 parte in modalita' ragionamento: la spegniamo per JSON pulito e veloce.
        if (str_starts_with($model, 'deepseek-v4')) {
            $payload['thinking'] = ['type' => 'disabled'];
        }

        $response = $this->httpJson(
            $baseUrl . '/chat/completions',
            ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}',
            $cancellation,
            $timeout
        );
        if (!$response['ok']) {
            return null;
        }

        $body = json_decode($response['body'], true);
        $content = (string) ($body['choices'][0]['message']['content'] ?? '');
        if ($content === '') {
            return null;
        }

        return $this->parseDecision($content);
    }

    /**
     * Estrae {"action":..,"query":..,"image_prompt":..} da una risposta potenzialmente
     * sporca (code-fence, blocchi <think> dei reasoning model, prosa attorno al JSON).
     *
     * @return array{decided: bool, action: string, query: string, image_prompt: string}|null
     */
    private function parseDecision(string $content): ?array
    {
        return WebSearchDecision::parse($content);
    }

    private function systemPrompt(): string
    {
        return implode("\n", [
            'Sei un router che decide l\'azione giusta per l\'ultimo messaggio dell\'utente.',
            'Rispondi SOLO con un oggetto JSON valido, senza testo prima o dopo, nel formato esatto: {"action": "chat"|"web"|"image", "query": "...", "image_prompt": "..."}.',
            'Scegli UNA sola action:',
            '- "image": l\'utente vuole CREARE/GENERARE/DISEGNARE un\'immagine, una foto, un logo, un\'illustrazione (in QUALSIASI lingua, anche con refusi: "crea un\'immagine", "disegnami", "generate an image", "dibuja"...). In tal caso image_prompt = descrizione dettagliata per il generatore, costruita dalla conversazione, preferibilmente in inglese; query="".',
            '- "web": servono informazioni fresche/aggiornate/verificabili online (notizie, prezzi, meteo, eventi/versioni recenti, "chi e\'/cos\'e\'" su persone/prodotti/aziende), OPPURE l\'utente chiede esplicitamente di cercare. query = la ricerca costruita dalla conversazione (risolvi i follow-up: "cerca online" da solo -> il vero soggetto dei turni precedenti), nella lingua del soggetto; image_prompt="". Se un documento interno del progetto dovrebbe rispondere (hasResidentKnowledge=true) e l\'utente NON chiede esplicitamente il web, usa "chat".',
            '- "chat": tutto il resto (domande normali, compiti creativi/di trasformazione come scrivere/tradurre/riassumere/poesie/email/codice, chiacchiere). query="", image_prompt="".',
            'Non spiegare, non aggiungere commenti: emetti solo il JSON.',
        ]);
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     */
    private function userPrompt(string $currentMessage, array $history, bool $hasResidentKnowledge): string
    {
        $lines = ['hasResidentKnowledge: ' . ($hasResidentKnowledge ? 'true' : 'false'), '', 'Conversazione precedente:'];

        $recent = array_slice($history, -self::MAX_HISTORY_TURNS);
        if ($recent === []) {
            $lines[] = '(nessuna)';
        } else {
            foreach ($recent as $turn) {
                $role = ($turn['role'] ?? '') === 'assistant' ? 'Assistente' : 'Utente';
                $text = trim((string) ($turn['content'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $lines[] = $role . ': ' . mb_substr($text, 0, 500);
            }
        }

        $lines[] = '';
        $lines[] = 'Messaggio corrente dell\'utente: ' . $currentMessage;
        $lines[] = '';
        $lines[] = 'JSON:';

        return implode("\n", $lines);
    }

    /**
     * @return array{ok: bool, body: string, error: string}
     */
    private function httpJson(string $url, array $headers, string $body, ?CancellationToken $cancellation = null, ?int $timeout = null): array
    {
        if ($cancellation?->isCancelled()) {
            return ['ok' => false, 'body' => '', 'error' => 'Richiesta interrotta.'];
        }
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
        if ($cancellation !== null) {
            curl_setopt($curl, CURLOPT_NOPROGRESS, false);
            curl_setopt($curl, CURLOPT_PROGRESSFUNCTION, static function (...$_) use ($cancellation): int {
                return $cancellation->isCancelled() ? 1 : 0;
            });
        }

        $responseBody = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if ($cancellation?->isCancelled()) {
            return ['ok' => false, 'body' => (string) $responseBody, 'error' => 'Richiesta interrotta.'];
        }
        if ($responseBody === false || $status >= 400) {
            return ['ok' => false, 'body' => (string) $responseBody, 'error' => $error !== '' ? $error : ('HTTP ' . $status)];
        }

        return ['ok' => true, 'body' => (string) $responseBody, 'error' => ''];
    }

    private function requestTimeout(): int
    {
        return max(3, min(30, (int) $this->config->get('WEB_INTENT_TIMEOUT', '8')));
    }
}
