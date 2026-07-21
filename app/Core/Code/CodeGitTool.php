<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 8 / integrazione read-only. Ingresso del CICLO agente al sottosistema Git: traduce le
 * azioni `git_status`/`git_diff` in un'OSSERVAZIONE strutturata e CAPPATA, come dato per il modello e
 * per la risposta finale. Non è una nuova policy né un nuovo parser: delega tutto a
 * {@see GitService}/{@see GitPathPolicy} (top-level confinato, sensibili/runtime esclusi, pathspec
 * letterali, timeout, output cappato).
 *
 * Solo lettura: nessuno staging/commit/reset/checkout/restore/clean/stash, nessun branch/merge/rebase,
 * nessun fetch/pull/push, nessuna rete, nessuna shell. Gli errori attesi (repository non valido,
 * workspace revocato, confine) NON sono eccezioni verso il ciclo: tornano come OSSERVAZIONE
 * comprensibile (fail closed), così la chat non cade.
 */
final class CodeGitTool
{
    /** @var callable(string):void hook interno (SOLO test) invocato subito prima dell'apertura del file
     *  del worktree, per esercitare la race di sostituzione. Confinato: mai raggiungibile dal modello. */
    private $beforeWorktreeOpen;

    /**
     * @param (callable(string):void)|null $beforeWorktreeOpen seam di test per la race symlink; default no-op.
     */
    public function __construct(
        private readonly GitService $git,
        private readonly GitPathPolicy $policy = new GitPathPolicy(),
        ?callable $beforeWorktreeOpen = null,
    ) {
        $this->beforeWorktreeOpen = $beforeWorktreeOpen ?? static function (string $abs): void {};
    }

    public static function withDefaults(): self
    {
        return new self(GitService::withDefaults());
    }

    /** git è risolvibile come eseguibile in bin fidata? (indipendente dalla singola cartella) */
    public function isAvailable(): bool
    {
        return $this->git->isAvailable();
    }

    /**
     * Stato STRUTTURATO del worktree come osservazione cappata. Distingue staged/unstaged/untracked e,
     * se esistono modifiche escluse (sensibili/runtime), ne comunica SOLO il conteggio senza dichiarare
     * mai il repository pulito.
     *
     * @return array{observation: string}
     */
    public function status(CodeWorkspace $workspace, int $maxChars): array
    {
        try {
            if (!$this->git->isRepository($workspace)) {
                return $this->unavailable('la cartella autorizzata non è il top-level di un repository Git.');
            }
            $status = $this->git->status($workspace);
        } catch (CodeWorkspaceException $e) {
            return $this->unavailable('workspace non accessibile (' . $e->getMessage() . ').');
        } catch (GitException $e) {
            return $this->unavailable('lettura dello stato non riuscita.');
        }

        return ['observation' => $this->cap($this->formatStatus($status), $maxChars)];
    }

    /**
     * Diff read-only, INDICE↔HEAD (`$staged`) oppure WORKTREE↔INDICE, come osservazione cappata. I
     * percorsi sensibili/runtime sono già esclusi da GitService: un diff vuoto NON viene presentato
     * come "repository pulito".
     *
     * @return array{observation: string}
     */
    public function diff(CodeWorkspace $workspace, bool $staged, int $maxChars): array
    {
        $label = $staged ? 'in stage' : 'non in stage';
        try {
            if (!$this->git->isRepository($workspace)) {
                return $this->unavailable('la cartella autorizzata non è il top-level di un repository Git.');
            }
            // Lo stato nello STESSO ingresso read-only: serve SOLO al conteggio aggregato degli
            // esclusi, così `git_diff` diretto (senza git_status) non nasconde le modifiche
            // sensibili/runtime e non lascia intendere un repository pulito.
            $status = $this->git->status($workspace);
            $diff = $staged ? $this->git->diffStaged($workspace) : $this->git->diffUnstaged($workspace);
        } catch (CodeWorkspaceException $e) {
            return $this->unavailable('workspace non accessibile (' . $e->getMessage() . ').');
        } catch (GitException $e) {
            return $this->unavailable('lettura del diff non riuscita.');
        }

        $parts = ['DIFF GIT (' . $label . '):'];
        if ($diff->truncated) {
            $parts[] = '(diff troncato dal server al limite di dimensione)';
        }
        $parts[] = $diff->isEmpty() ? 'Nessuna differenza da mostrare.' : $diff->text;
        if ($status->hasExcludedChanges()) {
            $parts[] = 'Modifiche escluse (file sensibili o runtime): ' . $status->excludedCount
                . ' — nomi e contenuti non mostrati. Il repository NON è pulito.';
        }

        return ['observation' => $this->cap(implode("\n", $parts), $maxChars)];
    }

    /**
     * Proposta di STAGING selettivo (Fase 8): valida i percorsi richiesti dal modello contro lo stato
     * Git REALE e ammesso, e produce un {@see GitStagePlan} immutabile SENZA eseguire alcun `git add`.
     *
     * Ammesso solo un percorso che, nello stato corrente:
     *   - è relativo alla root (già garantito a monte da CodeAgentAction) e supera confine/no-symlink/
     *     CodeSelfProtection ({@see CodeWorkspace::resolve()});
     *   - NON è sensibile né runtime ({@see GitPathPolicy}, difesa in profondità);
     *   - corrisponde a una modifica NON in stage o a un file NON tracciato dello stato ammesso;
     *   - per i rename/copy: combacia con l'origine O la destinazione di una voce già ammessa (i cui
     *     due capi sono entrambi non esclusi, perché altrimenti la voce non sarebbe nello stato).
     *
     * I percorsi rifiutati (inclusi eventuali sensibili/runtime indovinati) NON sono mai nominati:
     * l'osservazione riporta SOLO conteggi anonimi. Revoca o cambio di stato tra la lettura del modello
     * e questa validazione → nessun piano (fail closed): si rilegge sempre lo stato corrente.
     *
     * @param list<string> $paths percorsi normalizzati/dedotti (forma già validata)
     * @return array{plan: ?GitStagePlan, observation: ?string}
     */
    public function proposeStage(CodeWorkspace $workspace, array $paths, int $maxChars): array
    {
        try {
            if (!$this->git->isRepository($workspace)) {
                return ['plan' => null, 'observation' => $this->rejectStage('la cartella non è il top-level di un repository Git.', $maxChars)];
            }
            $status = $this->git->status($workspace);
            // FAIL CLOSED su stato PARZIALE: una proposta modificante non può nascere da uno stato
            // troncato (elenco incompleto). Nessun percorso, solo un motivo anonimo.
            if ($status->truncated) {
                return ['plan' => null, 'observation' => $this->rejectStage('stato Git parziale (output troncato): impossibile proporre uno staging affidabile.', $maxChars)];
            }
        } catch (CodeWorkspaceException $e) {
            return ['plan' => null, 'observation' => $this->rejectStage('workspace non accessibile.', $maxChars)];
        } catch (GitException $e) {
            return ['plan' => null, 'observation' => $this->rejectStage('lettura dello stato non riuscita.', $maxChars)];
        }

        // Candidati ammissibili: SOLO modifiche non in stage o file non tracciati (i sensibili/runtime
        // non sono nemmeno in $status->entries, sono già solo conteggio in excludedCount). Per i
        // rename/copy si conservano ENTRAMBI i capi; difesa in profondità: se un capo risultasse
        // sensibile/runtime (non dovrebbe, GitService lo escluderebbe) la voce è scartata.
        $candidates = $this->stageCandidates($status);

        // Percorsi richiesti che superano policy (non sensibili/runtime) e confine (no-symlink, dentro
        // la root, workspace attivo). Chi non passa è scartato in SILENZIO (solo conteggio).
        $validRequested = [];
        foreach ($paths as $path) {
            if ($this->policy->isExcluded($path)) {
                continue; // mai sensibile/runtime nel piano/errore
            }
            try {
                $workspace->resolve($path); // confine + no-symlink + CodeSelfProtection + revoca
            } catch (CodeWorkspaceException $e) {
                continue;
            }
            $validRequested[$path] = true;
        }

        // Selezionare l'origine O la destinazione identifica la STESSA voce; si conserva sempre la
        // forma canonica (path = destinazione, orig_path = origine per rename/copy).
        $selected = [];
        $allowedNotSelected = [];
        foreach ($candidates as $c) {
            $hit = isset($validRequested[$c['path']]) || ($c['orig'] !== null && isset($validRequested[$c['orig']]));
            if ($hit) {
                $selected[] = ['path' => $c['path'], 'orig_path' => $c['orig'], 'status' => $c['status']];
            } else {
                $allowedNotSelected[] = ['path' => $c['path'], 'orig_path' => $c['orig']];
            }
        }

        if ($selected === []) {
            return ['plan' => null, 'observation' => $this->rejectStage(
                'nessuno dei percorsi richiesti corrisponde a una modifica ammissibile allo staging'
                . ' (' . count($paths) . ' richiesti, ' . count($candidates) . ' ammissibili nel repository, '
                . $status->excludedCount . ' escluse sensibili/runtime, anonime).',
                $maxChars
            )];
        }

        // Impronta legata allo STATO EFFETTIVO delle SOLE voci selezionate. Può fallire chiuso
        // (symlink comparso, file non leggibile, output d'indice troncato): in tal caso nessun piano.
        $fingerprint = $this->stateFingerprint($selected, $status, $workspace);
        if ($fingerprint === null) {
            return ['plan' => null, 'observation' => $this->rejectStage('impossibile calcolare un\'impronta sicura dello stato dei percorsi selezionati.', $maxChars)];
        }

        $plan = GitStagePlan::create(
            $workspace->id,
            $selected,
            $allowedNotSelected,
            $status->excludedCount,
            $fingerprint,
            $workspace->resolve(''),
        );

        return ['plan' => $plan, 'observation' => null];
    }

    /**
     * Selezione deterministica per la chat: i percorsi non vengono inventati dal modello. Si
     * confronta il testo dell'utente con le sole modifiche Git attualmente ammissibili; un basename
     * è accettato soltanto se identifica una voce unica. «Tutto/tutti» seleziona tutte le modifiche
     * ammissibili. I percorsi esclusi non entrano mai nell'elenco e restano anonimi.
     *
     * @return array{plan: ?GitStagePlan, observation: ?string}
     */
    public function proposeStageFromPrompt(CodeWorkspace $workspace, string $prompt, int $maxChars): array
    {
        try {
            if (!$this->git->isRepository($workspace)) {
                return $this->proposeStage($workspace, [], $maxChars);
            }
            $status = $this->git->status($workspace);
        } catch (\Throwable) {
            return $this->proposeStage($workspace, [], $maxChars);
        }

        $candidates = $this->stageCandidates($status);
        $lowerPrompt = mb_strtolower($prompt);
        $selectAll = preg_match(
            '/\b(?:metti|aggiungi|includi|seleziona)\s+(?:tutto|tutti\s+i\s+file|tutte\s+le\s+(?:modifiche|variazioni))\b/u',
            $lowerPrompt
        ) === 1;

        $basenameCounts = [];
        foreach ($candidates as $candidate) {
            foreach (array_filter([$candidate['path'], $candidate['orig']]) as $path) {
                $basename = basename((string) $path);
                $basenameCounts[$basename] = ($basenameCounts[$basename] ?? 0) + 1;
            }
        }

        $requested = [];
        foreach ($candidates as $candidate) {
            foreach (array_filter([$candidate['path'], $candidate['orig']]) as $path) {
                $path = (string) $path;
                $basename = basename($path);
                if ($selectAll
                    || $this->promptMentionsPath($prompt, $path)
                    || (($basenameCounts[$basename] ?? 0) === 1 && $this->promptMentionsPath($prompt, $basename))) {
                    $requested[$path] = true;
                    break;
                }
            }
        }

        return $this->proposeStage($workspace, array_keys($requested), $maxChars);
    }

    /** @return list<array{path:string,orig:?string,status:string}> */
    private function stageCandidates(GitStatus $status): array
    {
        $candidates = [];
        foreach ($status->entries as $entry) {
            if (!$entry->isUnstaged() || $this->policy->isExcluded($entry->path)) {
                continue;
            }
            if ($entry->origPath !== null && $this->policy->isExcluded($entry->origPath)) {
                continue;
            }
            $candidates[] = [
                'path' => $entry->path,
                'orig' => $entry->origPath,
                'status' => $entry->untracked ? 'non tracciato' : ($entry->origPath !== null ? 'rinominato' : 'modificato'),
            ];
        }

        return $candidates;
    }

    private function promptMentionsPath(string $prompt, string $path): bool
    {
        $quoted = preg_quote($path, '~');

        return preg_match('~(?<![\pL\pN_./-])' . $quoted . '(?![\pL\pN_./-])~u', $prompt) === 1;
    }

    /**
     * Impronta READ-ONLY, deterministica, dello STATO EFFETTIVO delle SOLE voci selezionate (una
     * modifica NON selezionata non la cambia). Per ogni voce include: path/origPath e stato sintetico;
     * codici index/worktree e tipo (dallo `status`); stato dell'INDICE per entrambi i capi rilevanti
     * (mode/oid/stage, via `git ls-files --stage` confinato); e lo stato del WORKTREE per entrambi i
     * capi (hash streaming completo dei byte se file regolare, marker `absent` se mancante).
     *
     * Fail closed (ritorna null → nessun piano) se: l'output dell'indice è troncato; un capo è un
     * symlink; un file non è leggibile in sicurezza. `CodeWorkspace::resolve` rivalida sempre
     * confine/no-symlink/CodeSelfProtection; i sensibili/runtime non sono mai letti.
     *
     * Nessun blob id, contenuto o hash intermedio è esposto: tutto confluisce nel solo SHA-256 finale.
     *
     * @param list<array{path:string,orig_path:?string,status:string}> $selected
     */
    private function stateFingerprint(array $selected, GitStatus $status, CodeWorkspace $workspace): ?string
    {
        // Pathspec letterali: destinazioni e origini delle voci selezionate.
        $pathspecs = [];
        foreach ($selected as $e) {
            $pathspecs[$e['path']] = true;
            if ($e['orig_path'] !== null) {
                $pathspecs[$e['orig_path']] = true;
            }
        }
        try {
            $index = $this->git->indexEntries($workspace, array_keys($pathspecs));
        } catch (CodeWorkspaceException | GitException $e) {
            return null;
        }
        if ($index['truncated']) {
            return null; // output d'indice parziale: fail closed
        }

        // path → voci d'indice (mode/oid/stage), ordinate deterministicamente.
        $indexByPath = [];
        foreach ($index['entries'] as $entry) {
            $indexByPath[$entry['path']][] = [$entry['mode'], $entry['oid'], $entry['stage']];
        }
        foreach ($indexByPath as &$list) {
            sort($list);
        }
        unset($list);

        // path → codici/tipo dallo status.
        $codeByPath = [];
        foreach ($status->entries as $entry) {
            $codeByPath[$entry->path] = [$entry->index, $entry->worktree, $entry->untracked, $entry->unmerged];
        }

        $rows = [];
        foreach ($selected as $e) {
            try {
                $wtPath = $this->worktreeHash($workspace, $e['path']);
                $wtOrig = $e['orig_path'] !== null ? $this->worktreeHash($workspace, $e['orig_path']) : null;
            } catch (CodeWorkspaceException $ex) {
                return null; // symlink comparso / file non leggibile: fail closed
            }
            $rows[] = [
                'path' => $e['path'],
                'orig_path' => $e['orig_path'],
                'status' => $e['status'],
                'code' => $codeByPath[$e['path']] ?? null,
                'index_path' => $indexByPath[$e['path']] ?? [],
                'index_orig' => $e['orig_path'] !== null ? ($indexByPath[$e['orig_path']] ?? []) : [],
                'worktree_path' => $wtPath,
                'worktree_orig' => $wtOrig,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return hash('sha256', (string) json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Stato del worktree di un percorso come token opaco: `absent` SOLO se `lstat` conferma l'assenza;
     * altrimenti `sha256:<hash>` dei byte via hash STREAMING (mai in memoria, mai esposto).
     *
     * TOCTOU-safe: dopo `CodeWorkspace::resolve` (confine/no-symlink/CodeSelfProtection/revoca) il file
     * viene APERTO UNA SOLA volta in sola lettura; l'hash è calcolato dallo STESSO descrittore, non dal
     * pathname (un attaccante non può reindirizzarlo dopo l'apertura). Si verifica che:
     *   - `lstat(path)` sia un file REGOLARE (non symlink);
     *   - `fstat(fd)` sia un file REGOLARE;
     *   - device e inode di `fstat` e `lstat` COINCIDANO (il fd aperto è esattamente ciò che il nome
     *     indica ORA: nessun symlink seguito verso un altro oggetto);
     *   - dimensione/inode/device non cambino tra prima e dopo la lettura.
     * Qualsiasi anomalia o stato ambiguo → CodeWorkspaceException (nessun piano), senza esporre il path.
     * I sensibili/runtime non sono mai letti.
     */
    private function worktreeHash(CodeWorkspace $workspace, string $relative): string
    {
        if ($this->policy->isExcluded($relative)) {
            throw new CodeWorkspaceException('percorso escluso: non leggibile.');
        }
        $abs = $workspace->resolve($relative); // lancia su symlink/traversal/revoca

        // Seam di test (default no-op): simula una sostituzione tra validazione e apertura.
        ($this->beforeWorktreeOpen)($abs);

        clearstatcache(true, $abs);
        $handle = @fopen($abs, 'rb');
        if ($handle === false) {
            // Apertura fallita: `absent` SOLO se lstat conferma davvero l'assenza; altrimenti (symlink
            // dangling, permessi, stato ambiguo) fail closed.
            $lstat = @lstat($abs);
            if ($lstat === false) {
                return 'absent';
            }
            throw new CodeWorkspaceException('percorso non apribile in sicurezza.');
        }

        try {
            $fstat = @fstat($handle);
            $lstat = @lstat($abs);
            if ($fstat === false || $lstat === false) {
                throw new CodeWorkspaceException('stato del percorso non verificabile.');
            }
            if (($lstat['mode'] & 0170000) !== 0100000) {
                throw new CodeWorkspaceException('il percorso non è un file regolare.'); // symlink o altro
            }
            if (($fstat['mode'] & 0170000) !== 0100000) {
                throw new CodeWorkspaceException('il descrittore non è un file regolare.');
            }
            if ($fstat['dev'] !== $lstat['dev'] || $fstat['ino'] !== $lstat['ino']) {
                throw new CodeWorkspaceException('identità del file incoerente (possibile sostituzione).');
            }

            $ctx = hash_init('sha256');
            if (@hash_update_stream($ctx, $handle) === false) {
                throw new CodeWorkspaceException('lettura del worktree fallita.');
            }
            $digest = hash_final($ctx);

            // Rivalida i metadati del fd dopo la lettura: nessuna sostituzione dell'oggetto aperto.
            $after = @fstat($handle);
            if ($after === false
                || $after['dev'] !== $fstat['dev']
                || $after['ino'] !== $fstat['ino']
                || $after['size'] !== $fstat['size']) {
                throw new CodeWorkspaceException('metadati del file cambiati durante la lettura.');
            }

            return 'sha256:' . $digest;
        } finally {
            fclose($handle);
        }
    }

    private function rejectStage(string $reason, int $maxChars): string
    {
        return $this->cap('STAGING NON PROPOSTO: ' . $reason . ' Nessun `git add` è stato eseguito.', $maxChars);
    }

    private function formatStatus(GitStatus $status): string
    {
        $lines = ['STATO GIT'];
        if ($status->truncated) {
            $lines[] = '(stato parziale: output troncato dal server)';
        }

        if ($status->initial) {
            $lines[] = 'Ramo: ' . ($status->branch ?? '(nessun commit ancora)') . ' — repository iniziale (nessun commit).';
        } elseif ($status->detached) {
            $lines[] = 'HEAD staccato (nessun ramo corrente).';
        } else {
            $lines[] = 'Ramo: ' . ($status->branch ?? '(sconosciuto)');
        }
        if ($status->upstream !== null) {
            $lines[] = 'Upstream: ' . $status->upstream . ' (avanti ' . $status->ahead . ', indietro ' . $status->behind . ').';
        }

        $staged = $status->staged();
        $unstaged = array_values(array_filter($status->unstaged(), static fn (GitStatusEntry $e): bool => !$e->untracked));
        $untracked = array_values(array_filter($status->entries, static fn (GitStatusEntry $e): bool => $e->untracked));

        $lines[] = '';
        $lines[] = 'In stage (' . count($staged) . '):';
        foreach ($staged as $entry) {
            $lines[] = '  ' . $this->entryLine($entry);
        }
        $lines[] = 'Non in stage (' . count($unstaged) . '):';
        foreach ($unstaged as $entry) {
            $lines[] = '  ' . $this->entryLine($entry);
        }
        $lines[] = 'Non tracciati (' . count($untracked) . '):';
        foreach ($untracked as $entry) {
            $lines[] = '  ' . $entry->path;
        }

        if ($status->hasExcludedChanges()) {
            $lines[] = '';
            $lines[] = 'Modifiche escluse (file sensibili o runtime): ' . $status->excludedCount
                . ' — nomi e contenuti non mostrati. Il repository NON è pulito.';
        }
        if ($status->isClean()) {
            $lines[] = '';
            $lines[] = 'Nessuna modifica: worktree pulito.';
        }

        return implode("\n", $lines);
    }

    private function entryLine(GitStatusEntry $entry): string
    {
        $code = $entry->index . $entry->worktree;
        if ($entry->origPath !== null) {
            return $code . ' ' . $entry->origPath . ' → ' . $entry->path;
        }

        return $code . ' ' . $entry->path;
    }

    /** @return array{observation: string} */
    private function unavailable(string $reason): array
    {
        return ['observation' => 'GIT NON DISPONIBILE: ' . $reason];
    }

    /**
     * Cap DURO: `strlen()` del risultato non supera MAI `$maxChars`. Se il testo è tagliato e c'è
     * spazio anche per il marker, la troncatura è dichiarata; se il limite è troppo piccolo perfino
     * per il marker (o <= 0), si taglia comunque a `$maxChars` senza marker. `Utf8::cut` garantisce
     * UTF-8 valido entro il limite in byte.
     */
    private function cap(string $text, int $maxChars): string
    {
        $max = max(0, $maxChars);
        if (strlen($text) <= $max) {
            return $text;
        }
        $marker = "\n… (osservazione troncata al limite)";
        // Il marker entra solo se, insieme ad almeno 1 byte di testo, resta entro $max.
        if (strlen($marker) < $max) {
            return Utf8::cut($text, $max - strlen($marker)) . $marker;
        }

        // Limite troppo piccolo per il marker: cap duro a $max byte (UTF-8 valido, eventualmente vuoto).
        return Utf8::cut($text, $max);
    }
}
