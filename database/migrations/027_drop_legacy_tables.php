<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Drop delle tabelle legacy senza lato applicativo (audit 2026-07-09).
 *
 * - provider_states / provider_state_scans: stato provider letto via OCR-screenshot,
 *   sottosistema rimosso (sez. 49.3). Tabelle vuote, referenziate solo da migrazioni.
 * - knowledge_relations: creata da 005_project_brain ma mai usata da codice runtime.
 * - execution_snapshots / execution_history: storico tecnico scrivi-e-mai-letto; i writer
 *   sono stati rimossi (ExecutionSnapshot, ExecutionHistory). La continuita' verso l'AI
 *   viene dallo stato vivo execution_states, non da queste.
 *
 * DROP IF EXISTS: idempotente e sicuro anche se una tabella e' gia' assente.
 */
return function (Database $db): void {
    foreach ([
        'provider_states',
        'provider_state_scans',
        'knowledge_relations',
        'execution_snapshots',
        'execution_history',
    ] as $table) {
        $db->execute('DROP TABLE IF EXISTS ' . $table);
    }
};
