<?php

declare(strict_types=1);

namespace App\Core\Providers;

use App\Core\App;
use App\Core\Configuration\ConfigurationManager;
use App\Core\ContextEngine\ContextInterface;
use App\Core\Providers\Policy\AllowAllRoutingPolicy;
use App\Core\Providers\Policy\ProviderRoutingPolicyInterface;
use App\Repositories\ProviderConfigRepository;
use App\Services\AIProviderRegistry;
use App\Services\AIProviderResult;

final class ProviderManager
{
    // Finestra di ripiego per LM Studio quando l'API locale non e' raggiungibile o
    // nessun modello e' caricato: prudente (il default tipico di LM Studio), mai il
    // massimo teorico del modello.
    private const LMSTUDIO_FALLBACK_WINDOW = 8192;

    private bool $lmStudioWindowResolved = false;
    private int $lmStudioWindowCache = self::LMSTUDIO_FALLBACK_WINDOW;

    public function __construct(
        private readonly AIProviderRegistry $registry,
        private readonly ProviderConfigStoreInterface $configs,
        private readonly ConfigurationManager $configuration,
        private readonly ProviderRoutingPolicyInterface $routingPolicy,
    ) {
    }

    public static function default(): self
    {
        $app = App::get();
        return new self(
            AIProviderRegistry::fromConfig(),
            new ProviderConfigRepository(),
            ConfigurationManager::fromRoot($app->root),
            new AllowAllRoutingPolicy()
        );
    }

    public function stream(ProviderRequest $request, callable $onDelta): AIProviderResult
    {
        $intent = $request->intent ?? ProviderIntent::neutral();
        $candidates = $this->candidates($request->mode, $intent, $request->preferredProvider, $request->context, $request->prompt);
        if (!$candidates) {
            return AIProviderResult::failure($this->noCandidateMessage($intent), [], ['choice_reason' => $this->noCandidateReason($intent)]);
        }

        $this->extendExecutionTime($candidates);
        $deadline = microtime(true) + $this->requestBudgetSeconds();
        $attempts = [];

        // Traccia se un tentativo ha gia' streammato contenuto visibile: se poi fallisce e si
        // passa al provider successivo, segnaliamo al frontend di azzerare il parziale (es.
        // troncamento Gemini) cosi' la risposta del provider di fallback parte pulita.
        $streamedAttemptOutput = false;
        $wrappedOnDelta = static function (string $text, string $channel = 'content') use ($onDelta, &$streamedAttemptOutput): void {
            if ($text !== '' && self::isAttemptOutputChannel($channel)) {
                $streamedAttemptOutput = true;
            }
            $onDelta($text, $channel);
        };

        foreach ($candidates as $index => $config) {
            $provider = $this->registry->get((string) $config['provider']);
            if (!$provider) {
                continue;
            }

            if ($request->cancellation?->isCancelled()) {
                return AIProviderResult::failure('Richiesta interrotta.', [], ['choice_reason' => 'cancelled_by_user']);
            }

            $runtimeConfig = $this->configuration->runtimeConfig((string) $config['provider'], $config);
            $runtimeConfig['_cancellation_token'] = $request->cancellation;
            $runtimeConfig['_attachments'] = $request->attachments;
            $runtimeConfig['_intent'] = $request->intent;
            $runtimeConfig['_structured_json'] = $request->structuredJson;
            $remaining = $this->remainingBudgetSeconds($deadline);
            if ($remaining <= 1) {
                return AIProviderResult::failure('Tempo disponibile esaurito prima di completare la risposta AI.', ['attempts' => $attempts], [
                    'choice_reason' => 'execution_budget_exhausted',
                    'fallback_used' => count($attempts) > 0,
                ]);
            }
            $runtimeConfig['timeout_seconds'] = $this->attemptTimeout((int) ($runtimeConfig['timeout_seconds'] ?? 30), $remaining);

            $attempt = $provider->canAttempt($runtimeConfig);
            if (!$attempt['ok']) {
                $this->configs->updateHealth((string) $config['provider'], 'offline', (string) ($attempt['message'] ?? 'Provider non configurato'));
                $attempts[] = $config['provider'] . ':not_configured';
                continue;
            }

            $result = $provider->stream($request->prompt, $request->context, $runtimeConfig, $wrappedOnDelta);
            $meta = [
                'provider' => (string) $config['provider'],
                'model' => $result->model !== '' ? $result->model : (string) $runtimeConfig['model'],
                'endpoint' => $result->endpoint !== '' ? $result->endpoint : (string) $runtimeConfig['base_url'],
                'response_time_ms' => $result->responseTimeMs,
                'estimated_cost' => $this->estimatedCost((string) $config['provider'], $result->tokensInput, $result->tokensOutput),
                'choice_reason' => $this->choiceReason($request->mode, $index, $attempts, $request->intent ?? ProviderIntent::neutral()),
                'fallback_used' => $index > 0 || $attempts !== [],
            ];

            $final = $result->ok
                ? AIProviderResult::success($result->content, $result->tokensInput, $result->tokensOutput, $result->raw, $meta)
                : AIProviderResult::failure($result->error, $result->raw, $meta);

            $this->configs->markRequest((string) $config['provider'], $final->error);
            if ($final->ok) {
                $this->configs->updateHealth((string) $config['provider'], 'online');
                return $final;
            }

            if ($request->cancellation?->isCancelled() || $final->error === 'Richiesta interrotta.') {
                $meta['choice_reason'] = 'cancelled_by_user';
                return AIProviderResult::failure('Richiesta interrotta.', $final->raw, $meta);
            }

            $this->configs->updateHealth((string) $config['provider'], 'error', $final->error);
            $attempts[] = $config['provider'] . ':error';

            // Questo tentativo aveva gia' mostrato del testo parziale a video ma e' fallito:
            // azzera il parziale prima di provare il prossimo provider.
            if ($streamedAttemptOutput) {
                $onDelta('', 'reset');
                $streamedAttemptOutput = false;
            }
        }

        return AIProviderResult::failure('Tutti i provider disponibili hanno fallito.', ['attempts' => $attempts], [
            'choice_reason' => 'fallback_exhausted',
            'fallback_used' => count($attempts) > 1,
        ]);
    }

    public function healthCheck(string $providerKey, array $overrides = []): array
    {
        $provider = $this->registry->get($providerKey);
        $config = $this->configs->find($providerKey);
        if (!$provider || !$config) {
            return ['ok' => false, 'message' => 'Provider non registrato.'];
        }

        $runtimeConfig = $this->configuration->runtimeConfig($providerKey, $config);
        foreach (['base_url', 'model', 'api_key', 'timeout_seconds'] as $key) {
            if (array_key_exists($key, $overrides)) {
                $runtimeConfig[$key] = $overrides[$key];
            }
        }

        $result = $provider->healthCheck($runtimeConfig);
        $this->configs->updateHealth($providerKey, $result['ok'] ? 'online' : 'offline', $result['ok'] ? '' : (string) ($result['message'] ?? 'Errore'));
        return $result;
    }

    public function models(string $providerKey): array
    {
        $provider = $this->registry->get($providerKey);
        $config = $this->configs->find($providerKey);
        if (!$provider || !$config) {
            return [];
        }

        return $provider->models($this->configuration->runtimeConfig($providerKey, $config));
    }

    private function candidates(string $mode, ProviderIntent $intent, string $preferred = '', ?ContextInterface $context = null, string $prompt = ''): array
    {
        $enabled = array_values(array_filter(
            $this->configs->enabled(),
            fn (array $config): bool => $this->routingPolicy->allows((string) $config['provider'])
        ));
        $mode = strtolower($mode);
        if ($mode !== 'auto') {
            if ($intent->requiresVision && $this->profile($mode)['vision'] <= 0) {
                return array_values(array_filter($enabled, fn (array $row): bool => $this->profile((string) $row['provider'])['vision'] > 0));
            }

            return array_values(array_filter($enabled, fn (array $row): bool => strtolower((string) $row['provider']) === $mode));
        }

        if ($intent->requiresVision) {
            $enabled = array_values(array_filter($enabled, fn (array $row): bool => $this->profile((string) $row['provider'])['vision'] > 0));
        }

        // Stima dei token gia' in gioco (storico + knowledge + richiesta + allegati): serve a
        // penalizzare i provider con finestra di contesto piccola (es. Cerebras 8k) quando la
        // conversazione e' cresciuta, cosi' non vengono scelti per poi perdere i turni iniziali.
        $contextTokens = $this->estimateContextTokens($context, $prompt);

        usort($enabled, fn (array $a, array $b): int => $this->score($b, $intent, $contextTokens) <=> $this->score($a, $intent, $contextTokens));

        // Pin di sessione: se la conversazione gira gia' su un provider valido per questo
        // turno, tienilo in testa per non saltare provider a meta' chat (gli altri restano
        // come fallback). Una necessita' hard (vision) ha gia' filtrato i candidati sopra,
        // quindi il pin scatta solo se il provider e' ancora tra quelli ammissibili.
        return $this->pinPreferred($enabled, $preferred, $contextTokens);
    }

    private function pinPreferred(array $candidates, string $preferred, int $contextTokens): array
    {
        $preferred = strtolower(trim($preferred));
        if ($preferred === '') {
            return $candidates;
        }

        $pinned = [];
        $rest = [];
        foreach ($candidates as $config) {
            $provider = strtolower((string) $config['provider']);
            if ($provider === $preferred && self::canPreserveProvider($this->contextWindow($provider), $contextTokens)) {
                $pinned[] = $config;
            } else {
                $rest[] = $config;
            }
        }

        return array_merge($pinned, $rest);
    }

    /**
     * La continuita' di sessione e' desiderabile solo finche' il provider contiene
     * l'intero contesto. Una finestra insufficiente e' un vincolo hard come la vision:
     * il provider resta tra i fallback, ma non puo' scavalcare candidati compatibili.
     */
    public static function canPreserveProvider(int $window, int $contextTokens): bool
    {
        return self::windowPenalty($window, $contextTokens) === 0;
    }

    /** Canali prodotti dal singolo provider e da azzerare se quel tentativo fallisce. */
    public static function isAttemptOutputChannel(string $channel): bool
    {
        return !in_array($channel, ['sources', 'reset'], true);
    }

    private function noCandidateMessage(ProviderIntent $intent): string
    {
        if ($intent->requiresVision && !$this->hasEnabledVisionProvider()) {
            return 'Nessun provider con supporto immagini e abilitato. Abilita Gemini per leggere allegati immagine.';
        }

        return 'Nessun provider AI abilitato.';
    }

    private function noCandidateReason(ProviderIntent $intent): string
    {
        return $intent->requiresVision && !$this->hasEnabledVisionProvider() ? 'no_vision_provider' : 'no_enabled_provider';
    }

    private function hasEnabledVisionProvider(): bool
    {
        foreach ($this->configs->enabled() as $config) {
            $provider = (string) $config['provider'];
            if ($this->routingPolicy->allows($provider) && $this->profile($provider)['vision'] > 0) {
                return true;
            }
        }

        return false;
    }

    private function choiceReason(string $mode, int $index, array $attempts, ProviderIntent $intent): string
    {
        if (strtolower($mode) !== 'auto') {
            return 'manual_' . strtolower($mode);
        }

        if ($index === 0 && !$attempts) {
            return 'auto_intent_' . $intent->taskType;
        }

        return 'auto_intent_' . $intent->taskType . '_fallback_after_' . implode(',', $attempts);
    }

    /**
     * Penalita' di finestra: se i token gia' in gioco (storico + knowledge + richiesta)
     * non entrano nella finestra del provider lasciando un margine di 2500 per system +
     * output, lo si spinge in fondo (penalita' 1000). Con contextTokens sconosciuto (<=0)
     * nessuna penalita'. Regola dietro il fix "Cerebras non perde piu' contesto" (sez. 36).
     */
    public static function windowPenalty(int $window, int $contextTokens): int
    {
        return ($contextTokens > 0 && $window < $contextTokens + 2500) ? 1000 : 0;
    }

    private function score(array $config, ProviderIntent $intent, int $contextTokens = 0): int
    {
        $provider = (string) $config['provider'];
        $profile = $this->profile($provider);
        $isLocal = $provider === 'lmstudio';
        $heavy = $intent->isHeavy();
        $score = 0;

        // Finestra di contesto: se i token gia' in gioco (storico + knowledge + richiesta) non
        // entrano nella finestra del provider (lasciando un margine per system + output), lo si
        // spinge in fondo. Resta come ultima spiaggia (non escluso del tutto), ma i provider con
        // finestra grande vengono prima -> niente piu' "perde contesto e allucina" su chat lunghe.
        $windowPenalty = self::windowPenalty($this->contextWindow($provider), $contextTokens);

        // Rompicapo logico / problema difficile: comanda il ragionamento, ma senza ignorare
        // il costo. DeepSeek e' il ragionatore economico di riferimento.
        if ($intent->requiresDeepReasoning) {
            return $profile['reasoning'] * 10
                + $profile['knowledge'] * 2
                + $profile['context'] * 2
                + $profile['cost'] * 2
                + (int) round(((int) ($config['priority'] ?? 50)) / 50)
                - $windowPenalty;
        }

        // Il costo non domina da solo, ma conta: alcuni provider sono free tier, DeepSeek e'
        // molto economico, OpenAI/Claude sono premium.
        if ($heavy) {
            // Lavoro lungo/complesso: la latenza conta poco (lo streaming la maschera),
            // contano ragionamento, contesto e avere un locale illimitato/senza quota.
            $score += $profile['latency'];
            $score += $profile['reasoning'] * 4;
            $score += $profile['context'] * 2;
            if ($isLocal) {
                $score += 18;
            }
        } else {
            // Risposta breve: vince la velocita'; il locale lento e' penalizzato.
            $score += $profile['latency'] * 6;
            if ($isLocal) {
                $score -= 16;
            }
        }

        $score += $profile['cost'] * 2;

        // Domanda di pura conoscenza generale (fattuale/esplicativa): conta l'accuratezza,
        // non solo la velocita'. Vale anche quando la domanda contiene parole "ragionamento"
        // (es. "spiega"): Gemini (knowledge alto) supera locale e Groq, che resta fallback.
        // Non tocca codice/file/vision, che hanno requiresKnowledge=false.
        if ($intent->requiresKnowledge) {
            $score += $profile['knowledge'] * 4;
        }

        if ($intent->requiresFiles) {
            $score += $profile['files'] * 4;
        }

        if ($intent->requiresTools) {
            $score += $profile['tools'] * 3;
        }

        if ($intent->experimental) {
            $score += $profile['experimental'] * 5;
        }

        if ($intent->taskType === 'code') {
            $score += $profile['code'] * 3;
        }

        // Vision: Gemini (vision alto) resta primo; LM Studio resta candidato come
        // fallback se Gemini esaurisce il plafond gratuito.
        if ($intent->requiresVision) {
            $score += $profile['vision'] * 20;
        }

        // Priorita' solo come spareggio leggero.
        $score += (int) round(((int) ($config['priority'] ?? 50)) / 50);

        return $score - $windowPenalty;
    }

    /**
     * Stima grezza dei token gia' presenti nel contesto (storico conversazione + knowledge +
     * richiesta corrente), ~4 caratteri per token. Serve solo a confrontare con la finestra dei
     * provider, non a fatturare: l'approssimazione e' sufficiente.
     */
    private function estimateContextTokens(?ContextInterface $context, string $prompt = ''): int
    {
        if (!$context instanceof ContextInterface) {
            return self::requiredContextTokens($prompt, '', [], []);
        }

        $items = array_map(static fn ($item): string => (string) ($item->content ?? ''), $context->items());
        $history = array_map(static fn ($turn): string => (string) ($turn['content'] ?? ''), $context->history());

        return self::requiredContextTokens($prompt, $context->userRequest(), $items, $history);
    }

    /**
     * Token approssimativi (≈4 char/token) che entreranno nella finestra del provider.
     * Il testo primario e' il prompt REALE inviato (prompt_for_ai, che include il blocco
     * allegati); se assente si usa la richiesta grezza. Il blocco allegati NON sta nel
     * Context (per non duplicarlo), quindi va contato QUI o il routing sottostima e puo'
     * scegliere un provider dalla finestra troppo piccola.
     *
     * @param string[] $itemContents
     * @param string[] $historyContents
     */
    public static function requiredContextTokens(string $prompt, string $userRequest, array $itemContents, array $historyContents): int
    {
        $chars = max(mb_strlen($prompt), mb_strlen($userRequest));
        foreach ($itemContents as $content) {
            $chars += mb_strlen($content);
        }
        foreach ($historyContents as $content) {
            $chars += mb_strlen($content);
        }

        return (int) ceil($chars / 4);
    }

    /**
     * Finestra di contesto approssimativa (token) per provider. Valori prudenti del free/standard
     * tier. Cerebras (gpt-oss free) e' il piu' piccolo: e' il motivo per cui perdeva contesto.
     */
    private function contextWindow(string $provider): int
    {
        // LM Studio e' locale e la finestra REALE dipende da come l'utente carica il
        // modello (spesso 8k su un modello da 262k). Va letta dal server, non assunta,
        // altrimenti il routing manda documenti grandi al locale che poi li tronca.
        if ($provider === 'lmstudio') {
            return $this->lmStudioContextWindow();
        }

        return ModelRegistry::contextWindow($provider);
    }

    /**
     * Finestra di contesto REALE di LM Studio: interroga l'API locale (/api/v0/models)
     * e legge la `loaded_context_length` del modello caricato. LM Studio carica spesso
     * i modelli con una finestra molto piu' piccola del massimo (es. 8192 su qwen3.5-9b
     * che ne supporta 262144): il routing deve conoscere la capacita' effettiva, non
     * quella teorica. Memoizzato: una sola chiamata per istanza. Fallback prudente se
     * l'API non risponde o nessun modello e' caricato.
     */
    private function lmStudioContextWindow(): int
    {
        if ($this->lmStudioWindowResolved) {
            return $this->lmStudioWindowCache;
        }
        $this->lmStudioWindowResolved = true;

        $base = LmStudioContextWindow::apiBase($this->configuration->get('LMSTUDIO_ENDPOINT'));
        if ($base === '') {
            return $this->lmStudioWindowCache;
        }

        $json = $this->httpGetJson($base . '/api/v0/models', 2);
        $loaded = LmStudioContextWindow::fromModelsResponse($json);
        if ($loaded !== null) {
            $this->lmStudioWindowCache = $loaded;
        }
        return $this->lmStudioWindowCache;
    }

    /**
     * @return mixed decodifica JSON, oppure null in caso di errore/timeout.
     */
    private function httpGetJson(string $url, int $timeoutSeconds)
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (!is_string($body) || $status < 200 || $status >= 300) {
            return null;
        }
        return json_decode($body, true);
    }

    private function profile(string $provider): array
    {
        return ModelRegistry::profile($provider);
    }

    private function estimatedCost(string $provider, int $input, int $output): float
    {
        [$in, $out] = ModelRegistry::costRate($provider);
        return round(($input * $in) + ($output * $out), 6);
    }

    private function requestBudgetSeconds(): int
    {
        $maxExecution = (int) ini_get('max_execution_time');
        if ($maxExecution <= 0) {
            return 240;
        }

        return max(5, $maxExecution - 4);
    }

    private function remainingBudgetSeconds(float $deadline): int
    {
        return (int) floor($deadline - microtime(true));
    }

    private function attemptTimeout(int $configuredTimeout, int $remainingBudget): int
    {
        return max(1, min($configuredTimeout, $remainingBudget, $this->perAttemptLimitSeconds()));
    }

    private function perAttemptLimitSeconds(): int
    {
        $maxExecution = (int) ini_get('max_execution_time');
        if ($maxExecution <= 0) {
            return 240;
        }

        return max(5, $maxExecution - 8);
    }

    private function extendExecutionTime(array $candidates): void
    {
        if (!function_exists('set_time_limit')) {
            return;
        }

        $required = 30;
        foreach ($candidates as $config) {
            $required = max($required, (int) ($config['timeout_seconds'] ?? 30) + 20);
        }

        @set_time_limit(min(240, $required));
    }

}
