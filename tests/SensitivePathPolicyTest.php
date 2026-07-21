<?php

declare(strict_types=1);

use App\Core\Code\SensitivePathPolicy;

// F0.2 — la policy condivisa dei file sensibili (regola trasversale 2).

test('sensitive: .env e varianti sono sensibili', function () {
    $p = new SensitivePathPolicy();
    assertSame(true, $p->isSensitive('.env'));
    assertSame(true, $p->isSensitive('config/.env.local'));
    assertSame(true, $p->isSensitive('a/b/.env.production'));
});

test('sensitive: chiavi, certificati, database, credenziali', function () {
    $p = new SensitivePathPolicy();
    assertSame(true, $p->isSensitive('keys/server.pem'));
    assertSame(true, $p->isSensitive('id_rsa'));
    assertSame(true, $p->isSensitive('certs/site.crt'));
    assertSame(true, $p->isSensitive('storage/app.sqlite'));
    assertSame(true, $p->isSensitive('data/app.db'));
    assertSame(true, $p->isSensitive('.npmrc'));
});

test('sensitive: la directory .git ovunque nel percorso (case-insensitive)', function () {
    $p = new SensitivePathPolicy();
    assertSame(true, $p->isSensitive('.git/config'));
    assertSame(true, $p->isSensitive('sub/.git/HEAD'));
    assertSame(true, $p->isSensitive('.GIT/config'));
    assertSame(true, $p->isSensitive('sub/.Git/HEAD'));
});

test('sensitive: i file di codice normali non sono sensibili', function () {
    $p = new SensitivePathPolicy();
    assertSame(false, $p->isSensitive('src/App.php'));
    assertSame(false, $p->isSensitive('README.md'));
    assertSame(false, $p->isSensitive('app/Core/Code/PathGuard.php'));
});

test('sensitive: il match e\' case-insensitive', function () {
    $p = new SensitivePathPolicy();
    assertSame(true, $p->isSensitive('SECRET.PEM'));
    assertSame(true, $p->isSensitive('Config/.ENV'));
});

test('sensitive: i pattern extra vengono applicati', function () {
    $p = new SensitivePathPolicy(['*.foo']);
    assertSame(true, $p->isSensitive('bar.foo'));
    assertSame(false, $p->isSensitive('bar.bar'));
});
