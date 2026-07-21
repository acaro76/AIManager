<?php

declare(strict_types=1);

use App\Core\Code\CodeWorkspace;
use App\Core\Code\SensitivePathPolicy;
use App\Core\Code\VerificationDetector;
use App\Core\Code\VerificationProfileRegistry;

// Fase 5 — il rilevamento: un profilo è disponibile solo se il suo binario esiste (checker
// iniettato) E i file richiesti sono presenti nel workspace confinato. Nessun comando eseguito.

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

$mkroot = static function (): string {
    $base = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
    $root = $base . '/aimanager_vdet_' . uniqid('', true);
    mkdir($root . '/app', 0777, true);
    file_put_contents($root . '/app/Foo.php', "<?php\n");
    return $root;
};

$ws = static fn (string $root): CodeWorkspace
    => new CodeWorkspace(1, $root, basename($root), 'active', new SensitivePathPolicy());

/** Checker che riconosce solo i binari elencati. */
$only = static fn (array $bins): callable => static fn (string $bin): bool => in_array($bin, $bins, true);

test('detector: php-lint disponibile quando php è su PATH', function () use ($mkroot, $ws, $rmrf, $only) {
    $root = $mkroot();
    try {
        $det = new VerificationDetector(new VerificationProfileRegistry(), $only(['php']));
        $ids = $det->availableIds($ws($root), null);
        assertSame(true, in_array('php-lint', $ids, true));
        // Senza node/python i loro profili non compaiono.
        assertSame(false, in_array('js-syntax', $ids, true));
        assertSame(false, in_array('py-syntax', $ids, true));
    } finally {
        $rmrf($root);
    }
});

test('detector: php-test compare solo se vendor/bin/phpunit esiste ed è eseguibile', function () use ($mkroot, $ws, $rmrf, $only) {
    $root = $mkroot();
    try {
        $det = new VerificationDetector(new VerificationProfileRegistry(), $only(['php']));
        assertSame(false, in_array('php-test', $det->availableIds($ws($root), null), true));

        mkdir($root . '/vendor/bin', 0777, true);
        file_put_contents($root . '/vendor/bin/phpunit', "#!/bin/sh\n");
        chmod($root . '/vendor/bin/phpunit', 0755);
        assertSame(true, in_array('php-test', $det->availableIds($ws($root), null), true));
    } finally {
        $rmrf($root);
    }
});

test('detector: un marker non eseguibile non basta per il program locale', function () use ($mkroot, $ws, $rmrf, $only) {
    $root = $mkroot();
    try {
        mkdir($root . '/vendor/bin', 0777, true);
        file_put_contents($root . '/vendor/bin/phpunit', "#!/bin/sh\n");
        chmod($root . '/vendor/bin/phpunit', 0644); // NON eseguibile
        $det = new VerificationDetector(new VerificationProfileRegistry(), $only(['php']));
        assertSame(false, in_array('php-test', $det->availableIds($ws($root), null), true));
    } finally {
        $rmrf($root);
    }
});

test('detector: enabledIds restringe ai soli profili abilitati lato server', function () use ($mkroot, $ws, $rmrf, $only) {
    $root = $mkroot();
    try {
        $det = new VerificationDetector(new VerificationProfileRegistry(), $only(['php', 'node', 'python3']));
        // Anche se php-lint sarebbe rilevabile, l'abilitazione lo esclude.
        $ids = $det->availableIds($ws($root), ['js-syntax']);
        assertSame(false, in_array('php-lint', $ids, true));
    } finally {
        $rmrf($root);
    }
});

test('detector: senza il binario richiesto il profilo non è disponibile', function () use ($mkroot, $ws, $rmrf, $only) {
    $root = $mkroot();
    try {
        $det = new VerificationDetector(new VerificationProfileRegistry(), $only([])); // nessun binario
        assertSame([], $det->availableIds($ws($root), ['php-lint']));
    } finally {
        $rmrf($root);
    }
});

test('detector: FAIL CLOSED — un profilo che genera figli è negato senza isolamento del gruppo', function () use ($mkroot, $ws, $rmrf, $only) {
    $root = $mkroot();
    try {
        // php-test è rilevabile (phpunit presente ed eseguibile).
        mkdir($root . '/vendor/bin', 0777, true);
        file_put_contents($root . '/vendor/bin/phpunit', "#!/bin/sh\n");
        chmod($root . '/vendor/bin/phpunit', 0755);
        $registry = new VerificationProfileRegistry();

        // Con isolamento GARANTITO → php-test disponibile.
        $withIso = new VerificationDetector($registry, $only(['php']), static fn (): bool => true);
        assertSame(true, in_array('php-test', $withIso->availableIds($ws($root), null), true));

        // Senza isolamento → php-test NEGATO (fail closed), ma php-lint (mono-processo) resta.
        $noIso = new VerificationDetector($registry, $only(['php']), static fn (): bool => false);
        $ids = $noIso->availableIds($ws($root), null);
        assertSame(false, in_array('php-test', $ids, true));
        assertSame(true, in_array('php-lint', $ids, true));
    } finally {
        $rmrf($root);
    }
});
