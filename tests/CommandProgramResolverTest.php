<?php

declare(strict_types=1);

use App\Core\Code\CommandProgramResolver;
use App\Core\Code\CommandSpec;

// Fase 6 — risoluzione SOLO in bin fidate (correzione #1): mai PATH ereditato, mai la root. Identità
// = path assoluto regolare. E il supporto di `--` (correzione #4): assente → utility non disponibile.

$mkdirp = static function (): string {
    $d = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/aim_res_' . uniqid('', true);
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

test('resolver: risolve un\'utility reale in /usr/bin o /bin come path assoluto', function () {
    $exe = (new CommandProgramResolver())->resolve('cat');
    if ($exe === null) { assertSame(true, true); return; } // ambiente minimale senza cat
    assertSame(true, str_starts_with($exe, '/'));
    assertSame(true, in_array(dirname($exe), ['/usr/bin', '/bin'], true) || str_ends_with($exe, '/cat'));
    assertSame(true, is_file($exe) && is_executable($exe));
});

test('resolver: nome non valido / programma inesistente → null', function () {
    $r = new CommandProgramResolver();
    assertSame(null, $r->resolve('../evil'));
    assertSame(null, $r->resolve('EVIL'));
    assertSame(null, $r->resolve('nonexistent_prog_xyz'));
});

test('resolver: NON risolve un eseguibile fuori dalle bin fidate (root/workspace)', function () use ($mkdirp, $rmrf) {
    $bin = $mkdirp();
    try {
        file_put_contents($bin . '/mytool', "#!/bin/sh\necho hi\n");
        chmod($bin . '/mytool', 0755);
        // Bin fidate = SOLO /usr/bin,/bin (default): la dir custom NON è fidata.
        assertSame(null, (new CommandProgramResolver())->resolve('mytool'));
        // Con la dir dichiarata fidata (solo test), invece risolve.
        assertSame($bin . '/mytool', (new CommandProgramResolver([$bin]))->resolve('mytool'));
    } finally { $rmrf($bin); }
});

test('resolver: un symlink in bin fidata che punta FUORI è rifiutato', function () use ($mkdirp, $rmrf) {
    $bin = $mkdirp();
    $outside = $mkdirp();
    try {
        file_put_contents($outside . '/real', "#!/bin/sh\necho hi\n");
        chmod($outside . '/real', 0755);
        @symlink($outside . '/real', $bin . '/evil');
        // realpath risolve fuori dalla bin fidata → rifiutato.
        assertSame(null, (new CommandProgramResolver([$bin]))->resolve('evil'));
    } finally { $rmrf($bin); $rmrf($outside); }
});

test('resolver: `--` supportato → true; utility che segnala opzione illegale → false', function () use ($mkdirp, $rmrf) {
    $bin = $mkdirp();
    try {
        file_put_contents($bin . '/goodtool', "#!/bin/sh\nexit 0\n");
        chmod($bin . '/goodtool', 0755);
        file_put_contents($bin . '/badtool', "#!/bin/sh\necho 'badtool: illegal option -- z' 1>&2\nexit 2\n");
        chmod($bin . '/badtool', 0755);
        $r = new CommandProgramResolver([$bin]);
        $good = new CommandSpec('goodtool', minPaths: 0, maxPaths: 0);
        $bad = new CommandSpec('badtool', minPaths: 0, maxPaths: 0);
        assertSame(true, $r->supportsDoubleDash($good));
        assertSame(false, $r->supportsDoubleDash($bad));
    } finally { $rmrf($bin); }
});
