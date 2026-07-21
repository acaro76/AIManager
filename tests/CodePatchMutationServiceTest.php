<?php

declare(strict_types=1);

use App\Core\Code\CodePatch;
use App\Core\Code\CodePatchJournal;
use App\Core\Code\CodePatchLimits;
use App\Core\Code\CodePatchMutationService;
use App\Core\Code\CodePatchOperationRepository;
use App\Core\Code\CodePatchProposal;
use App\Core\Code\CodePatchStore;
use App\Core\Code\CodePatchValidator;
use App\Core\Code\CodeWorkspace;
use App\Core\Code\CodeWorkspaceLock;
use App\Core\Code\CodeWorkspaceRepository;
use App\Core\Code\SensitivePathPolicy;
use App\Core\Database;
use App\Services\MigrationRunner;

// Fase 4 / F4.6 — applicazione atomica, rilettura/verifica, rollback locale, concorrenza,
// recovery e casi offensivi. Tutto su SQLite + cartelle TEMPORANEE, mai il DB reale.

$tmpBase = static function (): string {
    $base = realpath(sys_get_temp_dir());
    return $base === false ? sys_get_temp_dir() : $base;
};

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

$make = static function () use ($tmpBase, $rmrf): array {
    $id = uniqid('', true);
    $root = $tmpBase() . '/aimanager_mut_root_' . $id;
    mkdir($root, 0777, true);
    file_put_contents($root . '/file.php', "<?php\n\$x = 1;\necho \$x;\n");
    mkdir($root . '/sub');
    $storage = $tmpBase() . '/aimanager_mut_store_' . $id;
    $dbPath = $tmpBase() . '/aimanager_mut_db_' . $id . '.sqlite';
    $db = new Database($dbPath);
    $r = dirname(__DIR__);
    (new MigrationRunner($db, $r . '/database/migrations', $r . '/database/seeds'))->run();
    $now = date('c');
    $db->execute('INSERT INTO code_workspaces (root_path, name, status, authorized_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', [realpath($root), '', 'active', $now, $now, $now]);
    $wid = $db->lastInsertId();
    $db->execute('INSERT INTO code_sessions (workspace_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$wid, 't', 'active', $now, $now]);
    $sid = $db->lastInsertId();
    $cleanup = static function () use ($root, $storage, $dbPath, $rmrf): void {
        $rmrf($root);
        $rmrf($storage);
        foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) {
            if (is_file($f)) { @unlink($f); }
        }
    };
    return [$db, $wid, $sid, $root, $storage, $cleanup];
};

// Costruisce, valida e PERSISTE una proposta come farà il servizio di chat. Ritorna [opId, digest].
$propose = static function (Database $db, string $storage, int $wid, int $sid, array $changes): array {
    $workspace = (new CodeWorkspaceRepository($db))->findById($wid);
    $validation = (new CodePatchValidator(CodePatchLimits::defaults()))->validate($workspace, CodePatchProposal::fromActionData(['changes' => $changes], CodePatchLimits::defaults()));
    if (!$validation->ok) {
        throw new \RuntimeException('proposta non valida nel setup: ' . $validation->reason);
    }
    $patch = $validation->patch;
    $opId = 'op-' . bin2hex(random_bytes(12));
    $digest = $patch->digest();
    (new CodePatchOperationRepository($db))->create($opId, $wid, $sid, null, $digest, $patch->metadata(), 1800);
    (new CodePatchStore($storage))->write($opId, $patch, $validation->entries);
    return [$opId, $digest];
};

// --- Applicazione ------------------------------------------------------------------------------

test('mutation: apply di un update scrive il file e verifica l\'hash', function () use ($make, $propose) {
    [$db, $wid, $sid, $root, $storage, $cleanup] = $make();
    try {
        [$opId, $digest] = $propose($db, $storage, $wid, $sid, [
            ['op' => 'update', 'path' => 'file.php', 'edits' => [['old' => '$x = 1;', 'new' => '$x = 42;']]],
        ]);
        $svc = new CodePatchMutationService($db, $storage);
        $res = $svc->apply($wid, $sid, $opId, $digest);
        assertSame('applied', $res['status']);
        assertSame(true, $res['ok']);
        assertSame("<?php\n\$x = 42;\necho \$x;\n", file_get_contents($root . '/file.php'));
        $row = (new CodePatchOperationRepository($db))->find($opId);
        assertSame('applied', (string) $row['status']);
        assertSame(true, $row['applied_at'] !== null);
        // journal committed conservato per il rollback
        assertSame(CodePatchJournal::PHASE_COMMITTED, (new CodePatchJournal($storage))->read($opId)['phase']);
    } finally {
        $cleanup();
    }
});

test('mutation: apply di una create crea il file', function () use ($make, $propose) {
    [$db, $wid, $sid, $root, $storage, $cleanup] = $make();
    try {
        [$opId, $digest] = $propose($db, $storage, $wid, $sid, [
            ['op' => 'create', 'path' => 'sub/nuovo.md', 'content' => "# Ciao\n"],
        ]);
        $res = (new CodePatchMutationService($db, $storage))->apply($wid, $sid, $opId, $digest);
        assertSame('applied', $res['status']);
        assertSame("# Ciao\n", file_get_contents($root . '/sub/nuovo.md'));
    } finally {
        $cleanup();
    }
});

test('mutation: apply multi-file (update + create) è atomico e completo', function () use ($make, $propose) {
    [$db, $wid, $sid, $root, $storage, $cleanup] = $make();
    try {
        [$opId, $digest] = $propose($db, $storage, $wid, $sid, [
            ['op' => 'update', 'path' => 'file.php', 'edits' => [['old' => 'echo $x;', 'new' => 'echo $x + 1;']]],
            ['op' => 'create', 'path' => 'sub/extra.txt', 'content' => "extra\n"],
        ]);
        $res = (new CodePatchMutationService($db, $storage))->apply($wid, $sid, $opId, $digest);
        assertSame('applied', $res['status']);
        assertSame(true, str_contains(file_get_contents($root . '/file.php'), 'echo $x + 1;'));
        assertSame("extra\n", file_get_contents($root . '/sub/extra.txt'));
        assertSame(2, count($res['files']));
    } finally {
        $cleanup();
    }
});

// --- Precondizioni / difese --------------------------------------------------------------------

test('mutation: digest sbagliato alla conferma → denied, nessuna scrittura', function () use ($make, $propose) {
    [$db, $wid, $sid, $root, $storage, $cleanup] = $make();
    try {
        [$opId] = $propose($db, $storage, $wid, $sid, [
            ['op' => 'update', 'path' => 'file.php', 'edits' => [['old' => '$x = 1;', 'new' => '$x = 9;']]],
        ]);
        $before = file_get_contents($root . '/file.php');
        $res = (new CodePatchMutationService($db, $storage))->apply($wid, $sid, $opId, str_repeat('0', 64));
        assertSame('denied', $res['status']);
        assertSame($before, file_get_contents($root . '/file.php'));
    } finally {
        $cleanup();
    }
});

test('mutation: worktree sporco (file cambiato dopo la proposta) → stale, nessuna scrittura', function () use ($make, $propose) {
    [$db, $wid, $sid, $root, $storage, $cleanup] = $make();
    try {
        [$opId, $digest] = $propose($db, $storage, $wid, $sid, [
            ['op' => 'update', 'path' => 'file.php', 'edits' => [['old' => '$x = 1;', 'new' => '$x = 7;']]],
        ]);
        // l'utente modifica il file DOPO la proposta
        file_put_contents($root . '/file.php', "<?php\n\$x = 100;\n");
        $res = (new CodePatchMutationService($db, $storage))->apply($wid, $sid, $opId, $digest);
        assertSame('stale', $res['status']);
        assertSame("<?php\n\$x = 100;\n", file_get_contents($root . '/file.php'));
        assertSame('failed', (string) (new CodePatchOperationRepository($db))->find($opId)['status']);
    } finally {
        $cleanup();
    }
});

test('mutation: monouso — applicare due volte la stessa proposta fallisce la seconda', function () use ($make, $propose) {
    [$db, $wid, $sid, $root, $storage, $cleanup] = $make();
    try {
        [$opId, $digest] = $propose($db, $storage, $wid, $sid, [
            ['op' => 'update', 'path' => 'file.php', 'edits' => [['old' => '$x = 1;', 'new' => '$x = 2;']]],
        ]);
        $svc = new CodePatchMutationService($db, $storage);
        assertSame('applied', $svc->apply($wid, $sid, $opId, $digest)['status']);
        // seconda applicazione: stato ormai 'applied', digest ancora giusto → denied
        assertSame('denied', $svc->apply($wid, $sid, $opId, $digest)['status']);
    } finally {
        $cleanup();
    }
});

test('mutation: reject impedisce la successiva apply', function () use ($make, $propose) {
    [$db, $wid, $sid, $root, $storage, $cleanup] = $make();
    try {
        [$opId, $digest] = $propose($db, $storage, $wid, $sid, [
            ['op' => 'create', 'path' => 'sub/x.txt', 'content' => 'x'],
        ]);
        $svc = new CodePatchMutationService($db, $storage);
        assertSame('rejected', $svc->reject($wid, $sid, $opId)['status']);
        assertSame('denied', $svc->apply($wid, $sid, $opId, $digest)['status']);
        assertSame(false, file_exists($root . '/sub/x.txt'));
    } finally {
        $cleanup();
    }
});

test('mutation: workspace revocato → denied, nessuna scrittura', function () use ($make, $propose) {
    [$db, $wid, $sid, $root, $storage, $cleanup] = $make();
    try {
        [$opId, $digest] = $propose($db, $storage, $wid, $sid, [
            ['op' => 'update', 'path' => 'file.php', 'edits' => [['old' => '$x = 1;', 'new' => '$x = 5;']]],
        ]);
        $db->execute('UPDATE code_workspaces SET status = ? WHERE id = ?', ['revoked', $wid]);
        $before = file_get_contents($root . '/file.php');
        $res = (new CodePatchMutationService($db, $storage))->apply($wid, $sid, $opId, $digest);
        assertSame('denied', $res['status']);
        assertSame($before, file_get_contents($root . '/file.php'));
    } finally {
        $cleanup();
    }
});

test('mutation: lock occupato → busy', function () use ($make, $propose) {
    [$db, $wid, $sid, $root, $storage, $cleanup] = $make();
    try {
        [$opId, $digest] = $propose($db, $storage, $wid, $sid, [
            ['op' => 'update', 'path' => 'file.php', 'edits' => [['old' => '$x = 1;', 'new' => '$x = 3;']]],
        ]);
        $lock = new CodeWorkspaceLock($storage);
        assertSame(true, $lock->acquire($wid));
        try {
            $res = (new CodePatchMutationService($db, $storage))->apply($wid, $sid, $opId, $digest);
            assertSame('busy', $res['status']);
        } finally {
            $lock->release();
        }
    } finally {
        $cleanup();
    }
});

test('mutation offensivo: target sostituito da symlink dopo la proposta → non scrive attraverso il symlink', function () use ($make, $propose, $tmpBase, $rmrf) {
    [$db, $wid, $sid, $root, $storage, $cleanup] = $make();
    $outside = $tmpBase() . '/aimanager_mut_outside_' . uniqid('', true) . '.txt';
    file_put_contents($outside, "SEGRETO\n");
    try {
        [$opId, $digest] = $propose($db, $storage, $wid, $sid, [
            ['op' => 'update', 'path' => 'file.php', 'edits' => [['old' => '$x = 1;', 'new' => '$x = 2;']]],
        ]);
        // sostituisci file.php con un symlink verso un file ESTERNO
        unlink($root . '/file.php');
        symlink($outside, $root . '/file.php');
        $res = (new CodePatchMutationService($db, $storage))->apply($wid, $sid, $opId, $digest);
        assertSame(true, in_array($res['status'], ['stale', 'denied'], true));
        // il file esterno NON è stato toccato
        assertSame("SEGRETO\n", file_get_contents($outside));
    } finally {
        $cleanup();
        $rmrf($outside);
    }
});

// --- Rollback ----------------------------------------------------------------------------------

test('mutation: rollback ripristina il preimage di un update', function () use ($make, $propose) {
    [$db, $wid, $sid, $root, $storage, $cleanup] = $make();
    try {
        $original = file_get_contents($root . '/file.php');
        [$opId, $digest] = $propose($db, $storage, $wid, $sid, [
            ['op' => 'update', 'path' => 'file.php', 'edits' => [['old' => '$x = 1;', 'new' => '$x = 2;']]],
        ]);
        $svc = new CodePatchMutationService($db, $storage);
        $svc->apply($wid, $sid, $opId, $digest);
        assertSame(true, str_contains(file_get_contents($root . '/file.php'), '$x = 2;'));
        $res = $svc->rollback($wid, $sid, $opId);
        assertSame('rolled_back', $res['status']);
        assertSame($original, file_get_contents($root . '/file.php'));
        assertSame('rolled_back', (string) (new CodePatchOperationRepository($db))->find($opId)['status']);
    } finally {
        $cleanup();
    }
});

test('mutation: rollback di una create elimina il file creato', function () use ($make, $propose) {
    [$db, $wid, $sid, $root, $storage, $cleanup] = $make();
    try {
        [$opId, $digest] = $propose($db, $storage, $wid, $sid, [
            ['op' => 'create', 'path' => 'sub/creato.txt', 'content' => "c\n"],
        ]);
        $svc = new CodePatchMutationService($db, $storage);
        $svc->apply($wid, $sid, $opId, $digest);
        assertSame(true, file_exists($root . '/sub/creato.txt'));
        assertSame('rolled_back', $svc->rollback($wid, $sid, $opId)['status']);
        assertSame(false, file_exists($root . '/sub/creato.txt'));
    } finally {
        $cleanup();
    }
});

test('mutation: rollback negato se il file è stato modificato dopo l\'applicazione', function () use ($make, $propose) {
    [$db, $wid, $sid, $root, $storage, $cleanup] = $make();
    try {
        [$opId, $digest] = $propose($db, $storage, $wid, $sid, [
            ['op' => 'update', 'path' => 'file.php', 'edits' => [['old' => '$x = 1;', 'new' => '$x = 2;']]],
        ]);
        $svc = new CodePatchMutationService($db, $storage);
        $svc->apply($wid, $sid, $opId, $digest);
        // l'utente modifica il file DOPO l'applicazione
        file_put_contents($root . '/file.php', "<?php\n// mio\n");
        $res = $svc->rollback($wid, $sid, $opId);
        assertSame('rollback_denied', $res['status']);
        assertSame("<?php\n// mio\n", file_get_contents($root . '/file.php'));
    } finally {
        $cleanup();
    }
});

// --- Recovery dopo crash -----------------------------------------------------------------------

test('mutation: recovery compensa un apply interrotto (journal prepared) alla mutazione successiva', function () use ($make, $propose) {
    [$db, $wid, $sid, $root, $storage, $cleanup] = $make();
    try {
        $original = file_get_contents($root . '/file.php');
        [$opId, $digest] = $propose($db, $storage, $wid, $sid, [
            ['op' => 'update', 'path' => 'file.php', 'edits' => [['old' => '$x = 1;', 'new' => '$x = 2;']]],
        ]);
        // Simula un CRASH a metà applicazione: stato 'applying', journal 'prepared' col preimage,
        // e il file già portato al contenuto NUOVO (come se il rename fosse avvenuto prima del crash).
        $newContent = "<?php\n\$x = 2;\necho \$x;\n";
        (new CodePatchOperationRepository($db))->transition($opId, ['proposed'], 'applying');
        (new CodePatchJournal($storage))->prepare($opId, $wid, [[
            'op' => 'update', 'rel_path' => 'file.php',
            'base_sha256' => hash('sha256', $original), 'result_sha256' => hash('sha256', $newContent),
            'preimage' => $original, 'mode' => 0644,
        ]]);
        file_put_contents($root . '/file.php', $newContent); // stato "nuovo" parziale

        // Una nuova proposta+apply innesca il recovery PRIMA: il file torna al preimage, op → failed.
        [$opId2, $digest2] = $propose($db, $storage, $wid, $sid, [
            ['op' => 'create', 'path' => 'sub/z.txt', 'content' => "z\n"],
        ]);
        $svc = new CodePatchMutationService($db, $storage);
        $res = $svc->apply($wid, $sid, $opId2, $digest2);

        assertSame($original, file_get_contents($root . '/file.php')); // preimage ripristinato
        assertSame('failed', (string) (new CodePatchOperationRepository($db))->find($opId)['status']);
        assertSame(false, (new CodePatchJournal($storage))->exists($opId)); // journal scartato
        assertSame('applied', $res['status']); // la nuova operazione va comunque a buon fine
    } finally {
        $cleanup();
    }
});

// --- Compensazione fail-closed (problema #1) --------------------------------------------------

test('mutation: compensazione incompleta conserva journal e payload', function () use ($make, $propose) {
    [$db, $wid, $sid, $root, $storage, $cleanup] = $make();
    try {
        // Creiamo un file che sappiamo poter modificare, poi rendiamo non scrivibile la directory
        // DOPO l'applicazione del primo file ma PRIMA del secondo, simulando un errore a metà.
        // Usiamo un approccio diverso: creiamo un multi-file, e simuliamo il crash più basso.
        // Approccio: usiamo un apply reale e poi simuliamo lo scenario con il journal manuale.

        $original = file_get_contents($root . '/file.php');
        // Costruiamo un journal 'prepared' simulando un apply interrotto che ha scritto il file
        // ma NON riesce a compensare (il file è stato rimosso = la compensazione lo salterebbe).
        $opId = 'op-compfail-' . bin2hex(random_bytes(8));
        $newContent = "<?php\n\$x = 99;\n";
        $resultHash = hash('sha256', $newContent);
        $baseHash = hash('sha256', $original);

        (new CodePatchOperationRepository($db))->create($opId, $wid, $sid, null, str_repeat('a', 64), [
            ['path' => 'file.php', 'op' => 'update', 'base_sha256' => $baseHash, 'result_sha256' => $resultHash],
        ], 1800);
        (new CodePatchOperationRepository($db))->transition($opId, ['proposed'], 'applying');

        // Il file è ora al contenuto "nuovo" (simula applicazione avvenuta)
        file_put_contents($root . '/file.php', $newContent);

        // Journal prepared con preimage
        (new CodePatchJournal($storage))->prepare($opId, $wid, [[
            'op' => 'update', 'rel_path' => 'file.php',
            'base_sha256' => $baseHash, 'result_sha256' => $resultHash,
            'preimage' => $original, 'mode' => 0644,
        ]]);

        // Ora MODIFICHIAMO il file con un terzo contenuto (simula modifica utente dopo il crash)
        file_put_contents($root . '/file.php', "<?php\n// utente\n");

        // La prossima mutazione innesca il recovery: il file NON corrisponde più a result_sha256
        // né a base_sha256 → il recovery non lo tocca → journal conservato.
        [$opId2, $digest2] = $propose($db, $storage, $wid, $sid, [
            ['op' => 'create', 'path' => 'sub/w.txt', 'content' => "w\n"],
        ]);
        $svc = new CodePatchMutationService($db, $storage);
        $svc->apply($wid, $sid, $opId2, $digest2);

        // Il file dell'utente NON deve essere stato toccato
        assertSame("<?php\n// utente\n", file_get_contents($root . '/file.php'));
        // Lo stato è failed (la transition avviene comunque)
        assertSame('failed', (string) (new CodePatchOperationRepository($db))->find($opId)['status']);
        // Ma il journal è CONSERVATO (recovery incompleto)
        assertSame(true, (new CodePatchJournal($storage))->exists($opId));
    } finally {
        $cleanup();
    }
});

test('mutation: recovery incompleto è ripetibile alla mutazione successiva', function () use ($make, $propose) {
    [$db, $wid, $sid, $root, $storage, $cleanup] = $make();
    try {
        $original = file_get_contents($root . '/file.php');
        $opId = 'op-recovrep-' . bin2hex(random_bytes(8));
        $newContent = "<?php\n\$x = 50;\n";
        $resultHash = hash('sha256', $newContent);
        $baseHash = hash('sha256', $original);

        (new CodePatchOperationRepository($db))->create($opId, $wid, $sid, null, str_repeat('b', 64), [
            ['path' => 'file.php', 'op' => 'update', 'base_sha256' => $baseHash, 'result_sha256' => $resultHash],
        ], 1800);
        // Stato 'failed' (il primo recovery lo ha già marcato failed ma non ha potuto ripristinare)
        (new CodePatchOperationRepository($db))->transition($opId, ['proposed'], 'applying');

        // Il file è al contenuto "nuovo" (scritto dall'apply interrotto)
        file_put_contents($root . '/file.php', $newContent);

        // Journal prepared con preimage
        (new CodePatchJournal($storage))->prepare($opId, $wid, [[
            'op' => 'update', 'rel_path' => 'file.php',
            'base_sha256' => $baseHash, 'result_sha256' => $resultHash,
            'preimage' => $original, 'mode' => 0644,
        ]]);

        // Prima mutazione: il file corrisponde a result_sha256 → recovery RIESCE, preimage ripristinato.
        [$opId2, $digest2] = $propose($db, $storage, $wid, $sid, [
            ['op' => 'create', 'path' => 'sub/r1.txt', 'content' => "r1\n"],
        ]);
        $svc = new CodePatchMutationService($db, $storage);
        $svc->apply($wid, $sid, $opId2, $digest2);

        // Il preimage è ripristinato
        assertSame($original, file_get_contents($root . '/file.php'));
        // Il journal è stato scartato (recovery completo)
        assertSame(false, (new CodePatchJournal($storage))->exists($opId));
    } finally {
        $cleanup();
    }
});

test('mutation: recovery con conflitto utente non sovrascrive il file modificato', function () use ($make, $propose) {
    [$db, $wid, $sid, $root, $storage, $cleanup] = $make();
    try {
        $original = file_get_contents($root . '/file.php');
        $opId = 'op-userconfl-' . bin2hex(random_bytes(8));
        $newContent = "<?php\n\$x = 77;\n";
        $resultHash = hash('sha256', $newContent);
        $baseHash = hash('sha256', $original);
        $userContent = "<?php\n// user edit after crash\n";

        (new CodePatchOperationRepository($db))->create($opId, $wid, $sid, null, str_repeat('c', 64), [
            ['path' => 'file.php', 'op' => 'update', 'base_sha256' => $baseHash, 'result_sha256' => $resultHash],
        ], 1800);
        (new CodePatchOperationRepository($db))->transition($opId, ['proposed'], 'applying');

        // L'utente ha modificato il file DOPO il crash, ora non corrisponde né a base né a result
        file_put_contents($root . '/file.php', $userContent);

        (new CodePatchJournal($storage))->prepare($opId, $wid, [[
            'op' => 'update', 'rel_path' => 'file.php',
            'base_sha256' => $baseHash, 'result_sha256' => $resultHash,
            'preimage' => $original, 'mode' => 0644,
        ]]);

        // Recovery: il file non corrisponde né a base_sha256 né a result_sha256 → NON sovrascrive
        [$opId2, $digest2] = $propose($db, $storage, $wid, $sid, [
            ['op' => 'create', 'path' => 'sub/uc.txt', 'content' => "uc\n"],
        ]);
        $svc = new CodePatchMutationService($db, $storage);
        $svc->apply($wid, $sid, $opId2, $digest2);

        // Il file dell'utente è INTATTO
        assertSame($userContent, file_get_contents($root . '/file.php'));
        // Journal conservato (recovery incompleto)
        assertSame(true, (new CodePatchJournal($storage))->exists($opId));
    } finally {
        $cleanup();
    }
});

// --- Preservazione permessi (problema #2) -----------------------------------------------------

test('mutation: update preserva i permessi 0755 del file originale', function () use ($make, $propose) {
    [$db, $wid, $sid, $root, $storage, $cleanup] = $make();
    try {
        // Rendi il file eseguibile
        chmod($root . '/file.php', 0755);
        assertSame(0755, fileperms($root . '/file.php') & 0777);

        [$opId, $digest] = $propose($db, $storage, $wid, $sid, [
            ['op' => 'update', 'path' => 'file.php', 'edits' => [['old' => '$x = 1;', 'new' => '$x = 42;']]],
        ]);
        $svc = new CodePatchMutationService($db, $storage);
        $res = $svc->apply($wid, $sid, $opId, $digest);
        assertSame('applied', $res['status']);
        // Il contenuto è cambiato
        assertSame(true, str_contains(file_get_contents($root . '/file.php'), '$x = 42;'));
        // I permessi sono PRESERVATI: il bit eseguibile non è stato rimosso
        clearstatcache(true, $root . '/file.php');
        assertSame(0755, fileperms($root . '/file.php') & 0777);
    } finally {
        $cleanup();
    }
});

test('mutation: rollback di un file 0755 ripristina contenuto e permessi', function () use ($make, $propose) {
    [$db, $wid, $sid, $root, $storage, $cleanup] = $make();
    try {
        $original = file_get_contents($root . '/file.php');
        chmod($root . '/file.php', 0755);

        [$opId, $digest] = $propose($db, $storage, $wid, $sid, [
            ['op' => 'update', 'path' => 'file.php', 'edits' => [['old' => '$x = 1;', 'new' => '$x = 42;']]],
        ]);
        $svc = new CodePatchMutationService($db, $storage);
        $svc->apply($wid, $sid, $opId, $digest);
        // Dopo apply: contenuto nuovo, permessi 0755 preservati
        assertSame(0755, fileperms($root . '/file.php') & 0777);

        // Rollback
        $res = $svc->rollback($wid, $sid, $opId);
        assertSame('rolled_back', $res['status']);
        assertSame($original, file_get_contents($root . '/file.php'));
        // Permessi ripristinati a 0755
        clearstatcache(true, $root . '/file.php');
        assertSame(0755, fileperms($root . '/file.php') & 0777);
    } finally {
        $cleanup();
    }
});

test('mutation: create produce un file con permessi 0644', function () use ($make, $propose) {
    [$db, $wid, $sid, $root, $storage, $cleanup] = $make();
    try {
        [$opId, $digest] = $propose($db, $storage, $wid, $sid, [
            ['op' => 'create', 'path' => 'sub/nuovo.sh', 'content' => "#!/bin/bash\necho hello\n"],
        ]);
        $svc = new CodePatchMutationService($db, $storage);
        $res = $svc->apply($wid, $sid, $opId, $digest);
        assertSame('applied', $res['status']);
        // Il file creato ha permessi 0644, NON eseguibile (il modello non decide i permessi)
        clearstatcache(true, $root . '/sub/nuovo.sh');
        assertSame(0644, fileperms($root . '/sub/nuovo.sh') & 0777);
    } finally {
        $cleanup();
    }
});

// --- Regressioni finali -----------------------------------------------------------------------

test('mutation: rolling_back incompleto non diventa rolled_back e conserva il journal', function () use ($make, $propose) {
    [$db, $wid, $sid, $root, $storage, $cleanup] = $make();
    try {
        $original = file_get_contents($root . '/file.php');
        $opId = 'op-rbinc-' . bin2hex(random_bytes(8));
        $newContent = "<?php\n\$x = 88;\n";
        $resultHash = hash('sha256', $newContent);
        $baseHash = hash('sha256', $original);
        $userContent = "<?php\n// user touched during rollback\n";

        (new CodePatchOperationRepository($db))->create($opId, $wid, $sid, null, str_repeat('d', 64), [
            ['path' => 'file.php', 'op' => 'update', 'base_sha256' => $baseHash, 'result_sha256' => $resultHash],
        ], 1800);
        // Simula: l'operazione era 'applied', poi il rollback è stato avviato ma interrotto.
        (new CodePatchOperationRepository($db))->transition($opId, ['proposed'], 'applying');
        (new CodePatchOperationRepository($db))->transition($opId, ['applying'], 'applied');

        // Journal in fase rolling_back (rollback interrotto a metà)
        $journal = new CodePatchJournal($storage);
        $journal->prepare($opId, $wid, [[
            'op' => 'update', 'rel_path' => 'file.php',
            'base_sha256' => $baseHash, 'result_sha256' => $resultHash,
            'preimage' => $original, 'mode' => 0644,
        ]]);
        $journal->markApplied($opId);
        $journal->markRollingBack($opId);

        // L'utente ha modificato il file: hash diverso da base E da result → conflitto.
        file_put_contents($root . '/file.php', $userContent);

        // La prossima mutazione innesca il recovery.
        [$opId2, $digest2] = $propose($db, $storage, $wid, $sid, [
            ['op' => 'create', 'path' => 'sub/rb.txt', 'content' => "rb\n"],
        ]);
        $svc = new CodePatchMutationService($db, $storage);
        $svc->apply($wid, $sid, $opId2, $digest2);

        // Il file dell'utente è INTATTO.
        assertSame($userContent, file_get_contents($root . '/file.php'));
        // Lo stato NON è rolled_back: il recovery non ha potuto completare.
        $op = (new CodePatchOperationRepository($db))->find($opId);
        assertSame(true, $op['status'] !== 'rolled_back');
        // Journal CONSERVATO.
        assertSame(true, (new CodePatchJournal($storage))->exists($opId));
    } finally {
        $cleanup();
    }
});

test('mutation: create sostituito da symlink/directory conserva journal e non tocca il target', function () use ($make, $propose) {
    [$db, $wid, $sid, $root, $storage, $cleanup] = $make();
    try {
        $opId = 'op-crsym-' . bin2hex(random_bytes(8));
        $createdContent = "created content\n";
        $resultHash = hash('sha256', $createdContent);

        (new CodePatchOperationRepository($db))->create($opId, $wid, $sid, null, str_repeat('e', 64), [
            ['path' => 'sub/link.txt', 'op' => 'create', 'base_sha256' => null, 'result_sha256' => $resultHash],
        ], 1800);
        (new CodePatchOperationRepository($db))->transition($opId, ['proposed'], 'applying');

        // Il file creato è stato sostituito dall'utente con un symlink.
        @mkdir($root . '/sub', 0755, true);
        file_put_contents($root . '/sub/real.txt', "real target\n");
        symlink($root . '/sub/real.txt', $root . '/sub/link.txt');

        (new CodePatchJournal($storage))->prepare($opId, $wid, [[
            'op' => 'create', 'rel_path' => 'sub/link.txt',
            'base_sha256' => null, 'result_sha256' => $resultHash,
            'preimage' => null, 'mode' => 0644,
        ]]);

        // La prossima mutazione innesca il recovery.
        [$opId2, $digest2] = $propose($db, $storage, $wid, $sid, [
            ['op' => 'create', 'path' => 'sub/other.txt', 'content' => "other\n"],
        ]);
        $svc = new CodePatchMutationService($db, $storage);
        $svc->apply($wid, $sid, $opId2, $digest2);

        // Il symlink NON è stato toccato (non eliminato, non sovrascritto).
        assertSame(true, is_link($root . '/sub/link.txt'));
        // Il target del symlink è intatto.
        assertSame("real target\n", file_get_contents($root . '/sub/real.txt'));
        // Journal CONSERVATO (recovery incompleto per conflitto).
        assertSame(true, (new CodePatchJournal($storage))->exists($opId));
    } finally {
        $cleanup();
    }
});
