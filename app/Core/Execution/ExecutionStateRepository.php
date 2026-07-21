<?php

declare(strict_types=1);

namespace App\Core\Execution;

use App\Core\Database;

final class ExecutionStateRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findOrCreate(array $project, array $session, string $objective): ExecutionState
    {
        $row = $this->db->fetch('SELECT * FROM execution_states WHERE project_id = ? AND session_id = ?', [(int) $project['id'], (int) $session['id']]);
        if ($row) {
            $state = ExecutionState::fromRow($row);
            if ($state->objective === '' && trim($objective) !== '') {
                $state->objective = trim($objective);
                $this->save($state);
            }
            return $state;
        }

        $now = date('c');
        $this->db->execute(
            'INSERT INTO execution_states (project_id, session_id, objective, current_state, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [(int) $project['id'], (int) $session['id'], trim($objective), 'active', $now, $now]
        );

        return ExecutionState::fromRow($this->db->fetch('SELECT * FROM execution_states WHERE id = ?', [$this->db->lastInsertId()]));
    }

    public function findForSession(int $sessionId): ?ExecutionState
    {
        $row = $this->db->fetch('SELECT * FROM execution_states WHERE session_id = ?', [$sessionId]);
        return $row ? ExecutionState::fromRow($row) : null;
    }

    public function save(ExecutionState $state): void
    {
        $data = $state->toPersistence();
        $this->db->execute(
            'UPDATE execution_states SET objective = ?, current_state = ?, execution_plan_json = ?, completed_tasks_json = ?, remaining_tasks_json = ?, current_provider = ?, previous_providers_json = ?, provider_change_reasons_json = ?, updated_at = ? WHERE id = ?',
            [$data['objective'], $data['current_state'], $data['execution_plan_json'], $data['completed_tasks_json'], $data['remaining_tasks_json'], $data['current_provider'], $data['previous_providers_json'], $data['provider_change_reasons_json'], date('c'), (int) $state->id]
        );
    }

}
