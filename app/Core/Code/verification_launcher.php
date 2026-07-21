<?php

declare(strict_types=1);

/**
 * Code — Fase 5. Launcher della verifica: isola il comando nel proprio PROCESS GROUP e poi lo
 * sostituisce (exec) in place. Serve per la TERMINAZIONE DELL'INTERO ALBERO: mettendo il processo
 * come leader di una nuova sessione (posix_setsid), il genitore può inviare il segnale al gruppo
 * negativo (-pid) e abbattere anche i figli/nipoti su Stop o timeout, senza processi orfani.
 *
 * NON è una shell e NON interpreta nulla: riceve il programma ASSOLUTO e i suoi argomenti già
 * risolti dal chiamante (dopo un separatore `--`) ed esegue esattamente quelli. In assenza di
 * pcntl/posix esce con errore: il chiamante allora esegue il comando direttamente (fallback).
 *
 * Uso: php verification_launcher.php -- <programma-assoluto> [arg...]
 */

$argv = $_SERVER['argv'] ?? [];
$sep = array_search('--', $argv, true);
if ($sep === false || !isset($argv[$sep + 1])) {
    fwrite(STDERR, 'launcher: argomenti non validi');
    exit(127);
}

$parts = array_slice($argv, $sep + 1);
$program = (string) array_shift($parts);
if ($program === '') {
    fwrite(STDERR, 'launcher: programma mancante');
    exit(127);
}

// Nuova sessione → nuovo process group con questo processo come leader (pgid == pid). Se fallisce
// (già leader: improbabile per un figlio appena creato) si prosegue: al peggio si perde solo la
// terminazione dell'albero, mai la sicurezza.
if (function_exists('posix_setsid')) {
    @posix_setsid();
}

if (!function_exists('pcntl_exec')) {
    fwrite(STDERR, 'launcher: pcntl non disponibile');
    exit(127);
}

// pcntl_exec eredita l'ambiente (già filtrato da chi ci ha avviati) e sostituisce l'immagine del
// processo: da qui in poi questo PID È il comando di verifica, leader del proprio gruppo.
pcntl_exec($program, array_values($parts));

// pcntl_exec ritorna SOLO in caso di fallimento dell'exec.
fwrite(STDERR, 'launcher: exec fallito');
exit(127);
