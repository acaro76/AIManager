<?php

use App\Core\Code\CodeWorkingMemorySchema;
use App\Core\Database;

// Fase 9 / Step 2: UNA memoria di lavoro CORRENTE per sessione Code (soli metadati curati; il
// payload è la serializzazione canonica di CodeWorkingMemory). ADDITIVA: non tocca le tabelle Code
// esistenti (chat + patch + verifiche + comandi + processi + git); aggiunge solo
// `code_working_memories`. Richiede lo schema chat di base (FK verso
// code_workspaces/code_sessions/code_conversations). Presenze divergenti falliscono PRIMA di
// qualunque modifica; nessun dato esistente viene alterato o cancellato.
return function (Database $db): void {
    $table = CodeWorkingMemorySchema::TABLE;
    if (CodeWorkingMemorySchema::tableExists($db, $table)) {
        $problems = CodeWorkingMemorySchema::verify($db);
        if ($problems !== []) {
            throw new \RuntimeException('040: schema memoria di lavoro Code incompatibile. ' . implode(' | ', $problems));
        }
        return;
    }

    foreach (['code_workspaces', 'code_sessions', 'code_conversations'] as $required) {
        if (!CodeWorkingMemorySchema::tableExists($db, $required)) {
            throw new \RuntimeException('040: schema Code di base incompleto; nessuna modifica applicata.');
        }
    }

    CodeWorkingMemorySchema::applyDdl($db);

    // Il runner racchiude la migrazione in una transazione: verificare DOPO il DDL permette di
    // annullare atomicamente la tabella su qualunque divergenza.
    $problems = CodeWorkingMemorySchema::verify($db);
    if ($problems !== []) {
        throw new \RuntimeException('040: schema memoria di lavoro Code incompatibile. ' . implode(' | ', $problems));
    }
};
