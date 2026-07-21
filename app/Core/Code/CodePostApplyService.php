<?php

declare(strict_types=1);

namespace App\Core\Code;

use App\Core\Database;

/**
 * Conclude una modifica già applicata senza chiedere un nuovo prompt all'utente.
 * Esegue solo verifiche sintattiche curate e, in un repository Git esistente,
 * prepara una proposta di staging: non inizializza repository e non muta Git.
 */
final class CodePostApplyService
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string,mixed> */
    public function complete(int $workspaceId, int $sessionId, string $operationId, bool $gitEnabled): array
    {
        $operation = (new CodePatchOperationRepository($this->db))
            ->findForScope($operationId, $workspaceId, $sessionId);
        if ($operation === null || (string) $operation['status'] !== 'applied') {
            return ['ok' => false, 'status' => 'not_found', 'message' => 'Modifica applicata non disponibile.'];
        }

        $workspace = (new CodeWorkspaceRepository($this->db))->findById($workspaceId);
        $session = (new CodeSessionRepository($this->db))->findForWorkspace($sessionId, $workspaceId);
        if ($workspace === null || $workspace->status !== 'active' || $session === null) {
            return ['ok' => false, 'status' => 'denied', 'message' => 'Cartella o sessione non disponibile.'];
        }

        $assistantId = (int) ($operation['assistant_conversation_id'] ?? 0);
        if ($assistantId < 1) {
            return ['ok' => false, 'status' => 'denied', 'message' => 'Modifica non associata alla conversazione.'];
        }

        $files = $this->files((string) $operation['files_json']);
        if ($files === []) {
            return ['ok' => false, 'status' => 'denied', 'message' => 'Nessun file applicato disponibile.'];
        }

        $verificationCards = $this->verify($workspace, $workspaceId, $sessionId, $assistantId, $files);
        $git = $this->git($workspace, $workspaceId, $sessionId, $assistantId, $files, $gitEnabled);

        return [
            'ok' => true,
            'status' => 'completed',
            'summary' => $this->summary($files),
            'files' => $files,
            'verifications' => $verificationCards,
            'git' => $git,
        ];
    }

    /** @return list<array{path:string,op:string}> */
    private function files(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $files = [];
        foreach ($decoded as $file) {
            if (!is_array($file) || !is_string($file['path'] ?? null) || !is_string($file['op'] ?? null)) {
                continue;
            }
            try {
                RelativePath::assert($file['path']);
            } catch (\Throwable) {
                continue;
            }
            if (!in_array($file['op'], [CodePatchProposal::OP_CREATE, CodePatchProposal::OP_UPDATE], true)) {
                continue;
            }
            $files[] = ['path' => $file['path'], 'op' => $file['op']];
        }
        return $files;
    }

    /**
     * @param list<array{path:string,op:string}> $files
     * @return list<array{profile:string,kind:string,outcome:string,exit_code:?int,path:?string,label:string}>
     */
    private function verify(CodeWorkspace $workspace, int $workspaceId, int $sessionId, int $assistantId, array $files): array
    {
        $profiles = ['py' => 'py-syntax', 'php' => 'php-lint', 'js' => 'js-syntax', 'mjs' => 'js-syntax', 'cjs' => 'js-syntax'];
        $repository = new CodeVerificationRunRepository($this->db);
        $tool = new CodeVerificationTool();
        $cards = [];

        foreach ($files as $file) {
            $extension = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));
            $profile = $profiles[$extension] ?? null;
            if ($profile === null) {
                continue;
            }
            $existing = $repository->findForAssistantProfilePath($workspaceId, $sessionId, $assistantId, $profile, $file['path']);
            if ($existing !== null) {
                $outcome = (string) $existing['outcome'];
                $exit = $existing['exit_code'] === null ? null : (int) $existing['exit_code'];
                $cards[] = [
                    'profile' => (string) $existing['profile_id'],
                    'kind' => (string) $existing['kind'],
                    'outcome' => $outcome,
                    'exit_code' => $exit,
                    'path' => (string) $existing['rel_path'],
                    'label' => CodeVerificationRunRecord::label($outcome, $exit),
                ];
                continue;
            }

            $result = $tool->run(
                $workspace,
                $profile,
                $file['path'],
                [$file['path']],
                static fn (): bool => false,
                static fn (): float => microtime(true),
                2000,
            );
            if ($result['record'] !== null) {
                $repository->record($workspaceId, $sessionId, $assistantId, $result['record']);
                $cards[] = $result['record']->toCard();
            }
        }
        return $cards;
    }

    /** @param list<array{path:string,op:string}> $files @return array<string,mixed> */
    private function git(CodeWorkspace $workspace, int $workspaceId, int $sessionId, int $assistantId, array $files, bool $enabled): array
    {
        if (!$enabled) {
            return ['status' => 'disabled', 'message' => 'File salvato localmente.'];
        }
        $git = GitService::withDefaults();
        if (!$git->isAvailable()) {
            return ['status' => 'unavailable', 'message' => 'File salvato localmente. Git non è disponibile.'];
        }
        if (!$git->isRepository($workspace)) {
            return ['status' => 'not_repository', 'message' => 'File salvato localmente. Questa cartella non è collegata a Git.'];
        }

        $repository = new GitOperationRepository($this->db);
        $existing = $repository->findStageForAssistant($workspaceId, $sessionId, $assistantId);
        if ($existing !== null) {
            return ['status' => 'proposed', 'message' => 'Puoi scegliere se salvare la modifica in Git.', 'card' => $this->gitCard($existing)];
        }

        $result = (new CodeGitTool($git))->proposeStage($workspace, array_column($files, 'path'), 2000);
        if (!$result['plan'] instanceof GitStagePlan) {
            return ['status' => 'unchanged', 'message' => 'File salvato. Non ci sono modifiche da aggiungere a Git.'];
        }
        $row = $repository->createStage($workspaceId, $sessionId, $assistantId, $result['plan']);
        return ['status' => 'proposed', 'message' => 'Puoi scegliere se salvare la modifica in Git.', 'card' => $this->gitCard($row)];
    }

    /** @return array<string,mixed> */
    private function gitCard(array $row): array
    {
        $plan = json_decode((string) $row['plan_json'], true);
        $selected = is_array($plan) && is_array($plan['selected'] ?? null) ? $plan['selected'] : [];
        return [
            'operation_id' => (string) $row['operation_id'],
            'kind' => 'stage',
            'state' => (string) $row['state'],
            'digest' => (string) $row['digest'],
            'selected' => $selected,
            'suggested_message' => GitStagePlan::suggestedCommitMessage($selected),
        ];
    }

    /** @param list<array{path:string,op:string}> $files */
    private function summary(array $files): string
    {
        if (count($files) === 1) {
            return $files[0]['path'] . ($files[0]['op'] === CodePatchProposal::OP_CREATE ? ' creato.' : ' aggiornato.');
        }
        return count($files) . ' file aggiornati.';
    }
}
