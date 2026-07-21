<?php

declare(strict_types=1);

use App\Core\Code\CodeWorkspace;
use App\Core\Code\CommandPlan;
use App\Core\Code\CommandResult;
use App\Core\Code\CommandRunLimits;
use App\Core\Code\CommandRunner;
use App\Core\Code\CommandStore;
use App\Core\Code\SafeStorageDir;
use App\Core\Code\SensitivePathPolicy;

// Fase 6 — DIFESA dai directory-symlink (correzione #1): nessun componente sotto la radice storage
// può essere un symlink (base/proposals/workspace/execution/home/tmp), e la dir d'esecuzione non
// può preesistere o essere non vuota. Test offensivi per OGNI livello.

$mk = static function (): string {
    $d = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/aim_sym_' . uniqid('', true);
    mkdir($d, 0777, true);
    return $d;
};
$rmrf = static function (string $p) use (&$rmrf): void {
    if (is_link($p)) { @unlink($p); return; }
    if (is_dir($p)) {
        foreach (scandir($p) ?: [] as $e) { if ($e === '.' || $e === '..') { continue; } $rmrf($p . '/' . $e); }
        @rmdir($p);
        return;
    }
    @unlink($p);
};

test('SafeStorageDir: crea una catena normale e la conferma confinata', function () use ($mk, $rmrf) {
    $anchor = $mk();
    try {
        $dir = SafeStorageDir::ensure($anchor, ['a', 'b', 'c'], false);
        assertSame($anchor . '/a/b/c', $dir);
        assertSame(true, is_dir($dir));
    } finally { $rmrf($anchor); }
});

test('SafeStorageDir: un componente symlink (a QUALSIASI livello) è rifiutato', function () use ($mk, $rmrf) {
    $anchor = $mk();
    $outside = $mk();
    try {
        mkdir($anchor . '/a', 0700);
        @symlink($outside, $anchor . '/a/b'); // b è un symlink verso l'esterno
        assertSame(null, SafeStorageDir::ensure($anchor, ['a', 'b', 'c'], false));
        assertSame(null, SafeStorageDir::ensure($anchor, ['a', 'b'], false));
    } finally { $rmrf($anchor); $rmrf($outside); }
});

test('SafeStorageDir: freshLeaf rifiuta un leaf PREESISTENTE (o non vuoto)', function () use ($mk, $rmrf) {
    $anchor = $mk();
    try {
        mkdir($anchor . '/wid/exec', 0700, true);
        file_put_contents($anchor . '/wid/exec/leftover', 'x');
        assertSame(null, SafeStorageDir::ensure($anchor, ['wid', 'exec'], true));
        // Con freshLeaf, un execId NUOVO va bene.
        assertSame($anchor . '/wid/execNEW', SafeStorageDir::ensure($anchor, ['wid', 'execNEW'], true));
    } finally { $rmrf($anchor); }
});

test('SafeStorageDir: componente file / .. / vuoto rifiutati', function () use ($mk, $rmrf) {
    $anchor = $mk();
    try {
        file_put_contents($anchor . '/afile', 'x');
        assertSame(null, SafeStorageDir::ensure($anchor, ['afile', 'x'], false));
        assertSame(null, SafeStorageDir::ensure($anchor, ['..', 'x'], false));
        assertSame(null, SafeStorageDir::ensure($anchor, ['', 'x'], false));
        assertSame(null, SafeStorageDir::ensure($anchor, ['a/b'], false));
    } finally { $rmrf($anchor); }
});

test('CommandStore: proposals symlink → write fallisce chiusa, read null', function () use ($mk, $rmrf) {
    $base = $mk();
    $outside = $mk();
    try {
        mkdir($base . '/code_commands', 0700, true);
        @symlink($outside, $base . '/code_commands/proposals');
        $store = new CommandStore($base . '/code_commands');
        $threw = false;
        try { $store->write('cmd-aaaaaaaaaaaaaaaa', 'd', 1, '/usr/bin/cat', ['program' => 'cat', 'flags' => [], 'pattern' => null, 'rel_paths' => ['a.php']]); }
        catch (\RuntimeException) { $threw = true; }
        assertSame(true, $threw);
        assertSame(null, $store->read('cmd-aaaaaaaaaaaaaaaa'));
        // Nulla è stato scritto nella cartella esterna.
        assertSame(false, is_file($outside . '/cmd-aaaaaaaaaaaaaaaa.json'));
    } finally { $rmrf($base); $rmrf($outside); }
});

test('CommandStore: base (code_commands) symlink → rifiutato', function () use ($mk, $rmrf) {
    $base = $mk();
    $outside = $mk();
    try {
        @symlink($outside, $base . '/code_commands'); // la base stessa è un symlink
        $store = new CommandStore($base . '/code_commands');
        $threw = false;
        try { $store->write('cmd-bbbbbbbbbbbbbbbb', 'd', 1, '/usr/bin/cat', ['program' => 'cat', 'flags' => [], 'pattern' => null, 'rel_paths' => ['a.php']]); }
        catch (\RuntimeException) { $threw = true; }
        assertSame(true, $threw);
    } finally { $rmrf($base); $rmrf($outside); }
});

if (!CommandRunner::supportsProcessGroupIsolation()) {
    return;
}

test('CommandRunner: un componente runtime symlink → error, nessuna esecuzione', function () use ($mk, $rmrf) {
    $root = $mk();
    $outside = $mk();
    try {
        file_put_contents($root . '/hello.txt', "x\n");
        $rtBase = $root . '/code_runtime';
        mkdir($rtBase, 0700, true);
        @symlink($outside, $rtBase . '/7'); // il livello {workspace}=7 è un symlink
        $ws = new CodeWorkspace(7, $root, basename($root), 'active', new SensitivePathPolicy());
        $plan = new CommandPlan('helper', [], null, []);
        $res = (new CommandRunner(CommandRunLimits::defaults(), $rtBase))->run($plan, $ws, PHP_BINARY, 'exec-symlink123');
        assertSame(CommandResult::ERROR, $res->outcome);
        assertSame(false, is_dir($outside . '/exec-symlink123')); // nessun redirect verso l'esterno
    } finally { $rmrf($root); $rmrf($outside); }
});

test('CommandRunner: dir d\'esecuzione PREESISTENTE → error (freshLeaf)', function () use ($mk, $rmrf) {
    $root = $mk();
    try {
        file_put_contents($root . '/hello.txt', "x\n");
        $rtBase = $root . '/code_runtime';
        mkdir($rtBase . '/7/exec-preexisting1', 0700, true); // la dir d'esecuzione esiste già
        $ws = new CodeWorkspace(7, $root, basename($root), 'active', new SensitivePathPolicy());
        $plan = new CommandPlan('helper', [], null, []);
        $res = (new CommandRunner(CommandRunLimits::defaults(), $rtBase))->run($plan, $ws, PHP_BINARY, 'exec-preexisting1');
        assertSame(CommandResult::ERROR, $res->outcome);
    } finally { $rmrf($root); }
});
