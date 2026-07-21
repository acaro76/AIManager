<?php

declare(strict_types=1);

use App\Core\Code\CodeAgentLimits;
use App\Core\Code\CodeAgentLoop;
use App\Core\Code\CodeAgentTools;
use App\Core\Code\CodeVerificationRunRecord;
use App\Core\Code\CodeVerificationTool;
use App\Core\Code\CodeWorkspace;
use App\Core\Code\RetrievalLimits;
use App\Core\Code\SensitivePathPolicy;
use App\Core\Code\VerificationDetector;
use App\Core\Code\VerificationLimits;
use App\Core\Code\VerificationProfileRegistry;
use App\Core\Code\VerificationResult;

// Fase 5 — il CICLO con run_check: esegue una verifica curata su un file GIÀ letto, riporta l'esito
// come dato, traccia il record; rifiuta file non letti e profili non disponibili; deduplica; onora
// il tetto per turno. Decisore FAKE, esecuzione reale con `php` (sempre presente).

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
    $root = $base . '/aimanager_agverify_' . uniqid('', true);
    mkdir($root . '/app', 0777, true);
    file_put_contents($root . '/app/Login.php', "<?php\nfunction login() { return true; }\n");
    file_put_contents($root . '/app/Two.php', "<?php\nfunction two() { return 2; }\n");
    file_put_contents($root . '/app/Broken.php', "<?php\nfunction broken( {\n");
    return $root;
};

$ws = static fn (string $root): CodeWorkspace
    => new CodeWorkspace(1, $root, basename($root), 'active', new SensitivePathPolicy());

$scripted = static function (array $script): callable {
    $i = 0;
    return static function () use ($script, &$i): string {
        return (string) ($script[$i++] ?? '{"action":"answer"}');
    };
};

/** Tool di verifica con `php` presente, phpunit assente (php-test non disponibile). */
$tool = static function (?VerificationLimits $limits = null): CodeVerificationTool {
    $registry = new VerificationProfileRegistry();
    $detector = new VerificationDetector($registry, static fn (string $b): bool => $b === 'php');
    return new CodeVerificationTool(null, $limits ?? VerificationLimits::defaults(), $registry, $detector);
};

$loop = static function (callable $decider, CodeVerificationTool $tool, ?CodeAgentLimits $agentLimits = null): CodeAgentLoop {
    $limits = RetrievalLimits::defaults();
    $agent = $agentLimits ?? CodeAgentLimits::defaults();
    return new CodeAgentLoop(
        limits: $limits,
        agentLimits: $agent,
        tools: new CodeAgentTools($limits, $agent),
        decider: $decider,
        verifyEnabled: true,
        verification: $tool,
    );
};

test('verify: legge un file e ne verifica la sintassi → passed, record tracciato', function () use ($mkroot, $ws, $rmrf, $scripted, $tool, $loop) {
    $root = $mkroot();
    try {
        $decider = $scripted([
            '{"action":"read_file","path":"app/Login.php"}',
            '{"action":"run_check","profile":"php-lint","path":"app/Login.php"}',
            '{"action":"answer"}',
        ]);
        $outcome = $loop($decider, $tool())->run($ws($root), 'controlla la sintassi di Login');
        assertSame('answer', $outcome->stopReason);
        assertSame(1, count($outcome->verificationRuns));
        $run = $outcome->verificationRuns[0];
        assertSame(VerificationResult::PASSED, $run->outcome);
        assertSame(0, $run->exitCode);
        assertSame('app/Login.php', $run->relPath);
        assertSame('php-lint', $run->profileId);
    } finally {
        $rmrf($root);
    }
});

test('verify: su un file con errore di sintassi → failed con exit code != 0', function () use ($mkroot, $ws, $rmrf, $scripted, $tool, $loop) {
    $root = $mkroot();
    try {
        $decider = $scripted([
            '{"action":"read_file","path":"app/Broken.php"}',
            '{"action":"run_check","profile":"php-lint","path":"app/Broken.php"}',
            '{"action":"answer"}',
        ]);
        $outcome = $loop($decider, $tool())->run($ws($root), 'verifica Broken');
        assertSame(1, count($outcome->verificationRuns));
        assertSame(VerificationResult::FAILED, $outcome->verificationRuns[0]->outcome);
        assertSame(true, $outcome->verificationRuns[0]->exitCode !== 0);
    } finally {
        $rmrf($root);
    }
});

test('verify: un file NON letto in questo turno è rifiutato (denied), nessun processo', function () use ($mkroot, $ws, $rmrf, $scripted, $tool, $loop) {
    $root = $mkroot();
    try {
        $decider = $scripted([
            '{"action":"run_check","profile":"php-lint","path":"app/Login.php"}',
            '{"action":"answer"}',
        ]);
        $outcome = $loop($decider, $tool())->run($ws($root), 'verifica senza leggere');
        assertSame(1, count($outcome->verificationRuns));
        assertSame(CodeVerificationRunRecord::DENIED, $outcome->verificationRuns[0]->outcome);
        assertSame(null, $outcome->verificationRuns[0]->exitCode);
    } finally {
        $rmrf($root);
    }
});

test('verify: profilo SCONOSCIUTO non produce record né processo', function () use ($mkroot, $ws, $rmrf, $scripted, $tool, $loop) {
    $root = $mkroot();
    try {
        $decider = $scripted([
            '{"action":"run_check","profile":"shell","path":"app/Login.php"}',
            '{"action":"answer"}',
        ]);
        $outcome = $loop($decider, $tool())->run($ws($root), 'prova shell');
        assertSame(0, count($outcome->verificationRuns));
        assertSame('answer', $outcome->stopReason);
    } finally {
        $rmrf($root);
    }
});

test('verify: profilo non disponibile (php-test senza phpunit) → unavailable', function () use ($mkroot, $ws, $rmrf, $scripted, $tool, $loop) {
    $root = $mkroot();
    try {
        $decider = $scripted([
            '{"action":"read_file","path":"app/Login.php"}',
            '{"action":"run_check","profile":"php-test"}',
            '{"action":"answer"}',
        ]);
        $outcome = $loop($decider, $tool())->run($ws($root), 'lancia i test');
        assertSame(1, count($outcome->verificationRuns));
        assertSame(CodeVerificationRunRecord::UNAVAILABLE, $outcome->verificationRuns[0]->outcome);
    } finally {
        $rmrf($root);
    }
});

test('verify: una verifica identica non viene rieseguita (deduplica)', function () use ($mkroot, $ws, $rmrf, $scripted, $tool, $loop) {
    $root = $mkroot();
    try {
        $decider = $scripted([
            '{"action":"read_file","path":"app/Login.php"}',
            '{"action":"run_check","profile":"php-lint","path":"app/Login.php"}',
            '{"action":"run_check","profile":"php-lint","path":"app/Login.php"}',
            '{"action":"answer"}',
        ]);
        $outcome = $loop($decider, $tool(), new CodeAgentLimits(8, 90.0, 24000, 6000, 2, 120))->run($ws($root), 'verifica due volte');
        assertSame(1, count($outcome->verificationRuns));
    } finally {
        $rmrf($root);
    }
});

test('verify: il tetto di verifiche per turno ferma le esecuzioni extra', function () use ($mkroot, $ws, $rmrf, $scripted, $tool, $loop) {
    $root = $mkroot();
    try {
        $limits = new VerificationLimits(maxSeconds: 20.0, maxOutputBytes: 32768, maxRunsPerTurn: 1);
        $decider = $scripted([
            '{"action":"read_file","path":"app/Login.php"}',
            '{"action":"read_file","path":"app/Two.php"}',
            '{"action":"run_check","profile":"php-lint","path":"app/Login.php"}',
            '{"action":"run_check","profile":"php-lint","path":"app/Two.php"}',
            '{"action":"answer"}',
        ]);
        $outcome = $loop($decider, $tool($limits), new CodeAgentLimits(8, 90.0, 24000, 6000, 2, 120))->run($ws($root), 'verifica due file');
        // Solo la prima verifica è stata eseguita; la seconda è oltre il tetto.
        assertSame(1, count($outcome->verificationRuns));
        assertSame(VerificationResult::PASSED, $outcome->verificationRuns[0]->outcome);
    } finally {
        $rmrf($root);
    }
});

test('verify: run_check NON è nel vocabolario se la verifica è disabilitata', function () use ($mkroot, $ws, $rmrf, $scripted) {
    $root = $mkroot();
    try {
        $limits = RetrievalLimits::defaults();
        $agent = CodeAgentLimits::defaults();
        // Nessuno strumento di verifica → run_check è output non valido, poi answer.
        $decider = $scripted([
            '{"action":"run_check","profile":"php-lint","path":"app/Login.php"}',
            '{"action":"answer"}',
        ]);
        $loop = new CodeAgentLoop(
            limits: $limits,
            agentLimits: $agent,
            tools: new CodeAgentTools($limits, $agent),
            decider: $decider,
            verifyEnabled: false,
        );
        $outcome = $loop->run($ws($root), 'prova');
        assertSame(0, count($outcome->verificationRuns));
        assertSame('answer', $outcome->stopReason);
    } finally {
        $rmrf($root);
    }
});
