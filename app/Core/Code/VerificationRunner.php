<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 5 / F5.4. L'esecutore di UNA verifica: programma + argv, MAI una shell.
 *
 * Confini non negoziabili:
 *   - `proc_open` riceve un ARRAY (argv), quindi il sistema esegue `execvp` SENZA passare da una
 *     shell: nessun globbing, nessuna espansione, `; && | $()` in un argomento restano letterali;
 *   - working directory OBBLIGATORIA e confinata (la root del workspace, già risolta dal chiamante);
 *   - environment FILTRATO a poche variabili neutre: nessun segreto ereditato dal processo padre;
 *   - TIMEOUT: oltre il tetto il processo è TERMINATO (SIGTERM→SIGKILL);
 *   - Stop dell'utente: verificato durante l'attesa, TERMINA il processo;
 *   - output CAPPATO: oltre il tetto si smette di leggere, si tronca e si chiude.
 *
 * NON è un registro di processi persistenti (Fase 7): attende la fine (o la uccide) e ritorna. Non
 * scrive nulla nel workspace, non applica patch, non tocca Git. Esegue soltanto.
 */
final class VerificationRunner
{
    /** Ambiente MINIMO e neutro passato al processo: nessun segreto del padre. */
    private const ENV = ['LANG' => 'C', 'LC_ALL' => 'C'];

    /** Il launcher che isola il comando nel proprio process group (terminazione dell'albero). */
    private const LAUNCHER = __DIR__ . '/verification_launcher.php';

    /** @var callable(): bool */
    private $isCancelled;

    /** @var callable(): float */
    private $clock;

    public function __construct(
        private readonly VerificationLimits $limits,
        ?callable $isCancelled = null,
        ?callable $clock = null,
    ) {
        $this->isCancelled = $isCancelled ?? static fn (): bool => false;
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * Esegue $argv con working directory $cwd. Ritorna sempre un VerificationResult: un avvio
     * fallito è `error`, non un'eccezione.
     *
     * @param list<string> $argv [program, ...args]
     */
    public function run(array $argv, string $cwd): VerificationResult
    {
        if ($argv === [] || !is_dir($cwd)) {
            return $this->error();
        }

        // Il programma va risolto ad ASSOLUTO: il launcher usa pcntl_exec, che NON cerca su PATH.
        $absProgram = $this->resolveProgram((string) $argv[0], $cwd);
        if ($absProgram === null) {
            return $this->error();
        }
        $args = array_slice($argv, 1);

        // Con pcntl/posix disponibili, il comando gira dentro un PROCESS GROUP dedicato (via
        // launcher): su Stop/timeout si abbatte l'INTERO albero. Senza, si esegue direttamente e
        // si termina il solo figlio (fallback: i profili curati sono comunque mono-processo).
        $useGroup = self::supportsProcessGroupIsolation();
        $command = $useGroup
            ? array_merge([PHP_BINARY, self::LAUNCHER, '--', $absProgram], $args)
            : array_merge([$absProgram], $args);

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        // Array come comando → nessuna shell (execvp). L'ambiente è filtrato; PATH resta per
        // risolvere i binari (`php`, `node`, `python3`) senza percorsi assoluti nei profili.
        $env = self::ENV + ['PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin')];
        $process = @proc_open($command, $descriptors, $pipes, $cwd, $env);
        if (!is_resource($process)) {
            return $this->error();
        }
        $pid = (int) (proc_get_status($process)['pid'] ?? -1);

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
                // L'exit code è affidabile SOLO al primo `running=false`.
                $code = $status['exitcode'] ?? -1;
                $exitCode = is_int($code) ? $code : -1;
            }

            [$chunkTotal, $reachedCap] = $this->drain($pipes, $buffer, $total, $this->limits->maxOutputBytes);
            $total = $chunkTotal;
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

            // Attesa breve, guidata dai pipe: ritorna appena c'è output, o dopo il tick.
            $this->waitReadable($pipes);
        }

        $this->terminate($process, $pipes, $useGroup, $pid);
        $durationMs = (int) round((($this->clock)() - $start) * 1000);

        if ($timedOut) {
            $outcome = VerificationResult::TIMED_OUT;
        } elseif ($killed) {
            $outcome = VerificationResult::KILLED;
        } elseif ($exitCode === 0) {
            $outcome = VerificationResult::PASSED;
        } elseif ($exitCode === null) {
            $outcome = VerificationResult::ERROR;
        } else {
            $outcome = VerificationResult::FAILED;
        }

        return new VerificationResult(
            outcome: $outcome,
            exitCode: $exitCode,
            output: Utf8::clean($buffer),
            durationMs: $durationMs,
            outputBytes: $total,
            truncated: $truncated,
            timedOut: $timedOut,
        );
    }

    /**
     * Legge il disponibile da stdout/stderr accumulando fino al tetto complessivo. Ritorna il
     * totale aggiornato e se il tetto è stato raggiunto (oltre il quale non si legge più).
     *
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
                    $total = $cap;
                    return [$total, true];
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
        // Tick corto: mantiene reattivi timeout e Stop anche se il processo non produce output.
        @stream_select($read, $write, $except, 0, 50000);
    }

    /**
     * Termina in modo deterministico e NON lascia orfani, in DUE fasi:
     *
     *  1. se il leader è ancora vivo (timeout/Stop), abbatte l'intero gruppo (SIGTERM→SIGKILL su
     *     `-pid`), poi lo reap con proc_close;
     *  2. ANCHE se il leader è GIÀ terminato (uscita normale) ma ha lasciato figli nel gruppo, li
     *     spazza: dopo il reap del leader il pgid resta valido finché il gruppo ha membri (garanzia
     *     POSIX: il pgid non viene riusato finché il gruppo non è vuoto), quindi `posix_kill(-pid)`
     *     colpisce ESATTAMENTE quei figli, mai processi estranei.
     *
     * Senza isolamento del gruppo si abbatte il solo figlio diretto (fallback): i profili che
     * possono generare figli non arrivano qui, sono già negati a monte (fail closed).
     *
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function terminate($process, array $pipes, bool $useGroup, int $pid): void
    {
        $canGroup = $useGroup && $pid > 1 && function_exists('posix_kill');

        // Fase 1 — leader ancora vivo: TERM→KILL (al gruppo se possibile, altrimenti al figlio).
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

        // Reap del leader (chiude i pipe e ne raccoglie lo zombie).
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        @proc_close($process);

        // Fase 2 — figli rimasti nel gruppo dopo l'uscita del leader: spazzali.
        if ($canGroup) {
            $this->reapGroup($pid);
        }
    }

    /**
     * Abbatte gli eventuali processi ancora presenti nel gruppo `$pid` (leader escluso: già uscito).
     * `posix_kill(-pid, 0)` verifica se il gruppo ha ancora membri; se sì, SIGTERM, breve attesa,
     * poi SIGKILL. Se il gruppo è già vuoto non fa nulla (nessuna latenza sulle verifiche pulite).
     */
    private function reapGroup(int $pid): void
    {
        if (!function_exists('posix_kill') || $pid <= 1 || !@posix_kill(-$pid, 0)) {
            return;
        }
        @posix_kill(-$pid, 15); // SIGTERM ai figli superstiti
        $deadline = microtime(true) + 0.5;
        while (microtime(true) < $deadline) {
            if (!@posix_kill(-$pid, 0)) {
                return;
            }
            usleep(20000);
        }
        @posix_kill(-$pid, 9); // SIGKILL: nessun orfano
    }

    /**
     * Risolve il programma ad ASSOLUTO: un percorso locale (contiene `/`) è relativo alla root; un
     * nome semplice si cerca su PATH. Null se non risolvibile (avvio impossibile → esito `error`).
     */
    private function resolveProgram(string $program, string $cwd): ?string
    {
        if ($program === '' || str_contains($program, "\0")) {
            return null;
        }
        if (str_contains($program, '/')) {
            $abs = rtrim($cwd, '/') . '/' . $program;
            return (is_file($abs) && is_executable($abs)) ? $abs : null;
        }
        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            return null;
        }
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = rtrim($dir, '/') . '/' . $program;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * L'isolamento del gruppo (e quindi la terminazione GARANTITA dell'intero albero) richiede
     * pcntl+posix e il launcher su disco. È pubblico perché il rilevamento lo usa per NEGARE i
     * profili che possono generare figli quando la garanzia non c'è (fail closed).
     */
    public static function supportsProcessGroupIsolation(): bool
    {
        return function_exists('pcntl_exec')
            && function_exists('posix_setsid')
            && function_exists('posix_kill')
            && is_file(self::LAUNCHER);
    }

    private function error(): VerificationResult
    {
        return new VerificationResult(
            outcome: VerificationResult::ERROR,
            exitCode: null,
            output: '',
            durationMs: 0,
            outputBytes: 0,
            truncated: false,
            timedOut: false,
        );
    }
}
