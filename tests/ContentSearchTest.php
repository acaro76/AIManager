<?php

declare(strict_types=1);

use App\Core\Code\CodeWorkspace;
use App\Core\Code\ContentSearch;
use App\Core\Code\RetrievalLimits;
use App\Core\Code\SensitivePathPolicy;

// F1.2 — ricerca per contenuto: lettura SOLO via readLimited, tutti i limiti applicati,
// timeout durante l'iterazione, revoca/root rivalutate, sensibili/binari/grandi esclusi.

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

$mkroot = static function () use ($tmpBase): string {
    $root = $tmpBase() . '/aimanager_cs_' . uniqid('', true);
    mkdir($root, 0777, true);
    return $root;
};

$ws = static function (string $root, string $status = 'active'): CodeWorkspace {
    return new CodeWorkspace(1, $root, '', $status, new SensitivePathPolicy());
};

// Costruisce RetrievalLimits partendo dai default con override mirati.
$limits = static function (array $o = []): RetrievalLimits {
    $a = array_merge([
        'scanMaxDepth' => 12, 'scanMaxFiles' => 2000, 'scanMaxReadBytes' => 262144, 'scanMaxSeconds' => 5.0,
        'searchMaxFilesScanned' => 2000, 'searchMaxMatches' => 100, 'searchMaxBytesPerFile' => 262144,
        'searchMaxTotalBytes' => 4194304, 'searchMaxSeconds' => 5.0,
        'readMaxFiles' => 12, 'readMaxBytesPerFile' => 65536, 'readMaxTotalBytes' => 262144, 'contextMaxChars' => 48000,
    ], $o);
    return new RetrievalLimits(...$a);
};

$paths = static fn (array $hits): array => array_map(static fn (array $h): string => $h['path'], $hits);

test('ContentSearch: trova i match con numero di riga corretto', function () use ($mkroot, $ws, $limits, $rmrf) {
    $root = $mkroot();
    try {
        file_put_contents($root . '/a.php', "<?php\n// niente\nfunction login() {}\n");
        $res = (new ContentSearch())->search($ws($root), ['a.php'], ['login'], $limits());
        assertSame(1, count($res['hits']));
        assertSame('a.php', $res['hits'][0]['path']);
        assertSame(3, $res['hits'][0]['line']);
        assertSame(1, $res['filesScanned']);
    } finally { $rmrf($root); }
});

test('ContentSearch: salta i file sensibili (.env) senza match', function () use ($mkroot, $ws, $limits, $rmrf, $paths) {
    $root = $mkroot();
    try {
        file_put_contents($root . '/.env', "login=secret\n");
        file_put_contents($root . '/ok.php', "login\n");
        $res = (new ContentSearch())->search($ws($root), ['.env', 'ok.php'], ['login'], $limits());
        assertSame(['ok.php'], $paths($res['hits'])); // .env mai letto
    } finally { $rmrf($root); }
});

test('ContentSearch: salta i symlink', function () use ($mkroot, $ws, $limits, $rmrf) {
    $root = $mkroot();
    try {
        file_put_contents($root . '/real.php', "login\n");
        symlink($root . '/real.php', $root . '/lnk.php');
        $res = (new ContentSearch())->search($ws($root), ['lnk.php'], ['login'], $limits());
        assertSame(0, count($res['hits']));
    } finally { $rmrf($root); }
});

test('ContentSearch: rifiuta traversal e percorsi assoluti', function () use ($mkroot, $ws, $limits, $rmrf) {
    $root = $mkroot();
    try {
        file_put_contents($root . '/in.php', "login\n");
        $res = (new ContentSearch())->search($ws($root), ['../secret', '/etc/passwd', 'in.php'], ['login', 'root'], $limits());
        // solo il file interno produce hit; nessuna evasione
        assertSame(['in.php'], array_values(array_unique(array_map(static fn (array $h): string => $h['path'], $res['hits']))));
    } finally { $rmrf($root); }
});

test('ContentSearch: esclude i binari (nessun match anche col token presente)', function () use ($mkroot, $ws, $limits, $rmrf) {
    $root = $mkroot();
    try {
        file_put_contents($root . '/bin.dat', "login\0login\n");
        $res = (new ContentSearch())->search($ws($root), ['bin.dat'], ['login'], $limits());
        assertSame(0, count($res['hits']));
        assertSame(1, $res['filesScanned']); // letto entro soglia, poi scartato come binario
    } finally { $rmrf($root); }
});

test('ContentSearch: non carica i file oltre la soglia per-file', function () use ($mkroot, $ws, $limits, $rmrf) {
    $root = $mkroot();
    try {
        file_put_contents($root . '/big.php', str_repeat('login ', 100)); // ~600 byte
        $res = (new ContentSearch())->search($ws($root), ['big.php'], ['login'], $limits(['searchMaxBytesPerFile' => 50, 'searchMaxTotalBytes' => 50]));
        assertSame(0, count($res['hits'])); // rifiutato da readLimited: non letto
    } finally { $rmrf($root); }
});

test('ContentSearch: rispetta searchMaxFilesScanned', function () use ($mkroot, $ws, $limits, $rmrf) {
    $root = $mkroot();
    try {
        foreach (['a.php', 'b.php', 'c.php'] as $f) { file_put_contents($root . '/' . $f, "nulla\n"); }
        $res = (new ContentSearch())->search($ws($root), ['a.php', 'b.php', 'c.php'], ['login'], $limits(['searchMaxFilesScanned' => 2]));
        assertSame(2, $res['filesScanned']);
        assertSame(true, in_array('search:files', $res['limitsHit'], true));
    } finally { $rmrf($root); }
});

test('ContentSearch: rispetta searchMaxTotalBytes', function () use ($mkroot, $ws, $limits, $rmrf) {
    $root = $mkroot();
    try {
        file_put_contents($root . '/a.php', 'loginlogi'); // 9 byte, contiene "login"
        file_put_contents($root . '/b.php', 'login');
        $res = (new ContentSearch())->search($ws($root), ['a.php', 'b.php'], ['login'], $limits(['searchMaxBytesPerFile' => 9, 'searchMaxTotalBytes' => 9]));
        assertSame(1, $res['filesScanned']); // dopo a.php il totale (9) blocca b.php
        assertSame(true, in_array('search:totalBytes', $res['limitsHit'], true));
    } finally { $rmrf($root); }
});

test('ContentSearch: il budget TOTALE non viene mai superato (secondo file oltre il residuo)', function () use ($mkroot, $ws, $limits, $rmrf, $paths) {
    $root = $mkroot();
    try {
        file_put_contents($root . '/a.php', 'loginlogin'); // 10 byte
        file_put_contents($root . '/b.php', 'loginlogin'); // 10 byte, ma il residuo sara' 2
        // perFile 10, totale 12: a.php (10) entra; per b.php restano 2 < 10 => rifiutato, non letto
        $res = (new ContentSearch())->search($ws($root), ['a.php', 'b.php'], ['login'], $limits(['searchMaxBytesPerFile' => 10, 'searchMaxTotalBytes' => 12]));
        assertSame(true, $res['bytesRead'] <= 12, 'bytesRead ' . $res['bytesRead']);
        assertSame(10, $res['bytesRead']); // solo a.php, b.php mai caricato
        assertSame(['a.php'], $paths($res['hits']));
        assertSame(true, in_array('search:totalBytes', $res['limitsHit'], true));
    } finally { $rmrf($root); }
});

test('ContentSearch: revoca a meta\' ricerca interrompe con revoked', function () use ($mkroot, $ws, $limits, $rmrf) {
    $root = $mkroot();
    try {
        file_put_contents($root . '/a.php', "login\n");
        file_put_contents($root . '/b.php', "login\n");
        $calls = 0;
        $isActive = static function () use (&$calls): bool { $calls++; return $calls <= 1; }; // attivo solo al 1o file
        $res = (new ContentSearch())->search($ws($root), ['a.php', 'b.php'], ['login'], $limits(), $isActive);
        assertSame(1, $res['filesScanned']);
        assertSame('a.php', $res['hits'][0]['path']);
        assertSame(true, in_array('revoked', $res['limitsHit'], true));
    } finally { $rmrf($root); }
});

test('ContentSearch: rispetta searchMaxMatches', function () use ($mkroot, $ws, $limits, $rmrf) {
    $root = $mkroot();
    try {
        file_put_contents($root . '/a.php', "login\nlogin\nlogin\n");
        $res = (new ContentSearch())->search($ws($root), ['a.php'], ['login'], $limits(['searchMaxMatches' => 1]));
        assertSame(1, count($res['hits']));
        assertSame(true, in_array('search:matches', $res['limitsHit'], true));
    } finally { $rmrf($root); }
});

test('ContentSearch: il timeout scatta DURANTE l\'iterazione (clock iniettato)', function () use ($mkroot, $ws, $limits, $rmrf) {
    $root = $mkroot();
    try {
        foreach (['a.php', 'b.php', 'c.php'] as $f) { file_put_contents($root . '/' . $f, "login\nlogin\n"); }
        $n = 0;
        $clock = static function () use (&$n): float { $n++; return (float) $n; }; // +1s ad ogni chiamata
        $res = (new ContentSearch($clock))->search($ws($root), ['a.php', 'b.php', 'c.php'], ['login'], $limits(['searchMaxSeconds' => 2.5]));
        assertSame(true, in_array('search:time', $res['limitsHit'], true));
        assertSame(true, $res['filesScanned'] < 3, 'non deve scandire tutti i file');
    } finally { $rmrf($root); }
});

test('ContentSearch: l\'estratto e\' sempre UTF-8 valido anche con multibyte e byte non validi', function () use ($mkroot, $ws, $limits, $rmrf) {
    $root = $mkroot();
    try {
        // riga lunga (> 200 byte) con accenti/emoji + un byte non valido, contenente il token
        $line = 'login ' . str_repeat('à', 120) . " 🚀🚀 \xFF fine città naïve";
        file_put_contents($root . '/a.php', $line . "\n");
        $res = (new ContentSearch())->search($ws($root), ['a.php'], ['login'], $limits());
        assertSame(1, count($res['hits']));
        $ex = $res['hits'][0]['excerpt'];
        assertSame(true, strlen($ex) <= 203, 'estratto ' . strlen($ex) . ' byte'); // 200 byte + eventuale … (3 byte)
        assertSame(true, mb_check_encoding($ex, 'UTF-8'));
        assertSame(true, json_encode($ex) !== false);
    } finally { $rmrf($root); }
});

test('ContentSearch: workspace revocato non legge nulla', function () use ($mkroot, $ws, $limits, $rmrf) {
    $root = $mkroot();
    try {
        file_put_contents($root . '/a.php', "login\n");
        $res = (new ContentSearch())->search($ws($root, 'revoked'), ['a.php'], ['login'], $limits());
        assertSame(0, count($res['hits']));
        assertSame(['revoked'], $res['limitsHit']);
    } finally { $rmrf($root); }
});

test('ContentSearch: root eliminata durante l\'operazione interrompe con limite root', function () use ($mkroot, $ws, $limits, $rmrf) {
    $root = $mkroot();
    file_put_contents($root . '/a.php', "login\n");
    $workspace = $ws($root);
    $rmrf($root); // root sparita prima della lettura
    $res = (new ContentSearch())->search($workspace, ['a.php', 'b.php'], ['login'], $limits());
    assertSame(0, count($res['hits']));
    assertSame(true, in_array('root', $res['limitsHit'], true));
});
