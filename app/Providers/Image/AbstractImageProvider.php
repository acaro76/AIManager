<?php

declare(strict_types=1);

namespace App\Providers\Image;

use App\Contracts\ImageProviderInterface;
use App\Core\Cancellation\CancellationToken;

abstract class AbstractImageProvider implements ImageProviderInterface
{
    /**
     * @return array{ok: bool, body: string, error: string, status: int}
     */
    protected function postJson(string $url, array $headers, string $body, int $timeout = 60, ?CancellationToken $cancellation = null): array
    {
        if ($cancellation?->isCancelled()) {
            return ['ok' => false, 'body' => '', 'error' => 'Richiesta interrotta.', 'status' => 0];
        }
        $curl = curl_init($url);
        if ($curl === false) {
            return ['ok' => false, 'body' => '', 'error' => 'init fallita', 'status' => 0];
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
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
