<?php

declare(strict_types=1);

use App\Core\Code\CodeChatSchema;
use App\Core\Code\CodePatchOperationRepository;
use App\Core\Code\CodePatchSchema;
use App\Core\Code\CodePatchStore;
use App\Core\Code\CodeSessionRepository;
use App\Core\Code\CodeWorkspaceRepository;
use App\Core\Database;
use App\Core\Providers\ProviderRequest;
use App\Services\AIProviderResult;
use App\Services\CodeChatService;

// Fase 4 / F4.D — integrazione: un `propose_patch` del ciclo diventa una PROPOSTA persistita e una
// card nel risultato, SENZA toccare alcun file. Con la scrittura disabilitata, nessuna proposta.

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

$mkroot = static function (): string {
    $base = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
    $root = $base . '/aimanager_patchchat_' . uniqid('', true);
    mkdir($root, 0777, true);
    file_put_contents($root . '/config.php', "<?php\nreturn ['debug' => false];\n");
    return $root;
};

$mkdb = static function (string $root): array {
    $path = sys_get_temp_dir() . '/aimanager_patchchat_' . uniqid('', true) . '.sqlite';
    $db = new Database($path);
    $db->pdo()->exec('PRAGMA foreign_keys = ON');
    $db->execute('CREATE TABLE code_workspaces (
        id INTEGER PRIMARY KEY AUTOINCREMENT, root_path TEXT NOT NULL UNIQUE, name TEXT NOT NULL DEFAULT \'\',
        status TEXT NOT NULL DEFAULT \'active\', authorized_at TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
        CHECK(status IN (\'active\',\'revoked\')))');
    CodeChatSchema::createForTests($db);
    CodePatchSchema::createForTests($db);
    $ws = (new CodeWorkspaceRepository($db))->authorizeRoot($root);
    $sid = (new CodeSessionRepository($db))->create($ws->id, 'sessione');
    return [$db, $ws->id, $sid, $path];
};

// Decisore che propone una modifica valida di config.php. Il "provider finale" (streamer) è un
// altro fake che risponde a parole.
$proposeDecider = static function (): callable {
    return static fn (string $system, string $user): string =>
        '{"action":"propose_patch","changes":[{"op":"update","path":"config.php","edits":[{"old":"\'debug\' => false","new":"\'debug\' => true"}]}]}';
};

$fakeStreamer = static function (): callable {
    return static function (ProviderRequest $request, callable $onDelta): AIProviderResult {
        $onDelta('Propongo di attivare il debug in config.php.', 'content');
        return AIProviderResult::success('Propongo di attivare il debug in config.php.');
    };
};

test('chat/proposta: con scrittura abilitata la proposta è persistita e in card, nessun file toccato', function () use ($mkroot, $mkdb, $rmrf, $proposeDecider, $fakeStreamer) {
    $root = $mkroot();
    $db = null; $dbPath = null;
    try {
        [$db, $wsId, $sid, $dbPath] = $mkdb($root);
        $before = file_get_contents($root . '/config.php');
        $storage = $root . '_patchstore';

        $deltas = [];
        $finalProviderCalled = false;
        $svc = new CodeChatService(
            $db,
            streamer: static function () use (&$finalProviderCalled): AIProviderResult {
                $finalProviderCalled = true;
                throw new RuntimeException('Il provider finale non deve essere chiamato per una proposta valida.');
            },
            decider: $proposeDecider(),
            writeEnabled: true,
            patchStorageDir: $storage,
        );
        $out = $svc->stream($wsId, $sid, 'attiva il debug', static function (string $text) use (&$deltas): void {
            $deltas[] = $text;
        });

        assertSame('success', $out['status']);
        assertSame(true, is_array($out['proposal']));
        assertSame(1, count($out['proposal']['files']));
        assertSame('config.php', $out['proposal']['files'][0]['path']);
        assertSame(true, str_contains($out['proposal']['files'][0]['diff'], "+return ['debug' => true];"));
        assertSame(false, $finalProviderCalled);
        assertSame(true, str_contains(implode('', $deltas), 'config.php'));
        assertSame(false, str_contains(strtolower(implode('', $deltas)), 'sola lettura'));

        $assistant = $db->fetch("SELECT content FROM code_conversations WHERE role = 'assistant' ORDER BY id DESC LIMIT 1");
        assertSame(true, str_contains((string) $assistant['content'], 'Controlla il diff'));

        // riga in code_patch_operations, stato proposed, legata al turno assistant
        $opId = (string) $out['proposal']['operation_id'];
        $row = (new CodePatchOperationRepository($db))->find($opId);
        assertSame('proposed', (string) $row['status']);
        assertSame(true, $row['assistant_conversation_id'] !== null);
        assertSame((string) $out['proposal']['patch_digest'], (string) $row['patch_digest']);

        // payload salvato, NESSUN file del workspace modificato
        assertSame(true, (new CodePatchStore($storage))->read($opId) !== null);
        assertSame($before, file_get_contents($root . '/config.php'));

        $rmrf($storage);
    } finally {
        $rmrf($root);
        if (is_string($dbPath)) {
            foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) { if (is_file($f)) { @unlink($f); } }
        }
    }
});

test('chat/continuità: il ciclo riceve percorso, tipo e stato dell\'operazione applicata, senza hash o contenuti', function () use ($mkroot, $mkdb, $rmrf, $proposeDecider) {
    $root = $mkroot();
    $db = null; $dbPath = null;
    try {
        [$db, $wsId, $sid, $dbPath] = $mkdb($root);
        $repo = new CodePatchOperationRepository($db);
        $base = str_repeat('a', 64);
        $result = str_repeat('b', 64);
        $repo->create(
            'op-continuity00001',
            $wsId,
            $sid,
            null,
            str_repeat('c', 64),
            [['path' => 'prova2.txt', 'op' => 'create', 'base_sha256' => null, 'result_sha256' => $result]],
            1800
        );
        assertSame(true, $repo->transition('op-continuity00001', ['proposed'], 'applied', true));

        $decisionPrompt = '';
        $storage = $root . '_patchstore';
        $svc = new CodeChatService(
            $db,
            streamer: static fn (): AIProviderResult => throw new RuntimeException('Provider finale inatteso.'),
            decider: static function (string $system, string $user) use (&$decisionPrompt): string {
                $decisionPrompt = $user;
                return '{"action":"propose_patch","changes":[{"op":"update","path":"config.php","edits":[{"old":"\'debug\' => false","new":"\'debug\' => true"}]}]}';
            },
            writeEnabled: true,
            patchStorageDir: $storage,
        );
        $out = $svc->stream($wsId, $sid, 'scrivi nel file appena creato', static function (): void {});

        assertSame('success', $out['status']);
        assertSame(true, str_contains($decisionPrompt, 'RIFERIMENTO «file appena creato»: prova2.txt'));
        assertSame(true, str_contains($decisionPrompt, 'create prova2.txt — applied'));
        assertSame(false, str_contains($decisionPrompt, $result));
        assertSame(false, str_contains($decisionPrompt, $base));

        $rmrf($storage);
    } finally {
        $rmrf($root);
        if (is_string($dbPath)) {
            foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) { if (is_file($f)) { @unlink($f); } }
        }
    }
});

test('chat/proposta: se la persistenza fallisce non salva né mostra un messaggio di proposta', function () use ($mkroot, $mkdb, $rmrf, $proposeDecider) {
    $root = $mkroot();
    $db = null; $dbPath = null;
    try {
        [$db, $wsId, $sid, $dbPath] = $mkdb($root);
        $db->execute('DROP TABLE code_patch_operations');
        $storage = $root . '_patchstore';
        $deltas = [];
        $svc = new CodeChatService(
            $db,
            streamer: static fn (): AIProviderResult => throw new RuntimeException('Provider finale inatteso.'),
            decider: $proposeDecider(),
            writeEnabled: true,
            patchStorageDir: $storage,
        );
        $out = $svc->stream($wsId, $sid, 'attiva il debug', static function (string $text) use (&$deltas): void {
            $deltas[] = $text;
        });

        assertSame('error', $out['status']);
        assertSame([], $deltas);
        assertSame(null, $out['proposal']);
        assertSame(0, (int) $db->fetch("SELECT COUNT(*) c FROM code_conversations WHERE role = 'assistant'")['c']);
        assertSame([], glob($storage . '/payload/*.json') ?: []);

        $rmrf($storage);
    } finally {
        $rmrf($root);
        if (is_string($dbPath)) {
            foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) { if (is_file($f)) { @unlink($f); } }
        }
    }
});

test('chat/modifica: senza patch strutturata non degrada in diff testuale né mostra Evidenze', function () use ($mkroot, $mkdb, $rmrf) {
    $root = $mkroot();
    $db = null; $dbPath = null;
    try {
        [$db, $wsId, $sid, $dbPath] = $mkdb($root);
        $finalProviderCalled = false;
        $svc = new CodeChatService(
            $db,
            streamer: static function () use (&$finalProviderCalled): AIProviderResult {
                $finalProviderCalled = true;
                return AIProviderResult::success('Ecco un diff testuale non applicabile.');
            },
            decider: static fn (): string => '{"action":"answer"}',
            writeEnabled: true,
            patchStorageDir: $root . '_patchstore',
        );
        $deltas = [];
        $out = $svc->stream($wsId, $sid, 'modifica config.php', static function (string $text) use (&$deltas): void {
            $deltas[] = $text;
        });

        assertSame('error', $out['status']);
        assertSame(false, $finalProviderCalled);
        assertSame([], $deltas);
        assertSame(null, $out['proposal']);
        assertSame(0, (int) $db->fetch("SELECT COUNT(*) c FROM code_conversations WHERE role = 'assistant'")['c']);
    } finally {
        $rmrf($root);
        $rmrf($root . '_patchstore');
        if (is_string($dbPath)) {
            foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) { if (is_file($f)) { @unlink($f); } }
        }
    }
});

test('chat/modifica fallita: conserva provider e modello reali usati dal decisore', function () use ($mkroot, $mkdb, $rmrf) {
    $root = $mkroot();
    $db = null; $dbPath = null;
    try {
        [$db, $wsId, $sid, $dbPath] = $mkdb($root);
        $svc = new CodeChatService(
            $db,
            streamer: static fn (): AIProviderResult => AIProviderResult::success(
                '{"action":"answer"}',
                meta: ['provider' => 'lmstudio', 'model' => 'qwen/qwen3.5-9b']
            ),
            writeEnabled: true,
            patchStorageDir: $root . '_patchstore',
        );
        $out = $svc->stream($wsId, $sid, 'modifica config.php', static function (): void {});

        assertSame('error', $out['status']);
        assertSame('lmstudio', $out['provider']);
        assertSame('qwen/qwen3.5-9b', $out['model']);
    } finally {
        $rmrf($root);
        $rmrf($root . '_patchstore');
        if (is_string($dbPath)) {
            foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) { if (is_file($f)) { @unlink($f); } }
        }
    }
});

test('chat/proposta: persiste il provider reale usato dal decisore', function () use ($mkroot, $mkdb, $rmrf) {
    $root = $mkroot();
    $db = null; $dbPath = null;
    try {
        [$db, $wsId, $sid, $dbPath] = $mkdb($root);
        $decision = 0;
        $svc = new CodeChatService(
            $db,
            streamer: static function () use (&$decision): AIProviderResult {
                $content = $decision++ === 0
                    ? '{"action":"read_file","path":"config.php"}'
                    : '{"action":"propose_file","path":"config.php","content":"<?php\\nreturn [\'debug\' => true];\\n"}';
                return AIProviderResult::success(
                    $content,
                    meta: ['provider' => 'lmstudio', 'model' => 'qwen/qwen3.5-9b']
                );
            },
            writeEnabled: true,
            patchStorageDir: $root . '_patchstore',
        );
        $out = $svc->stream($wsId, $sid, 'modifica config.php', static function (): void {});

        assertSame('success', $out['status']);
        assertSame('lmstudio', $out['provider']);
        assertSame('qwen/qwen3.5-9b', $out['model']);
        $assistant = $db->fetch("SELECT provider FROM code_conversations WHERE role = 'assistant' ORDER BY id DESC LIMIT 1");
        assertSame('lmstudio', (string) $assistant['provider']);
    } finally {
        $rmrf($root);
        $rmrf($root . '_patchstore');
        if (is_string($dbPath)) {
            foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) { if (is_file($f)) { @unlink($f); } }
        }
    }
});

test('chat/proposta: con scrittura DISABILITATA nessuna proposta (propose_patch non ammesso)', function () use ($mkroot, $mkdb, $rmrf, $proposeDecider, $fakeStreamer) {
    $root = $mkroot();
    $db = null; $dbPath = null;
    try {
        [$db, $wsId, $sid, $dbPath] = $mkdb($root);
        $svc = new CodeChatService(
            $db,
            streamer: $fakeStreamer(),
            decider: $proposeDecider(),
            writeEnabled: false,
        );
        $out = $svc->stream($wsId, $sid, 'attiva il debug', static function (): void {});
        // Il ciclo ricade sul single-shot; nessuna proposta.
        assertSame('success', $out['status']);
        assertSame(null, $out['proposal']);
        assertSame(0, (int) $db->fetch('SELECT COUNT(*) c FROM code_patch_operations')['c']);
    } finally {
        $rmrf($root);
        if (is_string($dbPath)) {
            foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) { if (is_file($f)) { @unlink($f); } }
        }
    }
});
