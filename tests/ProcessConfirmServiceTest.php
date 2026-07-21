<?php

declare(strict_types=1);

use App\Core\Code\CodeProcessTool;
use App\Core\Code\ProcessConfirmService;
use App\Core\Code\ProcessInspector;
use App\Core\Code\ProcessPlan;
use App\Core\Code\ProcessProfile;
use App\Core\Code\ProcessRunRepository;
use App\Core\Code\ProcessRunner;
use App\Core\Database;
use App\Services\MigrationRunner;

// Fase 7 — confirm/stop/maintain end-to-end su workspace TEMPORANEO, con un HELPER controllato al
// posto di `php -S` (nessun server web reale, mai AIManager come workspace). Copre: conferma atomica
// e monouso, porta privilegiata negata, docroot traversal negato, revoca prima dell'avvio, Stop
// dell'intero albero, Stop idempotente, rifiuto di segnalare un'identità non verificabile (orphaned),
// recovery prudente di record orfani.

$rmrf = static function (string $path) use (&$rmrf): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $e) { if ($e === '.' || $e === '..') { continue; } $rmrf($path . '/' . $e); }
        @rmdir($path);
        return;
    }
    @unlink($path);
};
$killGroup = static function (?int $pgid): void {
    if ($pgid !== null && $pgid > 1 && function_exists('posix_kill')) { @posix_kill(-$pgid, 9); }
};
$fakeInspector = static function (string $sig): ProcessInspector {
    return new class($sig) implements ProcessInspector {
        public function __construct(private string $sig) {}
        public function isAlive(int $pid): bool { return $pid > 1 && function_exists('posix_kill') && @posix_kill($pid, 0); }
        public function signature(int $pid): string { return $this->isAlive($pid) ? $this->sig : ''; }
        public function processGroupId(int $pid): ?int { $v = function_exists('posix_getpgid') ? @posix_getpgid($pid) : false; return is_int($v) && $v > 1 ? $v : null; }
    };
};
// Helper .php eseguito come `php <script>` (interprete ESPLICITO): il programma è PHP_BINARY (via
// programResolver iniettato) e lo script è l'argv (via argvBuilder iniettato), al posto di `php -S`.
$mkhelper = static function (string $root, string $name, string $body): string {
    $path = $root . '/' . $name . '.php';
    file_put_contents($path, "<?php\n" . $body . "\n");
    return $path;
};
$svcWith = static function (Database $db, string $rtBase, ProcessInspector $insp, string $exe): ProcessConfirmService {
    return new ProcessConfirmService(
        $db, $rtBase, null, $insp,
        static fn (): ?string => PHP_BINARY,
        static fn (ProcessPlan $plan, string $docroot): array => [$exe]
    );
};

$make = static function () use ($rmrf): array {
    $rootReal = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
    $wsRoot = $rootReal . '/aim_pconf_ws_' . uniqid('', true);
    mkdir($wsRoot . '/public', 0777, true);
    file_put_contents($wsRoot . '/public/index.php', "<?php\n");
    file_put_contents($wsRoot . '/.env', "SECRET=1\n");
    $wsRoot = realpath($wsRoot);

    $dbPath = sys_get_temp_dir() . '/aim_pconf_' . uniqid('', true) . '.sqlite';
    $db = new Database($dbPath);
    $mig = dirname(__DIR__);
    (new MigrationRunner($db, $mig . '/database/migrations', $mig . '/database/seeds'))->run();
    $now = date('c');
    $db->execute('INSERT INTO code_workspaces (root_path, name, status, authorized_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', [$wsRoot, '', 'active', $now, $now, $now]);
    $wid = $db->lastInsertId();
    $db->execute('INSERT INTO code_sessions (workspace_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$wid, 't', 'active', $now, $now]);
    $sid = $db->lastInsertId();
    $db->execute('INSERT INTO code_conversations (code_session_id, role, content, provider, created_at) VALUES (?, ?, ?, ?, ?)', [$sid, 'assistant', 'x', 'code', $now]);
    $aid = $db->lastInsertId();
    $runtimeBase = $wsRoot . '/.prt';
    $cleanup = static function () use ($dbPath, $wsRoot, $rmrf): void {
        foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) { if (is_file($f)) { @unlink($f); } }
        $rmrf($wsRoot);
    };
    return [$db, $wid, $sid, $aid, $wsRoot, $runtimeBase, $cleanup];
};

$pending = static function (Database $db, int $wid, int $sid, int $aid, string $root, string $processId, int $port, string $dir): string {
    $plan = new ProcessPlan(ProcessProfile::ID, ProcessProfile::HOST, $port, $dir);
    $digest = $plan->digest($root, $wid, $sid, CodeProcessTool::POLICY_VERSION);
    (new ProcessRunRepository($db))->createPending(
        $wid, $sid, $aid, $processId, $digest, CodeProcessTool::POLICY_VERSION,
        ProcessProfile::ID, ProcessProfile::PROGRAM, $plan->displaySummary(400), ProcessProfile::HOST, $port, $dir
    );
    return $digest;
};

if (!ProcessRunner::supportsProcessGroupIsolation()) {
    test('ProcessConfirmService: ambiente senza process group (skip esecuzione)', function () {
        assertSame(false, ProcessRunner::supportsProcessGroupIsolation());
    });
    return;
}

test('confirm: proposta ammissibile → running, processo vivo; conferma MONOUSO', function () use ($make, $pending, $fakeInspector, $mkhelper, $killGroup, $svcWith) {
    [$db, $wid, $sid, $aid, $root, $rtBase, $cleanup] = $make();
    $pgid = null;
    try {
        $exe = $mkhelper($root, 'srv', 'usleep(30000000);');
        $digest = $pending($db, $wid, $sid, $aid, $root, 'proc-aaaaaaaaaaaaaaaa', 8123, 'public');
        $svc = $svcWith($db, $rtBase, $fakeInspector('SIG'), $exe);

        $res = $svc->confirm($wid, $sid, 'proc-aaaaaaaaaaaaaaaa', $digest);
        assertSame(true, $res['ok']);
        assertSame('running', $res['status']);
        $row = $db->fetch('SELECT * FROM code_processes WHERE process_id = ?', ['proc-aaaaaaaaaaaaaaaa']);
        assertSame('running', (string) $row['state']);
        $pgid = (int) $row['pgid'];
        assertSame(true, @posix_kill((int) $row['pid'], 0));

        // MONOUSO: la pending è stata consumata (starting/running) → una seconda conferma non trova nulla.
        $again = $svc->confirm($wid, $sid, 'proc-aaaaaaaaaaaaaaaa', $digest);
        assertSame('not_found', $again['status']);
    } finally { $killGroup($pgid); $cleanup(); }
});

test('confirm: porta privilegiata → denied (nessun avvio)', function () use ($make, $pending, $fakeInspector, $mkhelper, $svcWith) {
    [$db, $wid, $sid, $aid, $root, $rtBase, $cleanup] = $make();
    try {
        $exe = $mkhelper($root, 'srv', 'usleep(1000000);');
        $digest = $pending($db, $wid, $sid, $aid, $root, 'proc-bbbbbbbbbbbbbbbb', 80, 'public');
        $svc = $svcWith($db, $rtBase, $fakeInspector('SIG'), $exe);
        $res = $svc->confirm($wid, $sid, 'proc-bbbbbbbbbbbbbbbb', $digest);
        assertSame('denied', $res['status']);
    } finally { $cleanup(); }
});

test('confirm: docroot traversal → denied (bind fallito, nessun avvio)', function () use ($make, $pending, $fakeInspector, $mkhelper, $svcWith) {
    [$db, $wid, $sid, $aid, $root, $rtBase, $cleanup] = $make();
    try {
        $exe = $mkhelper($root, 'srv', 'usleep(1000000);');
        $digest = $pending($db, $wid, $sid, $aid, $root, 'proc-cccccccccccccccc', 8123, '../evil');
        $svc = $svcWith($db, $rtBase, $fakeInspector('SIG'), $exe);
        $res = $svc->confirm($wid, $sid, 'proc-cccccccccccccccc', $digest);
        assertSame('denied', $res['status']);
    } finally { $cleanup(); }
});

test('confirm: workspace revocato PRIMA dell\'avvio → unavailable', function () use ($make, $pending, $fakeInspector, $mkhelper, $svcWith) {
    [$db, $wid, $sid, $aid, $root, $rtBase, $cleanup] = $make();
    try {
        $exe = $mkhelper($root, 'srv', 'usleep(1000000);');
        $digest = $pending($db, $wid, $sid, $aid, $root, 'proc-dddddddddddddddd', 8123, 'public');
        $db->execute('UPDATE code_workspaces SET status = ? WHERE id = ?', ['revoked', $wid]);
        $svc = $svcWith($db, $rtBase, $fakeInspector('SIG'), $exe);
        $res = $svc->confirm($wid, $sid, 'proc-dddddddddddddddd', $digest);
        assertSame('unavailable', $res['status']);
        assertSame('pending', (string) $db->fetch('SELECT state FROM code_processes WHERE process_id = ?', ['proc-dddddddddddddddd'])['state']);
    } finally { $cleanup(); }
});

test('stop: termina l\'INTERO albero (figlio compreso) ed è IDEMPOTENTE', function () use ($make, $pending, $fakeInspector, $mkhelper, $killGroup, $svcWith) {
    [$db, $wid, $sid, $aid, $root, $rtBase, $cleanup] = $make();
    $pgid = null;
    try {
        // Helper che forka un figlio a vita lunga e ne scrive il pid in un file laterale (indipendente
        // dallo stdout, non garantito attraverso l'exec). Il figlio resta nello STESSO process group.
        $exe = $mkhelper($root, 'srv', "\$pid = pcntl_fork();\nif (\$pid === -1) { exit(2); }\nif (\$pid === 0) { usleep(30000000); exit(0); }\nfile_put_contents('" . $root . "/child.pid', (string) \$pid);\nusleep(30000000);");
        $digest = $pending($db, $wid, $sid, $aid, $root, 'proc-eeeeeeeeeeeeeeee', 8123, '');
        $svc = $svcWith($db, $rtBase, $fakeInspector('SIG'), $exe);
        $res = $svc->confirm($wid, $sid, 'proc-eeeeeeeeeeeeeeee', $digest);
        assertSame('running', $res['status']);
        $row = $db->fetch('SELECT * FROM code_processes WHERE process_id = ?', ['proc-eeeeeeeeeeeeeeee']);
        $pgid = (int) $row['pgid'];
        usleep(250000);
        $childPid = is_file($root . '/child.pid') ? (int) file_get_contents($root . '/child.pid') : 0;
        assertSame(true, $childPid > 1);

        $stop = $svc->stop($wid, $sid, 'proc-eeeeeeeeeeeeeeee');
        assertSame('stopped', $stop['status']);
        usleep(250000);
        assertSame(false, @posix_kill((int) $row['pid'], 0)); // leader morto
        assertSame(false, @posix_kill($childPid, 0));         // figlio morto (intero albero)

        // IDEMPOTENTE: un secondo stop su una riga già terminale torna comunque ok.
        $again = $svc->stop($wid, $sid, 'proc-eeeeeeeeeeeeeeee');
        assertSame(true, $again['ok']);
        assertSame('stopped', $again['status']);
    } finally { $killGroup($pgid); $cleanup(); }
});

test('stop: identità NON verificabile (PID riciclato) → orphaned, NESSUN segnale', function () use ($make, $pending, $fakeInspector, $mkhelper, $killGroup, $svcWith) {
    [$db, $wid, $sid, $aid, $root, $rtBase, $cleanup] = $make();
    $pgid = null;
    try {
        $exe = $mkhelper($root, 'srv', 'usleep(30000000);');
        $digest = $pending($db, $wid, $sid, $aid, $root, 'proc-ffffffffffffffff', 8123, 'public');
        // Avvio con firma 'SIG'.
        $svc = $svcWith($db, $rtBase, $fakeInspector('SIG'), $exe);
        $res = $svc->confirm($wid, $sid, 'proc-ffffffffffffffff', $digest);
        assertSame('running', $res['status']);
        $row = $db->fetch('SELECT * FROM code_processes WHERE process_id = ?', ['proc-ffffffffffffffff']);
        $pgid = (int) $row['pgid'];

        // Stop con un inspector che riporta una firma DIVERSA → MISMATCH: fail closed, nessun segnale.
        $svcStale = $svcWith($db, $rtBase, $fakeInspector('DIVERSA'), $exe);
        $stop = $svcStale->stop($wid, $sid, 'proc-ffffffffffffffff');
        assertSame('orphaned', $stop['status']);
        // Il processo reale NON è stato segnalato: è ancora VIVO.
        assertSame(true, @posix_kill((int) $row['pid'], 0));
    } finally { $killGroup($pgid); $cleanup(); }
});

test('maintain: una riga running il cui processo è morto → orphaned (solo DB, nessun segnale)', function () use ($make, $pending, $fakeInspector, $mkhelper, $killGroup, $svcWith) {
    [$db, $wid, $sid, $aid, $root, $rtBase, $cleanup] = $make();
    try {
        $exe = $mkhelper($root, 'srv', 'usleep(30000000);');
        $digest = $pending($db, $wid, $sid, $aid, $root, 'proc-gggggggggggggggg', 8123, 'public');
        $svc = $svcWith($db, $rtBase, $fakeInspector('SIG'), $exe);
        $res = $svc->confirm($wid, $sid, 'proc-gggggggggggggggg', $digest);
        assertSame('running', $res['status']);
        $row = $db->fetch('SELECT * FROM code_processes WHERE process_id = ?', ['proc-gggggggggggggggg']);
        // Simula un crash del server: abbatti il gruppo fuori dal lifecycle.
        @posix_kill(-((int) $row['pgid']), 9);
        usleep(200000);
        // maintain riconcilia: il processo è morto → orphaned.
        $svc->maintain($wid, $sid);
        assertSame('orphaned', (string) $db->fetch('SELECT state FROM code_processes WHERE process_id = ?', ['proc-gggggggggggggggg'])['state']);
    } finally { $cleanup(); }
});
