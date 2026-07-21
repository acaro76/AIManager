<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Execution\ExecutionStateRepository;
use App\Models\AIRequestLog;
use App\Models\ChatAttachment;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\Project;
use App\Models\ProviderConfig;
use App\Models\Session;

final class WorkspaceController extends BaseController
{
    public function show(Request $request): void
    {
        [$project, $activeSession] = $this->resolveProjectAndSession($request);
        if (!$project) {
            Response::redirect('/projects');
        }

        $sessionModel = new Session();
        if (!$activeSession && !Project::isArchived($project)) {
            $sessionId = $sessionModel->create([
                'project_id' => (int) $project['id'],
                'title' => 'Sessione iniziale',
                'description' => '',
            ]);
            $activeSession = $sessionModel->find($sessionId);
        }

        $stateRepo = new ExecutionStateRepository($this->app->db);
        $executionState = $activeSession ? $stateRepo->findForSession((int) $activeSession['id']) : null;
        $messages = $activeSession ? (new Conversation())->forSession((int) $activeSession['id'], 120) : [];
        $assistantIds = array_column(array_filter($messages, fn (array $row): bool => $row['role'] === 'assistant'), 'id');
        $latestProviderLog = (new AIRequestLog())->latestForConversationIds($assistantIds);
        $this->view('workspace/show', [
            'title' => $project['name'] . ' Workspace',
            'hideTopbar' => true,
            'project' => $project,
            'sessions' => $sessionModel->forProject((int) $project['id']),
            'activeSession' => $activeSession,
            'messages' => $this->withAttachments($messages),
            'memories' => (new Memory())->forProject((int) $project['id'], 20),
            'executionState' => $executionState,
            'providerConfigs' => (new ProviderConfig())->all(),
            'latestProviderLog' => $latestProviderLog,
        ]);
    }

    public function exportExecutionState(Request $request): never
    {
        $session = (new Session())->find((int) $request->input('session_id'));
        if (!$session) {
            Response::json(['ok' => false, 'message' => 'Sessione non trovata.'], 404);
        }

        $state = (new ExecutionStateRepository($this->app->db))->findForSession((int) $session['id']);
        if (!$state) {
            Response::json(['ok' => false, 'message' => 'Execution State non ancora disponibile.'], 404);
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="execution-state-session-' . (int) $session['id'] . '.json"');
        echo json_encode([
            'id' => $state->id,
            'project_id' => $state->projectId,
            'session_id' => $state->sessionId,
            'objective' => $state->objective,
            'current_state' => $state->currentState,
            'execution_plan' => $state->executionPlan,
            'completed_tasks' => $state->completedTasks,
            'remaining_tasks' => $state->remainingTasks,
            'current_provider' => $state->currentProvider,
            'previous_providers' => $state->previousProviders,
            'provider_change_reasons' => $state->providerChangeReasons,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function resolveProjectAndSession(Request $request): array
    {
        $session = null;
        $sessionId = (int) $request->input('session_id', 0);
        if ($request->path === '/workspace/session') {
            $sessionId = (int) $request->input('id', $sessionId);
        }

        if ($sessionId > 0) {
            $session = (new Session())->find($sessionId);
        }

        $projectId = $session ? (int) $session['project_id'] : (int) $request->input('id');
        $project = (new Project())->find($projectId);
        if (!$project) {
            return [null, null];
        }

        if (!$session) {
            $session = (new Session())->firstForProject((int) $project['id']);
        }

        return [$project, $session];
    }

    private function withAttachments(array $messages): array
    {
        $grouped = (new ChatAttachment())->groupedForConversationIds(array_column($messages, 'id'));
        foreach ($messages as &$message) {
            $message['attachments'] = $grouped[(int) $message['id']] ?? [];
        }
        unset($message);

        return $messages;
    }

}
