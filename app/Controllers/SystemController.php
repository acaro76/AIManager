<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Code\ProcessConfirmService;
use App\Core\Code\PendingOperationService;
use App\Core\Code\ProcessRunSchema;
use App\Core\Request;
use App\Core\Response;

final class SystemController extends BaseController
{
    /** Risposta di health STABILE che identifica AIManager (nessun dato sensibile). */
    public const HEALTH_BODY = 'AIManager:ok';

    /**
     * Health check pubblico (Fase 10 / Step 3): identità del servizio in testo, minima e stabile.
     * Il launcher la usa per distinguere «porta occupata da AIManager» da «altro servizio» e per
     * attendere la disponibilità reale dopo l'avvio. Nessun guard/CSRF (è una GET di sola identità),
     * nessun dato sensibile.
     *
     * NON interroga direttamente il DB, ma è servita dal front controller DOPO `App::boot()`: quindi
     * una risposta positiva certifica implicitamente che boot, eventuale backup pre-migrazione e
     * migrazioni sono già andati a buon fine. Per questo NON va spostata prima del boot: lì
     * risponderebbe pur con boot/migrazioni non completati (o falliti), perdendo il suo valore.
     */
    public function health(Request $request): never
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo self::HEALTH_BODY;
        exit;
    }

    /**
     * Ferma il server locale integrato (php -S) su richiesta dell'utente dalla UI.
     * Ha senso solo con il server built-in: sotto un web server vero non c'e' un
     * processo "nostro" da spegnere, quindi rifiuta.
     */
    public function stop(Request $request): never
    {
        $this->guard($request);

        if (PHP_SAPI !== 'cli-server') {
            Response::json([
                'ok' => false,
                'message' => "Disponibile solo con il server locale integrato: qui AIManager non gira cosi'.",
            ], 400);
        }

        $processes = ['ok' => true, 'stopped' => 0, 'failures' => []];
        $pending = ['cancelled' => 0, 'failures' => 0];
        try {
            $pending = (new PendingOperationService(
                $this->app->db,
                $this->app->config['paths']['storage']
            ))->cancelAll();
        } catch (\Throwable) {
            $pending = ['cancelled' => 0, 'failures' => 1];
        }
        if (ProcessRunSchema::state($this->app->db) === ProcessRunSchema::STATE_READY) {
            try {
                $processes = (new ProcessConfirmService(
                    $this->app->db,
                    $this->app->config['paths']['storage'] . '/code_process_runtime'
                ))->stopAllForShutdown();
            } catch (\Throwable) {
                // Lo spegnimento esplicitamente richiesto non viene bloccato da un guasto nella
                // ricognizione. L'overlay fornisce comunque una verifica manuale generale.
                $processes = ['ok' => false, 'stopped' => 0, 'failures' => [[
                    'summary' => 'Processo persistente non identificato',
                    'host' => '127.0.0.1',
                    'port' => 0,
                ]]];
            }
        }

        $pid = getmypid();
        if ($pid !== false) {
            $this->scheduleShutdown($pid);
        }

        Response::json([
            'ok' => true,
            'message' => 'AIManager fermato. Puoi chiudere questa scheda.',
            'process_cleanup' => [
                'stopped' => $processes['stopped'],
                'remaining' => count($processes['failures']),
                'manual_instructions' => $processes['failures'] === []
                    ? ''
                    : $this->manualProcessStopMessage($processes['failures']),
            ],
            'pending_cleanup' => $pending,
        ]);
    }

    /**
     * Apre una finestra Terminale che segue dal vivo i log del server locale
     * (tail -f su storage/logs/server.log). L'avvio e' silenzioso, quindi questo e'
     * il modo per vedere i log a richiesta. Solo con il server integrato.
     */
    public function terminal(Request $request): never
    {
        $this->guard($request);

        if (PHP_SAPI !== 'cli-server') {
            Response::json([
                'ok' => false,
                'message' => "Disponibile solo con il server locale integrato.",
            ], 400);
        }

        if (!function_exists('exec') || $this->isDisabled('exec')) {
            Response::json(['ok' => false, 'message' => "Apertura non disponibile (exec disabilitato)."], 500);
        }

        $log = $this->app->root . '/storage/logs/server.log';
        $viewer = $this->app->root . '/bin/logview.sh';
        $port = (int) ($_SERVER['SERVER_PORT'] ?? 8000);
        // Visualizzatore che segue il log e colora le righe di php -S (console del
        // server dal vivo). Invocato via bash per non dipendere dal bit +x. La porta
        // gli serve per accorgersi quando il server si ferma e chiudersi da solo.
        $cmd = '/bin/bash ' . escapeshellarg($viewer) . ' ' . escapeshellarg($log) . ' ' . escapeshellarg((string) $port);
        $tail = 'tell application "Terminal" to do script "clear; ' . $cmd . '"';
        $activate = 'tell application "Terminal" to activate';

        exec('osascript -e ' . escapeshellarg($tail) . ' -e ' . escapeshellarg($activate) . ' 2>/dev/null', $out, $code);

        Response::json(
            $code === 0
                ? ['ok' => true, 'message' => 'Terminale aperto con i log del server.']
                : ['ok' => false, 'message' => "Impossibile aprire il Terminale."],
            $code === 0 ? 200 : 500
        );
    }

    /**
     * Termina il processo del server subito DOPO che la risposta e' stata
     * consegnata: un killer esterno ritardato lascia chiudere la richiesta in modo
     * pulito. Ripiego su posix_kill (a shutdown) se exec e' disabilitato.
     */
    private function scheduleShutdown(int $pid): void
    {
        if (function_exists('exec') && !$this->isDisabled('exec')) {
            exec('(sleep 1; kill ' . $pid . ') >/dev/null 2>&1 &');
            return;
        }
        if (function_exists('posix_kill')) {
            register_shutdown_function(static function () use ($pid): void {
                @posix_kill($pid, 15);
            });
        }
    }

    /** @param list<array{summary:string,host:string,port:int}> $failures */
    private function manualProcessStopMessage(array $failures): string
    {
        $lines = [
            'AIManager è stato fermato, ma almeno un processo potrebbe essere ancora in esecuzione.',
            'Dal Terminale identifica e verifica ciascun superstite prima di terminarlo:',
        ];
        foreach ($failures as $failure) {
            $port = (int) $failure['port'];
            $lines[] = '- ' . $failure['summary'];
            $lines[] = $port > 0
                ? '  lsof -nP -iTCP:' . $port . ' -sTCP:LISTEN'
                : '  lsof -nP -iTCP -sTCP:LISTEN | grep php';
        }
        $lines[] = 'Verifica che il comando appartenga al server PHP atteso; poi usa `kill -TERM <PID>`. Non terminare PID non verificati.';

        return implode("\n", $lines);
    }

    private function isDisabled(string $function): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return in_array($function, $disabled, true);
    }
}
