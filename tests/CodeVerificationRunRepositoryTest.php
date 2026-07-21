<?php

declare(strict_types=1);

use App\Core\Code\CodeVerificationRunRecord;
use App\Core\Code\CodeVerificationRunRepository;
use App\Core\Code\VerificationResult;
use App\Core\Database;
use App\Services\MigrationRunner;

// Fase 5 — la persistenza dell'audit verifiche: soli metadati, scope workspace/sessione imposto,
// profilo a vocabolario chiuso. SQLite temporaneo, mai il DB reale.

$make = static function (): array {
    $path = sys_get_temp_dir() . '/aimanager_vrepo_' . uniqid('', true) . '.sqlite';
    $db = new Database($path);
    $root = dirname(__DIR__);
    (new MigrationRunner($db, $root . '/database/migrations', $root . '/database/seeds'))->run();
    $now = date('c');
    $db->execute('INSERT INTO code_workspaces (root_path, name, status, authorized_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', ['/tmp/wrepo', '', 'active', $now, $now, $now]);
    $wid = $db->lastInsertId();
    $db->execute('INSERT INTO code_sessions (workspace_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$wid, 't', 'active', $now, $now]);
    $sid = $db->lastInsertId();
    $cleanup = static function () use ($path): void {
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (is_file($file)) { @unlink($file); }
        }
    };
    return [$db, $wid, $sid, $cleanup];
};

$throws = static function (callable $fn): bool {
    try { $fn(); return false; } catch (\Throwable $e) { return true; }
};

$record = static fn (string $profile, string $lang, string $kind, string $outcome, ?string $path = null): CodeVerificationRunRecord
    => new CodeVerificationRunRecord($profile, $lang, $kind, $path, $outcome, $outcome === VerificationResult::PASSED ? 0 : null, 12, 34, false);

test('repo: registra una verifica eseguita e la rilegge', function () use ($make, $record) {
    [$db, $wid, $sid, $cleanup] = $make();
    try {
        (new CodeVerificationRunRepository($db))->record($wid, $sid, null, $record('php-lint', 'php', 'lint', 'passed', 'app/Foo.php'));
        $rows = (new CodeVerificationRunRepository($db))->listForSession($wid, $sid);
        assertSame(1, count($rows));
        assertSame('php-lint', (string) $rows[0]['profile_id']);
        assertSame('passed', (string) $rows[0]['outcome']);
        assertSame('app/Foo.php', (string) $rows[0]['rel_path']);
        assertSame(0, (int) $rows[0]['exit_code']);
    } finally {
        $cleanup();
    }
});

test('repo: un profile_id fuori dal registro non viene scritto', function () use ($make, $throws) {
    [$db, $wid, $sid, $cleanup] = $make();
    try {
        $bogus = new CodeVerificationRunRecord('profilo-inventato', 'php', 'lint', null, 'passed', 0, 1, 1, false);
        assertSame(true, $throws(static fn () => (new CodeVerificationRunRepository($db))->record($wid, $sid, null, $bogus)));
        assertSame(0, count((new CodeVerificationRunRepository($db))->listForSession($wid, $sid)));
    } finally {
        $cleanup();
    }
});

test('repo: sessione non appartenente al workspace → nessuna riga', function () use ($make, $record, $throws) {
    [$db, $wid, $sid, $cleanup] = $make();
    try {
        $repo = new CodeVerificationRunRepository($db);
        // Sessione inesistente per quel workspace.
        assertSame(true, $throws(static fn () => $repo->record($wid, $sid + 999, null, $record('php-lint', 'php', 'lint', 'failed'))));
        assertSame(0, count($repo->listForSession($wid, $sid)));
    } finally {
        $cleanup();
    }
});

test('repo: gli esiti di rifiuto (denied/unavailable) sono tracciabili', function () use ($make, $record) {
    [$db, $wid, $sid, $cleanup] = $make();
    try {
        $repo = new CodeVerificationRunRepository($db);
        $repo->record($wid, $sid, null, $record('php-lint', 'php', 'lint', CodeVerificationRunRecord::DENIED, 'app/Foo.php'));
        $repo->record($wid, $sid, null, $record('py-test', 'python', 'test', CodeVerificationRunRecord::UNAVAILABLE));
        $rows = $repo->listForSession($wid, $sid);
        assertSame(2, count($rows));
        $outcomes = array_map(static fn (array $r): string => (string) $r['outcome'], $rows);
        assertSame(true, in_array('denied', $outcomes, true));
        assertSame(true, in_array('unavailable', $outcomes, true));
    } finally {
        $cleanup();
    }
});

test('repo: link all\'assistant e forHistory alimentano il rendering dopo refresh', function () use ($make, $record) {
    [$db, $wid, $sid, $cleanup] = $make();
    try {
        // Serve un turno assistant reale per la FK.
        $db->execute('INSERT INTO code_conversations (code_session_id, role, content, created_at) VALUES (?, ?, ?, ?)', [$sid, 'assistant', 'ok', date('c')]);
        $assistantId = $db->lastInsertId();

        $repo = new CodeVerificationRunRepository($db);
        $id1 = $repo->record($wid, $sid, null, $record('php-lint', 'php', 'lint', 'passed', 'app/Foo.php'));
        $id2 = $repo->record($wid, $sid, null, $record('php-lint', 'php', 'lint', 'failed', 'app/Bar.php'));
        // Prima del collegamento, la cronologia (solo righe collegate) è vuota.
        assertSame(0, count($repo->forHistory($sid, $wid)));

        $repo->linkAssistant([$id1, $id2], $assistantId, $wid, $sid);
        $history = $repo->forHistory($sid, $wid);
        assertSame(1, count($history));
        assertSame(2, count($history[$assistantId]));
        // La label deriva dai metadati, non dal testo del modello.
        assertSame('superata', $history[$assistantId][0]['label']);
        assertSame('php-lint', $history[$assistantId][0]['profile']);
    } finally {
        $cleanup();
    }
});

test('repo: linkAssistant non ruba le verifiche di un altro turno (solo NULL nello scope)', function () use ($make, $record) {
    [$db, $wid, $sid, $cleanup] = $make();
    try {
        $db->execute('INSERT INTO code_conversations (code_session_id, role, content, created_at) VALUES (?, ?, ?, ?)', [$sid, 'assistant', 'a', date('c')]);
        $a1 = $db->lastInsertId();
        $db->execute('INSERT INTO code_conversations (code_session_id, role, content, created_at) VALUES (?, ?, ?, ?)', [$sid, 'assistant', 'b', date('c')]);
        $a2 = $db->lastInsertId();

        $repo = new CodeVerificationRunRepository($db);
        $id1 = $repo->record($wid, $sid, null, $record('php-lint', 'php', 'lint', 'passed', 'x.php'));
        $repo->linkAssistant([$id1], $a1, $wid, $sid);
        // Ora è già collegato ad a1: un secondo link ad a2 NON deve spostarlo.
        $repo->linkAssistant([$id1], $a2, $wid, $sid);
        $history = $repo->forHistory($sid, $wid);
        assertSame(true, isset($history[$a1]));
        assertSame(false, isset($history[$a2]));
    } finally {
        $cleanup();
    }
});

test('card: la label del record deriva solo dai metadati', function () {
    assertSame('superata', CodeVerificationRunRecord::label('passed', 0));
    assertSame('fallita (exit 2)', CodeVerificationRunRecord::label('failed', 2));
    assertSame('interrotta (timeout)', CodeVerificationRunRecord::label('timed_out', null));
    assertSame('non disponibile', CodeVerificationRunRecord::label('unavailable', null));
    $card = (new CodeVerificationRunRecord('php-lint', 'php', 'lint', 'app/Foo.php', 'passed', 0, 5, 10, false))->toCard();
    assertSame('php-lint', $card['profile']);
    assertSame('superata', $card['label']);
    assertSame('app/Foo.php', $card['path']);
});

test('record: un esito o linguaggio fuori vocabolario è un errore', function () use ($throws) {
    assertSame(true, $throws(static fn () => new CodeVerificationRunRecord('php-lint', 'php', 'lint', null, 'boom', null, 0, 0, false)));
    assertSame(true, $throws(static fn () => new CodeVerificationRunRecord('php-lint', 'cobol', 'lint', null, 'passed', 0, 0, 0, false)));
    assertSame(true, $throws(static fn () => new CodeVerificationRunRecord('php-lint', 'php', 'lint', '../x', 'passed', 0, 0, 0, false)));
});
