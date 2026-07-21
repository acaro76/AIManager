<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 7. Osservazione di un processo per PID, senza fidarsi ciecamente di un PID/PGID
 * memorizzato. Due segnali indipendenti:
 *   - `isAlive(pid)`  : il processo esiste ancora?
 *   - `signature(pid)`: una firma d'avvio STABILE del processo (l'ora d'avvio via `ps`). Se il PID è
 *                       stato riciclato dal sistema, la firma cambia: confrontandola con quella
 *                       catturata all'avvio si intercetta il riuso.
 *
 * È un'interfaccia (iniettabile) così i test possono forzare gli esiti d'identità senza `ps` reale.
 * L'implementazione reale usa `ps` in argv (mai shell); su fallimento la firma è '' (identità NON
 * verificabile → il chiamante fallisce chiuso e NON segnala).
 */
interface ProcessInspector
{
    public function isAlive(int $pid): bool;

    /** Firma d'avvio stabile (o '' se non determinabile). */
    public function signature(int $pid): string;

    /** Process group corrente del PID, o null se non determinabile. */
    public function processGroupId(int $pid): ?int;
}
