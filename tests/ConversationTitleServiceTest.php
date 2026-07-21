<?php

declare(strict_types=1);

use App\Services\ConversationTitleService;

$svc = new ConversationTitleService();

// --- fromPrompt: titolo dai termini salienti, saltando stop-word ---

test('fromPrompt salta le stop-word e tiene i termini salienti', function () use ($svc) {
    assertSame('Funziona fotosintesi', $svc->fromPrompt('Spiegami come funziona la fotosintesi'));
});

test('fromPrompt su solo saluto ripiega su Nuova conversazione', function () use ($svc) {
    assertSame('Nuova conversazione', $svc->fromPrompt('Ciao!'));
});

test('fromPrompt su stringa vuota torna Nuova conversazione', function () use ($svc) {
    assertSame('Nuova conversazione', $svc->fromPrompt(''));
});

test('fromPrompt conserva numeri e trattini interni (GPT-4)', function () use ($svc) {
    assertSame('GPT-4 Claude', $svc->fromPrompt('GPT-4 vs Claude'));
});

test('fromPrompt tronca a 42 caratteri', function () use ($svc) {
    $title = $svc->fromPrompt('Sviluppare applicazione gestionale magazzino automatico intelligente');
    assertSame('Sviluppare applicazione gestionale magazzi', $title);
    assertSame(true, mb_strlen($title) <= 42, 'il titolo non deve superare 42 caratteri');
});

// --- isProvisional: riconosce i titoli segnaposto (case/spazi inclusi) ---

test('isProvisional riconosce i segnaposto anche con maiuscole e spazi', function () use ($svc) {
    assertSame(true, $svc->isProvisional('Chat libera'));
    assertSame(true, $svc->isProvisional('  NUOVA CONVERSAZIONE '));
    assertSame(true, $svc->isProvisional('Nuova sessione'));
});

test('isProvisional e\' falso per un titolo reale', function () use ($svc) {
    assertSame(false, $svc->isProvisional('Fotosintesi'));
});
