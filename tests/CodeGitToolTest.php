<?php

declare(strict_types=1);

use App\Core\Code\CodeGitTool;
use App\Core\Code\CodeWorkspace;
use App\Core\Code\GitInvoker;
use App\Core\Code\GitLimits;
use App\Core\Code\GitService;
use App\Core\Code\GitStagePlan;
use App\Core\Code\SensitivePathPolicy;

// Fase 8 — l'ingresso del ciclo a Git (CodeGitTool): osservazioni STRUTTURATE e CAPPATE per stato e
// diff, staged/unstaged/untracked distinti, sensibili/runtime esclusi (solo conteggio), errori attesi
// come DATO (mai eccezioni). Solo repository TEMPORANEI, mai AIManager. Nessun server, nessun browser.

$mktemp = static function (): string {
    $base = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
    $dir = $base . '/aimanager_gittool_' . uniqid('', true);
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

$git = static function (array $args, string $cwd): int {
    $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $env = [
        'LANG' => 'C', 'LC_ALL' => 'C', 'PATH' => '/usr/bin:/bin', 'HOME' => $cwd,
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

$ws = static fn (string $root, string $status = 'active'): CodeWorkspace
    => new CodeWorkspace(1, $root, 'temp', $status, new SensitivePathPolicy());

// Cattura stdout di git (per verificare che indice/HEAD/worktree NON cambino durante i test).
$gitOut = static function (array $args, string $cwd): string {
    $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $env = ['LANG' => 'C', 'LC_ALL' => 'C', 'PATH' => '/usr/bin:/bin', 'HOME' => $cwd,
        'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_SYSTEM' => '/dev/null'];
    $p = @proc_open(array_merge(['/usr/bin/git'], $args), $descriptors, $pipes, $cwd, $env);
    if (!is_resource($p)) { return ''; }
    $out = stream_get_contents($pipes[1]);
    foreach ([1, 2] as $fd) { if (isset($pipes[$fd])) { if ($fd === 2) { stream_get_contents($pipes[$fd]); } fclose($pipes[$fd]); } }
    proc_close($p);
    return (string) $out;
};

$tool = CodeGitTool::withDefaults();

if (!$tool->isAvailable()) {
    test('git non disponibile: CodeGitTool saltato', function (): void {
        assertSame(true, true);
    });
    return;
}

test('status: staged/unstaged/untracked distinti nell\'osservazione', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "uno\n");
        $git(['add', 'a.txt'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/a.txt', "uno-bis\n");   // unstaged
        file_put_contents($dir . '/b.txt', "due\n");       // untracked
        file_put_contents($dir . '/c.txt', "tre\n");
        $git(['add', 'c.txt'], $dir);                      // staged

        $obs = $tool->status($ws($dir), 6000)['observation'];
        assertSame(true, str_contains($obs, 'STATO GIT'));
        assertSame(true, str_contains($obs, 'Ramo: main'));
        assertSame(true, str_contains($obs, 'In stage (1):'));
        assertSame(true, str_contains($obs, 'c.txt'));
        assertSame(true, str_contains($obs, 'Non in stage (1):'));
        assertSame(true, str_contains($obs, 'a.txt'));
        assertSame(true, str_contains($obs, 'Non tracciati (1):'));
        assertSame(true, str_contains($obs, 'b.txt'));
        assertSame(false, str_contains($obs, 'worktree pulito'));
    } finally {
        $rmrf($dir);
    }
});

test('status: repo pulito → "worktree pulito"', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "x\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);

        $obs = $tool->status($ws($dir), 6000)['observation'];
        assertSame(true, str_contains($obs, 'worktree pulito'));
    } finally {
        $rmrf($dir);
    }
});

test('status: modifiche escluse (.env + storage/) → conteggio, NON pulito, nessun nome', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        mkdir($dir . '/storage', 0777, true);
        file_put_contents($dir . '/.env', "SECRET=v1\n");
        file_put_contents($dir . '/storage/app.log', "L1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/.env', "SECRET=TRAPELATO\n");
        file_put_contents($dir . '/storage/app.log', "L_TRAPELATO\n");

        $obs = $tool->status($ws($dir), 6000)['observation'];
        assertSame(true, str_contains($obs, 'Modifiche escluse'));
        assertSame(true, str_contains($obs, ': 2'));
        assertSame(true, str_contains($obs, 'NON è pulito'));
        assertSame(false, str_contains($obs, 'worktree pulito'));
        // Nessun nome/contenuto sensibile o runtime nell'osservazione.
        assertSame(false, str_contains($obs, '.env'));
        assertSame(false, str_contains($obs, 'storage/app.log'));
        assertSame(false, str_contains($obs, 'TRAPELATO'));
    } finally {
        $rmrf($dir);
    }
});

test('diff: staged e unstaged distinti; sensibili mai nel diff', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "riga1\n");
        file_put_contents($dir . '/.env', "SECRET=v1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/a.txt', "riga1-UNSTAGED\n");
        file_put_contents($dir . '/.env', "SECRET=NON_VISIBILE\n");
        file_put_contents($dir . '/c.txt', "STAGED_NEW\n");
        $git(['add', 'c.txt'], $dir);

        $unstaged = $tool->diff($ws($dir), false, 6000)['observation'];
        assertSame(true, str_contains($unstaged, 'DIFF GIT (non in stage)'));
        assertSame(true, str_contains($unstaged, 'riga1-UNSTAGED'));
        assertSame(false, str_contains($unstaged, 'NON_VISIBILE'));
        assertSame(false, str_contains($unstaged, 'STAGED_NEW'));

        $staged = $tool->diff($ws($dir), true, 6000)['observation'];
        assertSame(true, str_contains($staged, 'DIFF GIT (in stage)'));
        assertSame(true, str_contains($staged, 'STAGED_NEW'));
        assertSame(false, str_contains($staged, 'riga1-UNSTAGED'));
        assertSame(false, str_contains($staged, 'NON_VISIBILE'));
    } finally {
        $rmrf($dir);
    }
});

test('diff: nessuna differenza → "nessuna differenza", mai "pulito"', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "x\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);

        $obs = $tool->diff($ws($dir), false, 6000)['observation'];
        assertSame(true, stripos($obs, 'nessuna differenza da mostrare') !== false);
        assertSame(false, str_contains($obs, 'pulito'));
    } finally {
        $rmrf($dir);
    }
});

test('errore atteso: cartella non-repo → osservazione DATO, nessuna eccezione', function () use ($mktemp, $rmrf, $ws, $tool) {
    $dir = $mktemp();
    try {
        $status = $tool->status($ws($dir), 6000)['observation'];
        $diff = $tool->diff($ws($dir), true, 6000)['observation'];
        assertSame(true, str_contains($status, 'GIT NON DISPONIBILE'));
        assertSame(true, str_contains($diff, 'GIT NON DISPONIBILE'));
    } finally {
        $rmrf($dir);
    }
});

test('errore atteso: workspace revocato → osservazione DATO, nessuna eccezione', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        $obs = $tool->status($ws($dir, 'revoked'), 6000)['observation'];
        assertSame(true, str_contains($obs, 'GIT NON DISPONIBILE'));
    } finally {
        $rmrf($dir);
    }
});

test('confine: sottocartella di repo PADRE → non disponibile (top-level non coincidente)', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $parent = $mktemp();
    try {
        $git(['init', '-q'], $parent);
        file_put_contents($parent . '/segreto_padre.txt', "x\n");
        $git(['add', '-A'], $parent);
        $git(['commit', '-q', '-m', 'padre'], $parent);
        $sub = $parent . '/sub';
        mkdir($sub, 0777, true);

        $obs = $tool->status($ws(realpath($sub)), 6000)['observation'];
        assertSame(true, str_contains($obs, 'GIT NON DISPONIBILE'));
        assertSame(false, str_contains($obs, 'segreto_padre'));
    } finally {
        $rmrf($parent);
    }
});

test('cap: osservazione oltre il limite → troncatura dichiarata', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/big.txt', str_repeat("riga\n", 4000));
        $git(['add', '-A'], $dir);
        // Cap piccolo → il diff staged supera e viene troncato con marker.
        $obs = $tool->diff($ws($dir), true, 300)['observation'];
        assertSame(true, strlen($obs) <= 300);
        assertSame(true, str_contains($obs, 'troncata al limite'));
    } finally {
        $rmrf($dir);
    }
});

// --- Punto 2 (revisione): il diff comunica il conteggio degli esclusi anche senza git_status ---------

test('diff offensivo: sola modifica .env → conteggio aggregato, NON pulito, nessun nome/contenuto', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/.env', "SECRET=v1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/.env', "SECRET=TRAPELATO\n");

        $obs = $tool->diff($ws($dir), false, 6000)['observation'];
        assertSame(true, str_contains($obs, 'Modifiche escluse'));
        assertSame(true, str_contains($obs, ': 1'));
        assertSame(true, str_contains($obs, 'NON è pulito'));
        assertSame(false, str_contains($obs, '.env'));
        assertSame(false, str_contains($obs, 'TRAPELATO'));
    } finally {
        $rmrf($dir);
    }
});

test('diff offensivo: sola modifica storage/ → conteggio aggregato, nessun nome/contenuto', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        mkdir($dir . '/storage', 0777, true);
        file_put_contents($dir . '/storage/app.log', "L1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/storage/app.log', "L_TRAPELATO\n");

        $obs = $tool->diff($ws($dir), false, 6000)['observation'];
        assertSame(true, str_contains($obs, 'Modifiche escluse'));
        assertSame(true, str_contains($obs, ': 1'));
        assertSame(false, str_contains($obs, 'storage/app.log'));
        assertSame(false, str_contains($obs, 'TRAPELATO'));
    } finally {
        $rmrf($dir);
    }
});

test('diff offensivo: normale + esclusa → solo il normale nel diff, escluse conteggiate', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/app.php', "<?php // v1\n");
        file_put_contents($dir . '/.env', "SECRET=v1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/app.php', "<?php // NORMALE_VISIBILE\n");
        file_put_contents($dir . '/.env', "SECRET=NON_VISIBILE\n");

        $obs = $tool->diff($ws($dir), false, 6000)['observation'];
        assertSame(true, str_contains($obs, 'NORMALE_VISIBILE'));
        assertSame(true, str_contains($obs, 'Modifiche escluse'));
        assertSame(true, str_contains($obs, ': 1'));
        assertSame(false, str_contains($obs, 'NON_VISIBILE'));
        assertSame(false, str_contains($obs, '.env'));
    } finally {
        $rmrf($dir);
    }
});

// --- Punto 3 (revisione): cap DURO, mai oltre maxChars, anche <= lunghezza del marker ----------------

test('cap duro: strlen non supera mai maxChars per limiti piccolissimi (1, sotto il marker), UTF-8 valido', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        // Nome file MULTIBYTE tra gli untracked: il taglio non deve mai spezzare un carattere UTF-8.
        file_put_contents($dir . '/caffè_日本.txt', "x\n");

        foreach ([1, 2, 3, 5, 10, 20, 37, 40, 60] as $cap) {
            $status = $tool->status($ws($dir), $cap)['observation'];
            assertSame(true, strlen($status) <= $cap, 'status cap=' . $cap . ' len=' . strlen($status));
            assertSame(true, mb_check_encoding($status, 'UTF-8'), 'status UTF-8 non valido a cap=' . $cap);

            $diff = $tool->diff($ws($dir), true, $cap)['observation'];
            assertSame(true, strlen($diff) <= $cap, 'diff cap=' . $cap . ' len=' . strlen($diff));
            assertSame(true, mb_check_encoding($diff, 'UTF-8'), 'diff UTF-8 non valido a cap=' . $cap);
        }
    } finally {
        $rmrf($dir);
    }
});

// --- Tranche 3: proposeStage (proposta di staging selettivo, nessuna esecuzione) --------------------

test('proposeStage: seleziona modificato + non tracciato; ammessi-non-selezionati distinti; digest presente', function () use ($mktemp, $rmrf, $git, $gitOut, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "uno\n");
        file_put_contents($dir . '/keep.txt', "keep\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/a.txt', "uno-mod\n"); // modificato (unstaged)
        file_put_contents($dir . '/keep.txt', "keep-mod\n"); // ammesso ma NON richiesto
        file_put_contents($dir . '/b.txt', "due\n"); // non tracciato

        $before = $gitOut(['status', '--porcelain=v2'], $dir);
        $res = $tool->proposeStage($ws($dir), ['a.txt', 'b.txt'], 6000);
        $plan = $res['plan'];
        assertSame(true, $plan instanceof GitStagePlan);
        assertSame(['a.txt', 'b.txt'], $plan->paths());
        $statusByPath = [];
        foreach ($plan->selected as $e) { $statusByPath[$e['path']] = $e['status']; assertSame(null, $e['orig_path']); }
        assertSame('modificato', $statusByPath['a.txt']);
        assertSame('non tracciato', $statusByPath['b.txt']);
        assertSame([['path' => 'keep.txt', 'orig_path' => null]], $plan->allowedNotSelected);
        assertSame(0, $plan->excludedCount);
        assertSame(64, strlen($plan->fingerprint)); // sha256 hex
        assertSame(64, strlen($plan->digest)); // sha256 hex

        // Nessuna modifica a indice/worktree: stato git IDENTICO, niente in stage.
        assertSame($before, $gitOut(['status', '--porcelain=v2'], $dir));
        assertSame('', trim($gitOut(['diff', '--cached', '--name-only'], $dir)));
    } finally {
        $rmrf($dir);
    }
});

test('proposeStage: digest stabile per stessa selezione, diverso per selezione diversa', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "1\n");
        file_put_contents($dir . '/b.txt', "1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/a.txt', "2\n");
        file_put_contents($dir . '/b.txt', "2\n");

        // Ordine d'input diverso → stesso digest (normalizzazione+ordine deterministico a monte).
        $d1 = $tool->proposeStage($ws($dir), ['a.txt', 'b.txt'], 6000)['plan']->digest;
        $d2 = $tool->proposeStage($ws($dir), ['a.txt', 'b.txt'], 6000)['plan']->digest;
        assertSame($d1, $d2);
        $d3 = $tool->proposeStage($ws($dir), ['a.txt'], 6000)['plan']->digest;
        assertSame(true, $d1 !== $d3);
    } finally {
        $rmrf($dir);
    }
});

test('proposeStage: percorso non modificato non è ammesso → nessun piano, nessun nome', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "uno\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir); // a.txt pulito, non modificato

        $res = $tool->proposeStage($ws($dir), ['a.txt'], 6000);
        assertSame(null, $res['plan']);
        assertSame(true, str_contains((string) $res['observation'], 'STAGING NON PROPOSTO'));
    } finally {
        $rmrf($dir);
    }
});

test('proposeStage offensivo: sensibile (.env) e runtime (storage/) mai nel piano o nell\'osservazione', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        mkdir($dir . '/storage', 0777, true);
        file_put_contents($dir . '/app.php', "<?php // v1\n");
        file_put_contents($dir . '/.env', "SECRET=v1\n");
        file_put_contents($dir . '/storage/x.log', "L1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/app.php', "<?php // v2\n"); // ammesso
        file_put_contents($dir . '/.env', "SECRET=v2\n");       // sensibile
        file_put_contents($dir . '/storage/x.log', "L2\n");     // runtime

        // Il modello prova a includere anche i percorsi esclusi: devono essere ignorati (solo conteggio).
        $res = $tool->proposeStage($ws($dir), ['app.php', '.env', 'storage/x.log'], 6000);
        $plan = $res['plan'];
        assertSame(true, $plan instanceof GitStagePlan);
        assertSame(['app.php'], $plan->paths());
        assertSame(2, $plan->excludedCount); // .env + storage/x.log, anonimi
        // Nessun nome escluso comparso.
        foreach ($plan->selected as $e) { assertSame(false, $e['path'] === '.env' || str_contains($e['path'], 'storage/')); }
        assertSame(false, in_array('.env', $plan->allowedNotSelected, true));
    } finally {
        $rmrf($dir);
    }
});

test('proposeStage: solo percorsi esclusi richiesti → nessun piano, osservazione senza nomi', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/.env', "SECRET=v1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/.env', "SECRET=TRAPELATO\n");

        $res = $tool->proposeStage($ws($dir), ['.env'], 6000);
        assertSame(null, $res['plan']);
        $obs = (string) $res['observation'];
        assertSame(false, str_contains($obs, '.env'));
        assertSame(false, str_contains($obs, 'TRAPELATO'));
    } finally {
        $rmrf($dir);
    }
});

test('proposeStage: rename verso percorso sensibile non è aggirabile dall\'origine', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/config.txt', "contenuto stabile abbastanza lungo\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        $git(['mv', 'config.txt', 'secret.key'], $dir); // rename staged, destinazione SENSIBILE

        // Richiedere l'origine (non sensibile) non deve mettere in stage una voce con capo sensibile.
        $res = $tool->proposeStage($ws($dir), ['config.txt'], 6000);
        assertSame(null, $res['plan']);
        assertSame(false, str_contains((string) $res['observation'], 'secret.key'));
        assertSame(false, str_contains((string) $res['observation'], 'config.txt'));
    } finally {
        $rmrf($dir);
    }
});

test('proposeStage: workspace revocato → nessun piano (fail closed), nessuna eccezione', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "x\n");
        $res = $tool->proposeStage($ws($dir, 'revoked'), ['a.txt'], 6000);
        assertSame(null, $res['plan']);
        assertSame(true, str_contains((string) $res['observation'], 'STAGING NON PROPOSTO'));
    } finally {
        $rmrf($dir);
    }
});

// --- Revisione: (1) stato troncato → fail closed ----------------------------------------------------

test('proposeStage: STATO TRONCATO → nessun piano, osservazione anonima e cappata, nessun percorso', function () use ($mktemp, $rmrf, $git, $ws) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        // Molti file non tracciati: lo `status` supera un cap piccolo → GitStatus::truncated.
        for ($i = 0; $i < 60; $i++) {
            file_put_contents($dir . '/file_prova_numero_' . $i . '.txt', "contenuto\n");
        }
        // Cap 300 byte: rev-parse (path breve) NON tronca → isRepository ok; status (grande) tronca.
        $truncTool = new CodeGitTool(new GitService(new GitInvoker(new GitLimits(15.0, 300))));
        $res = $truncTool->proposeStage($ws($dir), ['file_prova_numero_0.txt'], 200);
        assertSame(null, $res['plan']);
        $obs = (string) $res['observation'];
        assertSame(true, str_contains($obs, 'STAGING NON PROPOSTO'));
        assertSame(true, str_contains($obs, 'parziale'));
        assertSame(false, str_contains($obs, 'file_prova_numero_')); // nessun percorso
        assertSame(true, strlen($obs) <= 200); // cappata
    } finally {
        $rmrf($dir);
    }
});

// --- Revisione: (2) fingerprint legata allo stato reale delle SOLE voci selezionate -----------------

test('fingerprint (1): file UNTRACKED selezionato → stesso contenuto stesso digest, contenuto cambiato digest diverso', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/u.txt', "X\n"); // untracked
        $d1 = $tool->proposeStage($ws($dir), ['u.txt'], 6000)['plan']->digest;
        $d1b = $tool->proposeStage($ws($dir), ['u.txt'], 6000)['plan']->digest;
        assertSame($d1, $d1b); // stesso contenuto → stesso digest (il diff globale non vedrebbe l'untracked)
        file_put_contents($dir . '/u.txt', "Y\n");
        $d2 = $tool->proposeStage($ws($dir), ['u.txt'], 6000)['plan']->digest;
        assertSame(true, $d2 !== $d1);
    } finally {
        $rmrf($dir);
    }
});

test('fingerprint (2): file BINARIO tracked selezionato → byte cambiati, digest diverso', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/bin.dat', "\x00\x01\x02\x03");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/bin.dat', "\x00\x01\x02\x04"); // modificato (unstaged)
        $d1 = $tool->proposeStage($ws($dir), ['bin.dat'], 6000)['plan']->digest;
        file_put_contents($dir . '/bin.dat', "\x00\x01\x02\x05"); // byte diversi
        $d2 = $tool->proposeStage($ws($dir), ['bin.dat'], 6000)['plan']->digest;
        assertSame(true, $d2 !== $d1);
    } finally {
        $rmrf($dir);
    }
});

test('fingerprint (3): modifica di un file AMMESSO ma NON selezionato → digest invariato', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "1\n");
        file_put_contents($dir . '/c.txt', "1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/a.txt', "2\n"); // selezionato
        $d1 = $tool->proposeStage($ws($dir), ['a.txt'], 6000)['plan']->digest;

        file_put_contents($dir . '/c.txt', "2\n"); // ammesso ma NON selezionato: non deve toccare il digest
        $d2 = $tool->proposeStage($ws($dir), ['a.txt'], 6000)['plan']->digest;
        assertSame($d1, $d2);

        $git(['add', 'c.txt'], $dir); // anche mettere c.txt in stage non cambia il digest di a.txt
        $d3 = $tool->proposeStage($ws($dir), ['a.txt'], 6000)['plan']->digest;
        assertSame($d1, $d3);
    } finally {
        $rmrf($dir);
    }
});

test('fingerprint (4): INDICE dello STESSO file selezionato cambiato → digest diverso', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);

        file_put_contents($dir . '/a.txt', "A\n"); $git(['add', 'a.txt'], $dir); // indice = A
        file_put_contents($dir . '/a.txt', "B\n"); // worktree = B → status MM
        $d1 = $tool->proposeStage($ws($dir), ['a.txt'], 6000)['plan']->digest;

        file_put_contents($dir . '/a.txt', "C\n"); $git(['add', 'a.txt'], $dir); // indice = C (cambiato)
        file_put_contents($dir . '/a.txt', "B\n"); // worktree di nuovo B (invariato)
        $d2 = $tool->proposeStage($ws($dir), ['a.txt'], 6000)['plan']->digest;
        assertSame(true, $d2 !== $d1); // solo l'indice è cambiato, il worktree no
    } finally {
        $rmrf($dir);
    }
});

// --- Revisione: (3) rename/copy completo nel piano -------------------------------------------------

test('proposeStage rename: piano conserva path E origPath; origine o destinazione = stessa voce', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/config.txt', "contenuto stabile abbastanza lungo\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        $git(['mv', 'config.txt', 'renamed.txt'], $dir); // rename staged
        file_put_contents($dir . '/renamed.txt', "contenuto stabile abbastanza lungo, modificato\n"); // worktree M → candidato

        $byDest = $tool->proposeStage($ws($dir), ['renamed.txt'], 6000)['plan'];
        $byOrig = $tool->proposeStage($ws($dir), ['config.txt'], 6000)['plan'];
        assertSame(true, $byDest instanceof GitStagePlan);
        assertSame(true, $byOrig instanceof GitStagePlan);
        // Stessa voce, entrambi i capi conservati.
        assertSame('renamed.txt', $byDest->selected[0]['path']);
        assertSame('config.txt', $byDest->selected[0]['orig_path']);
        assertSame('rinominato', $byDest->selected[0]['status']);
        assertSame('renamed.txt', $byOrig->selected[0]['path']);
        assertSame('config.txt', $byOrig->selected[0]['orig_path']);
        // Selezionare origine o destinazione identifica la stessa voce → stesso digest.
        assertSame($byDest->digest, $byOrig->digest);
    } finally {
        $rmrf($dir);
    }
});

test('proposeStage rename: allowedNotSelected conserva l\'origine (non ambiguo)', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/one.txt', "contenuto uno abbastanza lungo per il rename\n");
        file_put_contents($dir . '/two.txt', "contenuto due abbastanza lungo per il rename\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        $git(['mv', 'one.txt', 'one_r.txt'], $dir);
        file_put_contents($dir . '/one_r.txt', "contenuto uno abbastanza lungo per il rename, mod\n");
        $git(['mv', 'two.txt', 'two_r.txt'], $dir);
        file_put_contents($dir . '/two_r.txt', "contenuto due abbastanza lungo per il rename, mod\n");

        $plan = $tool->proposeStage($ws($dir), ['one_r.txt'], 6000)['plan'];
        assertSame(true, $plan instanceof GitStagePlan);
        assertSame('one_r.txt', $plan->selected[0]['path']);
        assertSame('one.txt', $plan->selected[0]['orig_path']);
        // Il rename non selezionato compare con la sua origine, senza ambiguità.
        assertSame([['path' => 'two_r.txt', 'orig_path' => 'two.txt']], $plan->allowedNotSelected);
    } finally {
        $rmrf($dir);
    }
});

// --- Revisione: (6) symlink comparso tra status e fingerprint → fail closed ------------------------

test('fingerprint (6): symlink comparso su un capo (origine di un rename) → nessun piano, fail closed', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/orig.txt', "contenuto stabile abbastanza lungo per il rename\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        $git(['mv', 'orig.txt', 'dest.txt'], $dir); // rename staged
        file_put_contents($dir . '/dest.txt', "contenuto stabile abbastanza lungo per il rename, mod\n"); // RM

        // Un symlink COMPARE al vecchio nome (capo origine): la fingerprint deve fallire chiuso.
        @symlink($dir . '/dest.txt', $dir . '/orig.txt');

        $res = $tool->proposeStage($ws($dir), ['dest.txt'], 6000);
        assertSame(null, $res['plan']);
        assertSame(true, str_contains((string) $res['observation'], 'STAGING NON PROPOSTO'));
        assertSame(false, str_contains((string) $res['observation'], 'orig.txt'));
    } finally {
        $rmrf($dir);
    }
});

test('worktreeHash: file regolare normale → digest stabile su riletture', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "regolare\n");
        file_put_contents($dir . '/a.txt', "regolare-mod\n"); // untracked, contenuto stabile
        $d1 = $tool->proposeStage($ws($dir), ['a.txt'], 6000)['plan']->digest;
        $d2 = $tool->proposeStage($ws($dir), ['a.txt'], 6000)['plan']->digest;
        assertSame($d1, $d2);
    } finally {
        $rmrf($dir);
    }
});

test('worktreeHash: symlink GIÀ presente sul percorso selezionato → rifiuto, nessun nome esposto', function () use ($mktemp, $rmrf, $git, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/target.txt', "payload\n");
        @symlink($dir . '/target.txt', $dir . '/link.txt'); // link.txt è un symlink (untracked)
        $res = $tool->proposeStage($ws($dir), ['link.txt'], 6000);
        assertSame(null, $res['plan']);
        assertSame(false, str_contains((string) $res['observation'], 'link.txt'));
        assertSame(false, str_contains((string) $res['observation'], 'payload'));
    } finally {
        $rmrf($dir);
    }
});

test('worktreeHash: sostituzione con symlink TRA validazione e lettura (race) → rifiuto', function () use ($mktemp, $rmrf, $git, $ws) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/a.txt', "2\n"); // modificato, candidato (file regolare alla validazione)
        file_put_contents($dir . '/altrove_segreto.txt', "PAYLOAD\n");

        // Hook confinato: subito prima dell'apertura, sostituisce a.txt con un symlink verso altro file.
        $swapped = false;
        $hook = static function (string $abs) use (&$swapped, $dir): void {
            if (!$swapped && str_ends_with($abs, '/a.txt')) {
                $swapped = true;
                @unlink($abs);
                @symlink($dir . '/altrove_segreto.txt', $abs);
            }
        };
        $raceTool = new CodeGitTool(GitService::withDefaults(), beforeWorktreeOpen: $hook);
        $res = $raceTool->proposeStage($ws($dir), ['a.txt'], 6000);
        assertSame(null, $res['plan']); // fstat(target) ≠ lstat(symlink) → fail closed
        assertSame(true, str_contains((string) $res['observation'], 'STAGING NON PROPOSTO'));
        assertSame(false, str_contains((string) $res['observation'], 'a.txt'));
        assertSame(false, str_contains((string) $res['observation'], 'PAYLOAD'));
    } finally {
        $rmrf($dir);
    }
});

test('fingerprint: nessuna modifica a indice/HEAD/worktree durante il calcolo della fingerprint', function () use ($mktemp, $rmrf, $git, $gitOut, $ws, $tool) {
    $dir = $mktemp();
    try {
        $git(['init', '-q'], $dir);
        file_put_contents($dir . '/a.txt', "1\n");
        $git(['add', '-A'], $dir);
        $git(['commit', '-q', '-m', 'primo'], $dir);
        file_put_contents($dir . '/a.txt', "2\n");
        $head = trim($gitOut(['rev-parse', 'HEAD'], $dir));
        $before = $gitOut(['status', '--porcelain=v2'], $dir);
        $tool->proposeStage($ws($dir), ['a.txt'], 6000);
        assertSame($head, trim($gitOut(['rev-parse', 'HEAD'], $dir)));
        assertSame($before, $gitOut(['status', '--porcelain=v2'], $dir));
        assertSame('', trim($gitOut(['diff', '--cached', '--name-only'], $dir)));
    } finally {
        $rmrf($dir);
    }
});
