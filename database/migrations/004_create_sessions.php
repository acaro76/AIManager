<?php

use App\Core\Database;

return function (Database $db): void {
    $db->execute('CREATE TABLE IF NOT EXISTS sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        description TEXT DEFAULT "",
        status TEXT NOT NULL DEFAULT "active",
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        last_activity TEXT NOT NULL,
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
    )');

    $conversationColumns = $db->fetchAll('PRAGMA table_info(conversations)');
    $conversationColumnNames = array_column($conversationColumns, 'name');
    if (!in_array('session_id', $conversationColumnNames, true)) {
        $db->execute('ALTER TABLE conversations ADD COLUMN session_id INTEGER NULL REFERENCES sessions(id) ON DELETE CASCADE');
    }

    $memoryColumns = $db->fetchAll('PRAGMA table_info(memories)');
    $memoryColumnNames = array_column($memoryColumns, 'name');
    if (!in_array('session_id', $memoryColumnNames, true)) {
        $db->execute('ALTER TABLE memories ADD COLUMN session_id INTEGER NULL REFERENCES sessions(id) ON DELETE SET NULL');
    }

    $now = date('c');
    foreach ($db->fetchAll('SELECT id, name FROM projects') as $project) {
        $session = $db->fetch('SELECT id FROM sessions WHERE project_id = ? ORDER BY id ASC LIMIT 1', [(int) $project['id']]);
        if (!$session) {
            $db->execute(
                'INSERT INTO sessions (project_id, title, description, status, created_at, updated_at, last_activity) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [(int) $project['id'], 'Sessione iniziale', 'Sessione creata automaticamente per il progetto ' . $project['name'] . '.', 'active', $now, $now, $now]
            );
            $sessionId = $db->lastInsertId();
        } else {
            $sessionId = (int) $session['id'];
        }

        $db->execute('UPDATE conversations SET session_id = ? WHERE project_id = ? AND session_id IS NULL', [$sessionId, (int) $project['id']]);
    }

    $db->execute('CREATE INDEX IF NOT EXISTS idx_sessions_project ON sessions(project_id, status, last_activity)');
    $db->execute('CREATE INDEX IF NOT EXISTS idx_conversations_session ON conversations(session_id, created_at)');
    $db->execute('CREATE INDEX IF NOT EXISTS idx_memories_session ON memories(session_id)');
};
