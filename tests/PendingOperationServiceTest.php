<?php

declare(strict_types=1);

use App\Core\Code\CodeConversationRepository;
use App\Core\Code\CodeSessionRepository;
use App\Core\Code\CodeWorkspaceRepository;
use App\Core\Code\PendingOperationService;
use App\Core\Database;
use App\Services\MigrationRunner;

$pendingRmrf = static function (string $path) use (&$pendingRmrf): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') $pendingRmrf($path . '/' . $entry);
        }
        @rmdir($path);
    } else {
        @unlink($path);
    }
};

test('spegnimento: conta e annulla tutte le proposte aperte, senza cancellare l’audit', function () use ($pendingRmrf) {
    $root = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/aim_pending_root_' . uniqid('', true);
    $storage = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/aim_pending_storage_' . uniqid('', true);
    $dbPath = sys_get_temp_dir() . '/aim_pending_' . uniqid('', true) . '.sqlite';
    mkdir($root, 0777, true);
    mkdir($storage, 0777, true);

    try {
        $db = new Database($dbPath);
        (new MigrationRunner($db, dirname(__DIR__) . '/database/migrations', dirname(__DIR__) . '/database/seeds'))->run();
        $workspace = (new CodeWorkspaceRepository($db))->authorizeRoot($root);
        $sessionId = (new CodeSessionRepository($db))->create($workspace->id, 'pending');
        $assistantId = (new CodeConversationRepository($db))->appendForWorkspace($sessionId, $workspace->id, 'assistant', 'proposte', 'code');
        $now = date('c');

        $db->execute(
            "INSERT INTO code_patch_operations (operation_id,workspace_id,code_session_id,assistant_conversation_id,patch_digest,status,files_json,created_at,updated_at,expires_at) VALUES (?,?,?,?,?,'proposed','[]',?,?,?)",
            ['op-pending0000001', $workspace->id, $sessionId, $assistantId, str_repeat('a', 64), $now, $now, date('c', time() + 900)]
        );
        $db->execute(
            "INSERT INTO code_command_runs (workspace_id,code_session_id,assistant_conversation_id,command_id,digest,policy_version,program,display_summary,state,truncated,created_at) VALUES (?,?,?,?,?,1,'cat','cat file','pending',0,?)",
            [$workspace->id, $sessionId, $assistantId, 'cmd-pending000001', str_repeat('b', 64), $now]
        );
        $db->execute(
            "INSERT INTO code_processes (workspace_id,code_session_id,assistant_conversation_id,process_id,digest,policy_version,profile_id,program,display_summary,host,port,directory,state,created_at) VALUES (?,?,?,?,?,1,'php-server','php','127.0.0.1:8123','127.0.0.1',8123,'','pending',?)",
            [$workspace->id, $sessionId, $assistantId, 'proc-pending00001', str_repeat('c', 64), $now]
        );
        $db->execute(
            "INSERT INTO code_git_operations (operation_id,workspace_id,code_session_id,assistant_conversation_id,kind,state,digest,fingerprint,plan_json,provenance_json,selected_count,excluded_count,created_at) VALUES (?,?,?,?,?,'pending',?,?,?,'{}',1,0,?)",
            ['git-pending000001', $workspace->id, $sessionId, $assistantId, 'stage', str_repeat('d', 64), str_repeat('e', 64), '{"selected":[]}', $now]
        );

        $service = new PendingOperationService($db, $storage);
        assertSame(4, $service->countAll());
        assertSame(['cancelled' => 4, 'failures' => 0], $service->cancelAll());
        assertSame(0, $service->countAll());
        assertSame('rejected', (string) $db->fetch("SELECT status FROM code_patch_operations WHERE operation_id='op-pending0000001'")['status']);
        assertSame('rejected', (string) $db->fetch("SELECT state FROM code_command_runs WHERE command_id='cmd-pending000001'")['state']);
        assertSame('rejected', (string) $db->fetch("SELECT state FROM code_processes WHERE process_id='proc-pending00001'")['state']);
        assertSame('rejected', (string) $db->fetch("SELECT state FROM code_git_operations WHERE operation_id='git-pending000001'")['state']);
    } finally {
        $pendingRmrf($root);
        $pendingRmrf($storage);
        @unlink($dbPath);
    }
});
