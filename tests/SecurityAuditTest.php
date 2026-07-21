<?php

declare(strict_types=1);

use App\Core\Security\ErrorBoundary;
use App\Core\Security\LocalHttpGuard;
use App\Core\Security\LocalPermissions;
use App\Core\Security\MediaTypePolicy;
use App\Core\Session;

// Fase 10 / Step 2 — audit di sicurezza (policy isolate, testabili senza avviare HTTP/server).

$rmrf = static function (string $path) use (&$rmrf): void {
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $e) { if ($e !== '.' && $e !== '..') { $rmrf($path . '/' . $e); } }
        @rmdir($path);
        return;
    }
    @unlink($path);
};

// --- 1) Confine HTTP locale --------------------------------------------------------------

test('LocalHttpGuard: loopback ammesso (127.0.0.1 e ::1)', function () {
    assertSame(true, LocalHttpGuard::isAllowed('127.0.0.1:8000', '127.0.0.1'));
    assertSame(true, LocalHttpGuard::isAllowed('localhost:8000', '::1'));
    assertSame(true, LocalHttpGuard::isAllowed('localhost', '127.0.0.1'));
});

test('LocalHttpGuard: IP remoto negato anche con Host localhost', function () {
    assertSame(false, LocalHttpGuard::isAllowed('localhost:8000', '203.0.113.7'));
    assertSame(false, LocalHttpGuard::isAllowed('127.0.0.1', '10.0.0.5'));
    assertSame(false, LocalHttpGuard::isAllowed('localhost', '')); // REMOTE_ADDR assente
});

test('LocalHttpGuard: Host ostile negato anche da loopback', function () {
    assertSame(false, LocalHttpGuard::isAllowed('evil.example.com', '127.0.0.1'));
    assertSame(false, LocalHttpGuard::isAllowed('attacker.test:8000', '127.0.0.1'));
    assertSame(false, LocalHttpGuard::isAllowed('', '127.0.0.1'));
});

// --- 2) Allegati serviti in sicurezza ----------------------------------------------------

test('MediaTypePolicy: raster reale servito inline; download forzato → attachment', function () use ($rmrf) {
    $dir = sys_get_temp_dir() . '/aimanager_media_' . uniqid('', true);
    mkdir($dir, 0700, true);
    try {
        $png = $dir . '/a.png';
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $gif = $dir . '/a.gif';
        file_put_contents($gif, base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));

        assertSame('image/png', MediaTypePolicy::detect($png));
        assertSame(['mime' => 'image/png', 'disposition' => 'inline'], MediaTypePolicy::forFile($png, false));
        assertSame(['mime' => 'image/gif', 'disposition' => 'inline'], MediaTypePolicy::forFile($gif, false));
        // download=1 forza sempre attachment, anche su un raster valido
        assertSame(['mime' => 'application/octet-stream', 'disposition' => 'attachment'], MediaTypePolicy::forFile($png, true));
    } finally { $rmrf($dir); }
});

test('MediaTypePolicy: testo/HTML/SVG/MIME falso sempre attachment (octet-stream)', function () use ($rmrf) {
    $dir = sys_get_temp_dir() . '/aimanager_media_' . uniqid('', true);
    mkdir($dir, 0700, true);
    try {
        $cases = [
            'nota.txt' => 'ciao mondo',
            'p.html' => "<!doctype html><script>alert(1)</script>",
            'v.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'falso.png' => 'questo NON è un png', // estensione mente: contenuto testuale
        ];
        foreach ($cases as $name => $content) {
            $p = $dir . '/' . $name;
            file_put_contents($p, $content);
            assertSame('application/octet-stream', MediaTypePolicy::detect($p), $name);
            assertSame(['mime' => 'application/octet-stream', 'disposition' => 'attachment'], MediaTypePolicy::forFile($p, false), $name);
        }
    } finally { $rmrf($dir); }
});

test('MediaTypePolicy: allow-list decide() — solo PNG/JPEG/GIF/WebP inline', function () {
    assertSame('inline', MediaTypePolicy::decide('image/png', false)['disposition']);
    assertSame('inline', MediaTypePolicy::decide('image/jpeg', false)['disposition']);
    assertSame('inline', MediaTypePolicy::decide('image/webp', false)['disposition']);
    assertSame('inline', MediaTypePolicy::decide('image/gif', false)['disposition']);
    // fuori allow-list o forzato → attachment + octet-stream
    assertSame('attachment', MediaTypePolicy::decide('image/svg+xml', false)['disposition']);
    assertSame('attachment', MediaTypePolicy::decide('text/html', false)['disposition']);
    assertSame('attachment', MediaTypePolicy::decide('application/pdf', false)['disposition']);
    assertSame('application/octet-stream', MediaTypePolicy::decide('image/png', true)['mime']);
});

// --- 3) Sessione e confine 500 -----------------------------------------------------------

test('Session::options: modalità stretta e solo-cookie, HttpOnly/SameSite=Lax/secure=false', function () {
    $o = Session::options();
    assertSame(true, $o['use_strict_mode']);
    assertSame(true, $o['use_only_cookies']);
    assertSame(true, $o['cookie_httponly']);
    assertSame('Lax', $o['cookie_samesite']);
    assertSame(false, $o['cookie_secure']);
});

test('ErrorBoundary: 500 generico al client, dettaglio solo nel log', function () {
    $logged = null;
    $r = ErrorBoundary::handle(
        new \RuntimeException('DSN=sqlite:/segreto host=db pw=hunter2'),
        static function (string $m) use (&$logged): void { $logged = $m; }
    );
    assertSame(500, $r['status']);
    assertSame('Errore interno del server.', $r['body']);
    // nessun dettaglio tecnico nella risposta...
    assertSame(false, str_contains($r['body'], 'hunter2'));
    assertSame(false, str_contains($r['body'], 'DSN'));
    // ...ma il dettaglio (categoria + messaggio) arriva al log
    assertSame(true, is_string($logged) && str_contains($logged, 'hunter2'));
    assertSame(true, str_contains((string) $logged, 'RuntimeException'));
});

// --- 4) Permessi locali ------------------------------------------------------------------

test('LocalPermissions: .env 0600, storage/db 0700, SQLite 0600', function () use ($rmrf) {
    $base = sys_get_temp_dir() . '/aimanager_perm_' . uniqid('', true);
    mkdir($base . '/database', 0777, true);
    try {
        $env = $base . '/.env';
        file_put_contents($env, "OPENAI_API_KEY=segreto\n");
        chmod($env, 0644);
        LocalPermissions::secureEnv($env);
        assertSame(0600, fileperms($env) & 0777);

        $sqlite = $base . '/database/aimanager.sqlite';
        file_put_contents($sqlite, 'x');
        chmod($sqlite, 0644);
        chmod($base, 0755);
        chmod($base . '/database', 0755);
        LocalPermissions::secureStorage($base, $sqlite);
        assertSame(0700, fileperms($base) & 0777);
        assertSame(0700, fileperms($base . '/database') & 0777);
        assertSame(0600, fileperms($sqlite) & 0777);
    } finally { $rmrf($base); }
});

// --- Cablaggio (senza server): verifiche di integrazione statica/comportamentale ---------

$root = dirname(__DIR__);

test('cablaggio: public/index.php esegue LocalHttpGuard e disattiva gli errori PRIMA di App::boot', function () use ($root) {
    $src = (string) file_get_contents($root . '/public/index.php');
    $posErr   = strpos($src, "ini_set('display_errors', '0')");
    $posGuard = strpos($src, 'LocalHttpGuard::isAllowed');
    $posTry   = strpos($src, "\ntry {");
    $posBoot  = strpos($src, 'App::boot(');
    $posDisp  = strpos($src, '->dispatch(');
    $posBound = strpos($src, 'ErrorBoundary::handle');
    assertSame(true, $posErr !== false && $posErr < $posBoot, 'display_errors off prima del boot');
    assertSame(true, $posGuard !== false && $posGuard < $posBoot, 'guard prima del boot');
    assertSame(true, $posTry !== false && $posTry < $posBoot, 'boot dentro il confine try');
    assertSame(true, $posBound !== false && $posDisp !== false && $posBound > $posDisp, 'confine ErrorBoundary attorno al dispatch');
});

test('cablaggio: MediaController usa MediaTypePolicy (non il MIME persistito) e invia la CSP sandbox', function () use ($root) {
    $src = (string) file_get_contents($root . '/app/Controllers/MediaController.php');
    assertSame(true, str_contains($src, 'MediaTypePolicy::forFile'), 'usa MediaTypePolicy per mime+disposition');
    assertSame(true, str_contains($src, 'Content-Security-Policy: sandbox'), 'invia la CSP sandbox');
    assertSame(false, str_contains($src, "row['mime']"), 'non serve piu il MIME persistito');
});

test('cablaggio: App applica i permessi PRIMA del MigrationRunner (e il file DB dopo new Database)', function () use ($root) {
    $src = (string) file_get_contents($root . '/app/Core/App.php');
    $posEnv   = strpos($src, 'LocalPermissions::secureEnv');
    $posStore = strpos($src, 'LocalPermissions::secureStorage');
    $posNewDb = strpos($src, 'new Database(');
    $posDbF   = strpos($src, 'LocalPermissions::secureDatabaseFile');
    $posMig   = strpos($src, 'new MigrationRunner(');
    assertSame(true, $posEnv !== false && $posEnv < $posNewDb, 'secureEnv prima di aprire il DB');
    assertSame(true, $posStore !== false && $posStore < $posNewDb, 'secureStorage prima di aprire il DB');
    assertSame(true, $posDbF !== false && $posDbF > $posNewDb && $posDbF < $posMig, 'file DB ristretto dopo new Database, prima delle migrazioni');
    assertSame(true, $posStore < $posMig && $posEnv < $posMig, 'tutti i permessi prima del MigrationRunner');
});

test('cablaggio: ConfigurationManager verifica la scrittura e applica secureEnv', function () use ($root, $rmrf) {
    // Sorgente: la scrittura è verificata (fail-closed) e i permessi applicati.
    $src = (string) file_get_contents($root . '/app/Core/Configuration/ConfigurationManager.php');
    assertSame(true, str_contains($src, 'file_put_contents($this->path'), 'scrive .env');
    assertSame(true, str_contains($src, '=== false') && str_contains($src, 'RuntimeException'), 'verifica l\'esito della scrittura');
    assertSame(true, str_contains($src, 'LocalPermissions::secureEnv($this->path)'), 'applica secureEnv dopo la scrittura');

    // Comportamento: set() scrive un .env realmente privato (0600).
    $dir = sys_get_temp_dir() . '/aimanager_cfg_' . uniqid('', true);
    mkdir($dir, 0777, true);
    try {
        \App\Core\Configuration\ConfigurationManager::fromRoot($dir)->set('OPENAI_API_KEY', 'segreto');
        $env = $dir . '/.env';
        assertSame(true, is_file($env));
        assertSame(0600, fileperms($env) & 0777);
    } finally { $rmrf($dir); }
});

test('cablaggio: LocalPermissions è fail-closed (verifica il mode) e no-op sui percorsi assenti', function () use ($root) {
    $src = (string) file_get_contents($root . '/app/Core/Security/LocalPermissions.php');
    assertSame(true, str_contains($src, 'fileperms(') && str_contains($src, 'RuntimeException'), 'verifica il mode effettivo e fallisce se non ristretto');
    // percorso assente: nessuna eccezione
    $threw = false;
    try { LocalPermissions::secureEnv(sys_get_temp_dir() . '/aimanager_assente_' . uniqid('', true) . '/.env'); }
    catch (\Throwable) { $threw = true; }
    assertSame(false, $threw);
});
