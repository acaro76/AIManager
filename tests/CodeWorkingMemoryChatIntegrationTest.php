<?php

declare(strict_types=1);

use App\Core\Code\CodeChatSchema;
use App\Core\Code\CodeSessionRepository;
use App\Core\Code\CodeWorkingMemoryRepository;
use App\Core\Code\CodeWorkspaceRepository;
use App\Core\Database;
use App\Core\Providers\ProviderRequest;
use App\Services\AIProviderResult;
use App\Services\CodeChatService;

// Fase 9 / Step 3 — aggancio del riepilogo a CodeChatService: il riepilogo scatta SOLO su un turno
// assistant riuscito, non su un fallimento, e un suo errore non degrada l'esito Code. Provider FAKE,
// SQLite e cartella temporanei (mai il DB reale). Lo schema 040 è presente in questi test.

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
    $root = $base . '/aimanager_wmemchat_' . uniqid('', true);
    mkdir($root . '/app', 0777, true);
    file_put_contents($root . '/app/Login.php', "<?php\nfunction login() { return true; }\n");
    file_put_contents($root . '/README.md', "Il login del progetto.\n");
    return $root;
};

// DB con SOLO tabelle Code + la 040 (code_working_memories) applicata dalla migrazione reale.
$mkdb = static function (string $root): array {
    $path = sys_get_temp_dir() . '/aimanager_wmemchat_' . uniqid('', true) . '.sqlite';
    $db = new Database($path);
    $db->pdo()->exec('PRAGMA foreign_keys = ON');
    $db->execute('CREATE TABLE code_workspaces (
        id INTEGER PRIMARY KEY AUTOINCREMENT, root_path TEXT NOT NULL UNIQUE, name TEXT NOT NULL DEFAULT \'\',
        status TEXT NOT NULL DEFAULT \'active\', authorized_at TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
        CHECK(status IN (\'active\',\'revoked\')))');
    CodeChatSchema::createForTests($db);
    (require dirname(__DIR__) . '/database/migrations/040_create_code_working_memories.php')($db);
    $ws = (new CodeWorkspaceRepository($db))->authorizeRoot($root);
    $sid = (new CodeSessionRepository($db))->create($ws->id, 'sessione');
    return [$db, $ws->id, $sid];
};

$streamer = static function (AIProviderResult $result): callable {
    return static function (ProviderRequest $request, callable $onDelta) use ($result): AIProviderResult {
        $onDelta('parziale ', 'content');
        return $result;
    };
};

test('chat→memoria: un turno riuscito crea la memoria col cutoff sull\'assistant salvato', function () use ($mkroot, $mkdb, $streamer, $rmrf) {
    $root = $mkroot();
    try {
        [$db, $wsId, $sid] = $mkdb($root);
        // Il fake streamer serve sia la risposta della chat sia la decisione del riepilogo: qui
        // restituisce un JSON di memoria valido, così il summarizer reale lo persiste.
        $json = '{"schema_version":1,"objective":"riassunto del lavoro","todos":["fatto x"]}';
        $svc = new CodeChatService($db, null, $streamer(AIProviderResult::success($json)), agentEnabled: false);

        $out = $svc->stream($wsId, $sid, 'dove viene gestito il login', static function (): void {});
        assertSame('success', $out['status']);

        $assistant = $db->fetch("SELECT id FROM code_conversations WHERE role = 'assistant' ORDER BY id DESC");
        $read = (new CodeWorkingMemoryRepository($db))->findForSession($wsId, $sid);
        assertSame(true, is_array($read));
        assertSame('riassunto del lavoro', $read['memory']->objective);
        assertSame((int) $assistant['id'], $read['last_conversation_id']);
    } finally { $rmrf($root); }
});

test('chat→memoria: un turno FALLITO (nessun assistant) non crea memoria', function () use ($mkroot, $mkdb, $streamer, $rmrf) {
    $root = $mkroot();
    try {
        [$db, $wsId, $sid] = $mkdb($root);
        $svc = new CodeChatService($db, null, $streamer(AIProviderResult::failure('provider giù')), agentEnabled: false);

        $out = $svc->stream($wsId, $sid, 'dove viene gestito il login', static function (): void {});
        assertSame('error', $out['status']);
        assertSame(0, (int) $db->fetch("SELECT COUNT(*) c FROM code_conversations WHERE role = 'assistant'")['c']);
        assertSame(null, (new CodeWorkingMemoryRepository($db))->findForSession($wsId, $sid));
    } finally { $rmrf($root); }
});

test('chat→memoria: un errore del riepilogo NON degrada la risposta riuscita', function () use ($mkroot, $mkdb, $streamer, $rmrf) {
    $root = $mkroot();
    try {
        [$db, $wsId, $sid] = $mkdb($root);
        // Riepilogo iniettato che ESPLODE: la chat deve restare success e la memoria assente.
        $boom = static function (int $w, int $s, int $a, callable $decider): void {
            throw new \RuntimeException('riepilogo rotto');
        };
        $svc = new CodeChatService(
            $db, null, $streamer(AIProviderResult::success('una risposta normale')),
            agentEnabled: false, memorySummary: $boom
        );

        $out = $svc->stream($wsId, $sid, 'dove viene gestito il login', static function (): void {});
        assertSame('success', $out['status']);
        assertSame(1, (int) $db->fetch("SELECT COUNT(*) c FROM code_conversations WHERE role = 'assistant'")['c']);
        assertSame(null, (new CodeWorkingMemoryRepository($db))->findForSession($wsId, $sid));
    } finally { $rmrf($root); }
});

// Fase 9 / gate reale — LM Studio rifiuta response_format.type=json_object (HTTP 400): il riepilogo
// NON deve chiedere structuredJson, mentre il normale protocollo agente lo mantiene. Un fake streamer
// registra il flag structuredJson di OGNI richiesta.
test('chat→memoria: il riepilogo NON usa structuredJson, l\'agente sì, e la memoria è persistita', function () use ($mkroot, $mkdb, $rmrf) {
    $root = $mkroot();
    try {
        [$db, $wsId, $sid] = $mkdb($root);
        $memJson = '{"schema_version":1,"objective":"riassunto reale","todos":["x"]}';
        $flags = [];
        $streamer = static function (ProviderRequest $request, callable $onDelta) use ($memJson, &$flags): AIProviderResult {
            $flags[] = $request->structuredJson;
            $onDelta('x', 'content');
            return AIProviderResult::success($memJson);
        };
        // agentEnabled=true e NESSUN decider iniettato → le decisioni del ciclo passano da
        // providerDecider (structuredJson=true); il riepilogo passa structuredJson=false.
        $svc = new CodeChatService($db, null, $streamer, agentEnabled: true);
        $out = $svc->stream($wsId, $sid, 'dove viene gestito il login', static function (): void {});
        assertSame('success', $out['status']);

        // (2) almeno una decisione dell'agente con structuredJson=true
        assertSame(true, in_array(true, $flags, true), 'attesa almeno una richiesta agente structuredJson=true');
        // (1) l'ULTIMA richiesta (il riepilogo, eseguito in finish()) ha structuredJson=false
        assertSame(false, $flags[count($flags) - 1], 'il riepilogo non deve richiedere structuredJson');

        // (3) riepilogo valido persistito, cutoff sull'assistant salvato
        $assistant = $db->fetch("SELECT id FROM code_conversations WHERE role = 'assistant' ORDER BY id DESC");
        $read = (new CodeWorkingMemoryRepository($db))->findForSession($wsId, $sid);
        assertSame(true, is_array($read));
        assertSame('riassunto reale', $read['memory']->objective);
        assertSame((int) $assistant['id'], $read['last_conversation_id']);
    } finally { $rmrf($root); }
});
