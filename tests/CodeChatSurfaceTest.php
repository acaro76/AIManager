<?php

declare(strict_types=1);

use App\Core\Cancellation\CancellationStore;
use App\Core\Code\CodeChatSchema;
use App\Core\Code\CodeSseWriter;
use App\Core\Code\CodeWorkspace;
use App\Core\Code\SensitivePathPolicy;
use App\Core\Database;

/**
 * F1.5 — superficie chat Code (SSE/UI). Test ISOLATI: viste rese direttamente (senza boot
 * dell'app né DB reale), wiring verificato per ispezione statica, SSE verificato sul writer
 * puro. Nessun server, nessun provider.
 */

$policy = new SensitivePathPolicy();

$renderView = static function (string $relView, array $vars): string {
    extract($vars, EXTR_SKIP);
    ob_start();
    require dirname(__DIR__) . '/app/Views/' . $relView;
    return (string) ob_get_clean();
};

$ws = static fn (int $id, string $root, string $status = 'active'): CodeWorkspace
    => new CodeWorkspace($id, $root, '', $status, $policy);

// DB temporaneo: code_workspaces sempre; lo schema chat solo se richiesto.
$mkdb = static function (string $chat = 'none'): Database {
    $path = sys_get_temp_dir() . '/aimanager_ccsurf_' . uniqid('', true) . '.sqlite';
    $db = new Database($path);
    $db->pdo()->exec('PRAGMA foreign_keys = ON');
    $db->execute('CREATE TABLE code_workspaces (
        id INTEGER PRIMARY KEY AUTOINCREMENT, root_path TEXT NOT NULL UNIQUE, name TEXT NOT NULL DEFAULT \'\',
        status TEXT NOT NULL DEFAULT \'active\', authorized_at TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
        CHECK(status IN (\'active\',\'revoked\')))');
    if ($chat === 'ready') {
        CodeChatSchema::createForTests($db);
    }
    if ($chat === 'incompatible') {
        // tabella omonima ma strutturalmente sbagliata
        $db->execute('CREATE TABLE code_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        $db->execute('CREATE TABLE code_conversations (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        $db->execute('CREATE TABLE code_operation_logs (id INTEGER PRIMARY KEY AUTOINCREMENT)');
    }
    return $db;
};

// --- stato dello schema: /code non deve mai interrogare tabelle assenti ---

test('CodeChatSchema.state: schema assente => missing (nessuna query alle tabelle chat)', function () use ($mkdb) {
    $db = $mkdb('none');
    assertSame(CodeChatSchema::STATE_MISSING, CodeChatSchema::state($db));
});

test('CodeChatSchema.state: schema completo e conforme => ready', function () use ($mkdb) {
    assertSame(CodeChatSchema::STATE_READY, CodeChatSchema::state($mkdb('ready')));
});

test('CodeChatSchema.state: schema omonimo ma incompatibile => incompatible (fail closed)', function () use ($mkdb) {
    assertSame(CodeChatSchema::STATE_INCOMPATIBLE, CodeChatSchema::state($mkdb('incompatible')));
});

test('CodeChatSchema.state: presenza PARZIALE delle tabelle => incompatible', function () use ($mkdb) {
    $db = $mkdb('none');
    $db->execute(CodeChatSchema::tableDdl()['code_sessions']); // solo una delle tre
    assertSame(CodeChatSchema::STATE_INCOMPATIBLE, CodeChatSchema::state($db));
});

// --- vista: i tre stati della chat ---

test('code chat: schema assente => stato non interattivo, nessun form di chat', function () use ($renderView, $ws) {
    $html = $renderView('code/workspace.php', [
        'csrf' => 'TOK', 'workspace' => $ws(1, '/tmp/p'), 'map' => new App\Core\Code\RepoMap([], false), 'error' => null,
        'chatState' => CodeChatSchema::STATE_MISSING, 'sessions' => [], 'session' => null, 'history' => [],
    ]);
    assertSame(true, str_contains($html, 'Chat Code non ancora attivata'));
    assertSame(false, str_contains($html, 'data-code-chat-form')); // niente chat interattiva
    // Anche la creazione resta fail-closed finché lo schema non è disponibile.
    assertSame(false, str_contains($html, '/code/session/create'));
    assertSame(false, str_contains($html, 'data-code-tree'));
});

test('code chat: schema incompatibile => fail closed con messaggio generico', function () use ($renderView, $ws) {
    $html = $renderView('code/workspace.php', [
        'csrf' => 'TOK', 'workspace' => $ws(1, '/tmp/p'), 'map' => new App\Core\Code\RepoMap([], false), 'error' => null,
        'chatState' => CodeChatSchema::STATE_INCOMPATIBLE, 'sessions' => [], 'session' => null, 'history' => [],
    ]);
    assertSame(true, str_contains($html, 'Chat Code non disponibile'));
    assertSame(false, str_contains($html, 'data-code-chat-form'));
    assertSame(false, str_contains($html, 'data-code-tree'));
});

test('code chat: schema ready senza sessione => solo creazione POST, nessuna scrittura su GET', function () use ($renderView, $ws) {
    $html = $renderView('code/workspace.php', [
        'csrf' => 'TOK', 'workspace' => $ws(1, '/tmp/p'), 'map' => new App\Core\Code\RepoMap([], false), 'error' => null,
        'chatState' => CodeChatSchema::STATE_READY, 'sessions' => [], 'session' => null, 'history' => [],
    ]);
    assertSame(true, str_contains($html, 'method="post" action="/code/session/create"'));
    assertSame(true, str_contains($html, 'name="_csrf"'));
    assertSame(false, str_contains($html, 'data-code-chat-form')); // niente chat finche' non c'e' sessione
});

test('code chat: mostra la conversazione senza esporre telemetria di letture o verifiche', function () use ($renderView, $ws) {
    $html = $renderView('code/workspace.php', [
        'csrf' => 'TOK', 'workspace' => $ws(1, '/tmp/p'), 'map' => new App\Core\Code\RepoMap([], false), 'error' => null,
        'chatState' => CodeChatSchema::STATE_READY,
        'sessions' => [['id' => 5, 'status' => 'active']],
        'session' => ['id' => 5, 'status' => 'active'],
        'history' => [
            ['role' => 'user', 'content' => 'dove sta il login'],
            ['id' => 7, 'role' => 'assistant', 'content' => 'in app/Auth/Login.php'],
        ],
        // Anche eventuali metadati storici passati da codice legacy non devono diventare UI.
        'historyEvidence' => [7 => ['citations' => [['kind' => 'read', 'path' => 'app/Auth/Login.php']]]],
        'historyVerifications' => [7 => [['profile' => 'php-lint', 'label' => 'superata']]],
    ]);
    assertSame(true, str_contains($html, 'data-code-chat-form'));
    assertSame(true, str_contains($html, 'code-composer-location'));
    assertSame(true, str_contains($html, 'data-code-provider-live'));
    assertSame(true, str_contains($html, 'data-code-provider-badge'));
    assertSame(true, str_contains($html, 'data-code-chat-attach'));
    assertSame(true, str_contains($html, 'data-code-chat-action'));
    assertSame(false, str_contains($html, 'data-code-chat-stop'));
    assertSame(false, str_contains($html, 'data-code-evidence-summary'));
    assertSame(false, str_contains($html, 'data-code-preview'));
    assertSame(false, str_contains($html, 'code-evidence-trigger'));
    assertSame(false, str_contains($html, 'Evidenze ·'));
    assertSame(false, str_contains($html, 'Attività sui file'));
    assertSame(false, str_contains($html, '>Letto<'));
    assertSame(false, str_contains($html, '>Verifica<'));
    assertSame(true, str_contains($html, 'dove sta il login'));
    assertSame(true, str_contains($html, 'app/Auth/Login.php'));
});

test('code chat: sessione archiviata => sola lettura, nessun form di invio', function () use ($renderView, $ws) {
    $html = $renderView('code/workspace.php', [
        'csrf' => 'TOK', 'workspace' => $ws(1, '/tmp/p'), 'map' => new App\Core\Code\RepoMap([], false), 'error' => null,
        'chatState' => CodeChatSchema::STATE_READY,
        'sessions' => [['id' => 5, 'status' => 'archived']],
        'session' => ['id' => 5, 'status' => 'archived'],
        'history' => [],
    ]);
    assertSame(true, str_contains($html, 'Sessione archiviata'));
    assertSame(false, str_contains($html, 'data-code-chat-form'));
});

test('code chat: accesso cartella revocato conserva cronologia e offre riautorizzazione', function () use ($renderView, $ws) {
    $html = $renderView('code/workspace.php', [
        'csrf' => 'TOK', 'workspace' => $ws(1, '/tmp/p'), 'error' => null,
        'accessRevoked' => true,
        'chatState' => CodeChatSchema::STATE_READY,
        'sessions' => [['id' => 5, 'status' => 'active']],
        'session' => ['id' => 5, 'status' => 'active'],
        'history' => [['role' => 'user', 'content' => 'messaggio conservato']],
    ]);
    assertSame(true, str_contains($html, 'messaggio conservato'));
    assertSame(true, str_contains($html, 'Riautorizza cartella'));
    assertSame(true, str_contains($html, 'data-code-folder-picker'));
    assertSame(false, str_contains($html, 'data-code-chat-form'));
});

test('code chat: output HTML escaped (storico e percorsi)', function () use ($renderView, $ws) {
    $html = $renderView('code/workspace.php', [
        'csrf' => 'TOK', 'workspace' => $ws(1, '/tmp/p'), 'map' => new App\Core\Code\RepoMap([], false), 'error' => null,
        'chatState' => CodeChatSchema::STATE_READY,
        'sessions' => [['id' => 5, 'status' => 'active']],
        'session' => ['id' => 5, 'status' => 'active'],
        'history' => [['role' => 'user', 'content' => '<script>alert(1)</script>']],
    ]);
    assertSame(false, str_contains($html, '<script>alert(1)</script>'));
    assertSame(true, str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'));
});

test('code chat: nessuna superficie LLM nella vista Code', function () use ($renderView, $ws) {
    $html = $renderView('code/workspace.php', [
        'csrf' => 'TOK', 'workspace' => $ws(1, '/tmp/p'), 'map' => new App\Core\Code\RepoMap([], false), 'error' => null,
        'chatState' => CodeChatSchema::STATE_READY,
        'sessions' => [['id' => 5, 'status' => 'active']],
        'session' => ['id' => 5, 'status' => 'active'],
        'history' => [],
    ]);
    foreach (['data-free-chat-session', '/chat/free', '/workspace/chat/stream', 'data-streaming-reasoning', 'project_id'] as $llm) {
        assertSame(false, str_contains($html, $llm), $llm);
    }
});

// --- SSE: mappatura dei canali ed evento finale ---

test('CodeSseWriter: mappa content/reasoning/reset sugli eventi della convenzione esistente', function () {
    $out = '';
    $sse = new CodeSseWriter(static function (string $chunk) use (&$out): void { $out .= $chunk; });
    $sse->delta('ciao', 'content');
    $sse->delta('penso', 'reasoning');
    $sse->delta('', 'reset');

    assertSame(true, str_contains($out, "event: delta\ndata: {\"text\":\"ciao\"}"));
    assertSame(true, str_contains($out, "event: reasoning\ndata: {\"text\":\"penso\"}"));
    assertSame(true, str_contains($out, "event: reset\ndata: []"));
});

test('CodeSseWriter: evento finale done con esito strutturato completo', function () {
    $out = '';
    $sse = new CodeSseWriter(static function (string $chunk) use (&$out): void { $out .= $chunk; });
    $sse->done([
        'status' => 'success',
        'message' => '',
        'provider' => 'lmstudio',
        'model' => 'qwen/qwen3.5-9b',
        'files' => ['read' => ['app/A.php'], 'found' => ['README.md']],
        'limits_hit' => ['scan'],
        'metrics' => ['filesRead' => 1],
        'citations' => [
            ['path' => 'app/A.php', 'kind' => 'read', 'line' => null],
            ['path' => 'README.md', 'kind' => 'found', 'line' => 7],
        ],
        'git_stage' => [
            'operation_id' => 'git-test',
            'kind' => 'stage',
            'state' => 'pending',
            'digest' => 'digest-test',
            'selected' => [['path' => 'README.md', 'status' => 'modificato']],
        ],
    ]);

    assertSame(true, str_contains($out, 'event: done'));
    $json = json_decode(trim(substr($out, strpos($out, 'data: ') + 6)), true);
    assertSame('success', $json['status']);
    assertSame('lmstudio', $json['provider']);
    assertSame('qwen/qwen3.5-9b', $json['model']);
    assertSame(['app/A.php'], $json['files']['read']);
    assertSame(['README.md'], $json['files']['found']);
    assertSame(['scan'], $json['limits_hit']);
    assertSame(1, $json['metrics']['filesRead']);
    assertSame('app/A.php', $json['citations'][0]['path']);
    assertSame(7, $json['citations'][1]['line']);
    assertSame('git-test', $json['git_stage']['operation_id']);
    assertSame('pending', $json['git_stage']['state']);
    assertSame('README.md', $json['git_stage']['selected'][0]['path']);
});

test('CodeSseWriter: done non-success comunica lo status (il client scarta il parziale)', function () {
    $out = '';
    $sse = new CodeSseWriter(static function (string $chunk) use (&$out): void { $out .= $chunk; });
    $sse->done([
        'status' => 'cancelled', 'message' => 'Richiesta interrotta.', 'provider' => '',
        'files' => ['read' => [], 'found' => []], 'limits_hit' => [], 'metrics' => [],
    ]);
    assertSame(true, str_contains($out, '"status":"cancelled"'));
});

test('CodeSseWriter: error non espone dettagli interni', function () {
    $out = '';
    $sse = new CodeSseWriter(static function (string $chunk) use (&$out): void { $out .= $chunk; });
    $sse->error('Errore interno durante la richiesta Code.');
    assertSame(true, str_contains($out, 'event: error'));
    assertSame(true, str_contains($out, '"status":"error"'));
});

// --- Stop: cancella il request id giusto, senza toccare altre sessioni ---

test('Stop: cancella SOLO il request_id indicato', function () {
    $store = new CancellationStore(sys_get_temp_dir() . '/aimanager_codestop_' . uniqid('', true));
    $mine = 'code-req-attiva-01';
    $other = 'code-req-altrui-02';
    $tokenMine = $store->token($mine);
    $tokenOther = $store->token($other);

    $store->cancel($mine);

    assertSame(true, $tokenMine->isCancelled());
    assertSame(false, $tokenOther->isCancelled()); // nessuna interferenza con altre richieste

    $store->clear($mine); // pulizia a fine richiesta
    assertSame(false, $tokenMine->isCancelled());
});

test('CodeController: usa il lifecycle atomico e rifiuta lo Stop tardivo', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/CodeController.php');
    assertSame(true, str_contains($source, '->begin($requestId)'));
    assertSame(true, str_contains($source, '->finish($requestId)'));
    assertSame(true, str_contains($source, '->cancelPendingOrActive($requestId)'));
    assertSame(true, str_contains($source, '$accepted ? 200 : 409'));
});

test('CodeSseWriter: byte UTF-8 non validi non rompono la serializzazione', function () {
    $out = '';
    $sse = new CodeSseWriter(static function (string $chunk) use (&$out): void { $out .= $chunk; });
    // testo con byte invalidi, come puo' arrivare dal contenuto di un file
    $sse->delta("ciao \xFF\xFE mondo", 'content');

    assertSame(true, str_contains($out, 'event: delta'));
    // la riga data: esiste, non e' vuota ed e' JSON valido
    $line = trim(substr($out, strpos($out, 'data: ') + 6));
    assertSame(true, $line !== '');
    $decoded = json_decode($line, true);
    assertSame(true, is_array($decoded), 'data: deve essere JSON valido');
    assertSame(true, array_key_exists('text', $decoded));
});

test('CodeSseWriter: done con byte invalidi resta serializzabile e completo', function () {
    $out = '';
    $sse = new CodeSseWriter(static function (string $chunk) use (&$out): void { $out .= $chunk; });
    $sse->done([
        'status' => 'error',
        'message' => "guasto \xFF invalido",
        'provider' => '',
        'files' => ['read' => ["bad\xFF.php"], 'found' => []],
        'limits_hit' => [],
        'metrics' => [],
    ]);
    $line = trim(substr($out, strpos($out, 'data: ') + 6));
    $decoded = json_decode($line, true);
    assertSame(true, is_array($decoded));
    assertSame('error', $decoded['status']);
});

// --- validazione server-side del request_id ---

test('request_id: il controller valida il formato di CancellationStore', function () {
    $c = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/CodeController.php');
    // pattern esplicito, coerente con CancellationStore::sanitize
    assertSame(true, str_contains($c, "'/^[A-Za-z0-9_-]{12,80}$/'"));
    assertSame(true, str_contains($c, 'isValidRequestId($requestId)'));
    // chat: id invalido => 422 e nessun service avviato
    assertSame(true, str_contains($c, '$this->streamHeaders(422);'));
    // stop: id invalido => JSON 422
    assertSame(true, str_contains($c, "'Identificativo di richiesta non valido.'], 422)"));
});

test('request_id: vuoto, corto, lungo e caratteri non ammessi sono rifiutati', function () {
    $valid = static fn (string $id): bool => preg_match('/^[A-Za-z0-9_-]{12,80}$/', $id) === 1;

    assertSame(false, $valid(''));                          // vuoto
    assertSame(false, $valid('code-1'));                    // troppo corto (< 12)
    assertSame(false, $valid(str_repeat('a', 81)));         // troppo lungo (> 80)
    assertSame(false, $valid('code-req-con spazio'));       // spazio
    assertSame(false, $valid('code-req/../etc'));           // caratteri non ammessi
    assertSame(false, $valid("code-req\n-newline"));        // newline

    assertSame(true, $valid('code-req-valida-01'));         // ok
    assertSame(true, $valid(str_repeat('a', 12)));          // minimo ammesso
    assertSame(true, $valid(str_repeat('a', 80)));          // massimo ammesso
});

test('request_id: un id fuori formato NON sarebbe cancellabile (motivo del rifiuto)', function () {
    $store = new CancellationStore(sys_get_temp_dir() . '/aimanager_codeid_' . uniqid('', true));
    $short = 'code-1'; // < 12 caratteri: CancellationStore lo riduce a stringa vuota
    $token = $store->token($short);
    $store->cancel($short);
    assertSame(false, $token->isCancelled()); // lo Stop sarebbe silenziosamente inefficace
});

// --- wiring: route e CSRF ---

test('code wiring: le route della chat Code sono registrate (sessione solo via POST)', function () {
    $routes = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
    assertSame(true, str_contains($routes, "\$router->post('/code/chat', [CodeController::class, 'chat'])"));
    assertSame(true, str_contains($routes, "\$router->post('/code/chat/stop', [CodeController::class, 'stopChat'])"));
    assertSame(true, str_contains($routes, "\$router->post('/code/session/create', [CodeController::class, 'createSession'])"));
    // nessuna creazione di sessione su GET (niente scritture implicite)
    assertSame(false, str_contains($routes, "\$router->get('/code/session/create'"));
});

test('code wiring: tutte le azioni POST Code sono protette da CSRF e usano lo scope', function () {
    $c = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/CodeController.php');
    // picker, open, revoke, create, correzione titolo, chat, stop + patch apply/reject/rollback (Fase 4)
    // + command confirm/reject/stop (Fase 6) + process confirm/reject/stop (Fase 7).
    assertSame(22, substr_count($c, '$this->guard($request);'));
    assertSame(true, str_contains($c, 'public function chat(Request $request): never'));
    assertSame(true, str_contains($c, 'public function stopChat(Request $request): never'));
    assertSame(true, str_contains($c, 'public function createSession(Request $request): never'));
    // Fase 4: la scrittura passa SEMPRE da POST + CSRF (mai su iniziativa del modello).
    assertSame(true, str_contains($c, 'public function applyPatch(Request $request): never'));
    assertSame(true, str_contains($c, 'public function rejectPatch(Request $request): never'));
    assertSame(true, str_contains($c, 'public function rollbackPatch(Request $request): never'));
    // Fase 6: l'esecuzione di un comando passa SEMPRE da POST + CSRF, mai su iniziativa del modello.
    assertSame(true, str_contains($c, 'public function confirmCommand(Request $request): never'));
    assertSame(true, str_contains($c, 'public function rejectCommand(Request $request): never'));
    assertSame(true, str_contains($c, 'public function stopCommand(Request $request): never'));
    // Fase 7: l'avvio/arresto di un processo passa SEMPRE da POST + CSRF, mai su iniziativa del modello.
    assertSame(true, str_contains($c, 'public function confirmProcess(Request $request): never'));
    assertSame(true, str_contains($c, 'public function rejectProcess(Request $request): never'));
    assertSame(true, str_contains($c, 'public function stopProcess(Request $request): never'));
    assertSame(true, str_contains($c, 'public function confirmGitStage(Request $request): never'));
    assertSame(true, str_contains($c, 'public function confirmGitCommit(Request $request): never'));
    // selezione della sessione SEMPRE scoped al workspace
    assertSame(true, str_contains($c, 'findForWorkspace($sessionId, $workspace->id)'));
    assertSame(true, str_contains($c, 'historyForWorkspace($sessionId, $workspace->id'));
    // le tabelle chat si toccano SOLO se lo schema e' ready
    assertSame(true, str_contains($c, 'if ($chatState === CodeChatSchema::STATE_READY) {'));
});

test('code wiring: il client SSE Code non riusa nulla della chat LLM', function () {
    $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/code-chat.js');
    assertSame(true, str_contains($js, "fetch('/code/chat'"));
    assertSame(true, str_contains($js, "fetch('/code/chat/stop'"));
    foreach (['/workspace/chat/stream', '/workspace/chat/stop', 'data-free-chat-session', 'project_id'] as $llm) {
        assertSame(false, str_contains($js, $llm), $llm);
    }
});

test('code client: lo Stop usa AbortController e tratta l\'abort come cancellazione', function () {
    $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/code-chat.js');
    // un controller per richiesta, passato alla fetch dello stream
    assertSame(true, str_contains($js, 'new AbortController()'));
    assertSame(true, str_contains($js, 'signal: controller.signal'));
    // Stop: cattura l'id PRIMA di abortire, abortisce subito, poi POST best-effort
    assertSame(true, str_contains($js, 'const id = activeRequestId;'));
    assertSame(true, str_contains($js, 'controller.abort();'));
    // l'abort e' una cancellazione, non un errore, e il parziale viene rimosso
    assertSame(true, str_contains($js, "error.name === 'AbortError'"));
    assertSame(true, str_contains($js, "aborted ? 'Richiesta interrotta.'"));
    assertSame(true, str_contains($js, "aborted ? 'cancelled' : 'error'"));
    // il cleanup non interferisce con una richiesta piu' recente
    assertSame(true, str_contains($js, 'if (activeRequestId === id) {'));
});

test('code client: la proposta Git live costruisce la card azionabile dal payload SSE', function () {
    $js = file_get_contents(dirname(__DIR__) . '/public/assets/js/code-chat.js');
    assertSame(true, str_contains($js, 'if (payload.git_stage && assistant)'));
    assertSame(true, str_contains($js, 'buildGitCard(payload.git_stage)'));
    assertSame(true, str_contains($js, "yes.dataset.codeGitConfirm=''"));
    assertSame(true, str_contains($js, "no.dataset.codeGitReject=''"));
    assertSame(true, str_contains($js, "function buildGitCommitForm(suggestedMessage='')"));
    assertSame(true, str_contains($js, "input.value=String(suggestedMessage||'')"));
    assertSame(true, str_contains($js, "setGitCardState(card,'staged')"));
    assertSame(true, str_contains($js, "setGitCardState(card,'rejected')"));
    assertSame(true, str_contains($js, "committed:'Completato'"));
    assertSame(true, str_contains($js, "'File da mettere in stage'"));
    assertSame(false, str_contains($js, "'Staging Git'"));
    assertSame(false, str_contains($js, 'preesistente'));
    assertSame(true, str_contains($js, 'Proposta rifiutata. Nessuna modifica eseguita.'));
    assertSame(false, str_contains($js, 'if(r.ok)card.remove()'));
    assertSame(false, str_contains($js, 'window.prompt('));
});

test('code storico: staging concluso offre il messaggio commit inline senza finestre', function () {
    $view = file_get_contents(dirname(__DIR__) . '/app/Views/code/_chat.php');
    assertSame(true, str_contains($view, "\$state === 'staged'"));
    assertSame(true, str_contains($view, 'data-code-git-commit-message'));
    assertSame(true, str_contains($view, 'data-code-git-commit-create'));
    assertSame(true, str_contains($view, "\$card['suggested_message'] ?? ''"));
    assertSame(true, str_contains($view, 'Proposta rifiutata. Nessuna modifica eseguita.'));
    assertSame(true, str_contains($view, 'File da mettere in stage'));
    assertSame(false, str_contains($view, 'preesistente'));
});

test('code client: un evento SSE error e\' terminale e conserva il messaggio del server', function () {
    $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/code-chat.js');
    $errorBlock = substr($js, (int) strpos($js, "if (name === 'error')"), 300);
    assertSame(true, str_contains($errorBlock, 'completed = true;'));
    assertSame(true, str_contains($errorBlock, "turnMessage(payload.message, 'error');"));
});

test('patch applicata: il client completa verifica e Git senza un nuovo prompt', function () {
    $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/code-chat.js');
    assertSame(true, str_contains($js, "patchRequest('/code/patch/complete', card)"));
    assertSame(true, str_contains($js, 'renderAppliedCompletion(card, res.completion'));
    assertSame(true, str_contains($js, 'completeHistoricalPatches()'));
    assertSame(false, str_contains($js, 'compactAppliedPatch(card)'));
});

test('code client: il request_id generato rispetta il formato di CancellationStore', function () {
    // stessa costruzione del client: prefisso + random + padding, troncata a 40
    for ($i = 0; $i < 20; $i++) {
        $rand = base_convert((string) random_int(100000, 999999), 10, 36) . base_convert((string) time(), 10, 36);
        $id = substr('code-' . $rand . '000000000000', 0, 40);
        assertSame(1, preg_match('/^[A-Za-z0-9_-]{12,80}$/', $id), $id);
    }
});

test('code assets: code-chat.js ha un cache busting DEDICATO (non quello di app.js)', function () {
    $layout = (string) file_get_contents(dirname(__DIR__) . '/app/Views/layouts/app.php');
    // la versione e' calcolata dal file code-chat.js, non da app.js
    assertSame(true, str_contains($layout, "\$codeJsPath = \$app->root . '/public/assets/js/code-chat.js';"));
    assertSame(true, str_contains($layout, '$codeJsVersion'));
    assertSame(true, str_contains($layout, 'code-chat.js?v=<?= View::e($codeJsVersion) ?>'));
    // l'asset Code NON usa piu' $jsVersion (derivato da app.js)
    assertSame(false, str_contains($layout, 'code-chat.js?v=<?= View::e($jsVersion) ?>'));
});
