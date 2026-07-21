<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 8 / base Git read-only. Esegue UNA invocazione di git, con confini non negoziabili:
 *
 *   - eseguibile `git` risolto SERVER-SIDE a un file regolare in una bin FIDATA (`/usr/bin`, `/bin`),
 *     MAI dal PATH ereditato né dalla root del workspace;
 *   - `proc_open` riceve un ARRAY (argv): il sistema esegue `execvp` SENZA shell — nessun globbing,
 *     nessuna espansione, `; && | $()` restano letterali. Gli operandi sono costruiti dal chiamante
 *     (GitService) a VOCABOLARIO CHIUSO;
 *   - working directory OBBLIGATORIA (la root Code già risolta/rivalidata dal chiamante);
 *   - environment FILTRATO e neutro: nessun segreto ereditato; config utente/di sistema NEUTRALIZZATA
 *     (`GIT_CONFIG_GLOBAL`/`GIT_CONFIG_SYSTEM` → /dev/null) così alias, `insteadOf`, hook, external diff
 *     e textconv definiti in `~/.gitconfig` non possono agire; nessuna variabile di rete;
 *   - TIMEOUT: oltre il tetto il processo è TERMINATO (SIGTERM→SIGKILL);
 *   - output CAPPATO: oltre il tetto si tronca e si chiude.
 *
 * È SOLO un trasporto d'esecuzione: non decide quali comandi sono ammessi (lo fa GitService) e non
 * interpreta l'output. Distinto dal sottosistema dei comandi generici (CommandRegistry): git non vi
 * appartiene.
 */
final class GitInvoker
{
    /** Ambiente MINIMO, neutro e senza rete passato a git. */
    private const ENV = [
        'LANG' => 'C',
        'LC_ALL' => 'C',
        'PATH' => '/usr/bin:/bin',
        // Config utente/di sistema neutralizzata: nessun external diff/textconv/alias/insteadOf/hook.
        'GIT_CONFIG_GLOBAL' => '/dev/null',
        'GIT_CONFIG_SYSTEM' => '/dev/null',
        // Nessun aggiornamento opzionale dell'indice (nessun lock/scrittura opportunistica).
        'GIT_OPTIONAL_LOCKS' => '0',
        // Nessuna interazione: mai un prompt (credenziali/rete) che appenda il processo.
        'GIT_TERMINAL_PROMPT' => '0',
        'GIT_PAGER' => 'cat',
        'PAGER' => 'cat',
    ];

    /** @var list<string> */
    private array $trustedBins;

    /** @var string|false|null cache della risoluzione: path assoluto, false (non risolvibile), null (mai tentato) */
    private string|false|null $gitPath = null;

    /** @var callable(): float */
    private $clock;

    /**
     * @param list<string>|null $trustedBins override SOLO per i test (default: /usr/bin, /bin)
     */
    public function __construct(
        private readonly GitLimits $limits,
        ?array $trustedBins = null,
        ?callable $clock = null,
    ) {
        $this->trustedBins = $trustedBins ?? ['/usr/bin', '/bin'];
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /** git è risolvibile a un eseguibile regolare in una bin fidata? */
    public function available(): bool
    {
        return $this->resolveGit() !== null;
    }

    /**
     * Esegue `git <operands>` in `$cwd`. Ritorna SEMPRE un GitCommandResult: un avvio impossibile è
     * `started=false`, non un'eccezione (la traduzione in errore atteso spetta a GitService).
     *
     * @param list<string> $operands  argv DOPO il programma, costruito dal server a vocabolario chiuso
     * @param ?string      $indexFile UNICA variabile d'ambiente aggiuntiva ammessa: `GIT_INDEX_FILE`
     *        (indice temporaneo/quarantena), TIPIZZATA e non un env array arbitrario. Deve essere un
     *        percorso ASSOLUTO, privo di NUL, già costruito/validato server-side. Non c'è alcuna
     *        superficie per iniettare `LD_PRELOAD`, `DYLD_*`, `PATH`, `HOME` o config Git: le variabili
     *        di sicurezza (self::ENV) sono inderogabili e sostituiscono l'intero ambiente del processo.
     */
    public function run(array $operands, string $cwd, ?string $indexFile = null): GitCommandResult
    {
        $git = $this->resolveGit();
        if ($git === null || $cwd === '' || !is_dir($cwd)) {
            return new GitCommandResult(started: false, exitCode: -1, stdout: '', truncated: false, timedOut: false);
        }
        // GIT_INDEX_FILE: solo un percorso ASSOLUTO senza NUL. Qualunque altra forma → avvio negato.
        if ($indexFile !== null && ($indexFile === '' || $indexFile[0] !== '/' || str_contains($indexFile, "\0"))) {
            return new GitCommandResult(started: false, exitCode: -1, stdout: '', truncated: false, timedOut: false);
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        // Ambiente FISSO e sostitutivo: le variabili di sicurezza (self::ENV) più HOME effimero, e SOLO
        // eventualmente GIT_INDEX_FILE. Nessuna variabile del processo padre viene ereditata.
        $env = self::ENV + ['HOME' => sys_get_temp_dir()];
        if ($indexFile !== null) {
            $env['GIT_INDEX_FILE'] = $indexFile;
        }
        $process = @proc_open(array_merge([$git], $operands), $descriptors, $pipes, $cwd, $env);
        if (!is_resource($process)) {
            return new GitCommandResult(started: false, exitCode: -1, stdout: '', truncated: false, timedOut: false);
        }

        foreach ([1, 2] as $fd) {
            if (isset($pipes[$fd]) && is_resource($pipes[$fd])) {
                stream_set_blocking($pipes[$fd], false);
            }
        }

        $start = ($this->clock)();
        $deadline = $start + $this->limits->maxSeconds;
        $stdout = '';
        $stdoutBytes = 0;
        $truncated = false;
        $timedOut = false;
        $exitCode = null;

        while (true) {
            $status = proc_get_status($process);
            $running = (bool) ($status['running'] ?? false);
            if (!$running && $exitCode === null) {
                $code = $status['exitcode'] ?? -1;
                $exitCode = is_int($code) ? $code : -1;
            }

            // stdout: accumulato fino al tetto. stderr: drenato e SCARTATO (non torna come testo),
            // così un pipe pieno non blocca git ma il suo contenuto non viene interpretato.
            [$stdoutBytes, $capped] = $this->drainCapped($pipes[1] ?? null, $stdout, $stdoutBytes, $this->limits->maxOutputBytes);
            $this->drainDiscard($pipes[2] ?? null);
            if ($capped) {
                $truncated = true;
                break;
            }
            if (!$running) {
                break;
            }
            if (($this->clock)() >= $deadline) {
                $timedOut = true;
                break;
            }

            $this->waitReadable($pipes);
        }

        // Un processo molto rapido può risultare già terminato prima che l'ultimo contenuto delle
        // pipe non bloccanti diventi leggibile. Drena fino a EOF dopo l'uscita naturale: senza
        // questo passaggio status/diff potevano risultare vuoti in modo intermittente.
        if (!$truncated && !$timedOut) {
            foreach ([1, 2] as $fd) {
                if (isset($pipes[$fd]) && is_resource($pipes[$fd])) {
                    @stream_set_blocking($pipes[$fd], true);
                }
            }
            [$stdoutBytes, $capped] = $this->drainCapped(
                $pipes[1] ?? null,
                $stdout,
                $stdoutBytes,
                $this->limits->maxOutputBytes
            );
            $this->drainDiscard($pipes[2] ?? null);
            $truncated = $truncated || $capped;
        }

        $this->terminate($process, $pipes);

        return new GitCommandResult(
            started: true,
            exitCode: $exitCode ?? -1,
            stdout: $stdout,
            truncated: $truncated,
            timedOut: $timedOut,
        );
    }

    /**
     * Path assoluto REGOLARE ed eseguibile di `git` in una bin fidata, o null. `realpath` risolve gli
     * eventuali symlink di sistema (es. /bin → /usr/bin): l'eseguibile FINALE dev'essere un file
     * regolare la cui directory è una bin fidata. Un symlink che punta FUORI (es. nella root) risolve
     * altrove → rifiutato.
     */
    private function resolveGit(): ?string
    {
        if ($this->gitPath !== null) {
            return $this->gitPath === false ? null : $this->gitPath;
        }

        $trustedReal = [];
        foreach ($this->trustedBins as $bin) {
            $real = realpath($bin);
            if ($real !== false && is_dir($real)) {
                $trustedReal[$real] = true;
            }
        }
        foreach ($this->trustedBins as $bin) {
            $candidate = rtrim($bin, '/') . '/git';
            if (!is_file($candidate) || !is_executable($candidate)) {
                continue;
            }
            $real = realpath($candidate);
            if ($real === false || !is_file($real) || !is_executable($real)) {
                continue;
            }
            if (!isset($trustedReal[dirname($real)])) {
                continue;
            }
            $this->gitPath = $real;
            return $real;
        }

        $this->gitPath = false;
        return null;
    }

    /**
     * Legge il disponibile da un pipe accumulando in $buffer fino a $cap; oltre il tetto tronca e
     * segnala. Ritorna il totale aggiornato e se il tetto è stato raggiunto.
     *
     * @param resource|null $pipe
     * @return array{0:int,1:bool}
     */
    private function drainCapped($pipe, string &$buffer, int $total, int $cap): array
    {
        if (!is_resource($pipe)) {
            return [$total, false];
        }
        while (($data = @fread($pipe, 8192)) !== false && $data !== '') {
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

        return [$total, false];
    }

    /** @param resource|null $pipe Drena e SCARTA (evita il blocco su stderr pieno). */
    private function drainDiscard($pipe): void
    {
        if (!is_resource($pipe)) {
            return;
        }
        while (($data = @fread($pipe, 8192)) !== false && $data !== '') {
            // volutamente scartato
        }
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
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function terminate($process, array $pipes): void
    {
        if ((bool) (proc_get_status($process)['running'] ?? false)) {
            @proc_terminate($process, 15);
            $deadline = microtime(true) + 0.5;
            while (microtime(true) < $deadline) {
                if (!(bool) (proc_get_status($process)['running'] ?? false)) {
                    break;
                }
                usleep(20000);
            }
            if ((bool) (proc_get_status($process)['running'] ?? false)) {
                @proc_terminate($process, 9);
            }
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        @proc_close($process);
    }
}
