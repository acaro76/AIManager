<?php

declare(strict_types=1);

use App\Core\Code\UnifiedDiff;

// Fase 4 / F4.3 — diff unificato: vista derivata, sola visualizzazione.

test('diff: nessuna differenza → stringa vuota', function () {
    assertSame('', UnifiedDiff::render('a.txt', "uguale\n", "uguale\n"));
});

test('diff: header a/ b/ presente', function () {
    $d = UnifiedDiff::render('app/Foo.php', "riga1\n", "riga1 modificata\n");
    assertSame(true, str_contains($d, '--- a/app/Foo.php'));
    assertSame(true, str_contains($d, '+++ b/app/Foo.php'));
});

test('diff: riga cambiata mostra - e +', function () {
    $d = UnifiedDiff::render('a.txt', "alpha\nbeta\ngamma\n", "alpha\nBETA\ngamma\n");
    assertSame(true, str_contains($d, "-beta"));
    assertSame(true, str_contains($d, "+BETA"));
    // le righe di contesto restano
    assertSame(true, str_contains($d, " alpha"));
    assertSame(true, str_contains($d, " gamma"));
});

test('diff: creazione (old vuoto) → tutte righe aggiunte', function () {
    $d = UnifiedDiff::render('nuovo.txt', '', "a\nb\n");
    assertSame(true, str_contains($d, "+a"));
    assertSame(true, str_contains($d, "+b"));
    assertSame(false, str_contains($d, "\n-"));
});

test('diff: stat conta aggiunte e rimosse', function () {
    $stat = UnifiedDiff::stat("uno\ndue\ntre\n", "uno\nDUE\ntre\nquattro\n");
    assertSame(1, $stat['removed']); // due
    assertSame(2, $stat['added']);   // DUE + quattro
});

test('diff: hunk header ha il formato @@ -x,y +a,b @@', function () {
    $d = UnifiedDiff::render('a.txt', "l1\nl2\nl3\nl4\nl5\n", "l1\nl2\nX\nl4\nl5\n");
    assertSame(true, (bool) preg_match('/@@ -\d+,\d+ \+\d+,\d+ @@/', $d));
});

test('diff: solo aggiunta in coda non tocca il resto', function () {
    $d = UnifiedDiff::render('a.txt', "a\nb\n", "a\nb\nc\n");
    assertSame(true, str_contains($d, "+c"));
    assertSame(false, str_contains($d, "-a"));
    assertSame(false, str_contains($d, "-b"));
});
