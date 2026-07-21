<?php

use App\Core\Database;

return function (Database $db): void {
    // Il ciclo di vita supportato ha due soli stati. Eventuali installazioni storiche
    // con "paused" tornano operative anziche sparire sia dagli attivi sia dagli archiviati.
    $db->execute('UPDATE projects SET status = "active", updated_at = ? WHERE status = "paused" AND is_system = 0', [date('c')]);
};
