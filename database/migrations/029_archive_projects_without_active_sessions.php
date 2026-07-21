<?php

use App\Core\Database;

return function (Database $db): void {
    // Riallinea il vecchio flusso, che poteva archiviare l'ultima sessione lasciando
    // il progetto attivo. Sono esclusi sia i progetti senza sessioni sia quelli che
    // conservano almeno una sessione operativa.
    $db->execute(
        'UPDATE projects
         SET status = "archived", updated_at = ?
         WHERE status = "active"
           AND is_system = 0
           AND EXISTS (SELECT 1 FROM sessions WHERE sessions.project_id = projects.id)
           AND NOT EXISTS (
               SELECT 1 FROM sessions
               WHERE sessions.project_id = projects.id AND sessions.status = "active"
           )',
        [date('c')]
    );
};
