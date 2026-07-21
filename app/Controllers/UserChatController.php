<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\AIRequestLog;
use App\Models\ChatAttachment;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\ProviderConfig;
use App\Models\Session;

final class UserChatController extends BaseController
{
    public function index(Request $request): void
    {
        $projectId = (int) $request->input('project_id', 0);
        if ($projectId === 0) {
            Response::redirect('/choose-project');
        }

        $project = (new Project())->find($projectId);
        if (!$project || (int) ($project['is_system'] ?? 0) === 1) {
            Response::redirect('/choose-project');
        }
        if (Project::isArchived($project)) {
            Response::redirect('/projects/show?id=' . (int) $project['id']);
        }

        $this->renderChat($project, $request);
    }

    public function chooseProject(Request $request): void
    {
        $this->view('user/projects', [
            'title' => 'Scegli un progetto',
            'projects' => (new Project())->active(),
        ]);
    }

    public function free(Request $request): void
    {
        $this->renderChat((new Project())->genericChatProject(), $request, true);
    }

    private function renderChat(array $project, Request $request, bool $isFreeChat = false): void
    {
        $sessionModel = new Session();
        $conversationModel = new Conversation();
        $sessions = $sessionModel->forProject((int) $project['id']);
        $forceNew = $isFreeChat && (string) $request->input('new', '') === '1';
        $activeSession = $forceNew ? null : $sessionModel->find((int) $request->input('session_id'));
        if (!$activeSession || (int) $activeSession['project_id'] !== (int) $project['id']) {
            $activeSession = $forceNew ? null : ($sessions[0] ?? null);
        }

        // "Nuova chat": riusa l'ultima sessione se e' gia' vuota, cosi' non si accumulano
        // conversazioni vuote a ogni click; altrimenti se ne crea una nuova sotto.
        if ($forceNew) {
            $candidate = $sessions[0] ?? null;
            if ($candidate && $conversationModel->forSession((int) $candidate['id'], 1) === []) {
                $activeSession = $candidate;
            }
        }

        if (!$activeSession) {
            $sessionId = $sessionModel->create([
                'project_id' => (int) $project['id'],
                'title' => $isFreeChat ? 'Nuova conversazione' : 'Conversazione iniziale',
            ]);
            $activeSession = $sessionModel->find($sessionId);
            $sessions = $sessionModel->forProject((int) $project['id']);
        }

        $providers = array_filter((new ProviderConfig())->all(), fn (array $provider): bool => (int) $provider['enabled'] === 1);
        $selectedProvider = strtolower((string) $request->input('provider', $project['provider'] ?: 'auto'));
        if ($selectedProvider !== 'auto' && !isset($providers[$selectedProvider])) {
            $selectedProvider = 'auto';
        }

        $messages = $conversationModel->forSession((int) $activeSession['id'], 120);

        $this->view('user/chat', [
            'title' => 'Chat',
            'project' => $project,
            'sessions' => $sessions,
            'activeSession' => $activeSession,
            'messages' => $this->withAttachments($messages),
            'providers' => $providers,
            'providerConfigs' => (new ProviderConfig())->all(),
            'latestProviderLog' => (new AIRequestLog())->latestForSession((int) $activeSession['id']),
            'selectedProvider' => $selectedProvider,
            'isFreeChat' => $isFreeChat,
        ]);
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
