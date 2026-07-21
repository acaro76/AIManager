<?php

use App\Core\Database;

return function (Database $db): void {
    $db->execute('CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');

    $db->execute('CREATE TABLE IF NOT EXISTS projects (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        description TEXT DEFAULT "",
        status TEXT NOT NULL DEFAULT "active",
        provider TEXT NOT NULL DEFAULT "",
        system_prompt TEXT DEFAULT "",
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');

    $db->execute('CREATE TABLE IF NOT EXISTS memories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NULL,
        title TEXT NOT NULL,
        content TEXT NOT NULL,
        tags TEXT DEFAULT "",
        importance INTEGER NOT NULL DEFAULT 3,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE SET NULL
    )');

    $db->execute('CREATE TABLE IF NOT EXISTS provider_configs (
        provider TEXT PRIMARY KEY,
        label TEXT NOT NULL,
        base_url TEXT NOT NULL,
        model TEXT NOT NULL,
        enabled INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT NOT NULL
    )');

    $db->execute('CREATE TABLE IF NOT EXISTS plugins (
        slug TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        version TEXT NOT NULL,
        description TEXT DEFAULT "",
        enabled INTEGER NOT NULL DEFAULT 0,
        path TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');

    $db->execute('CREATE INDEX IF NOT EXISTS idx_memories_project ON memories(project_id)');
    $db->execute('CREATE INDEX IF NOT EXISTS idx_projects_status ON projects(status)');
};
