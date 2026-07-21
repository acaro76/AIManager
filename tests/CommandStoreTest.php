<?php

declare(strict_types=1);

use App\Core\Code\CommandPlan;
use App\Core\Code\CommandStore;

// Fase 6 — deposito protetto del piano canonico: roundtrip, no-symlink, prune (TTL), id validato.

$mkbase = static function (): string {
    $d = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/aim_cstore_' . uniqid('', true);
    mkdir($d, 0777, true);
    return $d;
};
$rmrf = static function (string $p) use (&$rmrf): void {
    if (is_dir($p) && !is_link($p)) {
        foreach (scandir($p) ?: [] as $e) { if ($e === '.' || $e === '..') { continue; } $rmrf($p . '/' . $e); }
        @rmdir($p);
        return;
    }
    @unlink($p);
};

test('store: write/read roundtrip del piano (pattern grezzo vive SOLO qui)', function () use ($mkbase, $rmrf) {
    $base = $mkbase();
    try {
        $store = new CommandStore($base);
        $plan = new CommandPlan('grep', ['-n'], 'segreto', ['src/a.php']);
        $store->write('cmd-aaaaaaaaaaaaaaaa', 'digest123', 1, '/usr/bin/grep', $plan->toStore());
        $read = $store->read('cmd-aaaaaaaaaaaaaaaa');
        assertSame('digest123', $read['digest']);
        assertSame(1, $read['policy_version']);
        assertSame('/usr/bin/grep', $read['program_exe']);
        assertSame('segreto', $read['plan']->pattern);
        assertSame(['src/a.php'], $read['plan']->relPaths);
    } finally { $rmrf($base); }
});

test('store: id fuori formato è rifiutato', function () use ($mkbase, $rmrf) {
    $base = $mkbase();
    try {
        $store = new CommandStore($base);
        $threw = false;
        try { $store->write('short', 'd', 1, '/usr/bin/cat', ['program' => 'cat', 'flags' => [], 'pattern' => null, 'rel_paths' => []]); }
        catch (\InvalidArgumentException) { $threw = true; }
        assertSame(true, $threw);
    } finally { $rmrf($base); }
});

test('store: read RIFIUTA un file symlink (no-symlink, fail closed)', function () use ($mkbase, $rmrf) {
    $base = $mkbase();
    try {
        $store = new CommandStore($base);
        $dir = $base . '/proposals';
        @mkdir($dir, 0700, true);
        $outside = $base . '/outside.json';
        file_put_contents($outside, json_encode(['plan' => ['program' => 'cat', 'flags' => [], 'pattern' => null, 'rel_paths' => []]]));
        @symlink($outside, $dir . '/cmd-bbbbbbbbbbbbbbbb.json');
        assertSame(null, $store->read('cmd-bbbbbbbbbbbbbbbb'));
    } finally { $rmrf($base); }
});

test('store: prune rimuove le proposte oltre il TTL', function () use ($mkbase, $rmrf) {
    $base = $mkbase();
    try {
        $store = new CommandStore($base);
        $store->write('cmd-cccccccccccccccc', 'd', 1, '/usr/bin/cat', ['program' => 'cat', 'flags' => [], 'pattern' => null, 'rel_paths' => ['a.php']]);
        $file = $base . '/proposals/cmd-cccccccccccccccc.json';
        touch($file, time() - 5000);
        $store->prune(900);
        assertSame(false, is_file($file));
    } finally { $rmrf($base); }
});

test('store: digest del piano lega scope e policy_version', function () {
    $plan = new CommandPlan('cat', [], null, ['a.php']);
    $d1 = $plan->digest('/root', 1, 2, 1);
    $d2 = $plan->digest('/root', 1, 2, 2); // policy diversa
    $d3 = $plan->digest('/root', 9, 2, 1); // workspace diverso
    assertSame(false, $d1 === $d2);
    assertSame(false, $d1 === $d3);
    assertSame($d1, $plan->digest('/root', 1, 2, 1)); // stabile
});
