<?php

use App\Core\Code\CodeChatSchema;
use App\Core\Database;

// Fase 2: persistenza dei soli metadati delle evidenze per risposta. La 032 aggiornata crea
// gia' la tabella sui DB nuovi; sui DB esistenti questa migrazione aggiunge esclusivamente la
// quarta tabella. Presenze divergenti falliscono prima di qualunque modifica.
return function (Database $db): void {
    $table = 'code_response_evidence';
    if (CodeChatSchema::tableExists($db, $table)) {
        $problems = CodeChatSchema::verify($db);
        if ($problems !== []) {
            throw new \RuntimeException('033: schema evidenze Code incompatibile. ' . implode(' | ', $problems));
        }
        return;
    }

    foreach (['code_sessions', 'code_conversations', 'code_operation_logs'] as $required) {
        if (!CodeChatSchema::tableExists($db, $required)) {
            throw new \RuntimeException('033: schema chat Code di base incompleto; nessuna modifica applicata.');
        }
    }

    $db->execute(CodeChatSchema::tableDdl()[$table]);
    foreach (CodeChatSchema::indexDdl() as $ddl) {
        if (str_contains($ddl, 'idx_code_response_evidence_')) {
            $db->execute($ddl);
        }
    }

    // Il runner racchiude la migrazione in una transazione: verificare l'intero schema DOPO
    // il DDL permette di rilevare anche divergenze pregresse nelle tre tabelle della Fase 1
    // e di annullare atomicamente la nuova tabella.
    $problems = CodeChatSchema::verify($db);
    if ($problems !== []) {
        throw new \RuntimeException('033: schema chat Code incompatibile. ' . implode(' | ', $problems));
    }
};
