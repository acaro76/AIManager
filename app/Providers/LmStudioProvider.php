<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\ContextEngine\ContextInterface;
use App\Core\Providers\LmStudioModelChoice;
use App\Core\Providers\ProviderIntent;
use App\Services\AIProviderResult;

final class LmStudioProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'lmstudio';
    }

    public function label(): string
    {
        return 'LM Studio';
    }

    protected function baseUrl(): string
    {
        return 'http://localhost:1234/v1';
    }

    protected function defaultModel(): string
    {
        return '';
    }

    protected function requiresKey(): bool
    {
        return false;
    }

    protected function enabledByDefault(): bool
    {
        return true;
    }

    protected function supportsVision(): bool
    {
        return true;
    }

    public function healthCheck(array $config): array
    {
        $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            return ['ok' => false, 'message' => 'Endpoint non configurato.'];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => (int) ($config['timeout_seconds'] ?? 3),
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($baseUrl . '/models', false, $context);
        if ($result === false) {
            return ['ok' => false, 'message' => 'Endpoint non raggiungibile.'];
        }

        $models = $this->models($config);
        $model = (string) ($config['model'] ?? '');
        if ($model === '') {
            $model = $models[0] ?? '';
        }
        if ($model === '') {
            return ['ok' => false, 'message' => 'Nessun modello LM Studio disponibile.'];
        }

        return ['ok' => true, 'message' => 'LM Studio ONLINE. Endpoint modelli raggiungibile.', 'response_time_ms' => 0];
    }

    public function models(array $config): array
    {
        $baseUrl = rtrim((string) ($config['base_url'] ?? $this->baseUrl()), '/');
        $response = $this->getJson($baseUrl . '/models', [], (int) ($config['timeout_seconds'] ?? 5));
        if (empty($response['ok'])) {
            return [];
        }

        $body = json_decode((string) $response['body'], true);
        return is_array($body) ? $this->parseModelIds($body) : [];
    }

    public function createRequest(string $prompt, ContextInterface $context): array
    {
        return [
            'model' => '',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->systemMessage($context),
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'temperature' => 0.7,
            'top_p' => 1.0,
            'stream' => false,
        ];
    }

    public function stream(string $prompt, ContextInterface $context, array $config, callable $onDelta): AIProviderResult
    {
        $baseUrl = rtrim((string) ($config['base_url'] ?? $this->baseUrl()), '/');
        $model = $this->resolveModel($config);
        $payload = $this->createRequest($prompt, $context);
        $payload['model'] = $model;
        $payload['messages'] = $this->buildMessages($prompt, $context, $config);
        $payload['temperature'] = (float) ($config['temperature'] ?? 0.7);
        $payload['top_p'] = (float) ($config['top_p'] ?? 1.0);
        $payload['max_tokens'] = (int) ($config['max_tokens'] ?? 2048);
        if (!empty($config['_structured_json'])) {
            $payload['response_format'] = ['type' => 'json_object'];
            // Le decisioni del protocollo non beneficiano della creatività: meno prosa e meno JSON rotto.
            $payload['temperature'] = min($payload['temperature'], 0.2);
        }
        $payload['stream'] = true;
        $payload['stream_options'] = ['include_usage' => true];

        $timeout = max(1, (int) ($config['timeout_seconds'] ?? 30));
        set_time_limit($timeout + 5);
        $started = microtime(true);
        $response = $this->streamPostJson($baseUrl . '/chat/completions', $payload, [], $timeout, $onDelta, $config['_cancellation_token'] ?? null);
        $elapsed = (int) round((microtime(true) - $started) * 1000);

        if (empty($response['ok'])) {
            return AIProviderResult::failure((string) ($response['error'] ?? 'LM Studio non ha restituito contenuto.'), $response, $this->resultMeta(['model' => $model] + $config, $elapsed));
        }

        $tokensInput = (int) ($response['tokens_input'] ?? 0);
        $tokensOutput = (int) ($response['tokens_output'] ?? 0);
        if ($tokensInput === 0) {
            $tokensInput = $this->estimatePayloadTokens($payload['messages']);
        }
        if ($tokensOutput === 0) {
            $tokensOutput = $this->estimateTextTokens((string) $response['content']);
        }

        return AIProviderResult::success((string) $response['content'], $tokensInput, $tokensOutput, $response, $this->resultMeta(['model' => $model] + $config, $elapsed));
    }

    /**
     * Sceglie il modello locale in base al ruolo richiesto dall'intento. JIT auto-evict
     * in LM Studio garantisce che resti caricato un solo modello alla volta.
     *
     *  - vision  -> modello vlm (gemma veloce, o qwen per immagini complesse): il fast/code
     *               possono essere solo-testo, quindi non devono mai ricevere immagini.
     *  - codice  -> modello coder specializzato (veloce, non ragionante).
     *  - pesante -> modello ragionante (qwen) per lavori lunghi/complessi non-codice.
     *  - breve   -> modello veloce non-ragionante.
     */
    private function resolveModel(array $config): string
    {
        $intent = $config['_intent'] ?? null;

        return LmStudioModelChoice::resolve(
            (string) ($config['model'] ?? $this->defaultModel()),
            (string) ($config['fast_model'] ?? ''),
            (string) ($config['code_model'] ?? ''),
            (string) ($config['vision_model'] ?? ''),
            $intent instanceof ProviderIntent ? $intent : null,
        );
    }

    private function buildMessages(string $prompt, ContextInterface $context, array $config): array
    {
        return $this->openAiMessages($prompt, $context, $this->openAiUserContent($prompt, $config));
    }

    /**
     * Testo semplice se non ci sono immagini; altrimenti formato OpenAI multimodale
     * (text + image_url base64) supportato dai modelli vision di LM Studio.
     */
}
