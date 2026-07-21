<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 6 / F6.8. Proiezione SANIFICATA di una riga di `code_command_runs` per la UI (card
 * live e cronologia dopo refresh). SOLI metadati: mai output, mai path assoluti, mai il pattern
 * grezzo. L'etichetta d'esito deriva SOLO dallo stato, mai dal testo del modello.
 */
final class CommandRunRecord
{
    /** @var list<string> */
    public const STATES = CommandRunSchema::STATES;

    /** Etichetta leggibile, UNICA fonte di verità per card live e cronologia. */
    public static function label(string $state, ?int $exitCode): string
    {
        return match ($state) {
            'pending' => 'in attesa di conferma',
            'running' => 'in esecuzione',
            'completed' => 'completato',
            'failed' => 'fallito' . ($exitCode !== null ? ' (exit ' . $exitCode . ')' : ''),
            'timed_out' => 'interrotto (timeout)',
            'killed' => 'interrotto',
            'rejected' => 'rifiutato',
            'expired' => 'scaduto',
            'denied' => 'negato',
            default => 'errore',
        };
    }

    /**
     * Card STRUTTURATA da una riga DB. `digest` è incluso solo per le proposte pendenti (serve al
     * form di conferma); per gli stati terminali non è necessario.
     *
     * @param array<string, mixed> $row
     * @return array{command_id:string,program:string,display_summary:string,state:string,exit_code:?int,label:string,digest:string}
     */
    public static function fromRow(array $row): array
    {
        $state = (string) ($row['state'] ?? 'error');
        if (!in_array($state, self::STATES, true)) {
            $state = 'error';
        }
        $exit = $row['exit_code'] === null ? null : (int) $row['exit_code'];

        return [
            'command_id' => (string) ($row['command_id'] ?? ''),
            'program' => (string) ($row['program'] ?? ''),
            'display_summary' => (string) ($row['display_summary'] ?? ''),
            'state' => $state,
            'exit_code' => $exit,
            'label' => self::label($state, $exit),
            'digest' => $state === 'pending' ? (string) ($row['digest'] ?? '') : '',
        ];
    }
}
