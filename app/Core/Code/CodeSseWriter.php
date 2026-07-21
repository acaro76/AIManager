<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 1 / F1.5. Serializzazione degli eventi SSE della chat Code.
 *
 * Riusa la CONVENZIONE già in uso nella chat esistente (`delta` / `reasoning` / `reset`), così
 * il client non deve imparare un protocollo nuovo, e aggiunge un evento finale `done` con
 * l'esito STRUTTURATO del CodeChatService (status, message, provider, files, limits_hit,
 * metrics, citations). L'evento `error` porta solo messaggi pensati per l'utente: nessun dettaglio di
 * eccezioni interne.
 *
 * È puro: scrive su un sink iniettabile (default: echo), quindi si può testare senza server.
 */
final class CodeSseWriter
{
    /** @var callable(string): void */
    private $out;

    public function __construct(?callable $out = null)
    {
        $this->out = $out ?? static function (string $chunk): void {
            echo $chunk;
            @ob_flush();
            flush();
        };
    }

    /**
     * Serializzazione FAIL-SAFE: il payload può contenere testo che arriva dai file (byte UTF-8
     * non validi). `JSON_INVALID_UTF8_SUBSTITUTE` li sostituisce invece di far fallire la
     * codifica; se per qualsiasi motivo json_encode fallisce comunque, si emette un payload
     * generico valido. Nessun evento deve mai produrre una riga `data:` vuota o malformata.
     *
     * @param array<string, mixed> $payload
     */
    public function event(string $event, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            $json = json_encode(
                ['status' => 'error', 'message' => 'Contenuto non serializzabile.'],
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
        }
        if (!is_string($json) || $json === '') {
            $json = '{}'; // ultima rete: mai un data: vuoto
        }

        ($this->out)('event: ' . $event . "\n" . 'data: ' . $json . "\n\n");
    }

    /**
     * Mappa i canali prodotti dai provider sugli eventi SSE:
     *   content → delta · reasoning → reasoning · reset → reset (azzera il parziale).
     */
    public function delta(string $text, string $channel = 'content'): void
    {
        if ($channel === 'reset') {
            $this->event('reset', []);
            return;
        }

        $this->event($channel === 'reasoning' ? 'reasoning' : 'delta', ['text' => $text]);
    }

    /**
     * Evento finale con l'esito strutturato. `status` (success|error|cancelled) dice al client
     * se il parziale già mostrato è una risposta valida o va scartato.
     *
     * @param array{
     *   status: string, message: string, provider: string,
     *   files: array{read: list<string>, found: list<string>},
     *   limits_hit: list<string>, metrics: array<string, int>,
     *   citations?: list<array{path:string,kind:string,line:?int}>,
     *   verifications?: list<array{profile:string,kind:string,outcome:string,exit_code:?int,path:?string,label:string}>
     * } $result
     */
    public function done(array $result): void
    {
        $this->event('done', [
            'status' => (string) $result['status'],
            'message' => (string) $result['message'],
            'provider' => (string) $result['provider'],
            'model' => (string) ($result['model'] ?? ''),
            'files' => [
                'read' => array_values($result['files']['read'] ?? []),
                'found' => array_values($result['files']['found'] ?? []),
            ],
            'limits_hit' => array_values($result['limits_hit'] ?? []),
            'metrics' => $result['metrics'] ?? [],
            'citations' => array_values($result['citations'] ?? []),
            // Fase 5: esito STRUTTURATO delle verifiche (card live); deriva dai metadati.
            'verifications' => array_values($result['verifications'] ?? []),
            // Fase 4: proposta di modifica da mostrare in una card (null se assente).
            'proposal' => $result['proposal'] ?? null,
            // Fase 6: proposta di comando locale da confermare (null se assente).
            'command' => $result['command'] ?? null,
            // Fase 7: proposta di processo persistente da confermare (null se assente).
            'process' => $result['process'] ?? null,
            // Fase 8: proposta Git persistente da confermare (null se assente).
            'git_stage' => $result['git_stage'] ?? null,
        ]);
    }

    /** Errore mostrabile all'utente (mai il messaggio di un'eccezione inattesa). */
    public function error(string $message): void
    {
        $this->event('error', ['status' => 'error', 'message' => $message]);
    }
}
