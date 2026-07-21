<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\ContextEngine\ContextInterface;
use App\Services\AIProviderResult;

abstract class OpenAICompatibleProvider extends AbstractProvider
{
    public function canAttempt(array $config): array
    {
        $attempt = parent::canAttempt($config);
        if (!$attempt['ok']) {
            return $attempt;
        }

        if ((string) ($config['model'] ?? $this->defaultModel()) === '') {
            return ['ok' => false, 'message' => 'Modello ' . $this->label() . ' non configurato.'];
        }

        return $attempt;
    }

    public function healthCheck(array $config): array
    {
        $attempt = $this->canAttempt($config);
        if (!$attempt['ok']) {
            return $attempt;
        }

        $started = microtime(true);
        $response = $this->getJson($this->endpoint($config) . '/models', $this->headers($config), (int) ($config['timeout_seconds'] ?? 10));
        $elapsed = (int) round((microtime(true) - $started) * 1000);

        return $response['ok']
            ? ['ok' => true, 'message' => $this->label() . ' ONLINE. Endpoint modelli raggiungibile.', 'response_time_ms' => $elapsed]
            : ['ok' => false, 'message' => $response['error'], 'response_time_ms' => $elapsed];
    }

    public function models(array $config): array
    {
        $attempt = $this->canAttempt($config);
        if (!$attempt['ok']) {
            return [];
        }

        $response = $this->getJson($this->endpoint($config) . '/models', $this->headers($config), (int) ($config['timeout_seconds'] ?? 10));
        if (empty($response['ok'])) {
            return [];
        }

        $body = json_decode((string) $response['body'], true);
        return is_array($body) ? $this->parseModelIds($body) : [];
    }

    public function stream(string $prompt, ContextInterface $context, array $config, callable $onDelta): AIProviderResult
    {
        $attempt = $this->canAttempt($config);
        if (!$attempt['ok']) {
            return AIProviderResult::failure((string) $attempt['message'], [], $this->resultMeta($config));
        }

        $payload = array_merge([
            'model' => (string) ($config['model'] ?? $this->defaultModel()),
            'messages' => $this->openAiMessages($prompt, $context, $this->openAiUserContent($prompt, $config)),
            'temperature' => (float) ($config['temperature'] ?? 0.7),
            'top_p' => (float) ($config['top_p'] ?? 1.0),
            'max_tokens' => (int) ($config['max_tokens'] ?? 2048),
            'stream' => true,
            'stream_options' => ['include_usage' => true],
        ], $this->extraPayload($config));

        $started = microtime(true);
        $response = $this->streamPostJson($this->endpoint($config) . '/chat/completions', $payload, $this->headers($config), (int) ($config['timeout_seconds'] ?? 30), $onDelta, $config['_cancellation_token'] ?? null);
        $elapsed = (int) round((microtime(true) - $started) * 1000);
        $metaConfig = $config;
        if (!empty($response['model'])) {
            $metaConfig['model'] = (string) $response['model'];
        }

        if (empty($response['ok'])) {
            return AIProviderResult::failure((string) ($response['error'] ?? $this->label() . ' non ha restituito contenuto.'), $response, $this->resultMeta($metaConfig, $elapsed));
        }

        $tokensInput = (int) ($response['tokens_input'] ?? 0);
        $tokensOutput = (int) ($response['tokens_output'] ?? 0);
        if ($tokensInput === 0) {
            $tokensInput = $this->estimatePayloadTokens($payload['messages']);
        }
        if ($tokensOutput === 0) {
            $tokensOutput = $this->estimateTextTokens((string) $response['content']);
        }

        return AIProviderResult::success((string) $response['content'], $tokensInput, $tokensOutput, $response, $this->resultMeta($metaConfig, $elapsed));
    }

    protected function endpoint(array $config): string
    {
        return rtrim((string) ($config['base_url'] ?? $this->baseUrl()), '/');
    }

    protected function headers(array $config): array
    {
        return ['Authorization: Bearer ' . (string) ($config['api_key'] ?? '')];
    }
}
