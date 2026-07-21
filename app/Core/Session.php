<?php

declare(strict_types=1);

namespace App\Core;

final class Session
{
    public function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start(self::options());
        }
    }

    /**
     * Opzioni della sessione (Fase 10 / Step 2), isolate per essere testabili senza avviare una
     * sessione. Modalità STRETTA (`use_strict_mode`: rifiuta id di sessione non generati dal server) e
     * SOLO-COOKIE (`use_only_cookies`: mai id di sessione dagli URL). Restano `HttpOnly`, `SameSite=Lax`
     * e `secure=false`, perché la v1 gira su HTTP locale (nessun HTTPS da esigere).
     *
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        return [
            'use_strict_mode' => true,
            'use_only_cookies' => true,
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure' => false,
        ];
    }

    public function flash(string $key, ?string $value = null): ?string
    {
        if ($value !== null) {
            $_SESSION['_flash'][$key] = $value;
            return null;
        }

        $message = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $message;
    }

    public function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf'];
    }

    public function verify(?string $token): bool
    {
        return is_string($token) && hash_equals($_SESSION['_csrf'] ?? '', $token);
    }

}
