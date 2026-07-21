<?php

use App\Core\Code\CodePatchSchema;
use App\Core\Database;

// Fase 4: registro delle operazioni di modifica sicura dei file (soli metadati). ADDITIVA: non
// tocca le quattro tabelle della chat Code, aggiunge solo `code_patch_operations`. Richiede lo
// schema chat di base gia' presente (FK verso code_workspaces/code_sessions/code_conversations).
// Presenze divergenti falliscono PRIMA di qualunque modifica.
return function (Database $db): void {
    $table = CodePatchSchema::TABLE;
    if (CodePatchSchema::tableExists($db, $table)) {
        $problems = CodePatchSchema::verify($db);
        if ($problems !== []) {
            throw new \RuntimeException('034: schema operazioni patch Code incompatibile. ' . implode(' | ', $problems));
        }
        return;
    }

    foreach (['code_workspaces', 'code_sessions', 'code_conversations'] as $required) {
        if (!CodePatchSchema::tableExists($db, $required)) {
            throw new \RuntimeException('034: schema Code di base incompleto; nessuna modifica applicata.');
        }
    }

    CodePatchSchema::applyDdl($db);

    // Il runner racchiude la migrazione in una transazione: verificare DOPO il DDL permette di
    // annullare atomicamente la tabella su qualunque divergenza.
    $problems = CodePatchSchema::verify($db);
    if ($problems !== []) {
        throw new \RuntimeException('034: schema operazioni patch Code incompatibile. ' . implode(' | ', $problems));
    }
};
