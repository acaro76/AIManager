<?php

declare(strict_types=1);

use App\Core\Code\CodeWorkingMemory;
use App\Core\Code\CodeWorkingMemoryRepository;
use App\Core\Code\CodeWorkspaceException;
use App\Core\Database;
use App\Services\MigrationRunner;

// Fase 9 / Step 2 — persistenza scoped della memoria di lavoro Code su code_working_memories:
// round-trip, upsert senza duplicati, created_at conservato/updated_at aggiornato, scope errato,
// workspace revocato, payload incompatibile in lettura, FK attive. SQLite temporaneo, mai il reale.

$make = static function (): array {
    $path = sys_get_temp_dir() . '/aimanager_wmem_' . uniqid('', true) . '.sqlite';
    $db = new Database($path);
    $root = dirname(__DIR__);
    (new MigrationRunner($db, $root . '/database/migrations', $root . '/database/seeds'))->run();
    $now = date('c');
    $db->execute('INSERT INTO code_workspaces (root_path, name, status, authorized_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', ['/tmp/wmem', '', 'active', $now, $now, $now]);
    $wid = $db->lastInsertId();
    $db->execute('INSERT INTO code_sessions (workspace_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$wid, 't', 'active', $now, $now]);
    $sid = $db->lastInsertId();
    $db->execute('INSERT INTO code_conversations (code_session_id, role, content, provider, created_at) VALUES (?, ?, ?, ?, ?)', [$sid, 'assistant', 'x', 'code', $now]);
    $aid = $db->lastInsertId();
    $cleanup = static function () use ($path): void {
        foreach ([$path, $path . '-wal', $path . '-shm'] as $f) { if (is_file($f)) { @unlink($f); } }
    };
    return [$db, $wid, $sid, $aid, $cleanup];
};

$throws = static function (callable $fn): bool {
    try { $fn(); return false; } catch (\Throwable) { return true; }
};

$mem = static function (array $payload = []): CodeWorkingMemory {
    return CodeWorkingMemory::fromArray($payload);
};

test('memoria: insert e lettura round-trip', function () use ($make, $mem) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $repo = new CodeWorkingMemoryRepository($db);
        $m = $mem(['objective' => 'riprendere il login', 'state' => 'blocked', 'todos' => ['a', 'b']]);
        $repo->save($m, $wid, $sid, $aid);
        $read = $repo->findForSession($wid, $sid);
        assertSame(true, is_array($read));
        assertSame(true, $read['memory'] instanceof CodeWorkingMemory);
        assertSame($m->toJson(), $read['memory']->toJson());
        assertSame($aid, $read['last_conversation_id']);
    } finally { $cleanup(); }
});

test('memoria: lettura assente ritorna null', function () use ($make) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        assertSame(null, (new CodeWorkingMemoryRepository($db))->findForSession($wid, $sid));
    } finally { $cleanup(); }
});

test('memoria: upsert AGGIORNA la stessa riga (nessun duplicato)', function () use ($make, $mem) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $repo = new CodeWorkingMemoryRepository($db);
        $repo->save($mem(['objective' => 'prima']), $wid, $sid, $aid);
        $repo->save($mem(['objective' => 'seconda']), $wid, $sid, $aid);
        $count = (int) $db->fetch('SELECT COUNT(*) AS c FROM code_working_memories WHERE code_session_id = ?', [$sid])['c'];
        assertSame(1, $count);
        assertSame('seconda', $repo->findForSession($wid, $sid)['memory']->objective);
    } finally { $cleanup(); }
});

test('memoria: upsert con conversazione successiva ritorna il nuovo cutoff, senza duplicati', function () use ($make, $mem) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $repo = new CodeWorkingMemoryRepository($db);
        $repo->save($mem(['objective' => 'v1']), $wid, $sid, $aid);
        assertSame($aid, $repo->findForSession($wid, $sid)['last_conversation_id']);

        // Un turno successivo nella stessa sessione: l'upsert deve aggiornare il cutoff sulla stessa riga.
        $db->execute('INSERT INTO code_conversations (code_session_id, role, content, provider, created_at) VALUES (?, ?, ?, ?, ?)', [$sid, 'assistant', 'z', 'code', date('c')]);
        $aid2 = $db->lastInsertId();
        assertSame(true, $aid2 > $aid);
        $repo->save($mem(['objective' => 'v2']), $wid, $sid, $aid2);

        $read = $repo->findForSession($wid, $sid);
        assertSame($aid2, $read['last_conversation_id']);
        assertSame('v2', $read['memory']->objective);
        assertSame(1, (int) $db->fetch('SELECT COUNT(*) AS c FROM code_working_memories WHERE code_session_id = ?', [$sid])['c']);
    } finally { $cleanup(); }
});

test('memoria: created_at conservato e updated_at aggiornato sull\'upsert', function () use ($make, $mem) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $repo = new CodeWorkingMemoryRepository($db);
        $repo->save($mem(['objective' => 'v1']), $wid, $sid, $aid);
        // Retrodato entrambi i timestamp per rendere osservabile l'effetto del secondo salvataggio.
        $old = '2000-01-01T00:00:00+00:00';
        $db->execute('UPDATE code_working_memories SET created_at = ?, updated_at = ? WHERE code_session_id = ?', [$old, $old, $sid]);
        $repo->save($mem(['objective' => 'v2']), $wid, $sid, $aid);
        $row = $db->fetch('SELECT created_at, updated_at, payload_json FROM code_working_memories WHERE code_session_id = ?', [$sid]);
        assertSame($old, (string) $row['created_at']);              // conservato
        assertSame(true, (string) $row['updated_at'] !== $old);     // aggiornato
        assertSame(true, str_contains((string) $row['payload_json'], '"objective":"v2"'));
    } finally { $cleanup(); }
});

test('memoria: scope errato rifiutato (workspace, sessione, conversazione)', function () use ($make, $mem, $throws) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $repo = new CodeWorkingMemoryRepository($db);
        $m = $mem(['objective' => 'x']);

        // workspace che non possiede la sessione
        assertSame(true, $throws(static fn () => $repo->save($m, $wid + 999, $sid, $aid)));
        // sessione inesistente
        assertSame(true, $throws(static fn () => $repo->save($m, $wid, $sid + 999, $aid)));
        // conversazione di un'ALTRA sessione dello stesso workspace
        $now = date('c');
        $db->execute('INSERT INTO code_sessions (workspace_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$wid, 't2', 'active', $now, $now]);
        $sid2 = $db->lastInsertId();
        $db->execute('INSERT INTO code_conversations (code_session_id, role, content, provider, created_at) VALUES (?, ?, ?, ?, ?)', [$sid2, 'assistant', 'y', 'code', $now]);
        $aid2 = $db->lastInsertId();
        assertSame(true, $throws(static fn () => $repo->save($m, $wid, $sid, $aid2)));
        // nessuna riga scritta da nessun tentativo fallito
        assertSame(0, (int) $db->fetch('SELECT COUNT(*) AS c FROM code_working_memories')['c']);
    } finally { $cleanup(); }
});

test('memoria: fallita e riuscita: solo lo scope corretto viene rifiutato/accettato', function () use ($make, $mem, $throws) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $repo = new CodeWorkingMemoryRepository($db);
        assertSame(true, $throws(static fn () => $repo->save($mem(), $wid + 999, $sid, $aid)));
        $repo->save($mem(['objective' => 'ok']), $wid, $sid, $aid); // scope corretto: passa
        assertSame('ok', $repo->findForSession($wid, $sid)['memory']->objective);
    } finally { $cleanup(); }
});

test('memoria: workspace revocato non scrivibile', function () use ($make, $mem, $throws) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $repo = new CodeWorkingMemoryRepository($db);
        $db->execute('UPDATE code_workspaces SET status = ? WHERE id = ?', ['revoked', $wid]);
        $threw = false;
        try { $repo->save($mem(['objective' => 'x']), $wid, $sid, $aid); }
        catch (CodeWorkspaceException) { $threw = true; }
        assertSame(true, $threw);
        assertSame(0, (int) $db->fetch('SELECT COUNT(*) AS c FROM code_working_memories')['c']);
    } finally { $cleanup(); }
});

test('memoria: lettura cross-workspace negata (ritorna null)', function () use ($make, $mem) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $repo = new CodeWorkingMemoryRepository($db);
        $repo->save($mem(['objective' => 'segreto']), $wid, $sid, $aid);
        $now = date('c');
        $db->execute('INSERT INTO code_workspaces (root_path, name, status, authorized_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', ['/tmp/wmem-other', '', 'active', $now, $now, $now]);
        $other = $db->lastInsertId();
        assertSame(null, $repo->findForSession($other, $sid));
    } finally { $cleanup(); }
});

test('memoria: payload persistito senza versione o incompatibile rifiutato in lettura', function () use ($make, $throws) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $repo = new CodeWorkingMemoryRepository($db);
        $now = date('c');
        // Riga piantata direttamente con payload SENZA schema_version.
        $db->execute(
            'INSERT INTO code_working_memories (workspace_id, code_session_id, last_conversation_id, payload_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$wid, $sid, $aid, '{"objective":"x"}', $now, $now]
        );
        assertSame(true, $throws(static fn () => $repo->findForSession($wid, $sid)));

        // Payload con versione diversa.
        $db->execute('UPDATE code_working_memories SET payload_json = ? WHERE code_session_id = ?', ['{"schema_version":2}', $sid]);
        assertSame(true, $throws(static fn () => $repo->findForSession($wid, $sid)));
    } finally { $cleanup(); }
});

test('memoria: FK attive — conversazione inesistente rifiutata dal DB', function () use ($make, $throws) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $now = date('c');
        $bad = $throws(static function () use ($db, $wid, $sid, $now): void {
            $db->execute(
                'INSERT INTO code_working_memories (workspace_id, code_session_id, last_conversation_id, payload_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$wid, $sid, 999999, '{"schema_version":1}', $now, $now]
            );
        });
        assertSame(true, $bad);
    } finally { $cleanup(); }
});

// Aggiunge una conversazione (id crescente) alla sessione e ne ritorna l'id: serve per i cutoff.
$conv = static function (Database $db, int $sid): int {
    $db->execute('INSERT INTO code_conversations (code_session_id, role, content, provider, created_at) VALUES (?, ?, ?, ?, ?)', [$sid, 'assistant', 'c', 'code', date('c')]);
    return $db->lastInsertId();
};

test('memoria: cutoff MONOTÒNO — riepilogo fuori ordine non sovrascrive; stesso cutoff aggiorna; inferiore è no-op', function () use ($make, $mem, $conv) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $repo = new CodeWorkingMemoryRepository($db);
        $lo = $aid;              // cutoff basso
        $hi = $conv($db, $sid);  // cutoff alto (id maggiore)

        // Riepilogo NUOVO (cutoff alto) salvato.
        $repo->save($mem(['objective' => 'NUOVO']), $wid, $sid, $hi);
        // Retrodato updated_at per rendere osservabile un eventuale (indesiderato) aggiornamento.
        $old = '2000-01-01T00:00:00+00:00';
        $db->execute('UPDATE code_working_memories SET updated_at = ? WHERE code_session_id = ?', [$old, $sid]);

        // Riepilogo VECCHIO (cutoff basso): NO-OP. Payload, cutoff e updated_at invariati.
        $repo->save($mem(['objective' => 'VECCHIO']), $wid, $sid, $lo);
        $row = $db->fetch('SELECT payload_json, last_conversation_id, updated_at FROM code_working_memories WHERE code_session_id = ?', [$sid]);
        assertSame(true, str_contains((string) $row['payload_json'], '"objective":"NUOVO"'));
        assertSame($hi, (int) $row['last_conversation_id']);
        assertSame($old, (string) $row['updated_at']); // no-op non tocca updated_at

        // Stesso cutoff: può AGGIORNARE payload e updated_at.
        $repo->save($mem(['objective' => 'STESSO']), $wid, $sid, $hi);
        $row2 = $db->fetch('SELECT payload_json, updated_at FROM code_working_memories WHERE code_session_id = ?', [$sid]);
        assertSame(true, str_contains((string) $row2['payload_json'], '"objective":"STESSO"'));
        assertSame(true, (string) $row2['updated_at'] !== $old);

        // Nessun duplicato per tutta la sequenza.
        assertSame(1, (int) $db->fetch('SELECT COUNT(*) AS c FROM code_working_memories WHERE code_session_id = ?', [$sid])['c']);
    } finally { $cleanup(); }
});

test('memoria: no-op stale accettato SOLO con scope pienamente attivo, altrimenti errore', function () use ($make, $mem, $throws, $conv) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $repo = new CodeWorkingMemoryRepository($db);
        // Conversazione di UN'ALTRA sessione, con id INTERMEDIO; poi il cutoff alto in questa sessione.
        $db->execute('INSERT INTO code_sessions (workspace_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$wid, 't2', 'active', date('c'), date('c')]);
        $sid2 = $db->lastInsertId();
        $foreign = $conv($db, $sid2); // aid < foreign
        $hi = $conv($db, $sid);       // foreign < hi (in questa sessione)
        $repo->save($mem(['objective' => 'NUOVO']), $wid, $sid, $hi);

        // (1) stale valido, scope attivo → NO-OP (nessun errore, memoria invariata)
        $repo->save($mem(['objective' => 'STALE-OK']), $wid, $sid, $aid);
        assertSame('NUOVO', $repo->findForSession($wid, $sid)['memory']->objective);
        assertSame($hi, $repo->findForSession($wid, $sid)['last_conversation_id']);

        // (2) cutoff inferiore ma conversazione di un'ALTRA sessione → errore, invariata
        assertSame(true, $throws(static fn () => $repo->save($mem(['objective' => 'X']), $wid, $sid, $foreign)));
        assertSame('NUOVO', $repo->findForSession($wid, $sid)['memory']->objective);

        // (3) stale dopo ARCHIVIAZIONE della sessione → errore, invariata
        $db->execute('UPDATE code_sessions SET status = ? WHERE id = ?', ['archived', $sid]);
        assertSame(true, $throws(static fn () => $repo->save($mem(['objective' => 'X']), $wid, $sid, $aid)));
        assertSame('NUOVO', $repo->findForSession($wid, $sid)['memory']->objective);
        $db->execute('UPDATE code_sessions SET status = ? WHERE id = ?', ['active', $sid]);

        // (4) stale dopo REVOCA del workspace → errore, invariata
        $db->execute('UPDATE code_workspaces SET status = ? WHERE id = ?', ['revoked', $wid]);
        assertSame(true, $throws(static fn () => $repo->save($mem(['objective' => 'X']), $wid, $sid, $aid)));
        assertSame('NUOVO', $repo->findForSession($wid, $sid)['memory']->objective);
    } finally { $cleanup(); }
});

test('memoria: sessione archiviata durante il salvataggio → errore controllato, memoria invariata', function () use ($make, $mem, $throws, $conv) {
    [$db, $wid, $sid, $aid, $cleanup] = $make();
    try {
        $repo = new CodeWorkingMemoryRepository($db);
        $repo->save($mem(['objective' => 'PRIMA']), $wid, $sid, $aid);
        $hi = $conv($db, $sid); // cutoff più alto: NON è una regressione

        // La sessione viene archiviata nella finestra tra la verifica del riepilogatore e il salvataggio.
        $db->execute('UPDATE code_sessions SET status = ? WHERE id = ?', ['archived', $sid]);
        assertSame(true, $throws(static fn () => $repo->save($mem(['objective' => 'DOPO']), $wid, $sid, $hi)));

        // Nessuna scrittura: memoria precedente intatta.
        $read = $repo->findForSession($wid, $sid);
        assertSame('PRIMA', $read['memory']->objective);
        assertSame($aid, $read['last_conversation_id']);
    } finally { $cleanup(); }
});
