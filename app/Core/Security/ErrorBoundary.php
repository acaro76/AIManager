<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * Fase 10 / Step 2 — confine d'errore per boot/dispatch. Un Throwable non gestito NON deve mai
 * mostrare dettagli tecnici (stack trace, DSN, percorsi) al browser: il dettaglio va SOLO nel log,
 * mentre alla risposta va un 500 generico. Isolato qui per essere testabile senza avviare l'HTTP.
 */
final class ErrorBoundary
{
    /** Messaggio generico esposto al client: nessun dettaglio tecnico. */
    public const GENERIC_MESSAGE = 'Errore interno del server.';

    /**
     * Registra il dettaglio (categoria + messaggio) tramite il logger fornito e restituisce la
     * risposta generica. Il logger riceve solo una stringa; il chiamante decide dove scriverla.
     *
     * @param callable(string): void $logger
     * @return array{status: int, body: string}
     */
    public static function handle(\Throwable $e, callable $logger): array
    {
        $logger('[boot] ' . get_class($e) . ': ' . $e->getMessage());

        return ['status' => 500, 'body' => self::GENERIC_MESSAGE];
    }
}
