<?php

declare(strict_types=1);

use App\Core\Code\FileNameSearch;
use App\Core\Code\RetrievalLimits;

// F1.2 — ricerca per nome: matcher deterministico che rispetta i limiti search*.

$limits = static function (array $o = []): RetrievalLimits {
    $a = array_merge([
        'scanMaxDepth' => 12, 'scanMaxFiles' => 2000, 'scanMaxReadBytes' => 262144, 'scanMaxSeconds' => 5.0,
        'searchMaxFilesScanned' => 2000, 'searchMaxMatches' => 100, 'searchMaxBytesPerFile' => 262144,
        'searchMaxTotalBytes' => 4194304, 'searchMaxSeconds' => 5.0,
        'readMaxFiles' => 12, 'readMaxBytesPerFile' => 65536, 'readMaxTotalBytes' => 262144, 'contextMaxChars' => 48000,
    ], $o);
    return new RetrievalLimits(...$a);
};

$s = new FileNameSearch();

test('FileNameSearch: trova i percorsi che contengono un token', function () use ($s, $limits) {
    $res = $s->search(['app/Auth/Login.php', 'app/Home.php', 'lib/login_helper.js'], ['login'], $limits());
    $paths = array_map(static fn (array $h): string => $h['path'], $res['hits']);
    assertSame(['app/Auth/Login.php', 'lib/login_helper.js'], $paths);
    assertSame(false, $res['truncated']);
});

test('FileNameSearch: match case-insensitive e con line=0', function () use ($s, $limits) {
    $res = $s->search(['src/UserController.php'], ['usercontroller'], $limits());
    assertSame(1, count($res['hits']));
    assertSame(0, $res['hits'][0]['line']);
});

test('FileNameSearch: risultato ordinato per path indipendentemente dall\'input', function () use ($s, $limits) {
    $res = $s->search(['z/a.php', 'a/z.php', 'm/m.php'], ['php'], $limits());
    $paths = array_map(static fn (array $h): string => $h['path'], $res['hits']);
    assertSame(['a/z.php', 'm/m.php', 'z/a.php'], $paths);
});

test('FileNameSearch: OR tra token, un percorso conta una sola volta', function () use ($s, $limits) {
    $res = $s->search(['app/login_user.php'], ['login', 'user'], $limits());
    assertSame(1, count($res['hits']));
});

test('FileNameSearch: rispetta searchMaxMatches e segnala truncated', function () use ($s, $limits) {
    $res = $s->search(['a.php', 'b.php', 'c.php'], ['php'], $limits(['searchMaxMatches' => 2]));
    assertSame(2, count($res['hits']));
    assertSame(true, $res['truncated']);
    assertSame(true, in_array('search:matches', $res['limitsHit'], true));
    assertSame(['a.php', 'b.php'], array_map(static fn (array $h): string => $h['path'], $res['hits']));
});

test('FileNameSearch: rispetta searchMaxFilesScanned', function () use ($s, $limits) {
    // con 2 file esaminabili non arriva al terzo, che pure conterrebbe il token
    $res = $s->search(['a.php', 'b.php', 'c.php'], ['php'], $limits(['searchMaxFilesScanned' => 2]));
    assertSame(2, $res['filesScanned']);
    assertSame(true, in_array('search:files', $res['limitsHit'], true));
});

test('FileNameSearch: rispetta searchMaxSeconds durante l\'iterazione', function () use ($limits) {
    $n = 0;
    $clock = static function () use (&$n): float { $n++; return (float) $n; };
    $s = new FileNameSearch($clock);
    $res = $s->search(['a.php', 'b.php', 'c.php', 'd.php'], ['php'], $limits(['searchMaxSeconds' => 1.5]));
    assertSame(true, in_array('search:time', $res['limitsHit'], true));
    assertSame(true, $res['filesScanned'] < 4);
});

test('FileNameSearch: senza token non restituisce nulla', function () use ($s, $limits) {
    assertSame([], $s->search(['a.php'], [], $limits())['hits']);
});
