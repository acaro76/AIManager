<?php

declare(strict_types=1);

use App\Core\Code\RepoMap;
use App\Core\Code\RetrievalResult;

// F1.1 — l'esito del recupero deve distinguere file letti da soli trovati, esporre
// metriche/limiti per l'audit ed esibire SOLO percorsi relativi.

$throws = static function (callable $fn): bool {
    try {
        $fn();
        return false;
    } catch (\Throwable $e) {
        return true;
    }
};

$inventory = static function (array $paths = []): RepoMap {
    $files = array_map(static fn (string $p): array => ['path' => $p, 'symbols' => []], $paths);
    return new RepoMap($files, false);
};

test('RetrievalResult: filesConsulted separa i file letti dai soli trovati', function () use ($inventory) {
    $result = new RetrievalResult(
        query: 'dove sta il login',
        inventory: $inventory(['a.php', 'b.php', 'c.php']),
        searchHits: [
            ['path' => 'b.php', 'line' => 10, 'excerpt' => 'function login()'],
            ['path' => 'c.php', 'line' => 3, 'excerpt' => 'login'],
        ],
        readFiles: [
            ['path' => 'b.php', 'content' => "<?php\n", 'bytes' => 6, 'truncated' => false],
        ],
    );

    $consulted = $result->filesConsulted();
    // b.php e' stato letto: sta in 'read' e NON in 'found'
    assertSame(['b.php'], $consulted['read']);
    // c.php e' un hit di RICERCA non letto: e' l'unico 'found'. a.php e' SOLO inventario e
    // NON deve comparire (l'inventario resta a parte via `inventory`).
    assertSame(['c.php'], $consulted['found']);
});

test('RetrievalResult: l\'inventario non confluisce in found', function () use ($inventory) {
    $result = new RetrievalResult(
        query: 'x',
        inventory: $inventory(['a.php', 'b.php', 'c.php', 'd.php']),
        searchHits: [],
        readFiles: [],
    );

    $consulted = $result->filesConsulted();
    assertSame([], $consulted['read']);
    // Nessun hit di ricerca => found vuoto, anche con 4 file a inventario.
    assertSame([], $consulted['found']);
    // L'inventario resta comunque accessibile separatamente.
    assertSame(4, count($result->inventory->files()));
});

test('RetrievalResult: found e read sono ordinati e senza duplicati', function () use ($inventory) {
    $result = new RetrievalResult(
        query: 'x',
        inventory: $inventory(['z.php', 'a.php', 'm.php']),
        searchHits: [
            ['path' => 'z.php', 'line' => 1, 'excerpt' => 'x'],
            ['path' => 'a.php', 'line' => 4, 'excerpt' => 'x'],
            ['path' => 'z.php', 'line' => 9, 'excerpt' => 'x'], // duplicato di path
        ],
        readFiles: [
            ['path' => 'a.php', 'content' => 'x', 'bytes' => 1, 'truncated' => false],
        ],
    );

    $consulted = $result->filesConsulted();
    assertSame(['a.php'], $consulted['read']);
    // z.php una sola volta (dedup), a.php escluso perche' letto, ordinato.
    assertSame(['z.php'], $consulted['found']);
});

test('RetrievalResult: espone metriche e limiti morsi per l\'audit', function () use ($inventory) {
    $result = new RetrievalResult(
        query: 'x',
        inventory: $inventory(['a.php']),
        searchHits: [],
        readFiles: [],
        limitsHit: ['scan', 'read:files', 'scan'], // codici REALI del retrieval
        metrics: ['inventoryFiles' => 42, 'searchMatches' => 0, 'filesRead' => 0, 'readBytes' => 0],
    );

    // limitsHit deduplicato, ordine di prima comparsa
    assertSame(['scan', 'read:files'], $result->limitsHit());
    assertSame(true, $result->anyLimitHit());
    assertSame(42, $result->metrics()['inventoryFiles']);
});

test('RetrievalResult: rifiuta i limiti fuori dal vocabolario LIMIT_CODES', function () use ($inventory, $throws) {
    $bad = static fn (array $limits): callable => static fn () => new RetrievalResult(
        query: 'x', inventory: $inventory([]), limitsHit: $limits
    );

    assertSame(true, $throws($bad(['read'])));               // codice inesistente
    assertSame(true, $throws($bad(['PROMPT-SEGRETO'])));     // stringa arbitraria
    assertSame(true, $throws($bad(['k' => 'scan'])));        // mappa, non lista
    assertSame(true, $throws($bad([['scan']])));             // valore annidato
    assertSame(true, $throws($bad([42])));                   // tipo errato (niente strval)

    // tutti i codici realmente prodotti dal retrieval sono ammessi
    assertSame(false, $throws($bad(RetrievalResult::LIMIT_CODES)));
});

test('RetrievalResult: rifiuta metriche sconosciute, non intere o negative', function () use ($inventory, $throws) {
    $bad = static fn (array $metrics): callable => static fn () => new RetrievalResult(
        query: 'x', inventory: $inventory([]), metrics: $metrics
    );

    assertSame(true, $throws($bad(['promptUtente' => 1])));          // chiave sconosciuta
    assertSame(true, $throws($bad(['filesRead' => 'PROMPT'])));      // valore non intero
    assertSame(true, $throws($bad(['filesRead' => ['x']])));         // valore annidato
    assertSame(true, $throws($bad(['filesRead' => -1])));            // negativo

    // chiavi note con interi non negativi
    assertSame(false, $throws($bad(['filesRead' => 0, 'readBytes' => 10])));
});

test('RetrievalResult: i vocabolari coincidono con cio\' che il retrieval produce davvero', function () {
    // se il motore introducesse un codice/metrica nuovo senza aggiornare il vocabolario, il
    // costruttore lo rifiuterebbe: questi due elenchi sono l'unica fonte di verita'.
    assertSame(true, in_array('revoked', RetrievalResult::LIMIT_CODES, true));
    assertSame(true, in_array('root', RetrievalResult::LIMIT_CODES, true));
    assertSame(true, in_array('search:totalBytes', RetrievalResult::LIMIT_CODES, true));
    assertSame(true, in_array('read:skipped', RetrievalResult::LIMIT_CODES, true));
    assertSame(true, in_array('contentFilesScanned', RetrievalResult::METRIC_KEYS, true));
    assertSame(true, in_array('nameFilesScanned', RetrievalResult::METRIC_KEYS, true));
});

test('RetrievalResult: anyLimitHit e\' falso senza limiti morsi', function () use ($inventory) {
    $result = new RetrievalResult(query: 'x', inventory: $inventory([]));
    assertSame(false, $result->anyLimitHit());
    assertSame([], $result->limitsHit());
});

test('RetrievalResult: accessori restituiscono le liste cosi\' come costruite', function () use ($inventory) {
    $hits = [['path' => 'a.php', 'line' => 1, 'excerpt' => 'foo']];
    $reads = [['path' => 'a.php', 'content' => 'foo', 'bytes' => 3, 'truncated' => true]];
    $result = new RetrievalResult(query: 'x', inventory: $inventory(['a.php']), searchHits: $hits, readFiles: $reads);

    assertSame($hits, $result->searchHits());
    assertSame($reads, $result->readFiles());
});

test('RetrievalResult: rifiuta un percorso assoluto in searchHits', function () use ($inventory, $throws) {
    assertSame(true, $throws(static fn () => new RetrievalResult(
        query: 'x',
        inventory: $inventory([]),
        searchHits: [['path' => '/etc/passwd', 'line' => 1, 'excerpt' => 'root']],
    )));
});

test('RetrievalResult: rifiuta un percorso assoluto in readFiles', function () use ($inventory, $throws) {
    assertSame(true, $throws(static fn () => new RetrievalResult(
        query: 'x',
        inventory: $inventory([]),
        readFiles: [['path' => '/etc/hosts', 'content' => '', 'bytes' => 0, 'truncated' => false]],
    )));
});

test('RetrievalResult: rifiuta un percorso vuoto', function () use ($inventory, $throws) {
    assertSame(true, $throws(static fn () => new RetrievalResult(
        query: 'x',
        inventory: $inventory([]),
        searchHits: [['path' => '', 'line' => 1, 'excerpt' => '']],
    )));
});

test('RetrievalResult: rifiuta traversal Unix nei percorsi', function () use ($inventory, $throws) {
    foreach (['../secret', 'a/../../secret', './a', 'a/./b', 'a//b', '..'] as $bad) {
        assertSame(true, $throws(static fn () => new RetrievalResult(
            query: 'x',
            inventory: $inventory([]),
            searchHits: [['path' => $bad, 'line' => 1, 'excerpt' => 'x']],
        )), "traversal deve fallire: {$bad}");
    }
});

test('RetrievalResult: rifiuta traversal e drive in stile Windows', function () use ($inventory, $throws) {
    foreach (['..\\secret', 'a\\..\\..\\secret', 'C:\\Windows', 'C:/Windows', '\\\\server\\share'] as $bad) {
        assertSame(true, $throws(static fn () => new RetrievalResult(
            query: 'x',
            inventory: $inventory([]),
            searchHits: [['path' => $bad, 'line' => 1, 'excerpt' => 'x']],
        )), "path Windows deve fallire: {$bad}");
    }
});

test('RetrievalResult: il backslash non e\' canonico e viene rifiutato (una sola forma)', function () use ($inventory, $throws) {
    // 'src\file.php' non deve essere ne' accettato ne' normalizzato: darebbe due
    // rappresentazioni dello stesso concetto.
    assertSame(true, $throws(static fn () => new RetrievalResult(
        query: 'x',
        inventory: $inventory([]),
        readFiles: [['path' => 'src\\file.php', 'content' => '', 'bytes' => 0, 'truncated' => false]],
    )));

    // la forma canonica con '/' passa ed e' ESATTAMENTE quella esposta
    $r = new RetrievalResult(
        query: 'x',
        inventory: $inventory(['src/file.php']),
        readFiles: [['path' => 'src/file.php', 'content' => '', 'bytes' => 0, 'truncated' => false]],
    );
    assertSame(['src/file.php'], $r->filesConsulted()['read']);
    assertSame(false, str_contains($r->readFiles()[0]['path'], '\\'));
});

test('RetrievalResult: rifiuta un byte NUL nel percorso', function () use ($inventory, $throws) {
    assertSame(true, $throws(static fn () => new RetrievalResult(
        query: 'x',
        inventory: $inventory([]),
        searchHits: [['path' => "a\0b.php", 'line' => 1, 'excerpt' => 'x']],
    )));
});

test('RetrievalResult: valida anche i percorsi dell\'inventario', function () use ($inventory, $throws) {
    // Un RepoMap costruito male con un path assoluto non deve passare il costruttore.
    assertSame(true, $throws(static fn () => new RetrievalResult(
        query: 'x',
        inventory: $inventory(['/etc/passwd']),
    )));
    // ...ne' con traversal.
    assertSame(true, $throws(static fn () => new RetrievalResult(
        query: 'x',
        inventory: $inventory(['../secret']),
    )));
});
