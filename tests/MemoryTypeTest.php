<?php

declare(strict_types=1);

use App\Domain\Memory\MemoryType;

test('normalize conserva un tipo conosciuto', function () {
    assertSame('knowledge', MemoryType::normalize('knowledge'));
    assertSame('todo', MemoryType::normalize('todo'));
    assertSame('database_table', MemoryType::normalize('database_table'));
});

test('normalize ripiega su note per un tipo sconosciuto', function () {
    assertSame('note', MemoryType::normalize('sconosciuto'));
    assertSame('note', MemoryType::normalize(''));
});

test('normalize e\' case-sensitive (Knowledge non e\' knowledge)', function () {
    assertSame('note', MemoryType::normalize('Knowledge'));
});

test('all elenca tutti i 15 tipi con etichetta', function () {
    $all = MemoryType::all();
    assertSame(15, count($all));
    assertSame('Knowledge', $all['knowledge'] ?? null);
    assertSame('TODO', $all['todo'] ?? null);
});
