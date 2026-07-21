<?php

use App\Core\Code\ProcessRunSchema;
use App\Core\Database;

// Fase 7: lifecycle persistente dei processi locali (server PHP; soli metadati + identità
// d'esecuzione). ADDITIVA: non tocca le sette tabelle Code esistenti (chat + patch + verifiche +
// comandi) e NON altera code_command_runs; aggiunge solo `code_processes`. Richiede lo schema chat
// di base (FK verso code_workspaces/code_sessions/code_conversations). Presenze divergenti
// falliscono PRIMA di qualunque modifica.
return function (Database $db): void {
    $table = ProcessRunSchema::TABLE;
    if (ProcessRunSchema::tableExists($db, $table)) {
        $problems = ProcessRunSchema::verify($db);
        if ($problems !== []) {
            throw new \RuntimeException('038: schema processi Code incompatibile. ' . implode(' | ', $problems));
        }
        return;
    }

    foreach (['code_workspaces', 'code_sessions', 'code_conversations'] as $required) {
        if (!ProcessRunSchema::tableExists($db, $required)) {
            throw new \RuntimeException('038: schema Code di base incompleto; nessuna modifica applicata.');
        }
    }

    ProcessRunSchema::applyDdl($db);

    // Il runner racchiude la migrazione in una transazione: verificare DOPO il DDL permette di
    // annullare atomicamente la tabella su qualunque divergenza.
    $problems = ProcessRunSchema::verify($db);
    if ($problems !== []) {
        throw new \RuntimeException('038: schema processi Code incompatibile. ' . implode(' | ', $problems));
    }
};
