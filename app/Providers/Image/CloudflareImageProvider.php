<?php

declare(strict_types=1);

namespace App\Providers\Image;

use App\Core\Cancellation\CancellationToken;

/**
 * Generazione immagini via Cloudflare Workers AI (primo provider della catena, handoff sez. 46).
 *
 * Modello: `@cf/black-forest-labs/flux-1-schnell`. Endpoint REST per account:
 *   POST https://api.cloudflare.com/client/v4/accounts/{ACCOUNT_ID}/ai/run/{model}
 * Auth: Bearer CLOUDFLARE_API_TOKEN (permesso Workers AI). Quote e condizioni dipendono dal piano
 * del provider. FLUX schnell restituisce l'immagine base64 in result.image (JPEG).
 */
final class CloudflareImageProvider extends AbstractImageProvider
{
    public function key(): string
    {
        return 'cloudflare';
    }

    public function label(): string
    {
        return 'Cloudflare';
    }

    public function canAttempt(array $config): bool
    {
        return (string) ($config['account_id'] ?? '') !== ''
            && (string) ($config['api_token'] ?? '') !== ''
            && (string) ($config['model'] ?? '') !== '';
    }

    public function generate(string $prompt, array $config, ?CancellationToken $cancellation = null): array
    {
        $model = (string) ($config['model'] ?? '@cf/black-forest-labs/flux-1-schnell');
        $base = ['ok' => false, 'image_base64' => '', 'mime' => 'image/jpeg', 'model' => $model, 'error' => ''];
        if ($cancellation?->isCancelled()) {
            return ['error' => 'Richiesta interrotta.'] + $base;
        }

        $url = 'https://api.cloudflare.com/client/v4/accounts/'
            . rawurlencode((string) $config['account_id'])
            . '/ai/run/' . $model;

        $response = $this->postJson(
            $url,
            ['Authorization: Bearer ' . (string) $config['api_token']],
            json_encode(['prompt' => $prompt], JSON_UNESCAPED_UNICODE) ?: '{}',
            (int) ($config['timeout'] ?? 60),
            $cancellation
        );
        if (!$response['ok']) {
            $detail = $this->apiErrorMessage((string) ($response['body'] ?? ''));
            return ['error' => $this->label() . ': ' . ($detail !== '' ? $detail : $response['error'])] + $base;
        }

        $data = json_decode($response['body'], true);
        $image = (string) ($data['result']['image'] ?? '');
        if ($image === '') {
            return ['error' => $this->label() . ' non ha restituito un\'immagine.'] + $base;
        }

        return ['ok' => true, 'image_base64' => $image, 'mime' => 'image/jpeg', 'model' => $model, 'error' => ''];
    }

    /**
     * Estrae il messaggio d'errore leggibile dal corpo JSON di Cloudflare Workers AI
     * (es. filtro NSFW), cosi' la chat/scheda mostra la causa reale invece di "HTTP 400".
     */
    private function apiErrorMessage(string $body): string
    {
        if ($body === '') {
            return '';
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return '';
        }

        $message = (string) ($data['errors'][0]['message'] ?? '');

        return trim(preg_replace('/^(AiError:\s*)+/', '', $message) ?? $message);
    }
}
