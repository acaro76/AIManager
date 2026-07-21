<?php

declare(strict_types=1);

use App\Core\Code\CodeWorkspace;
use App\Core\Code\CodeWorkspaceException;
use App\Core\Code\CommandArgvValidator;
use App\Core\Code\CommandPlan;
use App\Core\Code\CommandProgramResolver;
use App\Core\Code\CommandRegistry;
use App\Core\Code\CommandResult;
use App\Core\Code\CommandRunLimits;
use App\Core\Code\CommandRunner;
use App\Core\Code\SensitivePathPolicy;

// Fase 6 — l'esecutore reale: process group OBBLIGATORIO (nessun fallback), argv (mai shell), env
// EFFIMERO e isolato, timeout, Stop, output cap, terminazione dell'albero. Timeout/Stop/albero sono
// provati con un HELPER INTERNO non registrato (correzione #9): il registro NON viene allargato.

$mkcwd = static function (): string {
    $base = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
    $dir = $base . '/aim_crun_' . uniqid('', true);
    mkdir($dir, 0777, true);
    return $dir;
};
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
$ws = static fn (string $root): CodeWorkspace => new CodeWorkspace(7, $root, basename($root), 'active', new SensitivePathPolicy());
$runtime = static fn (string $root): string => $root . '/.rt';

if (!CommandRunner::supportsProcessGroupIsolation()) {
    test('CommandRunner: process group non disponibile — exec disabilitata (skip esteso)', function () {
        assertSame(false, CommandRunner::supportsProcessGroupIsolation());
    });
    return;
}

// Helper INTERNO non registrato (correzione #9): uno script eseguibile con shebang all'interprete
// PHP reale, passato come absExe DIRETTO al runner (bypassando registro e resolver). Il registro NON
// viene allargato: questi programmi non sono raggiungibili via run_command.
$emptyPlan = static fn (): CommandPlan => new CommandPlan('helper', [], null, []);
$mkhelper = static function (string $root, string $name, string $body): string {
    $path = $root . '/' . $name;
    file_put_contents($path, '#!' . PHP_BINARY . "\n<?php\n" . $body . "\n");
    chmod($path, 0755);
    return $path;
};

test('CommandRunner: happy path — cat di un file reale del registro → completed + output', function () use ($mkcwd, $rmrf, $ws, $runtime) {
    $root = $mkcwd();
    try {
        file_put_contents($root . '/hello.txt', "ciao mondo\n");
        $spec = (new CommandRegistry())->find('cat');
        $plan = (new CommandArgvValidator())->validate($spec, ['hello.txt']);
        $exe = (new CommandProgramResolver())->resolve('cat');
        if ($exe === null) { assertSame(true, true); return; } // cat assente: ambiente minimale
        $runner = new CommandRunner(CommandRunLimits::defaults(), $runtime($root));
        $res = $runner->run($plan, $ws($root), $exe, 'exec-happy1234');
        assertSame(CommandResult::COMPLETED, $res->outcome);
        assertSame(0, $res->exitCode);
        assertSame(true, str_contains($res->output, 'ciao mondo'));
    } finally { $rmrf($root); }
});

test('CommandRunner: NESSUNA shell — un file con metacaratteri nel nome è letto letteralmente', function () use ($mkcwd, $rmrf, $ws, $runtime) {
    $root = $mkcwd();
    try {
        file_put_contents($root . '/a;b.txt', "letterale\n");
        $spec = (new CommandRegistry())->find('cat');
        $plan = (new CommandArgvValidator())->validate($spec, ['a;b.txt']);
        $exe = (new CommandProgramResolver())->resolve('cat');
        if ($exe === null) { assertSame(true, true); return; }
        $res = (new CommandRunner(CommandRunLimits::defaults(), $runtime($root)))->run($plan, $ws($root), $exe, 'exec-meta12345');
        // Se fosse passato da una shell, `;b.txt` sarebbe un secondo comando: qui è UN file.
        assertSame(CommandResult::COMPLETED, $res->outcome);
        assertSame(true, str_contains($res->output, 'letterale'));
    } finally { $rmrf($root); }
});

test('CommandRunner: bind — un path sensibile fa NEGARE (CodeWorkspaceException)', function () use ($mkcwd, $rmrf, $ws, $runtime) {
    $root = $mkcwd();
    try {
        file_put_contents($root . '/.env', "SECRET=1\n");
        $exe = (new CommandProgramResolver())->resolve('cat');
        if ($exe === null) { assertSame(true, true); return; }
        $plan = new CommandPlan('cat', [], null, ['.env']);
        $threw = false;
        try {
            (new CommandRunner(CommandRunLimits::defaults(), $runtime($root)))->run($plan, $ws($root), $exe, 'exec-sens123456');
        } catch (CodeWorkspaceException) {
            $threw = true;
        }
        assertSame(true, $threw);
    } finally { $rmrf($root); }
});

test('CommandRunner: TIMEOUT — helper che dorme oltre il tetto → timed_out, nessun orfano', function () use ($mkcwd, $rmrf, $ws, $runtime, $emptyPlan, $mkhelper) {
    $root = $mkcwd();
    try {
        $exe = $mkhelper($root, 'sleep', 'usleep(30000000);');
        $runner = new CommandRunner(new CommandRunLimits(maxSeconds: 1.0, maxOutputBytes: 65536), $runtime($root));
        $res = $runner->run($emptyPlan(), $ws($root), $exe, 'exec-timeout123');
        assertSame(CommandResult::TIMED_OUT, $res->outcome);
        assertSame(true, $res->timedOut);
        $pgid = $runner->lastProcessGroupId();
        assertSame(false, $pgid !== null && @posix_kill(-$pgid, 0));
    } finally { $rmrf($root); }
});

test('CommandRunner: STOP durante l\'esecuzione → killed', function () use ($mkcwd, $rmrf, $ws, $runtime, $emptyPlan, $mkhelper) {
    $root = $mkcwd();
    try {
        $exe = $mkhelper($root, 'sleep', 'usleep(30000000);');
        $calls = 0;
        $isCancelled = static function () use (&$calls): bool { return ++$calls > 1; };
        $runner = new CommandRunner(new CommandRunLimits(10.0, 65536), $runtime($root), $isCancelled);
        $res = $runner->run($emptyPlan(), $ws($root), $exe, 'exec-stop123456');
        assertSame(CommandResult::KILLED, $res->outcome);
        $pgid = $runner->lastProcessGroupId();
        assertSame(false, $pgid !== null && @posix_kill(-$pgid, 0));
    } finally { $rmrf($root); }
});

test('CommandRunner: OUTPUT CAP — helper che inonda → truncated + killed, byte limitati', function () use ($mkcwd, $rmrf, $ws, $runtime, $emptyPlan, $mkhelper) {
    $root = $mkcwd();
    try {
        $exe = $mkhelper($root, 'flood', "while (true) { echo str_repeat('x', 8192); }");
        $res = (new CommandRunner(new CommandRunLimits(10.0, 16384), $runtime($root)))
            ->run($emptyPlan(), $ws($root), $exe, 'exec-flood12345');
        assertSame(CommandResult::KILLED, $res->outcome);
        assertSame(true, $res->truncated);
        assertSame(true, $res->outputBytes <= 16384);
    } finally { $rmrf($root); }
});

test('CommandRunner: TERMINAZIONE ALBERO — figlio superstite dopo l\'uscita del leader viene abbattuto', function () use ($mkcwd, $rmrf, $ws, $runtime, $emptyPlan, $mkhelper) {
    $root = $mkcwd();
    try {
        $exe = $mkhelper($root, 'fork', "\$pid = pcntl_fork();\nif (\$pid === -1) { exit(2); }\nif (\$pid === 0) { usleep(30000000); exit(0); }\nfwrite(STDOUT, (string) \$pid);\nusleep(80000);\nexit(0);");
        $runner = new CommandRunner(new CommandRunLimits(10.0, 65536), $runtime($root));
        $res = $runner->run($emptyPlan(), $ws($root), $exe, 'exec-fork123456');
        $childPid = (int) trim($res->output);
        assertSame(true, $childPid > 1);
        usleep(250000);
        assertSame(false, @posix_kill($childPid, 0));
    } finally { $rmrf($root); }
});

test('CommandRunner: ENV isolato — nessun segreto del padre, HOME nella runtime effimera', function () use ($mkcwd, $rmrf, $ws, $runtime, $emptyPlan, $mkhelper) {
    $root = $mkcwd();
    putenv('AWS_SECRET_ACCESS_KEY=LEAKED_SECRET_VALUE');
    putenv('GITHUB_TOKEN=SHOULD_NOT_LEAK');
    try {
        $exe = $mkhelper($root, 'env', "echo 'HOME=' . getenv('HOME') . '|AWS=' . (getenv('AWS_SECRET_ACCESS_KEY') ?: 'none') . '|GH=' . (getenv('GITHUB_TOKEN') ?: 'none');");
        $res = (new CommandRunner(CommandRunLimits::defaults(), $runtime($root)))
            ->run($emptyPlan(), $ws($root), $exe, 'exec-env1234567');
        assertSame(false, str_contains($res->output, 'LEAKED_SECRET_VALUE'));
        assertSame(true, str_contains($res->output, 'AWS=none'));
        assertSame(true, str_contains($res->output, 'GH=none'));
        // HOME isolato nella runtime effimera dell'esecuzione, non il vero ~ del padre.
        assertSame(true, str_contains($res->output, 'HOME=' . $runtime($root)));
        assertSame(true, str_contains($res->output, 'exec-env1234567/home'));
        assertSame(false, str_contains($res->output, 'HOME=' . (getenv('HOME') ?: '/nonexistent-home') . '|'));
    } finally {
        putenv('AWS_SECRET_ACCESS_KEY');
        putenv('GITHUB_TOKEN');
        $rmrf($root);
    }
});

test('CommandRunner: la runtime effimera è rimossa a fine esecuzione', function () use ($mkcwd, $rmrf, $ws, $runtime, $emptyPlan, $mkhelper) {
    $root = $mkcwd();
    try {
        $exe = $mkhelper($root, 'noop', "echo 'ok';");
        $rtBase = $runtime($root);
        (new CommandRunner(CommandRunLimits::defaults(), $rtBase))
            ->run($emptyPlan(), $ws($root), $exe, 'exec-cleanup123');
        assertSame(false, is_dir($rtBase . '/7/exec-cleanup123'));
    } finally { $rmrf($root); }
});

test('CommandRunner: persistenza PGID rifiutata → error fail-closed, processo terminato', function () use ($mkcwd, $rmrf, $ws, $runtime, $emptyPlan, $mkhelper) {
    $root = $mkcwd();
    try {
        $exe = $mkhelper($root, 'pgid-false', 'usleep(30000000);');
        $runner = new CommandRunner(
            CommandRunLimits::defaults(),
            $runtime($root),
            onStarted: static fn (int $pgid): bool => false,
        );
        $res = $runner->run($emptyPlan(), $ws($root), $exe, 'exec-pgidfalse1');
        assertSame(CommandResult::ERROR, $res->outcome);
        assertSame('', $res->output);
        $pgid = $runner->lastProcessGroupId();
        assertSame(false, $pgid !== null && @posix_kill(-$pgid, 0));
    } finally { $rmrf($root); }
});

test('CommandRunner: eccezione persistenza PGID → error fail-closed, nessun orfano', function () use ($mkcwd, $rmrf, $ws, $runtime, $emptyPlan, $mkhelper) {
    $root = $mkcwd();
    try {
        $exe = $mkhelper($root, 'pgid-throw', 'usleep(30000000);');
        $runner = new CommandRunner(
            CommandRunLimits::defaults(),
            $runtime($root),
            onStarted: static function (int $pgid): bool {
                throw new RuntimeException('DB non disponibile');
            },
        );
        $res = $runner->run($emptyPlan(), $ws($root), $exe, 'exec-pgidthrow1');
        assertSame(CommandResult::ERROR, $res->outcome);
        assertSame('', $res->output);
        $pgid = $runner->lastProcessGroupId();
        assertSame(false, $pgid !== null && @posix_kill(-$pgid, 0));
    } finally { $rmrf($root); }
});
