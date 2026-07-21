<?php

declare(strict_types=1);

use App\Core\Code\CodePatchOperationRepository;
use App\Core\Database;
use App\Controllers\SystemController;
use App\Services\MigrationRunner;
use App\Services\SqliteBackupService;

// Fase 10 / Step 3 — affidabilità operativa: backup pre-migrazione, identità del servizio, filtro
// delle proposte scadute e cablaggio della manutenzione. Nessun server avviato, mai il DB reale.

$rmrf = static function (string $path) use (&$rmrf): void {
    if (is_link($path)) { @unlink($path); return; }
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $e) { if ($e !== '.' && $e !== '..') { $rmrf($path . '/' . $e); } }
        @rmdir($path);
        return;
    }
    @unlink($path);
};

$freshDbPath = static function (): string {
    return sys_get_temp_dir() . '/aimanager_rel_' . uniqid('', true) . '.sqlite';
};

$cleanupDb = static function (string $path): void {
    foreach ([$path, $path . '-wal', $path . '-shm'] as $f) { if (is_file($f)) { @unlink($f); } }
};

// Directory di migrazioni FINTE (mai quelle reali): la prima crea `settings` (richiesta da run()).
$makeMigrations = static function (): string {
    $dir = sys_get_temp_dir() . '/aimanager_mig_' . uniqid('', true);
    mkdir($dir, 0700, true);
    file_put_contents($dir . '/001_settings.php', "<?php\nreturn function (\$db) {\n    \$db->execute('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT, updated_at TEXT)');\n    \$db->execute('CREATE TABLE demo (id INTEGER PRIMARY KEY, v TEXT)');\n};\n");
    file_put_contents($dir . '/002_more.php', "<?php\nreturn function (\$db) {\n    \$db->execute(\"INSERT INTO demo (v) VALUES ('seed-from-migration')\");\n};\n");
    return $dir;
};

// --- 1) Backup automatico prima delle migrazioni -----------------------------------------

test('reliability: pending migrations rilevate senza mutazioni; run() le azzera', function () use ($freshDbPath, $cleanupDb, $makeMigrations, $rmrf) {
    $dbPath = $freshDbPath();
    $mig = $makeMigrations();
    $seeds = sys_get_temp_dir() . '/aimanager_seed_' . uniqid('', true);
    mkdir($seeds, 0700, true);
    try {
        $db = new Database($dbPath);
        $runner = new MigrationRunner($db, $mig, $seeds);

        // Sola lettura: nessuna tabella `migrations` creata dalla lettura delle pendenti.
        assertSame(['001_settings.php', '002_more.php'], $runner->pendingMigrations());
        assertSame(null, $db->fetch("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'migrations'"));

        $runner->run();
        assertSame([], $runner->pendingMigrations());
    } finally { $cleanupDb($dbPath); $rmrf($mig); $rmrf($seeds); }
});

test('reliability: backup coerente con WAL non checkpointato, permessi e SHA-256', function () use ($freshDbPath, $cleanupDb, $rmrf) {
    $dbPath = $freshDbPath();
    $backupDir = sys_get_temp_dir() . '/aimanager_bkp_' . uniqid('', true);
    try {
        $db = new Database($dbPath);
        $db->pdo()->exec('PRAGMA wal_autocheckpoint = 0'); // impedisci ogni checkpoint automatico
        $db->execute('CREATE TABLE demo (id INTEGER PRIMARY KEY, v TEXT)');
        $db->execute("INSERT INTO demo (v) VALUES ('wal-non-checkpointato')");

        $res = (new SqliteBackupService($db, $backupDir))->backup();

        assertSame(true, is_file($res['path']));
        assertSame(0700, fileperms($backupDir) & 0777);
        assertSame(0600, fileperms($res['path']) & 0777);
        assertSame(hash_file('sha256', $res['path']), $res['sha256']);
        assertSame(false, is_link($backupDir));

        // Il backup è LEGGIBILE e contiene il dato che era solo in WAL → coerenza dimostrata.
        $bpdo = new \PDO('sqlite:' . $res['path'], null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        assertSame('wal-non-checkpointato', (string) $bpdo->query('SELECT v FROM demo')->fetch()['v']);
        $bpdo = null;
    } finally { $cleanupDb($dbPath); $rmrf($backupDir); }
});

test('reliability: nessun backup su DB nuovo o senza pending (condizione di boot)', function () use ($freshDbPath, $cleanupDb, $makeMigrations, $rmrf) {
    $dbPath = $freshDbPath();
    $mig = $makeMigrations();
    $seeds = sys_get_temp_dir() . '/aimanager_seed_' . uniqid('', true);
    mkdir($seeds, 0700, true);
    try {
        // DB NUOVO: il file non esiste PRIMA di aprirlo (come in App::boot).
        $existedBefore = is_file($dbPath);
        assertSame(false, $existedBefore);

        $db = new Database($dbPath); // ora il file esiste
        $runner = new MigrationRunner($db, $mig, $seeds);
        assertSame(true, $runner->pendingMigrations() !== []);                       // pendenti presenti
        assertSame(false, $existedBefore && $runner->pendingMigrations() !== []);    // ma DB nuovo → nessun backup

        $runner->run();
        // DB aggiornato: nessuna pendente → nessun backup (anche se il file ora esiste).
        assertSame(false, is_file($dbPath) && $runner->pendingMigrations() !== []);
    } finally { $cleanupDb($dbPath); $rmrf($mig); $rmrf($seeds); }
});

test('reliability: DB preesistente con migrations assente/vuota + pendenti ⇒ backup richiesto', function () use ($freshDbPath, $cleanupDb, $makeMigrations, $rmrf) {
    $dbPath = $freshDbPath();
    $mig = $makeMigrations();
    $seeds = sys_get_temp_dir() . '/aimanager_seed_' . uniqid('', true);
    mkdir($seeds, 0700, true);
    try {
        // DB PREESISTENTE ma SENZA tabella `migrations` (la vecchia logica lo avrebbe migrato senza backup).
        $seed = new Database($dbPath);
        $seed->execute('CREATE TABLE preesistente (id INTEGER PRIMARY KEY)');
        $seed = null;

        $existedBefore = is_file($dbPath); // catturato PRIMA di riaprire, come in App::boot
        assertSame(true, $existedBefore);

        $db = new Database($dbPath);
        $runner = new MigrationRunner($db, $mig, $seeds);
        assertSame(true, $runner->pendingMigrations() !== []);                    // pendenti
        assertSame(true, $existedBefore && $runner->pendingMigrations() !== []);  // → BACKUP richiesto
    } finally { $cleanupDb($dbPath); $rmrf($mig); $rmrf($seeds); }
});

test('reliability: directory di backup non sicura (symlink) → il backup fallisce (migrazione non parte)', function () use ($freshDbPath, $cleanupDb, $rmrf) {
    $dbPath = $freshDbPath();
    $target = sys_get_temp_dir() . '/aimanager_bt_' . uniqid('', true);
    $link = sys_get_temp_dir() . '/aimanager_bl_' . uniqid('', true);
    mkdir($target, 0700, true);
    symlink($target, $link);
    try {
        $db = new Database($dbPath);
        $threw = false;
        try { (new SqliteBackupService($db, $link))->backup(); }
        catch (\Throwable) { $threw = true; }
        assertSame(true, $threw); // backup fallito → in App::boot le migrazioni non verrebbero eseguite
    } finally { $cleanupDb($dbPath); @unlink($link); $rmrf($target); }
});

test('reliability: fallimento dopo la creazione del backup → nessun file parziale residuo', function () use ($freshDbPath, $cleanupDb, $rmrf) {
    $dbPath = $freshDbPath();
    $backupDir = sys_get_temp_dir() . '/aimanager_bkp_' . uniqid('', true);
    mkdir($backupDir, 0700, true);
    try {
        $db = new Database($dbPath);
        // Sorgente con VIOLAZIONE FK (inserita con FK OFF): VACUUM INTO crea il file, ma la verifica
        // (foreign_key_check) fallisce DOPO → il file appena generato dev'essere rimosso.
        $db->pdo()->exec('PRAGMA foreign_keys = OFF');
        $db->execute('CREATE TABLE parent (id INTEGER PRIMARY KEY)');
        $db->execute('CREATE TABLE child (id INTEGER PRIMARY KEY, pid INTEGER REFERENCES parent(id))');
        $db->execute('INSERT INTO child (pid) VALUES (999)');

        $threw = false;
        try { (new SqliteBackupService($db, $backupDir))->backup(); }
        catch (\Throwable) { $threw = true; }
        assertSame(true, $threw);
        // Nessun backup parziale residuo (eliminato solo quel file, directory pulita).
        assertSame([], glob($backupDir . '/*.sqlite'));
    } finally { $cleanupDb($dbPath); $rmrf($backupDir); }
});

test('reliability: App esegue il backup PRIMA del MigrationRunner', function () {
    $src = (string) file_get_contents(dirname(__DIR__) . '/app/Core/App.php');
    $posBackup = strpos($src, 'SqliteBackupService');
    $posRun = strpos($src, '$runner->run()');
    $posCond = strpos($src, "\$databaseExisted && \$runner->pendingMigrations() !== []");
    assertSame(true, $posCond !== false, 'condizione file preesistente && pending');
    assertSame(true, $posBackup !== false && $posRun !== false && $posBackup < $posRun, 'backup prima di run()');
});

// --- 2) Identità del servizio ------------------------------------------------------------

test('reliability: route /system/health registrata e risposta stabile', function () {
    assertSame('AIManager:ok', SystemController::HEALTH_BODY);
    $idx = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
    assertSame(true, str_contains($idx, "get('/system/health'"), 'route health registrata');
    assertSame(true, str_contains($idx, "[SystemController::class, 'health']"));
});

test('reliability: launcher verifica la health, distingue AIManager da altro servizio e termina solo il PID nuovo', function () {
    $sh = (string) file_get_contents(dirname(__DIR__) . '/bin/launch.sh');
    assertSame(true, str_contains($sh, '/system/health'), 'usa la health');
    assertSame(true, str_contains($sh, 'AIManager:ok'), 'confronta l\'identità stabile');
    assertSame(true, str_contains($sh, 'is_aimanager'), 'distingue AIManager da altro servizio');
    assertSame(true, str_contains($sh, '127.0.0.1'), 'bind locale conservato');
    assertSame(true, str_contains($sh, 'umask 077'), 'umask sui file runtime');
    assertSame(true, str_contains($sh, 'kill "$SERVER_PID"'), 'termina solo il PID appena avviato');
    assertSame(true, str_contains($sh, 'server.log'), 'errore rimanda al log');
    assertSame(true, str_contains($sh, "exit 0"), 'contratto exit 0 conservato');
});

// --- 3) Recovery e cleanup proporzionati -------------------------------------------------

test('reliability: proposta patch scaduta non è più pendente (predicato puro)', function () {
    $now = 1_000_000_000;
    assertSame(false, CodePatchOperationRepository::isPendingVisible(['status' => 'proposed', 'expires_at' => date('c', $now - 100)], $now));
    assertSame(true, CodePatchOperationRepository::isPendingVisible(['status' => 'proposed', 'expires_at' => date('c', $now + 100)], $now));
    assertSame(true, CodePatchOperationRepository::isPendingVisible(['status' => 'applied', 'expires_at' => date('c', $now - 100)], $now)); // storica: mostrata
    assertSame(true, CodePatchOperationRepository::isPendingVisible(['status' => 'proposed'], $now)); // senza scadenza
    assertSame(true, CodePatchOperationRepository::isPendingVisible(['status' => 'proposed', 'expires_at' => ''], $now));
});

test('reliability: il refresh Code richiama maintain/expire PRIMA della lettura; process maintain invariato', function () {
    $cc = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/CodeController.php');

    $posCmdMaint = strpos($cc, '$this->commandService()->maintain()');
    $posCmdHist  = strpos($cc, 'new CommandRunRepository($this->app->db)');
    assertSame(true, $posCmdMaint !== false && $posCmdMaint < $posCmdHist, 'command maintain prima delle card');

    $posGitExpire = strpos($cc, '$gitRepo->expire()');
    $posGitHist   = strpos($cc, '$gitRepo->forHistory(');
    assertSame(true, $posGitExpire !== false && $posGitExpire < $posGitHist, 'git expire prima dello storico');

    // patch: filtro delle scadute nel rendering (nessuna mutazione in GET).
    assertSame(true, str_contains($cc, 'CodePatchOperationRepository::isPendingVisible'), 'filtro patch scadute');

    // recovery processi esistente invariato.
    assertSame(true, str_contains($cc, '$this->processService()->maintain('), 'process maintain conservato');
});
