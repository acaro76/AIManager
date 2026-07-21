<?php

declare(strict_types=1);

use App\Core\Code\CodePatchJournal;

// Fase 4 / F4.7 — journal WAL: prepare/read/commit/discard, preimage byte-esatti, scoping per
// workspace dei journal non committed (recovery).

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

$entries = static function (): array {
    return [
        ['op' => 'update', 'rel_path' => 'a.php', 'base_sha256' => hash('sha256', "old\0bytes"), 'result_sha256' => str_repeat('b', 64), 'preimage' => "old\0bytes", 'mode' => 0755],
        ['op' => 'create', 'rel_path' => 'b.php', 'base_sha256' => null, 'result_sha256' => str_repeat('c', 64), 'preimage' => null, 'mode' => 0644],
    ];
};

test('journal: prepare/read preserva preimage byte-esatti (anche NUL), fase prepared', function () use ($tmpBase, $rmrf, $entries) {
    $base = $tmpBase() . '/aimanager_journal_' . uniqid('', true);
    try {
        $j = new CodePatchJournal($base);
        $j->prepare('op-journal000001', 7, $entries());
        $rec = $j->read('op-journal000001');
        assertSame(CodePatchJournal::PHASE_PREPARED, $rec['phase']);
        assertSame(7, $rec['workspace_id']);
        assertSame("old\0bytes", $rec['entries'][0]['preimage']);
        assertSame(null, $rec['entries'][1]['preimage']);
    } finally {
        $rmrf($base);
    }
});

test('journal: markApplied → committed, markRollingBack → rolling_back, entries intatti', function () use ($tmpBase, $rmrf, $entries) {
    $base = $tmpBase() . '/aimanager_journal_' . uniqid('', true);
    try {
        $j = new CodePatchJournal($base);
        $j->prepare('op-journal000002', 3, $entries());
        $j->markApplied('op-journal000002');
        assertSame(CodePatchJournal::PHASE_COMMITTED, $j->read('op-journal000002')['phase']);
        $j->markRollingBack('op-journal000002');
        $rec = $j->read('op-journal000002');
        assertSame(CodePatchJournal::PHASE_ROLLING_BACK, $rec['phase']);
        assertSame("old\0bytes", $rec['entries'][0]['preimage']);
    } finally {
        $rmrf($base);
    }
});

test('journal: pendingForWorkspace elenca prepared e rolling_back, non committed, dello stesso ws', function () use ($tmpBase, $rmrf, $entries) {
    $base = $tmpBase() . '/aimanager_journal_' . uniqid('', true);
    try {
        $j = new CodePatchJournal($base);
        $j->prepare('op-journal000010', 1, $entries()); // ws1, prepared
        $j->prepare('op-journal000011', 1, $entries()); // ws1, poi committed
        $j->markApplied('op-journal000011');
        $j->prepare('op-journal000012', 1, $entries()); // ws1, poi rolling_back
        $j->markApplied('op-journal000012');
        $j->markRollingBack('op-journal000012');
        $j->prepare('op-journal000013', 2, $entries()); // ws2, prepared
        $pending = $j->pendingForWorkspace(1);
        $ids = array_map(static fn (array $p): string => $p['operation_id'], $pending);
        assertSame(['op-journal000010', 'op-journal000012'], $ids);
    } finally {
        $rmrf($base);
    }
});

test('journal: discard rimuove il journal', function () use ($tmpBase, $rmrf, $entries) {
    $base = $tmpBase() . '/aimanager_journal_' . uniqid('', true);
    try {
        $j = new CodePatchJournal($base);
        $j->prepare('op-journal000020', 1, $entries());
        assertSame(true, $j->exists('op-journal000020'));
        $j->discard('op-journal000020');
        assertSame(false, $j->exists('op-journal000020'));
        assertSame(null, $j->read('op-journal000020'));
    } finally {
        $rmrf($base);
    }
});

test('journal: mode preservato nel round-trip (0755 update, 0644 create)', function () use ($tmpBase, $rmrf, $entries) {
    $base = $tmpBase() . '/aimanager_journal_' . uniqid('', true);
    try {
        $j = new CodePatchJournal($base);
        $j->prepare('op-journal000030', 1, $entries());
        $rec = $j->read('op-journal000030');
        assertSame(0755, $rec['entries'][0]['mode']);
        assertSame(0644, $rec['entries'][1]['mode']);
        // Dopo markApplied il mode deve restare identico
        $j->markApplied('op-journal000030');
        $rec2 = $j->read('op-journal000030');
        assertSame(0755, $rec2['entries'][0]['mode']);
        assertSame(0644, $rec2['entries'][1]['mode']);
    } finally {
        $rmrf($base);
    }
});

test('journal: mode assente nel JSON di un journal legacy → fallback 0644', function () use ($tmpBase, $rmrf) {
    $base = $tmpBase() . '/aimanager_journal_' . uniqid('', true);
    try {
        // Simula un journal scritto PRIMA dell'introduzione del campo mode:
        // salva manualmente un JSON senza il campo 'mode' nelle entry.
        $dir = $base . '/journal';
        @mkdir($dir, 0700, true);
        $legacy = [
            'operation_id' => 'op-journal000031',
            'workspace_id' => 1,
            'phase' => 'prepared',
            'created_at' => date('c'),
            'entries' => [
                ['op' => 'update', 'rel_path' => 'x.php', 'base_sha256' => str_repeat('a', 64), 'result_sha256' => str_repeat('b', 64), 'preimage_b64' => base64_encode('old')],
            ],
        ];
        file_put_contents($dir . '/op-journal000031.json', json_encode($legacy));
        $j = new CodePatchJournal($base);
        $rec = $j->read('op-journal000031');
        assertSame(0644, $rec['entries'][0]['mode']); // fallback
    } finally {
        $rmrf($base);
    }
});
