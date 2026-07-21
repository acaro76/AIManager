<?php

declare(strict_types=1);

use App\Core\Providers\LmStudioContextWindow;

// --- apiBase: deriva la base REST v0 dall'endpoint OpenAI-compatibile ---

test('apiBase toglie il path /v1 e tiene host+porta', function () {
    assertSame('http://localhost:1234', LmStudioContextWindow::apiBase('http://localhost:1234/v1'));
});

test('apiBase conserva lo schema https', function () {
    assertSame('https://host:5000', LmStudioContextWindow::apiBase('https://host:5000/v1/chat'));
});

test('apiBase mette schema http di default quando manca', function () {
    // endpoint senza schema: parse_url ricava host+porta, la base usa http
    assertSame('http://localhost:1234', LmStudioContextWindow::apiBase('localhost:1234'));
});

test('apiBase su stringa vuota torna stringa vuota', function () {
    assertSame('', LmStudioContextWindow::apiBase('   '));
});

// --- fromModelsResponse: legge la finestra reale del modello caricato ---

test('fromModelsResponse prende il loaded_context_length del modello caricato', function () {
    $json = ['data' => [
        ['state' => 'loaded', 'loaded_context_length' => 8192],
    ]];
    assertSame(8192, LmStudioContextWindow::fromModelsResponse($json));
});

test('fromModelsResponse prende il massimo tra piu\' modelli caricati', function () {
    $json = ['data' => [
        ['state' => 'loaded', 'loaded_context_length' => 4096],
        ['state' => 'loaded', 'loaded_context_length' => 16384],
    ]];
    assertSame(16384, LmStudioContextWindow::fromModelsResponse($json));
});

test('fromModelsResponse ignora i modelli non caricati', function () {
    $json = ['data' => [
        ['state' => 'not-loaded', 'loaded_context_length' => 262144],
        ['state' => 'loaded', 'loaded_context_length' => 8192],
    ]];
    assertSame(8192, LmStudioContextWindow::fromModelsResponse($json));
});

test('fromModelsResponse torna null se nessun modello e\' caricato', function () {
    $json = ['data' => [
        ['state' => 'not-loaded', 'loaded_context_length' => 262144],
    ]];
    assertSame(null, LmStudioContextWindow::fromModelsResponse($json));
});

test('fromModelsResponse ignora loaded_context_length a zero o assente', function () {
    $json = ['data' => [
        ['state' => 'loaded', 'loaded_context_length' => 0],
        ['state' => 'loaded'],
    ]];
    assertSame(null, LmStudioContextWindow::fromModelsResponse($json));
});

test('fromModelsResponse torna null su risposta malformata', function () {
    assertSame(null, LmStudioContextWindow::fromModelsResponse(null));
    assertSame(null, LmStudioContextWindow::fromModelsResponse(['data' => []]));
    assertSame(null, LmStudioContextWindow::fromModelsResponse('stringa'));
});
