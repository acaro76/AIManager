<?php

declare(strict_types=1);

use App\Core\Providers\LmStudioModelChoice as Choice;
use App\Core\Providers\ProviderIntent;

// Modelli fittizi: R=ragionante, F=veloce, C=coder, V=vision.
// intento leggero/pesante secondo ProviderIntent::isHeavy().
function intent(array $o = []): ProviderIntent
{
    return new ProviderIntent(
        taskType: $o['taskType'] ?? 'general',
        complexity: $o['complexity'] ?? 2,
        latency: 3,
        cost: 3,
        contextSize: $o['contextSize'] ?? 1,
        requiresReasoning: $o['requiresReasoning'] ?? false,
        requiresVision: $o['requiresVision'] ?? false,
    );
}

// --- senza intento: modello veloce (o ragionante se manca) ---

test('senza intento usa il veloce', function () {
    assertSame('F', Choice::resolve('R', 'F', 'C', 'V', null));
});

test('senza intento e senza veloce ripiega sul ragionante', function () {
    assertSame('R', Choice::resolve('R', '', 'C', 'V', null));
});

// --- vision ---

test('vision leggera usa il modello vision', function () {
    assertSame('V', Choice::resolve('R', 'F', 'C', 'V', intent(['requiresVision' => true])));
});

test('vision pesante usa il ragionante (non un solo-testo)', function () {
    assertSame('R', Choice::resolve('R', 'F', 'C', 'V', intent(['requiresVision' => true, 'complexity' => 5])));
});

test('vision senza modello vision ripiega sul ragionante', function () {
    assertSame('R', Choice::resolve('R', 'F', 'C', '', intent(['requiresVision' => true])));
});

test('vision senza vision ne\' ragionante ripiega sul veloce', function () {
    assertSame('F', Choice::resolve('', 'F', 'C', '', intent(['requiresVision' => true])));
});

// --- codice ---

test('codice usa il coder', function () {
    assertSame('C', Choice::resolve('R', 'F', 'C', 'V', intent(['taskType' => 'code'])));
});

test('codice senza coder ripiega sul veloce', function () {
    assertSame('F', Choice::resolve('R', 'F', '', 'V', intent(['taskType' => 'code'])));
});

test('codice senza coder ne\' veloce ripiega sul ragionante', function () {
    assertSame('R', Choice::resolve('R', '', '', 'V', intent(['taskType' => 'code'])));
});

// --- pesante non-codice / leggero ---

test('compito pesante (analysis) usa il ragionante', function () {
    assertSame('R', Choice::resolve('R', 'F', 'C', 'V', intent(['taskType' => 'analysis'])));
});

test('pesante senza ragionante ripiega sul veloce', function () {
    assertSame('F', Choice::resolve('', 'F', 'C', 'V', intent(['taskType' => 'analysis'])));
});

test('contesto grande rende pesante -> ragionante', function () {
    assertSame('R', Choice::resolve('R', 'F', 'C', 'V', intent(['contextSize' => 5])));
});

test('richiesta leggera usa il veloce', function () {
    assertSame('F', Choice::resolve('R', 'F', 'C', 'V', intent()));
});

test('richiesta leggera senza veloce usa il ragionante', function () {
    assertSame('R', Choice::resolve('R', '', 'C', 'V', intent()));
});
