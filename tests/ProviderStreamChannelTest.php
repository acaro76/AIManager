<?php

declare(strict_types=1);

use App\Core\Providers\ProviderManager;

test('contenuto e reasoning appartengono al singolo tentativo provider', function () {
    assertSame(true, ProviderManager::isAttemptOutputChannel('content'));
    assertSame(true, ProviderManager::isAttemptOutputChannel('reasoning'));
});

test('fonti e reset non appartengono al singolo tentativo provider', function () {
    assertSame(false, ProviderManager::isAttemptOutputChannel('sources'));
    assertSame(false, ProviderManager::isAttemptOutputChannel('reset'));
});
