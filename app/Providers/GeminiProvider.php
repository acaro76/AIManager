<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\ContextEngine\ContextInterface;
use App\Services\AIProviderResult;

final class GeminiProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'gemini';
    }

    public function label(): string
    {
        return 'Gemini';
    }

    protected function baseUrl(): string
    {
        return 'https://generativelanguage.googleapis.com/v1beta';
    }

    protected function defaultModel(): string
    {
        return 'gemini-2.5-flash-lite';
    }

    protected function supportsVision(): bool
    {
        return true;
    }

    public function healthCheck(array $config): array
    {
        if (empty($config['api_key'])) {
            return ['ok' => false, 'message' => 'Chiave API mancante.'];
        }
        $baseUrl = rtrim((string) ($config['base_url'] ?? $this->baseUrl()), '/');
        $started = microtime(true);
        $response = $this->getJson($baseUrl . '/models?key=' . rawurlencode((string) $config['api_key']), [], (int) ($config['timeout_seconds'] ?? 10));
        $elapsed = (int) round((microtime(true) - $started) * 1000);
        return $response['ok']
            ? ['ok' => true, 'message' => 'Gemini ONLINE. Endpoint modelli raggiungibile.', 'response_time_ms' => $elapsed]
            : ['ok' => false, 'message' => $response['error'], 'response_time_ms' => $elapsed];
    }

    public function models(array $config): array
    {
        if (empty($config['api_key'])) {
            return [];
        }

        $baseUrl = rtrim((string) ($config['base_url'] ?? $this->baseUrl()), '/');
        $response = $this->getJson($baseUrl . '/models?key=' . rawurlencode((string) $config['api_key']), [], (int) ($config['timeout_seconds'] ?? 10));
        if (empty($response['ok'])) {
            return [];
        }

        $body = json_decode((string) $response['body'], true);
        return is_array($body) ? $this->parseModelIds($body) : [];
    }

    public function stream(string $prompt, ContextInterface $context, array $config, callable $onDelta): AIProviderResult
    {
        if (empty($config['api_key'])) {
            return AIProviderResult::failure('Chiave API Gemini mancante.', [], $this->resultMeta($config));
        }

        $baseUrl = rtrim((string) ($config['base_url'] ?? $this->baseUrl()), '/');
        $model = (string) ($config['model'] ?? $this->defaultModel());
        $payload = [
            'systemInstruction' => ['parts' => [['text' => $this->systemMessage($context)]]],
            'contents' => array_merge(
                $this->geminiHistoryContents($context),
                [['role' => 'user', 'parts' => $this->userParts($prompt, $config)]]
            ),
            'generationConfig' => [
                'temperature' => (float) ($config['temperature'] ?? 0.7),
                'topP' => (float) ($config['top_p'] ?? 1.0),
                'maxOutputTokens' => (int) ($config['max_tokens'] ?? 2048),
            ],
        ];

        $started = microtime(true);
        $url = $baseUrl . '/models/' . rawurlencode($model) . ':streamGenerateContent?alt=sse&key=' . rawurlencode((string) $config['api_key']);
        $response = $this->streamGemini($url, $payload, (int) ($config['timeout_seconds'] ?? 30), $onDelta, $config['_cancellation_token'] ?? null);
        $elapsed = (int) round((microtime(true) - $started) * 1000);

        // Lo stream SSE di Gemini a volte si chiude in anticipo SENZA inviare un finishReason
        // terminale (STOP/MAX_TOKENS): il testo arrivato e' una risposta troncata, non quella reale.
        // In quel caso (o per interruzioni tipo SAFETY/RECITATION) trattiamo l'esito come errore,
        // cosi' il ProviderManager fa fallback su un altro provider invece di salvare il troncamento.
        if (!empty($response['ok'])) {
            $finishReason = (string) ($response['finish_reason'] ?? '');
            if ($finishReason !== 'STOP' && $finishReason !== 'MAX_TOKENS') {
                $reason = $finishReason === '' ? 'stream chiuso prima della fine' : $finishReason;
                $response['ok'] = false;
                $response['error'] = 'Risposta Gemini interrotta (' . $reason . ').';
            }
        }

        if (empty($response['ok'])) {
            return AIProviderResult::failure((string) $response['error'], $response, $this->resultMeta($config, $elapsed));
        }

        return AIProviderResult::success(
            (string) $response['content'],
            (int) ($response['tokens_input'] ?? 0),
            (int) ($response['tokens_output'] ?? 0),
            $response,
            $this->resultMeta($config, $elapsed)
        );
    }

    private function streamGemini(string $url, array $payload, int $timeout, callable $onDelta, mixed $cancellation = null): array
    {
        $timeout = $this->streamTimeout($timeout);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ],
        ]);

        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            return ['ok' => false, 'error' => 'Gemini non e raggiungibile.'];
        }

        stream_set_timeout($stream, 1);
        $deadline = microtime(true) + $timeout;
        $content = '';
        $raw = '';
        $tokensInput = 0;
        $tokensOutput = 0;
        $finishReason = '';

        while (!feof($stream)) {
            if (($cancellation && method_exists($cancellation, 'isCancelled') && $cancellation->isCancelled()) || connection_aborted() === 1) {
                fclose($stream);
                return ['ok' => false, 'cancelled' => true, 'error' => 'Richiesta interrotta.', 'content' => $content];
            }

            if (microtime(true) >= $deadline) {
                fclose($stream);
                return ['ok' => false, 'error' => 'Timeout durante la chiamata a Gemini.', 'content' => $content];
            }

            $line = fgets($stream);
            if ($line === false) {
                continue;
            }

            $raw .= $line;
            $line = trim($line);
            if ($line === '' || str_starts_with($line, ':') || !str_starts_with($line, 'data:')) {
                continue;
            }

            $json = json_decode(trim(substr($line, 5)), true);
            if (!is_array($json)) {
                continue;
            }

            $delta = (string) ($json['candidates'][0]['content']['parts'][0]['text'] ?? '');
            if ($delta !== '') {
                $content .= $delta;
                $onDelta($delta);
            }

            if (isset($json['usageMetadata'])) {
                $tokensInput = (int) ($json['usageMetadata']['promptTokenCount'] ?? $tokensInput);
                $tokensOutput = (int) ($json['usageMetadata']['candidatesTokenCount'] ?? $tokensOutput);
            }

            $chunkFinish = (string) ($json['candidates'][0]['finishReason'] ?? '');
            if ($chunkFinish !== '') {
                $finishReason = $chunkFinish;
            }
        }

        $meta = stream_get_meta_data($stream);
        fclose($stream);
        $status = 200;
        foreach (($meta['wrapper_data'] ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match)) {
                $status = (int) $match[1];
                break;
            }
        }

        if ($status >= 400) {
            return ['ok' => false, 'error' => $this->httpError($status, $raw), 'status' => $status, 'body' => $raw, 'content' => $content];
        }

        return [
            'ok' => $content !== '',
            'content' => $content,
            'body' => $raw,
            'tokens_input' => $tokensInput,
            'tokens_output' => $tokensOutput,
            'finish_reason' => $finishReason,
            'error' => $content === '' ? 'Gemini non ha restituito contenuto.' : '',
        ];
    }

    private function userParts(string $prompt, array $config): array
    {
        $parts = [['text' => $prompt]];
        foreach ($this->imageAttachments($config) as $attachment) {
            $path = (string) ($attachment['absolute_path'] ?? '');
            if ($path === '' || !is_file($path)) {
                continue;
            }

            $bytes = file_get_contents($path);
            if (!is_string($bytes) || $bytes === '') {
                continue;
            }

            $parts[] = [
                'inline_data' => [
                    'mime_type' => (string) ($attachment['mime'] ?? 'image/png'),
                    'data' => base64_encode($bytes),
                ],
            ];
        }

        return $parts;
    }
}
