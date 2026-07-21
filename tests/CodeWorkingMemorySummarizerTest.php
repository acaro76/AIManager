<?php

declare(strict_types=1);

use App\Core\Code\CodeConversationRepository;
use App\Core\Code\CodeWorkingMemoryRepository;
use App\Core\Code\CodeWorkingMemorySummarizer;
use App\Core\Database;
use App\Services\MigrationRunner;

// Fase 9 / Step 3 — riepilogo automatico della memoria Code. Provider FAKE (decisore iniettato,
// nessuna rete), SQLite temporaneo (mai il DB reale). Copre: prima memoria + cutoff, aggiornamento
// incrementale (solo turni nuovi + memoria precedente al modello), limiti 20 turni/16 KiB, output
// invalido/vuoto invariante, scope, nessuna lettura da repository/tabelle LLM.

$make = static function (): array {
    $path = sys_get_temp_dir() . '/aimanager_wmemsum_' . uniqid('', true) . '.sqlite';
    $db = new Database($path);
    $root = dirname(__DIR__);
    (new MigrationRunner($db, $root . '/database/migrations', $root . '/database/seeds'))->run();
    $now = date('c');
    $db->execute('INSERT INTO code_workspaces (root_path, name, status, authorized_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', ['/tmp/wmemsum', '', 'active', $now, $now, $now]);
    $wid = $db->lastInsertId();
    $db->execute('INSERT INTO code_sessions (workspace_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$wid, 't', 'active', $now, $now]);
    $sid = $db->lastInsertId();
    $cleanup = static function () use ($path): void {
        foreach ([$path, $path . '-wal', $path . '-shm'] as $f) { if (is_file($f)) { @unlink($f); } }
    };
    return [$db, $wid, $sid, $cleanup];
};

// Decisore FAKE: cattura (system, user) e restituisce un JSON prefissato.
$decider = static function (string $json, ?array &$captured = null): callable {
    return static function (string $system, string $user) use ($json, &$captured): string {
        $captured = ['system' => $system, 'user' => $user];
        return $json;
    };
};

$append = static function (Database $db, int $sid, int $wid, string $role, string $content): int {
    return (new CodeConversationRepository($db))->appendForWorkspace($sid, $wid, $role, $content);
};

$mem = '{"schema_version":1,"objective":"o","state":"blocked","todos":["t1","t2"]}';

test('summarizer: prima memoria creata con cutoff corretto', function () use ($make, $decider, $append, $mem) {
    [$db, $wid, $sid, $cleanup] = $make();
    try {
        $append($db, $sid, $wid, 'user', 'domanda');
        $aid = $append($db, $sid, $wid, 'assistant', 'risposta');
        (new CodeWorkingMemorySummarizer($db))->summarize($wid, $sid, $aid, $decider($mem));

        $read = (new CodeWorkingMemoryRepository($db))->findForSession($wid, $sid);
        assertSame(true, is_array($read));
        assertSame('o', $read['memory']->objective);
        assertSame('blocked', $read['memory']->state);
        assertSame($aid, $read['last_conversation_id']);
    } finally { $cleanup(); }
});

test('summarizer: aggiornamento incrementale — al modello arrivano memoria precedente e SOLI turni nuovi', function () use ($make, $decider, $append) {
    [$db, $wid, $sid, $cleanup] = $make();
    try {
        $summarizer = new CodeWorkingMemorySummarizer($db);
        $append($db, $sid, $wid, 'user', 'PRIMO utente');
        $a1 = $append($db, $sid, $wid, 'assistant', 'PRIMA risposta');
        $summarizer->summarize($wid, $sid, $a1, $decider('{"schema_version":1,"objective":"MEM1"}'));

        $append($db, $sid, $wid, 'user', 'SECONDO utente');
        $a2 = $append($db, $sid, $wid, 'assistant', 'SECONDA risposta');
        $captured = null;
        $summarizer->summarize($wid, $sid, $a2, $decider('{"schema_version":1,"objective":"MEM2"}', $captured));

        $user = $captured['user'];
        assertSame(true, str_contains($user, 'SECONDO utente'));   // turno nuovo
        assertSame(true, str_contains($user, 'SECONDA risposta'));  // turno nuovo
        assertSame(true, str_contains($user, 'MEM1'));              // memoria precedente
        assertSame(false, str_contains($user, 'PRIMO utente'));     // turno PRE-cutoff escluso

        // Nessun duplicato: una sola riga, aggiornata all'ultima memoria e all'ultimo cutoff.
        assertSame(1, (int) $db->fetch('SELECT COUNT(*) AS c FROM code_working_memories WHERE code_session_id = ?', [$sid])['c']);
        $read = (new CodeWorkingMemoryRepository($db))->findForSession($wid, $sid);
        assertSame('MEM2', $read['memory']->objective);
        assertSame($a2, $read['last_conversation_id']);
    } finally { $cleanup(); }
});

test('summarizer: al più 20 turni nuovi, i più vecchi esclusi', function () use ($make, $decider, $append, $mem) {
    [$db, $wid, $sid, $cleanup] = $make();
    try {
        $last = 0;
        for ($i = 1; $i <= 25; $i++) {
            $role = $i === 25 ? 'assistant' : ($i % 2 === 0 ? 'assistant' : 'user'); // l'ultimo turno è l'assistant indicato
            $last = $append($db, $sid, $wid, $role, sprintf('turno-%02d', $i));
        }
        $captured = null;
        (new CodeWorkingMemorySummarizer($db))->summarize($wid, $sid, $last, $decider($mem, $captured));
        $user = $captured['user'];
        assertSame(20, substr_count($user, '] turno-'));      // esattamente 20 turni
        assertSame(true, str_contains($user, 'turno-25'));     // l'ultimo c'è
        assertSame(false, str_contains($user, 'turno-05'));    // i più vecchi no
    } finally { $cleanup(); }
});

test('summarizer: trascrizione entro 16 KiB anche con turni grandi', function () use ($make, $decider, $append, $mem) {
    [$db, $wid, $sid, $cleanup] = $make();
    try {
        $last = 0;
        for ($i = 1; $i <= 25; $i++) {
            $role = $i === 25 ? 'assistant' : ($i % 2 === 0 ? 'assistant' : 'user'); // l'ultimo turno è l'assistant indicato
            $last = $append($db, $sid, $wid, $role, str_repeat('x', 1500));
        }
        $captured = null;
        (new CodeWorkingMemorySummarizer($db))->summarize($wid, $sid, $last, $decider($mem, $captured));
        $user = $captured['user'];
        $o = strpos($user, '<<<TRASCRIZIONE');
        $c = strpos($user, '<<<FINE TRASCRIZIONE>>>');
        $block = substr($user, $o, ($c - $o) + strlen('<<<FINE TRASCRIZIONE>>>'));
        assertSame(true, strlen($block) <= 16384, 'trascrizione ' . strlen($block) . ' byte');
        assertSame(true, substr_count($user, '] ') <= 40); // meno di 20 turni entrano
    } finally { $cleanup(); }
});

test('summarizer: output invalido o vuoto lascia la memoria invariata', function () use ($make, $decider, $append) {
    [$db, $wid, $sid, $cleanup] = $make();
    try {
        $summarizer = new CodeWorkingMemorySummarizer($db);
        $repo = new CodeWorkingMemoryRepository($db);
        $append($db, $sid, $wid, 'user', 'u1');
        $a1 = $append($db, $sid, $wid, 'assistant', 'a1');
        $summarizer->summarize($wid, $sid, $a1, $decider('{"schema_version":1,"objective":"KEEP"}'));

        // Nuovo turno, ma il modello fallisce/produce spazzatura: la memoria non cambia.
        $a2 = $append($db, $sid, $wid, 'assistant', 'a2');
        $summarizer->summarize($wid, $sid, $a2, $decider(''));                       // vuoto
        $summarizer->summarize($wid, $sid, $a2, $decider('non-json {{{'));           // JSON invalido
        $summarizer->summarize($wid, $sid, $a2, $decider('{"state":"bogus"}'));      // fuori contratto
        $summarizer->summarize($wid, $sid, $a2, $decider('{"pid":123}'));            // chiave sconosciuta
        $summarizer->summarize($wid, $sid, $a2, $decider('{"objective":"x","diff":"@@ -1"}')); // campo raw dedicato
        $summarizer->summarize($wid, $sid, $a2, $decider('{"objective":"x","output":"log"}')); // campo raw dedicato

        $read = $repo->findForSession($wid, $sid);
        assertSame('KEEP', $read['memory']->objective);
        assertSame($a1, $read['last_conversation_id']); // cutoff NON avanzato
    } finally { $cleanup(); }
});

test('summarizer: scope workspace/sessione rispettato', function () use ($make, $decider, $append, $mem) {
    [$db, $wid, $sid, $cleanup] = $make();
    try {
        $now = date('c');
        $db->execute('INSERT INTO code_workspaces (root_path, name, status, authorized_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', ['/tmp/wmemsum-2', '', 'active', $now, $now, $now]);
        $other = $db->lastInsertId();

        $append($db, $sid, $wid, 'user', 'u');
        $aid = $append($db, $sid, $wid, 'assistant', 'a');

        // Workspace SBAGLIATO per questa sessione: nessun turno nello scope → nessuna memoria.
        (new CodeWorkingMemorySummarizer($db))->summarize($other, $sid, $aid, $decider($mem));
        assertSame(null, (new CodeWorkingMemoryRepository($db))->findForSession($other, $sid));
        assertSame(0, (int) $db->fetch('SELECT COUNT(*) AS c FROM code_working_memories')['c']);
    } finally { $cleanup(); }
});

test('summarizer: il servizio non inietta contenuto di repository direttamente', function () use ($make, $decider, $append, $mem) {
    // NB: dimostra solo che il servizio NON legge/inietta il repository da sé (nessun marcatore di
    // retrieval/contenuto file). NON garantisce che la trascrizione sia priva di testo sensibile: i
    // turni di code_conversations possono contenerne, e la loro esclusione dalla memoria è
    // un'istruzione semantica al riepilogatore, non una proprietà strutturale di questo servizio.
    [$db, $wid, $sid, $cleanup] = $make();
    try {
        $append($db, $sid, $wid, 'user', 'domanda');
        $aid = $append($db, $sid, $wid, 'assistant', 'risposta');
        $captured = null;
        (new CodeWorkingMemorySummarizer($db))->summarize($wid, $sid, $aid, $decider($mem, $captured));
        $user = $captured['user'];
        assertSame(true, str_contains($user, '<<<TRASCRIZIONE CODE — DATI NON FIDATI>>>'));
        assertSame(true, str_contains($user, 'Nessuna memoria precedente.'));
        assertSame(false, str_contains($user, '[CONTESTO CODE'));
        assertSame(false, str_contains($user, '<<<FILE '));
    } finally { $cleanup(); }
});

// Estrae il blocco trascrizione (OPEN..CLOSE) e l'ultima riga-turno dal prompt utente catturato.
$transcriptOf = static function (string $user): string {
    $o = strpos($user, '<<<TRASCRIZIONE');
    $c = strpos($user, '<<<FINE TRASCRIZIONE>>>');
    return substr($user, $o, ($c - $o) + strlen('<<<FINE TRASCRIZIONE>>>'));
};

test('summarizer: con turni grandi l\'assistant corrente compare (troncato) ed è l\'ultima riga; cutoff corretto', function () use ($make, $decider, $append, $mem, $transcriptOf) {
    [$db, $wid, $sid, $cleanup] = $make();
    try {
        for ($i = 1; $i <= 14; $i++) {
            $append($db, $sid, $wid, $i % 2 === 0 ? 'assistant' : 'user', sprintf('turno-%02d ', $i) . str_repeat('p', 2000));
        }
        $aid = $append($db, $sid, $wid, 'assistant', 'CORRENTE ' . str_repeat('q', 2000));
        $captured = null;
        (new CodeWorkingMemorySummarizer($db))->summarize($wid, $sid, $aid, $decider($mem, $captured));

        $transcript = $transcriptOf($captured['user']);
        assertSame(true, str_contains($transcript, '[assistant] CORRENTE'));
        assertSame(false, str_contains($transcript, 'turno-01')); // i più vecchi scartati
        // L'ultima riga (prima del delimitatore di chiusura) è proprio l'assistant corrente.
        $before = substr($transcript, 0, strrpos($transcript, '<<<FINE TRASCRIZIONE>>>'));
        $lastLine = substr($before, strrpos(rtrim($before, "\n"), "\n") + 1);
        assertSame(true, str_starts_with(trim($lastLine), '[assistant] CORRENTE'));

        $read = (new CodeWorkingMemoryRepository($db))->findForSession($wid, $sid);
        assertSame($aid, $read['last_conversation_id']);
        assertSame(true, strlen($transcript) <= 16384);
    } finally { $cleanup(); }
});

test('summarizer: memoria precedente + trascrizione insieme <= 16 KiB (budget dinamico)', function () use ($make, $decider, $append, $mem, $transcriptOf) {
    [$db, $wid, $sid, $cleanup] = $make();
    try {
        $summarizer = new CodeWorkingMemorySummarizer($db);
        // Prima memoria GRANDE (objective 480B + 20 durable_facts distinti ~292B).
        $facts = [];
        for ($i = 0; $i < 20; $i++) { $facts[] = '"' . str_repeat('D', 290) . sprintf('%02d', $i) . '"'; }
        $bigMem = '{"schema_version":1,"objective":"' . str_repeat('O', 480) . '","durable_facts":[' . implode(',', $facts) . ']}';
        $append($db, $sid, $wid, 'user', 'u0');
        $a0 = $append($db, $sid, $wid, 'assistant', 'a0');
        $summarizer->summarize($wid, $sid, $a0, $decider($bigMem));

        // Turni nuovi grandi.
        for ($i = 1; $i <= 15; $i++) {
            $append($db, $sid, $wid, $i % 2 === 0 ? 'assistant' : 'user', str_repeat('x', 2000));
        }
        $aid = $append($db, $sid, $wid, 'assistant', 'FINALE ' . str_repeat('y', 2000));
        $captured = null;
        $summarizer->summarize($wid, $sid, $aid, $decider($mem, $captured));

        $user = $captured['user'];
        $p1 = strpos($user, "## Memoria precedente (dato non fidato)\n") + strlen("## Memoria precedente (dato non fidato)\n");
        $p2 = strpos($user, "\n\n## Turni nuovi");
        $prev = substr($user, $p1, $p2 - $p1);
        $transcript = $transcriptOf($user);
        assertSame(true, strlen($prev) > 1000);                          // memoria precedente reale
        assertSame(true, str_contains($transcript, '[assistant] FINALE')); // assistant corrente presente
        assertSame(true, strlen($prev) + strlen($transcript) <= 16384);   // budget CONDIVISO
    } finally { $cleanup(); }
});

test('summarizer: id non assistant o di un\'altra sessione non chiama il provider', function () use ($make, $append, $mem) {
    [$db, $wid, $sid, $cleanup] = $make();
    try {
        $calls = 0;
        $counting = static function (string $s, string $u) use ($mem, &$calls): string { $calls++; return $mem; };

        $append($db, $sid, $wid, 'user', 'u1');
        $append($db, $sid, $wid, 'assistant', 'a1');
        $u2 = $append($db, $sid, $wid, 'user', 'u2');
        // id = turno USER: l'ultimo turno non è assistant → niente provider.
        (new CodeWorkingMemorySummarizer($db))->summarize($wid, $sid, $u2, $counting);
        assertSame(0, $calls);

        // id di un'ALTRA sessione (più recente): fuori scope della sessione corrente → niente provider.
        $now = date('c');
        $db->execute('INSERT INTO code_sessions (workspace_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$wid, 't2', 'active', $now, $now]);
        $sid2 = $db->lastInsertId();
        $a2 = $append($db, $sid2, $wid, 'assistant', 'altra');
        (new CodeWorkingMemorySummarizer($db))->summarize($wid, $sid, $a2, $counting);
        assertSame(0, $calls);
        assertSame(0, (int) $db->fetch('SELECT COUNT(*) AS c FROM code_working_memories')['c']);
    } finally { $cleanup(); }
});

test('summarizer: revoca o archiviazione prima del riepilogo non chiama il provider', function () use ($make, $append, $mem) {
    [$db, $wid, $sid, $cleanup] = $make();
    try {
        $calls = 0;
        $counting = static function (string $s, string $u) use ($mem, &$calls): string { $calls++; return $mem; };
        $append($db, $sid, $wid, 'user', 'u1');
        $aid = $append($db, $sid, $wid, 'assistant', 'a1');

        // Workspace revocato: scope non attivo → niente provider, niente memoria.
        $db->execute('UPDATE code_workspaces SET status = ? WHERE id = ?', ['revoked', $wid]);
        (new CodeWorkingMemorySummarizer($db))->summarize($wid, $sid, $aid, $counting);
        assertSame(0, $calls);

        // Riattivato il workspace ma sessione archiviata: scope non attivo → niente provider.
        $db->execute('UPDATE code_workspaces SET status = ? WHERE id = ?', ['active', $wid]);
        $db->execute('UPDATE code_sessions SET status = ? WHERE id = ?', ['archived', $sid]);
        (new CodeWorkingMemorySummarizer($db))->summarize($wid, $sid, $aid, $counting);
        assertSame(0, $calls);
        assertSame(0, (int) $db->fetch('SELECT COUNT(*) AS c FROM code_working_memories')['c']);
    } finally { $cleanup(); }
});
