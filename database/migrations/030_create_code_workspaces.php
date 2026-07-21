<?php

use App\Core\Database;

// Reparto Code — Fase 0 / F0.1: tabella dei workspace di codice.
// Un workspace = una cartella autorizzata per un progetto (una root per progetto:
// project_id UNIQUE). La revoca usa status='revoked' (mai DELETE); la riga sparisce
// solo con l'eliminazione definitiva del progetto (ON DELETE CASCADE).
return function (Database $db): void {
    $db->execute('CREATE TABLE IF NOT EXISTS code_workspaces (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL UNIQUE,
        root_path TEXT NOT NULL,
        name TEXT NOT NULL DEFAULT \'\',
        status TEXT NOT NULL DEFAULT \'active\',
        authorized_at TEXT NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        CHECK(status IN (\'active\', \'revoked\')),
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
    )');
};
