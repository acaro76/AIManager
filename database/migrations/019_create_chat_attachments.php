<?php

use App\Core\Database;

return function (Database $db): void {
    $db->execute('CREATE TABLE IF NOT EXISTS chat_attachments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        session_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        path TEXT NOT NULL,
        extension TEXT NOT NULL DEFAULT "",
        size INTEGER NOT NULL DEFAULT 0,
        mime TEXT NOT NULL DEFAULT "",
        extracted_text TEXT NOT NULL DEFAULT "",
        is_image INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
        FOREIGN KEY(session_id) REFERENCES sessions(id) ON DELETE CASCADE
    )');

    $db->execute('CREATE INDEX IF NOT EXISTS idx_chat_attachments_project ON chat_attachments(project_id, created_at)');
    $db->execute('CREATE INDEX IF NOT EXISTS idx_chat_attachments_session ON chat_attachments(session_id, created_at)');

    $legacy = $db->fetchAll('SELECT * FROM memories WHERE source = ?', ['chat_attachment']);
    foreach ($legacy as $row) {
        $metadata = json_decode((string) ($row['metadata_json'] ?? '{}'), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $path = (string) ($metadata['path'] ?? '');
        if ($path === '') {
            continue;
        }

        $mime = (string) ($metadata['mime'] ?? '');
        $content = (string) ($row['content'] ?? '');
        if (str_contains($content, 'non testuale o non estraibile')) {
            $content = '';
        }

        $exists = $db->fetch(
            'SELECT id FROM chat_attachments WHERE project_id = ? AND session_id = ? AND path = ? LIMIT 1',
            [(int) $row['project_id'], (int) $row['session_id'], $path]
        );
        if (!$exists) {
            $db->execute(
                'INSERT INTO chat_attachments (
                    project_id, session_id, name, path, extension, size, mime,
                    extracted_text, is_image, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $row['project_id'],
                    (int) $row['session_id'],
                    (string) ($metadata['name'] ?? $row['title']),
                    $path,
                    (string) ($metadata['extension'] ?? ''),
                    (int) ($metadata['size'] ?? 0),
                    $mime,
                    $content,
                    str_starts_with($mime, 'image/') ? 1 : 0,
                    (string) ($row['created_at'] ?? date('c')),
                ]
            );
        }
    }

    $db->execute('DELETE FROM memories WHERE source = ?', ['chat_attachment']);
};
