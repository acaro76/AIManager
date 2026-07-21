<?php

use App\Core\Code\CommandRunSchema;
use App\Core\Database;

// Fase 6: lifecycle persistente dei comandi locali (soli metadati). ADDITIVA: non tocca le sei
// tabelle Code esistenti (chat + patch + verifiche) e NON altera code_operation_logs né
// code_verification_runs; aggiunge solo `code_command_runs`. Richiede lo schema chat di base (FK
// verso code_workspaces/code_sessions/code_conversations). Presenze divergenti falliscono PRIMA di
// qualunque modifica.
return function (Database $db): void {
    $table = CommandRunSchema::TABLE;
    if (CommandRunSchema::tableExists($db, $table)) {
        $problems = CommandRunSchema::verify($db);
        if ($problems !== []) {
            throw new \RuntimeException('036: schema comandi Code incompatibile. ' . implode(' | ', $problems));
        }
        return;
    }

    foreach (['code_workspaces', 'code_sessions', 'code_conversations'] as $required) {
        if (!CommandRunSchema::tableExists($db, $required)) {
            throw new \RuntimeException('036: schema Code di base incompleto; nessuna modifica applicata.');
        }
    }

    CommandRunSchema::applyDdl($db);

    // Il runner racchiude la migrazione in una transazione: verificare DOPO il DDL permette di
    // annullare atomicamente la tabella su qualunque divergenza.
    $problems = CommandRunSchema::verify($db);
    if ($problems !== []) {
        throw new \RuntimeException('036: schema comandi Code incompatibile. ' . implode(' | ', $problems));
    }
};
