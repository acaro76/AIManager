<?php

declare(strict_types=1);

use App\Core\Code\VerificationLimits;
use App\Core\Code\VerificationProfile;
use App\Core\Code\VerificationProfileRegistry;

// Fase 5 — il profilo di verifica: forma FISSA, un solo segnaposto {file}, nessuna shell. Il
// registro cura solo PHP/JavaScript/Python; Git è escluso; ogni id è distinto.

$throws = static function (callable $fn): bool {
    try {
        $fn();
        return false;
    } catch (\Throwable $e) {
        return true;
    }
};

test('profile: render sostituisce {file} con il percorso e tiene i token letterali', function () {
    $p = new VerificationProfile('php-lint', 'php', 'lint', 'php', ['-l', VerificationProfile::FILE_PLACEHOLDER], requiredBinary: 'php');
    assertSame(['php', '-l', 'app/Foo.php'], $p->render('app/Foo.php'));
    assertSame(true, $p->requiresFile());
});

test('profile: {file} non viene MAI spezzato, neanche con metacaratteri shell nel nome', function () {
    $p = new VerificationProfile('php-lint', 'php', 'lint', 'php', ['-l', VerificationProfile::FILE_PLACEHOLDER], requiredBinary: 'php');
    // Un nome patologico resta UN solo argomento (l'esecuzione è argv, non shell).
    $argv = $p->render('a b;c.php');
    assertSame(3, count($argv));
    assertSame('a b;c.php', $argv[2]);
});

test('profile: un profilo senza {file} ignora il bersaglio', function () {
    $p = new VerificationProfile('php-test', 'php', 'test', 'vendor/bin/phpunit', ['--no-coverage'], requiredFiles: ['vendor/bin/phpunit']);
    assertSame(false, $p->requiresFile());
    assertSame(['vendor/bin/phpunit', '--no-coverage'], $p->render(null));
});

test('profile: richiede il file quando ha {file} e ne manca uno', function () use ($throws) {
    $p = new VerificationProfile('js-syntax', 'javascript', 'syntax', 'node', ['--check', VerificationProfile::FILE_PLACEHOLDER], requiredBinary: 'node');
    assertSame(true, $throws(static fn () => $p->render(null)));
    assertSame(true, $throws(static fn () => $p->render('../fuori.js'))); // path non relativo
});

test('profile: rifiuta due segnaposto {file}', function () use ($throws) {
    assertSame(true, $throws(static fn () => new VerificationProfile(
        'x', 'php', 'lint', 'php', [VerificationProfile::FILE_PLACEHOLDER, VerificationProfile::FILE_PLACEHOLDER], requiredBinary: 'php'
    )));
});

test('profile: rifiuta linguaggio e tipo fuori vocabolario (niente git)', function () use ($throws) {
    assertSame(true, $throws(static fn () => new VerificationProfile('x', 'ruby', 'lint', 'ruby', [], requiredBinary: 'ruby')));
    assertSame(true, $throws(static fn () => new VerificationProfile('x', 'php', 'git', 'php', [], requiredBinary: 'php')));
    // Git non è nemmeno un tipo ammesso.
    assertSame(false, in_array('git', VerificationProfile::KINDS, true));
});

test('profile: un program locale deve essere dichiarato tra requiredFiles', function () use ($throws) {
    assertSame(true, $throws(static fn () => new VerificationProfile('x', 'javascript', 'lint', 'node_modules/.bin/eslint', [VerificationProfile::FILE_PLACEHOLDER])));
});

test('profile: nome di binario con separatore o metacaratteri rifiutato', function () use ($throws) {
    assertSame(true, $throws(static fn () => new VerificationProfile('x', 'php', 'lint', 'bin/php', ['-l'], requiredBinary: '')));
    assertSame(true, $throws(static fn () => new VerificationProfile('x', 'php', 'lint', 'php; rm', ['-l'], requiredBinary: 'php')));
});

test('registro: i curati sono solo php/js/python, id distinti, nessun git', function () {
    $r = new VerificationProfileRegistry();
    $ids = $r->ids();
    assertSame(true, in_array('php-lint', $ids, true));
    assertSame(count($ids), count(array_unique($ids)));
    foreach ($r->all() as $p) {
        assertSame(true, in_array($p->language, VerificationProfile::LANGUAGES, true));
        assertSame(true, in_array($p->kind, VerificationProfile::KINDS, true));
        // Nessun profilo esegue git/shell/installazioni: sono binari di linguaggio o bin locali.
        assertSame(false, str_contains($p->program, 'git'));
        assertSame(false, str_contains($p->program, 'npm'));
    }
});

test('registro: enabled è l\'intersezione coi curati; un id ignoto non abilita nulla', function () {
    $r = new VerificationProfileRegistry();
    $enabled = $r->enabled(['php-lint', 'inventato-x', 'shell']);
    assertSame(1, count($enabled));
    assertSame('php-lint', $enabled[0]->id);
    // null = tutti i curati.
    assertSame(count($r->ids()), count($r->enabled(null)));
});

test('limiti di verifica: valori non positivi sono errori di programmazione', function () use ($throws) {
    assertSame(true, $throws(static fn () => new VerificationLimits(0.0, 1000, 1)));
    assertSame(true, $throws(static fn () => new VerificationLimits(1.0, 0, 1)));
    assertSame(true, $throws(static fn () => new VerificationLimits(1.0, 1000, 0)));
    $ok = VerificationLimits::defaults();
    assertSame(true, $ok->maxSeconds > 0.0 && $ok->maxRunsPerTurn > 0);
});
