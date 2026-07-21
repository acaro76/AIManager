<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 7 / F7.8. Proiezione SANIFICATA di una riga di `code_processes` per la UI (card live e
 * cronologia dopo refresh). SOLI metadati: mai path assoluti, mai il contenuto dei log. L'etichetta
 * deriva SOLO dallo stato, mai dal testo del modello.
 */
final class ProcessRunRecord
{
    /** @var list<string> */
    public const STATES = ProcessRunSchema::STATES;

    /** Etichetta leggibile, UNICA fonte di verità per card live e cronologia. */
    public static function label(string $state, ?int $exitCode): string
    {
        return match ($state) {
            'pending' => 'in attesa di conferma',
            'starting' => 'in avvio',
            'running' => 'in esecuzione',
            'stopped' => 'arrestato',
            'rejected' => 'rifiutato',
            'expired' => 'scaduto',
            'denied' => 'negato',
            'failed' => 'avvio fallito' . ($exitCode !== null ? ' (exit ' . $exitCode . ')' : ''),
            'orphaned' => 'non più identificabile',
            default => 'errore',
        };
    }

    /** Solo gli stati in cui esiste un processo che si può fermare. */
    public static function isActive(string $state): bool
    {
        return in_array($state, ['starting', 'running'], true);
    }

    /**
     * Card STRUTTURATA da una riga DB. `digest` è incluso solo per le proposte pendenti (serve al
     * form di conferma). Include `host`/`port`/`display_summary` per la UI; mai path assoluti.
     *
     * @param array<string, mixed> $row
     * @return array{process_id:string,profile_id:string,display_summary:string,host:string,port:int,state:string,exit_code:?int,label:string,digest:string,can_stop:bool}
     */
    public static function fromRow(array $row): array
    {
        $state = (string) ($row['state'] ?? 'error');
        if (!in_array($state, self::STATES, true)) {
            $state = 'error';
        }
        $exit = $row['exit_code'] === null ? null : (int) $row['exit_code'];

        return [
            'process_id' => (string) ($row['process_id'] ?? ''),
            'profile_id' => (string) ($row['profile_id'] ?? ''),
            'display_summary' => (string) ($row['display_summary'] ?? ''),
            'host' => (string) ($row['host'] ?? ''),
            'port' => (int) ($row['port'] ?? 0),
            'state' => $state,
            'exit_code' => $exit,
            'label' => self::label($state, $exit),
            'digest' => $state === 'pending' ? (string) ($row['digest'] ?? '') : '',
            'can_stop' => self::isActive($state),
        ];
    }
}
