<?php

use App\Core\Database;

return function (Database $db): void {
    $columns = $db->fetchAll('PRAGMA table_info(memories)');
    $names = array_column($columns, 'name');

    if (!in_array('memory_type', $names, true)) {
        $db->execute('ALTER TABLE memories ADD COLUMN memory_type TEXT NOT NULL DEFAULT "note"');
    }

    $firstProject = $db->fetch('SELECT id FROM projects ORDER BY id ASC LIMIT 1');
    if ($firstProject) {
        $db->execute('UPDATE memories SET project_id = ? WHERE project_id IS NULL', [(int) $firstProject['id']]);
    }

    $db->execute('CREATE INDEX IF NOT EXISTS idx_memories_type ON memories(memory_type)');
};
