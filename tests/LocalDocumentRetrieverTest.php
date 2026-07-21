<?php

declare(strict_types=1);

use App\Services\LocalDocumentRetriever;

// embedModel '' => nessun embedding: percorso deterministico "primi pezzi in ordine
// di documento", senza rete. E' il ripiego quando LM Studio embed non e' disponibile.
$retriever = new LocalDocumentRetriever('', '', '');

test('selectRelevant restituisce il documento invariato se ci sta nel budget', function () use ($retriever) {
    $doc = "Riga uno.\n\nRiga due.";
    assertSame($doc, $retriever->selectRelevant($doc, 'domanda', 1000));
});

test('selectRelevant su documento vuoto torna vuoto', function () use ($retriever) {
    assertSame('', $retriever->selectRelevant('', 'domanda', 100));
});

test('selectRelevant con budget zero torna il documento (trimmato)', function () use ($retriever) {
    assertSame('hello', $retriever->selectRelevant('  hello  ', 'domanda', 0));
});

test('selectRelevant taglia il primo pezzo se gia\' oltre il budget', function () use ($retriever) {
    $result = $retriever->selectRelevant(str_repeat('A', 2000), 'domanda', 100);
    assertSame(str_repeat('A', 100), $result);
    assertSame(true, mb_strlen($result) <= 100, 'non deve sforare il budget');
});

test('selectRelevant scarta il pezzo che sforerebbe il budget', function () use ($retriever) {
    $p1 = str_repeat('a', 1600);
    $p2 = str_repeat('b', 1600);
    // budget 1700: entra solo il primo pezzo (1600); il secondo + separatore sforerebbe.
    $result = $retriever->selectRelevant($p1 . "\n\n" . $p2, 'domanda', 1700);
    assertSame($p1, $result);
    assertSame(true, mb_strlen($result) <= 1700, 'non deve sforare il budget');
});
