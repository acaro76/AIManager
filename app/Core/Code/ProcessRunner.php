<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 7. Avvio di UN processo persistente (server locale) e cattura della sua IDENTITÀ.
 *
 * Non è il CommandRunner (Fase 6, INTOCCATO): un comando è breve e bloccante, un server è persistente.
 * Qui si avvia il `process_launcher.php` (doppio-fork + setsid), che detacha il server e ne scrive il
 * pidfile; il runner attende il pidfile, verifica che il processo sia VIVO e ne cattura la firma
 * d'avvio (ProcessInspector). L'identità (pid/pgid + run_token + firma) servirà allo Stop sicuro.
 *
 *   - process group OBBLIGATORIO, senza fallback: senza pcntl/posix il server NON parte (error);
 *   - l'ultimo bind del docroot avviene QUI, subito prima di proc_open: un path fuori root/sensibile
 *     fa NEGARE l'avvio (CodeWorkspaceException, propagata);
 *   - environment EFFIMERO e isolato (HOME/TMP/XDG sotto il runtime protetto): riduce l'esposizione,
 *     NON è una sandbox del sistema operativo — il server esegue codice del workspace;
 *   - host imposto a 127.0.0.1 dal chiamante (ProcessProfile): mai un bind pubblico.
 */
final class ProcessRunner
{
    private const LAUNCHER = __DIR__ . '/process_launcher.php';

    private readonly ProcessInspector $inspector;

    /** @var callable(): float */
    private $clock;

    public function __construct(
        private readonly ProcessRunLimits $limits,
        private readonly string $runtimeBaseDir,
        ?ProcessInspector $inspector = null,
        ?callable $clock = null,
    ) {
        $this->inspector = $inspector ?? new PsProcessInspector();
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /** L'isolamento del gruppo è la PRECONDIZIONE dell'avvio (nessun fallback). */
    public static function supportsProcessGroupIsolation(): bool
    {
        return function_exists('pcntl_fork')
            && function_exists('posix_setsid')
            && function_exists('pcntl_exec')
            && function_exists('posix_kill')
            && function_exists('posix_getpgid')
            && function_exists('posix_setrlimit')
            && defined('POSIX_RLIMIT_FSIZE')
            && is_file(self::LAUNCHER);
    }

    /**
     * Avvia il processo. `$absExe` è già risolto/validato dal chiamante; `$buildArgs(absDocroot)`
     * costruisce l'argv del server DOPO l'ultimo bind del docroot (host/porta li ha imposti il
     * chiamante). Ritorna ProcessResult (started con identità, o error).
     *
     * @param callable(string): list<string> $buildArgs
     * @throws CodeWorkspaceException se l'ultimo bind del docroot fallisce (avvio NEGATO)
     */
    public function start(
        ProcessPlan $plan,
        CodeWorkspace $workspace,
        string $absExe,
        string $executionId,
        string $runToken,
        callable $buildArgs,
    ): ProcessResult {
        if (!self::supportsProcessGroupIsolation() || !is_file($absExe) || !is_executable($absExe)) {
            return ProcessResult::error();
        }
        if ($runToken === '' || preg_match('/^[A-Za-z0-9_-]{16,120}$/', $runToken) !== 1) {
            return ProcessResult::error();
        }

        // ULTIMO bind del docroot: subito prima di proc_open. Una violazione qui è una NEGAZIONE.
        if ($workspace->isSensitive($plan->relDir)) {
            throw new CodeWorkspaceException('Directory sensibile: avvio non consentito.');
        }
        $absDocroot = $workspace->resolve($plan->relDir);
        if (!is_dir($absDocroot)) {
            return ProcessResult::error();
        }

        $runtime = ProcessRuntime::prepare($this->runtimeBaseDir, $workspace->id, $executionId);
        if ($runtime === null) {
            return ProcessResult::error();
        }

        $serverArgs = array_values($buildArgs($absDocroot));
        $command = array_merge(
            [PHP_BINARY, self::LAUNCHER, '--', $runtime['pid_file'], $runToken,
                (string) $this->limits->maxLogFileBytes, $absExe],
            $serverArgs
        );
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $runtime['log_file'], 'a'],
            2 => ['file', $runtime['log_file'], 'a'],
        ];
        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes, $absDocroot, $this->env($runtime['dir']));
        if (!is_resource($process)) {
            ProcessRuntime::cleanup($this->runtimeBaseDir, $workspace->id, $executionId);
            return ProcessResult::error();
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }

        // Attendi il pidfile: il figlio diretto (launcher) è già uscito, il nipote (server) sta
        // scrivendo la propria identità. proc_close ritorna subito (il figlio diretto è uscito).
        $identity = $this->awaitPidfile($runtime['pid_file'], $runToken);
        @proc_close($process);

        if ($identity === null) {
            // Avvio fallito o server non identificabile: se un nipote fosse comunque vivo senza
            // pidfile non potremmo segnalarlo in sicurezza; ma senza pidfile l'exec è fallito e il
            // nipote è uscito. Runtime rimosso.
            ProcessRuntime::cleanup($this->runtimeBaseDir, $workspace->id, $executionId);
            return ProcessResult::error();
        }

        // Il pidfile viene scritto PRIMA dell'exec: un exec fallito o una porta occupata possono
        // lasciare il processo vivo per pochi millisecondi. Richiedi quindi una finestra minima di
        // stabilità prima di dichiararlo running, verificando anche il PGID reale a ogni passaggio.
        $stableUntil = ($this->clock)() + $this->limits->startStabilitySeconds;
        do {
            if (!$this->inspector->isAlive($identity['pid'])
                || $identity['pid'] !== $identity['pgid']
                || $this->inspector->processGroupId($identity['pid']) !== $identity['pgid']) {
                ProcessRuntime::cleanup($this->runtimeBaseDir, $workspace->id, $executionId);
                return ProcessResult::error();
            }
            if (($this->clock)() >= $stableUntil) {
                break;
            }
            usleep(20000);
        } while (true);

        $signature = $this->inspector->signature($identity['pid']);

        return new ProcessResult(
            outcome: ProcessResult::STARTED,
            pid: $identity['pid'],
            pgid: $identity['pgid'],
            runToken: $runToken,
            startSignature: $signature,
            logId: $executionId,
        );
    }

    /**
     * @return array{pid:int,pgid:int,run_token:string}|null
     */
    private function awaitPidfile(string $pidFile, string $expectedToken): ?array
    {
        $deadline = ($this->clock)() + $this->limits->startTimeoutSeconds;
        while (($this->clock)() < $deadline) {
            $id = ProcessRuntime::readPidfile($pidFile);
            if ($id !== null && hash_equals($expectedToken, $id['run_token'])) {
                return $id;
            }
            usleep(20000);
        }
        // Ultimo tentativo (il clock iniettato nei test può non avanzare col tempo reale).
        $id = ProcessRuntime::readPidfile($pidFile);

        return ($id !== null && hash_equals($expectedToken, $id['run_token'])) ? $id : null;
    }

    /** Environment EFFIMERO e isolato: nessun segreto, nessun HOME reale. */
    private function env(string $runtimeDir): array
    {
        $home = $runtimeDir . '/home';

        return [
            'LANG' => 'C',
            'LC_ALL' => 'C',
            'TERM' => 'dumb',
            'PATH' => '/usr/bin:/bin',
            'HOME' => $home,
            'TMPDIR' => $runtimeDir . '/tmp',
            'XDG_CACHE_HOME' => $home . '/.cache',
            'XDG_CONFIG_HOME' => $home . '/.config',
            'XDG_DATA_HOME' => $home . '/.local/share',
            'XDG_STATE_HOME' => $home . '/.local/state',
        ];
    }
}
