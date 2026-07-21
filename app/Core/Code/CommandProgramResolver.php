<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 6 / F6.3. Risolve il NOME di un programma a un ESEGUIBILE ASSOLUTO REGOLARE, e SOLO
 * dentro directory fidate (correzione finale #1): `/usr/bin` e `/bin`. MAI il PATH ereditato, MAI la
 * root del workspace. Così un eseguibile omonimo nella cartella, o un PATH ostile, non possono
 * dirottare l'esecuzione.
 *
 * L'identità (il path assoluto risolto) viene fissata nella proposta e RIVALIDATA alla conferma: se
 * alla conferma il programma non risolve più allo stesso eseguibile regolare in bin fidata, la
 * conferma è negata.
 *
 * Verifica anche il supporto di `--` (correzione #4): un'utility che non lo riconosce non è
 * disponibile, perché senza `--` non possiamo garantire l'anti option-injection.
 *
 * NB: HOME/TMP/XDG isolati riducono l'esposizione ma NON sono una sandbox del processo. La difesa
 * reale è questo insieme chiuso di programmi + la rivalidazione tipizzata dei path.
 */
final class CommandProgramResolver
{
    /** @var list<string> */
    private array $trustedBins;

    /** @var array<string, string|false> cache: program → path assoluto o false (non risolvibile) */
    private array $resolveCache = [];

    /** @var array<string, bool> cache: program → supporto di `--` */
    private array $dashCache = [];

    /**
     * @param list<string>|null $trustedBins override SOLO per i test (default: /usr/bin, /bin)
     */
    public function __construct(?array $trustedBins = null)
    {
        $this->trustedBins = $trustedBins ?? ['/usr/bin', '/bin'];
    }

    /**
     * Path assoluto REGOLARE ed eseguibile del programma dentro una bin fidata, o null. Il risultato
     * di `realpath` deve risiedere in una delle bin fidate (gestisce /bin → /usr/bin come symlink di
     * sistema), ma l'eseguibile finale dev'essere un file regolare.
     */
    public function resolve(string $program): ?string
    {
        if (array_key_exists($program, $this->resolveCache)) {
            return $this->resolveCache[$program] === false ? null : $this->resolveCache[$program];
        }
        $resolved = $this->doResolve($program);
        $this->resolveCache[$program] = $resolved ?? false;

        return $resolved;
    }

    private function doResolve(string $program): ?string
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $program) !== 1) {
            return null;
        }
        $trustedReal = [];
        foreach ($this->trustedBins as $bin) {
            $real = realpath($bin);
            if ($real !== false && is_dir($real)) {
                $trustedReal[$real] = true;
            }
        }
        foreach ($this->trustedBins as $bin) {
            $candidate = rtrim($bin, '/') . '/' . $program;
            if (!is_file($candidate) || !is_executable($candidate)) {
                continue;
            }
            // realpath risolve gli eventuali symlink di sistema (es. /bin → /usr/bin): l'eseguibile
            // FINALE dev'essere un file regolare e la sua directory una bin fidata. Un symlink che
            // punta FUORI dalle bin fidate (es. nella root) risolve altrove → rifiutato.
            $real = realpath($candidate);
            if ($real === false || !is_file($real) || !is_executable($real)) {
                continue;
            }
            if (!isset($trustedReal[dirname($real)])) {
                continue;
            }

            return $real;
        }

        return null;
    }

    /**
     * L'utility riconosce `--` come terminatore delle opzioni? Probe benigno (solo /dev/null),
     * argv (mai shell), timeout corto. Se stderr segnala un'opzione illegale, `--` non è supportato.
     */
    public function supportsDoubleDash(CommandSpec $spec): bool
    {
        if (array_key_exists($spec->program, $this->dashCache)) {
            return $this->dashCache[$spec->program];
        }
        $exe = $this->resolve($spec->program);
        if ($exe === null) {
            return $this->dashCache[$spec->program] = false;
        }

        $operands = ['--'];
        if ($spec->allowsPattern) {
            $operands[] = 'x';
        }
        for ($i = 0; $i < max(1, $spec->minPaths); $i++) {
            $operands[] = '/dev/null';
        }

        $this->dashCache[$spec->program] = $this->probe($exe, $operands);

        return $this->dashCache[$spec->program];
    }

    /**
     * @param list<string> $operands
     */
    private function probe(string $exe, array $operands): bool
    {
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $env = ['LANG' => 'C', 'LC_ALL' => 'C', 'PATH' => implode(':', $this->trustedBins)];
        $process = @proc_open(array_merge([$exe], $operands), $descriptors, $pipes, sys_get_temp_dir(), $env);
        if (!is_resource($process)) {
            return false;
        }
        foreach ([1, 2] as $fd) {
            if (isset($pipes[$fd]) && is_resource($pipes[$fd])) {
                stream_set_blocking($pipes[$fd], false);
            }
        }
        $stderr = '';
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                $chunk = @fread($pipes[2], 4096);
                if (is_string($chunk)) {
                    $stderr .= $chunk;
                }
            }
            if (!($status['running'] ?? false)) {
                break;
            }
            usleep(10000);
        }
        if ((bool) (proc_get_status($process)['running'] ?? false)) {
            @proc_terminate($process, 9);
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        @proc_close($process);

        return preg_match('/illegal option|unrecognized option|invalid option|unknown option|not an option/i', $stderr) !== 1;
    }
}
