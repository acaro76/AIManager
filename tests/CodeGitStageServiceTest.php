<?php

declare(strict_types=1);

use App\Core\Code\CodeGitTool;
use App\Core\Code\CodeWorkspace;
use App\Core\Code\GitInvoker;
use App\Core\Code\GitLimits;
use App\Core\Code\GitPathPolicy;
use App\Core\Code\GitService;
use App\Core\Code\GitStagePlan;
use App\Core\Code\GitStageResult;
use App\Core\Code\GitStageService;
use App\Core\Code\SensitivePathPolicy;

// Fase 8 / tranche 4 — l'esecutore dello staging: rivalidazione integrale + `git add` confinato su un
// indice di QUARANTENA, sostituito atomicamente solo al successo. SOLO repository TEMPORANEI, mai
// AIManager. Nessun server, nessun browser, nessuna route/UI. Nessun reset/restore/checkout.

$mktemp = static function (): string {
    $base = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
    $dir = $base . '/aimanager_gitstage_' . uniqid('', true);
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

$env = static fn (string $dir): array => [
    'LANG' => 'C', 'LC_ALL' => 'C', 'PATH' => '/usr/bin:/bin', 'HOME' => $dir,
    'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_SYSTEM' => '/dev/null',
    'GIT_AUTHOR_NAME' => 'Test', 'GIT_AUTHOR_EMAIL' => 'test@example.com',
    'GIT_COMMITTER_NAME' => 'Test', 'GIT_COMMITTER_EMAIL' => 'test@example.com',
];

$git = static function (array $args, string $dir) use ($env): int {
    $d = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = @proc_open(array_merge(['/usr/bin/git', '-c', 'init.defaultBranch=main'], $args), $d, $pipes, $dir, $env($dir));
    if (!is_resource($p)) { return -1; }
    foreach ([1, 2] as $fd) { if (isset($pipes[$fd])) { stream_get_contents($pipes[$fd]); fclose($pipes[$fd]); } }
    return proc_close($p);
};

$gitOut = static function (array $args, string $dir) use ($env): string {
    $d = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = @proc_open(array_merge(['/usr/bin/git'], $args), $d, $pipes, $dir, $env($dir));
    if (!is_resource($p)) { return ''; }
    $out = stream_get_contents($pipes[1]);
    foreach ([1, 2] as $fd) { if (isset($pipes[$fd])) { if ($fd === 2) { stream_get_contents($pipes[$fd]); } fclose($pipes[$fd]); } }
    proc_close($p);
    return (string) $out;
};

// Snapshot completo dell'indice reale (mode+oid+stage+path): serve a provare "indice identico".
$indexSnap = static fn (string $dir): string => $gitOut(['ls-files', '--stage'], $dir);
$stagedNames = static fn (string $dir): string => trim($gitOut(['diff', '--cached', '--name-only'], $dir));

$ws = static fn (string $root, string $status = 'active'): CodeWorkspace
    => new CodeWorkspace(1, $root, 'temp', $status, new SensitivePathPolicy());

$tool = CodeGitTool::withDefaults();
$service = GitStageService::withDefaults();

if (!$tool->isAvailable()) {
    test('git non disponibile: GitStageService saltato', function (): void {
        assertSame(true, true);
    });
    return;
}

// Costruisce un piano valido per i percorsi dati (o null).
$planFor = static function (string $dir, array $paths) use ($tool, $ws): ?GitStagePlan {
    return $tool->proposeStage($ws($dir), $paths, 8192)['plan'];
};

// ---- 1) staging di un file tracked modificato --------------------------------------------------------

test('staging: file tracked modificato → staged, HEAD e worktree invariati', function () use ($mktemp, $rmrf, $git, $gitOut, $stagedNames, $planFor, $ws, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/a.txt', "2\n");
        $head = trim($gitOut(['rev-parse', 'HEAD'], $dir));

        $plan = $planFor($dir, ['a.txt']);
        $res = $service->execute($ws($dir), $plan, $plan->digest);
        assertSame(GitStageResult::STAGED, $res->outcome);
        assertSame(['a.txt'], $res->stagedPaths);
        assertSame('a.txt', $stagedNames($dir)); // realmente in stage nell'indice reale
        assertSame($head, trim($gitOut(['rev-parse', 'HEAD'], $dir))); // HEAD invariato
        assertSame("2\n", file_get_contents($dir . '/a.txt')); // worktree invariato
        assertSame([], glob($dir . '/.git/aimanager-index-*') ?: []); // nessuna quarantena residua
    } finally {
        $rmrf($dir);
    }
});

// ---- 2) staging di un file untracked -----------------------------------------------------------------

test('staging: file untracked → staged', function () use ($mktemp, $rmrf, $git, $stagedNames, $planFor, $ws, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/seed.txt', "x\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/nuovo.txt', "contenuto\n"); // untracked

        $plan = $planFor($dir, ['nuovo.txt']);
        $res = $service->execute($ws($dir), $plan, $plan->digest);
        assertSame(GitStageResult::STAGED, $res->outcome);
        assertSame('nuovo.txt', $stagedNames($dir));
    } finally {
        $rmrf($dir);
    }
});

// ---- 3/14) staging selettivo: uno entra, l'altro resta fuori -----------------------------------------

test('staging selettivo: solo il file scelto entra; l\'ammesso non scelto resta fuori', function () use ($mktemp, $rmrf, $git, $stagedNames, $planFor, $ws, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "1\n");
        file_put_contents($dir . '/b.txt', "1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/a.txt', "2\n"); // scelto
        file_put_contents($dir . '/b.txt', "2\n"); // ammesso ma NON scelto

        $plan = $planFor($dir, ['a.txt']);
        $res = $service->execute($ws($dir), $plan, $plan->digest);
        assertSame(GitStageResult::STAGED, $res->outcome);
        assertSame('a.txt', $stagedNames($dir)); // solo a.txt; b.txt NON in stage
    } finally {
        $rmrf($dir);
    }
});

// ---- 4) rename gestito con entrambi i capi (delete origine + aggiunta destinazione) ------------------

test('staging rename: entrambi i capi (eliminazione origine + destinazione) messi in stage', function () use ($mktemp, $rmrf, $git, $gitOut, $planFor, $ws, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/orig.txt', "contenuto\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        // rename NON in stage: eliminazione di orig.txt (tracked) + nuovo dest.txt (untracked).
        @unlink($dir . '/orig.txt');
        file_put_contents($dir . '/dest.txt', "contenuto\n");

        $plan = $planFor($dir, ['orig.txt', 'dest.txt']);
        assertSame(true, $plan instanceof GitStagePlan);
        $res = $service->execute($ws($dir), $plan, $plan->digest);
        assertSame(GitStageResult::STAGED, $res->outcome);
        // Nell'indice reale entrambi i capi risultano in stage: git li riconosce come un rename
        // (R) orig.txt → dest.txt. Verifica robusta: entrambi i nomi presenti nel diff staged.
        $staged = $gitOut(['diff', '--cached', '--name-status'], $dir);
        assertSame(true, str_contains($staged, 'orig.txt'));
        assertSame(true, str_contains($staged, 'dest.txt'));
        // Nessuna traccia dei due capi tra le modifiche NON in stage (staging completo).
        assertSame('', trim($gitOut(['diff', '--name-only', '--', 'orig.txt', 'dest.txt'], $dir)));
    } finally {
        $rmrf($dir);
    }
});

// ---- 5) digest errato → rejected, indice identico ---------------------------------------------------

test('rivalidazione: digest errato → rejected, indice reale identico', function () use ($mktemp, $rmrf, $git, $indexSnap, $stagedNames, $planFor, $ws, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/a.txt', "2\n");
        $plan = $planFor($dir, ['a.txt']);
        $before = $indexSnap($dir);

        $res = $service->execute($ws($dir), $plan, 'digest-sbagliato');
        assertSame(GitStageResult::REJECTED, $res->outcome);
        assertSame('', $stagedNames($dir));
        assertSame($before, $indexSnap($dir)); // indice reale identico
    } finally {
        $rmrf($dir);
    }
});

// ---- 6) fingerprint cambiata dopo la proposta → stale, indice identico ------------------------------

test('rivalidazione: stato cambiato dopo la proposta → stale, indice reale identico', function () use ($mktemp, $rmrf, $git, $indexSnap, $stagedNames, $planFor, $ws, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/a.txt', "2\n");
        $plan = $planFor($dir, ['a.txt']);
        $before = $indexSnap($dir);

        file_put_contents($dir . '/a.txt', "3\n"); // contenuto cambiato dopo la proposta
        $res = $service->execute($ws($dir), $plan, $plan->digest);
        assertSame(GitStageResult::STALE, $res->outcome);
        assertSame('', $stagedNames($dir));
        assertSame($before, $indexSnap($dir));
    } finally {
        $rmrf($dir);
    }
});

// ---- 7) workspace revocato → rejected, indice identico ----------------------------------------------

test('rivalidazione: workspace revocato → rejected, indice reale identico', function () use ($mktemp, $rmrf, $git, $indexSnap, $stagedNames, $planFor, $ws, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/a.txt', "2\n");
        $plan = $planFor($dir, ['a.txt']);
        $before = $indexSnap($dir);

        $res = $service->execute($ws($dir, 'revoked'), $plan, $plan->digest);
        assertSame(GitStageResult::REJECTED, $res->outcome);
        // anche via callback di attività
        $res2 = $service->execute($ws($dir), $plan, $plan->digest, static fn (): bool => false);
        assertSame(GitStageResult::REJECTED, $res2->outcome);
        assertSame('', $stagedNames($dir));
        assertSame($before, $indexSnap($dir));
    } finally {
        $rmrf($dir);
    }
});

// ---- 8) root/top-level divergente → rejected --------------------------------------------------------

test('rivalidazione: root non top-level (sottocartella di repo padre) → rejected', function () use ($mktemp, $rmrf, $git, $planFor, $ws, $service) {
    $parent = $mktemp();
    $child = $mktemp();
    try {
        // Repo "vero" (child) per costruire un piano valido.
        $git(['init', '-q'], $child);
        file_put_contents($child . '/a.txt', "1\n");
        $git(['add', '-A'], $child);
        $git(['commit', '-q', '-m', 'primo'], $child);
        file_put_contents($child . '/a.txt', "2\n");
        $plan = $planFor($child, ['a.txt']);

        // Repo padre con una sottocartella: la sottocartella NON è il top-level.
        $git(['init', '-q'], $parent);
        mkdir($parent . '/sub', 0777, true);
        $sub = realpath($parent . '/sub');

        $res = $service->execute($ws($sub), $plan, $plan->digest);
        assertSame(GitStageResult::REJECTED, $res->outcome);
    } finally {
        $rmrf($parent);
        $rmrf($child);
    }
});

// ---- 9) sensibile/runtime nel piano → rejected, mai staged ------------------------------------------

test('rivalidazione: piano con percorso sensibile → rejected, .env mai in stage, nessun nome esposto', function () use ($mktemp, $rmrf, $git, $indexSnap, $stagedNames, $ws, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/.env', "SECRET=1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/.env', "SECRET=2\n");
        $before = $indexSnap($dir);

        // Piano OSTILE costruito a mano con un percorso sensibile.
        $plan = GitStagePlan::create(1, [['path' => '.env', 'orig_path' => null, 'status' => 'modificato']], [], 0, 'fp', realpath($dir) ?: $dir);
        $res = $service->execute($ws($dir), $plan, $plan->digest);
        assertSame(GitStageResult::REJECTED, $res->outcome);
        assertSame(false, str_contains($res->message, '.env')); // nessun nome sensibile esposto
        assertSame('', $stagedNames($dir)); // .env mai in stage
        assertSame($before, $indexSnap($dir));
    } finally {
        $rmrf($dir);
    }
});

// ---- 10) symlink già presente → rejected ------------------------------------------------------------

test('rivalidazione: symlink al posto del file selezionato → rejected, indice identico', function () use ($mktemp, $rmrf, $git, $indexSnap, $stagedNames, $planFor, $ws, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "1\n");
        file_put_contents($dir . '/target.txt', "payload\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/a.txt', "2\n");
        $plan = $planFor($dir, ['a.txt']); // piano valido su file regolare
        $before = $indexSnap($dir);

        @unlink($dir . '/a.txt');
        @symlink($dir . '/target.txt', $dir . '/a.txt'); // a.txt ora è un symlink
        $res = $service->execute($ws($dir), $plan, $plan->digest);
        assertSame(GitStageResult::REJECTED, $res->outcome);
        assertSame('', $stagedNames($dir));
        assertSame($before, $indexSnap($dir));
    } finally {
        $rmrf($dir);
    }
});

// ---- 11) sostituzione TOCTOU con symlink durante la rivalidazione → rejected ------------------------

test('rivalidazione: TOCTOU symlink durante la lettura del worktree → rejected, indice identico', function () use ($mktemp, $rmrf, $git, $indexSnap, $stagedNames, $planFor, $ws) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/a.txt', "2\n");
        file_put_contents($dir . '/altrove.txt', "PAYLOAD\n");
        $plan = $planFor($dir, ['a.txt']);
        $before = $indexSnap($dir);

        // Servizio con un tool che sostituisce a.txt con un symlink subito prima della lettura.
        $swapped = false;
        $hook = static function (string $abs) use (&$swapped, $dir): void {
            if (!$swapped && str_ends_with($abs, '/a.txt')) {
                $swapped = true;
                @unlink($abs);
                @symlink($dir . '/altrove.txt', $abs);
            }
        };
        $g = GitService::withDefaults();
        $raceService = new GitStageService($g, new CodeGitTool($g, new GitPathPolicy(), $hook));
        $res = $raceService->execute($ws($dir), $plan, $plan->digest);
        assertSame(GitStageResult::REJECTED, $res->outcome);
        assertSame('', $stagedNames($dir));
        assertSame($before, $indexSnap($dir));
    } finally {
        $rmrf($dir);
    }
});

// ---- 12) errore durante lo staging (lock occupato) → error, indice identico ------------------------

test('atomicità: lockfile dell\'indice occupato → error, indice reale identico', function () use ($mktemp, $rmrf, $git, $gitOut, $indexSnap, $stagedNames, $planFor, $ws, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/a.txt', "2\n");
        $plan = $planFor($dir, ['a.txt']);
        $before = $indexSnap($dir);

        // Occupa il lockfile dell'indice: la quarantena non può essere claimata → fail closed.
        $realIndex = trim($gitOut(['rev-parse', '--git-path', 'index'], $dir));
        if ($realIndex !== '' && $realIndex[0] !== '/') { $realIndex = $dir . '/' . $realIndex; }
        file_put_contents($realIndex . '.lock', 'busy');
        try {
            $res = $service->execute($ws($dir), $plan, $plan->digest);
            assertSame(GitStageResult::ERROR, $res->outcome);
            assertSame('', $stagedNames($dir));
            assertSame($before, $indexSnap($dir));
        } finally {
            @unlink($realIndex . '.lock');
        }
    } finally {
        $rmrf($dir);
    }
});

// ---- 13) output troncato → fail closed --------------------------------------------------------------

test('fail closed: stato Git troncato → error, indice reale identico', function () use ($mktemp, $rmrf, $git, $indexSnap, $stagedNames, $planFor, $ws) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        for ($i = 0; $i < 60; $i++) { file_put_contents($dir . '/f_' . $i . '.txt', "x\n"); }
        file_put_contents($dir . '/a.txt', "2\n");
        $plan = $planFor($dir, ['a.txt']);
        $before = $indexSnap($dir);

        // Servizio con cap minuscolo: lo `status` in rivalidazione risulta troncato → fail closed.
        $tg = new GitService(new GitInvoker(new GitLimits(15.0, 300)));
        $truncService = new GitStageService($tg, new CodeGitTool($tg));
        $res = $truncService->execute($ws($dir), $plan, $plan->digest);
        assertSame(GitStageResult::ERROR, $res->outcome);
        assertSame('', $stagedNames($dir));
        assertSame($before, $indexSnap($dir));
    } finally {
        $rmrf($dir);
    }
});

// ---- (punto 1) GitInvoker: nessuna superficie per iniettare env arbitrario ------------------------

test('GitInvoker: ambiente FISSO, nessuna iniezione di LD_PRELOAD/DYLD/PATH/HOME/config Git', function () use ($mktemp, $rmrf) {
    $fakeBin = $mktemp();
    $cwd = $mktemp();
    // Env ostile nel processo PADRE: non deve MAI raggiungere il figlio git.
    $saved = [];
    foreach (['LD_PRELOAD' => '/evil.so', 'DYLD_INSERT_LIBRARIES' => '/evil.dylib', 'PATH' => '/evil/bin', 'HOME' => '/evil/home', 'GIT_CONFIG_GLOBAL' => '/evil/gitconfig'] as $k => $v) {
        $saved[$k] = getenv($k);
        putenv("$k=$v");
    }
    try {
        // Fake `git` che scarica l'ambiente ricevuto in un file nella cwd (argv ignorato).
        $php = PHP_BINARY;
        $script = "#!" . $php . "\n<?php\n"
            . "\$keys = ['LD_PRELOAD','DYLD_INSERT_LIBRARIES','PATH','HOME','GIT_CONFIG_GLOBAL','GIT_INDEX_FILE'];\n"
            . "\$out = [];\n"
            . "foreach (\$keys as \$k) { \$v = getenv(\$k); \$out[] = \$k.'='.(\$v === false ? '<UNSET>' : \$v); }\n"
            . "@file_put_contents(getcwd().'/env.dump', implode(\"\\n\", \$out));\n";
        file_put_contents($fakeBin . '/git', $script);
        chmod($fakeBin . '/git', 0755);
        $invoker = new GitInvoker(GitLimits::defaults(), [$fakeBin]);

        // Senza GIT_INDEX_FILE.
        $invoker->run(['rev-parse'], $cwd);
        $dump = (string) @file_get_contents($cwd . '/env.dump');
        assertSame(true, str_contains($dump, 'LD_PRELOAD=<UNSET>'));
        assertSame(true, str_contains($dump, 'DYLD_INSERT_LIBRARIES=<UNSET>'));
        assertSame(true, str_contains($dump, 'PATH=/usr/bin:/bin'));
        assertSame(true, str_contains($dump, 'HOME=' . sys_get_temp_dir()));
        assertSame(true, str_contains($dump, 'GIT_CONFIG_GLOBAL=/dev/null'));
        assertSame(true, str_contains($dump, 'GIT_INDEX_FILE=<UNSET>'));

        // Con GIT_INDEX_FILE assoluto: è l'UNICA aggiunta ammessa.
        @unlink($cwd . '/env.dump');
        $invoker->run(['rev-parse'], $cwd, $cwd . '/quarantine.idx');
        $dump2 = (string) @file_get_contents($cwd . '/env.dump');
        assertSame(true, str_contains($dump2, 'GIT_INDEX_FILE=' . $cwd . '/quarantine.idx'));
        assertSame(true, str_contains($dump2, 'PATH=/usr/bin:/bin')); // sicurezza inderogabile

        // GIT_INDEX_FILE non assoluto o con NUL → avvio NEGATO (nessun processo, nessun dump).
        @unlink($cwd . '/env.dump');
        $r1 = $invoker->run(['rev-parse'], $cwd, 'relativo.idx');
        assertSame(false, $r1->started);
        assertSame(false, is_file($cwd . '/env.dump'));
        $r2 = $invoker->run(['rev-parse'], $cwd, "/abs/with\0nul");
        assertSame(false, $r2->started);
    } finally {
        foreach ($saved as $k => $v) {
            if ($v === false) { putenv($k); } else { putenv("$k=$v"); }
        }
        $rmrf($fakeBin);
        $rmrf($cwd);
    }
});

// ---- (punto 2) layout non modificabile (worktree collegato / .git file) → rejected, nessun lock -----

test('confine indice: worktree collegato (.git file, gitdir esterna) → rejected, nessun lock esterno', function () use ($mktemp, $rmrf, $git, $planFor, $ws, $service) {
    $main = $mktemp();
    $wtBase = $mktemp();
    try {
        $git(['init', '-q'], $main);
        file_put_contents($main . '/a.txt', "1\n");
        $git(['add', '-A'], $main);
        $git(['commit', '-q', '-m', 'primo'], $main);
        // Worktree collegato: il suo `.git` è un FILE che punta a una gitdir esterna.
        $wt = $wtBase . '/wt';
        $git(['worktree', 'add', '-q', $wt], $main);
        assertSame(false, is_dir($wt . '/.git')); // .git è un file
        file_put_contents($wt . '/a.txt', "2\n"); // modifica nel worktree

        $plan = $planFor($wt, ['a.txt']); // read-only: la proposta si costruisce comunque
        assertSame(true, $plan instanceof GitStagePlan);

        $externalGitDir = $main . '/.git/worktrees/wt';
        $res = $service->execute($ws($wt), $plan, $plan->digest);
        assertSame(GitStageResult::REJECTED, $res->outcome);
        // Nessun lock creato: né nel worktree né nella gitdir esterna.
        assertSame(false, is_file($externalGitDir . '/index.lock'));
        assertSame(false, is_file($wt . '/.git.lock'));
    } finally {
        $rmrf($wtBase);
        $rmrf($main);
    }
});

// ---- (punto 3) indice cambiato TRA controllo preliminare e lock → rilevato sotto lock (stale) -------

test('lock-first: indice cambiato tra preliminari e lock → stale sotto lock, servizio non tocca l\'indice', function () use ($mktemp, $rmrf, $git, $indexSnap, $planFor, $ws) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/a.txt', "2\n");
        $plan = $planFor($dir, ['a.txt']);

        // Hook eseguito TRA i controlli preliminari e il claim del lock: cambia l'INDICE del file
        // selezionato (stage di un contenuto diverso), lasciandolo comunque candidato (MM).
        $afterHook = null;
        $hook = function () use (&$afterHook, $dir, $git, $indexSnap): void {
            file_put_contents($dir . '/a.txt', "3\n");
            $git(['add', 'a.txt'], $dir); // indice cambiato
            file_put_contents($dir . '/a.txt', "4\n"); // worktree diverso → resta candidato
            $afterHook = $indexSnap($dir);
        };
        $g = GitService::withDefaults();
        $svc = new GitStageService($g, new CodeGitTool($g), $hook);
        $res = $svc->execute($ws($dir), $plan, $plan->digest);
        assertSame(GitStageResult::STALE, $res->outcome); // rilevato dalla rivalidazione SOTTO lock
        assertSame($afterHook, $indexSnap($dir)); // il servizio non ha modificato ulteriormente l'indice
    } finally {
        $rmrf($dir);
    }
});

// ---- (punto 4) rename già in indice con modifica worktree (orig_path presente ma eliminato) ---------

test('staging rename type-2 (RM): destinazione in argv, origine eliminata non in argv, entrambi i capi in stage', function () use ($mktemp, $rmrf, $git, $gitOut, $planFor, $ws, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/orig.txt', "contenuto abbastanza lungo\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        $git(['mv', 'orig.txt', 'dest.txt'], $dir); // rename in indice
        file_put_contents($dir . '/dest.txt', "contenuto abbastanza lungo, modificato\n"); // worktree M → RM

        $plan = $planFor($dir, ['dest.txt']);
        assertSame(true, $plan instanceof GitStagePlan);
        assertSame('dest.txt', $plan->selected[0]['path']);
        assertSame('orig.txt', $plan->selected[0]['orig_path']); // orig_path presente ma eliminato dal worktree

        $res = $service->execute($ws($dir), $plan, $plan->digest);
        assertSame(GitStageResult::STAGED, $res->outcome);
        // Entrambi i capi rappresentati nell'indice reale (rename R) e nessun residuo non in stage.
        $staged = $gitOut(['diff', '--cached', '--name-status'], $dir);
        assertSame(true, str_contains($staged, 'orig.txt'));
        assertSame(true, str_contains($staged, 'dest.txt'));
        assertSame('', trim($gitOut(['diff', '--name-only', '--', 'dest.txt', 'orig.txt'], $dir)));
    } finally {
        $rmrf($dir);
    }
});

// ---- 16) nessuna shell / pathspec letterali: glob e spazi trattati letteralmente -------------------

test('argv/no-shell: pathspec letterali, nessun glob (il decoy non viene messo in stage)', function () use ($mktemp, $rmrf, $git, $stagedNames, $planFor, $ws, $service) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/seed.txt', "x\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/lit*eral.txt', "scelto\n"); // untracked, nome con glob
        file_put_contents($dir . '/litXeral.txt', "decoy\n");   // untracked, un glob lo prenderebbe

        $plan = $planFor($dir, ['lit*eral.txt']);
        assertSame(true, $plan instanceof GitStagePlan);
        $res = $service->execute($ws($dir), $plan, $plan->digest);
        assertSame(GitStageResult::STAGED, $res->outcome);
        // Solo il file letterale è in stage; il decoy NO (nessun globbing di shell).
        assertSame('lit*eral.txt', $stagedNames($dir));
    } finally {
        $rmrf($dir);
    }
});
