<?php

declare(strict_types=1);

use App\Core\Code\CommandArgvValidator;
use App\Core\Code\CommandPlan;
use App\Core\Code\CommandRegistry;

// Fase 6 — policy argv CHIUSA e strict-deny (pura, nessun IO). Copre i casi ALLOW e i casi BLOCKED,
// inclusi i test offensivi sulla FORMA: metacaratteri restano operandi, opzioni ricorsive/exec
// negate, path fuori root/assoluti negati, interpreti/package manager/git non nel registro.

$reg = new CommandRegistry();
$val = new CommandArgvValidator();

$plan = static function (string $program, array $args) use ($reg, $val): ?CommandPlan {
    $spec = $reg->find($program);
    if ($spec === null) {
        return null;
    }
    try {
        return $val->validate($spec, $args);
    } catch (\InvalidArgumentException) {
        return null;
    }
};

$denied = static function (string $program, array $args) use ($reg, $val): bool {
    $spec = $reg->find($program);
    if ($spec === null) {
        return true; // non registrato = negato
    }
    try {
        $val->validate($spec, $args);
        return false;
    } catch (\InvalidArgumentException) {
        return true;
    }
};

test('registro: solo utility di lettura, niente interpreti/pm/git/shell', function () use ($reg) {
    assertSame(['cat', 'diff', 'file', 'grep', 'head', 'stat', 'tail', 'wc'], $reg->programs());
    foreach (['php', 'node', 'python', 'python3', 'npm', 'composer', 'pip', 'git', 'bash', 'sh', 'go', 'cargo', 'curl', 'wget', 'rm', 'make'] as $forbidden) {
        assertSame(null, $reg->find($forbidden), $forbidden);
    }
});

test('allow: cat/grep/head/diff/stat con argv conforme', function () use ($plan) {
    assertSame(true, $plan('cat', ['app/Foo.php']) instanceof CommandPlan);
    $g = $plan('grep', ['-n', '-i', 'needle', 'src/a.php', 'src/b.php']);
    assertSame(true, $g instanceof CommandPlan);
    assertSame('needle', $g->pattern);
    assertSame(['app/a.php'], $plan('head', ['-n', '20', 'app/a.php'])?->relPaths);
    assertSame(true, $plan('diff', ['-u', 'a.txt', 'b.txt']) instanceof CommandPlan);
    assertSame(true, $plan('stat', ['x.php']) instanceof CommandPlan);
});

test('exec argv inserisce `--` server-side prima di pattern/path', function () use ($plan) {
    $g = $plan('grep', ['-n', 'foo', 'a.php']);
    // [ ...flags, '--', pattern, ...paths ]  (i path relativi sono qui segnaposto)
    assertSame(['-n', '--', 'foo', '/abs/a.php'], $g->execTail(['/abs/a.php']));
});

test('BLOCKED: opzioni ricorsive / exec / follow', function () use ($denied) {
    assertSame(true, $denied('grep', ['-r', 'x', '.']));
    assertSame(true, $denied('grep', ['-R', 'x', '.']));
    assertSame(true, $denied('grep', ['--include=*.php', 'x', 'a.php']));
    assertSame(true, $denied('grep', ['-f', 'patterns.txt', 'a.php']));
    assertSame(true, $denied('tail', ['-f', 'log.txt']));
    assertSame(true, $denied('tail', ['-F', 'log.txt']));
    assertSame(true, $denied('diff', ['-r', 'a', 'b']));
});

test('BLOCKED: opzione combinata / stdin / `--` dal modello / opzione sconosciuta', function () use ($denied) {
    assertSame(true, $denied('grep', ['-ni', 'x', 'a.php'])); // combinata non whitelisted
    assertSame(true, $denied('cat', ['-'])); // stdin
    assertSame(true, $denied('cat', ['--', 'a.php'])); // `--` lo inserisce il server
    assertSame(true, $denied('cat', ['--evil', 'a.php']));
    assertSame(true, $denied('wc', ['-x', 'a.php']));
});

test('BLOCKED: path traversal / assoluto / backslash', function () use ($denied) {
    assertSame(true, $denied('cat', ['../../etc/passwd']));
    assertSame(true, $denied('cat', ['/etc/passwd']));
    assertSame(true, $denied('cat', ['a\\b.php']));
    assertSame(true, $denied('grep', ['x', '../secret']));
});

test('BLOCKED: metacaratteri shell NON sono interpretati — restano operandi (e falliscono come path)', function () use ($plan, $denied) {
    // Un metacarattere in un operando path resta letterale: è un percorso relativo «strano», non una
    // shell. Passa la forma (nome file legale) ma NON abilita alcuna espansione: sarà il bind a valle
    // a rifiutarlo se non è un file regolare. Qui verifichiamo che la forma non esploda in opzioni.
    $p = $plan('cat', ['a;rm -rf b.php']);
    assertSame(true, $p instanceof CommandPlan);
    assertSame(['a;rm -rf b.php'], $p->relPaths);
    // Un pattern con metacaratteri è testo letterale, non una shell.
    $g = $plan('grep', ['$(whoami)', 'a.php']);
    assertSame('$(whoami)', $g->pattern);
});

test('BLOCKED: NUL, vuoto, troppi path, path/pattern troppo lunghi', function () use ($denied) {
    assertSame(true, $denied('cat', ["a\0b.php"]));
    assertSame(true, $denied('cat', ['']));
    assertSame(true, $denied('diff', ['a.txt'])); // servono 2
    assertSame(true, $denied('diff', ['a', 'b', 'c'])); // max 2
    assertSame(true, $denied('cat', [str_repeat('a', 5000)]));
    assertSame(true, $denied('grep', [str_repeat('x', 300), 'a.php'])); // pattern > 200
});

test('BLOCKED: grep senza pattern; numerico fuori range o non numerico', function () use ($denied) {
    assertSame(true, $denied('grep', []));
    assertSame(true, $denied('grep', ['needle'])); // manca il file
    assertSame(true, $denied('head', ['-n', '0', 'a']));
    assertSame(true, $denied('head', ['-n', '99999999', 'a']));
    assertSame(true, $denied('head', ['-n', 'abc', 'a']));
    assertSame(true, $denied('head', ['-n'])); // manca il valore
});

test('policy version è stabile', function () use ($reg) {
    assertSame(1, $reg->policyVersion());
});
