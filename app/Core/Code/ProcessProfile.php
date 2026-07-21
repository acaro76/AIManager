<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 7 / F7.1. L'UNICO profilo di processo persistente ammesso: un server PHP locale
 * `php -S 127.0.0.1:{port} -t {directory}`. Server-side e FISSO:
 *   - il programma è `php` (l'interprete che stiamo eseguendo, PHP_BINARY), risolto e validato qui;
 *   - l'host è IMPOSTO a 127.0.0.1: il modello non può sceglierlo, e nessun bind pubblico è possibile;
 *   - gli argomenti sono un array COSTRUITO dal server (mai una shell, mai argv arbitrari del modello);
 *   - il modello può proporre SOLO porta e directory relativa; nient'altro.
 *
 * Non è un process manager generale: esiste un solo profilo, senza Node/npm/Python/Docker/Git/shell.
 */
final class ProcessProfile
{
    /** Id server-side FISSO del profilo (l'unico ammesso). */
    public const ID = 'php-server';

    /** Nome del programma (identità, non un path): l'interprete PHP. */
    public const PROGRAM = 'php';

    /** Host IMPOSTO dal server. Mai scelto dal modello, mai un bind pubblico. */
    public const HOST = '127.0.0.1';

    /** Intervallo di porte NON privilegiate ammesse (evita 0..1023). */
    public const PORT_MIN = 1024;
    public const PORT_MAX = 65535;

    public static function isKnown(string $profileId): bool
    {
        return $profileId === self::ID;
    }

    /** La porta è un intero in un intervallo non privilegiato. */
    public static function portAllowed(int $port): bool
    {
        return $port >= self::PORT_MIN && $port <= self::PORT_MAX;
    }

    /**
     * Risolve `php` all'ESEGUIBILE che stiamo già eseguendo (PHP_BINARY): un path assoluto, noto e
     * fidato per costruzione (è l'interprete del processo corrente). Verifica solo che sia ancora un
     * file regolare eseguibile. Null → non avviabile (fail closed).
     */
    public static function resolveProgram(): ?string
    {
        $php = PHP_BINARY;
        if ($php === '' || !is_file($php) || !is_executable($php)) {
            return null;
        }
        $real = realpath($php);

        return $real !== false && is_file($real) && is_executable($real) ? $real : null;
    }

    /**
     * Argv del server, COSTRUITO dal server: host fisso, porta validata, docroot ASSOLUTO già
     * ri-bindato dal chiamante subito prima dell'avvio. Nessun router script, nessun argomento del
     * modello. Sempre un array (mai una stringa passata a una shell).
     *
     * @return list<string>
     */
    public static function serverArgs(int $port, string $absDocroot): array
    {
        return ['-S', self::HOST . ':' . $port, '-t', $absDocroot];
    }
}
