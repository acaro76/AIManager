<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 8 / esecutore dello staging selettivo. Servizio APPLICATIVO dedicato, distinto dal ciclo
 * agente e dai comandi generici: ricevuto un {@see GitStagePlan} già costruito server-side, RIVALIDA
 * integralmente proposta e stato reale e — solo se tutto combacia — esegue lo staging confinato.
 *
 * NON è raggiungibile da UI/route in questa tranche. Nessuna persistenza, nessuna conferma HTTP,
 * nessuna operazione Git modificante oltre a UNA `git add` confinata su un INDICE DI QUARANTENA
 * separato dal lock reale. Nessun reset/restore/checkout: l'atomicità è data preparando il nuovo
 * indice fuori da quello reale e sostituendolo con un rename atomico solo dopo il successo totale;
 * ogni fallimento lascia l'indice reale intatto.
 *
 * Ordine (punto di sicurezza): prima i controlli non mutanti e la validazione del LAYOUT dell'indice
 * modificabile (solo `<root>/.git/index` reale, mai worktree collegati/gitdir esterne/symlink); poi il
 * claim O_EXCL del lock; e SOLO SOTTO LOCK la rivalidazione definitiva (stato, piano, digest,
 * fingerprint), la preparazione della quarantena, `git add`, la verifica e il commit atomico.
 */
final class GitStageService
{
    /** Tetto dell'osservazione di rivalidazione (non forwardata: serve solo internamente). */
    private const REVALIDATE_MAX = 8192;

    /** @var callable():void seam di test (default no-op): invocato tra i controlli preliminari e il lock. */
    private $beforeLock;

    /**
     * @param (callable():void)|null $beforeLock seam confinato per i test (race indice pre-lock); mai dal modello.
     */
    public function __construct(
        private readonly GitService $git,
        private readonly CodeGitTool $tool,
        ?callable $beforeLock = null,
    ) {
        $this->beforeLock = $beforeLock ?? static function (): void {};
    }

    public static function withDefaults(): self
    {
        $git = GitService::withDefaults();

        return new self($git, new CodeGitTool($git));
    }

    /**
     * Rivalida ed esegue lo staging del piano. Ritorna sempre un {@see GitStageResult} tipizzato; una
     * rivalidazione fallita non esegue ALCUNA operazione Git modificante e non lascia stati parziali.
     *
     * @param callable():bool|null $isActive verifica di attività/revoca (riletta dal chiamante); null =
     *        usa solo lo stato dello snapshot.
     */
    public function execute(CodeWorkspace $workspace, GitStagePlan $plan, string $expectedDigest, ?callable $isActive = null): GitStageResult
    {
        // --- Controlli PRELIMINARI non mutanti -------------------------------------------------------
        if ($workspace->status !== 'active' || ($isActive !== null && !$isActive())) {
            return GitStageResult::rejected('Workspace non attivo o revocato.');
        }
        if ($plan->workspaceId !== $workspace->id) {
            return GitStageResult::rejected('La proposta non appartiene a questo workspace.');
        }
        if (!hash_equals($plan->digest, $expectedDigest)) {
            return GitStageResult::rejected('Digest della proposta non corrispondente.');
        }
        try {
            if (!$this->git->isRepository($workspace)) {
                return GitStageResult::rejected('Repository non valido o top-level non coincidente con la root Code.');
            }
        } catch (CodeWorkspaceException $e) {
            return GitStageResult::rejected('Workspace non accessibile.');
        }

        // --- Validazione del LAYOUT dell'indice MODIFICABILE (punto sicurezza) ------------------------
        // Solo `<root>/.git/index` reale è ammesso per lo staging. Worktree collegati, `.git` file,
        // gitdir esterne, symlink su indice o parent → rejected SENZA scrivere nulla fuori root.
        $indexPath = $this->modifiableIndexPath($workspace);
        if ($indexPath === null) {
            return GitStageResult::rejected('Layout Git non idoneo allo staging (indice non confinato in <root>/.git).');
        }

        // Seam di test: cambia lo stato/indice tra i controlli preliminari e l'acquisizione del lock.
        ($this->beforeLock)();

        // --- Claim del lock PRIMA della rivalidazione definitiva -------------------------------------
        $lockPath = $indexPath . '.lock';
        $lock = @fopen($lockPath, 'xb');
        if ($lock === false) {
            return GitStageResult::error('Indice Git occupato o non scrivibile: staging non eseguito.');
        }
        @fclose($lock);

        $committed = false;
        $quarantineDir = $this->quarantineDir(dirname($indexPath));
        if ($quarantineDir === null) {
            @unlink($lockPath);
            return GitStageResult::error('Indice temporaneo non disponibile: staging non eseguito.');
        }
        $quarantineIndex = $quarantineDir . '/index';
        try {
            // --- Rivalidazione DEFINITIVA sotto lock (l'indice reale è ora protetto) ------------------
            try {
                $realStatus = $this->git->status($workspace);
            } catch (CodeWorkspaceException $e) {
                return GitStageResult::rejected('Workspace non accessibile.');
            } catch (GitException $e) {
                return GitStageResult::error('Lettura dello stato Git non riuscita.');
            }
            if ($realStatus->truncated) {
                return GitStageResult::error('Stato Git parziale (troncato): staging non eseguito.');
            }

            $fresh = $this->tool->proposeStage($workspace, $plan->paths(), self::REVALIDATE_MAX);
            if ($fresh['plan'] === null) {
                return GitStageResult::rejected('Rivalidazione fallita: i percorsi non sono più ammissibili o sicuri.');
            }
            /** @var GitStagePlan $freshPlan */
            $freshPlan = $fresh['plan'];
            // Digest + fingerprint identici ⇒ nessun percorso aggiunto/rimosso/sostituito e stato invariato.
            if (!hash_equals($plan->digest, $freshPlan->digest) || !hash_equals($plan->fingerprint, $freshPlan->fingerprint)) {
                return GitStageResult::stale('Lo stato del repository è cambiato dopo la proposta.', $plan->digest, $plan->fingerprint);
            }

            // --- Preparazione della QUARANTENA -------------------------------------------------------
            // Il lock reale e l'indice temporaneo devono essere file DISTINTI: Git riserva il nome
            // `index.lock` e non può usarlo come GIT_INDEX_FILE. Una directory privata separata
            // elimina l'ambiguità mantenendo il lock reale fino al rename atomico finale.
            if (is_file($indexPath)) {
                if (!@copy($indexPath, $quarantineIndex)) {
                    return GitStageResult::error('Copia dell\'indice non riuscita: indice reale invariato.');
                }
                @chmod($quarantineIndex, fileperms($indexPath) & 0777);
                // Una copia appena creata può far apparire "pulita" una modifica stessa dimensione
                // e stesso timestamp (racy-clean). Retrodatare soltanto l'indice di quarantena
                // obbliga Git a confrontare il contenuto; worktree e indice reale restano intatti.
                if (!@touch($quarantineIndex, 1)) {
                    return GitStageResult::error('Preparazione dell\'indice non riuscita: indice reale invariato.');
                }
            } else {
                $init = $this->git->initEmptyIndex($workspace, $quarantineIndex);
                if (!$init->ok()) {
                    return GitStageResult::error('Inizializzazione dell\'indice di quarantena non riuscita.');
                }
            }

            // --- Pathspec con ENTRAMBI i capi rename/copy (punto 4) -----------------------------------
            $pathspecs = $this->executionPathspecs($workspace, $freshPlan);
            if ($pathspecs === null) {
                return GitStageResult::error('Costruzione dei pathspec non riuscita: indice reale invariato.');
            }

            // --- UNICA operazione modificante, confinata alla quarantena ------------------------------
            $add = $this->git->addPaths($workspace, $pathspecs, $quarantineIndex);
            if (!$add->started || $add->timedOut || $add->truncated || $add->exitCode !== 0) {
                return GitStageResult::error('Operazione di staging non riuscita: indice reale invariato.', $plan->digest, $plan->fingerprint);
            }

            // --- Verifica sull'indice di QUARANTENA (mai il reale) ------------------------------------
            try {
                $tempStatus = $this->git->status($workspace, $quarantineIndex);
            } catch (GitException $e) {
                return GitStageResult::error('Verifica dello staging non riuscita: indice reale invariato.');
            }
            if ($tempStatus->truncated) {
                return GitStageResult::error('Verifica dello staging parziale (troncata): indice reale invariato.');
            }

            $check = $this->verifyStaged($freshPlan, $realStatus, $tempStatus);
            if ($check !== null) {
                return GitStageResult::error($check);
            }

            // Trasferisce l'indice verificato nel lock già posseduto; soltanto dopo avviene il rename
            // atomico lock→index. Nessun istante espone un indice parziale.
            if (!@copy($quarantineIndex, $lockPath)) {
                return GitStageResult::error('Preparazione finale dell\'indice non riuscita: indice reale invariato.');
            }
            if (is_file($indexPath)) {
                @chmod($lockPath, fileperms($indexPath) & 0777);
            }

            // --- Commit ATOMICO lock→index (pattern nativo). Nessun reset. ----------------------------
            if (!@rename($lockPath, $indexPath)) {
                return GitStageResult::error('Sostituzione atomica dell\'indice non riuscita: indice reale invariato.');
            }
            $committed = true;

            return GitStageResult::staged($freshPlan->paths(), $freshPlan->excludedCount, $freshPlan->digest, $freshPlan->fingerprint);
        } finally {
            if (!$committed && is_file($lockPath)) {
                @unlink($lockPath);
            }
            foreach ([$quarantineIndex . '.lock', $quarantineIndex] as $temporary) {
                if (is_file($temporary) && !is_link($temporary)) {
                    @unlink($temporary);
                }
            }
            @rmdir($quarantineDir);
        }
    }

    /** Directory privata per l'indice alternativo, creata atomicamente dentro `.git`. */
    private function quarantineDir(string $dotGit): ?string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            try {
                $suffix = bin2hex(random_bytes(12));
            } catch (\Throwable) {
                return null;
            }
            $path = $dotGit . '/aimanager-index-' . $suffix;
            if (@mkdir($path, 0700)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Valida il LAYOUT dell'indice modificabile e ne restituisce il percorso canonico `<root>/.git/index`,
     * oppure null se il repository non è idoneo allo staging (resta usabile in sola lettura). Ammessi
     * SOLO: root Code canonica; `<root>/.git` directory reale e non symlink; indice restituito da Git
     * equivalente a `<root>/.git/index`; parent reale e non symlink; indice assente o file regolare non
     * symlink. Rifiutati: `.git` file, worktree collegati, gitdir esterne, symlink, percorso divergente.
     */
    private function modifiableIndexPath(CodeWorkspace $workspace): ?string
    {
        try {
            $root = $workspace->resolve(''); // root canonica, no-symlink, CodeSelfProtection, attiva
        } catch (CodeWorkspaceException $e) {
            return null;
        }
        $dotGit = $root . '/.git';
        // `.git` deve essere una DIRECTORY reale (non un `.git` file di worktree/submodulo, non symlink).
        if (is_link($dotGit) || !is_dir($dotGit)) {
            return null;
        }
        $dotGitReal = realpath($dotGit);
        if ($dotGitReal === false || $dotGitReal !== $dotGit) {
            return null; // parent dell'indice non reale/canonico
        }

        // L'indice che Git userebbe deve coincidere canonicamente con `<root>/.git/index`.
        try {
            $gitIndex = $this->git->indexPath($workspace);
        } catch (GitException $e) {
            return null;
        }
        if (basename($gitIndex) !== 'index') {
            return null;
        }
        $gitIndexParent = realpath(dirname($gitIndex));
        if ($gitIndexParent === false || $gitIndexParent !== $dotGitReal) {
            return null; // gitdir esterna / worktree collegato / percorso divergente
        }

        $canonicalIndex = $dotGitReal . '/index';
        // L'indice, se esiste, dev'essere un file REGOLARE non symlink; se assente va bene.
        if (is_link($canonicalIndex)) {
            return null;
        }
        if (file_exists($canonicalIndex) && !is_file($canonicalIndex)) {
            return null;
        }

        return $canonicalIndex;
    }

    /**
     * Pathspec di esecuzione costruiti DAL PIANO: `path` di ogni voce e, per rename/copy, anche
     * `orig_path` QUANDO è un file regolare realmente presente nel worktree (caso copy: l'origine va
     * inclusa; caso rename già in indice: l'origine è eliminata e va rappresentata dall'indice, non da
     * argv, altrimenti `git add` fallirebbe). Dedup e ordine deterministico. Null se un capo non è
     * risolvibile in sicurezza (symlink comparso → fail closed).
     *
     * @return list<string>|null
     */
    private function executionPathspecs(CodeWorkspace $workspace, GitStagePlan $plan): ?array
    {
        $specs = [];
        foreach ($plan->selected as $entry) {
            $specs[$entry['path']] = true;
            if ($entry['orig_path'] !== null) {
                try {
                    $abs = $workspace->resolve($entry['orig_path']); // confine/no-symlink/self-protection
                } catch (CodeWorkspaceException $e) {
                    return null;
                }
                if (is_link($abs)) {
                    return null; // symlink comparso su un capo: fail closed
                }
                if (is_file($abs)) {
                    $specs[$entry['orig_path']] = true; // origine presente (copy): incluso
                }
                // origine assente (rename): NON in argv (già rappresentata nell'indice).
            }
        }
        $out = array_keys($specs);
        sort($out, SORT_STRING);

        return $out;
    }

    /**
     * Verifica post-add sull'indice di quarantena. Ritorna null se tutto è corretto, altrimenti un
     * messaggio d'errore controllato:
     *   (a) ogni percorso selezionato risulta in stage (per i rename/copy: la voce è rappresentata da
     *       ENTRAMBI i capi nello stato di quarantena);
     *   (b) nessun percorso NUOVO fuori dalla selezione (compresi gli origin ammessi) è stato staged.
     */
    private function verifyStaged(GitStagePlan $plan, GitStatus $realStatus, GitStatus $tempStatus): ?string
    {
        $stagedTemp = $this->stagedSet($tempStatus);
        $stagedReal = $this->stagedSet($realStatus);

        // (a) selezionati in stage.
        foreach ($plan->selected as $entry) {
            if (!isset($stagedTemp[$entry['path']])) {
                return 'Un percorso selezionato non risulta in stage: indice reale invariato.';
            }
        }

        // Insieme AMMESSO di percorsi che possono comparire come novità: path + orig_path della selezione
        // (un rename mette in stage entrambi i capi; un origin ammesso non è "inatteso").
        $allowed = [];
        foreach ($plan->selected as $entry) {
            $allowed[$entry['path']] = true;
            if ($entry['orig_path'] !== null) {
                $allowed[$entry['orig_path']] = true;
            }
        }

        // (b) nessuna novità fuori dall'insieme ammesso.
        foreach (array_keys($stagedTemp) as $path) {
            if (!isset($stagedReal[$path]) && !isset($allowed[$path])) {
                return 'Staging inatteso rilevato: indice reale invariato.';
            }
        }

        return null;
    }

    /**
     * Insieme dei percorsi IN STAGE di uno stato (voci con colonna d'indice attiva). I sensibili/runtime
     * non compaiono (già esclusi da GitStatus). Per i rename/copy si considerano ENTRAMBI i capi
     * (destinazione e origine): git può rappresentare elimina+aggiungi come un'unica voce di rename, e
     * la verifica deve riconoscere entrambi i capi come «messi in stage».
     *
     * @return array<string,true>
     */
    private function stagedSet(GitStatus $status): array
    {
        $out = [];
        foreach ($status->staged() as $entry) {
            $out[$entry->path] = true;
            if ($entry->origPath !== null) {
                $out[$entry->origPath] = true;
            }
        }

        return $out;
    }
}
