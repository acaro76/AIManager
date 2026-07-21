<?php

use App\Core\Database;

// Code — F0.b. Ricostruisce code_workspaces come tabella AUTONOMA: la cartella È il
// progetto Code, nessun legame con `projects` (via la 030, ora superata: aveva
// project_id + FK verso projects).
//
// Fail-closed: se la tabella legacy contiene righe NON si tocca lo schema (niente
// cancellazioni silenziose). L'eccezione viene lanciata PRIMA del DROP e, dentro la
// transazione atomica del MigrationRunner, lascia tabella e righe originali intatte.
return function (Database $db): void {
    $legacy = $db->fetch("SELECT name FROM sqlite_master WHERE type='table' AND name='code_workspaces'");
    if ($legacy !== null) {
        $count = (int) ($db->fetch('SELECT COUNT(*) AS c FROM code_workspaces')['c'] ?? 0);
        if ($count > 0) {
            throw new \RuntimeException(
                "031: code_workspaces contiene {$count} righe: migrazione manuale richiesta prima di ricostruire lo schema standalone."
            );
        }
        $db->execute('DROP TABLE code_workspaces');
    }

    $db->execute('CREATE TABLE code_workspaces (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        root_path TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL DEFAULT \'\',
        status TEXT NOT NULL DEFAULT \'active\',
        authorized_at TEXT NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        CHECK(status IN (\'active\', \'revoked\'))
    )');
};
