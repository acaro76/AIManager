<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\ContextEngine\ContextInterface;
use App\Services\AIProviderResult;

final class ClaudeProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'claude';
    }

    public function label(): string
    {
        return 'Claude';
    }

    protected function baseUrl(): string
    {
        return 'https://api.anthropic.com/v1';
    }

    protected function defaultModel(): string
    {
        return 'claude-3-5-sonnet-latest';
    }

    public function healthCheck(array $config): array
    {
        if (empty($config['api_key'])) {
            return ['ok' => false, 'message' => 'Chiave API mancante.'];
        }
        $baseUrl = rtrim((string) ($config['base_url'] ?? $this->baseUrl()), '/');
        $started = microtime(true);
        $response = $this->getJson($baseUrl . '/models', [
            'x-api-key: ' . $config['api_key'],
            'anthropic-version: 2023-06-01',
        ], (int) ($config['timeout_seconds'] ?? 10));
        $elapsed = (int) round((microtime(true) - $started) * 1000);
        return $response['ok']
            ? ['ok' => true, 'message' => 'Claude ONLINE. Endpoint modelli raggiungibile.', 'response_time_ms' => $elapsed]
            : ['ok' => false, 'message' => $response['error'], 'response_time_ms' => $elapsed];
    }

    public function models(array $config): array
    {
        if (empty($config['api_key'])) {
            return [];
        }

        $baseUrl = rtrim((string) ($config['base_url'] ?? $this->baseUrl()), '/');
        $response = $this->getJson($baseUrl . '/models', [
            'x-api-key: ' . $config['api_key'],
            'anthropic-version: 2023-06-01',
        ], (int) ($config['timeout_seconds'] ?? 10));
        if (empty($response['ok'])) {
            return [];
        }

        $body = json_decode((string) $response['body'], true);
        return is_array($body) ? $this->parseModelIds($body) : [];
    }

    public function stream(string $prompt, ContextInterface $context, array $config, callable $onDelta): AIProviderResult
    {
        if (empty($config['api_key'])) {
            return AIProviderResult::failure('Chiave API Claude mancante.', [], $this->resultMeta($config));
        }

        $baseUrl = rtrim((string) ($config['base_url'] ?? $this->baseUrl()), '/');
        $payload = [
            'model' => (string) ($config['model'] ?? $this->defaultModel()),
            'system' => $this->systemMessage($context),
            'messages' => array_merge(
                $this->historyMessages($context),
                [['role' => 'user', 'content' => $prompt]]
            ),
            'temperature' => (float) ($config['temperature'] ?? 0.7),
            'top_p' => (float) ($config['top_p'] ?? 1.0),
            'max_tokens' => (int) ($config['max_tokens'] ?? 2048),
            'stream' => true,
        ];

        $started = microtime(true);
        $response = $this->streamClaude($baseUrl . '/messages', $payload, [
            'x-api-key: ' . $config['api_key'],
            'anthropic-version: 2023-06-01',
        ], (int) ($config['timeout_seconds'] ?? 30), $onDelta, $config['_cancellation_token'] ?? null);
        $elapsed = (int) round((microtime(true) - $started) * 1000);

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

    private function streamClaude(string $url, array $payload, array $headers, int $timeout, callable $onDelta, mixed $cancellation = null): array
    {
        $timeout = $this->streamTimeout($timeout);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => implode("\r\n", array_merge(['Content-Type: application/json'], $headers)) . "\r\n",
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ],
        ]);

        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            return ['ok' => false, 'error' => 'Claude non e raggiungibile.'];
        }

        stream_set_timeout($stream, 1);
        $deadline = microtime(true) + $timeout;
        $content = '';
        $raw = '';
        $tokensInput = 0;
        $tokensOutput = 0;
        $stopReason = '';
        $sawStop = false;

        while (!feof($stream)) {
            if (($cancellation && method_exists($cancellation, 'isCancelled') && $cancellation->isCancelled()) || connection_aborted() === 1) {
                fclose($stream);
                return ['ok' => false, 'cancelled' => true, 'error' => 'Richiesta interrotta.', 'content' => $content];
            }

            if (microtime(true) >= $deadline) {
                fclose($stream);
                return ['ok' => false, 'error' => 'Timeout durante la chiamata a Claude.', 'content' => $content];
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

            $type = (string) ($json['type'] ?? '');
            if ($type === 'message_start') {
                $tokensInput = (int) ($json['message']['usage']['input_tokens'] ?? $tokensInput);
                continue;
            }

            if ($type === 'content_block_delta') {
                $delta = (string) ($json['delta']['text'] ?? '');
                if ($delta !== '') {
                    $content .= $delta;
                    $onDelta($delta);
                }
                continue;
            }

            if ($type === 'message_delta') {
                $tokensOutput = (int) ($json['usage']['output_tokens'] ?? $tokensOutput);
                if (!empty($json['delta']['stop_reason'])) {
                    $stopReason = (string) $json['delta']['stop_reason'];
                }
            }

            if ($type === 'message_stop') {
                $sawStop = true;
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

        // Stream chiuso con del testo ma senza segnale di fine (nessuno stop_reason e nessun
        // message_stop): troncamento di trasporto -> errore, cosi' scatta il fallback su un
        // altro provider invece di salvare il parziale.
        if ($content !== '' && $stopReason === '' && !$sawStop) {
            return [
                'ok' => false,
                'error' => 'Claude ha interrotto lo stream prima della fine (risposta troncata).',
                'content' => $content,
                'body' => $raw,
                'tokens_input' => $tokensInput,
                'tokens_output' => $tokensOutput,
                'stop_reason' => $stopReason,
            ];
        }

        return [
            'ok' => $content !== '',
            'content' => $content,
            'body' => $raw,
            'tokens_input' => $tokensInput,
            'tokens_output' => $tokensOutput,
            'stop_reason' => $stopReason,
            'error' => $content === '' ? 'Claude non ha restituito contenuto.' : '',
        ];
    }
}
