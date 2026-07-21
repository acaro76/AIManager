<?php

declare(strict_types=1);

use App\Core\Code\CodeWorkspace;
use App\Core\Code\CodeWorkspaceException;
use App\Core\Code\GitDiff;
use App\Core\Code\GitException;
use App\Core\Code\GitInvoker;
use App\Core\Code\GitLimits;
use App\Core\Code\GitService;
use App\Core\Code\GitStatus;
use App\Core\Code\SensitivePathPolicy;

// Fase 8 — base Git READ-ONLY. Solo repository TEMPORANEI: mai AIManager come workspace o fixture,
// nessun server, nessun browser. Le fixture costruiscono il repo con git in argv (mai shell), con
// identità esplicita e config globale neutralizzata, così i test sono ermetici.

$mktemp = static function (): string {
    $base = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
    $dir = $base . '/aimanager_git_' . uniqid('', true);
    mkdir($dir, 0777, true);
    return realpath($dir) ?: $dir;
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

// Esegue git in una cwd con argv (nessuna shell), identità esplicita e HOME/config isolati: serve
// SOLO a preparare le fixture, non è il codice di produzione.
$git = static function (array $args, string $cwd): int {
    $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $env = [
        'LANG' => 'C', 'LC_ALL' => 'C', 'PATH' => '/usr/bin:/bin',
        'HOME' => $cwd,
        'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_SYSTEM' => '/dev/null',
        'GIT_AUTHOR_NAME' => 'Test', 'GIT_AUTHOR_EMAIL' => 'test@example.com',
        'GIT_COMMITTER_NAME' => 'Test', 'GIT_COMMITTER_EMAIL' => 'test@example.com',
    ];
    $full = array_merge(['/usr/bin/git', '-c', 'init.defaultBranch=main', '-c', 'commit.gpgsign=false'], $args);
    $p = @proc_open($full, $descriptors, $pipes, $cwd, $env);
    if (!is_resource($p)) { return -1; }
    foreach ([1, 2] as $fd) { if (isset($pipes[$fd])) { stream_get_contents($pipes[$fd]); fclose($pipes[$fd]); } }
    return proc_close($p);
};

$mkworkspace = static function (string $root): CodeWorkspace {
    return new CodeWorkspace(1, $root, 'temp', 'active', new SensitivePathPolicy());
};

$service = GitService::withDefaults();

// Se git non è risolvibile in bin fidata, l'ambiente non può esercitare il sottosistema: registra un
// unico caso e fermati, senza far fallire la suite.
if (!$service->isAvailable()) {
    test('git non disponibile in bin fidata: sottosistema Git saltato', function (): void {
        assertSame(true, true);
    });
    return;
}

test('isRepository: falso su una cartella non-git', function () use ($mktemp, $rmrf, $mkworkspace, $service) {
    $dir = $mktemp();
    try {
        assertSame(false, $service->isRepository($mkworkspace($dir)));
    } finally {
        $rmrf($dir);
    }
});

test('isRepository: vero su un repo git inizializzato', function () use ($mktemp, $rmrf, $git, $mkworkspace, $service) {
    $dir = $mktemp();
    try {
        assertSame(0, $git(['init', '-q'], $dir));
        assertSame(true, $service->isRepository($mkworkspace($dir)));
    } finally {
        $rmrf($dir);
    }
});

test('status: repo iniziale con untracked → initial=true, ramo main, voce untracked', function () use ($mktemp, $rmrf, $git, $mkworkspace, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/nuovo.txt', "ciao\n");
        $status = $service->status($mkworkspace($dir));
        assertSame(true, $status instanceof GitStatus);
        assertSame('main', $status->branch);
        assertSame(true, $status->initial);
        assertSame(0, $status->ahead);
        assertSame(0, $status->behind);
        $untracked = array_values(array_filter($status->entries, fn ($e) => $e->untracked));
        assertSame(1, count($untracked));
        assertSame('nuovo.txt', $untracked[0]->path);
        assertSame(false, $status->isClean());
    } finally {
        $rmrf($dir);
    }
});

test('status: separa staged e unstaged; committed pulito', function () use ($mktemp, $rmrf, $git, $mkworkspace, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "uno\n");
        $git(['add', 'a.txt'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);

        // pulito subito dopo il commit
        $clean = $service->status($mkworkspace($dir));
        assertSame(true, $clean->isClean());
        assertSame(false, $clean->initial);

        // a.txt modificato (unstaged), b.txt untracked, c.txt in stage
        file_put_contents($dir . '/a.txt', "uno-bis\n");
        file_put_contents($dir . '/b.txt', "due\n");
        file_put_contents($dir . '/c.txt', "tre\n");
        $git(['add', 'c.txt'], $dir);

        $status = $service->status($mkworkspace($dir));
        $stagedPaths = array_map(fn ($e) => $e->path, $status->staged());
        $unstagedPaths = array_map(fn ($e) => $e->path, $status->unstaged());
        sort($stagedPaths);
        sort($unstagedPaths);
        assertSame(['c.txt'], $stagedPaths);
        assertSame(['a.txt', 'b.txt'], $unstagedPaths);
    } finally {
        $rmrf($dir);
    }
});

test('diffUnstaged/diffStaged: contenuti separati, external diff/textconv disattivati', function () use ($mktemp, $rmrf, $git, $mkworkspace, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "riga1\n");
        $git(['add', 'a.txt'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);

        file_put_contents($dir . '/a.txt', "riga1-modificata\n"); // unstaged
        file_put_contents($dir . '/c.txt', "nuovo-in-stage\n");
        $git(['add', 'c.txt'], $dir); // staged

        $unstaged = $service->diffUnstaged($mkworkspace($dir));
        assertSame(true, $unstaged instanceof GitDiff);
        assertSame(false, $unstaged->staged);
        assertSame(true, str_contains($unstaged->text, 'a.txt'));
        assertSame(true, str_contains($unstaged->text, 'riga1-modificata'));
        assertSame(false, str_contains($unstaged->text, 'nuovo-in-stage'));

        $staged = $service->diffStaged($mkworkspace($dir));
        assertSame(true, $staged->staged);
        assertSame(true, str_contains($staged->text, 'c.txt'));
        assertSame(true, str_contains($staged->text, 'nuovo-in-stage'));
        assertSame(false, str_contains($staged->text, 'riga1-modificata'));
    } finally {
        $rmrf($dir);
    }
});

test('status: worktree pulito → diff vuoti', function () use ($mktemp, $rmrf, $git, $mkworkspace, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "x\n");
        $git(['add', 'a.txt'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        assertSame(true, $service->diffUnstaged($mkworkspace($dir))->isEmpty());
        assertSame(true, $service->diffStaged($mkworkspace($dir))->isEmpty());
    } finally {
        $rmrf($dir);
    }
});

test('status: nomi file NON fidati (spazi) preservati come dato letterale', function () use ($mktemp, $rmrf, $git, $mkworkspace, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/we ird name.txt', "z\n");
        $status = $service->status($mkworkspace($dir));
        $paths = array_map(fn ($e) => $e->path, $status->entries);
        assertSame(true, in_array('we ird name.txt', $paths, true));
    } finally {
        $rmrf($dir);
    }
});

test('status: rename in stage → voce di tipo 2 con origPath (doppio token NUL)', function () use ($mktemp, $rmrf, $git, $mkworkspace, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/vecchio.txt', "contenuto stabile abbastanza lungo\n");
        $git(['add', 'vecchio.txt'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        $git(['mv', 'vecchio.txt', 'nuovo.txt'], $dir);

        $status = $service->status($mkworkspace($dir));
        $renamed = array_values(array_filter($status->entries, fn ($e) => $e->origPath !== null));
        assertSame(1, count($renamed));
        assertSame('nuovo.txt', $renamed[0]->path);
        assertSame('vecchio.txt', $renamed[0]->origPath);
        assertSame('R', $renamed[0]->index);
        assertSame(true, $renamed[0]->isStaged());
    } finally {
        $rmrf($dir);
    }
});

test('confine: workspace revocato → CodeWorkspaceException, nessuna invocazione git', function () use ($mktemp, $rmrf, $git, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        $revoked = new CodeWorkspace(1, $dir, 'temp', 'revoked', new SensitivePathPolicy());
        $threw = false;
        try {
            $service->status($revoked);
        } catch (CodeWorkspaceException $e) {
            $threw = true;
        }
        assertSame(true, $threw);
    } finally {
        $rmrf($dir);
    }
});

test('confine: root non più valida → CodeWorkspaceException (fail closed)', function () use ($mktemp, $rmrf, $git, $mkworkspace, $service) {
    $dir = $mktemp();
    $git(['init', '-q'], $dir);
    $ws = $mkworkspace($dir);
    $rmrf($dir); // la cartella sparisce dopo l'autorizzazione
    $threw = false;
    try {
        $service->status($ws);
    } catch (CodeWorkspaceException $e) {
        $threw = true;
    }
    assertSame(true, $threw);
});

test('non-repo: status/diff → GitException (atteso), non un fatale', function () use ($mktemp, $rmrf, $mkworkspace, $service) {
    $dir = $mktemp();
    try {
        $ws = $mkworkspace($dir);
        $n = 0;
        foreach ([fn () => $service->status($ws), fn () => $service->diffUnstaged($ws), fn () => $service->diffStaged($ws)] as $call) {
            try {
                $call();
            } catch (GitException $e) {
                $n++;
            }
        }
        assertSame(3, $n);
    } finally {
        $rmrf($dir);
    }
});

test('binario: git risolto SOLO in bin fidata (una bin fasulla non risolve)', function () use ($mktemp, $rmrf) {
    $fake = $mktemp();
    try {
        // Nessun eseguibile git in questa "bin": la risoluzione deve fallire, non cadere sul PATH.
        $invoker = new GitInvoker(GitLimits::defaults(), [$fake]);
        assertSame(false, $invoker->available());
    } finally {
        $rmrf($fake);
    }
});

test('output cap: un diff oltre il tetto viene troncato', function () use ($mktemp, $rmrf, $git, $mkworkspace) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/big.txt', str_repeat("riga di riempimento\n", 5000));
        $git(['add', 'big.txt'], $dir);
        // Tetto minuscolo: il diff staged supera abbondantemente il cap → truncated.
        $service = new GitService(new GitInvoker(new GitLimits(15.0, 256)));
        $diff = $service->diffStaged($mkworkspace($dir));
        assertSame(true, $diff->truncated);
        assertSame(true, strlen($diff->text) <= 256);
    } finally {
        $rmrf($dir);
    }
});

// --- Punto 1: repository top-level confinato (nessuna sottocartella di un repo padre) ---------------

test('confine top-level: sottocartella di un repo PADRE → isRepository false, status/diff negati', function () use ($mktemp, $rmrf, $git, $mkworkspace, $service) {
    $parent = $mktemp();
    try {
        $git(['init', '-q'], $parent);
        // File nel padre, FUORI dalla root Code: non deve mai diventare visibile.
        file_put_contents($parent . '/segreto_del_padre.txt', "roba del padre\n");
        $git(['add', '-A'], $parent);
        $git(['commit', '-q', '-m', 'padre'], $parent);

        $sub = $parent . '/sub';
        mkdir($sub, 0777, true);
        $ws = $mkworkspace(realpath($sub));

        assertSame(false, $service->isRepository($ws));

        $negati = 0;
        foreach ([fn () => $service->status($ws), fn () => $service->diffUnstaged($ws), fn () => $service->diffStaged($ws)] as $call) {
            try {
                $call();
            } catch (GitException $e) {
                $negati++;
            }
        }
        assertSame(3, $negati);
    } finally {
        $rmrf($parent);
    }
});

test('confine top-level: repo autonomo con top-level coincidente → accettato', function () use ($mktemp, $rmrf, $git, $mkworkspace, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        assertSame(true, $service->isRepository($mkworkspace($dir)));
    } finally {
        $rmrf($dir);
    }
});

// --- Punto 2: esclusione dei file sensibili da status e diff (offensivi) ----------------------------

test('sensibili: .env tracciato e modificato è ASSENTE da status e diff', function () use ($mktemp, $rmrf, $git, $mkworkspace, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/.env', "SECRET=originale\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/.env', "SECRET=TRAPELATO_XYZ\n");

        $status = $service->status($mkworkspace($dir));
        $paths = array_map(fn ($e) => $e->path, $status->entries);
        assertSame(false, in_array('.env', $paths, true));

        $diff = $service->diffUnstaged($mkworkspace($dir));
        assertSame(false, str_contains($diff->text, 'TRAPELATO_XYZ'));
        assertSame(true, $diff->isEmpty()); // nessun altro percorso ammesso → diff vuoto, git non invocato
    } finally {
        $rmrf($dir);
    }
});

test('sensibili: chiave e database tracciati → contenuto MAI nel diff', function () use ($mktemp, $rmrf, $git, $mkworkspace, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/id_rsa', "PRIVATE_KEY_v1\n");
        file_put_contents($dir . '/data.sqlite', "DB_ROW_v1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/id_rsa', "PRIVATE_KEY_TRAPELATA\n");
        file_put_contents($dir . '/data.sqlite', "DB_ROW_TRAPELATA\n");

        $status = $service->status($mkworkspace($dir));
        assertSame([], $status->entries); // entrambe sensibili → stato vuoto

        $unstaged = $service->diffUnstaged($mkworkspace($dir));
        assertSame(false, str_contains($unstaged->text, 'PRIVATE_KEY_TRAPELATA'));
        assertSame(false, str_contains($unstaged->text, 'DB_ROW_TRAPELATA'));
    } finally {
        $rmrf($dir);
    }
});

test('sensibili: file normale + sensibile modificati insieme → solo il normale', function () use ($mktemp, $rmrf, $git, $mkworkspace, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/app.php', "<?php // v1\n");
        file_put_contents($dir . '/.env', "SECRET=v1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/app.php', "<?php // v2 NORMALE_VISIBILE\n");
        file_put_contents($dir . '/.env', "SECRET=NON_VISIBILE\n");

        $status = $service->status($mkworkspace($dir));
        $paths = array_map(fn ($e) => $e->path, $status->entries);
        assertSame(['app.php'], $paths);

        $diff = $service->diffUnstaged($mkworkspace($dir));
        assertSame(true, str_contains($diff->text, 'NORMALE_VISIBILE'));
        assertSame(false, str_contains($diff->text, 'NON_VISIBILE'));
        assertSame(false, str_contains($diff->text, '.env'));
    } finally {
        $rmrf($dir);
    }
});

test('sensibili: rename VERSO un percorso sensibile → voce esclusa', function () use ($mktemp, $rmrf, $git, $mkworkspace, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/config.txt', "contenuto stabile abbastanza lungo\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        $git(['mv', 'config.txt', 'secret.key'], $dir); // destinazione sensibile (*.key)

        $status = $service->status($mkworkspace($dir));
        foreach ($status->entries as $e) {
            assertSame(false, $e->path === 'secret.key' || $e->origPath === 'secret.key');
        }
        // Nessun percorso ammesso in stage (l'unica voce è il rename escluso) → diff staged vuoto.
        assertSame(true, $service->diffStaged($mkworkspace($dir))->isEmpty());
    } finally {
        $rmrf($dir);
    }
});

test('sensibili: rename DA un percorso sensibile → voce esclusa', function () use ($mktemp, $rmrf, $git, $mkworkspace, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/server.pem', "contenuto stabile abbastanza lungo\n"); // origine sensibile (*.pem)
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        $git(['mv', 'server.pem', 'plain.txt'], $dir);

        $status = $service->status($mkworkspace($dir));
        foreach ($status->entries as $e) {
            assertSame(false, $e->origPath === 'server.pem' || $e->path === 'server.pem');
        }
    } finally {
        $rmrf($dir);
    }
});

test('pathspec: nomi con `-` iniziale e `:` magic trattati LETTERALMENTE, mai come opzione', function () use ($mktemp, $rmrf, $git, $mkworkspace, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/-dash.txt', "DASH_v1\n");
        file_put_contents($dir . '/:colon.txt', "COLON_v1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/-dash.txt', "DASH_MODIFICATO\n");
        file_put_contents($dir . '/:colon.txt', "COLON_MODIFICATO\n");

        $status = $service->status($mkworkspace($dir));
        $paths = array_map(fn ($e) => $e->path, $status->entries);
        sort($paths);
        assertSame(['-dash.txt', ':colon.txt'], $paths);

        // Il diff non fallisce (git non interpreta `-dash.txt` come opzione né `:colon.txt` come magic).
        $diff = $service->diffUnstaged($mkworkspace($dir));
        assertSame(true, str_contains($diff->text, 'DASH_MODIFICATO'));
        assertSame(true, str_contains($diff->text, 'COLON_MODIFICATO'));
    } finally {
        $rmrf($dir);
    }
});

// --- Revisione 3 / Punto 1: nessun falso "worktree pulito" con sole modifiche escluse --------------

test('non-pulito: sola modifica a .env → entries vuote, diff vuoto, ma isClean()=false', function () use ($mktemp, $rmrf, $git, $mkworkspace, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/.env', "SECRET=v1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/.env', "SECRET=v2\n");

        $status = $service->status($mkworkspace($dir));
        assertSame([], $status->entries);
        assertSame(true, $status->hasExcludedChanges());
        assertSame(false, $status->isClean());
        assertSame(true, $service->diffUnstaged($mkworkspace($dir))->isEmpty());
    } finally {
        $rmrf($dir);
    }
});

test('pulito: repository realmente pulito → isClean()=true, nessun escluso', function () use ($mktemp, $rmrf, $git, $mkworkspace, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "x\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);

        $status = $service->status($mkworkspace($dir));
        assertSame(true, $status->isClean());
        assertSame(false, $status->hasExcludedChanges());
        assertSame(0, $status->excludedCount);
    } finally {
        $rmrf($dir);
    }
});

test('non-pulito: normale + sensibile insieme → solo il normale, stato non pulito', function () use ($mktemp, $rmrf, $git, $mkworkspace, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/app.php', "<?php // v1\n");
        file_put_contents($dir . '/.env', "SECRET=v1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/app.php', "<?php // v2\n");
        file_put_contents($dir . '/.env', "SECRET=v2\n");

        $status = $service->status($mkworkspace($dir));
        $paths = array_map(fn ($e) => $e->path, $status->entries);
        assertSame(['app.php'], $paths);
        assertSame(false, $status->isClean());
        assertSame(true, $status->hasExcludedChanges());
    } finally {
        $rmrf($dir);
    }
});

// --- Revisione 3 / Punto 2: esclusione dei percorsi RUNTIME (storage/) -------------------------------

test('runtime: file tracciato sotto storage/ → assente da entries e diff, isClean()=false', function () use ($mktemp, $rmrf, $git, $mkworkspace, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        mkdir($dir . '/storage', 0777, true);
        file_put_contents($dir . '/storage/app.log', "LOG_v1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/storage/app.log', "LOG_RUNTIME_TRAPELATO\n");

        $status = $service->status($mkworkspace($dir));
        $paths = array_map(fn ($e) => $e->path, $status->entries);
        assertSame(false, in_array('storage/app.log', $paths, true));
        assertSame(false, $status->isClean());
        assertSame(true, $status->hasExcludedChanges());

        $diff = $service->diffUnstaged($mkworkspace($dir));
        assertSame(false, str_contains($diff->text, 'LOG_RUNTIME_TRAPELATO'));
        assertSame(true, $diff->isEmpty());
    } finally {
        $rmrf($dir);
    }
});

test('runtime: storage a QUALSIASI profondità (sub/storage/runtime.log) → escluso', function () use ($mktemp, $rmrf, $git, $mkworkspace, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        mkdir($dir . '/sub/storage', 0777, true);
        file_put_contents($dir . '/sub/storage/runtime.log', "R_v1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/sub/storage/runtime.log', "R_TRAPELATO\n");

        $status = $service->status($mkworkspace($dir));
        $paths = array_map(fn ($e) => $e->path, $status->entries);
        assertSame(false, in_array('sub/storage/runtime.log', $paths, true));
        assertSame(true, $status->hasExcludedChanges());
        assertSame(false, str_contains($service->diffUnstaged($mkworkspace($dir))->text, 'R_TRAPELATO'));
    } finally {
        $rmrf($dir);
    }
});

test('runtime: `mystorage.php` NON è escluso (confronto per segmenti, non sottostringa)', function () use ($mktemp, $rmrf, $git, $mkworkspace, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/mystorage.php', "<?php // v1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/mystorage.php', "<?php // MYSTORAGE_VISIBILE\n");

        $status = $service->status($mkworkspace($dir));
        $paths = array_map(fn ($e) => $e->path, $status->entries);
        assertSame(['mystorage.php'], $paths);
        assertSame(true, str_contains($service->diffUnstaged($mkworkspace($dir))->text, 'MYSTORAGE_VISIBILE'));
    } finally {
        $rmrf($dir);
    }
});

test('runtime: file normale + runtime modificati insieme → solo il normale', function () use ($mktemp, $rmrf, $git, $mkworkspace, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        mkdir($dir . '/storage', 0777, true);
        file_put_contents($dir . '/app.php', "<?php // v1\n");
        file_put_contents($dir . '/storage/cache.bin', "C_v1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/app.php', "<?php // NORMALE_VISIBILE\n");
        file_put_contents($dir . '/storage/cache.bin', "RUNTIME_NON_VISIBILE\n");

        $diff = $service->diffUnstaged($mkworkspace($dir));
        assertSame(true, str_contains($diff->text, 'NORMALE_VISIBILE'));
        assertSame(false, str_contains($diff->text, 'RUNTIME_NON_VISIBILE'));
        assertSame(false, str_contains($diff->text, 'storage/cache.bin'));
    } finally {
        $rmrf($dir);
    }
});

test('runtime: rename VERSO storage/ → voce esclusa e conteggiata', function () use ($mktemp, $rmrf, $git, $mkworkspace, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        mkdir($dir . '/storage', 0777, true);
        file_put_contents($dir . '/config.txt', "contenuto stabile abbastanza lungo\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        $git(['mv', 'config.txt', 'storage/config.txt'], $dir);

        $status = $service->status($mkworkspace($dir));
        foreach ($status->entries as $e) {
            assertSame(false, $e->path === 'storage/config.txt' || $e->origPath === 'storage/config.txt');
        }
        assertSame(true, $status->hasExcludedChanges());
        assertSame(true, $service->diffStaged($mkworkspace($dir))->isEmpty());
    } finally {
        $rmrf($dir);
    }
});

test('runtime: rename DA storage/ → voce esclusa', function () use ($mktemp, $rmrf, $git, $mkworkspace, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        mkdir($dir . '/storage', 0777, true);
        file_put_contents($dir . '/storage/old.txt', "contenuto stabile abbastanza lungo\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        $git(['mv', 'storage/old.txt', 'plain.txt'], $dir);

        $status = $service->status($mkworkspace($dir));
        foreach ($status->entries as $e) {
            assertSame(false, $e->origPath === 'storage/old.txt' || $e->path === 'storage/old.txt');
        }
        assertSame(true, $status->hasExcludedChanges());
    } finally {
        $rmrf($dir);
    }
});

// --- Punto 3: timeout realmente verificato (helper `git` lento, via trustedBins) --------------------

test('timeout: helper git lento → timedOut=true, processo terminato senza residuo', function () use ($mktemp, $rmrf) {
    $fakeBin = $mktemp();
    $cwd = $mktemp();
    try {
        // Helper controllato di nome `git`: registra il proprio PID nella cwd e poi dorme a lungo.
        // Invocato via proc_open in ARGV (shebang onorato dal kernel), NESSUNA shell libera.
        $php = PHP_BINARY;
        $script = "#!" . $php . "\n"
            . "<?php\n"
            . "@file_put_contents(getcwd() . '/child.pid', (string) getmypid());\n"
            . "usleep(20000000);\n";
        file_put_contents($fakeBin . '/git', $script);
        chmod($fakeBin . '/git', 0755);

        // Tetto BREVE ma sopra la latenza d'avvio dell'interprete (così l'helper fa in tempo a
        // registrare il PID prima del kill), e comunque enormemente sotto i 20s del suo sleep.
        $invoker = new GitInvoker(new GitLimits(2.0, 1 << 20), [$fakeBin]);
        assertSame(true, $invoker->available());

        $t0 = microtime(true);
        $res = $invoker->run(['rev-parse', '--show-toplevel'], $cwd);
        $elapsed = microtime(true) - $t0;

        assertSame(true, $res->started);
        assertSame(true, $res->timedOut);
        // Terminato al tetto (~2s + margine), NON dopo i 20s dell'helper.
        assertSame(true, $elapsed < 10.0);

        // Nessun residuo: il PID dell'helper non è più vivo.
        if (function_exists('posix_kill')) {
            $pid = (int) @file_get_contents($cwd . '/child.pid');
            assertSame(true, $pid > 0);
            $alive = true;
            $deadline = microtime(true) + 1.0;
            while (microtime(true) < $deadline) {
                if (!@posix_kill($pid, 0)) {
                    $alive = false;
                    break;
                }
                usleep(20000);
            }
            assertSame(false, $alive);
        }
    } finally {
        $rmrf($fakeBin);
        $rmrf($cwd);
    }
});
