<?php

declare(strict_types=1);

use App\Core\Database;

return function (Database $db): void {
    $keep = 30;
    $states = $db->fetchAll('SELECT id FROM execution_states ORDER BY id ASC');

    foreach ($states as $state) {
        $stateId = (int) ($state['id'] ?? 0);
        if ($stateId <= 0) {
            continue;
        }

        $db->execute(
            'DELETE FROM execution_snapshots
             WHERE execution_state_id = ?
             AND id NOT IN (
                SELECT id
                FROM execution_snapshots
                WHERE execution_state_id = ?
                ORDER BY created_at DESC, id DESC
                LIMIT ?
             )',
            [$stateId, $stateId, $keep]
        );

        $db->execute(
            'DELETE FROM execution_history
             WHERE execution_state_id = ?
             AND id NOT IN (
                SELECT id
                FROM execution_history
                WHERE execution_state_id = ?
                ORDER BY created_at DESC, id DESC
                LIMIT ?
             )',
            [$stateId, $stateId, $keep]
        );
    }
};
