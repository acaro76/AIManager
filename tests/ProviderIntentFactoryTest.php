<?php

declare(strict_types=1);

use App\Core\Providers\ProviderIntentFactory as IntentFactory;

test('intent creativo resta chat generale senza knowledge', function () {
    $intent = IntentFactory::fromPrompt('Scrivimi una poesia sul mare', false, false);
    assertSame('general', $intent->taskType);
    assertSame(false, $intent->requiresKnowledge);
    assertSame(false, $intent->requiresWeb);
});

test('domanda fattuale richiede knowledge', function () {
    $intent = IntentFactory::fromPrompt('Come funziona la fotosintesi?', false, false);
    assertSame(true, $intent->requiresKnowledge);
});

test('audit PHP viene classificato come codice pesante', function () {
    $intent = IntentFactory::fromPrompt('Fai un audit del codice PHP', false, false);
    assertSame('code', $intent->taskType);
    assertSame(true, $intent->requiresTools);
    assertSame(true, $intent->isHeavy());
});

test('allegato testuale richiede file ma non knowledge generale', function () {
    $intent = IntentFactory::fromPrompt('Riassumi il documento allegato', true, false);
    assertSame(true, $intent->requiresFiles);
    assertSame(false, $intent->requiresKnowledge);
    assertSame(true, $intent->isHeavy());
});

test('allegato immagine impone vision', function () {
    $intent = IntentFactory::fromPrompt('Descrivi questa foto', true, true);
    assertSame('vision', $intent->taskType);
    assertSame(true, $intent->requiresVision);
});

test('richiesta web esplicita attiva il fallback web', function () {
    $intent = IntentFactory::fromPrompt('Cerca sul web le notizie di oggi', false, false);
    assertSame(true, $intent->requiresWeb);
});

test('rompicapo logico richiede deep reasoning', function () {
    $intent = IntentFactory::fromPrompt('Risolvi questo rompicapo logico', false, false);
    assertSame(true, $intent->requiresDeepReasoning);
});
