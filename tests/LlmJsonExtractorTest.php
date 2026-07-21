<?php

declare(strict_types=1);

use App\Services\LlmJsonExtractor as X;

test('extractObject legge un oggetto JSON pulito', function () {
    assertSame(['action' => 'chat'], X::extractObject('{"action":"chat"}'));
});

test('extractObject ignora un blocco <think> chiuso', function () {
    assertSame(['a' => 1], X::extractObject('<think>rag</think>{"a":1}'));
});

// Il caso segnalato dall'audit: il parser del Project Brain prima perdeva il JSON
// dopo un <think> NON chiuso; ora la pulizia condivisa lo recupera.
test('extractObject recupera il JSON dopo un <think> non chiuso', function () {
    $in = "<think>ragionamento non chiuso\n{\"items\":[{\"type\":\"knowledge\"}]}";
    assertSame(['items' => [['type' => 'knowledge']]], X::extractObject($in));
});

test('extractObject ignora le fence markdown', function () {
    assertSame(['ok' => true], X::extractObject("```json\n{\"ok\":true}\n```"));
});

test('extractObject estrae il JSON da prosa attorno', function () {
    assertSame(['x' => 'y'], X::extractObject('Ecco: {"x":"y"} fine.'));
});

test('extractObject torna null senza oggetto JSON', function () {
    assertSame(null, X::extractObject('nessun json'));
    assertSame(null, X::extractObject('<think>ragiono senza chiudere e senza json'));
});

test('extractObject salta graffe non JSON prima dell oggetto valido', function () {
    $in = 'ragionamento con esempio {non json} poi {"action":"web","query":"x"}';
    assertSame(['action' => 'web', 'query' => 'x'], X::extractObject($in));
});

test('extractObject rispetta graffe e virgolette escape dentro le stringhe', function () {
    $in = '{"message":"usa {questa} e \\"quella\\"","ok":true}';
    assertSame(['message' => 'usa {questa} e "quella"', 'ok' => true], X::extractObject($in));
});

test('extractObject legge oggetti JSON annidati', function () {
    assertSame(['outer' => ['inner' => 1]], X::extractObject('{"outer":{"inner":1}}'));
});
