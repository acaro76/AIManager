<?php

declare(strict_types=1);

use App\Core\Code\CodePatchLimits;
use App\Core\Code\CodePatchProposal;

// Fase 4 / F4.2 — la proposta grezza (draft) del modello: validazione di FORMA a vocabolario
// chiuso, senza contatto col filesystem. Nessun messaggio d'errore riporta il valore ricevuto.

$limits = CodePatchLimits::defaults();

$parse = static function (array $data) use ($limits): CodePatchProposal {
    return CodePatchProposal::fromActionData($data, $limits);
};

$reason = static function (callable $fn): string {
    try {
        $fn();
        return '';
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
};

test('proposta: update con una modifica esatta', function () use ($parse) {
    $p = $parse(['changes' => [
        ['op' => 'update', 'path' => 'app/Foo.php', 'edits' => [['old' => 'a', 'new' => 'b']]],
    ]]);
    assertSame(1, count($p->operations));
    assertSame('update', $p->operations[0]->kind);
    assertSame('app/Foo.php', $p->operations[0]->path);
    assertSame(false, $p->operations[0]->isCreate());
    assertSame('a', $p->operations[0]->edits[0]['old']);
});

test('proposta: create con contenuto', function () use ($parse) {
    $p = $parse(['changes' => [
        ['op' => 'create', 'path' => 'nuovo.md', 'content' => "# Titolo\n"],
    ]]);
    assertSame('create', $p->operations[0]->kind);
    assertSame(true, $p->operations[0]->isCreate());
    assertSame("# Titolo\n", $p->operations[0]->content);
});

test('proposta: ./ iniziale normalizzato, / finale rimosso', function () use ($parse) {
    $p = $parse(['changes' => [
        ['op' => 'update', 'path' => './app/Bar.php/', 'edits' => [['old' => 'x', 'new' => 'y']]],
    ]]);
    assertSame('app/Bar.php', $p->operations[0]->path);
});

test('proposta: operazione sconosciuta rifiutata (niente delete/rename)', function () use ($reason) {
    foreach (['delete', 'rename', 'chmod', 'mkdir', 'exec'] as $bad) {
        $msg = $reason(fn () => CodePatchProposal::fromActionData(
            ['changes' => [['op' => $bad, 'path' => 'a.php']]],
            CodePatchLimits::defaults()
        ));
        assertSame(true, str_contains($msg, 'update') && str_contains($msg, 'create'), "op {$bad}");
        assertSame(false, str_contains($msg, $bad), "il messaggio non cita l'op ricevuta ({$bad})");
    }
});

test('proposta: percorso con .. rifiutato senza citarlo', function () use ($reason) {
    $msg = $reason(fn () => CodePatchProposal::fromActionData(
        ['changes' => [['op' => 'update', 'path' => '../fuori.php', 'edits' => [['old' => 'a', 'new' => 'b']]]]],
        CodePatchLimits::defaults()
    ));
    assertSame(true, str_contains($msg, 'RELATIVO'));
    // Il messaggio fisso può citare ".." come GUIDA; non deve citare il valore RICEVUTO.
    assertSame(false, str_contains($msg, 'fuori'));
});

test('proposta: percorso assoluto rifiutato', function () use ($reason) {
    $msg = $reason(fn () => CodePatchProposal::fromActionData(
        ['changes' => [['op' => 'create', 'path' => '/etc/passwd', 'content' => 'x']]],
        CodePatchLimits::defaults()
    ));
    assertSame(true, $msg !== '');
    assertSame(false, str_contains($msg, 'passwd'));
});

test('proposta: old vuoto rifiutato', function () use ($reason) {
    $msg = $reason(fn () => CodePatchProposal::fromActionData(
        ['changes' => [['op' => 'update', 'path' => 'a.php', 'edits' => [['old' => '', 'new' => 'b']]]]],
        CodePatchLimits::defaults()
    ));
    assertSame(true, str_contains($msg, 'old'));
});

test('proposta: NUL in old/new/content rifiutato', function () use ($reason) {
    $u = $reason(fn () => CodePatchProposal::fromActionData(
        ['changes' => [['op' => 'update', 'path' => 'a.php', 'edits' => [['old' => "a\0", 'new' => 'b']]]]],
        CodePatchLimits::defaults()
    ));
    $c = $reason(fn () => CodePatchProposal::fromActionData(
        ['changes' => [['op' => 'create', 'path' => 'a.bin', 'content' => "x\0y"]]],
        CodePatchLimits::defaults()
    ));
    assertSame(true, str_contains($u, 'NUL'));
    assertSame(true, str_contains($c, 'NUL'));
});

test('proposta: due operazioni sullo stesso file rifiutate', function () use ($reason) {
    $msg = $reason(fn () => CodePatchProposal::fromActionData(
        ['changes' => [
            ['op' => 'update', 'path' => 'a.php', 'edits' => [['old' => 'a', 'new' => 'b']]],
            ['op' => 'update', 'path' => 'a.php', 'edits' => [['old' => 'c', 'new' => 'd']]],
        ]],
        CodePatchLimits::defaults()
    ));
    assertSame(true, str_contains($msg, 'stesso file'));
});

test('proposta: changes vuoto o non lista rifiutato', function () use ($reason) {
    assertSame(true, $reason(fn () => CodePatchProposal::fromActionData(['changes' => []], CodePatchLimits::defaults())) !== '');
    assertSame(true, $reason(fn () => CodePatchProposal::fromActionData(['changes' => 'x'], CodePatchLimits::defaults())) !== '');
    assertSame(true, $reason(fn () => CodePatchProposal::fromActionData([], CodePatchLimits::defaults())) !== '');
});

test('proposta: troppe operazioni oltre il tetto', function () use ($reason) {
    $small = new CodePatchLimits(maxOperations: 2, maxEditsPerOp: 10, maxFileBytes: 1000, maxTotalBytes: 2000, ttlSeconds: 60);
    $changes = [];
    for ($i = 0; $i < 3; $i++) {
        $changes[] = ['op' => 'create', 'path' => "f{$i}.txt", 'content' => 'x'];
    }
    $msg = $reason(fn () => CodePatchProposal::fromActionData(['changes' => $changes], $small));
    assertSame(true, str_contains($msg, 'Troppe operazioni'));
});
