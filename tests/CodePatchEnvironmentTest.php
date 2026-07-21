<?php

declare(strict_types=1);

use App\Core\Code\CodeChatSchema;
use App\Core\Code\CodeWorkspace;
use App\Core\Code\SensitivePathPolicy;

// Fase 4 / F4.E — wiring di route/controller e rendering della card della proposta nel flusso chat.
// Viste rese direttamente (senza boot/DB reale); route verificate per ispezione statica.

$policy = new SensitivePathPolicy();

$renderView = static function (string $relView, array $vars): string {
    extract($vars, EXTR_SKIP);
    ob_start();
    require dirname(__DIR__) . '/app/Views/' . $relView;
    return (string) ob_get_clean();
};

$ws = static fn (int $id, string $root, string $name, string $status): CodeWorkspace
    => new CodeWorkspace($id, $root, $name, $status, $policy);

$baseVars = static function (CodeWorkspace $workspace, array $history, array $patchProposals): array {
    return [
        'csrf' => 'TOK',
        'workspace' => $workspace,
        'chatState' => CodeChatSchema::STATE_READY,
        'session' => ['id' => 10, 'status' => 'active', 'title' => 'sessione'],
        'history' => $history,
        'historyEvidence' => [],
        'patchProposals' => $patchProposals,
        'accessRevoked' => false,
        'codeProviderMode' => 'auto',
    ];
};

$sampleCard = static function (string $status): array {
    return [
        'operation_id' => 'op-abcdef0123456789',
        'patch_digest' => str_repeat('a', 64),
        'status' => $status,
        'files' => [
            ['path' => 'app/Config.php', 'op' => 'update', 'added' => 1, 'removed' => 1, 'diff' => "--- a/app/Config.php\n+riga nuova"],
        ],
    ];
};

test('route: apply/complete/reject/rollback registrate e mappate al CodeController', function () {
    $index = file_get_contents(dirname(__DIR__) . '/public/index.php');
    foreach (['/code/patch/apply', '/code/patch/complete', '/code/patch/reject', '/code/patch/rollback'] as $route) {
        assertSame(true, str_contains($index, "'" . $route . "'"), $route);
    }
    assertSame(true, str_contains($index, "[CodeController::class, 'applyPatch']"));
    assertSame(true, str_contains($index, "[CodeController::class, 'completeAppliedPatch']"));
    assertSame(true, str_contains($index, "[CodeController::class, 'rejectPatch']"));
    assertSame(true, str_contains($index, "[CodeController::class, 'rollbackPatch']"));
});

test('controller: i metodi patch esistono', function () {
    foreach (['applyPatch', 'completeAppliedPatch', 'rejectPatch', 'rollbackPatch'] as $m) {
        assertSame(true, method_exists(\App\Controllers\CodeController::class, $m), $m);
    }
});

test('card proposta: mostra riepilogo, diff e azioni Applica/Rifiuta', function () use ($renderView, $ws, $baseVars, $sampleCard) {
    $history = [['id' => 5, 'role' => 'assistant', 'content' => 'Propongo una modifica.', 'provider' => 'lmstudio']];
    $html = $renderView('code/_chat.php', $baseVars($ws(1, '/tmp/p', 'P', 'active'), $history, [5 => [$sampleCard('proposed')]]));

    assertSame(true, str_contains($html, 'data-code-patch'));
    assertSame(true, str_contains($html, 'op-abcdef0123456789'));
    assertSame(true, str_contains($html, 'Proposta di modifica'));
    assertSame(true, str_contains($html, 'app/Config.php'));
    assertSame(true, str_contains($html, 'data-code-patch-apply'));
    assertSame(true, str_contains($html, 'data-code-patch-reject'));
    assertSame(true, str_contains($html, 'class="code-patch-file-head"'));
    assertSame(false, str_contains($html, 'code-patch-op'));
    assertSame(true, str_contains($html, 'riga nuova'));
});

test('modifica applicata: resta solo il riepilogo compatto, senza diff o azioni', function () use ($renderView, $ws, $baseVars, $sampleCard) {
    $history = [['id' => 5, 'role' => 'assistant', 'content' => 'Fatto.', 'provider' => 'lmstudio']];
    $html = $renderView('code/_chat.php', $baseVars($ws(1, '/tmp/p', 'P', 'active'), $history, [5 => [$sampleCard('applied')]]));

    assertSame(true, str_contains($html, 'data-code-patch-history'));
    assertSame(true, str_contains($html, 'data-code-patch-completion'));
    assertSame(true, str_contains($html, 'data-operation-id="op-abcdef0123456789"'));
    assertSame(false, str_contains($html, 'data-code-patch-rollback'));
    assertSame(false, str_contains($html, 'data-code-patch-apply'));
    assertSame(false, str_contains($html, 'data-code-patch-reject'));
    assertSame(true, str_contains($html, 'Modifica applicata'));
    assertSame(true, str_contains($html, 'app/Config.php'));
    assertSame(false, str_contains($html, '>Fatto.<'));
    assertSame(false, str_contains($html, 'riga nuova'));
});

test('modifica rifiutata: resta il riepilogo compatto persistente, senza diff o azioni', function () use ($renderView, $ws, $baseVars, $sampleCard) {
    $history = [['id' => 5, 'role' => 'assistant', 'content' => 'Proposta.', 'provider' => 'lmstudio']];
    $html = $renderView('code/_chat.php', $baseVars($ws(1, '/tmp/p', 'P', 'active'), $history, [5 => [$sampleCard('rejected')]]));

    assertSame(true, str_contains($html, 'Modifica rifiutata'));
    assertSame(true, str_contains($html, 'app/Config.php'));
    assertSame(false, str_contains($html, 'data-code-patch-apply'));
    assertSame(false, str_contains($html, 'data-code-patch-reject'));
    assertSame(false, str_contains($html, 'riga nuova'));
});

test('card: assente quando non ci sono proposte per il turno', function () use ($renderView, $ws, $baseVars) {
    $history = [['id' => 5, 'role' => 'assistant', 'content' => 'Solo testo.', 'provider' => 'lmstudio']];
    $html = $renderView('code/_chat.php', $baseVars($ws(1, '/tmp/p', 'P', 'active'), $history, []));
    assertSame(false, str_contains($html, 'data-code-patch'));
});

test('diff nella card è escapato (nessuna iniezione HTML dal contenuto del file)', function () use ($renderView, $ws, $baseVars) {
    $card = [
        'operation_id' => 'op-abcdef0123456789',
        'patch_digest' => str_repeat('b', 64),
        'status' => 'proposed',
        'files' => [['path' => 'x.html', 'op' => 'create', 'added' => 1, 'removed' => 0, 'diff' => "+<script>alert(1)</script>"]],
    ];
    $history = [['id' => 5, 'role' => 'assistant', 'content' => 'c', 'provider' => 'p']];
    $html = $renderView('code/_chat.php', $baseVars($ws(1, '/tmp/p', 'P', 'active'), $history, [5 => [$card]]));
    assertSame(false, str_contains($html, '<script>alert(1)</script>'));
    assertSame(true, str_contains($html, '&lt;script&gt;'));
});
