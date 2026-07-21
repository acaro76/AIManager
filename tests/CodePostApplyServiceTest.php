<?php

declare(strict_types=1);

use App\Core\Code\CodePatchOperationRepository;
use App\Core\Code\CodePostApplyService;
use App\Core\Code\CodeVerificationRunRepository;
use App\Core\Code\CodeWorkspaceRepository;
use App\Core\Code\GitService;
use App\Core\Database;
use App\Services\MigrationRunner;

$removePostApply = static function (string $path) use (&$removePostApply): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') { $removePostApply($path . '/' . $entry); }
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
};

$makePostApply = static function (bool $withGit = false) use ($removePostApply): array {
    $base = sys_get_temp_dir() . '/aimanager_post_apply_' . bin2hex(random_bytes(6));
    mkdir($base, 0700, true);
    if ($withGit) {
        $process = proc_open(
            ['/usr/bin/git', '-c', 'init.defaultBranch=main', 'init', '-q'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $base,
            ['PATH' => '/usr/bin:/bin', 'HOME' => $base, 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_SYSTEM' => '/dev/null'],
        );
        if (is_resource($process)) {
            stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        }
    }
    file_put_contents($base . '/app.py', "print('ok')\n");
    $dbPath = $base . '/test.sqlite';
    $db = new Database($dbPath);
    $root = dirname(__DIR__);
    (new MigrationRunner($db, $root . '/database/migrations', $root . '/database/seeds'))->run();
    $now = date('c');
    $db->execute(
        'INSERT INTO code_workspaces (root_path, name, status, authorized_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$base, 'post', 'active', $now, $now, $now]
    );
    $workspaceId = $db->lastInsertId();
    $db->execute(
        'INSERT INTO code_sessions (workspace_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        [$workspaceId, 'post', 'active', $now, $now]
    );
    $sessionId = $db->lastInsertId();
    $db->execute(
        'INSERT INTO code_conversations (code_session_id, role, content, created_at) VALUES (?, ?, ?, ?)',
        [$sessionId, 'assistant', 'proposta', $now]
    );
    $assistantId = $db->lastInsertId();
    $operationId = 'op-' . bin2hex(random_bytes(12));
    $hash = (string) hash_file('sha256', $base . '/app.py');
    $operations = new CodePatchOperationRepository($db);
    $operations->create($operationId, $workspaceId, $sessionId, $assistantId, str_repeat('a', 64), [[
        'path' => 'app.py',
        'op' => 'create',
        'base_sha256' => null,
        'result_sha256' => $hash,
    ]], 900);
    $operations->transition($operationId, ['proposed'], 'applied', true);

    $cleanup = static function () use ($base, $removePostApply): void { $removePostApply($base); };
    return [$db, $workspaceId, $sessionId, $operationId, $cleanup];
};

test('post-apply: verifica Python e conclude chiaramente una cartella senza Git', function () use ($makePostApply) {
    [$db, $workspaceId, $sessionId, $operationId, $cleanup] = $makePostApply();
    try {
        $service = new CodePostApplyService($db);
        $result = $service->complete($workspaceId, $sessionId, $operationId, true);
        assertSame(true, $result['ok']);
        assertSame('app.py creato.', $result['summary']);
        assertSame('not_repository', $result['git']['status']);
        assertSame('File salvato localmente. Questa cartella non è collegata a Git.', $result['git']['message']);
        assertSame(1, count($result['verifications']));
        assertSame('py-syntax', $result['verifications'][0]['profile']);
        assertSame('superata', $result['verifications'][0]['label']);

        $again = $service->complete($workspaceId, $sessionId, $operationId, true);
        assertSame('superata', $again['verifications'][0]['label']);
        assertSame(1, count((new CodeVerificationRunRepository($db))->listForSession($workspaceId, $sessionId)));
    } finally {
        $cleanup();
    }
});

test('post-apply: in un repository prepara una sola proposta Git senza eseguire staging', function () use ($makePostApply) {
    [$db, $workspaceId, $sessionId, $operationId, $cleanup] = $makePostApply(true);
    try {
        $service = new CodePostApplyService($db);
        $result = $service->complete($workspaceId, $sessionId, $operationId, true);
        assertSame('proposed', $result['git']['status']);
        assertSame('pending', $result['git']['card']['state']);
        assertSame('app.py', $result['git']['card']['selected'][0]['path']);

        $again = $service->complete($workspaceId, $sessionId, $operationId, true);
        assertSame($result['git']['card']['operation_id'], $again['git']['card']['operation_id']);
        $workspace = (new CodeWorkspaceRepository($db))->findById($workspaceId);
        $status = GitService::withDefaults()->status($workspace);
        $app = array_values(array_filter($status->entries, static fn ($entry): bool => $entry->path === 'app.py'));
        assertSame(1, count($app));
        assertSame(false, $app[0]->isStaged());
    } finally {
        $cleanup();
    }
});
