<?php

declare(strict_types=1);

use App\Services\WebSearchDecision as Decision;

// --- decisioni valide ---

test('parse legge una decisione chat', function () {
    assertSame(
        ['decided' => true, 'action' => 'chat', 'query' => '', 'image_prompt' => ''],
        Decision::parse('{"action":"chat","query":"","image_prompt":""}')
    );
});

test('parse legge web con query', function () {
    $r = Decision::parse('{"action":"web","query":"meteo domani"}');
    assertSame('web', $r['action']);
    assertSame('meteo domani', $r['query']);
    assertSame('', $r['image_prompt']);
});

test('parse legge image con image_prompt', function () {
    $r = Decision::parse('{"action":"image","image_prompt":"a red cat"}');
    assertSame('image', $r['action']);
    assertSame('a red cat', $r['image_prompt']);
    assertSame('', $r['query']);
});

// --- pulizia dell'output del modello ---

test('parse ignora un blocco <think> prima del JSON', function () {
    $r = Decision::parse("<think>ragiono...</think>{\"action\":\"web\",\"query\":\"x\"}");
    assertSame('web', $r['action']);
    assertSame('x', $r['query']);
});

test('parse ignora le fence markdown', function () {
    $r = Decision::parse("```json\n{\"action\":\"chat\"}\n```");
    assertSame('chat', $r['action']);
});

test('parse estrae il JSON da testo attorno', function () {
    $r = Decision::parse('Ecco la decisione: {"action":"web","query":"y"} fine.');
    assertSame('web', $r['action']);
    assertSame('y', $r['query']);
});

test('parse normalizza action con maiuscole e spazi', function () {
    $r = Decision::parse('{"action":" WEB "}');
    assertSame('web', $r['action']);
});

// --- risposte non valide -> null ---

test('parse rifiuta una action sconosciuta', function () {
    assertSame(null, Decision::parse('{"action":"foo"}'));
});

test('parse torna null senza JSON', function () {
    assertSame(null, Decision::parse('nessun json qui'));
});

test('parse torna null se manca la chiave action', function () {
    assertSame(null, Decision::parse('{"query":"x"}'));
});

// Un <think> NON chiuso seguito dal JSON: la decisione si recupera comunque
// (si tolgono solo i tag, non il JSON che segue). Regressione risolta.
test('parse recupera il JSON dopo un <think> non chiuso', function () {
    $r = Decision::parse('<think>bla {"action":"web","query":"z"}');
    assertSame('web', $r['action']);
    assertSame('z', $r['query']);
});

test('parse torna null con <think> non chiuso e senza JSON', function () {
    assertSame(null, Decision::parse('<think>sto ragionando senza chiudere'));
});
