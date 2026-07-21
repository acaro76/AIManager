<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 7. Derivazione e validazione dei percorsi del runtime di un processo persistente, sotto
 * una radice `storage/` fidata e SYMLINK-SAFE (via SafeStorageDir): `base/{workspace}/{execution}/`
 * con dentro `server.pid` (identità) e `server.log` (stdout/stderr).
 *
 * Separato dal runtime dei comandi (Fase 6): un server VIVE tra le richieste, quindi la sua directory
 * NON è effimera come quella di un comando — non va rimossa alla fine dell'avvio, ma solo all'arresto.
 *
 * Il DB tiene solo un `log_id` OPACO (== execution_id): il path assoluto del log NON è mai esposto né
 * persistito; si ricostruisce qui, server-side, dallo scope.
 */
final class ProcessRuntime
{
    public const PIDFILE = 'server.pid';
    public const LOGFILE = 'server.log';

    private const EXEC_ID = '/^[A-Za-z0-9_-]{8,80}$/';

    /**
     * Crea (fresh) il runtime dell'esecuzione, con `home`/`tmp` isolati per l'environment effimero.
     * Ogni componente è rifiutato se è un symlink (SafeStorageDir), e la dir d'esecuzione dev'essere
     * FRESCA (mai preesistente). Null → fail closed.
     *
     * @return array{dir:string,pid_file:string,log_file:string}|null
     */
    public static function prepare(string $baseDir, int $workspaceId, string $executionId): ?array
    {
        if (preg_match(self::EXEC_ID, $executionId) !== 1) {
            return null;
        }
        $anchor = dirname(rtrim($baseDir, '/'));
        $chain = [basename(rtrim($baseDir, '/')), (string) $workspaceId, $executionId];

        $dir = SafeStorageDir::ensure($anchor, $chain, true);
        if ($dir === null) {
            return null;
        }
        if (SafeStorageDir::ensure($anchor, array_merge($chain, ['home']), false) === null
            || SafeStorageDir::ensure($anchor, array_merge($chain, ['tmp']), false) === null) {
            return null;
        }

        return [
            'dir' => $dir,
            'pid_file' => $dir . '/' . self::PIDFILE,
            'log_file' => $dir . '/' . self::LOGFILE,
        ];
    }

    /**
     * Individua un runtime GIÀ esistente (per Stop / lettura log dopo refresh), senza crearlo. Null se
     * assente, non una directory o con un componente symlink.
     *
     * @return array{dir:string,pid_file:string,log_file:string}|null
     */
    public static function locate(string $baseDir, int $workspaceId, string $executionId): ?array
    {
        if (preg_match(self::EXEC_ID, $executionId) !== 1) {
            return null;
        }
        $base = realpath(rtrim($baseDir, '/'));
        if ($base === false || !is_dir($base)) {
            return null;
        }
        $dir = $base;
        foreach ([(string) $workspaceId, $executionId] as $comp) {
            $next = $dir . '/' . $comp;
            if (is_link($next) || !is_dir($next)) {
                return null; // assente o symlink → non identificabile (fail closed)
            }
            $dir = $next;
        }

        return [
            'dir' => $dir,
            'pid_file' => $dir . '/' . self::PIDFILE,
            'log_file' => $dir . '/' . self::LOGFILE,
        ];
    }

    /**
     * Legge il pidfile del server: no-symlink, JSON valido, pid/pgid interi > 1 e run_token stringa.
     * Null (fail closed) altrimenti: senza identità non si segnala nulla.
     *
     * @return array{pid:int,pgid:int,run_token:string}|null
     */
    public static function readPidfile(string $pidFile): ?array
    {
        if (!is_file($pidFile) || is_link($pidFile)) {
            return null;
        }
        $raw = @file_get_contents($pidFile);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        $pid = isset($data['pid']) ? (int) $data['pid'] : 0;
        $pgid = isset($data['pgid']) ? (int) $data['pgid'] : 0;
        $token = isset($data['run_token']) && is_string($data['run_token']) ? $data['run_token'] : '';
        if ($pid <= 1 || $pgid <= 1 || $token === '') {
            return null;
        }

        return ['pid' => $pid, 'pgid' => $pgid, 'run_token' => $token];
    }

    /**
     * Coda del log ESPOSTA alla UI: al massimo `$maxBytes` byte dalla fine, come DATO NON FIDATO
     * (UTF-8 ripulito). No-symlink; file assente → ''. Il file su disco NON viene modificato.
     */
    public static function tailLog(string $logFile, int $maxBytes): string
    {
        if ($maxBytes <= 0 || !is_file($logFile) || is_link($logFile)) {
            return '';
        }
        $size = @filesize($logFile);
        $handle = @fopen($logFile, 'rb');
        if ($handle === false) {
            return '';
        }
        try {
            if ($size !== false && $size > $maxBytes) {
                @fseek($handle, $size - $maxBytes);
            }
            $data = @stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        return $data === false ? '' : Utf8::clean($data);
    }

    /**
     * Rimuove il runtime di un'esecuzione (all'arresto): SOLO dentro la base fidata, mai un path
     * arbitrario. I symlink-directory sono già stati esclusi da locate(); qui si scollega/cancella.
     */
    public static function cleanup(string $baseDir, int $workspaceId, string $executionId): void
    {
        $located = self::locate($baseDir, $workspaceId, $executionId);
        if ($located === null) {
            return;
        }
        $base = realpath(rtrim($baseDir, '/'));
        $target = realpath($located['dir']);
        if ($base === false || $target === false || !str_starts_with($target . '/', $base . '/')) {
            return;
        }
        self::rmrf($target);
    }

    private static function rmrf(string $path): void
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
                self::rmrf($path . '/' . $entry);
            }
            @rmdir($path);
            return;
        }
        @unlink($path);
    }
}
