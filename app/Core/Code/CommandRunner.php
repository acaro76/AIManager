<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 6 / F6.7. Esecutore di UN comando locale: argv (MAI shell), cwd confinata, environment
 * EFFIMERO e isolato, timeout, output cappato, Stop e TERMINAZIONE DELL'INTERO ALBERO.
 *
 * Differenze rispetto a VerificationRunner (Fase 5, INTOCCATA — qui si RIUSA solo lo script
 * `verification_launcher.php` su disco, letto in sola lettura):
 *   - il process group è OBBLIGATORIO, senza fallback (correzione finale): senza pcntl/posix il
 *     comando NON parte (esito `error`);
 *   - il programma è già risolto ad ASSOLUTO in bin fidata (CommandProgramResolver): nessun lookup
 *     su PATH ereditato o nella root;
 *   - l'environment è costruito con HOME/TMPDIR/XDG_* isolati ed EFFIMERI (creati per l'esecuzione,
 *     rimossi alla fine). Riduce l'esposizione; NON è una sandbox del sistema operativo;
 *   - `--` è inserito dal server prima di pattern/path (anti option-injection), via CommandPlan;
 *   - l'ULTIMO bind dei path avviene QUI, subito prima di proc_open (finestra TOCTOU residua e
 *     documentata): un path divenuto symlink/sensibile/fuori root fa fallire il comando (negato).
 *
 * Non è un registro di processi persistenti (Fase 7): attende, cattura, termina e ritorna.
 */
final class CommandRunner
{
    private const LAUNCHER = __DIR__ . '/verification_launcher.php';

    /** @var callable(): bool */
    private $isCancelled;

    /** @var callable(): float */
    private $clock;

    /** @var callable(int): void chiamato con il pid/pgid appena il processo è avviato (proc_open) */
    private $onStarted;

    private ?int $lastProcessGroupId = null;

    /**
     * @param (callable(int): bool)|null $onStarted invocato SUBITO dopo proc_open col pid (== pgid,
     *        il launcher fa posix_setsid): serve a persistire `process_group_id` mentre lo stato è
     *        `running`, così un crash della richiesta non lascia una riga running senza pgid.
     */
    public function __construct(
        private readonly CommandRunLimits $limits,
        private readonly string $runtimeBaseDir,
        ?callable $isCancelled = null,
        ?callable $clock = null,
        private readonly CommandPathBinder $binder = new CommandPathBinder(),
        ?callable $onStarted = null,
    ) {
        $this->isCancelled = $isCancelled ?? static fn (): bool => false;
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->onStarted = $onStarted ?? static fn (int $pid): bool => true;
    }

    /** pgid dell'ultima esecuzione (per l'audit/recovery), o null se non avviata. */
    public function lastProcessGroupId(): ?int
    {
        return $this->lastProcessGroupId;
    }

    /** L'isolamento del gruppo è la PRECONDIZIONE dell'esecuzione (nessun fallback). */
    public static function supportsProcessGroupIsolation(): bool
    {
        return function_exists('pcntl_exec')
            && function_exists('posix_setsid')
            && function_exists('posix_kill')
            && is_file(self::LAUNCHER);
    }

    /**
     * Esegue il piano. Il programma è già risolto assoluto e verificato dal chiamante.
     *
     * @throws CodeWorkspaceException se l'ultimo bind dei path fallisce (comando NEGATO)
     */
    public function run(CommandPlan $plan, CodeWorkspace $workspace, string $absExe, string $executionId): CommandResult
    {
        $this->lastProcessGroupId = null;

        if (!self::supportsProcessGroupIsolation() || !is_file($absExe) || !is_executable($absExe)) {
            return $this->error();
        }

        try {
            $cwd = $workspace->resolve('');
        } catch (CodeWorkspaceException) {
            return $this->error();
        }
        if (!is_dir($cwd)) {
            return $this->error();
        }

        // ULTIMO bind: subito prima di proc_open. Una violazione qui è una NEGAZIONE (propaga).
        $absPaths = $this->binder->bind($workspace, $plan->relPaths);

        $runtimeDir = $this->prepareRuntime($workspace->id, $executionId);
        if ($runtimeDir === null) {
            return $this->error();
        }

        try {
            return $this->execute($plan, $absExe, $absPaths, $cwd, $runtimeDir);
        } finally {
            $this->cleanupRuntime($runtimeDir);
        }
    }

    /**
     * @param list<string> $absPaths
     */
    private function execute(CommandPlan $plan, string $absExe, array $absPaths, string $cwd, string $runtimeDir): CommandResult
    {
        // launcher -- <exe> <flags> -- <pattern> <paths>   (il secondo `--` è del CommandPlan)
        $command = array_merge([PHP_BINARY, self::LAUNCHER, '--', $absExe], $plan->execTail($absPaths));

        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes, $cwd, $this->env($runtimeDir));
        if (!is_resource($process)) {
            return $this->error();
        }
        $pid = (int) (proc_get_status($process)['pid'] ?? -1);
        // Il launcher fa posix_setsid: diventa leader del proprio gruppo, pgid == pid.
        $this->lastProcessGroupId = $pid > 1 ? $pid : null;
        // Persisti SUBITO il pgid (record ancora `running`): un crash della richiesta da qui in poi
        // lascia una riga con pgid, e il recovery (solo-DB) può riconciliarla senza segnali tardivi.
        if ($this->lastProcessGroupId === null) {
            $this->terminate($process, $pipes, $pid);
            return $this->error();
        }
        try {
            $startedPersisted = ($this->onStarted)($this->lastProcessGroupId);
        } catch (\Throwable) {
            $startedPersisted = false;
        }
        if ($startedPersisted !== true) {
            // Fail closed: senza audit `running` + PGID persistito il comando non può proseguire.
            // Il processo/gruppo appena creato viene abbattuto prima di osservare o accettare output.
            $this->terminate($process, $pipes, $pid);
            return $this->error();
        }

        $start = ($this->clock)();
        $deadline = $start + $this->limits->maxSeconds;
        foreach ([1, 2] as $fd) {
            if (isset($pipes[$fd]) && is_resource($pipes[$fd])) {
                stream_set_blocking($pipes[$fd], false);
            }
        }

        $buffer = '';
        $total = 0;
        $truncated = false;
        $timedOut = false;
        $killed = false;
        $exitCode = null;

        while (true) {
            $status = proc_get_status($process);
            $running = (bool) ($status['running'] ?? false);
            if (!$running && $exitCode === null) {
                $code = $status['exitcode'] ?? -1;
                $exitCode = is_int($code) ? $code : -1;
            }

            [$total, $reachedCap] = $this->drain($pipes, $buffer, $total, $this->limits->maxOutputBytes);
            if ($reachedCap) {
                $truncated = true;
                $killed = true;
                break;
            }
            if (!$running) {
                break;
            }
            if (($this->clock)() >= $deadline) {
                $timedOut = true;
                break;
            }
            if (($this->isCancelled)()) {
                $killed = true;
                break;
            }
            $this->waitReadable($pipes);
        }

        $this->terminate($process, $pipes, $pid);
        $durationMs = (int) round((($this->clock)() - $start) * 1000);

        if ($timedOut) {
            $outcome = CommandResult::TIMED_OUT;
        } elseif ($killed) {
            $outcome = CommandResult::KILLED;
        } elseif ($exitCode === 0) {
            $outcome = CommandResult::COMPLETED;
        } elseif ($exitCode === null) {
            $outcome = CommandResult::ERROR;
        } else {
            $outcome = CommandResult::FAILED;
        }

        return new CommandResult(
            outcome: $outcome,
            exitCode: $exitCode,
            output: Utf8::clean($buffer),
            durationMs: $durationMs,
            outputBytes: $total,
            truncated: $truncated,
            timedOut: $timedOut,
        );
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

    /**
     * Runtime EFFIMERA e symlink-safe: base/{workspace}/{execution}/{home,tmp}. Ogni componente è
     * rifiutato se è un symlink (SafeStorageDir), e la directory d'esecuzione dev'essere FRESCA
     * (nega una dir preesistente o non vuota: nessun redirect verso dati altrui). Null → fail closed.
     */
    private function prepareRuntime(int $workspaceId, string $executionId): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]{8,80}$/', $executionId) !== 1) {
            return null;
        }
        $base = rtrim($this->runtimeBaseDir, '/');
        $anchor = dirname($base);
        $chain = [basename($base), (string) $workspaceId, $executionId];

        // La dir d'esecuzione dev'essere creata QUI (freshLeaf): mai riusare/seguire l'esistente.
        $exec = SafeStorageDir::ensure($anchor, $chain, true);
        if ($exec === null) {
            return null;
        }
        // home e tmp sotto la dir fresca: anche qui symlink rifiutati.
        if (SafeStorageDir::ensure($anchor, array_merge($chain, ['home']), false) === null
            || SafeStorageDir::ensure($anchor, array_merge($chain, ['tmp']), false) === null) {
            return null;
        }

        return $exec;
    }

    private function cleanupRuntime(string $dir): void
    {
        $base = realpath(rtrim($this->runtimeBaseDir, '/'));
        $target = realpath($dir);
        // Difesa: rimuovi SOLO dentro la base runtime, mai un path arbitrario.
        if ($base === false || $target === false || !str_starts_with($target . '/', $base . '/')) {
            return;
        }
        $this->rmrf($target);
    }

    private function rmrf(string $path): void
    {
        if (is_link($path)) {
            @unlink($path);
            return;
        }
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->rmrf($path . '/' . $entry);
            }
            @rmdir($path);
            return;
        }
        @unlink($path);
    }

    /**
     * @param array<int, resource> $pipes
     * @return array{0:int,1:bool}
     */
    private function drain(array $pipes, string &$buffer, int $total, int $cap): array
    {
        foreach ([1, 2] as $fd) {
            if (!isset($pipes[$fd]) || !is_resource($pipes[$fd])) {
                continue;
            }
            while (($data = @fread($pipes[$fd], 8192)) !== false && $data !== '') {
                $room = $cap - $total;
                if ($room <= 0) {
                    return [$total, true];
                }
                if (strlen($data) > $room) {
                    $buffer .= substr($data, 0, $room);
                    return [$cap, true];
                }
                $buffer .= $data;
                $total += strlen($data);
            }
        }

        return [$total, false];
    }

    /** @param array<int, resource> $pipes */
    private function waitReadable(array $pipes): void
    {
        $read = [];
        foreach ([1, 2] as $fd) {
            if (isset($pipes[$fd]) && is_resource($pipes[$fd])) {
                $read[] = $pipes[$fd];
            }
        }
        if ($read === []) {
            usleep(20000);
            return;
        }
        $write = null;
        $except = null;
        @stream_select($read, $write, $except, 0, 50000);
    }

    /**
     * Terminazione in due fasi (come F5): se il leader è vivo, TERM→KILL sul gruppo (-pid); poi reap
     * e, se restano figli nel gruppo dopo l'uscita del leader, li spazza. Nessun orfano.
     *
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function terminate($process, array $pipes, int $pid): void
    {
        $canGroup = $pid > 1 && function_exists('posix_kill');

        if ((bool) (proc_get_status($process)['running'] ?? false)) {
            $canGroup ? @posix_kill(-$pid, 15) : @proc_terminate($process, 15);
            $deadline = microtime(true) + 0.5;
            while (microtime(true) < $deadline) {
                if (!(bool) (proc_get_status($process)['running'] ?? false)) {
                    break;
                }
                usleep(20000);
            }
            if ((bool) (proc_get_status($process)['running'] ?? false)) {
                $canGroup ? @posix_kill(-$pid, 9) : @proc_terminate($process, 9);
            }
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        @proc_close($process);

        if ($canGroup) {
            $this->reapGroup($pid);
        }
    }

    private function reapGroup(int $pid): void
    {
        if (!function_exists('posix_kill') || $pid <= 1 || !@posix_kill(-$pid, 0)) {
            return;
        }
        @posix_kill(-$pid, 15);
        $deadline = microtime(true) + 0.5;
        while (microtime(true) < $deadline) {
            if (!@posix_kill(-$pid, 0)) {
                return;
            }
            usleep(20000);
        }
        @posix_kill(-$pid, 9);
    }

    private function error(): CommandResult
    {
        return new CommandResult(
            outcome: CommandResult::ERROR,
            exitCode: null,
            output: '',
            durationMs: 0,
            outputBytes: 0,
            truncated: false,
            timedOut: false,
        );
    }
}
