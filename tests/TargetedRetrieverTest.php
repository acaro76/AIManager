<?php

declare(strict_types=1);

use App\Core\Code\CodeWorkspace;
use App\Core\Code\RetrievalLimits;
use App\Core\Code\SensitivePathPolicy;
use App\Core\Code\TargetedRetriever;

// F1.2 — recupero mirato end-to-end su repo temporaneo: scan* passati al RepoScanner,
// ricerca+selezione+lettura confinata, metriche/limiti, esclusioni di sicurezza.

$rmrf = static function (string $path) use (&$rmrf): void {
    if (is_link($path)) { @unlink($path); return; }
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $e) {
            if ($e === '.' || $e === '..') { continue; }
            $rmrf($path . '/' . $e);
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
};

$tmpBase = static function (): string {
    $base = realpath(sys_get_temp_dir());
    return $base === false ? sys_get_temp_dir() : $base;
};

$ws = static fn (string $root): CodeWorkspace => new CodeWorkspace(1, $root, '', 'active', new SensitivePathPolicy());

$limits = static function (array $o = []): RetrievalLimits {
    $a = array_merge([
        'scanMaxDepth' => 12, 'scanMaxFiles' => 2000, 'scanMaxReadBytes' => 262144, 'scanMaxSeconds' => 5.0,
        'searchMaxFilesScanned' => 2000, 'searchMaxMatches' => 100, 'searchMaxBytesPerFile' => 262144,
        'searchMaxTotalBytes' => 4194304, 'searchMaxSeconds' => 5.0,
        'readMaxFiles' => 12, 'readMaxBytesPerFile' => 65536, 'readMaxTotalBytes' => 262144, 'contextMaxChars' => 48000,
    ], $o);
    return new RetrievalLimits(...$a);
};

// Repo campione con: file rilevante, rumore (vendor/.git), sensibile, binario, symlink.
$mkrepo = static function () use ($tmpBase): string {
    $root = $tmpBase() . '/aimanager_tr_' . uniqid('', true);
    mkdir($root . '/app/Auth', 0777, true);
    mkdir($root . '/vendor', 0777, true);
    mkdir($root . '/.git', 0777, true);
    file_put_contents($root . '/app/Auth/Login.php', "<?php\nfunction login() { return true; }\n");
    file_put_contents($root . '/app/Home.php', "<?php\n// home\n");
    file_put_contents($root . '/README.md', "Come funziona il login del progetto.\n");
    file_put_contents($root . '/vendor/lib.php', "<?php\nfunction login() {}\n"); // rumore: escluso
    file_put_contents($root . '/.git/config', "login=x\n");                       // sensibile+rumore
    file_put_contents($root . '/.env', "login=secret\n");                          // sensibile
    file_put_contents($root . '/bin.dat', "login\0login\n");                       // binario
    symlink($root . '/app/Auth/Login.php', $root . '/link.php');                    // symlink
    return $root;
};

test('TargetedRetriever: inventario esclude rumore, sensibili e symlink', function () use ($mkrepo, $ws, $limits, $rmrf) {
    $root = $mkrepo();
    try {
        $res = (new TargetedRetriever($limits()))->retrieve($ws($root), 'login');
        $inv = array_map(static fn (array $f): string => $f['path'], $res->inventory->files());
        assertSame(true, in_array('app/Auth/Login.php', $inv, true));
        assertSame(false, in_array('vendor/lib.php', $inv, true), 'vendor escluso');
        assertSame(false, in_array('.env', $inv, true), 'sensibile escluso');
        assertSame(false, in_array('link.php', $inv, true), 'symlink escluso');
        // .git escluso a qualsiasi profondita'
        foreach ($inv as $p) { assertSame(false, str_contains($p, '.git'), $p); }
    } finally { $rmrf($root); }
});

test('TargetedRetriever: legge il file rilevante e distingue letti da soli trovati', function () use ($mkrepo, $ws, $limits, $rmrf) {
    $root = $mkrepo();
    try {
        $res = (new TargetedRetriever($limits()))->retrieve($ws($root), 'login');
        $consulted = $res->filesConsulted();
        // Login.php ha piu' contesto (match di contenuto): letto.
        assertSame(true, in_array('app/Auth/Login.php', $consulted['read'], true));
        // README.md e' un match di ricerca ma, se non selezionato per lettura, resta "found".
        $readPaths = $consulted['read'];
        $foundPaths = $consulted['found'];
        // nessun file compare in entrambe le liste
        assertSame([], array_values(array_intersect($readPaths, $foundPaths)));
        // il binario non entra mai tra i letti
        assertSame(false, in_array('bin.dat', $readPaths, true));
    } finally { $rmrf($root); }
});

test('TargetedRetriever: i contenuti letti sono quelli reali del file', function () use ($mkrepo, $ws, $limits, $rmrf) {
    $root = $mkrepo();
    try {
        $res = (new TargetedRetriever($limits()))->retrieve($ws($root), 'login');
        $byPath = [];
        foreach ($res->readFiles() as $f) { $byPath[$f['path']] = $f['content']; }
        assertSame(true, isset($byPath['app/Auth/Login.php']));
        assertSame(true, str_contains($byPath['app/Auth/Login.php'], 'function login()'));
    } finally { $rmrf($root); }
});

test('TargetedRetriever: rispetta readMaxFiles con selezione deterministica', function () use ($tmpBase, $ws, $limits, $rmrf) {
    $root = $tmpBase() . '/aimanager_tr2_' . uniqid('', true);
    mkdir($root, 0777, true);
    try {
        // tre file con match di contenuto; con readMaxFiles=1 se ne legge uno solo
        file_put_contents($root . '/a.php', "login\nlogin\n"); // 2 match
        file_put_contents($root . '/b.php', "login\n");         // 1 match
        file_put_contents($root . '/c.php', "login\n");         // 1 match
        $res = (new TargetedRetriever($limits(['readMaxFiles' => 1])))->retrieve($ws($root), 'login');
        assertSame(1, count($res->readFiles()));
        // a.php ha piu' match => selezionato per primo (deterministico)
        assertSame('a.php', $res->readFiles()[0]['path']);
        assertSame(true, in_array('read:files', $res->limitsHit(), true));
    } finally { $rmrf($root); }
});

test('TargetedRetriever: rispetta readMaxTotalBytes', function () use ($tmpBase, $ws, $limits, $rmrf) {
    $root = $tmpBase() . '/aimanager_tr3_' . uniqid('', true);
    mkdir($root, 0777, true);
    try {
        file_put_contents($root . '/a.php', str_repeat('login\n', 20));
        file_put_contents($root . '/b.php', str_repeat('login\n', 20));
        $res = (new TargetedRetriever($limits(['readMaxBytesPerFile' => 200, 'readMaxTotalBytes' => 200])))->retrieve($ws($root), 'login');
        // il totale (200) permette un solo file
        assertSame(1, count($res->readFiles()));
        assertSame(true, in_array('read:totalBytes', $res->limitsHit(), true));
    } finally { $rmrf($root); }
});

test('TargetedRetriever: espone metriche coerenti con contatori scan separati', function () use ($mkrepo, $ws, $limits, $rmrf) {
    $root = $mkrepo();
    try {
        $res = (new TargetedRetriever($limits()))->retrieve($ws($root), 'login');
        $m = $res->metrics();
        assertSame(true, $m['inventoryFiles'] >= 3);
        assertSame(true, $m['filesRead'] >= 1);
        assertSame(true, $m['searchMatches'] >= 1);
        assertSame(true, $m['readBytes'] > 0);
        // contatori file di ricerca ESPOSTI SEPARATAMENTE (niente doppio conteggio)
        assertSame(true, array_key_exists('nameFilesScanned', $m));
        assertSame(true, array_key_exists('contentFilesScanned', $m));
    } finally { $rmrf($root); }
});

test('TargetedRetriever: revoca a meta\' lettura interrompe con revoked', function () use ($tmpBase, $ws, $limits, $rmrf) {
    $root = $tmpBase() . '/aimanager_tr4_' . uniqid('', true);
    mkdir($root, 0777, true);
    try {
        // repo di 2 soli file, entrambi con match: scan (0 isActive) + ricerca contenuto (2)
        // + lettura (2). Attivo per le prime 3 chiamate: la 2a lettura trova la revoca.
        file_put_contents($root . '/a.php', "login\nlogin\n");
        file_put_contents($root . '/b.php', "login\nlogin\n");
        $calls = 0;
        $isActive = static function () use (&$calls): bool { $calls++; return $calls <= 3; };
        $res = (new TargetedRetriever($limits(), null, null, $isActive))->retrieve($ws($root), 'login');
        assertSame(1, count($res->readFiles()));
        assertSame(true, in_array('revoked', $res->limitsHit(), true));
    } finally { $rmrf($root); }
});

test('TargetedRetriever: query senza token utili non legge nulla ma rende l\'inventario', function () use ($mkrepo, $ws, $limits, $rmrf) {
    $root = $mkrepo();
    try {
        $res = (new TargetedRetriever($limits()))->retrieve($ws($root), 'a b c'); // tutti < 3 char
        assertSame(0, count($res->readFiles()));
        assertSame(0, count($res->searchHits()));
        assertSame(true, count($res->inventory->files()) >= 3);
    } finally { $rmrf($root); }
});

test('TargetedRetriever: tokenize applica minuscole, split e soglia di 3 caratteri', function () {
    assertSame(['dove', 'gestito', 'login'], TargetedRetriever::tokenize('Dove è gestito il LOGIN?'));
    assertSame([], TargetedRetriever::tokenize('a di il'));
});
