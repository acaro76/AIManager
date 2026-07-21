<?php

declare(strict_types=1);

use App\Core\Code\CodeContext;
use App\Core\ContextEngine\Context;
use App\Core\ContextEngine\ContextItem;
use App\Providers\LmStudioProvider;

// F1.4 — supporto ADDITIVO al system prompt dedicato in AbstractProvider::systemMessage().
// Nessuna rete: si invoca solo il metodo protetto di costruzione del messaggio di sistema.

// I metodi protetti sono gia' invocabili via Reflection da PHP 8.1 (niente setAccessible).
$systemMessage = static function (object $context): string {
    $provider = new LmStudioProvider();
    return (string) (new ReflectionMethod($provider, 'systemMessage'))->invoke($provider, $context);
};

test('systemMessage: un CodeContext impone il SUO system prompt (dedicato e prioritario)', function () use ($systemMessage) {
    $ctx = new CodeContext(
        systemPrompt: 'SYSTEM-CODE-DEDICATO',
        userRequest: 'dove sta il login',
        items: [new ContextItem('code', 'context', 'Contesto Code', 'blocco dati', 100)],
        history: [],
        workspaceId: 7,
        codeSessionId: 3,
        workspaceName: 'progetto',
    );
    $sys = $systemMessage($ctx);
    assertSame('SYSTEM-CODE-DEDICATO', $sys); // esattamente il prompt Code
    // NON deve comparire nulla del ramo "progetto LLM" ne' della chat libera
    assertSame(false, str_contains($sys, 'Sei AIManager'));
    assertSame(false, str_contains($sys, 'Progetto:'));
    assertSame(false, str_contains($sys, 'assistente AI conversazionale'));
});

test('systemMessage: il contesto LLM di WORKSPACE resta invariato (anti-regressione)', function () use ($systemMessage) {
    $ctx = new Context(
        ['id' => 1, 'name' => 'ProgettoX', 'is_system' => 0],
        'domanda',
        [new ContextItem('memoria', 'nota', 'Titolo', 'contenuto nota', 5)],
    );
    $sys = $systemMessage($ctx);
    assertSame(true, str_contains($sys, 'Sei AIManager'));
    assertSame(true, str_contains($sys, 'Progetto: ProgettoX'));
    assertSame(true, str_contains($sys, 'Contesto ordinato per priorita:'));
    assertSame(true, str_contains($sys, 'contenuto nota'));
});

test('systemMessage: la CHAT LIBERA LLM resta invariata (anti-regressione)', function () use ($systemMessage) {
    $ctx = new Context(
        ['id' => 2, 'name' => 'Chat libera', 'is_system' => 1],
        'ciao',
        [],
    );
    $sys = $systemMessage($ctx);
    assertSame(true, str_contains($sys, 'Sei un assistente AI conversazionale.'));
    assertSame(false, str_contains($sys, 'Progetto:'));
});

test('CodeContext: executionState e\' null e project/session sono Code-specifici', function () {
    $ctx = new CodeContext('s', 'q', [], [], 9, 4, 'cartella');
    assertSame(null, $ctx->executionState()); // mai un ExecutionState fittizio
    assertSame('code', $ctx->project()['surface']);
    assertSame(9, $ctx->project()['workspace_id']);
    assertSame(4, $ctx->session()['code_session_id']);
    // nessuna chiave da progetto LLM
    assertSame(false, array_key_exists('is_system', $ctx->project()));
});

test('ProviderRequest: executionState e\' opzionale e vale null di default', function () {
    $ctx = new CodeContext('s', 'q', [], [], 1, 1, 'c');
    $req = new App\Core\Providers\ProviderRequest(prompt: 'p', context: $ctx);
    assertSame(null, $req->executionState);
    assertSame('auto', $req->mode);
    assertSame(false, $req->structuredJson);
});
