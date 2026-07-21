<?php

declare(strict_types=1);

use App\Core\Code\Utf8;

// F1.2 — taglio UTF-8 sicuro: mai byte oltre budget, mai codifica spezzata, byte non validi
// scartati in modo deterministico.

test('Utf8::cut non spezza mai un carattere multibyte su budget di confine', function () {
    $s = 'aàé😀bc'; // a(1) à(2) é(2) 😀(4) b(1) c(1) = 11 byte
    for ($b = 0; $b <= 14; $b++) {
        $out = Utf8::cut($s, $b);
        assertSame(true, strlen($out) <= $b, "budget {$b}: len " . strlen($out));
        assertSame(true, mb_check_encoding($out, 'UTF-8'), "budget {$b}: codifica");
        assertSame(true, json_encode($out) !== false, "budget {$b}: json");
    }
});

test('Utf8::cut a budget 1 su carattere multibyte iniziale restituisce vuoto', function () {
    assertSame('', Utf8::cut('à', 1)); // 'à' e' 2 byte, non entra in 1
    assertSame('à', Utf8::cut('à', 2));
});

test('Utf8::cut scarta byte non validi in modo deterministico', function () {
    $s = "abc\xFF\xFEdef"; // due byte invalidi in mezzo
    $out = Utf8::cut($s, 100);
    assertSame('abcdef', $out);
    assertSame(true, mb_check_encoding($out, 'UTF-8'));
});

test('Utf8::cut scarta una sequenza incompleta a fine stringa', function () {
    $s = "ab\xC3"; // \xC3 e' l'inizio di una 2-byte senza continuazione
    $out = Utf8::cut($s, 100);
    assertSame('ab', $out);
    assertSame(true, mb_check_encoding($out, 'UTF-8'));
});

test('Utf8::clean rimuove i byte non validi senza tagliare per lunghezza', function () {
    $s = "città\xFF però 🚀";
    $out = Utf8::clean($s);
    assertSame(true, mb_check_encoding($out, 'UTF-8'));
    assertSame(true, str_contains($out, 'città'));
    assertSame(true, str_contains($out, '🚀'));
    assertSame(false, str_contains($out, "\xFF"));
});

test('Utf8::cut budget 0 o negativo restituisce vuoto', function () {
    assertSame('', Utf8::cut('àbc', 0));
    assertSame('', Utf8::cut('àbc', -5));
});

test('Utf8::cut rifiuta overlong, surrogati e fuori-range conservando l\'ASCII', function () {
    // ogni sequenza malformata, avvolta da 'A' e 'B': deve restare solo 'AB'
    $cases = [
        "\xC0\x80",         // overlong 2 byte
        "\xC1\xBF",         // overlong 2 byte
        "\xE0\x80\x80",     // overlong 3 byte
        "\xED\xA0\x80",     // surrogato (U+D800)
        "\xED\xBF\xBF",     // surrogato (U+DFFF)
        "\xF0\x80\x80\x80", // overlong 4 byte
        "\xF4\x90\x80\x80", // > U+10FFFF
        "\xF5\x80\x80\x80", // lead fuori-range
    ];
    foreach ($cases as $seq) {
        $out = Utf8::cut('A' . $seq . 'B', 100);
        assertSame('AB', $out, 'seq ' . bin2hex($seq));
        assertSame(true, mb_check_encoding($out, 'UTF-8'), 'codifica ' . bin2hex($seq));
        assertSame(true, json_encode($out) !== false, 'json ' . bin2hex($seq));
    }
});

test('Utf8::cut accetta i confini canonici validi', function () {
    // le controparti valide delle sequenze sopra devono passare intatte
    $valid = [
        "\xC2\x80",         // U+0080 (minimo 2 byte)
        "\xE0\xA0\x80",     // U+0800 (minimo 3 byte)
        "\xED\x9F\xBF",     // U+D7FF (ultimo prima dei surrogati)
        "\xEE\x80\x80",     // U+E000 (primo dopo i surrogati)
        "\xF0\x90\x80\x80", // U+10000 (minimo 4 byte)
        "\xF4\x8F\xBF\xBF", // U+10FFFF (massimo)
    ];
    foreach ($valid as $seq) {
        $out = Utf8::cut('A' . $seq . 'B', 100);
        assertSame('A' . $seq . 'B', $out, 'seq ' . bin2hex($seq));
        assertSame(true, mb_check_encoding($out, 'UTF-8'), 'codifica ' . bin2hex($seq));
    }
});
