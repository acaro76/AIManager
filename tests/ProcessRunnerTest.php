<?php

declare(strict_types=1);

use App\Core\Code\CodeWorkspace;
use App\Core\Code\CodeWorkspaceException;
use App\Core\Code\ProcessIdentity;
use App\Core\Code\ProcessInspector;
use App\Core\Code\ProcessPlan;
use App\Core\Code\ProcessProfile;
use App\Core\Code\ProcessResult;
use App\Core\Code\ProcessRunLimits;
use App\Core\Code\ProcessRunner;
use App\Core\Code\ProcessRuntime;
use App\Core\Code\SensitivePathPolicy;

// Fase 7 — il runner reale: avvio DETACHED (doppio-fork), pidfile con identità, processo PERSISTENTE
// riconosciuto DOPO il ritorno della funzione (== dopo la fine della richiesta), cattura firma. NON
// si avvia un server web reale: si usa un HELPER controllato (sleeper). Runtime symlink-safe e log
// cappato sono provati su ProcessRuntime senza processi.

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
    $root = $base . '/aim_prun_' . uniqid('', true);
    mkdir($root . '/public', 0777, true);
    file_put_contents($root . '/public/index.php', "<?php\n");
    file_put_contents($root . '/.env', "SECRET=1\n");
    return $root;
};
$ws = static fn (string $root): CodeWorkspace => new CodeWorkspace(7, $root, basename($root), 'active', new SensitivePathPolicy());
$runtime = static fn (string $root): string => $root . '/.prt';
$noArgs = static fn (string $docroot): array => [];
// Firma d'avvio deterministica per i test (nessuna dipendenza da `ps` reale).
$fakeInspector = static function (string $sig): ProcessInspector {
    return new class($sig) implements ProcessInspector {
        public function __construct(private string $sig) {}
        public function isAlive(int $pid): bool { return $pid > 1 && function_exists('posix_kill') && @posix_kill($pid, 0); }
        public function signature(int $pid): string { return $this->isAlive($pid) ? $this->sig : ''; }
        public function processGroupId(int $pid): ?int { $v = function_exists('posix_getpgid') ? @posix_getpgid($pid) : false; return is_int($v) && $v > 1 ? $v : null; }
    };
};
// L'helper è uno script .php eseguito come `php <script>` (interprete ESPLICITO, come in produzione
// `php -S`): il programma passato al runner è sempre PHP_BINARY, lo script è un argomento.
$mkhelper = static function (string $root, string $name, string $body): string {
    $path = $root . '/' . $name . '.php';
    file_put_contents($path, "<?php\n" . $body . "\n");
    return $path;
};
$killGroup = static function (?int $pgid): void {
    if ($pgid !== null && $pgid > 1 && function_exists('posix_kill')) { @posix_kill(-$pgid, 9); }
};

// --- ProcessRuntime: symlink-safe + log cappato + pidfile (nessun processo). ---

test('ProcessRuntime: tailLog cappa alla coda e rifiuta i symlink', function () use ($mkroot, $rmrf, $runtime) {
    $root = $mkroot();
    try {
        $prepared = ProcessRuntime::prepare($runtime($root), 7, 'exec-runtime123');
        assertSame(true, is_array($prepared));
        file_put_contents($prepared['log_file'], str_repeat('A', 5000) . 'TAIL');
        $tail = ProcessRuntime::tailLog($prepared['log_file'], 1024);
        assertSame(true, strlen($tail) <= 1024);
        assertSame(true, str_ends_with($tail, 'TAIL'));

        // symlink al log → rifiutato (fail closed).
        $link = $prepared['dir'] . '/link.log';
        @symlink($prepared['log_file'], $link);
        assertSame('', ProcessRuntime::tailLog($link, 1024));
    } finally { $rmrf($root); }
});

test('ProcessRuntime: readPidfile rifiuta JSON incompleto / symlink', function () use ($mkroot, $rmrf, $runtime) {
    $root = $mkroot();
    try {
        $prepared = ProcessRuntime::prepare($runtime($root), 7, 'exec-pidfile123');
        file_put_contents($prepared['pid_file'], '{"pid":1,"pgid":1,"run_token":""}');
        assertSame(null, ProcessRuntime::readPidfile($prepared['pid_file']));
        file_put_contents($prepared['pid_file'], json_encode(['pid' => 4242, 'pgid' => 4242, 'run_token' => 'tok']));
        $id = ProcessRuntime::readPidfile($prepared['pid_file']);
        assertSame(4242, $id['pid']);
        assertSame('tok', $id['run_token']);
    } finally { $rmrf($root); }
});

test('ProcessIdentity: richiede pid=pgid e il PGID reale coincidente', function () use ($mkroot, $rmrf, $runtime) {
    $root = $mkroot();
    try {
        $prepared = ProcessRuntime::prepare($runtime($root), 7, 'exec-ident1234');
        file_put_contents($prepared['pid_file'], json_encode(['pid' => 4242, 'pgid' => 4242, 'run_token' => 'tok']));
        $row = ['workspace_id' => 7, 'pid' => 4242, 'pgid' => 4242, 'run_token' => 'tok', 'start_signature' => 'SIG', 'execution_id' => 'exec-ident1234'];
        $inspector = static fn (?int $pgid): ProcessInspector => new class($pgid) implements ProcessInspector {
            public function __construct(private ?int $pgid) {}
            public function isAlive(int $pid): bool { return true; }
            public function signature(int $pid): string { return 'SIG'; }
            public function processGroupId(int $pid): ?int { return $this->pgid; }
        };
        assertSame(ProcessIdentity::ALIVE, ProcessIdentity::verify($row, $runtime($root), $inspector(4242)));
        assertSame(ProcessIdentity::MISMATCH, ProcessIdentity::verify($row, $runtime($root), $inspector(9999)));
        assertSame(ProcessIdentity::MISMATCH, ProcessIdentity::verify($row, $runtime($root), $inspector(null)));
        $row['pgid'] = 4243;
        assertSame(ProcessIdentity::UNKNOWN, ProcessIdentity::verify($row, $runtime($root), $inspector(4243)));
    } finally { $rmrf($root); }
});

if (!ProcessRunner::supportsProcessGroupIsolation()) {
    test('ProcessRunner: process group non disponibile — avvio disabilitato (skip esteso)', function () {
        assertSame(false, ProcessRunner::supportsProcessGroupIsolation());
    });
    return;
}

$plan = static fn (string $dir): ProcessPlan => new ProcessPlan(ProcessProfile::ID, ProcessProfile::HOST, 8000, $dir);

test('ProcessRunner: avvio → processo PERSISTENTE riconosciuto dopo il ritorno, identità verificata', function () use ($mkroot, $rmrf, $ws, $runtime, $noArgs, $fakeInspector, $mkhelper, $killGroup, $plan) {
    $root = $mkroot();
    $pgid = null;
    try {
        $exe = $mkhelper($root, 'server', 'usleep(30000000);');
        $runner = new ProcessRunner(ProcessRunLimits::defaults(), $runtime($root), $fakeInspector('SIG'));
        $res = $runner->start($plan('public'), $ws($root), PHP_BINARY, 'exec-persist1234', 'tok-persist0000000000', static fn (string $d): array => [$exe]);
        assertSame(ProcessResult::STARTED, $res->outcome);
        assertSame(true, $res->started());
        $pgid = $res->pgid;
        // PERSISTENTE: dopo il ritorno della funzione (== fine della richiesta) il processo è VIVO.
        assertSame(true, @posix_kill($res->pid, 0));
        // Identità verificabile in una "richiesta successiva" (stessa base runtime, stesso pidfile).
        $row = ['workspace_id' => 7, 'pid' => $res->pid, 'pgid' => $res->pgid, 'run_token' => $res->runToken, 'start_signature' => $res->startSignature, 'execution_id' => 'exec-persist1234'];
        assertSame(ProcessIdentity::ALIVE, ProcessIdentity::verify($row, $runtime($root), $fakeInspector('SIG')));
        // Firma divergente → MISMATCH (PID riciclato simulato): identità NON verificabile.
        assertSame(ProcessIdentity::MISMATCH, ProcessIdentity::verify($row, $runtime($root), $fakeInspector('ALTRA')));
    } finally { $killGroup($pgid); $rmrf($root); }
});

test('ProcessRunner: il server è avviato nella docroot confinata, con runtime del log creata', function () use ($mkroot, $rmrf, $ws, $runtime, $noArgs, $fakeInspector, $mkhelper, $killGroup, $plan) {
    $root = $mkroot();
    $pgid = null;
    try {
        // Il server scrive un marker via file_put_contents (indipendente dalla redirezione dello
        // stdout, che attraverso l'exec non è garantita — vedi FASE7_PIANO §log): prova che il
        // processo ESEGUE realmente e con cwd = docroot confinata.
        $exe = $mkhelper($root, 'server', "file_put_contents(getcwd() . '/ran.marker', 'ok');\nusleep(30000000);");
        $runner = new ProcessRunner(ProcessRunLimits::defaults(), $runtime($root), $fakeInspector('SIG'));
        $res = $runner->start($plan(''), $ws($root), PHP_BINARY, 'exec-run12345678', 'tok-run00000000000000', static fn (string $d): array => [$exe]);
        assertSame(ProcessResult::STARTED, $res->outcome);
        $pgid = $res->pgid;
        usleep(200000);
        assertSame(true, is_file($root . '/ran.marker')); // eseguito, cwd = docroot (radice)
        $located = ProcessRuntime::locate($runtime($root), 7, 'exec-run12345678');
        assertSame(true, is_array($located) && is_file($located['log_file'])); // runtime del log creata (symlink-safe)
    } finally { $killGroup($pgid); $rmrf($root); }
});

test('ProcessRunner: processo che muore subito dopo il pidfile non diventa running', function () use ($mkroot, $rmrf, $ws, $runtime, $fakeInspector, $mkhelper, $plan) {
    $root = $mkroot();
    try {
        // Riproduce il falso positivo osservato con `php -S` su una porta già occupata: il launcher
        // scrive il pidfile, poi il programma parte e termina immediatamente.
        $exe = $mkhelper($root, 'dies-fast', 'usleep(50000); exit(1);');
        $runner = new ProcessRunner(ProcessRunLimits::defaults(), $runtime($root), $fakeInspector('SIG'));
        $res = $runner->start($plan(''), $ws($root), PHP_BINARY, 'exec-fastfail123', 'tok-fastfail0000000', static fn (string $d): array => [$exe]);
        assertSame(ProcessResult::ERROR, $res->outcome);
        assertSame(null, ProcessRuntime::locate($runtime($root), 7, 'exec-fastfail123'));
    } finally { $rmrf($root); }
});

test('ProcessRunner: RLIMIT_FSIZE impedisce al log persistente di superare il tetto', function () use ($mkroot, $rmrf, $ws, $runtime, $fakeInspector, $mkhelper, $killGroup, $plan) {
    $root = $mkroot();
    $pgid = null;
    $limit = 32768;
    try {
        // Attesa iniziale di 200 ms prima di produrre output: l'helper è VIVO con certezza durante la
        // finestra di stabilità (start()), poi supera il tetto e viene fermato da SIGXFSZ. Rende
        // deterministica la corsa avvio-php/SIGXFSZ che rendeva `start()` intermittentemente `error`.
        $exe = $mkhelper($root, 'noisy', "usleep(200000); for (\$i = 0; \$i < 4096; \$i++) { echo str_repeat('X', 1024); } usleep(5000000);");
        $limits = new ProcessRunLimits(5.0, 1.0, 30, $limit, 4096, 0.01);
        $runner = new ProcessRunner($limits, $runtime($root), $fakeInspector('SIG'));
        $res = $runner->start($plan(''), $ws($root), PHP_BINARY, 'exec-logcap1234', 'tok-logcap000000000', static fn (string $d): array => [$exe]);
        $pgid = $res->pgid;
        // 500 ms: coprono i 200 ms di attesa dell'helper più la scrittura, così alla misura il file
        // ha già raggiunto (ed è stato troncato a) il tetto.
        usleep(500000);
        $located = ProcessRuntime::locate($runtime($root), 7, 'exec-logcap1234');
        assertSame(true, is_array($located));
        clearstatcache(true, $located['log_file']);
        $size = filesize($located['log_file']);
        assertSame(true, is_int($size) && $size <= $limit, 'log oltre RLIMIT_FSIZE');
    } finally { $killGroup($pgid); $rmrf($root); }
});

test('ProcessRunner: exe non eseguibile → error', function () use ($mkroot, $rmrf, $ws, $runtime, $noArgs, $fakeInspector, $plan) {
    $root = $mkroot();
    try {
        $res = (new ProcessRunner(ProcessRunLimits::defaults(), $runtime($root), $fakeInspector('SIG')))
            ->start($plan(''), $ws($root), $root . '/public/index.php', 'exec-notexe12345', 'tok-notexe0000000000', $noArgs);
        assertSame(ProcessResult::ERROR, $res->outcome);
    } finally { $rmrf($root); }
});

test('ProcessRunner: docroot sensibile o traversal → NEGATO (CodeWorkspaceException)', function () use ($mkroot, $rmrf, $ws, $runtime, $noArgs, $fakeInspector, $mkhelper) {
    $root = $mkroot();
    try {
        $exe = $mkhelper($root, 'server', 'usleep(1000000);');
        $runner = new ProcessRunner(ProcessRunLimits::defaults(), $runtime($root), $fakeInspector('SIG'));
        foreach (['.env', '../evil'] as $bad) {
            $threw = false;
            try {
                $runner->start(new ProcessPlan(ProcessProfile::ID, ProcessProfile::HOST, 8000, $bad), $ws($root), PHP_BINARY, 'exec-bad12345678', 'tok-bad0000000000000', static fn (string $d): array => [$exe]);
            } catch (CodeWorkspaceException) { $threw = true; }
            assertSame(true, $threw, $bad);
        }
    } finally { $rmrf($root); }
});
