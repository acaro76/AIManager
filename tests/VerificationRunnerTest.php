<?php

declare(strict_types=1);

use App\Core\Code\VerificationLimits;
use App\Core\Code\VerificationProfileRegistry;
use App\Core\Code\VerificationResult;
use App\Core\Code\VerificationRunner;

$hasBin = static function (string $bin): bool {
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
        if ($dir !== '' && is_file(rtrim($dir, '/') . '/' . $bin) && is_executable(rtrim($dir, '/') . '/' . $bin)) {
            return true;
        }
    }
    return false;
};

// Fase 5 — l'esecutore: processi reali (php, sempre presente dove gira la suite), timeout con
// terminazione, Stop, output cappato, e la garanzia argv (nessuna shell). Nessun DB, nessun server.

$mkcwd = static function (): string {
    $base = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
    $dir = $base . '/aimanager_vrun_' . uniqid('', true);
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

test('runner: exit 0 → passed, con output catturato', function () use ($mkcwd, $rmrf) {
    $cwd = $mkcwd();
    try {
        $runner = new VerificationRunner(VerificationLimits::defaults());
        $res = $runner->run(['php', '-r', 'fwrite(STDOUT, "tutto bene");'], $cwd);
        assertSame(VerificationResult::PASSED, $res->outcome);
        assertSame(0, $res->exitCode);
        assertSame(true, str_contains($res->output, 'tutto bene'));
    } finally {
        $rmrf($cwd);
    }
});

test('runner: exit != 0 → failed, con l\'exit code reale', function () use ($mkcwd, $rmrf) {
    $cwd = $mkcwd();
    try {
        $res = (new VerificationRunner(VerificationLimits::defaults()))->run(['php', '-r', 'fwrite(STDERR, "male"); exit(3);'], $cwd);
        assertSame(VerificationResult::FAILED, $res->outcome);
        assertSame(3, $res->exitCode);
        assertSame(true, str_contains($res->output, 'male'));
    } finally {
        $rmrf($cwd);
    }
});

test('runner: supera il timeout → timed_out e processo terminato', function () use ($mkcwd, $rmrf) {
    $cwd = $mkcwd();
    try {
        $limits = new VerificationLimits(maxSeconds: 0.4, maxOutputBytes: 4096, maxRunsPerTurn: 3);
        $res = (new VerificationRunner($limits))->run(['php', '-r', 'usleep(4000000);'], $cwd);
        assertSame(VerificationResult::TIMED_OUT, $res->outcome);
        assertSame(true, $res->timedOut);
        // Terminato ben prima dei 4s richiesti dal processo.
        assertSame(true, $res->durationMs < 2500);
    } finally {
        $rmrf($cwd);
    }
});

test('runner: Stop dell\'utente → killed', function () use ($mkcwd, $rmrf) {
    $cwd = $mkcwd();
    try {
        $res = (new VerificationRunner(VerificationLimits::defaults(), static fn (): bool => true))
            ->run(['php', '-r', 'usleep(4000000);'], $cwd);
        assertSame(VerificationResult::KILLED, $res->outcome);
    } finally {
        $rmrf($cwd);
    }
});

test('runner: output oltre il tetto → troncato al limite', function () use ($mkcwd, $rmrf) {
    $cwd = $mkcwd();
    try {
        $limits = new VerificationLimits(maxSeconds: 10.0, maxOutputBytes: 100, maxRunsPerTurn: 3);
        $res = (new VerificationRunner($limits))->run(['php', '-r', 'echo str_repeat("x", 50000);'], $cwd);
        assertSame(true, $res->truncated);
        assertSame(100, $res->outputBytes);
        assertSame(true, strlen($res->output) <= 100);
    } finally {
        $rmrf($cwd);
    }
});

test('runner: nessuna shell — i metacaratteri sono argomenti letterali, non comandi', function () use ($mkcwd, $rmrf) {
    $cwd = $mkcwd();
    try {
        // Se ci fosse una shell, "$(touch pwned)" / "&& touch pwned2" creerebbero file. Con argv no.
        (new VerificationRunner(VerificationLimits::defaults()))->run(
            ['php', '-r', 'echo count($argv);', '$(touch pwned)', '&&', 'touch', 'pwned2'],
            $cwd
        );
        assertSame(false, is_file($cwd . '/pwned'));
        assertSame(false, is_file($cwd . '/pwned2'));
    } finally {
        $rmrf($cwd);
    }
});

test('runner: cwd inesistente o argv vuoto → error, nessun crash', function () use ($mkcwd, $rmrf) {
    $runner = new VerificationRunner(VerificationLimits::defaults());
    assertSame(VerificationResult::ERROR, $runner->run([], sys_get_temp_dir())->outcome);
    assertSame(VerificationResult::ERROR, $runner->run(['php', '-v'], '/percorso/che/non/esiste/mai')->outcome);
});

test('runner: py-syntax NON è mutante — nessun __pycache__ né .pyc', function () use ($mkcwd, $rmrf, $hasBin) {
    if (!$hasBin('python3')) {
        return; // ambiente senza python3: il rilevamento non lo offrirebbe comunque
    }
    $cwd = $mkcwd();
    try {
        file_put_contents($cwd . '/m.py', "x = 1\n");
        $profile = (new VerificationProfileRegistry())->find('py-syntax');
        $res = (new VerificationRunner(VerificationLimits::defaults()))->run($profile->render('m.py'), $cwd);
        assertSame(VerificationResult::PASSED, $res->outcome);
        // Nessun bytecode scritto: né la cartella __pycache__ né file .pyc.
        assertSame(false, is_dir($cwd . '/__pycache__'));
        $pyc = glob($cwd . '/*.pyc') ?: [];
        assertSame(0, count($pyc));
    } finally {
        $rmrf($cwd);
    }
});

test('runner: py-syntax su file con errore di sintassi → failed', function () use ($mkcwd, $rmrf, $hasBin) {
    if (!$hasBin('python3')) {
        return;
    }
    $cwd = $mkcwd();
    try {
        file_put_contents($cwd . '/bad.py', "def broken(\n");
        $profile = (new VerificationProfileRegistry())->find('py-syntax');
        $res = (new VerificationRunner(VerificationLimits::defaults()))->run($profile->render('bad.py'), $cwd);
        assertSame(VerificationResult::FAILED, $res->outcome);
    } finally {
        $rmrf($cwd);
    }
});

test('runner: su timeout abbatte l\'INTERO albero (anche i nipoti)', function () use ($mkcwd, $rmrf) {
    // Richiede pcntl/posix per l'isolamento del gruppo; altrove il fallback è mono-processo.
    if (!function_exists('pcntl_exec') || !function_exists('posix_setsid') || !function_exists('posix_kill')) {
        return;
    }
    $cwd = $mkcwd();
    try {
        // Un NIPOTE che scriverebbe un marker dopo 2s, avviato da un figlio che dorme 4s.
        file_put_contents($cwd . '/inner.php', "<?php usleep(2000000); file_put_contents(__DIR__ . '/grandchild.txt', 'x');\n");
        file_put_contents(
            $cwd . '/outer.php',
            "<?php \$d=[['file','/dev/null','r'],['file','/dev/null','w'],['file','/dev/null','w']];"
            . " proc_open([PHP_BINARY, __DIR__.'/inner.php'], \$d, \$p); usleep(4000000);\n"
        );
        $limits = new VerificationLimits(maxSeconds: 0.5, maxOutputBytes: 4096, maxRunsPerTurn: 3);
        $res = (new VerificationRunner($limits))->run(['php', 'outer.php'], $cwd);
        assertSame(VerificationResult::TIMED_OUT, $res->outcome);
        // Oltre i 2s del nipote: se il gruppo fosse sopravvissuto, il marker esisterebbe.
        usleep(2600000);
        assertSame(false, is_file($cwd . '/grandchild.txt'));
    } finally {
        $rmrf($cwd);
    }
});

test('runner: il LEADER termina subito ma il FIGLIO resta → il figlio non sopravvive', function () use ($mkcwd, $rmrf) {
    if (!function_exists('pcntl_exec') || !function_exists('posix_setsid') || !function_exists('posix_kill')) {
        return;
    }
    $cwd = $mkcwd();
    try {
        // Il figlio scriverebbe un marker dopo 2s; il LEADER lo avvia e ESCE subito (exit 0).
        file_put_contents($cwd . '/child.php', "<?php usleep(2000000); file_put_contents(__DIR__ . '/leftover.txt', 'x');\n");
        file_put_contents(
            $cwd . '/leader.php',
            "<?php \$d=[['file','/dev/null','r'],['file','/dev/null','w'],['file','/dev/null','w']];"
            . " proc_open([PHP_BINARY, __DIR__.'/child.php'], \$d, \$p); exit(0);\n"
        );
        // Il leader esce con 0: l'esito è "passed", ma la terminazione DEVE spazzare il figlio.
        $res = (new VerificationRunner(VerificationLimits::defaults()))->run(['php', 'leader.php'], $cwd);
        assertSame(VerificationResult::PASSED, $res->outcome);
        usleep(2600000);
        assertSame(false, is_file($cwd . '/leftover.txt'));
    } finally {
        $rmrf($cwd);
    }
});

test('runner: su Stop abbatte l\'intero albero', function () use ($mkcwd, $rmrf) {
    if (!function_exists('pcntl_exec') || !function_exists('posix_setsid') || !function_exists('posix_kill')) {
        return;
    }
    $cwd = $mkcwd();
    try {
        file_put_contents($cwd . '/inner.php', "<?php usleep(2000000); file_put_contents(__DIR__ . '/grandchild.txt', 'x');\n");
        file_put_contents(
            $cwd . '/outer.php',
            "<?php \$d=[['file','/dev/null','r'],['file','/dev/null','w'],['file','/dev/null','w']];"
            . " proc_open([PHP_BINARY, __DIR__.'/inner.php'], \$d, \$p); usleep(4000000);\n"
        );
        $res = (new VerificationRunner(VerificationLimits::defaults(), static fn (): bool => true))
            ->run(['php', 'outer.php'], $cwd);
        assertSame(VerificationResult::KILLED, $res->outcome);
        usleep(2600000);
        assertSame(false, is_file($cwd . '/grandchild.txt'));
    } finally {
        $rmrf($cwd);
    }
});
