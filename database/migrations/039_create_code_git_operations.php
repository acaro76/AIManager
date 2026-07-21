<?php

use App\Core\Database;

return function (Database $db): void {
    $db->execute(<<<'SQL'
        CREATE TABLE IF NOT EXISTS code_git_operations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            operation_id TEXT NOT NULL UNIQUE,
            workspace_id INTEGER NOT NULL,
            code_session_id INTEGER NOT NULL,
            assistant_conversation_id INTEGER NULL,
            kind TEXT NOT NULL CHECK (kind IN ('stage','commit')),
            state TEXT NOT NULL CHECK (state IN ('pending','running','staged','commit_pending','committed','rejected','denied','stale','expired','error')),
            digest TEXT NOT NULL,
            fingerprint TEXT NOT NULL,
            plan_json TEXT NOT NULL,
            provenance_json TEXT NOT NULL DEFAULT '{}',
            commit_message TEXT NULL,
            parent_operation_id TEXT NULL,
            selected_count INTEGER NOT NULL DEFAULT 0,
            excluded_count INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            confirmed_at TEXT NULL,
            finished_at TEXT NULL,
            FOREIGN KEY (workspace_id) REFERENCES code_workspaces(id) ON DELETE CASCADE,
            FOREIGN KEY (code_session_id) REFERENCES code_sessions(id) ON DELETE CASCADE,
            FOREIGN KEY (assistant_conversation_id) REFERENCES code_conversations(id) ON DELETE SET NULL
        )
    SQL);
    $db->execute('CREATE INDEX IF NOT EXISTS idx_code_git_scope ON code_git_operations (workspace_id, code_session_id)');
    $db->execute('CREATE INDEX IF NOT EXISTS idx_code_git_state ON code_git_operations (state)');
};
