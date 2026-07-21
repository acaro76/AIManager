<?php

declare(strict_types=1);

use App\Core\Code\CodeAgentAction;
use App\Core\Code\CodeAgentLimits;
use App\Core\Code\CodeAgentLoop;
use App\Core\Code\CodeAgentTools;
use App\Core\Code\CodeGitTool;
use App\Core\Code\CodeWorkspace;
use App\Core\Code\GitStagePlan;
use App\Core\Code\RetrievalLimits;
use App\Core\Code\SensitivePathPolicy;

// Fase 8 — il CICLO con git_status/git_diff: gating dal flag, azioni read-only NON terminali, osservazioni
// raccolte per la risposta finale, deduplica, scelta tipizzata staged/unstaged. Decisore FAKE.

// --- Gating e forma dell'azione (unit, nessun filesystem) ------------------------------------------

test('parse: git_status/git_diff ASSENTI dal vocabolario con git disabilitato', function () {
    $limits = CodeAgentLimits::defaults();
    foreach (['{"action":"git_status"}', '{"action":"git_diff","mode":"staged"}'] as $raw) {
        $threw = false;
        try {
            CodeAgentAction::parse($raw, $limits, false, null, false, false, false, false);
        } catch (\InvalidArgumentException $e) {
            $threw = true;
        }
        assertSame(true, $threw);
    }
});

test('parse: git_status/git_diff PRESENTI con git abilitato', function () {
    $limits = CodeAgentLimits::defaults();
    $status = CodeAgentAction::parse('{"action":"git_status"}', $limits, false, null, false, false, false, true);
    assertSame(true, $status->isGitStatus());
    assertSame('git_status', $status->key());

    $diff = CodeAgentAction::parse('{"action":"git_diff","mode":"staged"}', $limits, false, null, false, false, false, true);
    assertSame(true, $diff->isGitDiff());
    assertSame(true, $diff->gitDiffStaged);
    assertSame('git_diff:staged', $diff->key());
});

test('parse: git_diff mode tipizzato (staged/unstaged/booleano); mancante o ignoto → errore', function () {
    $limits = CodeAgentLimits::defaults();
    $unstaged = CodeAgentAction::parse('{"action":"git_diff","mode":"unstaged"}', $limits, false, null, false, false, false, true);
    assertSame(false, $unstaged->gitDiffStaged);
    assertSame('git_diff:unstaged', $unstaged->key());

    $boolean = CodeAgentAction::parse('{"action":"git_diff","staged":true}', $limits, false, null, false, false, false, true);
    assertSame(true, $boolean->gitDiffStaged);

    foreach (['{"action":"git_diff"}', '{"action":"git_diff","mode":"tutto"}'] as $raw) {
        $threw = false;
        try {
            CodeAgentAction::parse($raw, $limits, false, null, false, false, false, true);
        } catch (\InvalidArgumentException $e) {
            $threw = true;
        }
        assertSame(true, $threw);
    }
});

test('parse: propose_git_stage ASSENTE con git disabilitato, PRESENTE con git abilitato', function () {
    $limits = CodeAgentLimits::defaults();
    $raw = '{"action":"propose_git_stage","paths":["a.txt"]}';
    $threw = false;
    try {
        CodeAgentAction::parse($raw, $limits, false, null, false, false, false, false);
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    assertSame(true, $threw);

    $action = CodeAgentAction::parse($raw, $limits, false, null, false, false, false, true);
    assertSame(true, $action->isProposeGitStage());
    assertSame(['a.txt'], $action->stagePaths);
});

test('parse: propose_git_stage — lista vuota, duplicati, ordine e forme malformate deterministici', function () {
    $limits = CodeAgentLimits::defaults();

    // Duplicati rimossi, ordine deterministico (indipendente dall'input).
    $a = CodeAgentAction::parse('{"action":"propose_git_stage","paths":["b.txt","a.txt","b.txt"]}', $limits, false, null, false, false, false, true);
    assertSame(['a.txt', 'b.txt'], $a->stagePaths);

    // Forme rifiutate: lista vuota, non-stringa, traversal, assoluto, backslash, opzione, pathspec-magic.
    $bad = [
        '{"action":"propose_git_stage","paths":[]}',
        '{"action":"propose_git_stage"}',
        '{"action":"propose_git_stage","paths":[123]}',
        '{"action":"propose_git_stage","paths":["../fuori"]}',
        '{"action":"propose_git_stage","paths":["/etc/passwd"]}',
        '{"action":"propose_git_stage","paths":["a\\\\b"]}',
        '{"action":"propose_git_stage","paths":["-opzione"]}',
        '{"action":"propose_git_stage","paths":[":(top)magic"]}',
    ];
    foreach ($bad as $raw) {
        $threw = false;
        try {
            CodeAgentAction::parse($raw, $limits, false, null, false, false, false, true);
        } catch (\InvalidArgumentException $e) {
            $threw = true;
        }
        assertSame(true, $threw, 'atteso rifiuto per: ' . $raw);
    }
});

// --- Integrazione nel ciclo (repository temporaneo) ------------------------------------------------

$hasGit = CodeGitTool::withDefaults()->isAvailable();

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

$mkrepo = static function (): string {
    $base = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
    $dir = $base . '/aimanager_agit_' . uniqid('', true);
    mkdir($dir, 0777, true);
    $dir = realpath($dir) ?: $dir;
    $env = [
        'LANG' => 'C', 'LC_ALL' => 'C', 'PATH' => '/usr/bin:/bin', 'HOME' => $dir,
        'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_SYSTEM' => '/dev/null',
        'GIT_AUTHOR_NAME' => 'T', 'GIT_AUTHOR_EMAIL' => 't@e.x',
        'GIT_COMMITTER_NAME' => 'T', 'GIT_COMMITTER_EMAIL' => 't@e.x',
    ];
    $run = static function (array $args) use ($dir, $env): void {
        $d = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = @proc_open(array_merge(['/usr/bin/git', '-c', 'init.defaultBranch=main'], $args), $d, $pipes, $dir, $env);
        if (is_resource($p)) {
            foreach ([1, 2] as $fd) { if (isset($pipes[$fd])) { stream_get_contents($pipes[$fd]); fclose($pipes[$fd]); } }
            proc_close($p);
        }
    };
    $run(['init', '-q']);
    file_put_contents($dir . '/a.txt', "uno\n");
    $run(['add', '-A']);
    $run(['commit', '-q', '-m', 'primo']);
    file_put_contents($dir . '/a.txt', "uno-modificato\n"); // modifica non in stage
    return $dir;
};

$ws = static fn (string $root): CodeWorkspace
    => new CodeWorkspace(1, $root, 'temp', 'active', new SensitivePathPolicy());

$scripted = static function (array $script): callable {
    $i = 0;
    return static function () use ($script, &$i): string {
        return (string) ($script[$i++] ?? '{"action":"answer"}');
    };
};

$loop = static function (callable $decider, bool $gitEnabled): CodeAgentLoop {
    $limits = RetrievalLimits::defaults();
    $agent = CodeAgentLimits::defaults();
    return new CodeAgentLoop(
        limits: $limits,
        agentLimits: $agent,
        tools: new CodeAgentTools($limits, $agent),
        decider: $decider,
        gitEnabled: $gitEnabled,
        git: CodeGitTool::withDefaults(),
    );
};

if (!$hasGit) {
    test('git non disponibile: integrazione ciclo Git saltata', function (): void {
        assertSame(true, true);
    });
    return;
}

test('selezione Git deterministica: usa solo modifiche nominate, senza modello o letture file', function () use ($mkrepo, $rmrf, $ws) {
    $dir = $mkrepo();
    try {
        mkdir($dir . '/app', 0777, true);
        file_put_contents($dir . '/app/altro.txt', "nuovo\n");
        file_put_contents($dir . '/.env', "SEGRETO=test\n");
        $tool = CodeGitTool::withDefaults();

        $one = $tool->proposeStageFromPrompt($ws($dir), 'Metti soltanto a.txt in stage', 6000);
        assertSame(true, $one['plan'] instanceof GitStagePlan);
        assertSame(['a.txt'], $one['plan']->paths());

        $missing = $tool->proposeStageFromPrompt($ws($dir), 'Metti config.json in stage', 6000);
        assertSame(null, $missing['plan']);
        assertSame(false, str_contains((string) $missing['observation'], 'config.json'));

        $sensitive = $tool->proposeStageFromPrompt($ws($dir), 'Metti .env in stage', 6000);
        assertSame(null, $sensitive['plan']);
        assertSame(false, str_contains((string) $sensitive['observation'], '.env'));

        $all = $tool->proposeStageFromPrompt($ws($dir), 'Metti tutto in stage', 6000);
        assertSame(true, $all['plan'] instanceof GitStagePlan);
        assertSame(['a.txt', 'app/altro.txt'], $all['plan']->paths());
    } finally {
        $rmrf($dir);
    }
});

test('ciclo: git_status poi git_diff → osservazioni raccolte, esito answer usabile', function () use ($mkrepo, $rmrf, $ws, $scripted, $loop) {
    $dir = $mkrepo();
    try {
        $decider = $scripted([
            '{"action":"git_status"}',
            '{"action":"git_diff","mode":"unstaged"}',
            '{"action":"answer"}',
        ]);
        $outcome = $loop($decider, true)->run($ws($dir), 'Come sta il repo?');
        assertSame('answer', $outcome->stopReason);
        assertSame(2, count($outcome->gitObservations));
        assertSame(true, $outcome->hasGit());
        assertSame(true, $outcome->usableForAnswer());
        assertSame(true, str_contains($outcome->gitObservations[0], 'STATO GIT'));
        assertSame(true, str_contains($outcome->gitObservations[1], 'DIFF GIT (non in stage)'));
        assertSame(true, str_contains($outcome->gitObservations[1], 'uno-modificato'));
    } finally {
        $rmrf($dir);
    }
});

test('ciclo: git_status duplicato è deduplicato (una sola osservazione)', function () use ($mkrepo, $rmrf, $ws, $scripted, $loop) {
    $dir = $mkrepo();
    try {
        $decider = $scripted([
            '{"action":"git_status"}',
            '{"action":"git_status"}',
            '{"action":"answer"}',
        ]);
        $outcome = $loop($decider, true)->run($ws($dir), 'stato?');
        assertSame('answer', $outcome->stopReason);
        assertSame(1, count($outcome->gitObservations));
    } finally {
        $rmrf($dir);
    }
});

test('ciclo: stato Git richiesto non accetta answer prima della lettura corrente', function () use ($mkrepo, $rmrf, $ws, $scripted, $loop) {
    $dir = $mkrepo();
    try {
        $decider = $scripted([
            '{"action":"answer"}',
            '{"action":"git_status"}',
            '{"action":"answer"}',
        ]);
        $outcome = $loop($decider, true)->run($ws($dir), 'Mostrami lo stato Git corrente', '', false, false, false, false, true);
        assertSame('answer', $outcome->stopReason);
        assertSame(true, $outcome->gitStatusObservation !== null);
        assertSame(1, count($outcome->gitObservations));
        assertSame(true, str_contains($outcome->gitObservations[0], 'STATO GIT'));
    } finally {
        $rmrf($dir);
    }
});

test('ciclo: con git DISABILITATO git_status non è eseguibile (output non valido)', function () use ($mkrepo, $rmrf, $ws, $scripted, $loop) {
    $dir = $mkrepo();
    try {
        $decider = $scripted([
            '{"action":"git_status"}',
            '{"action":"git_status"}',
            '{"action":"git_status"}',
            '{"action":"git_status"}',
        ]);
        $outcome = $loop($decider, false)->run($ws($dir), 'stato?');
        assertSame('invalid', $outcome->stopReason);
        assertSame([], $outcome->gitObservations);
    } finally {
        $rmrf($dir);
    }
});

test('ciclo: propose_git_stage è TERMINALE → piano nell\'esito, indice/worktree non toccati', function () use ($mkrepo, $rmrf, $ws, $scripted, $loop) {
    $dir = $mkrepo(); // a.txt committato poi modificato (unstaged)
    try {
        $decider = $scripted(['{"action":"propose_git_stage","paths":["a.txt"]}']);
        $outcome = $loop($decider, true)->run($ws($dir), 'metti in stage a.txt', '', false, false, false, true);
        assertSame('git_stage', $outcome->stopReason);
        assertSame(true, $outcome->hasGitStageProposal());
        assertSame(true, $outcome->gitStagePlan instanceof GitStagePlan);
        assertSame(['a.txt'], $outcome->gitStagePlan->paths());
        assertSame('modificato', $outcome->gitStagePlan->selected[0]['status']);

        // Nessun `git add`: niente in stage dopo la proposta.
        $env = ['LANG' => 'C', 'PATH' => '/usr/bin:/bin', 'HOME' => $dir,
            'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_SYSTEM' => '/dev/null'];
        $d = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = proc_open(['/usr/bin/git', 'diff', '--cached', '--name-only'], $d, $pi, $dir, $env);
        $staged = is_resource($p) ? stream_get_contents($pi[1]) : 'ERR';
        if (is_resource($p)) { foreach ([1, 2] as $f) { if ($f === 2) { stream_get_contents($pi[$f]); } fclose($pi[$f]); } proc_close($p); }
        assertSame('', trim((string) $staged));
    } finally {
        $rmrf($dir);
    }
});

test('ciclo: staging richiesto solo per un sensibile conserva un rifiuto anonimo', function () use ($mkrepo, $rmrf, $ws, $scripted, $loop) {
    $dir = $mkrepo();
    file_put_contents($dir . '/.env', "SEGRETO=test\n");
    try {
        $outcome = $loop($scripted(['{"action":"propose_git_stage","paths":[".env"]}']), true)
            ->run($ws($dir), 'metti in stage .env', '', false, false, false, true);
        assertSame(null, $outcome->gitStagePlan);
        assertSame(true, $outcome->gitStageFailureObservation !== null);
        assertSame(true, str_contains((string) $outcome->gitStageFailureObservation, 'STAGING NON PROPOSTO'));
        assertSame(false, str_contains((string) $outcome->gitStageFailureObservation, '.env'));
    } finally {
        $rmrf($dir);
    }
});

test('ciclo: propose_git_stage senza richiesta esplicita non produce alcun piano', function () use ($mkrepo, $rmrf, $ws, $scripted, $loop) {
    $dir = $mkrepo();
    try {
        $decider = $scripted([
            '{"action":"propose_git_stage","paths":["a.txt"]}',
            '{"action":"answer"}',
        ]);
        $outcome = $loop($decider, true)->run(
            $ws($dir),
            'Mostrami stato e diff. Non preparare staging o commit.'
        );
        assertSame('answer', $outcome->stopReason);
        assertSame(null, $outcome->gitStagePlan);
    } finally {
        $rmrf($dir);
    }
});

test('ciclo: propose_git_stage con git DISABILITATO non è eseguibile (output non valido)', function () use ($mkrepo, $rmrf, $ws, $scripted, $loop) {
    $dir = $mkrepo();
    try {
        $decider = $scripted(array_fill(0, 4, '{"action":"propose_git_stage","paths":["a.txt"]}'));
        $outcome = $loop($decider, false)->run($ws($dir), 'stage?');
        assertSame('invalid', $outcome->stopReason);
        assertSame(null, $outcome->gitStagePlan);
    } finally {
        $rmrf($dir);
    }
});
