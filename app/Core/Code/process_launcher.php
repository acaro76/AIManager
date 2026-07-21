<?php

declare(strict_types=1);

/**
 * Code — Fase 7. Launcher del PROCESSO PERSISTENTE (server PHP). Distinto da
 * `verification_launcher.php` (Fase 5/6, INTOCCATO): un server deve SOPRAVVIVERE alla richiesta che
 * lo avvia, quindi qui si DOPPIO-FORKA e ci si detacha, invece di eseguire in place e attendere.
 *
 * Sequenza:
 *   1. fork: il figlio DIRETTO (quello che `proc_open` vede) esce SUBITO, così `proc_close` del
 *      chiamante ritorna immediatamente e la richiesta non resta bloccata sul server;
 *   2. il NIPOTE fa `posix_setsid()` → diventa leader del proprio process group (pgid == pid): il
 *      chiamante potrà poi terminare l'INTERO albero segnalando il gruppo negativo (-pgid);
 *   3. il nipote scrive un PIDFILE (pid, pgid, run_token) nel runtime protetto: è l'identità che lo
 *      Stop dopo un refresh verifica PRIMA di segnalare, per ridurre il rischio di PID/PGID riciclato;
 *   4. `pcntl_exec` sostituisce l'immagine col programma ASSOLUTO e i suoi argomenti già risolti dal
 *      chiamante (dopo `--`). NON è una shell e NON interpreta nulla.
 *
 * stdout/stderr sono già rediretti dal chiamante (descrittori di `proc_open`) verso il file di log:
 * il launcher non tocca lo stdio. In assenza di pcntl/posix esce con errore (fail closed).
 *
 * Uso: php process_launcher.php -- <pidfile> <run_token> <programma-assoluto> [arg...]
 */

$argv = $_SERVER['argv'] ?? [];
$sep = array_search('--', $argv, true);
if ($sep === false) {
    fwrite(STDERR, 'process_launcher: argomenti non validi');
    exit(127);
}

$parts = array_slice($argv, $sep + 1);
$pidFile = (string) array_shift($parts);
$runToken = (string) array_shift($parts);
$maxLogFileBytes = filter_var(array_shift($parts), FILTER_VALIDATE_INT);
$program = (string) array_shift($parts);
if ($pidFile === '' || $runToken === '' || $maxLogFileBytes === false
    || $maxLogFileBytes < 1 || $program === '') {
    fwrite(STDERR, 'process_launcher: parametri mancanti');
    exit(127);
}

if (!function_exists('pcntl_fork') || !function_exists('posix_setsid') || !function_exists('pcntl_exec')
    || !function_exists('posix_setrlimit') || !defined('POSIX_RLIMIT_FSIZE')) {
    fwrite(STDERR, 'process_launcher: pcntl/posix non disponibili');
    exit(127);
}

// (1) Detach: il figlio diretto esce, il nipote prosegue come processo indipendente.
$pid = pcntl_fork();
if ($pid === -1) {
    fwrite(STDERR, 'process_launcher: fork fallito');
    exit(127);
}
if ($pid > 0) {
    exit(0); // il processo che proc_open osserva termina subito → proc_close non blocca
}

// (2) Nuova sessione → nuovo process group con questo processo come leader (pgid == pid).
if (posix_setsid() === -1) {
    exit(127);
}
$self = (int) getmypid();

// Limite REALE del file stdout/stderr ereditato: non basta limitare la coda mostrata in UI.
// Se il server supera il tetto, il sistema può consegnare SIGXFSZ/negare altre scritture e il
// processo può terminare. È preferibile a una crescita disco illimitata.
if (!@posix_setrlimit(POSIX_RLIMIT_FSIZE, $maxLogFileBytes, $maxLogFileBytes)) {
    exit(127);
}

// (3) Pidfile con l'identità dell'esecuzione (scrittura atomica: tmp + rename).
$payload = json_encode([
    'pid' => $self,
    'pgid' => $self,
    'run_token' => $runToken,
    'started_at' => date('c'),
], JSON_UNESCAPED_SLASHES);
$tmp = $pidFile . '.tmp';
if (@file_put_contents($tmp, (string) $payload) === false || !@rename($tmp, $pidFile)) {
    // Senza pidfile il processo non sarebbe identificabile né arrestabile: fail closed.
    @unlink($tmp);
    exit(127);
}

// (4) Sostituisci l'immagine col server. Eredita l'ambiente già filtrato dal chiamante e i
//     descrittori di stdout/stderr già rediretti verso il log. Ritorna SOLO se l'exec fallisce.
pcntl_exec($program, array_values($parts));

fwrite(STDERR, 'process_launcher: exec fallito');
exit(127);
