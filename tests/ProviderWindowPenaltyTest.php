<?php

declare(strict_types=1);

use App\Core\Providers\ProviderManager;

// Regola: penalita' 1000 se la finestra del provider non contiene i token in gioco
// piu' un margine di 2500 (system + output). Con contextTokens sconosciuto, nessuna.

test('nessuna penalita\' con contextTokens sconosciuto (0)', function () {
    assertSame(0, ProviderManager::windowPenalty(8192, 0));
});

test('nessuna penalita\' se la finestra basta col margine', function () {
    assertSame(0, ProviderManager::windowPenalty(8192, 5000));
});

test('penalita\' se la finestra non basta col margine', function () {
    assertSame(1000, ProviderManager::windowPenalty(8192, 6000));
});

test('al confine esatto (finestra == token + 2500) nessuna penalita\'', function () {
    assertSame(0, ProviderManager::windowPenalty(8192, 5692)); // 5692 + 2500 = 8192
});

test('appena oltre il confine scatta la penalita\'', function () {
    assertSame(1000, ProviderManager::windowPenalty(8192, 5693)); // serve 8193 > 8192
});

test('finestra grande non e\' mai penalizzata', function () {
    assertSame(0, ProviderManager::windowPenalty(262144, 50000));
});

test('contextTokens negativo e\' trattato come sconosciuto', function () {
    assertSame(0, ProviderManager::windowPenalty(8192, -5));
});

test('il pin e\' ammesso quando la finestra contiene il contesto', function () {
    assertSame(true, ProviderManager::canPreserveProvider(8192, 5000));
});

test('il pin non scavalca provider compatibili quando la finestra e\' insufficiente', function () {
    assertSame(false, ProviderManager::canPreserveProvider(8192, 6000));
});
