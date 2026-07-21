<?php

use App\Core\Database;

return function (Database $db): void {
    $db->execute('CREATE TABLE IF NOT EXISTS conversations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        role TEXT NOT NULL,
        content TEXT NOT NULL,
        provider TEXT NOT NULL,
        model TEXT NOT NULL,
        tokens_input INTEGER DEFAULT 0,
        tokens_output INTEGER DEFAULT 0,
        created_at TEXT NOT NULL,
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
    )');

    $db->execute('CREATE TABLE IF NOT EXISTS ai_request_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        conversation_id INTEGER NULL,
        provider TEXT NOT NULL,
        model TEXT NOT NULL,
        response_time_ms INTEGER DEFAULT 0,
        tokens_input INTEGER DEFAULT 0,
        tokens_output INTEGER DEFAULT 0,
        error TEXT DEFAULT "",
        created_at TEXT NOT NULL,
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
        FOREIGN KEY(conversation_id) REFERENCES conversations(id) ON DELETE SET NULL
    )');

    $db->execute('CREATE INDEX IF NOT EXISTS idx_conversations_project ON conversations(project_id, created_at)');
    $db->execute('CREATE INDEX IF NOT EXISTS idx_ai_request_logs_project ON ai_request_logs(project_id, created_at)');
};
