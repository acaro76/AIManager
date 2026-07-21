<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * Fase 10 / Step 2 — confine HTTP LOCALE. AIManager v1 è un'app locale, singolo utente, con il
 * server integrato vincolato a 127.0.0.1: nessuna richiesta deve arrivare dalla rete.
 *
 * Due condizioni, ENTRAMBE necessarie e valutate PRIMA del boot:
 *  - l'`Host` è nella allow-list storica (`127.0.0.1`/`localhost`), difesa da DNS rebinding;
 *  - il client è su LOOPBACK secondo `REMOTE_ADDR` (`127.0.0.1` o `::1`).
 *
 * `REMOTE_ADDR` è l'indirizzo del socket: NON si guardano header forwarded (`X-Forwarded-For` e
 * simili), falsificabili dal client. Così una richiesta remota con `Host: localhost` è rifiutata.
 */
final class LocalHttpGuard
{
    /** @var list<string> Allow-list Host storica (invariata). */
    private const ALLOWED_HOSTS = ['127.0.0.1', 'localhost'];

    /** @var list<string> Indirizzi di loopback ammessi per REMOTE_ADDR. */
    private const LOOPBACK = ['127.0.0.1', '::1'];

    public static function isAllowed(string $httpHost, string $remoteAddr): bool
    {
        $hostName = parse_url('http://' . $httpHost, PHP_URL_HOST);
        if (!is_string($hostName) || !in_array(strtolower($hostName), self::ALLOWED_HOSTS, true)) {
            return false;
        }

        return in_array($remoteAddr, self::LOOPBACK, true);
    }
}
