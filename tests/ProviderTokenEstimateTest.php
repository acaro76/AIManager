<?php

declare(strict_types=1);

use App\Core\Providers\ProviderManager;

// Stima token per il routing (~4 char/token). Il testo primario e' il prompt REALE
// inviato (prompt_for_ai, con il blocco allegati); se assente si usa la richiesta grezza.

test('conta la richiesta quando il prompt e\' vuoto', function () {
    assertSame(1, ProviderManager::requiredContextTokens('', 'ciao', [], []));
});

test('conta il prompt (con allegati) quando piu\' lungo della richiesta', function () {
    // prompt 100 char -> 25 token; la richiesta breve non deve mascherarlo
    assertSame(25, ProviderManager::requiredContextTokens(str_repeat('a', 100), 'ciao', [], []));
});

test('ripiega sulla richiesta quando il prompt e\' piu\' corto', function () {
    assertSame(5, ProviderManager::requiredContextTokens('short', 'diciannove caratt.', [], []));
});

test('somma item e storico', function () {
    assertSame(2, ProviderManager::requiredContextTokens('', 'ab', ['cd', 'ef'], ['gh']));
});

// Regressione dell'audit #1: un allegato grande finiva in prompt_for_ai ma NON veniva
// contato (si misurava solo la richiesta grezza), quindi il routing non penalizzava i
// provider dalla finestra piccola. Ora il prompt reale e' contato.
test('un allegato grande viene conteggiato e fa scattare la penalita\' finestra', function () {
    $tokens = ProviderManager::requiredContextTokens(str_repeat('x', 100000), 'domanda breve', [], []);
    assertSame(25000, $tokens);
    // Con una finestra da 8192 il provider va penalizzato (non scelto per poi troncare).
    assertSame(1000, ProviderManager::windowPenalty(8192, $tokens));
    // Una finestra grande resta senza penalita'.
    assertSame(0, ProviderManager::windowPenalty(262144, $tokens));
});
