<?php

use App\Core\Database;

return function (Database $db): void {
    $db->execute('CREATE TABLE IF NOT EXISTS execution_states (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        session_id INTEGER NOT NULL,
        objective TEXT NOT NULL,
        current_state TEXT NOT NULL DEFAULT "active",
        execution_plan_json TEXT NOT NULL DEFAULT "[]",
        completed_tasks_json TEXT NOT NULL DEFAULT "[]",
        remaining_tasks_json TEXT NOT NULL DEFAULT "[]",
        decisions_json TEXT NOT NULL DEFAULT "[]",
        knowledge_json TEXT NOT NULL DEFAULT "[]",
        files_json TEXT NOT NULL DEFAULT "[]",
        documents_json TEXT NOT NULL DEFAULT "[]",
        current_provider TEXT DEFAULT "",
        previous_providers_json TEXT NOT NULL DEFAULT "[]",
        provider_change_reasons_json TEXT NOT NULL DEFAULT "[]",
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        UNIQUE(project_id, session_id),
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
        FOREIGN KEY(session_id) REFERENCES sessions(id) ON DELETE CASCADE
    )');

    $db->execute('CREATE TABLE IF NOT EXISTS execution_snapshots (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        execution_state_id INTEGER NOT NULL,
        summary TEXT NOT NULL,
        snapshot_json TEXT NOT NULL,
        created_at TEXT NOT NULL,
        FOREIGN KEY(execution_state_id) REFERENCES execution_states(id) ON DELETE CASCADE
    )');

    $db->execute('CREATE TABLE IF NOT EXISTS execution_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        execution_state_id INTEGER NOT NULL,
        event_type TEXT NOT NULL,
        event_json TEXT NOT NULL,
        created_at TEXT NOT NULL,
        FOREIGN KEY(execution_state_id) REFERENCES execution_states(id) ON DELETE CASCADE
    )');

    $db->execute('CREATE INDEX IF NOT EXISTS idx_execution_states_project ON execution_states(project_id, session_id)');
    $db->execute('CREATE INDEX IF NOT EXISTS idx_execution_snapshots_state ON execution_snapshots(execution_state_id, created_at)');
    $db->execute('CREATE INDEX IF NOT EXISTS idx_execution_history_state ON execution_history(execution_state_id, created_at)');
};
