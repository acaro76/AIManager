<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 8 / base Git read-only. Servizio DEDICATO e CONFINATO per interrogare Git su una root
 * Code, SENZA alcuna operazione modificante.
 *
 * Superficie (sola lettura):
 *   - {@see isRepository()} : la root autorizzata è (parte di) un worktree Git?
 *   - {@see status()}       : stato STRUTTURATO del worktree (branch, ahead/behind, voci);
 *   - {@see diffUnstaged()} : diff WORKTREE↔INDICE;
 *   - {@see diffStaged()}   : diff INDICE↔HEAD.
 *
 * Confini di sicurezza (tutti applicati QUI o in GitInvoker):
 *   - cwd = root Code RIVALIDATA a ogni chiamata via {@see CodeWorkspace::resolve('')}, che impone
 *     workspace attivo, CodeSelfProtection, PathGuard e il divieto di symlink. `.git` NON è mai letto
 *     tramite CodeWorkspace e SensitivePathPolicy resta invariata: si interroga solo `git`, non il
 *     filesystem di `.git`;
 *   - eseguibile `git` risolto server-side in bin fidata (GitInvoker), MAI dal PATH né dalla root;
 *   - argv COSTRUITO DAL SERVER a vocabolario chiuso: costanti qui sotto, nessun input del modello;
 *   - external diff e textconv DISATTIVATI (`--no-ext-diff`, `--no-textconv`, `-c diff.external=`);
 *   - aggiornamenti opzionali dell'indice EVITATI (`--no-optional-locks` + `GIT_OPTIONAL_LOCKS=0`);
 *   - nessun accesso di rete (nessun sotto-comando remoto; env senza credenziali; prompt disabilitato);
 *   - timeout e output cappato (GitLimits);
 *   - nomi file e output = DATI NON FIDATI (mai ri-risolti sul filesystem).
 *
 * Escluso QUI (base): staging, commit, reset/checkout/restore/clean/stash, branch/merge/rebase,
 * fetch/pull/push, persistenza, proposte/conferme, route/UI, ciclo agente. Git NON è in CommandRegistry:
 * è un sottosistema distinto dai comandi generici.
 */
final class GitService
{
    /**
     * Opzioni GLOBALI anteposte a OGNI sotto-comando (top-level, prima del sotto-comando):
     *   - `--no-optional-locks` : nessun lock/aggiornamento opportunistico dell'indice;
     *   - `--literal-pathspecs` : ogni pathspec è LETTERALE — niente glob né magic (`:(...)`),
     *                             difesa contro la pathspec injection (oltre al separatore `--`);
     *   - `-c core.fsmonitor=false` : nessun fsmonitor (nessun processo/hook esterno);
     *   - `-c diff.external=` : neutralizza un external diff ereditato (oltre a --no-ext-diff);
     *   - `-c core.hooksPath=/dev/null` : nessun hook eseguito.
     *
     * @var list<string>
     */
    private const GLOBAL_OPTS = [
        '--no-optional-locks',
        '--literal-pathspecs',
        '-c', 'core.fsmonitor=false',
        '-c', 'diff.external=',
        '-c', 'core.hooksPath=/dev/null',
    ];

    private readonly GitPathPolicy $policy;

    public function __construct(
        private readonly GitInvoker $invoker,
        ?GitPathPolicy $policy = null,
    ) {
        $this->policy = $policy ?? new GitPathPolicy();
    }

    public static function withDefaults(): self
    {
        return new self(new GitInvoker(GitLimits::defaults()));
    }

    /** git è disponibile come eseguibile in bin fidata? (indipendente dal repo) */
    public function isAvailable(): bool
    {
        return $this->invoker->available();
    }

    /**
     * La root autorizzata è ESATTAMENTE il top-level di un worktree Git? Non legge `.git`: chiede a
     * git stesso (`rev-parse --show-toplevel`). L'accettazione richiede che il top-level Git CANONICO
     * coincida con la root Code CANONICA: così una SOTTOCARTELLA di un repository padre è RIFIUTATA
     * (da quella cwd, status/diff mostrerebbero file esterni alla root Code). Guasto d'avvio,
     * non-repo, top-level assente o divergente → false (fail closed).
     */
    public function isRepository(CodeWorkspace $workspace): bool
    {
        $root = $this->confinedRoot($workspace);
        $toplevel = $this->repositoryToplevel($root);

        return $toplevel !== null && $toplevel === $root;
    }

    /**
     * Top-level Git CANONICO per la cwd, o null. `--show-toplevel` risale al confine del repository;
     * se la cwd è dentro un repository PADRE, ritorna il padre (≠ root Code → rifiuto). Il valore è
     * ri-canonicalizzato con realpath per un confronto robusto (symlink di sistema, `/var`→`/private/var`).
     */
    private function repositoryToplevel(string $cwd): ?string
    {
        $result = $this->invoker->run(
            array_merge(self::GLOBAL_OPTS, ['rev-parse', '--show-toplevel']),
            $cwd,
        );
        if (!$result->ok()) {
            return null;
        }
        $line = trim($result->stdout);
        if ($line === '') {
            return null;
        }
        $canonical = realpath($line);
        if ($canonical === false || !is_dir($canonical)) {
            return null;
        }

        return rtrim($canonical, DIRECTORY_SEPARATOR);
    }

    /**
     * Stato STRUTTURATO del worktree. Richiede un repository: altrimenti GitException (atteso).
     * Usa `--porcelain=v2 -z` (stabile, machine-readable) con `--branch` e `--untracked-files=all`.
     *
     * `$indexFile` (opzionale, SERVER-SIDE) legge lo stato rispetto a un INDICE alternativo
     * (quarantena): serve alla verifica di uno staging preparato fuori dall'indice reale.
     */
    public function status(CodeWorkspace $workspace, ?string $indexFile = null): GitStatus
    {
        $cwd = $this->assertRepository($workspace);
        $result = $this->invoker->run(
            array_merge(self::GLOBAL_OPTS, [
                'status',
                '--porcelain=v2',
                '--branch',
                '--untracked-files=all',
                '-z',
            ]),
            $cwd,
            $indexFile,
        );
        if (!$result->started || $result->timedOut) {
            throw new GitException('Lettura dello stato Git non riuscita.');
        }
        // Un output troncato al cap è un parziale legittimo (processo terminato, exit code ignoto):
        // si accetta e si segnala via `truncated`. Solo un exit code anomalo senza troncamento è errore.
        if (!$result->truncated && $result->exitCode !== 0) {
            throw new GitException('Lettura dello stato Git non riuscita.');
        }
        // Le voci escluse (sensibili via SensitivePathPolicy + runtime come `storage/`, e i rename con
        // un capo escluso) sono rimosse dalle entries ma CONTATE in `excludedCount`: nessun pattern
        // duplicato (GitPathPolicy delega i sensibili), nessun nome/contenuto escluso esposto.
        return $this->parseStatus($result->stdout, $result->truncated);
    }

    /** Diff WORKTREE↔INDICE (modifiche non in stage). Richiede un repository. */
    public function diffUnstaged(CodeWorkspace $workspace): GitDiff
    {
        return $this->diff($workspace, staged: false);
    }

    /** Diff INDICE↔HEAD (modifiche in stage). Richiede un repository. */
    public function diffStaged(CodeWorkspace $workspace): GitDiff
    {
        return $this->diff($workspace, staged: true);
    }

    /**
     * Voci dell'INDICE per i soli pathspec dati, via `git ls-files --stage -z` (SOLO lettura). I
     * pathspec sono LETTERALI (`--literal-pathspecs`) e server-side (mai dal modello), col separatore
     * `--`. Per ogni voce d'indice restituisce `mode`, `oid` (blob id) e `stage` — inclusi gli stage
     * multipli in caso di conflitto. Serve alla fingerprint dello stato: NON viene mai esposto all'esito.
     *
     * @param list<string> $paths pathspec letterali, già validati come relativi/canonici a monte
     * @return array{entries: list<array{path:string,mode:string,oid:string,stage:int}>, truncated: bool}
     */
    public function indexEntries(CodeWorkspace $workspace, array $paths): array
    {
        $cwd = $this->assertRepository($workspace);
        if ($paths === []) {
            return ['entries' => [], 'truncated' => false];
        }
        $operands = array_merge(self::GLOBAL_OPTS, ['ls-files', '--stage', '-z', '--']);
        foreach ($paths as $path) {
            $operands[] = $path;
        }
        $result = $this->invoker->run($operands, $cwd);
        if (!$result->started || $result->timedOut) {
            throw new GitException('Lettura dell\'indice Git non riuscita.');
        }
        if (!$result->truncated && $result->exitCode !== 0) {
            throw new GitException('Lettura dell\'indice Git non riuscita.');
        }

        return ['entries' => $this->parseLsFiles($result->stdout), 'truncated' => $result->truncated];
    }

    /**
     * Percorso ASSOLUTO del file di indice reale del repository (`git rev-parse --git-path index`),
     * SOLO lettura. Serve all'esecutore dello staging per preparare un indice temporaneo/quarantena e
     * poi sostituire atomicamente quello reale. GitException se non risolvibile.
     */
    public function indexPath(CodeWorkspace $workspace): string
    {
        $cwd = $this->assertRepository($workspace);
        $result = $this->invoker->run(
            array_merge(self::GLOBAL_OPTS, ['rev-parse', '--git-path', 'index']),
            $cwd,
        );
        if (!$result->ok()) {
            throw new GitException('Percorso dell\'indice Git non risolvibile.');
        }
        $line = trim($result->stdout);
        if ($line === '') {
            throw new GitException('Percorso dell\'indice Git non risolvibile.');
        }
        // `--git-path` è relativo alla cwd (la root): rendilo assoluto senza seguire symlink (uso lessicale).
        if ($line[0] !== '/') {
            $line = rtrim($cwd, '/') . '/' . $line;
        }

        return $line;
    }

    /**
     * Mette in stage i SOLI pathspec dati, scrivendo su un INDICE indicato da `$indexFile` (quarantena):
     * `git add --literal-pathspecs -- <paths>`. Argv array (mai shell), env neutralizzato, nessuna rete,
     * nessuno staging globale. L'indice REALE non è toccato (si scrive su `$indexFile`). Ritorna il
     * risultato grezzo (exit/troncamento): la valutazione tipizzata spetta al chiamante.
     *
     * @param list<string> $paths pathspec letterali, server-side (mai dal modello)
     */
    public function addPaths(CodeWorkspace $workspace, array $paths, string $indexFile): GitCommandResult
    {
        $cwd = $this->assertRepository($workspace);
        if ($paths === [] || $indexFile === '') {
            throw new GitException('Nessun percorso da mettere in stage.');
        }
        $operands = array_merge(self::GLOBAL_OPTS, ['add', '--']);
        foreach ($paths as $path) {
            $operands[] = $path;
        }

        return $this->invoker->run($operands, $cwd, $indexFile);
    }

    /**
     * Inizializza `$indexFile` come indice VUOTO valido (`git read-tree --empty`). Serve solo alla
     * quarantena quando il repository non ha ancora un indice reale (repo iniziale con soli file non
     * tracciati): scrive esclusivamente su `$indexFile`, mai sull'indice reale.
     */
    public function initEmptyIndex(CodeWorkspace $workspace, string $indexFile): GitCommandResult
    {
        $cwd = $this->assertRepository($workspace);
        if ($indexFile === '') {
            throw new GitException('Percorso dell\'indice di quarantena mancante.');
        }

        return $this->invoker->run(
            array_merge(self::GLOBAL_OPTS, ['read-tree', '--empty']),
            $cwd,
            $indexFile,
        );
    }

    /** HEAD corrente, oppure stringa vuota per un repository iniziale. Solo lettura. */
    public function head(CodeWorkspace $workspace): string
    {
        $cwd = $this->assertRepository($workspace);
        $result = $this->invoker->run(array_merge(self::GLOBAL_OPTS, ['rev-parse', '--verify', 'HEAD']), $cwd);
        if (!$result->started || $result->timedOut || $result->truncated) {
            throw new GitException('HEAD Git non leggibile.');
        }
        return $result->exitCode === 0 ? trim($result->stdout) : '';
    }

    /**
     * Commit tipizzato dell'indice corrente. Il messaggio è già validato dal servizio; nessuna opzione
     * arbitraria, hook disattivati, firma disattivata, nessun push/rete.
     */
    public function commit(CodeWorkspace $workspace, string $message): GitCommandResult
    {
        $cwd = $this->assertRepository($workspace);
        return $this->invoker->run(array_merge(self::GLOBAL_OPTS, [
            '-c', 'commit.gpgsign=false', '-c', 'user.name=AIManager',
            '-c', 'user.email=aimanager@local', 'commit', '--no-verify', '-m', $message,
        ]), $cwd);
    }

    /**
     * Parser di `git ls-files --stage -z`: record separati da NUL, ognuno `<mode> <oid> <stage>\t<path>`.
     * I percorsi restano DATI (nessuna normalizzazione lessicale, nessun accesso al FS).
     *
     * @return list<array{path:string,mode:string,oid:string,stage:int}>
     */
    private function parseLsFiles(string $raw): array
    {
        $out = [];
        foreach (explode("\0", $raw) as $record) {
            if ($record === '') {
                continue;
            }
            $tab = strpos($record, "\t");
            if ($tab === false) {
                continue;
            }
            $meta = substr($record, 0, $tab);
            $path = substr($record, $tab + 1);
            $parts = preg_split('/ +/', trim($meta)) ?: [];
            if (count($parts) < 3) {
                continue;
            }
            $out[] = [
                'path' => Utf8::clean($path),
                'mode' => $parts[0],
                'oid' => $parts[1],
                'stage' => (int) $parts[2],
            ];
        }

        return $out;
    }

    /**
     * Il diff è costruito ESCLUSIVAMENTE sui percorsi NON sensibili individuati dallo stato pertinente
     * (staged o unstaged): un file sensibile tracciato non compare mai, nemmeno modificato. I pathspec
     * sono letterali (`--literal-pathspecs`), server-side, protetti da option/pathspec injection dal
     * separatore `--`; nessun percorso proviene dal modello o dall'utente. Nessun percorso ammesso →
     * diff vuoto senza invocare git.
     */
    private function diff(CodeWorkspace $workspace, bool $staged): GitDiff
    {
        // status() asserisce già il repository (top-level = root) ed esclude i percorsi sensibili.
        $status = $this->status($workspace);
        $paths = $staged ? $this->stagedPaths($status) : $this->unstagedPaths($status);
        if ($paths === []) {
            return new GitDiff(staged: $staged, text: '', truncated: false);
        }

        $cwd = $this->confinedRoot($workspace);
        $operands = array_merge(self::GLOBAL_OPTS, [
            'diff',
            '--no-color',
            '--no-ext-diff',
            '--no-textconv',
        ]);
        if ($staged) {
            $operands[] = '--cached';
        }
        $operands[] = '--'; // separatore: tutto ciò che segue è pathspec, mai un'opzione
        foreach ($paths as $path) {
            $operands[] = $path;
        }

        $result = $this->invoker->run($operands, $cwd);
        if (!$result->started || $result->timedOut) {
            throw new GitException('Lettura del diff Git non riuscita.');
        }
        // git diff esce 0 (nessuna differenza) o 1 (differenze) in modalità normale; un troncamento al
        // cap termina il processo prima dell'exit (code ignoto) ma resta un parziale valido.
        if (!$result->truncated && $result->exitCode !== 0 && $result->exitCode !== 1) {
            throw new GitException('Lettura del diff Git non riuscita.');
        }

        return new GitDiff(staged: $staged, text: Utf8::clean($result->stdout), truncated: $result->truncated);
    }

    /**
     * Pathspec (già non sensibili) delle voci in STAGE. Per un rename si includono sia il percorso
     * nuovo sia l'origine, così il diff --cached mostra la voce; l'ordine è deterministico e senza
     * duplicati.
     *
     * @return list<string>
     */
    private function stagedPaths(GitStatus $status): array
    {
        return $this->collectPaths($status->staged());
    }

    /** @return list<string> */
    private function unstagedPaths(GitStatus $status): array
    {
        return $this->collectPaths($status->unstaged());
    }

    /**
     * @param list<GitStatusEntry> $entries
     * @return list<string>
     */
    private function collectPaths(array $entries): array
    {
        $paths = [];
        foreach ($entries as $entry) {
            // Le entries sono già filtrate; il ri-controllo qui rende i pathspec del diff difesi
            // per costruzione contro percorsi esclusi (sensibili/runtime), non solo di riflesso.
            foreach ([$entry->path, $entry->origPath] as $candidate) {
                if ($candidate !== null && !$this->policy->isExcluded($candidate)) {
                    $paths[$candidate] = true;
                }
            }
        }

        return array_keys($paths);
    }

    /**
     * cwd = root Code rivalidata: workspace attivo + CodeSelfProtection + PathGuard + no-symlink.
     * Un workspace revocato o una root non più valida lancia CodeWorkspaceException (fail closed).
     */
    private function confinedRoot(CodeWorkspace $workspace): string
    {
        return $workspace->resolve('');
    }

    private function assertRepository(CodeWorkspace $workspace): string
    {
        if (!$this->isRepository($workspace)) {
            throw new GitException('La cartella autorizzata non è un repository Git.');
        }

        return $this->confinedRoot($workspace);
    }

    /**
     * Parser di `git status --porcelain=v2 -z`. I record sono separati da NUL; le voci di tipo `2`
     * (rename/copy) consumano un SECONDO token (origPath). Tutto ciò che non è header/voce nota è
     * ignorato. I percorsi restano DATI (nessuna normalizzazione lessicale, nessun accesso al FS).
     *
     * Le voci il cui percorso è escluso (sensibile o runtime) sono RIMOSSE dalle entries ma CONTATE in
     * `excludedCount`; un rename/copy è ammesso solo se ENTRAMBI i capi (corrente e origine) sono
     * ammessi. L'esclusione usa {@see GitPathPolicy} (nessun pattern duplicato). Nessun nome escluso è
     * conservato: si aggiorna solo il conteggio aggregato.
     */
    private function parseStatus(string $raw, bool $truncated): GitStatus
    {
        $excludedCount = 0;
        $tokens = explode("\0", $raw);
        $count = count($tokens);

        $branch = null;
        $upstream = null;
        $ahead = 0;
        $behind = 0;
        $detached = false;
        $initial = false;
        $entries = [];

        $i = 0;
        while ($i < $count) {
            $tok = $tokens[$i];
            if ($tok === '') {
                $i++;
                continue;
            }
            $type = $tok[0];

            if ($type === '#') {
                if (str_starts_with($tok, '# branch.head ')) {
                    $head = substr($tok, strlen('# branch.head '));
                    if ($head === '(detached)') {
                        $detached = true;
                    } else {
                        $branch = $head;
                    }
                } elseif (str_starts_with($tok, '# branch.oid ')) {
                    if (substr($tok, strlen('# branch.oid ')) === '(initial)') {
                        $initial = true;
                    }
                } elseif (str_starts_with($tok, '# branch.upstream ')) {
                    $upstream = substr($tok, strlen('# branch.upstream '));
                } elseif (str_starts_with($tok, '# branch.ab ')) {
                    [$ahead, $behind] = $this->parseAheadBehind(substr($tok, strlen('# branch.ab ')));
                }
                $i++;
                continue;
            }

            if ($type === '1') {
                // "1 <XY> <sub> <mH> <mI> <mW> <hH> <hI> <path>"
                $parts = explode(' ', $tok, 9);
                if (count($parts) === 9) {
                    $xy = $parts[1];
                    $path = Utf8::clean($parts[8]);
                    if ($this->policy->isExcluded($path)) {
                        $excludedCount++;
                    } else {
                        $entries[] = new GitStatusEntry(
                            path: $path,
                            origPath: null,
                            index: $xy[0] ?? '.',
                            worktree: $xy[1] ?? '.',
                            untracked: false,
                            unmerged: false,
                        );
                    }
                }
                $i++;
                continue;
            }

            if ($type === '2') {
                // "2 <XY> <sub> <mH> <mI> <mW> <hH> <hI> <Xscore> <path>"; origPath = token successivo.
                $parts = explode(' ', $tok, 10);
                $orig = $tokens[$i + 1] ?? null;
                if (count($parts) === 10) {
                    $xy = $parts[1];
                    $path = Utf8::clean($parts[9]);
                    $origPath = $orig !== null && $orig !== '' ? Utf8::clean($orig) : null;
                    // Rename/copy ammesso SOLO se ENTRAMBI i capi (corrente e origine) sono ammessi.
                    if ($this->policy->isExcluded($path) || ($origPath !== null && $this->policy->isExcluded($origPath))) {
                        $excludedCount++;
                    } else {
                        $entries[] = new GitStatusEntry(
                            path: $path,
                            origPath: $origPath,
                            index: $xy[0] ?? '.',
                            worktree: $xy[1] ?? '.',
                            untracked: false,
                            unmerged: false,
                        );
                    }
                }
                $i += 2; // salta il record principale E il suo origPath
                continue;
            }

            if ($type === 'u') {
                // "u <XY> ..." — voce non risolta (merge/conflitto).
                $parts = explode(' ', $tok, 11);
                if (count($parts) === 11) {
                    $xy = $parts[1];
                    $path = Utf8::clean($parts[10]);
                    if ($this->policy->isExcluded($path)) {
                        $excludedCount++;
                    } else {
                        $entries[] = new GitStatusEntry(
                            path: $path,
                            origPath: null,
                            index: $xy[0] ?? '.',
                            worktree: $xy[1] ?? '.',
                            untracked: false,
                            unmerged: true,
                        );
                    }
                }
                $i++;
                continue;
            }

            if ($type === '?') {
                // "? <path>" — untracked.
                $path = Utf8::clean(substr($tok, 2));
                if ($this->policy->isExcluded($path)) {
                    $excludedCount++;
                } else {
                    $entries[] = new GitStatusEntry(
                        path: $path,
                        origPath: null,
                        index: '?',
                        worktree: '?',
                        untracked: true,
                        unmerged: false,
                    );
                }
                $i++;
                continue;
            }

            // '!' (ignored, non richiesto) o token inatteso: ignorato.
            $i++;
        }

        return new GitStatus(
            branch: $branch,
            upstream: $upstream,
            ahead: $ahead,
            behind: $behind,
            detached: $detached,
            initial: $initial,
            entries: $entries,
            truncated: $truncated,
            excludedCount: $excludedCount,
        );
    }

    /**
     * "+A -B" → [A, B]. Difensivo: valori non numerici → 0.
     *
     * @return array{0:int,1:int}
     */
    private function parseAheadBehind(string $ab): array
    {
        $ahead = 0;
        $behind = 0;
        foreach (explode(' ', $ab) as $piece) {
            if ($piece === '') {
                continue;
            }
            $sign = $piece[0];
            $value = (int) substr($piece, 1);
            if ($sign === '+') {
                $ahead = $value;
            } elseif ($sign === '-') {
                $behind = $value;
            }
        }

        return [$ahead, $behind];
    }
}
