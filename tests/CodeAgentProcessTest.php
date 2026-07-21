<?php

declare(strict_types=1);

use App\Core\Code\CodeAgentAction;
use App\Core\Code\CodeAgentLimits;
use App\Core\Code\CodeAgentLoop;
use App\Core\Code\CodeAgentTools;
use App\Core\Code\CodeProcessTool;
use App\Core\Code\CodeWorkspace;
use App\Core\Code\ProcessPlan;
use App\Core\Code\ProcessProfile;
use App\Core\Code\ProcessRunner;
use App\Core\Code\RetrievalLimits;
use App\Core\Code\SensitivePathPolicy;

// Fase 7 — start_process nel ciclo: una proposta ammissibile è TERMINALE e porta il piano; un
// processo non ammesso (profilo ignoto, porta privilegiata, docroot fuori root/sensibile) torna
// come DATO e il ciclo prosegue. Offensivo: profili shell/node/git/interpreti sono id ignoti →
// negati; l'host è SEMPRE 127.0.0.1 (mai scelto dal modello); traversal/assoluti rifiutati.

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
    $root = $base . '/aim_agproc_' . uniqid('', true);
    mkdir($root . '/public', 0777, true);
    file_put_contents($root . '/public/index.php', "<?php\necho 'ok';\n");
    file_put_contents($root . '/.env', "SECRET=1\n");
    return $root;
};
$ws = static fn (string $root): CodeWorkspace => new CodeWorkspace(1, $root, basename($root), 'active', new SensitivePathPolicy());
$scripted = static function (array $script): callable {
    $i = 0;
    return static function () use ($script, &$i): string { return (string) ($script[$i++] ?? '{"action":"answer"}'); };
};
$loop = static function (callable $decider, ?CodeProcessTool $tool): CodeAgentLoop {
    $limits = RetrievalLimits::defaults();
    $agent = CodeAgentLimits::defaults();
    return new CodeAgentLoop(
        limits: $limits,
        agentLimits: $agent,
        tools: new CodeAgentTools($limits, $agent),
        decider: $decider,
        processesEnabled: $tool !== null,
        processes: $tool,
    );
};

// --- Parse (solo forma): non richiede disponibilità dell'ambiente. ---

test('parse: start_process entra nel vocabolario SOLO se abilitato', function () {
    $json = '{"action":"start_process","profile":"php-server","port":8000,"directory":"public"}';
    $threw = false;
    try {
        CodeAgentAction::parse($json, CodeAgentLimits::defaults()); // processesEnabled = false
    } catch (\InvalidArgumentException) { $threw = true; }
    assertSame(true, $threw);

    $action = CodeAgentAction::parse($json, CodeAgentLimits::defaults(), false, null, false, false, true);
    assertSame(true, $action->isStartProcess());
    assertSame('php-server', $action->processProfile);
    assertSame(8000, $action->processPort);
    assertSame('public', $action->processDir);
});

test('parse: porta mancante / non numerica / fuori range → rifiutata', function () {
    foreach ([
        '{"action":"start_process","profile":"php-server","directory":""}',
        '{"action":"start_process","profile":"php-server","port":"abc","directory":""}',
        '{"action":"start_process","profile":"php-server","port":0,"directory":""}',
        '{"action":"start_process","profile":"php-server","port":70000,"directory":""}',
    ] as $bad) {
        $threw = false;
        try { CodeAgentAction::parse($bad, CodeAgentLimits::defaults(), false, null, false, false, true); }
        catch (\InvalidArgumentException) { $threw = true; }
        assertSame(true, $threw, $bad);
    }
});

test('parse: directory traversal / assoluta → rifiutata; "" e numerica-stringa ammesse', function () {
    foreach ([
        '{"action":"start_process","profile":"php-server","port":8000,"directory":"../etc"}',
        '{"action":"start_process","profile":"php-server","port":8000,"directory":"/etc"}',
        '{"action":"start_process","profile":"php-server","port":8000,"directory":"a/../../b"}',
    ] as $bad) {
        $threw = false;
        try { CodeAgentAction::parse($bad, CodeAgentLimits::defaults(), false, null, false, false, true); }
        catch (\InvalidArgumentException) { $threw = true; }
        assertSame(true, $threw, $bad);
    }
    // porta come stringa numerica: tollerata (i modelli locali la scrivono spesso così).
    $ok = CodeAgentAction::parse('{"action":"start_process","profile":"php-server","port":"8000","directory":""}', CodeAgentLimits::defaults(), false, null, false, false, true);
    assertSame(8000, $ok->processPort);
    assertSame('', $ok->processDir);
});

// Precondizione dei test su strumento/ciclo: process group + php risolvibile.
$available = ProcessRunner::supportsProcessGroupIsolation() && ProcessProfile::resolveProgram() !== null;

if (!$available) {
    test('CodeAgentProcess: ambiente senza process group / php (skip strumento e ciclo)', function () {
        assertSame(true, true);
    });
    return;
}

test('tool.validate: proposta ammissibile → piano con host FISSO 127.0.0.1 (mai dal modello)', function () use ($mkroot, $ws, $rmrf) {
    $root = $mkroot();
    try {
        $res = (new CodeProcessTool())->validate($ws($root), 'php-server', 8000, 'public', 4000);
        assertSame(true, $res['plan'] instanceof ProcessPlan);
        assertSame('127.0.0.1', $res['plan']->host);
        assertSame(8000, $res['plan']->port);
        assertSame('public', $res['plan']->relDir);
    } finally { $rmrf($root); }
});

test('tool.validate: profilo ignoto / porta privilegiata / docroot sensibile → rifiutati (dato)', function () use ($mkroot, $ws, $rmrf) {
    $root = $mkroot();
    try {
        $tool = new CodeProcessTool();
        assertSame(null, $tool->validate($ws($root), 'node-server', 8000, 'public', 4000)['plan']);
        assertSame(null, $tool->validate($ws($root), 'php-server', 80, 'public', 4000)['plan']); // privilegiata
        assertSame(null, $tool->validate($ws($root), 'php-server', 8000, '.env', 4000)['plan']); // sensibile
        assertSame(null, $tool->validate($ws($root), 'php-server', 8000, 'inesistente', 4000)['plan']); // non dir
    } finally { $rmrf($root); }
});

test('ciclo: start_process ammissibile è TERMINALE e porta il piano', function () use ($mkroot, $ws, $rmrf, $scripted, $loop) {
    $root = $mkroot();
    try {
        $decider = $scripted(['{"action":"start_process","profile":"php-server","port":8000,"directory":"public"}']);
        $outcome = $loop($decider, new CodeProcessTool())->run($ws($root), 'avvia il server', '', false, false, true);
        assertSame('process', $outcome->stopReason);
        assertSame(true, $outcome->hasProcessProposal());
        assertSame(true, $outcome->processPlan instanceof ProcessPlan);
        assertSame('127.0.0.1', $outcome->processPlan->host);
        assertSame(8000, $outcome->processPlan->port);
        assertSame('public', $outcome->processPlan->relDir);
    } finally { $rmrf($root); }
});

test('OFFENSIVO: profili shell/node/git/interpreti sono id ignoti → rifiutati, nessun piano', function () use ($mkroot, $ws, $rmrf, $scripted, $loop) {
    $root = $mkroot();
    try {
        foreach (['node-server', 'bash', 'git', 'php', 'python', 'npm', 'docker'] as $forbidden) {
            $decider = $scripted([
                '{"action":"start_process","profile":"' . $forbidden . '","port":8000,"directory":""}',
                '{"action":"answer"}',
            ]);
            $outcome = $loop($decider, new CodeProcessTool())->run($ws($root), 'x', '', false, false, false);
            assertSame('answer', $outcome->stopReason, $forbidden);
            assertSame(null, $outcome->processPlan, $forbidden);
        }
    } finally { $rmrf($root); }
});

test('OFFENSIVO: docroot fuori root/sensibile e porta privilegiata → rifiutati, nessun piano', function () use ($mkroot, $ws, $rmrf, $scripted, $loop) {
    $root = $mkroot();
    try {
        foreach ([
            '{"action":"start_process","profile":"php-server","port":8000,"directory":"public"}', // ok control below overridden
        ] as $_) { /* no-op */ }
        // Porta privilegiata e docroot sensibile: il modello propone, il tool nega, il ciclo prosegue.
        foreach ([
            '{"action":"start_process","profile":"php-server","port":22,"directory":""}',
            '{"action":"start_process","profile":"php-server","port":8000,"directory":".env"}',
        ] as $bad) {
            $decider = $scripted([$bad, '{"action":"answer"}']);
            $outcome = $loop($decider, new CodeProcessTool())->run($ws($root), 'x', '', false, false, false);
            assertSame('answer', $outcome->stopReason, $bad);
            assertSame(null, $outcome->processPlan, $bad);
        }
    } finally { $rmrf($root); }
});

test('ciclo: processo chiesto ESPLICITAMENTE ma strumento assente → process_unavailable, nessun piano', function () use ($mkroot, $ws, $rmrf, $scripted, $loop) {
    $root = $mkroot();
    try {
        $decider = $scripted(['{"action":"answer"}']);
        $outcome = $loop($decider, null)->run($ws($root), 'avvia', '', false, false, true);
        assertSame('process_unavailable', $outcome->stopReason);
        assertSame(null, $outcome->processPlan);
    } finally { $rmrf($root); }
});

test('chat: il rifiuto finale spiega il confine senza gergo interno', function () {
    $service = (string) file_get_contents(dirname(__DIR__) . '/app/Services/CodeChatService.php');
    assertSame(true, str_contains($service, 'Avvio rifiutato per sicurezza.'));
    assertSame(true, str_contains($service, 'dentro la cartella autorizzata'));
    assertSame(true, str_contains($service, 'Nessun processo è stato avviato.'));
    assertSame(false, str_contains($service, 'proporre un processo ammesso'));
    assertSame(true, str_contains($service, "'assistant',\n                    \$message,"));
    assertSame(true, str_contains($service, 'live e storico restano identici dopo il refresh'));
    $refusal = substr(
        $service,
        (int) strpos($service, 'if ($processRequired) {'),
        (int) strpos($service, '// Un comando chiesto esplicitamente') - (int) strpos($service, 'if ($processRequired) {')
    );
    assertSame(false, str_contains($refusal, 'storeEvidence('));
    assertSame(false, str_contains($refusal, 'linkVerifications('));
});
