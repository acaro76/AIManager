<?php

declare(strict_types=1);

use App\Core\Code\RepoMap;

// F0.3 — RepoMap::toPrompt deve garantire strlen(output) <= budget, anche estremi.

$sample = static function (bool $truncated = false): RepoMap {
    return new RepoMap(
        [
            ['path' => 'a.php', 'symbols' => ['class A', 'function f']],
            ['path' => 'b/c.php', 'symbols' => ['def g']],
        ],
        $truncated
    );
};

test('RepoMap: con budget ampio rende percorsi e simboli', function () use ($sample) {
    $out = $sample()->toPrompt(10000);
    assertSame(true, str_contains($out, 'a.php'));
    assertSame(true, str_contains($out, 'class A'));
    assertSame(true, str_contains($out, 'b/c.php'));
    assertSame(true, str_contains($out, 'def g'));
});

test('RepoMap: budget 0 da\' stringa vuota', function () use ($sample) {
    assertSame('', $sample()->toPrompt(0));
});

test('RepoMap: strlen(output) <= budget per qualsiasi budget', function () use ($sample) {
    foreach ([1, 2, 3, 5, 8, 12, 16, 24, 50, 200, 10000] as $budget) {
        $len = strlen($sample(true)->toPrompt($budget));
        assertSame(true, $len <= $budget, "budget {$budget}: len {$len}");
    }
});

test('RepoMap: una mappa troncata lo segnala quando c\'e\' spazio', function () use ($sample) {
    $out = $sample(true)->toPrompt(10000);
    assertSame(true, str_contains($out, '[mappa troncata]'));
});

test('RepoMap: una mappa non troncata e che entra non mostra il marcatore', function () use ($sample) {
    $out = $sample(false)->toPrompt(10000);
    assertSame(false, str_contains($out, '[mappa troncata]'));
});

test('RepoMap: mappa vuota resta entro budget', function () {
    $out = (new RepoMap([], false))->toPrompt(5);
    assertSame(true, strlen($out) <= 5);
});
