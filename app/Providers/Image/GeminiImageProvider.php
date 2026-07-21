<?php

declare(strict_types=1);

namespace App\Providers\Image;

use App\Core\Cancellation\CancellationToken;

/**
 * Generazione immagini via API Gemini nativa (generateContent).
 *
 * Modello free: `gemini-2.5-flash-image` (~500 img/giorno, l'unico col free tier; i
 * modelli 3.x non ce l'hanno). Riusa la chiave GOOGLE_API_KEY gia' presente.
 * L'immagine torna come parte `inlineData` (base64) nella risposta.
 */
final class GeminiImageProvider extends AbstractImageProvider
{
    public function key(): string
    {
        return 'gemini';
    }

    public function label(): string
    {
        return 'Gemini';
    }

    public function canAttempt(array $config): bool
    {
        return (string) ($config['api_key'] ?? '') !== '' && (string) ($config['model'] ?? '') !== '';
    }

    public function generate(string $prompt, array $config, ?CancellationToken $cancellation = null): array
    {
        $model = (string) ($config['model'] ?? 'gemini-2.5-flash-image');
        $base = ['ok' => false, 'image_base64' => '', 'mime' => '', 'model' => $model, 'error' => ''];
        if ($cancellation?->isCancelled()) {
            return ['error' => 'Richiesta interrotta.'] + $base;
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
        $payload = json_encode([
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE']],
        ], JSON_UNESCAPED_UNICODE) ?: '{}';

        $response = $this->postJson(
            $url,
            ['x-goog-api-key: ' . (string) $config['api_key']],
            $payload,
            (int) ($config['timeout'] ?? 60),
            $cancellation
        );
        if (!$response['ok']) {
            return ['error' => $this->label() . ': ' . $response['error']] + $base;
        }

        $data = json_decode($response['body'], true);
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        foreach (is_array($parts) ? $parts : [] as $part) {
            $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
            if (is_array($inline) && !empty($inline['data'])) {
                return [
                    'ok' => true,
                    'image_base64' => (string) $inline['data'],
                    'mime' => (string) ($inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png'),
                    'model' => $model,
                    'error' => '',
                ];
            }
        }

        return ['error' => $this->label() . ' non ha restituito un\'immagine.'] + $base;
    }
}
