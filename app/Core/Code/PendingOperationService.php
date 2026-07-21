<?php

declare(strict_types=1);

namespace App\Core\Code;

use App\Core\Database;

/** Riconcilia le sole proposte ancora in attesa quando AIManager viene arrestato. */
final class PendingOperationService
{
    public function __construct(
        private readonly Database $db,
        private readonly string $storageRoot,
    ) {
    }

    public function countAll(): int
    {
        return count($this->all());
    }

    /** @return array{cancelled:int,failures:int} */
    public function cancelAll(): array
    {
        $cancelled = 0;
        $failures = 0;

        foreach ($this->all() as $operation) {
            try {
                $ok = match ($operation['type']) {
                    'patch' => (new CodePatchMutationService($this->db, $this->storageRoot . '/code_patches'))
                        ->reject($operation['workspace_id'], $operation['session_id'], $operation['id'])['status'] === 'rejected',
                    'command' => (new CommandConfirmService(
                        $this->db,
                        $this->storageRoot . '/code_commands',
                        $this->storageRoot . '/code_runtime',
                    ))->reject($operation['workspace_id'], $operation['session_id'], $operation['id'])['status'] === 'rejected',
                    'process' => (new ProcessConfirmService($this->db, $this->storageRoot . '/code_process_runtime'))
                        ->reject($operation['workspace_id'], $operation['session_id'], $operation['id'])['status'] === 'rejected',
                    'git' => (new GitOperationRepository($this->db))
                        ->reject($operation['id'], $operation['workspace_id'], $operation['session_id']),
                    default => false,
                };
            } catch (\Throwable) {
                $ok = false;
            }

            $ok ? $cancelled++ : $failures++;
        }

        return ['cancelled' => $cancelled, 'failures' => $failures];
    }

    /**
     * @return list<array{type:string,id:string,workspace_id:int,session_id:int}>
     */
    private function all(): array
    {
        $sources = [
            ['code_patch_operations', 'patch', 'operation_id', "status = 'proposed'"],
            ['code_command_runs', 'command', 'command_id', "state = 'pending'"],
            ['code_processes', 'process', 'process_id', "state = 'pending'"],
            ['code_git_operations', 'git', 'operation_id', "state IN ('pending', 'commit_pending')"],
        ];
        $operations = [];

        foreach ($sources as [$table, $type, $idColumn, $where]) {
            if (!$this->tableExists($table)) {
                continue;
            }
            foreach ($this->db->fetchAll(
                "SELECT {$idColumn} AS operation_id, workspace_id, code_session_id FROM {$table} WHERE {$where}"
            ) as $row) {
                $operations[] = [
                    'type' => $type,
                    'id' => (string) $row['operation_id'],
                    'workspace_id' => (int) $row['workspace_id'],
                    'session_id' => (int) $row['code_session_id'],
                ];
            }
        }

        return $operations;
    }

    private function tableExists(string $table): bool
    {
        return $this->db->fetch(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table]
        ) !== null;
    }
}
