<?php

declare(strict_types=1);

use App\Core\Code\CodeAgentAction;
use App\Core\Code\CodeAgentLimits;
use App\Core\Code\CodeAgentLoop;
use App\Core\Code\CodeAgentTools;
use App\Core\Code\CodePatchLimits;
use App\Core\Code\CodeWorkspace;
use App\Core\Code\RetrievalLimits;
use App\Core\Code\SensitivePathPolicy;

// Fase 4 / F4.D — l'azione `propose_patch` nel vocabolario dell'agente e nel ciclo: ammessa SOLO
// con la scrittura abilitata, TERMINALE, e senza toccare alcun file (produce solo una proposta).

$rmrf = static function (string $path) use (&$rmrf): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $e) {
            if ($e === '.' || $e === '..') { continue; }
            $rmrf($path . '/' . $e);
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
};

$mkroot = static function (): string {
    $base = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
    $root = $base . '/aimanager_patchagent_' . uniqid('', true);
    mkdir($root, 0777, true);
    file_put_contents($root . '/a.php', "<?php\n\$x = 1;\n");
    return $root;
};

$ws = static fn (string $root): CodeWorkspace
    => new CodeWorkspace(1, $root, basename($root), 'active', new SensitivePathPolicy());

$reason = static function (callable $fn): string {
    try { $fn(); return ''; } catch (\Throwable $e) { return $e->getMessage(); }
};

$proposeRaw = '{"action":"propose_patch","changes":[{"op":"create","path":"nuovo.txt","content":"ciao"}]}';

test('azione: propose_patch NON ammessa se la scrittura è disabilitata', function () use ($reason, $proposeRaw) {
    $msg = $reason(fn () => CodeAgentAction::parse($proposeRaw, CodeAgentLimits::defaults(), false));
    assertSame(true, str_contains($msg, 'Azione sconosciuta'));
    assertSame(false, str_contains($msg, 'propose_patch'));
});

test('azione: propose_patch ammessa se la scrittura è abilitata, isProposal true', function () use ($proposeRaw) {
    $action = CodeAgentAction::parse($proposeRaw, CodeAgentLimits::defaults(), true, CodePatchLimits::defaults());
    assertSame(true, $action->isProposal());
    assertSame(false, $action->isAnswer());
    assertSame(1, count($action->proposal->operations));
    assertSame('create', $action->proposal->operations[0]->kind);
});

test('azione: propose_patch con operazione non valida rifiutata', function () use ($reason) {
    $raw = '{"action":"propose_patch","changes":[{"op":"delete","path":"a.php"}]}';
    $msg = $reason(fn () => CodeAgentAction::parse($raw, CodeAgentLimits::defaults(), true, CodePatchLimits::defaults()));
    assertSame(true, $msg !== '');
});

test('azione: propose_file accetta percorso e contenuto finale entro i limiti', function () {
    $raw = '{"action":"propose_file","path":"config.php","content":"<?php\\nreturn [];\\n"}';
    $action = CodeAgentAction::parse($raw, CodeAgentLimits::defaults(), true, CodePatchLimits::defaults());
    assertSame(true, $action->isWholeFileProposal());
    assertSame('config.php', $action->wholeFile['path']);
});

$loop = static function (callable $decider, bool $writeEnabled): CodeAgentLoop {
    $limits = RetrievalLimits::defaults();
    $agent = CodeAgentLimits::defaults();
    return new CodeAgentLoop(
        limits: $limits,
        agentLimits: $agent,
        tools: new CodeAgentTools($limits, $agent),
        decider: $decider,
        writeEnabled: $writeEnabled,
        patchLimits: CodePatchLimits::defaults(),
    );
};

test('ciclo: propose_patch è TERMINALE e porta la proposta, nessun file toccato', function () use ($mkroot, $rmrf, $ws, $loop, $proposeRaw) {
    $root = $mkroot();
    try {
        $decider = static fn (string $s, string $u): string => $proposeRaw;
        $outcome = $loop($decider, true)->run($ws($root), 'crea un file');
        assertSame('proposal', $outcome->stopReason);
        assertSame(true, $outcome->hasProposal());
        assertSame(1, count($outcome->proposal->operations));
        assertSame(1, $outcome->iterations);
        // nessun file creato: era solo una proposta
        assertSame(false, file_exists($root . '/nuovo.txt'));
    } finally {
        $rmrf($root);
    }
});

test('protocollo: per un file nuovo ordina di proporlo direttamente senza leggerlo', function () use ($mkroot, $rmrf, $ws, $loop) {
    $root = $mkroot();
    try {
        $system = '';
        $decider = static function (string $s) use (&$system): string {
            $system = $s;
            return '{"action":"propose_file","path":"app.py","content":"print(1)\\n"}';
        };
        $outcome = $loop($decider, true)->run($ws($root), 'crea app.py', '', true);
        assertSame('proposal', $outcome->stopReason);
        assertSame('create', $outcome->proposal->operations[0]->kind);
        assertSame(true, str_contains($system, 'NON provare a leggerlo'));
    } finally {
        $rmrf($root);
    }
});

test('ciclo: una richiesta di modifica non può terminare con answer e prosegue fino alla proposta', function () use ($mkroot, $rmrf, $ws, $loop, $proposeRaw) {
    $root = $mkroot();
    try {
        $calls = 0;
        $decider = static function (string $s, string $u) use (&$calls, $proposeRaw): string {
            $calls++;
            return $calls === 1 ? '{"action":"answer"}' : $proposeRaw;
        };
        $outcome = $loop($decider, true)->run($ws($root), 'aggiungi una riga', '', true);
        assertSame('proposal', $outcome->stopReason);
        assertSame(true, $outcome->hasProposal());
        assertSame(2, $outcome->iterations);
    } finally {
        $rmrf($root);
    }
});

test('ciclo: propose_file converte il contenuto finale in una proposta update canonica', function () use ($mkroot, $rmrf, $ws, $loop) {
    $root = $mkroot();
    try {
        $calls = 0;
        $decider = static function () use (&$calls): string {
            $calls++;
            return $calls === 1
                ? '{"action":"read_file","path":"a.php"}'
                : '{"action":"propose_file","path":"a.php","content":"<?php\\n$x = 2;\\n"}';
        };
        $outcome = $loop($decider, true)->run($ws($root), 'modifica config.php', '', true);
        assertSame('proposal', $outcome->stopReason);
        assertSame(true, $outcome->hasProposal());
        assertSame('update', $outcome->proposal->operations[0]->kind);
        assertSame('a.php', $outcome->proposal->operations[0]->path);
    } finally {
        $rmrf($root);
    }
});

test('ciclo: con scrittura disabilitata propose_patch è output invalido → non è una proposta', function () use ($mkroot, $rmrf, $ws, $loop, $proposeRaw) {
    $root = $mkroot();
    try {
        $decider = static fn (string $s, string $u): string => $proposeRaw;
        $outcome = $loop($decider, false)->run($ws($root), 'crea un file');
        assertSame(false, $outcome->hasProposal());
        assertSame('invalid', $outcome->stopReason);
    } finally {
        $rmrf($root);
    }
});
