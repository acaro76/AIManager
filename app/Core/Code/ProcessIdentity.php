<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 7. Verifica dell'IDENTITÀ di un processo persistente PRIMA di segnalarlo. Non ci si
 * fida mai di un PID/PGID solo perché è memorizzato: un PID può essere stato riciclato dal sistema e
 * un segnale colpirebbe un processo estraneo.
 *
 * Tre controlli concordi:
 *   1. il PIDFILE nel runtime protetto esiste, non è un symlink, e combacia con pid/pgid/run_token
 *      persistiti (l'esecuzione è la nostra);
 *   2. il processo è VIVO (`isAlive`);
 *   3. la FIRMA d'avvio corrente combacia con quella catturata all'avvio (`start_signature`): se
 *      differisce (o non è determinabile), il PID è stato riciclato o l'identità non è verificabile.
 *
 * Esito:
 *   - ALIVE    : identità verificata e processo vivo → si può segnalare in sicurezza;
 *   - DEAD     : identità nostra, ma processo già uscito → nessun segnale necessario;
 *   - MISMATCH : processo vivo ma firma divergente → PID riciclato → NON segnalare (fail closed);
 *   - UNKNOWN  : pidfile assente/incoerente → identità non verificabile → NON segnalare (fail closed).
 */
final class ProcessIdentity
{
    public const ALIVE = 'alive';
    public const DEAD = 'dead';
    public const MISMATCH = 'mismatch';
    public const UNKNOWN = 'unknown';

    /**
     * @param array<string, mixed> $row riga di code_processes
     */
    public static function verify(array $row, string $runtimeBaseDir, ProcessInspector $inspector): string
    {
        $pid = isset($row['pid']) ? (int) $row['pid'] : 0;
        $pgid = isset($row['pgid']) ? (int) $row['pgid'] : 0;
        $token = isset($row['run_token']) ? (string) $row['run_token'] : '';
        $signature = isset($row['start_signature']) ? (string) $row['start_signature'] : '';
        $executionId = isset($row['execution_id']) ? (string) $row['execution_id'] : '';
        $workspaceId = isset($row['workspace_id']) ? (int) $row['workspace_id'] : 0;

        // Il launcher crea una nuova sessione: il leader deve avere pid == pgid. Non basta che il
        // valore sia presente nel DB/pidfile, perché il segnale verrà inviato al gruppo negativo.
        if ($pid <= 1 || $pgid <= 1 || $pid !== $pgid || $token === '' || $executionId === '') {
            return self::UNKNOWN;
        }

        $runtime = ProcessRuntime::locate($runtimeBaseDir, $workspaceId, $executionId);
        if ($runtime === null) {
            return self::UNKNOWN;
        }
        $pidfile = ProcessRuntime::readPidfile($runtime['pid_file']);
        if ($pidfile === null
            || !hash_equals($token, $pidfile['run_token'])
            || $pidfile['pid'] !== $pid
            || $pidfile['pgid'] !== $pgid) {
            return self::UNKNOWN;
        }

        if (!$inspector->isAlive($pid)) {
            return self::DEAD;
        }

        // Verifica il process group REALE subito prima di autorizzare qualunque segnale. Un PGID
        // non determinabile o divergente può essere stale/riciclato: fail closed.
        $currentPgid = $inspector->processGroupId($pid);
        if ($currentPgid === null || $currentPgid !== $pgid) {
            return self::MISMATCH;
        }

        // Firma d'avvio: se non è determinabile ORA (o non lo era all'avvio) l'identità non è
        // verificabile → si tratta come MISMATCH (fail closed: nessun segnale a un PID incerto).
        $current = $inspector->signature($pid);
        if ($signature === '' || $current === '' || !hash_equals($signature, $current)) {
            return self::MISMATCH;
        }

        return self::ALIVE;
    }
}
