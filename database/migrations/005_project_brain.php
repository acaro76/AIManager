<?php

use App\Core\Database;

return function (Database $db): void {
    $memoryColumns = $db->fetchAll('PRAGMA table_info(memories)');
    $memoryColumnNames = array_column($memoryColumns, 'name');

    $columns = [
        'brain_category' => 'TEXT DEFAULT ""',
        'canonical_key' => 'TEXT DEFAULT ""',
        'confidence' => 'REAL DEFAULT 0',
        'source' => 'TEXT DEFAULT ""',
        'metadata_json' => 'TEXT DEFAULT "{}"',
    ];

    foreach ($columns as $name => $definition) {
        if (!in_array($name, $memoryColumnNames, true)) {
            $db->execute('ALTER TABLE memories ADD COLUMN ' . $name . ' ' . $definition);
        }
    }

    $db->execute('CREATE TABLE IF NOT EXISTS knowledge_relations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        source_memory_id INTEGER NOT NULL,
        target_memory_id INTEGER NOT NULL,
        relation_type TEXT NOT NULL,
        created_at TEXT NOT NULL,
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
        FOREIGN KEY(source_memory_id) REFERENCES memories(id) ON DELETE CASCADE,
        FOREIGN KEY(target_memory_id) REFERENCES memories(id) ON DELETE CASCADE
    )');

    $db->execute('CREATE INDEX IF NOT EXISTS idx_memories_brain_key ON memories(project_id, canonical_key)');
    $db->execute('CREATE INDEX IF NOT EXISTS idx_memories_brain_category ON memories(project_id, brain_category, updated_at)');
    $db->execute('CREATE INDEX IF NOT EXISTS idx_knowledge_relations_source ON knowledge_relations(source_memory_id)');
    $db->execute('CREATE INDEX IF NOT EXISTS idx_knowledge_relations_target ON knowledge_relations(target_memory_id)');
};
