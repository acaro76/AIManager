<?php

declare(strict_types=1);

namespace App\Core\Orchestrator;

use App\Core\App;
use App\Core\Cancellation\CancellationToken;
use App\Core\ContextEngine\Context;
use App\Core\ContextEngine\ContextInterface;
use App\Core\ContextEngine\ContextItem;
use App\Core\Execution\ExecutionStateRepository;
use App\Core\Providers\ImageProviderManager;
use App\Core\Providers\ProviderIntent;
use App\Core\Providers\ProviderIntentFactory;
use App\Core\Providers\ProviderManager;
use App\Core\Providers\ProviderRequest;
use App\Models\Conversation;
use App\Services\AIProviderResult;
use App\Services\ChatAttachmentService;
use App\Services\LocalDocumentRetriever;
use App\Services\WebSearchIntentService;
use App\Services\WebSearchService;

final class Orchestrator
{
    public function __construct(
        private readonly App $app,
        private readonly OrchestratorStoreInterface $store,
    ) {
    }

    public function plan(string $userRequest, array $project, array $session): ExecutionPlan
    {
        $needsAi = $this->needsAi($userRequest);

        $plan = (new ExecutionPlan())
            ->add(new ExecutionStep(1, 'context.retrieve', 'Recupera Context'))
            ->add(new ExecutionStep(2, 'web.search', 'Cerca sul web'))
            ->add(new ExecutionStep(3, 'knowledge.consult', 'Consulta Knowledge'))
            ->add(new ExecutionStep(4, 'image.generate', 'Genera immagine'))
            ->add(new ExecutionStep(5, 'ai.complete', 'Interroga AI Provider', true, ['needs_ai' => $needsAi]))
            ->add(new ExecutionStep(6, 'brain.prepare', 'Prepara Project Brain'))
            ->add(new ExecutionStep(7, 'conversation.save', 'Salva conversazione e log'))
            ->add(new ExecutionStep(8, 'response.build', 'Rispondi'));

        $pluginPlans = $this->app->plugins->hook('orchestrator.plan', [
            'request' => $userRequest,
            'project' => $project,
            'session' => $session,
            'plan' => $plan,
        ]);

        foreach ($pluginPlans as $pluginPlan) {
            if ($pluginPlan instanceof ExecutionPlan) {
                return $pluginPlan;
            }
        }

        return $plan;
    }

    public function handle(string $userRequest, array $project, array $session, ?CancellationToken $cancellation = null, ?callable $onDelta = null, array $extras = []): OrchestratorResult
    {
        $plan = $this->plan($userRequest, $project, $session);
        $stateRepo = new ExecutionStateRepository($this->app->db);
        $executionState = $stateRepo->findOrCreate($project, $session, $userRequest);
        $executionState->executionPlan = $plan->toArray();
        $executionState->remainingTasks = array_map(fn (ExecutionStep $step): string => $step->label, $plan->steps());
        $stateRepo->save($executionState);

        $state = [
            'request' => $userRequest,
            'prompt_for_ai' => (string) ($extras['prompt_for_ai'] ?? $userRequest),
            'attachments' => is_array($extras['attachments'] ?? null) ? $extras['attachments'] : [],
            'intent' => ($extras['intent'] ?? null) instanceof ProviderIntent ? $extras['intent'] : null,
            'on_delta' => is_callable($onDelta) ? $onDelta : null,
            'project' => $project,
            'session' => $session,
            'context' => null,
            'route' => ['action' => 'chat', 'web_query' => '', 'image_prompt' => '', 'prompt_for_ai' => ''],
            'generated_image' => null,
            'sticky_provider' => '',
            'execution_state' => $executionState,
            'execution_repo' => $stateRepo,
            'provider_mode' => (string) ($project['provider'] ?? 'auto'),
            'ai_result' => null,
            'tool_results' => [],
            'response_time_ms' => 0,
            'user_conversation_id' => null,
            'assistant_conversation_id' => null,
            'final_message' => '',
            'fatal_error' => '',
            'cancellation' => $cancellation,
        ];

        foreach ($plan->steps() as $step) {
            $started = $step->start();
            if ($this->isCancelled($state) && !in_array($step->type, ['conversation.save', 'response.build'], true)) {
                $state['ai_result'] = AIProviderResult::failure('Richiesta interrotta.', [], ['choice_reason' => 'cancelled_by_user']);
                $state['final_message'] = 'Richiesta interrotta.';
                $step->skip($started, ['reason' => 'Richiesta interrotta']);
                continue;
            }

            $pluginResult = $this->runPluginStep($step, $state);
            if ($pluginResult !== null) {
                $state = array_merge($state, $pluginResult['state'] ?? []);
                $this->finishPluginStep($step, $started, $pluginResult);
                if ($step->status === ExecutionStep::FAILED && !in_array($step->type, ['ai.complete', 'image.generate'], true)) {
                    break;
                }
                continue;
            }

            try {
                $state = $this->runBuiltInStep($step, $state, $started);
            } catch (\Throwable $exception) {
                $step->fail($started, $exception->getMessage());
                $state['fatal_error'] = $exception->getMessage();
                break;
            }

            if ($step->status === ExecutionStep::FAILED && !in_array($step->type, ['ai.complete', 'image.generate'], true)) {
                if ($state['fatal_error'] === '') {
                    $state['fatal_error'] = (string) (end($step->errors) ?: ('Passaggio "' . $step->label . '" non riuscito.'));
                }
                break;
            }
        }

        $ok = !$plan->hasFailures() && (($state['ai_result'] instanceof AIProviderResult && $state['ai_result']->ok) || $state['final_message'] !== '');
        $message = $ok
            ? ($state['final_message'] ?: 'Richiesta completata.')
            : $this->failureMessage($state);

        return new OrchestratorResult($ok, $message, $plan, [
            'assistant_conversation_id' => $state['assistant_conversation_id'],
            'user_conversation_id' => $state['user_conversation_id'],
            'ai_result' => $state['ai_result'] instanceof AIProviderResult ? $state['ai_result'] : null,
        ]);
    }

    /**
     * Messaggio quando la richiesta NON e' riuscita: l'errore reale (eccezione o step
     * fallito, altrimenti l'errore del provider AI), mai il fallback positivo
     * "Richiesta completata.". Il dettaglio tecnico resta comunque nel piano/log.
     */
    private function failureMessage(array $state): string
    {
        if ((string) ($state['fatal_error'] ?? '') !== '') {
            return (string) $state['fatal_error'];
        }
        if (($state['ai_result'] ?? null) instanceof AIProviderResult && $state['ai_result']->error !== '') {
            return (string) $state['ai_result']->error;
        }
        return 'La richiesta non e\' stata completata.';
    }

    private function runBuiltInStep(ExecutionStep $step, array $state, float $started): array
    {
        return match ($step->type) {
            'context.retrieve' => $this->retrieveContext($step, $state, $started),
            'web.search' => $this->searchWeb($step, $state, $started),
            'knowledge.consult' => $this->consultKnowledge($step, $state, $started),
            'image.generate' => $this->generateImage($step, $state, $started),
            'ai.complete' => $this->completeWithAi($step, $state, $started),
            'brain.prepare' => $this->prepareBrain($step, $state, $started),
            'conversation.save' => $this->saveConversation($step, $state, $started),
            'response.build' => $this->buildResponse($step, $state, $started),
            default => $this->skipUnknown($step, $state, $started),
        };
    }

    private function retrieveContext(ExecutionStep $step, array $state, float $started): array
    {
        // Storico conversazionale: serve sia per la memoria della chat (turni precedenti
        // passati al modello come messaggi) sia per il pin del provider di sessione.
        $history = $this->loadHistory($state['session']);
        $state['sticky_provider'] = $this->lastAssistantProvider($history);

        // Il contesto usa la richiesta RAW, non prompt_for_ai: il blocco allegati sta gia' nel
        // messaggio utente (prompt_for_ai). Passarlo qui lo duplicherebbe nel contesto via
        // compactPrompt("Richiesta corrente") -> token/finestra raddoppiati sui documenti (audit).
        $contextRequest = (string) $state['request'];
        $state['context'] = $this->isGenericChat($state['project'])
            ? new Context($state['project'], $contextRequest, [], $state['session'], null, $this->toHistoryMessages($history))
            : $this->app->context->build($state['project'], $contextRequest, $state['session'], $state['execution_state']);
        $state['context'] = $this->fitLocalResidentKnowledge($state);
        // Router di intento (chat|web|image), calcolato una volta qui e riusato dagli step
        // web.search / image.generate / ai.complete (handoff sez. 44 + 46).
        $state['route'] = $this->resolveRoute($state);
        if (empty($state['user_conversation_id'])) {
            $state['user_conversation_id'] = $this->store->saveUserMessage($state, (string) ($state['provider_mode'] ?? 'auto'), '');
            $this->store->attachUserMessageAttachments($state, (int) $state['user_conversation_id']);
            $this->store->touchSession((int) $state['session']['id']);
        }
        $this->completeExecutionTask($state, $step->label);
        $step->complete($started, ['items' => count($state['context']->items())]);
        return $state;
    }

    /**
     * Ultimi turni della sessione (piu' vecchi -> piu' recenti), col messaggio utente
     * corrente ancora NON salvato: quindi e' solo cronologia precedente.
     */
    private function loadHistory(array $session): array
    {
        $sessionId = (int) ($session['id'] ?? 0);
        if ($sessionId <= 0) {
            return [];
        }

        return (new Conversation())->forSession($sessionId, 12);
    }

    /**
     * Ultimo messaggio utente PRECEDENTE (escluso quello corrente, gia' salvato nello step
     * context.retrieve). Serve a dare un soggetto ai follow-up tipo "cerca su internet".
     */
    private function previousUserMessage(array $state): string
    {
        $current = trim((string) $state['request']);
        foreach (array_reverse($this->loadHistory($state['session'])) as $row) {
            if (($row['role'] ?? '') !== 'user') {
                continue;
            }
            $content = trim((string) ($row['content'] ?? ''));
            if ($content !== '' && $content !== $current) {
                return $content;
            }
        }

        return '';
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function toHistoryMessages(array $rows): array
    {
        $messages = [];
        foreach ($rows as $row) {
            $content = trim((string) ($row['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $messages[] = [
                'role' => ($row['role'] ?? '') === 'assistant' ? 'assistant' : 'user',
                'content' => $content,
            ];
        }

        return $messages;
    }

    /**
     * Provider dell'ultima risposta assistant della sessione: e' il "pin" per non
     * saltare provider a meta' conversazione. Vuoto se nuova chat o provider ignoto.
     */
    private function lastAssistantProvider(array $rows): string
    {
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            if (($rows[$i]['role'] ?? '') !== 'assistant') {
                continue;
            }
            $provider = strtolower(trim((string) ($rows[$i]['provider'] ?? '')));
            return ($provider === '' || $provider === 'auto') ? '' : $provider;
        }

        return '';
    }

    private function searchWeb(ExecutionStep $step, array $state, float $started): array
    {
        $route = is_array($state['route'] ?? null) ? $state['route'] : [];
        if (($route['action'] ?? 'chat') !== 'web') {
            $step->skip($started, ['reason' => 'Ricerca web non richiesta']);
            return $state;
        }

        $service = WebSearchService::fromRoot($this->app->root);
        if (!$service->enabled()) {
            $step->skip($started, ['reason' => 'Ricerca web disabilitata']);
            return $state;
        }

        if ($this->isCancelled($state)) {
            $step->skip($started, ['reason' => 'Richiesta interrotta']);
            return $state;
        }

        $outcome = $service->search((string) ($route['web_query'] ?? ''), $state['cancellation'] ?? null);
        if ($this->isCancelled($state)) {
            $step->skip($started, ['reason' => 'Richiesta interrotta']);
            return $state;
        }
        if (empty($outcome['ok']) || empty($outcome['results'])) {
            $error = trim((string) ($outcome['error'] ?? ''));
            if ($error === '') {
                $error = 'La ricerca non ha restituito risultati.';
            }

            // Il soft fallback resta: la chat puo' rispondere, ma il modello deve sapere
            // che NON ha ottenuto fonti aggiornate e dichiararlo invece di sembrare grounded.
            if ($state['context'] && method_exists($state['context'], 'withAdditionalItems')) {
                $state['context'] = $state['context']->withAdditionalItems([
                    new ContextItem(
                        source: 'web',
                        type: 'web_status',
                        title: 'Ricerca web non riuscita',
                        content: 'La ricerca web richiesta non ha restituito fonti utilizzabili: ' . $error . ' Dillo chiaramente all\'utente e non presentare informazioni come verificate online.',
                        priority: 150,
                        metadata: ['provider' => (string) ($outcome['provider'] ?? ''), 'error' => $error],
                    ),
                ]);
            }

            if ((string) ($route['prompt_for_ai'] ?? '') !== '') {
                $state['prompt_for_ai'] = (string) $route['prompt_for_ai'];
            }

            $onDelta = $state['on_delta'] ?? null;
            if (is_callable($onDelta)) {
                $payload = json_encode([
                    'query' => (string) ($outcome['query'] ?? $route['web_query'] ?? ''),
                    'provider' => (string) ($outcome['provider'] ?? ''),
                    'results' => [],
                    'error' => $error,
                ], JSON_UNESCAPED_UNICODE);
                if ($payload !== false) {
                    $onDelta($payload, 'sources');
                }
            }

            $step->skip($started, [
                'reason' => 'Nessun risultato web',
                'provider' => (string) ($outcome['provider'] ?? ''),
                'error' => $error,
            ]);
            return $state;
        }

        $items = $service->toContextItems($outcome['results']);
        if ($state['context'] && method_exists($state['context'], 'withAdditionalItems')) {
            $state['context'] = $state['context']->withAdditionalItems($items);
        }

        // Follow-up in cui il soggetto e' stato ricostruito dai turni precedenti (comando
        // nudo tipo "cerca online"): dai al modello l'interpretazione contestuale invece
        // della singola parola-comando.
        if ((string) ($route['prompt_for_ai'] ?? '') !== '') {
            $state['prompt_for_ai'] = (string) $route['prompt_for_ai'];
        }

        // Mostra subito le fonti nel frontend (blocco "Fonti web"), prima della risposta.
        $onDelta = $state['on_delta'] ?? null;
        if (is_callable($onDelta)) {
            $payload = json_encode([
                'query' => $outcome['query'],
                'provider' => $outcome['provider'],
                'results' => array_map(static fn (array $row): array => [
                    'title' => (string) ($row['title'] ?? ''),
                    'url' => (string) ($row['url'] ?? ''),
                ], $outcome['results']),
            ], JSON_UNESCAPED_UNICODE);
            if ($payload !== false) {
                $onDelta($payload, 'sources');
            }
        }

        $this->completeExecutionTask($state, $step->label);
        $step->complete($started, [
            'provider' => $outcome['provider'],
            'query' => $outcome['query'],
            'results' => count($outcome['results']),
        ]);
        return $state;
    }

    /**
     * Router di intento del messaggio (handoff sez. 44 + 46). Decide un'unica azione:
     *
     *  - Fast-path esplicito immagine "/img ...": azione image, prompt = testo del comando.
     *  - Fast-path esplicito web ("cerca sul web", "/web"...): azione web, niente classificatore.
     *  - Classificatore AI (WebSearchIntentService, catena deepseek->cerebras): language-agnostic,
     *    sceglie chat|web|image e ricostruisce query/prompt dai turni precedenti.
     *  - Fallback anti-regressione: se il classificatore non decide -> vecchio requiresWeb a
     *    parole-chiave SOLO per il web (l'immagine non parte mai da keyword: serve decisione positiva).
     *
     * @return array{action: string, web_query: string, image_prompt: string, prompt_for_ai: string}
     */
    private function resolveRoute(array $state): array
    {
        $request = (string) $state['request'];
        $chat = ['action' => 'chat', 'web_query' => '', 'image_prompt' => '', 'prompt_for_ai' => ''];

        // Fast-path esplicito immagine: comando "/img ..." (scorciatoia, salta il classificatore).
        if (preg_match('/^\s*\/(?:img|image|immagine)\b\s*(.*)$/isu', $request, $m)) {
            $prompt = trim((string) $m[1]);
            return ['action' => 'image', 'web_query' => '', 'image_prompt' => $prompt !== '' ? $prompt : $request, 'prompt_for_ai' => ''];
        }

        $explicit = ProviderIntentFactory::isExplicitWebRequest($request);
        $resident = $this->contextHasResidentKnowledge($state['context'] ?? null);
        $service = WebSearchService::fromRoot($this->app->root);
        $previous = $this->previousUserMessage($state);
        $followUpPrompt = static fn (string $query): string => sprintf(
            "Richiesta originale dell'utente: %s\n\nInterpretazione contestuale: l'utente chiede di verificare/cercare sul web il tema del turno precedente: \"%s\". Rispondi a questa interpretazione, non alla singola parola del comando breve.",
            $request,
            $query
        );
        $webRoute = static fn (string $query, string $prompt): array => ['action' => 'web', 'web_query' => $query, 'image_prompt' => '', 'prompt_for_ai' => $prompt];

        // Fast-path web esplicito: forzato, query come nel percorso storico (sez. 38.1).
        if ($explicit) {
            $query = $service->meaningfulQuery($request, $previous);
            return $webRoute($query, $service->queryWasResolvedFromFallback($request, $previous, $query) ? $followUpPrompt($query) : '');
        }

        // Classificatore AI language-agnostic.
        $intentService = WebSearchIntentService::fromRoot($this->app->root);
        if ($intentService->enabled()) {
            $decision = $intentService->classify($request, $this->toHistoryMessages($this->loadHistory($state['session'])), $resident, $state['cancellation'] ?? null);
            if ($decision['decided']) {
                if ($decision['action'] === 'image') {
                    $prompt = $decision['image_prompt'] !== '' ? $decision['image_prompt'] : $request;
                    return ['action' => 'image', 'web_query' => '', 'image_prompt' => $prompt, 'prompt_for_ai' => ''];
                }
                if ($decision['action'] === 'web') {
                    // Memoria residente presente + nessun trigger esplicito: la risposta si ancora
                    // al documento interno, niente web (sez. 43.5).
                    if ($resident) {
                        return $chat;
                    }
                    $query = $decision['query'] !== '' ? $decision['query'] : $service->meaningfulQuery($request, $previous);
                    return $webRoute($query, $service->isBareCommand($request) ? $followUpPrompt($query) : '');
                }
                return $chat;
            }
        }

        // Fallback a parole-chiave (rete di sicurezza per il web; l'immagine mai da keyword).
        $intent = $state['intent'] instanceof ProviderIntent ? $state['intent'] : null;
        if ($intent !== null && $intent->requiresWeb && !$resident) {
            $query = $service->meaningfulQuery($request, $previous);
            return $webRoute($query, $service->queryWasResolvedFromFallback($request, $previous, $query) ? $followUpPrompt($query) : '');
        }

        return $chat;
    }

    /**
     * Step di generazione immagine (handoff sez. 46). Gira solo se il router ha deciso
     * action=image. Genera via ImageProviderManager (catena Gemini->Cloudflare), salva
     * l'immagine come allegato, la mostra subito in chat e imposta ai_result cosi' che gli
     * step di salvataggio/risposta la trattino come la risposta dell'assistant.
     */
    private function generateImage(ExecutionStep $step, array $state, float $started): array
    {
        $route = is_array($state['route'] ?? null) ? $state['route'] : [];
        if (($route['action'] ?? 'chat') !== 'image') {
            $step->skip($started, ['reason' => 'Nessuna immagine richiesta']);
            return $state;
        }

        $manager = ImageProviderManager::default();
        if (!$manager->enabled()) {
            $state['ai_result'] = AIProviderResult::failure('Generazione immagini disabilitata.', [], ['choice_reason' => 'image_disabled']);
            $step->fail($started, 'Generazione immagini disabilitata');
            return $state;
        }

        $prompt = (string) ($route['image_prompt'] ?? $state['request']);
        if ($this->isCancelled($state)) {
            $state['ai_result'] = AIProviderResult::failure('Richiesta interrotta.', [], ['choice_reason' => 'cancelled_by_user']);
            $step->skip($started, ['reason' => 'Richiesta interrotta']);
            return $state;
        }

        $outcome = $manager->generate($prompt, $state['cancellation'] ?? null);
        $elapsed = (int) round((microtime(true) - $started) * 1000);
        $state['response_time_ms'] = $elapsed;
        if ($this->isCancelled($state)) {
            $state['ai_result'] = AIProviderResult::failure('Richiesta interrotta.', $outcome, [
                'choice_reason' => 'cancelled_by_user',
                'response_time_ms' => $elapsed,
            ]);
            $step->skip($started, ['reason' => 'Richiesta interrotta']);
            return $state;
        }

        if (empty($outcome['ok'])) {
            $message = "Non sono riuscito a generare l'immagine. " . (string) ($outcome['error'] ?? '');
            $state['ai_result'] = AIProviderResult::failure($message, $outcome, [
                'provider' => (string) ($outcome['provider'] ?? ''),
                'choice_reason' => 'image_failed',
                'response_time_ms' => $elapsed,
            ]);
            // image.generate e' esente dal break del piano (come ai.complete): lo step fallisce
            // ma save/response proseguono e la chat mostra l'errore.
            $step->fail($started, $message, ['provider' => (string) ($outcome['provider'] ?? ''), 'response_time_ms' => $elapsed]);
            return $state;
        }

        $saved = $this->saveGeneratedImage($state, (string) $outcome['image_base64'], (string) $outcome['mime']);
        if ($saved === null) {
            $state['ai_result'] = AIProviderResult::failure("Immagine generata ma non salvata.", $outcome, ['choice_reason' => 'image_save_failed', 'response_time_ms' => $elapsed]);
            $step->fail($started, 'Immagine generata ma non salvata');
            return $state;
        }
        $state['generated_image'] = $saved;

        $onDelta = $state['on_delta'] ?? null;
        if (is_callable($onDelta)) {
            $payload = json_encode([
                'url' => $saved['url'],
                'name' => $saved['name'],
                'mime' => $saved['mime'],
                'prompt' => $prompt,
            ], JSON_UNESCAPED_UNICODE);
            if ($payload !== false) {
                $onDelta($payload, 'image');
            }
        }

        $state['ai_result'] = AIProviderResult::success('Immagine generata.', 0, 0, ['image' => true], [
            'provider' => (string) $outcome['provider'],
            'model' => (string) $outcome['model'],
            'choice_reason' => 'image_generated',
            'response_time_ms' => $elapsed,
        ]);
        if ((string) $outcome['provider'] !== '') {
            $state['execution_state']->recordProvider((string) $outcome['provider'], 'image_generated');
        }
        $this->completeExecutionTask($state, $step->label);
        $step->complete($started, ['provider' => (string) $outcome['provider'], 'model' => (string) $outcome['model'], 'response_time_ms' => $elapsed]);
        return $state;
    }

    /**
     * Salva l'immagine base64 su disco come allegato di chat (is_image), senza conversation_id:
     * verra' collegato al messaggio assistant in conversation.save. Ritorna i dati per la resa.
     *
     * @return array{id: int, url: string, name: string, path: string, absolute_path: string, mime: string}|null
     */
    private function saveGeneratedImage(array $state, string $base64, string $mime): ?array
    {
        $storage = (string) ($this->app->config['paths']['storage'] ?? ($this->app->root . '/storage'));
        $service = new ChatAttachmentService($storage);
        $saved = $service->saveGeneratedImage($base64, $mime, (int) $state['project']['id'], (int) $state['session']['id']);
        if ($saved === null) {
            return null;
        }

        return [
            'id' => (int) $saved['id'],
            'url' => '/media/attachment?id=' . (int) $saved['id'],
            'name' => (string) $saved['name'],
            'path' => (string) $saved['path'],
            'absolute_path' => (string) $saved['absolute_path'],
            'mime' => (string) $saved['mime'],
        ];
    }

    /**
     * Pin locale (LM Studio): il documento residente intero non entra nella finestra
     * reale del modello locale. Se la Knowledge supera il budget, la si spezza e si
     * tengono solo i pezzi pertinenti alla domanda (embedding locali). In AUTO e sugli
     * altri provider non si tocca nulla (sez. 43). Solo per la memoria residente.
     */
    private function fitLocalResidentKnowledge(array $state): ContextInterface
    {
        $context = $state['context'] ?? null;
        if (!$context instanceof ContextInterface || !method_exists($context, 'withItems')) {
            return $context;
        }
        if (strtolower((string) ($state['provider_mode'] ?? '')) !== 'lmstudio') {
            return $context;
        }

        $items = $context->items();
        $knowledge = array_values(array_filter($items, static fn (ContextItem $item): bool => $item->source === 'knowledge'));
        if ($knowledge === []) {
            return $context;
        }

        $retriever = LocalDocumentRetriever::fromRoot($this->app->root);
        $budgetChars = $retriever->budgetChars($state['cancellation'] ?? null);

        $totalChars = 0;
        foreach ($knowledge as $item) {
            $totalChars += mb_strlen($item->content);
        }
        if ($totalChars <= $budgetChars) {
            return $context; // ci sta gia' tutto: nessun taglio
        }

        $query = (string) ($state['request'] ?? $context->userRequest());
        $perItemBudget = max(1, intdiv($budgetChars, count($knowledge)));

        $rebuilt = [];
        foreach ($items as $item) {
            if ($item->source !== 'knowledge') {
                $rebuilt[] = $item;
                continue;
            }
            if ($this->isCancelled($state)) {
                return $context;
            }
            $reduced = $retriever->selectRelevant($item->content, $query, $perItemBudget, $state['cancellation'] ?? null);
            if ($reduced === '' || $reduced === $item->content) {
                $rebuilt[] = $item;
                continue;
            }
            $rebuilt[] = new ContextItem(
                source: $item->source,
                type: $item->type,
                title: $item->title . ' (estratti pertinenti)',
                content: $reduced,
                priority: $item->priority,
                metadata: $item->metadata,
            );
        }

        return $context->withItems($rebuilt);
    }

    /**
     * Il contesto contiene memoria residente di progetto (Knowledge)? La chiave 'knowledge'
     * e' quella del KnowledgeContextProvider (sempre iniettata per i progetti, sez. 43).
     */
    private function contextHasResidentKnowledge(?ContextInterface $context): bool
    {
        if ($context === null) {
            return false;
        }
        foreach ($context->items() as $item) {
            if ($item->source === 'knowledge') {
                return true;
            }
        }
        return false;
    }

    private function consultKnowledge(ExecutionStep $step, array $state, float $started): array
    {
        $items = $state['context'] ? count($state['context']->items()) : 0;
        $this->completeExecutionTask($state, $step->label);
        $step->complete($started, ['context_items_available' => $items]);
        return $state;
    }

    private function completeWithAi(ExecutionStep $step, array $state, float $started): array
    {
        // Azione immagine: la risposta e' gia' stata prodotta da image.generate (ai_result
        // impostato). Nessuna risposta testuale del modello.
        if (($state['route']['action'] ?? 'chat') === 'image') {
            $step->skip($started, ['reason' => 'Immagine gia generata, nessuna risposta testuale']);
            return $state;
        }

        if (!$step->metadata['needs_ai']) {
            $state['ai_result'] = AIProviderResult::success('Richiesta gestita senza AI.', 0, 0);
            $step->skip($started, ['reason' => 'AI non necessaria']);
            return $state;
        }

        $intent = $state['intent'] instanceof ProviderIntent ? $state['intent'] : $this->providerIntent($state);
        $providerRequest = new ProviderRequest(
            (string) ($state['prompt_for_ai'] ?? $state['request']),
            $state['context'],
            $state['execution_state'],
            $state['provider_mode'] ?: 'auto',
            $state['cancellation'],
            $intent,
            is_array($state['attachments'] ?? null) ? $state['attachments'] : [],
            (string) ($state['sticky_provider'] ?? '')
        );
        $manager = ProviderManager::default();
        $onDelta = $state['on_delta'] ?? null;
        if (!is_callable($onDelta)) {
            $state['ai_result'] = AIProviderResult::failure('La chat supporta solo risposte in streaming.', [], [
                'choice_reason' => 'streaming_required',
            ]);
            $step->fail($started, $state['ai_result']->error);
            return $state;
        }

        $state['ai_result'] = $manager->stream($providerRequest, $onDelta);
        $state['response_time_ms'] = $state['ai_result']->responseTimeMs;
        if ($state['ai_result']->provider !== '') {
            $state['execution_state']->recordProvider($state['ai_result']->provider, $state['ai_result']->choiceReason);
        }

        if (!$state['ai_result']->ok) {
            if ($this->isCancelledResult($state['ai_result'])) {
                $state['execution_state']->currentState = 'cancelled';
                $this->persistExecutionState($state);
                $step->skip($started, ['reason' => 'Richiesta interrotta']);
                return $state;
            }

            $state['execution_state']->currentState = 'provider_failed';
            $this->persistExecutionState($state);
            $step->fail($started, $state['ai_result']->error, ['response_time_ms' => $state['response_time_ms']]);
            return $state;
        }

        $step->complete($started, [
            'provider' => $state['ai_result']->provider,
            'model' => $state['ai_result']->model,
            'choice_reason' => $state['ai_result']->choiceReason,
            'fallback_used' => $state['ai_result']->fallbackUsed,
            'provider_intent' => $intent->toArray(),
            'response_time_ms' => $state['response_time_ms'],
        ]);
        $this->completeExecutionTask($state, $step->label);
        return $state;
    }

    private function prepareBrain(ExecutionStep $step, array $state, float $started): array
    {
        if ($this->isGenericChat($state['project'])) {
            $this->completeExecutionTask($state, $step->label);
            $step->skip($started, ['reason' => 'Chat libera senza Project Brain']);
            return $state;
        }

        $this->completeExecutionTask($state, $step->label);
        $step->complete($started, ['mode' => 'deferred_until_session_archive']);
        return $state;
    }

    private function saveConversation(ExecutionStep $step, array $state, float $started): array
    {
        $result = $state['ai_result'];
        $provider = $result instanceof AIProviderResult ? $result->provider : '';
        $model = $result instanceof AIProviderResult ? $result->model : '';

        if (!$this->isCancelled($state) && $result instanceof AIProviderResult && $result->ok) {
            $state['assistant_conversation_id'] = $this->store->saveAssistantMessage($state, $provider, $model, $result->content, $result->tokensInput, $result->tokensOutput);
            // Immagine generata: collega l'allegato al messaggio assistant cosi' ricompare
            // nello storico dopo il reload (sez. 46).
            if (is_array($state['generated_image'] ?? null) && (int) $state['assistant_conversation_id'] > 0) {
                $this->store->attachToConversation([(int) $state['generated_image']['id']], (int) $state['assistant_conversation_id']);
            }
        } elseif (is_array($state['generated_image'] ?? null) && (int) ($state['generated_image']['id'] ?? 0) > 0) {
            // Messaggio assistant non salvato (richiesta interrotta o fallita): l'immagine
            // generata resterebbe orfana, nessun messaggio a cui agganciarla. La rimuoviamo
            // (riga + file), come per gli allegati utente (audit).
            $storage = (string) $this->app->config['paths']['storage'];
            (new ChatAttachmentService($storage))->discard([[
                'id' => (int) $state['generated_image']['id'],
                'absolute_path' => (string) ($state['generated_image']['absolute_path'] ?? ''),
            ]]);
        }

        $this->store->logRequest($state, $state['assistant_conversation_id'], $provider, $model);

        $this->store->touchSession((int) $state['session']['id']);
        $this->completeExecutionTask($state, $step->label);
        $step->complete($started, ['assistant_conversation_id' => $state['assistant_conversation_id']]);
        return $state;
    }

    private function buildResponse(ExecutionStep $step, array $state, float $started): array
    {
        $result = $state['ai_result'];
        $state['final_message'] = $result instanceof AIProviderResult && $result->ok
            ? 'Risposta generata.'
            : ($result instanceof AIProviderResult ? $result->error : 'Richiesta non completata.');
        $state['execution_state']->currentState = match (true) {
            $result instanceof AIProviderResult && $result->ok => 'waiting_next_step',
            $result instanceof AIProviderResult && $this->isCancelledResult($result) => 'cancelled',
            default => 'blocked_provider_error',
        };
        $this->completeExecutionTask($state, $step->label);
        $this->persistExecutionState($state);
        $step->complete($started, ['message' => $state['final_message']]);
        return $state;
    }

    private function skipUnknown(ExecutionStep $step, array $state, float $started): array
    {
        $step->skip($started, ['reason' => 'Step non gestito dal core']);
        return $state;
    }

    private function runPluginStep(ExecutionStep $step, array $state): ?array
    {
        if (!$step->replaceable) {
            return null;
        }

        $results = $this->app->plugins->hook('orchestrator.step.' . $step->type, [
            'step' => $step,
            'state' => $state,
        ]);

        foreach ($results as $result) {
            if (is_array($result) && array_key_exists('handled', $result) && $result['handled'] === true) {
                return $result;
            }
        }

        return null;
    }

    private function finishPluginStep(ExecutionStep $step, float $started, array $pluginResult): void
    {
        $output = $pluginResult['output'] ?? [];
        if (!empty($pluginResult['error'])) {
            $step->fail($started, (string) $pluginResult['error'], $output);
            return;
        }

        if (($pluginResult['status'] ?? ExecutionStep::COMPLETED) === ExecutionStep::SKIPPED) {
            $step->skip($started, $output);
            return;
        }

        $step->complete($started, $output);
    }

    private function needsAi(string $request): bool
    {
        return trim($request) !== '';
    }

    /**
     * Fallback usato solo quando l'intent non e' gia' stato calcolato a monte
     * (percorso non-streaming, extras['intent'] assente). Delega alla stessa
     * logica di ChatService::stream() tramite ProviderIntentFactory, cosi'
     * i due percorsi non possono piu' divergere (audit sez. 37).
     */
    private function providerIntent(array $state): ProviderIntent
    {
        $hasFiles = !empty($state['attachments'] ?? null);
        $hasImages = ProviderIntentFactory::hasImageAttachments(
            is_array($state['attachments'] ?? null) ? $state['attachments'] : []
        );

        return ProviderIntentFactory::fromPrompt((string) $state['request'], $hasFiles, $hasImages);
    }

    private function completeExecutionTask(array $state, string $task): void
    {
        $execution = $state['execution_state'];
        $execution->completedTasks[] = $task;
        $execution->remainingTasks = array_values(array_filter($execution->remainingTasks, fn (string $item): bool => $item !== $task));
    }

    private function persistExecutionState(array $state): void
    {
        $state['execution_repo']->save($state['execution_state']);
    }

    private function isGenericChat(array $project): bool
    {
        return (int) ($project['is_system'] ?? 0) === 1;
    }

    private function isCancelled(array $state): bool
    {
        return ($state['cancellation'] ?? null) instanceof CancellationToken && $state['cancellation']->isCancelled();
    }

    private function isCancelledResult(AIProviderResult $result): bool
    {
        return $result->choiceReason === 'cancelled_by_user' || $result->error === 'Richiesta interrotta.';
    }

}
