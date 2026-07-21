<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Cancellation\CancellationStore;
use App\Core\Request;
use App\Core\Response;
use App\Models\Project;
use App\Models\ProviderConfig;
use App\Models\Session;
use App\Services\ChatService;

final class ChatController extends BaseController
{
    public function stream(Request $request): never
    {
        $this->guard($request);

        $session = (new Session())->find((int) $request->input('session_id'));
        if (!$session) {
            $this->streamHeaders(404);
            $this->event('error', ['message' => 'Sessione non trovata.']);
            exit;
        }

        $project = (new Project())->find((int) $session['project_id']);
        if (!$project) {
            $this->streamHeaders(404);
            $this->event('error', ['message' => 'Progetto non trovato.']);
            exit;
        }

        if (Project::isArchived($project) || (string) ($session['status'] ?? 'active') === 'archived') {
            $this->streamHeaders(409);
            $this->event('error', ['message' => 'Il progetto o la sessione sono archiviati e disponibili in sola lettura.']);
            exit;
        }

        $provider = strtolower(trim((string) $request->input('provider', $project['provider'] ?? 'auto')));
        $enabledProviders = (new ProviderConfig())->all();
        if ($provider === 'auto' || (isset($enabledProviders[$provider]) && (int) $enabledProviders[$provider]['enabled'] === 1)) {
            $project['provider'] = $provider;
        }

        $prompt = trim((string) $request->input('prompt'));
        if ($prompt === '') {
            $this->streamHeaders(422);
            $this->event('error', ['message' => 'Scrivi un messaggio prima di inviare.']);
            exit;
        }

        $requestId = (string) $request->input('request_id', '');
        $cancellations = $this->cancellations();
        $cancellations->clear($requestId);
        // Pulisci i file di cancellazione stantii (richieste vecchie) e, a fine di QUESTA
        // richiesta (anche su abort/errore), rimuovi il proprio: altrimenti i *.cancel
        // restano su disco per sempre e sporcano il worktree (audit).
        $cancellations->prune();
        if ($requestId !== '') {
            register_shutdown_function(static function () use ($cancellations, $requestId): void {
                $cancellations->clear($requestId);
            });
        }
        $token = $cancellations->token($requestId);

        $this->streamHeaders();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $this->disableOutputBuffering();

        $result = (new ChatService())->stream($project, $session, $prompt, function (string $text, string $channel = 'content'): void {
            if ($channel === 'sources') {
                $decoded = json_decode($text, true);
                $this->event('sources', is_array($decoded) ? $decoded : ['results' => []]);
                return;
            }

            if ($channel === 'image') {
                $decoded = json_decode($text, true);
                $this->event('image', is_array($decoded) ? $decoded : []);
                return;
            }

            if ($channel === 'reset') {
                $this->event('reset', []);
                return;
            }

            $this->event($channel === 'reasoning' ? 'reasoning' : 'delta', ['text' => $text]);
        }, $token, $_FILES);

        $surface = (string) $request->input('surface', 'chat');
        if ($result['ok']) {
            $this->event('done', $result + ['redirect' => $this->chatRedirect($project, $session, $surface)]);
        } else {
            $this->event('error', $result + ['redirect' => $this->chatRedirect($project, $session, $surface)]);
        }
        exit;
    }

    public function stop(Request $request): never
    {
        $this->guard($request);
        $requestId = (string) $request->input('request_id', '');
        if ($requestId !== '') {
            $this->cancellations()->cancel($requestId);
        }

        Response::json(['ok' => true, 'message' => 'Richiesta interrotta.']);
    }

    private function chatRedirect(array $project, array $session, string $surface): string
    {
        if ($surface === 'workspace') {
            return '/workspace/session?id=' . (int) $session['id'];
        }

        if ((int) ($project['is_system'] ?? 0) === 1) {
            return '/chat/free?session_id=' . (int) $session['id'];
        }

        return '/chat?project_id=' . (int) $project['id'] . '&session_id=' . (int) $session['id'] . '&provider=' . rawurlencode((string) ($project['provider'] ?? 'auto'));
    }

    private function cancellations(): CancellationStore
    {
        return new CancellationStore($this->app->config['paths']['storage'] . '/cancellations');
    }

    private function streamHeaders(int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
    }

    private function event(string $event, array $payload): void
    {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
        @ob_flush();
        flush();
    }

    private function disableOutputBuffering(): void
    {
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        ob_implicit_flush(true);
    }
}
