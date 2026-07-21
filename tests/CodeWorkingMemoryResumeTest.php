<?php

declare(strict_types=1);

use App\Core\Code\CodeChatSchema;
use App\Core\Code\CodeConversationRepository;
use App\Core\Code\CodeSessionRepository;
use App\Core\Code\CodeWorkingMemory;
use App\Core\Code\CodeWorkingMemoryRepository;
use App\Core\Code\CodeWorkingMemorySummarizer;
use App\Core\Code\CodeWorkspaceRepository;
use App\Core\Database;
use App\Core\Providers\ProviderRequest;
use App\Core\Code\RetrievalLimits;
use App\Services\AIProviderResult;
use App\Services\CodeChatService;

// Fase 9 / Step 4 — ripresa del lavoro in una nuova sessione Code. Provider FAKE, SQLite e cartella
// temporanei (mai il DB reale). La tabella 040 è presente in questi test (schema chat + 040).

$rmrf = static function (string $path) use (&$rmrf): void {
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $e) { if ($e !== '.' && $e !== '..') { $rmrf($path . '/' . $e); } }
        @rmdir($path);
        return;
    }
    @unlink($path);
};

$mkroot = static function (): string {
    $base = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
    $root = $base . '/aimanager_wmemresume_' . uniqid('', true);
    mkdir($root . '/app', 0777, true);
    file_put_contents($root . '/app/Login.php', "<?php\nfunction login() { return true; }\n");
    file_put_contents($root . '/README.md', "Il login del progetto.\n");
    return realpath($root) ?: $root;
};

$limits = static function (array $o = []): RetrievalLimits {
    $a = array_merge([
        'scanMaxDepth' => 12, 'scanMaxFiles' => 2000, 'scanMaxReadBytes' => 262144, 'scanMaxSeconds' => 5.0,
        'searchMaxFilesScanned' => 2000, 'searchMaxMatches' => 100, 'searchMaxBytesPerFile' => 262144,
        'searchMaxTotalBytes' => 4194304, 'searchMaxSeconds' => 5.0,
        'readMaxFiles' => 12, 'readMaxBytesPerFile' => 65536, 'readMaxTotalBytes' => 262144, 'contextMaxChars' => 48000,
    ], $o);
    return new RetrievalLimits(...$a);
};

// DB con SOLO tabelle Code + la 040 applicata dalla migrazione reale.
$mkdb = static function (string $root): array {
    $path = sys_get_temp_dir() . '/aimanager_wmemresume_' . uniqid('', true) . '.sqlite';
    $db = new Database($path);
    $db->pdo()->exec('PRAGMA foreign_keys = ON');
    $db->execute('CREATE TABLE code_workspaces (
        id INTEGER PRIMARY KEY AUTOINCREMENT, root_path TEXT NOT NULL UNIQUE, name TEXT NOT NULL DEFAULT \'\',
        status TEXT NOT NULL DEFAULT \'active\', authorized_at TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
        CHECK(status IN (\'active\',\'revoked\')))');
    CodeChatSchema::createForTests($db);
    (require dirname(__DIR__) . '/database/migrations/040_create_code_working_memories.php')($db);
    $ws = (new CodeWorkspaceRepository($db))->authorizeRoot($root);
    $sid = (new CodeSessionRepository($db))->create($ws->id, 'sessione A');
    return [$db, $ws->id, $sid];
};

$fakeStreamer = static function (AIProviderResult $result, ?array &$captured = null): callable {
    return static function (ProviderRequest $request, callable $onDelta) use ($result, &$captured): AIProviderResult {
        $captured = ['request' => $request];
        $onDelta('x', 'content');
        return $result;
    };
};

// Riepilogo NO-OP: nei test di contesto evita che il riepilogo (che richiamerebbe lo streamer)
// sovrascriva la ProviderRequest catturata. La creazione della memoria è coperta a parte.
$noSummary = static function (int $w, int $s, int $a, callable $decider): void {};

// Semina una riga di memoria per una sessione (conversazione per la FK + payload canonico).
$seedMemory = static function (Database $db, int $wid, int $sid, string $state, string $objective, ?string $updatedAt = null) {
    $now = date('c');
    $conv = (new CodeConversationRepository($db))->appendForWorkspace($sid, $wid, 'assistant', 'seed');
    $payload = CodeWorkingMemory::fromArray(['state' => $state, 'objective' => $objective])->toJson();
    $db->execute(
        'INSERT INTO code_working_memories (workspace_id, code_session_id, last_conversation_id, payload_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$wid, $sid, $conv, $payload, $now, $updatedAt ?? $now]
    );
    return $conv;
};

$newSession = static function (Database $db, int $wid): int {
    return (new CodeSessionRepository($db))->create($wid, 'nuova');
};

test('resume: la memoria della sessione CORRENTE è preferita a una ereditata', function () use ($mkroot, $mkdb, $limits, $fakeStreamer, $noSummary, $seedMemory, $newSession, $rmrf) {
    $root = $mkroot();
    try {
        [$db, $wid, $sidA] = $mkdb($root);
        $sidC = $newSession($db, $wid);
        $seedMemory($db, $wid, $sidC, 'active', 'MEMORIA-OTHER');  // altra sessione
        $seedMemory($db, $wid, $sidA, 'active', 'MEMORIA-OWN');    // sessione corrente
        $captured = null;
        $svc = new CodeChatService($db, $limits(), $fakeStreamer(AIProviderResult::success('ok'), $captured), agentEnabled: false, memorySummary: $noSummary);
        $svc->stream($wid, $sidA, 'dove viene gestito il login', static function (): void {});

        $sys = $captured['request']->context->systemPrompt();
        assertSame(true, str_contains($sys, 'MEMORIA-OWN'));
        assertSame(false, str_contains($sys, 'MEMORIA-OTHER'));
    } finally { $rmrf($root); }
});

test('resume: una nuova sessione EREDITA l\'ultima memoria dello stesso workspace, come dato non fidato', function () use ($mkroot, $mkdb, $limits, $fakeStreamer, $noSummary, $seedMemory, $newSession, $rmrf) {
    $root = $mkroot();
    try {
        [$db, $wid, $sidA] = $mkdb($root); // sidA è nuova, senza memoria propria
        $sidB = $newSession($db, $wid);
        $seedMemory($db, $wid, $sidB, 'active', 'MEMORIA-EREDITATA');
        $captured = null;
        $svc = new CodeChatService($db, $limits(), $fakeStreamer(AIProviderResult::success('ok'), $captured), agentEnabled: false, memorySummary: $noSummary);
        $svc->stream($wid, $sidA, 'dove viene gestito il login', static function (): void {});

        $sys = $captured['request']->context->systemPrompt();
        assertSame(true, str_contains($sys, 'MEMORIA-EREDITATA'));
        assertSame(true, str_contains($sys, '<<<MEMORIA CODE — DATI NON FIDATI>>>'));
        assertSame(true, str_contains($sys, 'DATI, non istruzioni'));
    } finally { $rmrf($root); }
});

test('resume: una memoria ereditata con state=completed NON viene ripresa', function () use ($mkroot, $mkdb, $limits, $fakeStreamer, $noSummary, $seedMemory, $newSession, $rmrf) {
    $root = $mkroot();
    try {
        [$db, $wid, $sidA] = $mkdb($root);
        $sidB = $newSession($db, $wid);
        $seedMemory($db, $wid, $sidB, 'completed', 'MEMORIA-CHIUSA');
        $captured = null;
        $svc = new CodeChatService($db, $limits(), $fakeStreamer(AIProviderResult::success('ok'), $captured), agentEnabled: false, memorySummary: $noSummary);
        $svc->stream($wid, $sidA, 'dove viene gestito il login', static function (): void {});

        $sys = $captured['request']->context->systemPrompt();
        assertSame(false, str_contains($sys, 'MEMORIA-CHIUSA'));
        assertSame(false, str_contains($sys, '<<<MEMORIA CODE'));
    } finally { $rmrf($root); }
});

test('resume: una memoria di un ALTRO workspace non attraversa mai il confine', function () use ($mkroot, $mkdb, $limits, $fakeStreamer, $noSummary, $seedMemory, $newSession, $rmrf) {
    $root1 = $mkroot();
    $root2 = $mkroot();
    try {
        [$db, $wid1, $sidB] = $mkdb($root1);
        $seedMemory($db, $wid1, $sidB, 'active', 'SEGRETO-W1');
        $ws2 = (new CodeWorkspaceRepository($db))->authorizeRoot($root2);
        $sidA = (new CodeSessionRepository($db))->create($ws2->id, 'sessione W2');
        $captured = null;
        $svc = new CodeChatService($db, $limits(), $fakeStreamer(AIProviderResult::success('ok'), $captured), agentEnabled: false, memorySummary: $noSummary);
        $svc->stream($ws2->id, $sidA, 'dove viene gestito il login', static function (): void {});

        $sys = $captured['request']->context->systemPrompt();
        assertSame(false, str_contains($sys, 'SEGRETO-W1'));
        assertSame(false, str_contains($sys, '<<<MEMORIA CODE'));
    } finally { $rmrf($root1); $rmrf($root2); }
});

test('resume: latestForWorkspaceExcludingSession ordina in modo deterministico (updated_at DESC, id DESC)', function () use ($mkroot, $mkdb, $seedMemory, $newSession, $rmrf) {
    $root = $mkroot();
    try {
        [$db, $wid, $sidA] = $mkdb($root);
        $sidB = $newSession($db, $wid);
        $sidC = $newSession($db, $wid);
        $sidD = $newSession($db, $wid);
        $seedMemory($db, $wid, $sidB, 'active', 'B', '2020-01-01T00:00:00+00:00');
        $seedMemory($db, $wid, $sidC, 'active', 'C', '2021-01-01T00:00:00+00:00'); // pari data con D
        $seedMemory($db, $wid, $sidD, 'active', 'D', '2021-01-01T00:00:00+00:00'); // id più alto → vince

        $repo = new CodeWorkingMemoryRepository($db);
        assertSame('D', $repo->latestForWorkspaceExcludingSession($wid, $sidA)['memory']->objective); // tie → id DESC
        assertSame('C', $repo->latestForWorkspaceExcludingSession($wid, $sidD)['memory']->objective); // escluso D → C
        assertSame('D', $repo->latestForWorkspaceExcludingSession($wid, $sidC)['memory']->objective); // escluso C → D (data > B)
        assertSame(null, $repo->latestForWorkspaceExcludingSession($wid + 999, $sidA));               // altro workspace
    } finally { $rmrf($root); }
});

test('resume: budget globale rispettato e blocco Git prioritario sulla memoria ereditata', function () use ($mkroot, $mkdb, $limits, $fakeStreamer, $noSummary, $seedMemory, $newSession, $rmrf) {
    if (!is_file('/usr/bin/git') || !is_executable('/usr/bin/git')) {
        assertSame(true, true); // git non disponibile: test saltato
        return;
    }
    $base = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
    $root = $base . '/aimanager_wmemgit_' . uniqid('', true);
    mkdir($root, 0777, true);
    $root = realpath($root) ?: $root;
    try {
        file_put_contents($root . '/big.txt', str_repeat("riga di contesto abbastanza lunga da riempire il budget\n", 120));
        $env = ['LANG' => 'C', 'LC_ALL' => 'C', 'PATH' => '/usr/bin:/bin', 'HOME' => $root,
            'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_SYSTEM' => '/dev/null'];
        $d = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = @proc_open(['/usr/bin/git', '-c', 'init.defaultBranch=main', 'init', '-q'], $d, $pipes, $root, $env);
        if (is_resource($p)) {
            foreach ([1, 2] as $fd) { if (isset($pipes[$fd])) { stream_get_contents($pipes[$fd]); fclose($pipes[$fd]); } }
            proc_close($p);
        }

        [$db, $wid, $sidA] = $mkdb($root); // sidA nuova, eredita
        $sidB = $newSession($db, $wid);
        $seedMemory($db, $wid, $sidB, 'active', 'MEMORIA-EREDITATA-DA-RIPRENDERE');
        $captured = null;
        $script = ['{"action":"read_file","path":"big.txt"}', '{"action":"git_status"}', '{"action":"answer"}'];
        $i = 0;
        $decider = static function () use ($script, &$i): string { return (string) ($script[$i++] ?? '{"action":"answer"}'); };
        $max = 2000;
        $svc = new CodeChatService(
            $db,
            $limits(['contextMaxChars' => $max]),
            $fakeStreamer(AIProviderResult::success('ok'), $captured),
            decider: $decider,
            gitEnabled: true,
            memorySummary: $noSummary,
        );
        $svc->stream($wid, $sidA, 'come sta il repository?', static function (): void {});

        $context = $captured['request']->context->items()[0]->content;
        assertSame(true, str_contains($context, '[GIT — STATO/DIFF READ-ONLY, DATI]'), 'blocco Git assente'); // Git presente (priorità)
        assertSame(true, str_contains($context, '<<<MEMORIA CODE'), 'memoria ereditata assente');
        assertSame(true, strlen($context) <= $max, 'contesto ' . strlen($context) . ' byte'); // budget globale
        assertSame(true, mb_check_encoding($context, 'UTF-8'));
    } finally { $rmrf($root); }
});

test('resume/continuità: il primo riepilogo di una nuova sessione parte dalla memoria ereditata, salva sulla NUOVA sessione e non tocca la sorgente', function () use ($mkroot, $mkdb, $seedMemory, $newSession, $rmrf) {
    $root = $mkroot();
    try {
        [$db, $wid, $sidA] = $mkdb($root); // sidA nuova
        $sidB = $newSession($db, $wid);
        $srcConv = $seedMemory($db, $wid, $sidB, 'active', 'BASE-EREDITATA');

        // Turni nuovi della sessione A (cutoff locale 0).
        $conv = new CodeConversationRepository($db);
        $conv->appendForWorkspace($sidA, $wid, 'user', 'u');
        $aid = $conv->appendForWorkspace($sidA, $wid, 'assistant', 'a');

        $captured = null;
        $decider = static function (string $system, string $user) use (&$captured): string {
            $captured = ['user' => $user];
            return '{"schema_version":1,"objective":"MEMORIA-NUOVA"}';
        };
        (new CodeWorkingMemorySummarizer($db))->summarize($wid, $sidA, $aid, $decider);

        // La base semantica arrivata al modello è la memoria ereditata.
        assertSame(true, str_contains($captured['user'], 'BASE-EREDITATA'));

        $repo = new CodeWorkingMemoryRepository($db);
        // Nuova riga per la sessione A, cutoff sull'assistant di A.
        $readA = $repo->findForSession($wid, $sidA);
        assertSame('MEMORIA-NUOVA', $readA['memory']->objective);
        assertSame($aid, $readA['last_conversation_id']);
        // La sorgente (sessione B) resta invariata.
        $readB = $repo->findForSession($wid, $sidB);
        assertSame('BASE-EREDITATA', $readB['memory']->objective);
        assertSame($srcConv, $readB['last_conversation_id']);
        // Due righe distinte: nessuna copia in fase di creazione, sorgente intatta.
        assertSame(2, (int) $db->fetch('SELECT COUNT(*) AS c FROM code_working_memories')['c']);
    } finally { $rmrf($root); }
});

test('resume: schema assente o payload incompatibile non degrada la chat', function () use ($mkroot, $mkdb, $limits, $fakeStreamer, $noSummary, $rmrf) {
    // (a) schema assente: la tabella non esiste → nessuna memoria, chat regolare.
    $root = $mkroot();
    try {
        [$db, $wid, $sid] = $mkdb($root);
        $db->execute('DROP TABLE code_working_memories');
        $captured = null;
        $svc = new CodeChatService($db, $limits(), $fakeStreamer(AIProviderResult::success('ok'), $captured), agentEnabled: false, memorySummary: $noSummary);
        $out = $svc->stream($wid, $sid, 'dove viene gestito il login', static function (): void {});
        assertSame('success', $out['status']);
        assertSame(false, str_contains($captured['request']->context->systemPrompt(), '<<<MEMORIA CODE'));
    } finally { $rmrf($root); }

    // (b) payload incompatibile per la sessione corrente → nessuna memoria, chat regolare.
    $root2 = $mkroot();
    try {
        [$db, $wid, $sid] = $mkdb($root2);
        $conv = (new CodeConversationRepository($db))->appendForWorkspace($sid, $wid, 'assistant', 'x');
        $now = date('c');
        $db->execute(
            'INSERT INTO code_working_memories (workspace_id, code_session_id, last_conversation_id, payload_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$wid, $sid, $conv, '{"schema_version":2}', $now, $now]
        );
        $captured = null;
        $svc = new CodeChatService($db, $limits(), $fakeStreamer(AIProviderResult::success('ok'), $captured), agentEnabled: false, memorySummary: $noSummary);
        $out = $svc->stream($wid, $sid, 'dove viene gestito il login', static function (): void {});
        assertSame('success', $out['status']);
        assertSame(false, str_contains($captured['request']->context->systemPrompt(), '<<<MEMORIA CODE'));
    } finally { $rmrf($root2); }
});

test('resume: la sola creazione della sessione non scrive memoria', function () use ($mkroot, $mkdb, $newSession, $rmrf) {
    $root = $mkroot();
    try {
        [$db, $wid, $sidA] = $mkdb($root);
        $newSession($db, $wid);
        $newSession($db, $wid);
        assertSame(0, (int) $db->fetch('SELECT COUNT(*) AS c FROM code_working_memories')['c']);
    } finally { $rmrf($root); }
});

test('resume: budget con allegati + memoria + repository + Git resta <= contextMaxChars, Git prioritario', function () use ($mkdb, $limits, $fakeStreamer, $noSummary, $seedMemory, $newSession, $rmrf) {
    if (!is_file('/usr/bin/git') || !is_executable('/usr/bin/git')) {
        assertSame(true, true); // git non disponibile: test saltato
        return;
    }
    $base = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
    $root = $base . '/aimanager_wmemall_' . uniqid('', true);
    mkdir($root, 0777, true);
    $root = realpath($root) ?: $root;
    try {
        file_put_contents($root . '/big.txt', str_repeat("riga di contesto abbastanza lunga da riempire il budget\n", 200));
        $env = ['LANG' => 'C', 'LC_ALL' => 'C', 'PATH' => '/usr/bin:/bin', 'HOME' => $root,
            'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_SYSTEM' => '/dev/null'];
        $d = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = @proc_open(['/usr/bin/git', '-c', 'init.defaultBranch=main', 'init', '-q'], $d, $pipes, $root, $env);
        if (is_resource($p)) {
            foreach ([1, 2] as $fd) { if (isset($pipes[$fd])) { stream_get_contents($pipes[$fd]); fclose($pipes[$fd]); } }
            proc_close($p);
        }

        [$db, $wid, $sidA] = $mkdb($root);
        $sidB = $newSession($db, $wid);
        $seedMemory($db, $wid, $sidB, 'active', 'MEMORIA-EREDITATA-XYZ');
        $captured = null;
        $script = ['{"action":"read_file","path":"big.txt"}', '{"action":"git_status"}', '{"action":"answer"}'];
        $i = 0;
        $decider = static function () use ($script, &$i): string { return (string) ($script[$i++] ?? '{"action":"answer"}'); };
        $max = 6000;
        $svc = new CodeChatService(
            $db,
            $limits(['contextMaxChars' => $max]),
            $fakeStreamer(AIProviderResult::success('ok'), $captured),
            decider: $decider,
            gitEnabled: true,
            memorySummary: $noSummary,
        );
        $attachments = [['name' => 'note.txt', 'content' => 'ALLEGATO-' . str_repeat('A', 4000)]];
        $svc->stream($wid, $sidA, 'come sta il repository?', static function (): void {}, null, 'auto', $attachments);

        $context = $captured['request']->context->items()[0]->content;
        assertSame(true, str_contains($context, '[FILE SELEZIONATI'), 'allegato assente');
        assertSame(true, str_contains($context, '[GIT — STATO/DIFF READ-ONLY, DATI]'), 'Git assente'); // priorità
        assertSame(true, str_contains($context, '<<<MEMORIA CODE'), 'memoria ereditata assente');
        assertSame(true, str_contains($context, 'riga di contesto'), 'repository context assente');
        assertSame(true, strlen($context) <= $max, 'contesto ' . strlen($context) . ' byte'); // budget globale
        assertSame(true, mb_check_encoding($context, 'UTF-8'));
    } finally { $rmrf($root); }
});

test('resume: una memoria ereditata INCOMPATIBILE non degrada la chat (nessuna memoria nel contesto)', function () use ($mkroot, $mkdb, $limits, $fakeStreamer, $noSummary, $newSession, $rmrf) {
    $root = $mkroot();
    try {
        [$db, $wid, $sidA] = $mkdb($root);
        $sidB = $newSession($db, $wid);
        $convB = (new CodeConversationRepository($db))->appendForWorkspace($sidB, $wid, 'assistant', 'x');
        $now = date('c');
        // Riga sorgente con payload incompatibile (versione diversa): la lettura ereditata fallisce chiusa.
        $db->execute(
            'INSERT INTO code_working_memories (workspace_id, code_session_id, last_conversation_id, payload_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$wid, $sidB, $convB, '{"schema_version":2}', $now, $now]
        );
        $captured = null;
        $svc = new CodeChatService($db, $limits(), $fakeStreamer(AIProviderResult::success('ok'), $captured), agentEnabled: false, memorySummary: $noSummary);
        $out = $svc->stream($wid, $sidA, 'dove viene gestito il login', static function (): void {});
        assertSame('success', $out['status']);
        assertSame(false, str_contains($captured['request']->context->systemPrompt(), '<<<MEMORIA CODE'));
    } finally { $rmrf($root); }
});

test('resume: la memoria non tocca tabelle LLM (assenti) né i loro conteggi', function () use ($mkroot, $mkdb, $limits, $fakeStreamer, $noSummary, $seedMemory, $newSession, $rmrf) {
    $root = $mkroot();
    try {
        [$db, $wid, $sidA] = $mkdb($root);
        $sidB = $newSession($db, $wid);
        $seedMemory($db, $wid, $sidB, 'active', 'MEMORIA-EREDITATA');
        $svc = new CodeChatService($db, $limits(), $fakeStreamer(AIProviderResult::success('ok')), agentEnabled: false, memorySummary: $noSummary);
        $svc->stream($wid, $sidA, 'dove viene gestito il login', static function (): void {});

        // Nessuna tabella LLM esiste in questo DB: l'ereditarietà lavora su tabelle Code soltanto.
        foreach (['projects', 'sessions', 'conversations', 'execution_states'] as $t) {
            assertSame(null, $db->fetch("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$t]), $t);
        }
        // La memoria (Code) è stata comunque usata: la sorgente esiste ed è intatta.
        assertSame(1, (int) $db->fetch('SELECT COUNT(*) AS c FROM code_working_memories')['c']);
    } finally { $rmrf($root); }
});
