<?php

declare(strict_types=1);

use App\Core\Code\CodePatchLimits;
use App\Core\Code\CodePatchProposal;
use App\Core\Code\CodePatchValidation;
use App\Core\Code\CodePatchValidator;
use App\Core\Code\CodeWorkspace;
use App\Core\Code\SensitivePathPolicy;

// Fase 4 / F4.2 — validazione di SANDBOX (read-only) di una proposta: confine, sensibili,
// symlink, esistenza, testo, corrispondenza esatta e hash calcolati dal server.

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

$mkroot = static function () use ($tmpBase): string {
    $root = $tmpBase() . '/aimanager_patchval_' . uniqid('', true);
    mkdir($root, 0777, true);
    file_put_contents($root . '/file.php', "<?php\n\$x = 1;\necho \$x;\n");
    file_put_contents($root . '/.env', "SECRET=1\n");
    file_put_contents($root . '/bin.dat', "abc\0def");
    mkdir($root . '/sub');
    symlink($root . '/file.php', $root . '/lnk.php');
    return $root;
};

$ws = static function (string $root): CodeWorkspace {
    return new CodeWorkspace(1, $root, '', 'active', new SensitivePathPolicy());
};

$validator = new CodePatchValidator(CodePatchLimits::defaults());

$proposal = static function (array $changes): CodePatchProposal {
    return CodePatchProposal::fromActionData(['changes' => $changes], CodePatchLimits::defaults());
};

test('validator: update valido calcola hash e produce diff', function () use ($mkroot, $rmrf, $ws, $validator, $proposal) {
    $root = $mkroot();
    try {
        $p = $proposal([['op' => 'update', 'path' => 'file.php', 'edits' => [['old' => '$x = 1;', 'new' => '$x = 2;']]]]);
        $v = $validator->validate($ws($root), $p);
        assertSame(true, $v->ok);
        assertSame(1, count($v->patch->operations));
        $op = $v->patch->operations[0];
        assertSame(hash('sha256', "<?php\n\$x = 1;\necho \$x;\n"), $op->baseSha256);
        assertSame("<?php\n\$x = 2;\necho \$x;\n", $op->newContent);
        assertSame(hash('sha256', "<?php\n\$x = 2;\necho \$x;\n"), $op->resultSha256);
        assertSame(true, str_contains($v->entries[0]['diff'], '+$x = 2;'));
    } finally {
        $rmrf($root);
    }
});

test('validator: create valido su percorso libero', function () use ($mkroot, $rmrf, $ws, $validator, $proposal) {
    $root = $mkroot();
    try {
        $p = $proposal([['op' => 'create', 'path' => 'sub/nuovo.txt', 'content' => "ciao\n"]]);
        $v = $validator->validate($ws($root), $p);
        assertSame(true, $v->ok);
        $op = $v->patch->operations[0];
        assertSame(null, $op->baseSha256);
        assertSame(hash('sha256', "ciao\n"), $op->resultSha256);
        // Non ha scritto nulla: il file non esiste.
        assertSame(false, file_exists($root . '/sub/nuovo.txt'));
    } finally {
        $rmrf($root);
    }
});

test('validator: old non presente → no_match', function () use ($mkroot, $rmrf, $ws, $validator, $proposal) {
    $root = $mkroot();
    try {
        $v = $validator->validate($ws($root), $proposal([
            ['op' => 'update', 'path' => 'file.php', 'edits' => [['old' => 'INESISTENTE', 'new' => 'x']]],
        ]));
        assertSame(false, $v->ok);
        assertSame(CodePatchValidation::NO_MATCH, $v->reason);
    } finally {
        $rmrf($root);
    }
});

test('validator: old ambiguo (più occorrenze) → ambiguous', function () use ($mkroot, $rmrf, $ws, $validator, $proposal) {
    $root = $mkroot();
    try {
        // '$x' compare più volte in file.php
        $v = $validator->validate($ws($root), $proposal([
            ['op' => 'update', 'path' => 'file.php', 'edits' => [['old' => '$x', 'new' => '$y']]],
        ]));
        assertSame(false, $v->ok);
        assertSame(CodePatchValidation::AMBIGUOUS, $v->reason);
    } finally {
        $rmrf($root);
    }
});

test('validator: update su file inesistente → not_found', function () use ($mkroot, $rmrf, $ws, $validator, $proposal) {
    $root = $mkroot();
    try {
        $v = $validator->validate($ws($root), $proposal([
            ['op' => 'update', 'path' => 'manca.php', 'edits' => [['old' => 'a', 'new' => 'b']]],
        ]));
        assertSame(false, $v->ok);
        assertSame(CodePatchValidation::NOT_FOUND, $v->reason);
    } finally {
        $rmrf($root);
    }
});

test('validator: file sensibile (.env) negato', function () use ($mkroot, $rmrf, $ws, $validator, $proposal) {
    $root = $mkroot();
    try {
        $v = $validator->validate($ws($root), $proposal([
            ['op' => 'update', 'path' => '.env', 'edits' => [['old' => 'SECRET=1', 'new' => 'SECRET=2']]],
        ]));
        assertSame(false, $v->ok);
        assertSame(CodePatchValidation::SENSITIVE, $v->reason);
    } finally {
        $rmrf($root);
    }
});

test('validator: create che sovrascriverebbe .env negato come sensibile', function () use ($mkroot, $rmrf, $ws, $validator, $proposal) {
    $root = $mkroot();
    try {
        $v = $validator->validate($ws($root), $proposal([
            ['op' => 'create', 'path' => '.env.local', 'content' => 'x'],
        ]));
        assertSame(false, $v->ok);
        assertSame(CodePatchValidation::SENSITIVE, $v->reason);
    } finally {
        $rmrf($root);
    }
});

test('validator: target symlink negato (bloccato dal confine PathGuard)', function () use ($mkroot, $rmrf, $ws, $validator, $proposal) {
    $root = $mkroot();
    try {
        // Un symlink preesistente è rifiutato già da PathGuard (nessun componente symlink nel
        // percorso): l'esito è BLOCKED. Il controllo is_link nel validator/writer resta come
        // difesa contro un symlink comparso DOPO la risoluzione (TOCTOU).
        $v = $validator->validate($ws($root), $proposal([
            ['op' => 'update', 'path' => 'lnk.php', 'edits' => [['old' => 'a', 'new' => 'b']]],
        ]));
        assertSame(false, $v->ok);
        assertSame(CodePatchValidation::BLOCKED, $v->reason);
    } finally {
        $rmrf($root);
    }
});

test('validator: create su percorso occupato → exists', function () use ($mkroot, $rmrf, $ws, $validator, $proposal) {
    $root = $mkroot();
    try {
        $v = $validator->validate($ws($root), $proposal([
            ['op' => 'create', 'path' => 'file.php', 'content' => 'x'],
        ]));
        assertSame(false, $v->ok);
        assertSame(CodePatchValidation::EXISTS, $v->reason);
    } finally {
        $rmrf($root);
    }
});

test('validator: update su file binario → binary', function () use ($mkroot, $rmrf, $ws, $validator, $proposal) {
    $root = $mkroot();
    try {
        $v = $validator->validate($ws($root), $proposal([
            ['op' => 'update', 'path' => 'bin.dat', 'edits' => [['old' => 'abc', 'new' => 'xyz']]],
        ]));
        assertSame(false, $v->ok);
        assertSame(CodePatchValidation::BINARY, $v->reason);
    } finally {
        $rmrf($root);
    }
});

test('validator: workspace revocato → blocked', function () use ($mkroot, $rmrf, $validator, $proposal) {
    $root = $mkroot();
    try {
        $revoked = new CodeWorkspace(1, $root, '', 'revoked', new SensitivePathPolicy());
        $v = $validator->validate($revoked, $proposal([
            ['op' => 'update', 'path' => 'file.php', 'edits' => [['old' => '$x = 1;', 'new' => '$x = 2;']]],
        ]));
        assertSame(false, $v->ok);
        assertSame(CodePatchValidation::BLOCKED, $v->reason);
    } finally {
        $rmrf($root);
    }
});

test('validator: digest stabile e sensibile al contenuto', function () use ($mkroot, $rmrf, $ws, $validator, $proposal) {
    $root = $mkroot();
    try {
        $a = $validator->validate($ws($root), $proposal([['op' => 'update', 'path' => 'file.php', 'edits' => [['old' => '$x = 1;', 'new' => '$x = 2;']]]]));
        $b = $validator->validate($ws($root), $proposal([['op' => 'update', 'path' => 'file.php', 'edits' => [['old' => '$x = 1;', 'new' => '$x = 2;']]]]));
        $c = $validator->validate($ws($root), $proposal([['op' => 'update', 'path' => 'file.php', 'edits' => [['old' => '$x = 1;', 'new' => '$x = 3;']]]]));
        assertSame($a->patch->digest(), $b->patch->digest());
        assertSame(true, $a->patch->digest() !== $c->patch->digest());
    } finally {
        $rmrf($root);
    }
});

test('validator: totale oltre il tetto complessivo → too_large', function () use ($mkroot, $rmrf, $ws, $proposal) {
    $root = $mkroot();
    try {
        $tiny = new CodePatchLimits(maxOperations: 20, maxEditsPerOp: 100, maxFileBytes: 100, maxTotalBytes: 100, ttlSeconds: 60);
        $validator = new CodePatchValidator($tiny);
        $big = str_repeat('x', 80);
        $v = $validator->validate($ws($root), CodePatchProposal::fromActionData(['changes' => [
            ['op' => 'create', 'path' => 'a.txt', 'content' => $big],
            ['op' => 'create', 'path' => 'b.txt', 'content' => $big],
        ]], $tiny));
        assertSame(false, $v->ok);
        assertSame(CodePatchValidation::TOO_LARGE, $v->reason);
    } finally {
        $rmrf($root);
    }
});
