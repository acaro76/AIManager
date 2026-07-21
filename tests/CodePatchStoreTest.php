<?php

declare(strict_types=1);

use App\Core\Code\CodePatch;
use App\Core\Code\CodePatchOp;
use App\Core\Code\CodePatchProposal;
use App\Core\Code\CodePatchStore;

// Fase 4 / F4.5 — deposito locale del payload: roundtrip byte-esatto, diff, cancellazione,
// fallimento pulito su file corrotto.

$tmpBase = static function (): string {
    $base = realpath(sys_get_temp_dir());
    return $base === false ? sys_get_temp_dir() : $base;
};

$rmrf = static function (string $path) use (&$rmrf): void {
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $e) {
            if ($e === '.' || $e === '..') { continue; }
            $rmrf($path . '/' . $e);
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
};

$makePatch = static function (string $newContent): CodePatch {
    $op = new CodePatchOp(
        kind: CodePatchProposal::OP_CREATE,
        path: 'sub/new.txt',
        baseSha256: null,
        edits: [],
        content: $newContent,
        resultSha256: hash('sha256', $newContent),
        newContent: $newContent,
        oldContent: '',
    );
    return new CodePatch([$op]);
};

test('store: write/read preserva i byte esatti del contenuto', function () use ($tmpBase, $rmrf, $makePatch) {
    $base = $tmpBase() . '/aimanager_store_' . uniqid('', true);
    try {
        $store = new CodePatchStore($base);
        // contenuto con UTF-8 e byte "difficili"
        $content = "riga1\nàèìòù €\n\ttab\n";
        $patch = $makePatch($content);
        $store->write('op-store000000001', $patch, [['path' => 'sub/new.txt', 'op' => 'create', 'diff' => "--- a\n+riga1", 'added' => 3, 'removed' => 0]]);
        $read = $store->read('op-store000000001');
        assertSame($content, $read['operations'][0]['new_content']);
        assertSame($patch->digest(), $read['digest']);
        assertSame("--- a\n+riga1", $read['operations'][0]['diff']);
        assertSame('create', $read['operations'][0]['op']);
    } finally {
        $rmrf($base);
    }
});

test('store: delete rimuove il payload', function () use ($tmpBase, $rmrf, $makePatch) {
    $base = $tmpBase() . '/aimanager_store_' . uniqid('', true);
    try {
        $store = new CodePatchStore($base);
        $store->write('op-store000000002', $makePatch('x'), [['path' => 'sub/new.txt', 'op' => 'create', 'diff' => '', 'added' => 1, 'removed' => 0]]);
        assertSame(true, $store->read('op-store000000002') !== null);
        $store->delete('op-store000000002');
        assertSame(null, $store->read('op-store000000002'));
    } finally {
        $rmrf($base);
    }
});

test('store: payload corrotto → read null (fail closed)', function () use ($tmpBase, $rmrf) {
    $base = $tmpBase() . '/aimanager_store_' . uniqid('', true);
    try {
        @mkdir($base . '/payload', 0700, true);
        file_put_contents($base . '/payload/op-store000000003.json', 'non-json{');
        $store = new CodePatchStore($base);
        assertSame(null, $store->read('op-store000000003'));
    } finally {
        $rmrf($base);
    }
});

test('store: read di operazione inesistente → null', function () use ($tmpBase, $rmrf) {
    $base = $tmpBase() . '/aimanager_store_' . uniqid('', true);
    try {
        $store = new CodePatchStore($base);
        assertSame(null, $store->read('op-store000000004'));
    } finally {
        $rmrf($base);
    }
});
