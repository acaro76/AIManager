<?php

declare(strict_types=1);

// Fase 10 / Step 4 — builder dell'artefatto in un repo Git TEMPORANEO (mai il repo/DB reale, nessun
// server). Verifica realmente `bin/build-release.sh` e il cablaggio essenziale di `bin/backup.php`.

$rmrf = static function (string $path) use (&$rmrf): void {
    if (is_link($path)) { @unlink($path); return; }
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $e) { if ($e !== '.' && $e !== '..') { $rmrf($path . '/' . $e); } }
        @rmdir($path);
        return;
    }
    @unlink($path);
};

$put = static function (string $path, string $content): void {
    if (!is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
    file_put_contents($path, $content);
};

$git = static function (string $repo, string $args): array {
    exec('git -C ' . escapeshellarg($repo) . ' ' . $args . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};

// Costruisce un repo committato e PULITO, con allow-list + file vietati tracciati + segreti ignorati.
$makeRepo = static function () use ($put, $git): string {
    $repo = sys_get_temp_dir() . '/aimanager_rel_' . uniqid('', true);
    mkdir($repo, 0700, true);

    // Allow-list (tracciati).
    $put($repo . '/app/Core/x.php', "<?php\n");
    $put($repo . '/bin/launch.sh', "#!/bin/bash\nexit 0\n");
    $put($repo . '/config/app.php', "<?php\nreturn [];\n");
    $put($repo . '/database/001_x.php', "<?php\nreturn function (\$db) {};\n");
    $put($repo . '/plugins/.gitkeep', "");
    $put($repo . '/public/index.php', "<?php\n");
    $put($repo . '/storage/plugins/.gitkeep', "");
    $put($repo . '/.env.example', "OPENAI_API_KEY=\n");
    $put($repo . '/README.md', "# AIManager\n");
    $put($repo . '/LICENSE', "Apache License 2.0\n");
    $put($repo . '/SECURITY.md', "# Security\n");
    $put($repo . '/docs/RELEASE.md', "# Rilascio\n");
    $put($repo . '/docs/PROVIDERS.md', "# Provider\n");
    $put($repo . '/docs/USER_GUIDE.md', "# Guida utente\n");
    $put($repo . '/docs/PUBLIC_ROADMAP.md', "# Roadmap\n");
    // Il builder deve esistere DENTRO il repo (usa la propria posizione come root).
    $put($repo . '/bin/build-release.sh', (string) file_get_contents(dirname(__DIR__) . '/bin/build-release.sh'));

    // Vietati ma TRACCIATI (devono restare fuori dall'artefatto via allow-list).
    $put($repo . '/tests/Foo.php', "<?php\n");
    $put($repo . '/docs/INTERNAL.md', "# interno\n");
    $put($repo . '/.claude/config.json', "{}\n");

    // .gitignore tracciato: segreti e runtime NON tracciati e artefatti ignorati (tree resta pulito).
    $put($repo . '/.gitignore', ".env\nstorage/database/\nstorage/logs/\ndist/\n");

    $git($repo, 'init -q');
    $git($repo, 'config user.email test@example.com');
    $git($repo, 'config user.name Test');
    $git($repo, 'config commit.gpgsign false');
    $git($repo, 'add -A');
    $git($repo, 'commit -q -m init');

    // Segreti/runtime NON tracciati (ignorati): non devono entrare nell'artefatto.
    $put($repo . '/.env', "OPENAI_API_KEY=segreto\n");
    $put($repo . '/storage/database/aimanager.sqlite', "dati\n");
    $put($repo . '/storage/logs/server.log', "log\n");

    return $repo;
};

$build = static function (string $repo): array {
    exec('bash ' . escapeshellarg($repo . '/bin/build-release.sh') . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};

$tarLines = static function (string $tarball): array {
    exec('tar -tzf ' . escapeshellarg($tarball) . ' 2>&1', $out, $code);
    return array_map('trim', $out);
};

$hasPrefix = static function (array $lines, string $prefix): bool {
    foreach ($lines as $l) { if (str_starts_with($l, $prefix)) { return true; } }
    return false;
};

test('release: artefatto e checksum validi, entry point presenti, vietati/segreti assenti', function () use ($makeRepo, $git, $build, $tarLines, $hasPrefix, $rmrf) {
    $repo = $makeRepo();
    try {
        [$code, $out] = $build($repo);
        assertSame(0, $code, 'build fallita: ' . $out);

        [, $sha] = $git($repo, 'rev-parse --short HEAD');
        $sha = trim($sha);
        $name = 'AIManager-' . $sha;
        $tarball = $repo . '/dist/' . $name . '.tar.gz';
        $checksum = $tarball . '.sha256';

        assertSame(true, is_file($tarball), 'tarball assente');
        assertSame(true, is_file($checksum), 'checksum assente');

        // I temporanei di build (pubblicati con hard link) sono stati rimossi dalla trap: nessun residuo.
        assertSame([], glob($repo . '/dist/.build-*') ?: []);

        // checksum verificabile
        exec('cd ' . escapeshellarg($repo . '/dist') . ' && shasum -a 256 -c ' . escapeshellarg($name . '.tar.gz.sha256') . ' 2>&1', $vo, $vc);
        assertSame(0, $vc, 'checksum non verificato: ' . implode("\n", $vo));

        $lines = $tarLines($tarball);
        // Entry point, configurazione e documentazione pubblica presenti sotto la root omonima.
        assertSame(true, in_array($name . '/public/index.php', $lines, true), 'index.php assente');
        assertSame(true, in_array($name . '/bin/launch.sh', $lines, true), 'launch.sh assente');
        assertSame(true, in_array($name . '/.env.example', $lines, true), '.env.example assente');
        assertSame(true, in_array($name . '/README.md', $lines, true), 'README assente');
        assertSame(true, in_array($name . '/LICENSE', $lines, true), 'licenza assente');
        assertSame(true, in_array($name . '/SECURITY.md', $lines, true), 'security policy assente');

        // Vietati/segreti assenti.
        assertSame(false, $hasPrefix($lines, $name . '/tests/'), 'tests presenti');
        assertSame(false, $hasPrefix($lines, $name . '/.claude'), '.claude presente');
        assertSame(false, $hasPrefix($lines, $name . '/storage/database/'), 'DB runtime presente');
        assertSame(false, $hasPrefix($lines, $name . '/storage/logs/'), 'log runtime presenti');
        assertSame(false, in_array($name . '/docs/INTERNAL.md', $lines, true), 'docs interne presenti');
        assertSame(false, in_array($name . '/.env', $lines, true), '.env segreto presente');
        assertSame(false, in_array($name . '/.gitignore', $lines, true), '.gitignore presente');
        foreach (['RELEASE.md', 'PROVIDERS.md', 'USER_GUIDE.md', 'PUBLIC_ROADMAP.md'] as $publicDoc) {
            assertSame(true, in_array($name . '/docs/' . $publicDoc, $lines, true), $publicDoc . ' assente');
        }

        // L'archivio compresso è integro (gzip -t sul tarball prodotto).
        exec('gzip -t ' . escapeshellarg($tarball) . ' 2>&1', $go, $gc);
        assertSame(0, $gc, 'gzip -t sul tarball: ' . implode("\n", $go));

        // La copia documentata dello storage (cp -Rp "$OLD/storage/." "$NEW/storage/") preserva i
        // contenuti e NON crea una directory storage/storage.
        $old = $repo . '/inst_old';
        $new = $repo . '/inst_new';
        mkdir($old . '/storage/database', 0700, true);
        file_put_contents($old . '/storage/database/x.sqlite', 'dati');
        mkdir($new . '/storage', 0700, true);
        exec('cp -Rp ' . escapeshellarg($old . '/storage/.') . ' ' . escapeshellarg($new . '/storage/') . ' 2>&1', $co, $cc);
        assertSame(0, $cc, 'cp storage: ' . implode("\n", $co));
        assertSame(true, is_file($new . '/storage/database/x.sqlite'));
        assertSame('dati', file_get_contents($new . '/storage/database/x.sqlite'));
        assertSame(false, is_dir($new . '/storage/storage'), 'non deve creare storage/storage');
    } finally { $rmrf($repo); }
});

test('release: working tree sporco → rifiutato, nessun artefatto', function () use ($makeRepo, $build, $put, $rmrf) {
    $repo = $makeRepo();
    try {
        // File non tracciato e NON ignorato → tree sporco.
        $put($repo . '/nuovo-non-tracciato.txt', "x\n");
        [$code, $out] = $build($repo);
        assertSame(true, $code !== 0, 'un tree sporco deve essere rifiutato');
        assertSame(true, str_contains($out, 'working tree non pulito'));
        assertSame(false, is_dir($repo . '/dist'), 'nessun artefatto su tree sporco');
    } finally { $rmrf($repo); }
});

test('release: artefatto esistente → non sovrascritto', function () use ($makeRepo, $build, $rmrf) {
    $repo = $makeRepo();
    try {
        [$code1] = $build($repo);
        assertSame(0, $code1);
        // Seconda esecuzione: l'artefatto esiste già (dist/ è ignorato, tree ancora pulito).
        [$code2, $out2] = $build($repo);
        assertSame(true, $code2 !== 0, 'la seconda build non deve sovrascrivere');
        assertSame(true, str_contains($out2, 'gia\' esistente'));
    } finally { $rmrf($repo); }
});

test('release: bin/backup.php ha il cablaggio essenziale (nessun boot/migrazione)', function () {
    $src = (string) file_get_contents(dirname(__DIR__) . '/bin/backup.php');
    assertSame(false, str_contains($src, 'App::boot'), 'non deve eseguire App::boot');
    assertSame(false, str_contains($src, 'MigrationRunner'), 'non deve migrare');
    assertSame(true, str_contains($src, 'is_file($databasePath)'), 'controlla la presenza del DB');
    assertSame(true, str_contains($src, 'new Database('), 'riusa Database');
    assertSame(true, str_contains($src, 'LocalPermissions::secureDatabaseFile'), 'riusa LocalPermissions');
    assertSame(true, str_contains($src, 'SqliteBackupService'), 'riusa SqliteBackupService');
    assertSame(true, str_contains($src, "\$result['sha256']"), 'stampa lo SHA-256');
    assertSame(true, str_contains($src, 'exit(1)'), 'esce non-zero sul fallimento');
});
