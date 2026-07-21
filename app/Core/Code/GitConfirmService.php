<?php

declare(strict_types=1);

namespace App\Core\Code;

use App\Core\Database;

/** Unico ingresso applicativo alle conferme Git monouso (staging e commit). */
final class GitConfirmService
{
    private readonly GitOperationRepository $repo;

    public function __construct(private readonly Database $db, private readonly GitStageService $stager, private readonly GitService $git)
    {
        $this->repo = new GitOperationRepository($db);
    }

    public static function withDefaults(Database $db): self
    {
        $git = GitService::withDefaults();
        return new self($db, new GitStageService($git, new CodeGitTool($git)), $git);
    }

    public function confirmStage(int $workspaceId, int $sessionId, string $id, string $digest): array
    {
        $this->repo->expire();
        $row = $this->repo->find($id, $workspaceId, $sessionId);
        if ($row === null || $row['kind'] !== 'stage' || $row['state'] !== 'pending') { return $this->fail('not_found', 'Proposta Git non disponibile.'); }
        if (!hash_equals((string)$row['digest'], $digest)) { $this->repo->terminatePending($id,$workspaceId,$sessionId,'denied'); return $this->fail('denied', 'Digest Git non corrispondente.'); }
        $ws = (new CodeWorkspaceRepository($this->db))->findById($workspaceId);
        if ($ws === null || $ws->status !== 'active') { return $this->fail('denied', 'Workspace non disponibile.'); }
        $plan = $this->decodePlan($row, $ws);
        if ($plan === null || !$this->repo->claim($id,$workspaceId,$sessionId,'pending',$digest)) { return $this->fail('stale', 'Proposta Git già consumata o incoerente.'); }
        $result = $this->stager->execute($ws, $plan, $digest, fn(): bool => (new CodeWorkspaceRepository($this->db))->findById($workspaceId)?->status === 'active');
        $state = match ($result->outcome) { GitStageResult::STAGED=>'staged', GitStageResult::STALE=>'stale', GitStageResult::REJECTED=>'denied', default=>'error' };
        $this->repo->finish($id,$workspaceId,$sessionId,$state);
        return ['ok'=>$result->isStaged(),'status'=>$state,'message'=>$result->message,'git'=>['operation_id'=>$id,'state'=>$state,'selected_count'=>$result->stagedCount,'digest'=>$digest]];
    }

    public function reject(int $workspaceId, int $sessionId, string $id): array
    {
        return $this->repo->reject($id,$workspaceId,$sessionId)
            ? ['ok'=>true,'status'=>'rejected','message'=>''] : $this->fail('not_found','Proposta Git non disponibile.');
    }

    /** Crea la seconda proposta, distinta, soltanto dopo staging riuscito. */
    public function proposeCommit(int $workspaceId, int $sessionId, string $stageId, string $message): array
    {
        $message = trim($message);
        if ($message === '' || strlen($message) > 200 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $message)) {
            return $this->fail('denied','Messaggio di commit non valido.');
        }
        $stage = $this->repo->find($stageId,$workspaceId,$sessionId);
        if ($stage === null || $stage['state'] !== 'staged' || $stage['kind'] !== 'stage') { return $this->fail('not_found','Staging confermato non disponibile.'); }
        if ($this->repo->hasCommitChild($stageId,$workspaceId,$sessionId)) { return $this->fail('not_found','Staging già associato a un commit.'); }
        $ws = (new CodeWorkspaceRepository($this->db))->findById($workspaceId);
        try {
            if ($ws === null || !$this->git->isRepository($ws)) { return $this->fail('denied','Repository non disponibile.'); }
            $fingerprint = $this->commitFingerprint($ws, $stage);
        } catch (\Throwable) { return $this->fail('denied','Staging non idoneo al commit.'); }
        $digest = hash('sha256', $workspaceId."\0".$sessionId."\0".$stageId."\0".$message."\0".$fingerprint);
        $row = $this->repo->createCommit($stage,$message,$digest,$fingerprint);
        return ['ok'=>true,'status'=>'commit_pending','message'=>'','git'=>$this->card($row)];
    }

    public function confirmCommit(int $workspaceId, int $sessionId, string $id, string $digest): array
    {
        $this->repo->expire();
        $row = $this->repo->find($id,$workspaceId,$sessionId);
        if ($row === null || $row['kind'] !== 'commit' || $row['state'] !== 'commit_pending') { return $this->fail('not_found','Proposta commit non disponibile.'); }
        if (!hash_equals((string)$row['digest'],$digest)) { $this->repo->terminatePending($id,$workspaceId,$sessionId,'denied'); return $this->fail('denied','Digest commit non corrispondente.'); }
        $ws = (new CodeWorkspaceRepository($this->db))->findById($workspaceId);
        try {
            if ($ws === null || !$this->git->isRepository($ws)) { return $this->fail('denied','Repository non disponibile.'); }
            $currentFingerprint=$this->commitFingerprint($ws,$row);
        } catch (\Throwable) { return $this->fail('denied','Staging non idoneo al commit.'); }
        if (!hash_equals((string)$row['fingerprint'],$currentFingerprint)) { $this->repo->terminatePending($id,$workspaceId,$sessionId,'stale'); return $this->fail('stale','Lo staging è cambiato dopo la proposta.'); }
        if (!$this->repo->claim($id,$workspaceId,$sessionId,'commit_pending',$digest)) { return $this->fail('stale','Proposta commit già consumata.'); }
        $before = $this->git->head($ws);
        $res = $this->git->commit($ws,(string)$row['commit_message']);
        $after = $this->git->head($ws);
        $ok = $res->started && !$res->timedOut && !$res->truncated && $res->exitCode === 0 && $after !== '' && $after !== $before;
        $this->repo->finish($id,$workspaceId,$sessionId,$ok?'committed':'error');
        return ['ok'=>$ok,'status'=>$ok?'committed':'error','message'=>$ok?'':'Commit non riuscito.','git'=>['operation_id'=>$id,'kind'=>'commit','state'=>$ok?'committed':'error','commit'=>$ok?$after:'','commit_message'=>(string)$row['commit_message']]];
    }

    /**
     * Seconda conferma esplicita del flusso: il messaggio mostrato e accettato dall'utente crea la
     * proposta persistente e la consuma subito nello stesso POST. Restano due operazioni/audit
     * distinti (stage e commit), ma nessun terzo clic puramente burocratico.
     */
    public function createCommit(int $workspaceId, int $sessionId, string $stageId, string $message): array
    {
        $proposal = $this->proposeCommit($workspaceId, $sessionId, $stageId, $message);
        if (!($proposal['ok'] ?? false) || !is_array($proposal['git'] ?? null)) {
            return $proposal;
        }

        return $this->confirmCommit(
            $workspaceId,
            $sessionId,
            (string) $proposal['git']['operation_id'],
            (string) $proposal['git']['digest']
        );
    }

    private function decodePlan(array $row, CodeWorkspace $ws): ?GitStagePlan
    {
        $p=json_decode((string)$row['plan_json'],true);
        if (!is_array($p)) return null;
        try { $plan=GitStagePlan::create($ws->id,$p['selected']??[],$p['allowed_not_selected']??[],(int)($p['excluded_count']??0),(string)($p['fingerprint']??''),$ws->resolve('')); }
        catch (\Throwable) { return null; }
        return hash_equals($plan->digest,(string)$row['digest']) ? $plan : null;
    }

    private function commitFingerprint(CodeWorkspace $ws, array $row): string
    {
        $p=json_decode((string)$row['plan_json'],true); $paths=[];
        foreach (($p['selected']??[]) as $e) { if(is_array($e)){ $paths[]=(string)$e['path']; if(($e['orig_path']??null)!==null)$paths[]=(string)$e['orig_path']; } }
        sort($paths,SORT_STRING); $paths=array_values(array_unique($paths));
        $allowed=array_fill_keys($paths,true); $status=$this->git->status($ws);
        if($status->truncated) throw new GitException('Stato troncato.');
        foreach($status->staged() as $entry){
            if(!isset($allowed[$entry->path]) && ($entry->origPath===null || !isset($allowed[$entry->origPath])))
                throw new GitException('Indice contiene percorsi fuori proposta.');
        }
        $idx=$this->git->indexEntries($ws,$paths);
        if($idx['truncated']) throw new GitException('Indice troncato.');
        return hash('sha256',json_encode([$this->git->head($ws),$idx['entries']],JSON_UNESCAPED_SLASHES));
    }

    private function card(array $row): array { return ['operation_id'=>$row['operation_id'],'kind'=>$row['kind'],'state'=>$row['state'],'digest'=>$row['digest'],'selected_count'=>(int)$row['selected_count'],'excluded_count'=>(int)$row['excluded_count'],'commit_message'=>$row['commit_message']]; }
    private function fail(string $status,string $message): array { return ['ok'=>false,'status'=>$status,'message'=>$message,'git'=>null]; }
}
