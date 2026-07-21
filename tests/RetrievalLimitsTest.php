<?php

declare(strict_types=1);

use App\Core\Code\RetrievalLimits;

// F1.1 — i limiti del recupero devono essere deterministici, separati e sempre positivi.

$throws = static function (callable $fn): bool {
    try {
        $fn();
        return false;
    } catch (\Throwable $e) {
        return true;
    }
};

$valid = static function (array $overrides = []): RetrievalLimits {
    $args = array_merge([
        'scanMaxDepth' => 12,
        'scanMaxFiles' => 2000,
        'scanMaxReadBytes' => 262144,
        'scanMaxSeconds' => 5.0,
        'searchMaxFilesScanned' => 2000,
        'searchMaxMatches' => 100,
        'searchMaxBytesPerFile' => 262144,
        'searchMaxTotalBytes' => 4194304,
        'searchMaxSeconds' => 5.0,
        'readMaxFiles' => 12,
        'readMaxBytesPerFile' => 65536,
        'readMaxTotalBytes' => 262144,
        'contextMaxChars' => 48000,
    ], $overrides);

    return new RetrievalLimits(...$args);
};

test('RetrievalLimits: defaults sono coerenti e i quattro gruppi indipendenti', function () {
    $l = RetrievalLimits::defaults();
    // scansione
    assertSame(12, $l->scanMaxDepth);
    assertSame(2000, $l->scanMaxFiles);
    assertSame(262144, $l->scanMaxReadBytes);
    assertSame(5.0, $l->scanMaxSeconds);
    // match
    assertSame(2000, $l->searchMaxFilesScanned);
    assertSame(100, $l->searchMaxMatches);
    assertSame(262144, $l->searchMaxBytesPerFile);
    assertSame(4194304, $l->searchMaxTotalBytes);
    assertSame(5.0, $l->searchMaxSeconds);
    // lettura
    assertSame(12, $l->readMaxFiles);
    assertSame(65536, $l->readMaxBytesPerFile);
    assertSame(262144, $l->readMaxTotalBytes);
    // contesto
    assertSame(48000, $l->contextMaxChars);
});

test('RetrievalLimits: i default dello scan coincidono con quelli del RepoScanner', function () {
    // Se un giorno divergono, questo test lo segnala: lo scan del retrieval NON deve
    // introdurre limiti diversi da quelli gia' verificati in F0.3.
    $l = RetrievalLimits::defaults();
    assertSame(12, $l->scanMaxDepth);
    assertSame(2000, $l->scanMaxFiles);
    assertSame(262144, $l->scanMaxReadBytes);
    assertSame(5.0, $l->scanMaxSeconds);
});

test('RetrievalLimits: rifiuta interi <= 0 in ogni gruppo', function () use ($valid, $throws) {
    foreach ([
        'scanMaxDepth', 'scanMaxFiles', 'scanMaxReadBytes',
        'searchMaxFilesScanned', 'searchMaxMatches', 'searchMaxBytesPerFile', 'searchMaxTotalBytes',
        'readMaxFiles', 'readMaxBytesPerFile', 'readMaxTotalBytes',
        'contextMaxChars',
    ] as $field) {
        assertSame(true, $throws(static fn () => $valid([$field => 0])), "{$field}=0 deve fallire");
        assertSame(true, $throws(static fn () => $valid([$field => -1])), "{$field}=-1 deve fallire");
    }
});

test('RetrievalLimits: rifiuta i timeout <= 0', function () use ($valid, $throws) {
    assertSame(true, $throws(static fn () => $valid(['scanMaxSeconds' => 0.0])));
    assertSame(true, $throws(static fn () => $valid(['scanMaxSeconds' => -0.1])));
    assertSame(true, $throws(static fn () => $valid(['searchMaxSeconds' => 0.0])));
    assertSame(true, $throws(static fn () => $valid(['searchMaxSeconds' => -0.1])));
});

test('RetrievalLimits: readMaxTotalBytes non puo\' essere inferiore a readMaxBytesPerFile', function () use ($valid, $throws) {
    assertSame(true, $throws(static fn () => $valid(['readMaxBytesPerFile' => 100000, 'readMaxTotalBytes' => 50000])));
    // uguali e' ammesso (un solo file entra per intero)
    assertSame(false, $throws(static fn () => $valid(['readMaxBytesPerFile' => 50000, 'readMaxTotalBytes' => 50000])));
});

test('RetrievalLimits: searchMaxTotalBytes non puo\' essere inferiore a searchMaxBytesPerFile', function () use ($valid, $throws) {
    assertSame(true, $throws(static fn () => $valid(['searchMaxBytesPerFile' => 100000, 'searchMaxTotalBytes' => 50000])));
    // uguali e' ammesso
    assertSame(false, $throws(static fn () => $valid(['searchMaxBytesPerFile' => 50000, 'searchMaxTotalBytes' => 50000])));
});

test('RetrievalLimits: e\' immutabile (proprieta\' readonly)', function () use ($valid) {
    $l = $valid();
    $failed = false;
    try {
        /** @phpstan-ignore-next-line assegnazione volutamente illegale */
        $l->scanMaxFiles = 1;
    } catch (\Throwable $e) {
        $failed = true;
    }
    assertSame(true, $failed);
});
