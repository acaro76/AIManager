<?php

use App\Core\Code\CodeVerificationSchema;
use App\Core\Database;

// Fase 5: registro delle verifiche del codice (soli metadati). ADDITIVA: non tocca le cinque
// tabelle Code esistenti (chat + patch) e NON altera la semantica di code_operation_logs; aggiunge
// solo `code_verification_runs`. Richiede lo schema chat di base (FK verso
// code_workspaces/code_sessions/code_conversations). Presenze divergenti falliscono PRIMA di
// qualunque modifica.
return function (Database $db): void {
    $table = CodeVerificationSchema::TABLE;
    if (CodeVerificationSchema::tableExists($db, $table)) {
        $problems = CodeVerificationSchema::verify($db);
        if ($problems !== []) {
            throw new \RuntimeException('035: schema verifiche Code incompatibile. ' . implode(' | ', $problems));
        }
        return;
    }

    foreach (['code_workspaces', 'code_sessions', 'code_conversations'] as $required) {
        if (!CodeVerificationSchema::tableExists($db, $required)) {
            throw new \RuntimeException('035: schema Code di base incompleto; nessuna modifica applicata.');
        }
    }

    CodeVerificationSchema::applyDdl($db);

    // Il runner racchiude la migrazione in una transazione: verificare DOPO il DDL permette di
    // annullare atomicamente la tabella su qualunque divergenza.
    $problems = CodeVerificationSchema::verify($db);
    if ($problems !== []) {
        throw new \RuntimeException('035: schema verifiche Code incompatibile. ' . implode(' | ', $problems));
    }
};
