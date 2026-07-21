<?php

use App\Core\Code\CodeChatSchema;
use App\Core\Database;

// Code — Fase 1 / F1.7. Crea le tabelle dedicate della chat Code: code_sessions,
// code_conversations, code_operation_logs. Nessun project_id, nessuna FK verso le tabelle LLM
// (projects/sessions/conversations/execution_states): lo schema è isolato per costruzione.
//
// Il DDL e la verifica strutturale vivono in CodeChatSchema (fonte di verità unica, già
// coperta da test). Qui si decide SOLO se applicarlo, in base allo stato rilevato:
//
//   missing      → applica il DDL (caso normale: prima applicazione)
//   ready        → NO-OP idempotente (tabelle già presenti e strutturalmente conformi)
//   incompatible → ECCEZIONE prima di qualunque DDL: presenza parziale o schema divergente.
//                  Nessuna riparazione automatica e nessuna operazione distruttiva (non si
//                  rimuovono tabelle né righe): la migrazione non tocca i dati e richiede un
//                  intervento manuale consapevole.
//
// Si usa applyDdl(), che NON apre transazioni: il MigrationRunner esegue già dentro una
// transazione atomica e SQLite/PDO rifiuterebbe una transazione annidata. Il wrapper
// transazionale di CodeChatSchema è riservato ai test standalone e qui non va usato.
return function (Database $db): void {
    $state = CodeChatSchema::state($db);

    if ($state === CodeChatSchema::STATE_READY) {
        return; // già applicata: niente da fare
    }

    if ($state === CodeChatSchema::STATE_INCOMPATIBLE) {
        $problems = CodeChatSchema::verify($db);
        throw new \RuntimeException(
            '032: schema chat Code incompatibile (tabelle omonime presenti ma divergenti o parziali). '
            . 'Nessuna modifica applicata; richiede migrazione manuale. Problemi rilevati: '
            . implode(' | ', $problems)
        );
    }

    // STATE_MISSING: nessuna delle tabelle esiste → creazione pulita.
    CodeChatSchema::applyDdl($db);
};
