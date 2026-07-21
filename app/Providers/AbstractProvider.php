<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AIProviderInterface;
use App\Core\Cancellation\CancellationToken;
use App\Core\ContextEngine\ContextInterface;
use App\Core\ContextEngine\SystemPromptContextInterface;
use App\Services\AIProviderResult;

abstract class AbstractProvider implements AIProviderInterface
{
    public function defaults(): array
    {
        return [
            'label' => $this->label(),
            'base_url' => $this->baseUrl(),
            'model' => $this->defaultModel(),
            'requires_key' => $this->requiresKey(),
            'enabled' => $this->enabledByDefault(),
            'timeout_seconds' => 30,
            'temperature' => 0.7,
            'max_tokens' => 2048,
            'top_p' => 1.0,
            'priority' => $this->enabledByDefault() ? 100 : 50,
            'mode' => 'auto',
            'status' => 'offline',
        ];
    }

    public function canAttempt(array $config): array
    {
        if ($this->requiresKey() && empty($config['api_key'])) {
            return ['ok' => false, 'message' => 'Chiave API mancante.'];
        }

        if ($this->hasImageAttachments($config) && !$this->supportsVision()) {
            return ['ok' => false, 'message' => $this->label() . ' non supporta allegati immagine in questa configurazione.'];
        }

        $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            return ['ok' => false, 'message' => 'Endpoint non configurato.'];
        }

        return ['ok' => true, 'message' => 'Configurazione valida.'];
    }

    public function healthCheck(array $config): array
    {
        return $this->canAttempt($config);
    }

    public function createRequest(string $prompt, ContextInterface $context): array
    {
        return [
            'prompt' => $prompt,
            'context' => $context->toArray(),
        ];
    }

    public function models(array $config): array
    {
        $model = (string) ($config['model'] ?? $this->defaultModel());
        return $model === '' ? [] : [$model];
    }

    abstract protected function baseUrl(): string;

    abstract protected function defaultModel(): string;

    protected function systemMessage(ContextInterface $context): string
    {
        // ADDITIVO: un contesto con system prompt DEDICATO (oggi Code) ha la precedenza e non
        // passa ne' dal ramo "chat libera" ne' da quello "progetto LLM". I contesti LLM non
        // implementano l'interfaccia, quindi il loro comportamento resta invariato.
        if ($context instanceof SystemPromptContextInterface) {
            return $context->systemPrompt();
        }

        $hasWeb = $this->hasWebContext($context);

        if ((int) ($context->project()['is_system'] ?? 0) === 1) {
            $free = array_merge([
                'Sei un assistente AI conversazionale.',
                'Data e ora correnti: ' . date('d/m/Y H:i') . '. Usa questo come unico riferimento per qualsiasi domanda su anno, mese o data odierna, ignorando le date del tuo addestramento.',
                'Rispondi in modo diretto, utile e naturale.',
                'Questa e una chat libera senza memoria di progetto e senza strumenti di workspace.',
                'Non citare AIManager, provider, orchestrator, execution state, task interni, log o configurazioni tecniche, salvo richiesta esplicita dell utente.',
                'Se non hai abbastanza informazioni, chiedi una precisazione semplice.',
            ], $this->antiHallucinationLines($hasWeb));

            if ($hasWeb) {
                $free[] = '';
                $free[] = 'Risultati di ricerca web:';
                $free = array_merge($free, $this->webContextLines($context));
            }

            return implode("\n", $free);
        }

        $lines = [
            'Sei AIManager, assistente di ricerca e sviluppo per il Workspace del progetto.',
            'Data e ora correnti: ' . date('d/m/Y H:i') . '. Usa questo come riferimento per le domande temporali, ignorando le date del tuo addestramento.',
            'Usa il contesto fornito quando e rilevante. Se il contesto non basta, dichiaralo con chiarezza.',
            'La cronologia conversazionale e materiale storico: non trattare vecchi audit, vecchi errori o vecchie diagnosi come stato attuale se non sono confermati dal contesto corrente.',
            'Non esporre stato interno, provider, orchestrator, execution state, task tecnici, log o update interni, salvo richiesta esplicita dell utente.',
            'Non aggiungere sezioni come Internal State Update nelle risposte all utente.',
        ];
        $lines = array_merge($lines, $this->antiHallucinationLines($hasWeb));
        $lines[] = '';
        $lines[] = 'Progetto: ' . (string) ($context->project()['name'] ?? '');
        $lines[] = 'Contesto ordinato per priorita:';

        foreach ($context->items() as $item) {
            if ($item->source === 'web') {
                $url = trim((string) ($item->metadata['url'] ?? ''));
                $lines[] = sprintf(
                    '- Fonte web: %s%s. Sintesi: %s',
                    $item->title,
                    $url !== '' ? ' - URL: ' . $url : '',
                    $item->content
                );
                continue;
            }

            $source = $item->source === 'execution_state' ? 'memoria' : $item->source;
            $type = $item->type === 'continuation' ? 'continuita' : $item->type;
            $title = $item->title === 'Execution State' ? 'Memoria di continuita' : $item->title;
            $lines[] = sprintf('- [%s:%s:%d] %s: %s', $source, $type, $item->priority, $title, $item->content);
        }

        return implode("\n", $lines);
    }

    /**
     * Istruzioni anti-allucinazione + grounding. Quando sono presenti fonti web nel
     * contesto, l'AI deve ancorarsi a quelle e citare gli URL; in ogni caso deve
     * preferire "non sono sicuro" all'invenzione su fatti recenti/verificabili.
     *
     * @return string[]
     */
    protected function antiHallucinationLines(bool $hasWeb): array
    {
        $lines = [
            'Se non sei sicuro di un fatto - soprattutto su eventi recenti, prodotti, versioni, prezzi, aziende o persone - dichiaralo invece di inventare. Meglio "non sono sicuro" o "non ho questa informazione aggiornata" che una risposta plausibile ma falsa.',
            'Non inventare nomi, date, cifre, citazioni, URL o riferimenti. Se non li conosci, dillo.',
            'Vai dritto alla risposta. Non riversare in chat tutto il ragionamento passo-passo: dai la conclusione chiara e, se serve, solo i passaggi chiave. Mostra il procedimento completo solo se l utente lo chiede.',
        ];

        if ($hasWeb) {
            $lines[] = 'Sono stati recuperati risultati di ricerca web. Basa la risposta su queste fonti quando rispondono alla domanda. Quando citi una fonte, usa link Markdown leggibili come [Nome fonte](URL), non copiare etichette interne o codici tipo web:search_result. Se le fonti non bastano o si contraddicono, dichiaralo apertamente e non colmare i vuoti con invenzioni.';
        }

        return $lines;
    }

    /**
     * Storico conversazionale come messaggi OpenAI-compatibili (user/assistant).
     * Permette i follow-up: senza questo ogni messaggio sarebbe isolato.
     *
     * @return array<int, array{role: string, content: string}>
     */
    protected function historyMessages(ContextInterface $context): array
    {
        $messages = [];
        foreach ($context->history() as $turn) {
            $content = trim((string) ($turn['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $messages[] = [
                'role' => ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user',
                'content' => $content,
            ];
        }

        return $messages;
    }

    /**
     * Contenuto del messaggio utente per API OpenAI-compatibili: stringa semplice se non
     * ci sono immagini, altrimenti formato multimodale (text + image_url base64) usato dai
     * modelli vision (es. LM Studio con modello vlm). I provider non-vision non ricevono
     * mai immagini perche' canAttempt() le rifiuta prima.
     */
    protected function openAiUserContent(string $prompt, array $config): array|string
    {
        $images = $this->imageAttachments($config);
        if ($images === []) {
            return $prompt;
        }

        $parts = [['type' => 'text', 'text' => $prompt]];
        foreach ($images as $image) {
            $path = (string) ($image['absolute_path'] ?? '');
            if ($path === '' || !is_file($path)) {
                continue;
            }
            $bytes = file_get_contents($path);
            if (!is_string($bytes) || $bytes === '') {
                continue;
            }
            $mime = (string) ($image['mime'] ?? 'image/png');
            $parts[] = [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode($bytes)],
            ];
        }

        return $parts;
    }

    /**
     * Sequenza messaggi OpenAI-compatibile: system + storico + messaggio corrente.
     * $userContent puo' essere stringa o array (formato multimodale text+image_url).
     */
    protected function openAiMessages(string $prompt, ContextInterface $context, array|string|null $userContent = null): array
    {
        $messages = [['role' => 'system', 'content' => $this->systemMessage($context)]];
        foreach ($this->historyMessages($context) as $message) {
            $messages[] = $message;
        }
        $messages[] = ['role' => 'user', 'content' => $userContent ?? $prompt];

        return $messages;
    }

    /**
     * Storico nel formato Gemini "contents" (role user/model + parts text).
     *
     * @return array<int, array{role: string, parts: array<int, array{text: string}>}>
     */
    protected function geminiHistoryContents(ContextInterface $context): array
    {
        $contents = [];
        foreach ($this->historyMessages($context) as $message) {
            $contents[] = [
                'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $message['content']]],
            ];
        }

        return $contents;
    }

    protected function hasWebContext(ContextInterface $context): bool
    {
        foreach ($context->items() as $item) {
            if ($item->source === 'web' && $item->type !== 'web_status') {
                return true;
            }
        }

        return false;
    }

    /**
     * Righe di contesto per le sole fonti web, nello stesso formato usato per il
     * contesto di progetto. Serve alla chat libera, che altrimenti non renderizza item.
     *
     * @return string[]
     */
    protected function webContextLines(ContextInterface $context): array
    {
        $lines = [];
        $index = 1;
        foreach ($context->items() as $item) {
            if ($item->source !== 'web') {
                continue;
            }

            $url = trim((string) ($item->metadata['url'] ?? ''));
            $lines[] = sprintf(
                '- Fonte web %d: %s%s. Sintesi: %s',
                $index,
                $item->title,
                $url !== '' ? ' - URL: ' . $url : '',
                $item->content
            );
            $index++;
        }

        return $lines;
    }

    protected function postJson(string $url, array $payload, array $headers, int $timeout, ?CancellationToken $cancellation = null): array
    {
        $timeout = $this->streamTimeout($timeout);
        $headerLines = array_merge(['Content-Type: application/json'], $headers);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headerLines) . "\r\n",
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ],
        ]);

        $stream = @fopen($url, 'rb', false, $context);
        if ($cancellation?->isCancelled()) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            return ['ok' => false, 'cancelled' => true, 'error' => 'Richiesta interrotta.'];
        }

        if ($stream === false) {
            return ['ok' => false, 'error' => $this->label() . ' non e raggiungibile.'];
        }

        stream_set_timeout($stream, 1);
        $deadline = microtime(true) + $timeout;
        $body = '';

        while (!feof($stream)) {
            if ($cancellation?->isCancelled()) {
                fclose($stream);
                return ['ok' => false, 'cancelled' => true, 'error' => 'Richiesta interrotta.'];
            }

            if (microtime(true) >= $deadline) {
                fclose($stream);
                return ['ok' => false, 'error' => 'Timeout durante la chiamata a ' . $this->label() . '.'];
            }

            $chunk = fread($stream, 8192);
            if ($chunk === false) {
                fclose($stream);
                return ['ok' => false, 'error' => $this->label() . ' non e raggiungibile.'];
            }

            if ($chunk !== '') {
                $body .= $chunk;
            }
        }

        $meta = stream_get_meta_data($stream);
        fclose($stream);
        if ($cancellation?->isCancelled()) {
            return ['ok' => false, 'cancelled' => true, 'error' => 'Richiesta interrotta.'];
        }

        $status = 200;
        foreach (($meta['wrapper_data'] ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match)) {
                $status = (int) $match[1];
                break;
            }
        }

        if ($status >= 400) {
            return ['ok' => false, 'error' => $this->httpError($status, $body), 'status' => $status, 'body' => $body];
        }

        return ['ok' => true, 'status' => $status, 'body' => $body];
    }

    /**
     * Saldo/credito reale dell'account presso il provider, se espone un endpoint dedicato.
     * Default: non supportato (la maggior parte dei provider non ha un'API di saldo o e' gratis).
     * @return array{total:string,currency:string,available:bool}|null
     */
    public function accountBalance(array $config): ?array
    {
        return null;
    }

    /**
     * Campi extra specifici del provider da fondere nel payload di chat/completions
     * (es. DeepSeek V4: disattivazione del thinking). Default: nessuno.
     */
    protected function extraPayload(array $config): array
    {
        return [];
    }

    protected function getJson(string $url, array $headers, int $timeout): array
    {
        $timeout = $this->streamTimeout($timeout);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers) . "\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return ['ok' => false, 'error' => $this->label() . ' non e raggiungibile.'];
        }

        $status = 200;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match)) {
                $status = (int) $match[1];
                break;
            }
        }

        if ($status >= 400) {
            return ['ok' => false, 'error' => $this->httpError($status, $body), 'status' => $status, 'body' => $body];
        }

        return ['ok' => true, 'status' => $status, 'body' => $body];
    }

    protected function streamPostJson(string $url, array $payload, array $headers, int $timeout, callable $onDelta, ?CancellationToken $cancellation = null): array
    {
        $timeout = $this->streamTimeout($timeout);
        $headerLines = array_merge(['Content-Type: application/json'], $headers);
        $curl = curl_init($url);
        if ($curl === false) {
            return ['ok' => false, 'error' => $this->label() . ' non e raggiungibile.'];
        }
        $status = 0;
        $content = '';
        $raw = '';
        $model = '';
        $buffer = '';
        $cancelled = false;
        $tokensInput = 0;
        $tokensOutput = 0;
        $finishReason = '';
        $sawDone = false;
        $hadReasoning = false;
        $contentRaw = '';
        $emittedVisible = 0;
        $emittedThink = 0;
        $parser = function (string $line) use (&$content, &$contentRaw, &$emittedVisible, &$emittedThink, &$model, &$raw, &$tokensInput, &$tokensOutput, &$finishReason, &$sawDone, &$hadReasoning, $onDelta): void {
            $raw .= $line . "\n";
            $line = trim($line);
            if ($line === '' || str_starts_with($line, ':') || !str_starts_with($line, 'data:')) {
                return;
            }

            $data = trim(substr($line, 5));
            if ($data === '[DONE]') {
                $sawDone = true;
                return;
            }

            $json = json_decode($data, true);
            if (!is_array($json)) {
                return;
            }

            if (!empty($json['model'])) {
                $model = (string) $json['model'];
            }

            if (isset($json['usage']) && is_array($json['usage'])) {
                $tokensInput = (int) ($json['usage']['prompt_tokens'] ?? $json['usage']['input_tokens'] ?? $tokensInput);
                $tokensOutput = (int) ($json['usage']['completion_tokens'] ?? $json['usage']['output_tokens'] ?? $tokensOutput);
            }

            $choice = $json['choices'][0] ?? [];
            if (!empty($choice['finish_reason'])) {
                $finishReason = (string) $choice['finish_reason'];
            }

            $reasoning = (string) ($choice['delta']['reasoning_content'] ?? $choice['delta']['reasoning'] ?? $choice['message']['reasoning_content'] ?? $choice['message']['reasoning'] ?? '');
            if ($reasoning !== '') {
                $hadReasoning = true;
                $onDelta($reasoning, 'reasoning');
            }

            $delta = (string) ($choice['delta']['content'] ?? $choice['message']['content'] ?? '');
            if ($delta === '') {
                return;
            }

            $contentRaw .= $delta;
            $content = $this->emitFilteredContent($contentRaw, $emittedVisible, $emittedThink, $hadReasoning, $onDelta, false);
        };

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_BUFFERSIZE => 512,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HEADERFUNCTION => function ($curl, string $header) use (&$status): int {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match)) {
                    $status = (int) $match[1];
                }

                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$buffer, &$cancelled, $cancellation, $parser): int {
                if ($cancellation?->isCancelled() || connection_aborted() === 1) {
                    $cancelled = true;
                    return 0;
                }

                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    $parser($line);
                }

                return strlen($chunk);
            },
        ]);

        if (defined('CURLOPT_TCP_NODELAY')) {
            curl_setopt($curl, CURLOPT_TCP_NODELAY, true);
        }

        $ok = curl_exec($curl);
        $curlError = curl_error($curl);
        if ($status === 0) {
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        }

        if ($buffer !== '') {
            $parser($buffer);
        }

        // Flush finale: emette la coda trattenuta e consegna il contenuto visibile ripulito
        // dagli eventuali blocchi <think> (ragionamento in chiaro inline da alcuni modelli).
        $content = $this->emitFilteredContent($contentRaw, $emittedVisible, $emittedThink, $hadReasoning, $onDelta, true);

        if ($cancelled) {
            return ['ok' => false, 'cancelled' => true, 'error' => 'Richiesta interrotta.', 'content' => $content, 'model' => $model, 'tokens_input' => $tokensInput, 'tokens_output' => $tokensOutput];
        }

        if ($ok === false) {
            return ['ok' => false, 'error' => $curlError !== '' ? $curlError : $this->label() . ' non e raggiungibile.', 'body' => $raw, 'content' => $content, 'model' => $model, 'tokens_input' => $tokensInput, 'tokens_output' => $tokensOutput];
        }

        if ($status >= 400) {
            return ['ok' => false, 'error' => $this->httpError($status, $raw), 'status' => $status, 'body' => $raw, 'content' => $content, 'model' => $model, 'tokens_input' => $tokensInput, 'tokens_output' => $tokensOutput];
        }

        // Stream chiuso con del testo ma SENZA segnale di fine (nessun finish_reason e nessun
        // [DONE]): e' un troncamento di trasporto, non la risposta reale. Lo trattiamo come
        // errore cosi' il ProviderManager fa fallback su un altro provider invece di salvare il
        // parziale. Tutti i provider OpenAI-compatibili in uso mandano almeno uno dei due marker
        // a fine risposta (verificato: groq/cerebras/mistral/deepseek/openrouter), quindi una
        // risposta completa non viene mai scartata per errore.
        if ($content !== '' && $finishReason === '' && !$sawDone) {
            return ['ok' => false, 'error' => $this->label() . ' ha interrotto lo stream prima della fine (risposta troncata).', 'body' => $raw, 'content' => $content, 'model' => $model, 'tokens_input' => $tokensInput, 'tokens_output' => $tokensOutput, 'finish_reason' => $finishReason];
        }

        return ['ok' => $content !== '', 'body' => $raw, 'content' => $content, 'model' => $model, 'tokens_input' => $tokensInput, 'tokens_output' => $tokensOutput, 'finish_reason' => $finishReason, 'error' => $content === '' ? $this->emptyContentError($finishReason, $hadReasoning) : ''];
    }

    /**
     * Separa i blocchi <think>...</think> dal testo: ritorna [visibile, ragionamento].
     * Alcuni modelli ragionanti (qwen, gpt-oss, deepseek, glm) emettono il pensiero inline
     * nel content invece che nel campo reasoning: senza questo finirebbe in chiaro in chat.
     * Un <think> non chiuso (stream incompleto) manda tutto il resto al ragionamento.
     *
     * @return array{0: string, 1: string}
     */
    protected function splitThinkTags(string $raw): array
    {
        if (stripos($raw, '<think') === false) {
            return [$raw, ''];
        }

        $visible = '';
        $think = '';
        $offset = 0;
        while (true) {
            $open = stripos($raw, '<think>', $offset);
            if ($open === false) {
                $visible .= substr($raw, $offset);
                break;
            }
            $visible .= substr($raw, $offset, $open - $offset);
            $close = stripos($raw, '</think>', $open);
            if ($close === false) {
                $think .= substr($raw, $open + 7);
                break;
            }
            $think .= substr($raw, $open + 7, $close - ($open + 7));
            $offset = $close + 8;
        }

        return [$visible, $think];
    }

    /**
     * Streaming-safe: dato tutto il grezzo accumulato finora, separa ragionamento (<think>)
     * e contenuto visibile, emette solo le porzioni NUOVE sui rispettivi canali e ritorna il
     * visibile pulito. Trattiene una piccola coda (8 byte) finche' non e' il flush finale, cosi'
     * un tag <think> spezzato tra due chunk non viene mai mostrato in chiaro. Il taglio e'
     * allineato ai confini UTF-8 per non spezzare un carattere multibyte.
     */
    protected function emitFilteredContent(string $raw, int &$emittedVisible, int &$emittedThink, bool &$hadReasoning, callable $onDelta, bool $final): string
    {
        [$visible, $think] = $this->splitThinkTags($raw);
        $hold = $final ? 0 : 8;

        $visTarget = $this->utf8Boundary($visible, max($emittedVisible, strlen($visible) - $hold));
        if ($visTarget > $emittedVisible) {
            $onDelta(substr($visible, $emittedVisible, $visTarget - $emittedVisible));
            $emittedVisible = $visTarget;
        }

        if ($think !== '') {
            $hadReasoning = true;
        }
        $thinkTarget = $this->utf8Boundary($think, max($emittedThink, strlen($think) - $hold));
        if ($thinkTarget > $emittedThink) {
            $onDelta(substr($think, $emittedThink, $thinkTarget - $emittedThink), 'reasoning');
            $emittedThink = $thinkTarget;
        }

        return $visible;
    }

    private function utf8Boundary(string $s, int $pos): int
    {
        $len = strlen($s);
        if ($pos >= $len) {
            return $len;
        }
        if ($pos < 0) {
            return 0;
        }
        // Indietreggia se $pos cade su un byte di continuazione UTF-8 (10xxxxxx).
        while ($pos > 0 && (ord($s[$pos]) & 0xC0) === 0x80) {
            $pos--;
        }

        return $pos;
    }

    protected function emptyContentError(string $finishReason, bool $hadReasoning): string
    {
        if ($finishReason === 'length' && $hadReasoning) {
            return $this->label() . ' ha esaurito i token disponibili ragionando senza completare la risposta. Aumenta max_tokens o usa un modello senza ragionamento per le richieste brevi.';
        }

        return $this->label() . ' non ha restituito contenuto.';
    }

    protected function streamTimeout(int $timeout): int
    {
        $timeout = max(1, $timeout);
        $maxExecution = (int) ini_get('max_execution_time');
        if ($maxExecution > 0) {
            $timeout = min($timeout, max(1, $maxExecution - 5));
        }

        return $timeout;
    }

    protected function resultMeta(array $config, int $responseTimeMs = 0): array
    {
        return [
            'provider' => $this->key(),
            'model' => (string) ($config['model'] ?? $this->defaultModel()),
            'endpoint' => rtrim((string) ($config['base_url'] ?? $this->baseUrl()), '/'),
            'response_time_ms' => $responseTimeMs,
        ];
    }

    protected function estimatePayloadTokens(array $messages): int
    {
        $chars = 0;
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $chars += strlen((string) ($message['content'] ?? '')) + 12;
        }

        if ($chars === 0) {
            return 0;
        }

        return max(1, (int) ceil($chars / 4));
    }

    protected function estimateTextTokens(string $text): int
    {
        $length = strlen($text);
        if ($length === 0) {
            return 0;
        }

        return max(1, (int) ceil($length / 4));
    }

    protected function requiresKey(): bool
    {
        return true;
    }

    protected function supportsVision(): bool
    {
        return false;
    }

    protected function imageAttachments(array $config): array
    {
        return array_values(array_filter($config['_attachments'] ?? [], fn (array $attachment): bool => !empty($attachment['is_image'])));
    }

    protected function hasImageAttachments(array $config): bool
    {
        return $this->imageAttachments($config) !== [];
    }

    protected function parseModelIds(array $body): array
    {
        $rows = $body['data'] ?? $body['models'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $models = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $id = (string) ($row['id'] ?? $row['name'] ?? '');
                if (str_starts_with($id, 'models/')) {
                    $id = substr($id, 7);
                }
                if ($id !== '') {
                    $models[] = $id;
                }
            }
        }

        sort($models);
        return array_values(array_unique($models));
    }

    protected function httpError(int $status, string $body): string
    {
        if ($status === 401 || $status === 403) {
            return 'Errore autenticazione ' . $this->label() . '. Verifica API key e permessi.';
        }

        if ($status === 404) {
            return $this->label() . ' non trova endpoint o modello configurato.';
        }

        if ($status === 408 || $status === 504) {
            return 'Timeout durante la chiamata a ' . $this->label() . '.';
        }

        $decoded = json_decode($body, true);
        $message = is_array($decoded) ? (string) ($decoded['error']['message'] ?? $decoded['message'] ?? '') : '';
        return trim($this->label() . ' ha restituito HTTP ' . $status . '. ' . $message);
    }

    protected function enabledByDefault(): bool
    {
        return false;
    }
}
