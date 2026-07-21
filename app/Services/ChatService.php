<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Cancellation\CancellationToken;
use App\Core\Execution\ExecutionStateRepository;
use App\Core\Orchestrator\Orchestrator;
use App\Core\Providers\ProviderIntentFactory;
use App\Models\Conversation;
use App\Models\Session;
use App\Services\AIProviderResult;
use App\Repositories\OrchestratorRepository;

final class ChatService
{
    // Budget in caratteri per il testo degli allegati di chat dopo il recupero mirato.
    // CLOUD = ampio per i provider con finestra grande (~25k token). Per LM Studio si usa
    // il budget REALE della sua finestra (residuo dopo la riserva), senza minimi fissi:
    // un floor sopra la finestra la farebbe sforare (es. 24000 > 22768 su una finestra 8k).
    private const MAX_ATTACHMENT_BUDGET_CLOUD = 100000;

    public function stream(array $project, array $session, string $prompt, callable $onDelta, ?CancellationToken $cancellation = null, array $files = []): array
    {
        $app = App::get();
        $sessionTitle = $this->updateFreeChatTitle($project, $session, $prompt);
        $attachmentService = new ChatAttachmentService($app->config['paths']['storage']);
        // Recupero mirato anche per gli allegati di chat: il file viene spezzato e si tengono
        // solo i pezzi pertinenti alla domanda (come per i documenti di progetto grandi),
        // invece di troncare alle prime pagine. Budget in caratteri per il testo allegato:
        // se la sessione e' pinnata su LM Studio (finestra piccola) usa il budget della sua
        // finestra reale; altrimenti un budget ampio adatto ai provider cloud. In AUTO, se
        // l'allegato e' grande il router evita comunque il locale (penalita' finestra).
        $retriever = LocalDocumentRetriever::fromRoot($app->root);
        $attachmentBudget = strtolower((string) ($project['provider'] ?? 'auto')) === 'lmstudio'
            ? $retriever->budgetChars($cancellation)
            : self::MAX_ATTACHMENT_BUDGET_CLOUD;
        $attachments = $attachmentService->ingest($files, $project, $session, $prompt, $attachmentBudget, $retriever);
        $promptForAi = $prompt . $attachmentService->promptBlock($attachments);
        $intent = ProviderIntentFactory::fromPrompt(
            $promptForAi,
            $attachments !== [],
            ProviderIntentFactory::hasImageAttachments($attachments)
        );

        $orchestrator = new Orchestrator(
            $app,
            new OrchestratorRepository($app->db)
        );

        $orchestratorResult = $orchestrator->handle($prompt, $project, $session, $cancellation, $onDelta, [
            'prompt_for_ai' => $promptForAi,
            'attachments' => $attachments,
            'intent' => $intent,
        ]);

        $result = $orchestratorResult->data['ai_result'] ?? null;

        // Se il messaggio utente non e' stato salvato (es. richiesta interrotta prima del
        // salvataggio), gli allegati ingeriti resterebbero orfani: mai collegati a una
        // conversazione, invisibili nello storico. Li scartiamo (riga + file).
        if ((int) ($orchestratorResult->data['user_conversation_id'] ?? 0) <= 0 && $attachments !== []) {
            $attachmentService->discard($attachments);
            $attachments = [];
        }

        $assistantId = (int) ($orchestratorResult->data['assistant_conversation_id'] ?? 0);

        return [
            'ok' => $orchestratorResult->ok,
            'message' => $orchestratorResult->ok ? 'Risposta generata.' : $orchestratorResult->message,
            'assistant' => $this->assistantMessage($assistantId)
                ?: ($result instanceof AIProviderResult ? $this->assistantFromResult($result) : null),
            'session_title' => $sessionTitle,
            'attachments' => array_map(fn (array $item): array => [
                'id' => (int) $item['id'],
                'name' => (string) $item['name'],
                'extension' => (string) $item['extension'],
                'size' => (int) $item['size'],
            ], $attachments),
        ];
    }

    private function updateFreeChatTitle(array $project, array $session, string $prompt): string
    {
        $currentTitle = (string) ($session['title'] ?? '');
        if ((int) ($project['is_system'] ?? 0) !== 1) {
            return $currentTitle;
        }

        $titles = new ConversationTitleService();
        if (!$titles->isProvisional($currentTitle)) {
            return $currentTitle;
        }

        $title = $titles->fromPrompt($prompt);
        (new Session())->updateTitle((int) $session['id'], $title);

        return $title;
    }

    private function assistantMessage(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $message = (new Conversation())->find($id);
        if (!$message) {
            return null;
        }

        return [
            'id' => (int) $message['id'],
            'role' => (string) $message['role'],
            'content' => (string) $message['content'],
            'provider' => (string) $message['provider'],
            'model' => (string) $message['model'],
            'tokens_input' => (int) $message['tokens_input'],
            'tokens_output' => (int) $message['tokens_output'],
            'created_at' => (string) $message['created_at'],
        ];
    }

    private function assistantFromResult(AIProviderResult $result): ?array
    {
        if (!$result->ok) {
            return null;
        }

        return [
            'id' => 0,
            'role' => 'assistant',
            'content' => $result->content,
            'provider' => $result->provider,
            'model' => $result->model,
            'tokens_input' => $result->tokensInput,
            'tokens_output' => $result->tokensOutput,
            'created_at' => date('c'),
        ];
    }

}
