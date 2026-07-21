<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 7. Implementazione reale di ProcessInspector via `posix_kill(pid, 0)` (liveness) e
 * `ps -o lstart= -p <pid>` (firma d'avvio) in argv, MAI shell. La firma è l'ora d'avvio del
 * processo: stabile per tutta la vita del processo, ma DIVERSA per un PID riciclato.
 *
 * Su `ps` non disponibile o fallito la firma è '' → identità non verificabile → il chiamante non
 * segnala il processo (fail closed).
 */
final class PsProcessInspector implements ProcessInspector
{
    public function isAlive(int $pid): bool
    {
        if ($pid <= 1 || !function_exists('posix_kill')) {
            return false;
        }

        return @posix_kill($pid, 0);
    }

    public function signature(int $pid): string
    {
        if ($pid <= 1) {
            return '';
        }
        $out = $this->run(['ps', '-o', 'lstart=', '-p', (string) $pid]);
        if ($out === null) {
            $out = $this->run(['ps', '-o', 'lstart=', (string) $pid]); // BSD/GNU tolleranti
        }

        return $out === null ? '' : trim($out);
    }

    public function processGroupId(int $pid): ?int
    {
        if ($pid <= 1 || !function_exists('posix_getpgid')) {
            return null;
        }
        $pgid = @posix_getpgid($pid);

        return is_int($pgid) && $pgid > 1 ? $pgid : null;
    }

    /**
     * @param list<string> $argv
     */
    private function run(array $argv): ?string
    {
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']];
        $pipes = [];
        $env = ['LANG' => 'C', 'LC_ALL' => 'C', 'PATH' => '/usr/bin:/bin'];
        $process = @proc_open($argv, $descriptors, $pipes, sys_get_temp_dir(), $env);
        if (!is_resource($process)) {
            return null;
        }
        $out = '';
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            stream_set_blocking($pipes[1], true);
            $out = (string) @stream_get_contents($pipes[1]);
            @fclose($pipes[1]);
        }
        $code = @proc_close($process);
        if ($code !== 0 || trim($out) === '') {
            return null;
        }

        return $out;
    }
}
