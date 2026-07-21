<?php

declare(strict_types=1);

namespace App\Core\Code;

use App\Core\Database;

final class GitOperationRepository
{
    public const TTL_SECONDS = 900;

    public function __construct(private readonly Database $db) {}

    /** @return array<string,string> path => code|preexisting */
    public function provenance(int $workspaceId, int $sessionId, array $paths): array
    {
        $code = [];
        if ($this->db->fetch("SELECT name FROM sqlite_master WHERE type='table' AND name='code_patch_operations'") === null) {
            return array_fill_keys($paths, 'preexisting');
        }
        $rows = $this->db->fetchAll(
            "SELECT files_json FROM code_patch_operations WHERE workspace_id = ? AND code_session_id = ? AND status = 'applied'",
            [$workspaceId, $sessionId]
        );
        foreach ($rows as $row) {
            $files = json_decode((string) $row['files_json'], true);
            if (!is_array($files)) { continue; }
            foreach ($files as $file) {
                if (is_array($file) && is_string($file['path'] ?? null)) { $code[$file['path']] = true; }
            }
        }
        $out = [];
        foreach ($paths as $path) { $out[$path] = isset($code[$path]) ? 'code' : 'preexisting'; }
        return $out;
    }

    public function createStage(int $workspaceId, int $sessionId, int $assistantId, GitStagePlan $plan): array
    {
        $id = 'git-' . bin2hex(random_bytes(12));
        $planJson = json_encode([
            'selected' => $plan->selected,
            'allowed_not_selected' => $plan->allowedNotSelected,
            'excluded_count' => $plan->excludedCount,
            'fingerprint' => $plan->fingerprint,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $provenance = $this->provenance($workspaceId, $sessionId, $plan->paths());
        $this->db->execute(
            "INSERT INTO code_git_operations
             (operation_id,workspace_id,code_session_id,assistant_conversation_id,kind,state,digest,fingerprint,plan_json,provenance_json,selected_count,excluded_count,created_at)
             VALUES (?,?,?,?,?,'pending',?,?,?,?,?,?,?)",
            [$id,$workspaceId,$sessionId,$assistantId,'stage',$plan->digest,$plan->fingerprint,$planJson,
             json_encode($provenance, JSON_THROW_ON_ERROR),$plan->selectedCount(),$plan->excludedCount,date('c')]
        );
        return $this->find($id, $workspaceId, $sessionId) ?? throw new \RuntimeException('Proposta Git non persistita.');
    }

    public function find(string $id, int $workspaceId, int $sessionId): ?array
    {
        return $this->db->fetch('SELECT * FROM code_git_operations WHERE operation_id=? AND workspace_id=? AND code_session_id=?', [$id,$workspaceId,$sessionId]);
    }

    /** La conclusione post-applicazione può essere richiamata più volte senza duplicare la proposta. */
    public function findStageForAssistant(int $workspaceId, int $sessionId, int $assistantId): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM code_git_operations
             WHERE workspace_id=? AND code_session_id=? AND assistant_conversation_id=? AND kind='stage'
             ORDER BY id DESC LIMIT 1",
            [$workspaceId,$sessionId,$assistantId]
        );
    }

    public function hasCommitChild(string $stageId, int $workspaceId, int $sessionId): bool
    {
        return $this->db->fetch(
            "SELECT id FROM code_git_operations WHERE parent_operation_id=? AND workspace_id=? AND code_session_id=? AND kind='commit' AND state IN ('commit_pending','running','committed') LIMIT 1",
            [$stageId,$workspaceId,$sessionId]
        ) !== null;
    }

    public function claim(string $id, int $workspaceId, int $sessionId, string $from, string $digest): bool
    {
        return $this->db->execute(
            'UPDATE code_git_operations SET state=\'running\', confirmed_at=? WHERE operation_id=? AND workspace_id=? AND code_session_id=? AND state=? AND digest=?',
            [date('c'),$id,$workspaceId,$sessionId,$from,$digest]
        ) === 1;
    }

    public function finish(string $id, int $workspaceId, int $sessionId, string $state): void
    {
        $this->db->execute('UPDATE code_git_operations SET state=?, finished_at=? WHERE operation_id=? AND workspace_id=? AND code_session_id=? AND state=\'running\'', [$state,date('c'),$id,$workspaceId,$sessionId]);
    }

    public function reject(string $id, int $workspaceId, int $sessionId): bool
    {
        return $this->db->execute('UPDATE code_git_operations SET state=\'rejected\', finished_at=? WHERE operation_id=? AND workspace_id=? AND code_session_id=? AND state IN (\'pending\',\'commit_pending\')', [date('c'),$id,$workspaceId,$sessionId]) === 1;
    }

    public function terminatePending(string $id,int $workspaceId,int $sessionId,string $state): void
    {
        if(!in_array($state,['denied','stale','error'],true)) throw new \InvalidArgumentException('Stato Git terminale non ammesso.');
        $this->db->execute("UPDATE code_git_operations SET state=?, finished_at=? WHERE operation_id=? AND workspace_id=? AND code_session_id=? AND state IN ('pending','commit_pending')",[$state,date('c'),$id,$workspaceId,$sessionId]);
    }

    public function createCommit(array $stageRow, string $message, string $digest, string $fingerprint): array
    {
        $id = 'git-' . bin2hex(random_bytes(12));
        $this->db->execute(
            "INSERT INTO code_git_operations
             (operation_id,workspace_id,code_session_id,assistant_conversation_id,kind,state,digest,fingerprint,plan_json,provenance_json,commit_message,parent_operation_id,selected_count,excluded_count,created_at)
             VALUES (?,?,?,?,?,'commit_pending',?,?,?,?,?,?,?,?,?)",
            [$id,$stageRow['workspace_id'],$stageRow['code_session_id'],$stageRow['assistant_conversation_id'],'commit',$digest,$fingerprint,
             $stageRow['plan_json'],$stageRow['provenance_json'],$message,$stageRow['operation_id'],$stageRow['selected_count'],$stageRow['excluded_count'],date('c')]
        );
        return $this->find($id, (int)$stageRow['workspace_id'], (int)$stageRow['code_session_id']) ?? throw new \RuntimeException('Proposta commit non persistita.');
    }

    public function expire(): void
    {
        $cutoff = date('c', time() - self::TTL_SECONDS);
        $this->db->execute("UPDATE code_git_operations SET state='expired', finished_at=? WHERE state IN ('pending','commit_pending') AND created_at < ?", [date('c'),$cutoff]);
    }

    /** @return array<int,list<array<string,mixed>>> */
    public function forHistory(int $workspaceId,int $sessionId): array
    {
        $rows=$this->db->fetchAll('SELECT * FROM code_git_operations WHERE workspace_id=? AND code_session_id=? AND assistant_conversation_id IS NOT NULL ORDER BY id',[$workspaceId,$sessionId]);
        $advancedParents=[];
        foreach($rows as $row){
            if($row['kind']==='commit' && in_array($row['state'],['commit_pending','running','committed'],true) && is_string($row['parent_operation_id']) && $row['parent_operation_id']!==''){
                $advancedParents[$row['parent_operation_id']]=true;
            }
        }
        $out=[];
        foreach($rows as $row){
            if($row['kind']==='stage' && isset($advancedParents[$row['operation_id']])){continue;}
            if($row['kind']==='commit' && !in_array($row['state'],['commit_pending','running','committed'],true)){continue;}
            $plan=json_decode((string)$row['plan_json'],true);$prov=json_decode((string)$row['provenance_json'],true);$selected=is_array($plan)?($plan['selected']??[]):[];$out[(int)$row['assistant_conversation_id']][]=[
            'operation_id'=>$row['operation_id'],'kind'=>$row['kind'],'state'=>$row['state'],'digest'=>$row['digest'],
            'selected'=>$selected,'provenance'=>is_array($prov)?$prov:[],
            'excluded_count'=>(int)$row['excluded_count'],'commit_message'=>$row['commit_message'],
            'parent_operation_id'=>$row['parent_operation_id'],
            'suggested_message'=>GitStagePlan::suggestedCommitMessage(is_array($selected)?$selected:[]),
        ];}
        return $out;
    }
}
