<?php

declare(strict_types=1);

use App\Core\Code\CodeAgentLimits;
use App\Core\Code\CodeAgentLoop;
use App\Core\Code\CodeAgentTools;
use App\Core\Code\CodeCommandTool;
use App\Core\Code\CodeAgentAction;
use App\Core\Code\CommandPlan;
use App\Core\Code\CommandProgramResolver;
use App\Core\Code\CommandRunner;
use App\Core\Code\CodeWorkspace;
use App\Core\Code\RetrievalLimits;
use App\Core\Code\SensitivePathPolicy;

// Fase 6 — il CICLO con run_command: una proposta di comando ammissibile è TERMINALE e porta il
// piano; un comando non ammesso (git/interprete/traversal) torna come DATO e il modello può
// correggersi. Offensivo: interpreti/package manager/git NON sono nel registro → sempre negati.

$rmrf = static function (string $path) use (&$rmrf): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $e) { if ($e === '.' || $e === '..') { continue; } $rmrf($path . '/' . $e); }
        @rmdir($path);
        return;
    }
    @unlink($path);
};
$mkroot = static function (): string {
    $base = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
    $root = $base . '/aim_agcmd_' . uniqid('', true);
    mkdir($root . '/app', 0777, true);
    file_put_contents($root . '/app/Foo.php', "<?php\n// hello needle\n");
    return $root;
};
$ws = static fn (string $root): CodeWorkspace => new CodeWorkspace(1, $root, basename($root), 'active', new SensitivePathPolicy());
$scripted = static function (array $script): callable {
    $i = 0;
    return static function () use ($script, &$i): string { return (string) ($script[$i++] ?? '{"action":"answer"}'); };
};
$loop = static function (callable $decider, ?CodeCommandTool $tool): CodeAgentLoop {
    $limits = RetrievalLimits::defaults();
    $agent = CodeAgentLimits::defaults();
    return new CodeAgentLoop(
        limits: $limits,
        agentLimits: $agent,
        tools: new CodeAgentTools($limits, $agent),
        decider: $decider,
        commandsEnabled: $tool !== null,
        commands: $tool,
    );
};

// Precondizione dei test di esecuzione/disponibilità: process group + cat risolvibile in bin fidata.
$available = CommandRunner::supportsProcessGroupIsolation()
    && (new CommandProgramResolver())->resolve('cat') !== null;

test('parse: run_command entra nel vocabolario SOLO se abilitato', function () {
    $json = '{"action":"run_command","program":"cat","args":["app/Foo.php"]}';
    $threw = false;
    try {
        CodeAgentAction::parse($json, CodeAgentLimits::defaults()); // commandsEnabled = false
    } catch (\InvalidArgumentException) { $threw = true; }
    assertSame(true, $threw);

    $action = CodeAgentAction::parse($json, CodeAgentLimits::defaults(), false, null, false, true);
    assertSame(true, $action->isRunCommand());
    assertSame('cat', $action->commandProgram);
    assertSame(['app/Foo.php'], $action->commandArgs);
});

test('parse: program malformato / args non stringa → rifiutati', function () {
    foreach ([
        '{"action":"run_command","program":"../evil","args":[]}',
        '{"action":"run_command","program":"cat","args":[1,2]}',
        '{"action":"run_command","args":["x"]}',
    ] as $bad) {
        $threw = false;
        try { CodeAgentAction::parse($bad, CodeAgentLimits::defaults(), false, null, false, true); }
        catch (\InvalidArgumentException) { $threw = true; }
        assertSame(true, $threw, $bad);
    }
});

if (!$available) {
    test('CodeAgentCommand: ambiente senza process group / cat (skip esecuzione)', function () {
        assertSame(true, true);
    });
    return;
}

test('ciclo: run_command ammissibile è TERMINALE e porta il piano', function () use ($mkroot, $ws, $rmrf, $scripted, $loop) {
    $root = $mkroot();
    try {
        $decider = $scripted(['{"action":"run_command","program":"cat","args":["app/Foo.php"]}']);
        $outcome = $loop($decider, new CodeCommandTool())->run($ws($root), 'mostra Foo');
        assertSame('command', $outcome->stopReason);
        assertSame(true, $outcome->hasCommandProposal());
        assertSame(true, $outcome->commandPlan instanceof CommandPlan);
        assertSame('cat', $outcome->commandPlan->program);
        assertSame(['app/Foo.php'], $outcome->commandPlan->relPaths);
    } finally { $rmrf($root); }
});

test('ciclo: grep porta il pattern LETTERALE (metacaratteri non interpretati)', function () use ($mkroot, $ws, $rmrf, $scripted, $loop) {
    $root = $mkroot();
    try {
        $decider = $scripted(['{"action":"run_command","program":"grep","args":["$(whoami)","app/Foo.php"]}']);
        $outcome = $loop($decider, new CodeCommandTool())->run($ws($root), 'cerca');
        assertSame('command', $outcome->stopReason);
        assertSame('$(whoami)', $outcome->commandPlan->pattern);
    } finally { $rmrf($root); }
});

test('OFFENSIVO: git / php / npm / bash NON sono nel registro → rifiutati come dato, il ciclo prosegue', function () use ($mkroot, $ws, $rmrf, $scripted, $loop) {
    $root = $mkroot();
    try {
        foreach (['git', 'php', 'python3', 'npm', 'composer', 'bash', 'curl', 'rm'] as $forbidden) {
            $decider = $scripted([
                '{"action":"run_command","program":"' . $forbidden . '","args":["status"]}',
                '{"action":"answer"}',
            ]);
            $outcome = $loop($decider, new CodeCommandTool())->run($ws($root), 'x');
            assertSame('answer', $outcome->stopReason, $forbidden);
            assertSame(null, $outcome->commandPlan, $forbidden);
        }
    } finally { $rmrf($root); }
});

test('OFFENSIVO: traversal / opzioni ricorsive → rifiutati, nessun piano', function () use ($mkroot, $ws, $rmrf, $scripted, $loop) {
    $root = $mkroot();
    try {
        foreach ([
            '{"action":"run_command","program":"cat","args":["../../etc/passwd"]}',
            '{"action":"run_command","program":"grep","args":["-r","x","."]}',
            '{"action":"run_command","program":"cat","args":["/etc/passwd"]}',
        ] as $bad) {
            $decider = $scripted([$bad, '{"action":"answer"}']);
            $outcome = $loop($decider, new CodeCommandTool())->run($ws($root), 'x');
            assertSame('answer', $outcome->stopReason, $bad);
            assertSame(null, $outcome->commandPlan, $bad);
        }
    } finally { $rmrf($root); }
});

test('ciclo: run_command NON offerto quando lo strumento è assente (azione sconosciuta)', function () use ($mkroot, $ws, $rmrf, $scripted, $loop) {
    $root = $mkroot();
    try {
        // Nessun CodeCommandTool: run_command non è nel vocabolario → output non valido → fallback.
        $decider = $scripted(['{"action":"run_command","program":"cat","args":["app/Foo.php"]}']);
        $outcome = $loop($decider, null)->run($ws($root), 'x');
        assertSame(true, in_array($outcome->stopReason, ['invalid', 'answer'], true));
        assertSame(null, $outcome->commandPlan);
    } finally { $rmrf($root); }
});
