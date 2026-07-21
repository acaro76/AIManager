<?php

declare(strict_types=1);

use App\Core\Code\CodePatch;
use App\Core\Code\CodePatchOp;
use App\Core\Code\CodePatchProposal;

// Fase 4 / F4.2 — il digest della patch canonica è ricomputabile dai soli METADATI
// (files_json) e non dipende dal testo grezzo delle modifiche: è ancorato agli hash.

$updateOp = static function (string $base, string $result): CodePatchOp {
    return new CodePatchOp(
        kind: CodePatchProposal::OP_UPDATE,
        path: 'app/Foo.php',
        baseSha256: $base,
        edits: [['old' => 'a', 'new' => 'b']],
        content: null,
        resultSha256: $result,
        newContent: 'nuovo',
        oldContent: 'vecchio',
    );
};

test('digest: ricomputabile dai metadati', function () use ($updateOp) {
    $base = hash('sha256', 'vecchio');
    $result = hash('sha256', 'nuovo');
    $patch = new CodePatch([$updateOp($base, $result)]);
    assertSame($patch->digest(), CodePatch::digestFromMetadata($patch->metadata()));
});

test('digest: NON dipende dal testo grezzo delle modifiche (stessi hash → stesso digest)', function () {
    $base = hash('sha256', 'vecchio');
    $result = hash('sha256', 'nuovo');
    $a = new CodePatch([new CodePatchOp('update', 'a.php', $base, [['old' => 'x', 'new' => 'y']], null, $result, 'nuovo', 'vecchio')]);
    $b = new CodePatch([new CodePatchOp('update', 'a.php', $base, [['old' => 'DIVERSO', 'new' => 'ALTRO']], null, $result, 'nuovo', 'vecchio')]);
    assertSame($a->digest(), $b->digest());
});

test('digest: cambia se cambia il risultato', function () {
    $base = hash('sha256', 'vecchio');
    $a = new CodePatch([new CodePatchOp('update', 'a.php', $base, [['old' => 'x', 'new' => 'y']], null, hash('sha256', 'r1'), 'r1', 'vecchio')]);
    $b = new CodePatch([new CodePatchOp('update', 'a.php', $base, [['old' => 'x', 'new' => 'y']], null, hash('sha256', 'r2'), 'r2', 'vecchio')]);
    assertSame(true, $a->digest() !== $b->digest());
});

test('digest: create ricomputabile dai metadati (base null)', function () {
    $result = hash('sha256', "ciao\n");
    $patch = new CodePatch([new CodePatchOp('create', 'nuovo.txt', null, [], "ciao\n", $result, "ciao\n", '')]);
    assertSame($patch->digest(), CodePatch::digestFromMetadata($patch->metadata()));
});
