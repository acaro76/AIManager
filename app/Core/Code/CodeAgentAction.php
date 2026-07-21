<?php

declare(strict_types=1);

namespace App\Core\Code;

use App\Services\LlmJsonExtractor;

/**
 * Code — Fase 3 / F3.2. L'azione richiesta dal modello: JSON a VOCABOLARIO CHIUSO.
 *
 * Il protocollo è confinato in Code: nessuna modifica al ProviderManager, nessun tool calling
 * nativo, nessuna contaminazione delle chat LLM. Si riusa l'estrattore già in casa
 * (LlmJsonExtractor), che regge i reasoning model (blocchi <think> anche non chiusi), le fence
 * markdown e la prosa attorno al JSON.
 *
 * REGOLA DI SICUREZZA: il testo del modello NON è un comando. Qui è solo un DATO da validare, e
 * l'unica cosa che può attraversare questo confine è una delle azioni di self::ACTIONS con
 * argomenti validati:
 *   - `find_files`  {query}          → ricerca per nome sull'inventario
 *   - `search_text` {query}          → ricerca nel contenuto (letture confinate)
 *   - `list_dir`    {path?}          → figli immediati di una directory ('' = root)
 *   - `read_file`   {path}           → lettura mirata confinata
 *   - `answer`      {}               → il modello ha abbastanza contesto: si conclude
 *   - `propose_file` {path,content}   → proposta whole-file, adattata ai modelli locali
 *   - `propose_patch` {changes}       → proposta precisa multi-file
 *
 * Un output non parsabile o un'azione sconosciuta lanciano \InvalidArgumentException con un
 * messaggio a TESTO FISSO: non riporta mai il valore ricevuto, così un contenuto ostile letto da
 * un file non può rientrare nel dialogo (né finire nei log) attraverso il messaggio d'errore.
 * Il chiamante (CodeAgentLoop) tratta l'eccezione come DATO e la restituisce al modello.
 *
 * I percorsi passano da RelativePath (relativi e canonici); il confine vero resta comunque a
 * valle, in CodeWorkspace (PathGuard + SensitivePathPolicy + revoca): questa è una validazione
 * di forma, non l'unica difesa.
 */
final class CodeAgentAction
{
    public const FIND_FILES = 'find_files';
    public const SEARCH_TEXT = 'search_text';
    public const LIST_DIR = 'list_dir';
    public const READ_FILE = 'read_file';
    public const ANSWER = 'answer';
    /** Fase 5: avvia una VERIFICA (lint/test/sintassi) tramite un profilo server-side. NON è
     *  terminale e NON scrive nulla: esegue un profilo curato e ne riporta l'esito come dato.
     *  Ammessa solo quando la verifica è abilitata lato server. Vietato Git, shell, installazioni. */
    public const RUN_CHECK = 'run_check';
    /** Fase 4: proponi una modifica sicura. TERMINALE come `answer`, e NON scrive nulla: produce
     *  solo una proposta da confermare (la scrittura avviene, se confermata, altrove). Ammessa solo
     *  quando la scrittura è abilitata lato server. */
    public const PROPOSE_PATCH = 'propose_patch';
    public const PROPOSE_FILE = 'propose_file';
    /** Fase 6: proponi un COMANDO locale (utility di lettura, argv chiuso). TERMINALE e NON esegue
     *  nulla: produce una proposta da confermare esplicitamente. Ammessa solo quando i comandi sono
     *  abilitati lato server. Vietati shell, interpreti, package manager, rete, Git, mutazioni. */
    public const RUN_COMMAND = 'run_command';
    /** Fase 7: proponi l'avvio di un PROCESSO persistente (unico profilo: server PHP locale).
     *  TERMINALE e NON avvia nulla: produce una proposta da confermare esplicitamente. Ammessa solo
     *  quando i processi sono abilitati lato server. Host imposto 127.0.0.1; niente argv arbitrari. */
    public const START_PROCESS = 'start_process';
    /** Fase 8: leggi lo STATO Git del worktree. NON terminale e SOLO lettura: nessuna operazione
     *  modificante o di rete. Ammessa solo quando Git è abilitato lato server. */
    public const GIT_STATUS = 'git_status';
    /** Fase 8: leggi un DIFF Git read-only, con scelta tipizzata `mode`: "staged" o "unstaged".
     *  NON terminale e SOLO lettura. Ammessa solo quando Git è abilitato lato server. */
    public const GIT_DIFF = 'git_diff';
    /** Fase 8: PROPONI lo staging selettivo di alcuni percorsi. TERMINALE e NON esegue alcun `git add`:
     *  produce solo un piano revisionabile (validato a valle contro lo stato Git ammesso). Ammessa solo
     *  quando Git è abilitato lato server. */
    public const PROPOSE_GIT_STAGE = 'propose_git_stage';

    /** @var list<string> Vocabolario CHIUSO di SOLA LETTURA: sempre ammesso. */
    public const ACTIONS = [self::FIND_FILES, self::SEARCH_TEXT, self::LIST_DIR, self::READ_FILE, self::ANSWER];

    private function __construct(
        public readonly string $name,
        public readonly string $query = '',
        public readonly string $path = '',
        public readonly ?CodePatchProposal $proposal = null,
        /** @var array{path:string,content:string}|null */
        public readonly ?array $wholeFile = null,
        /** Id del profilo di verifica (Fase 5), vuoto per le altre azioni. */
        public readonly string $profileId = '',
        /** Programma proposto (Fase 6), vuoto per le altre azioni. */
        public readonly string $commandProgram = '',
        /** @var list<string> Argomenti proposti (Fase 6), senza il programma e senza `--`. */
        public readonly array $commandArgs = [],
        /** Id del profilo di processo proposto (Fase 7), vuoto per le altre azioni. */
        public readonly string $processProfile = '',
        /** Porta proposta per il processo (Fase 7), 0 per le altre azioni. */
        public readonly int $processPort = 0,
        /** Docroot RELATIVO del processo (Fase 7), '' = radice. */
        public readonly string $processDir = '',
        /** Diff staged (Fase 8): true = INDICE↔HEAD, false = WORKTREE↔INDICE. Rilevante solo per git_diff. */
        public readonly bool $gitDiffStaged = false,
        /** @var list<string> Percorsi proposti per lo staging (Fase 8): normalizzati, dedotti, ordinati.
         *  Forma già validata; l'ammissibilità reale è decisa a valle contro lo stato Git. */
        public readonly array $stagePaths = [],
    ) {
    }

    /**
     * Estrae e VALIDA l'azione dall'output grezzo del modello.
     *
     * @throws \InvalidArgumentException messaggio a testo fisso, riproponibile al modello
     */
    public static function parse(
        string $raw,
        CodeAgentLimits $limits,
        bool $writeEnabled = false,
        ?CodePatchLimits $patchLimits = null,
        bool $verifyEnabled = false,
        bool $commandsEnabled = false,
        bool $processesEnabled = false,
        bool $gitEnabled = false,
    ): self {
        $data = LlmJsonExtractor::extractObject($raw);
        if ($data === null) {
            throw new \InvalidArgumentException(
                'Nessun JSON valido. Rispondi con un solo oggetto JSON, senza altro testo.'
            );
        }

        // Le azioni oltre la sola lettura entrano nel vocabolario solo se abilitate lato server.
        $allowed = self::ACTIONS;
        if ($writeEnabled) {
            $allowed = array_merge($allowed, [self::PROPOSE_PATCH, self::PROPOSE_FILE]);
        }
        if ($verifyEnabled) {
            $allowed = array_merge($allowed, [self::RUN_CHECK]);
        }
        if ($commandsEnabled) {
            $allowed = array_merge($allowed, [self::RUN_COMMAND]);
        }
        if ($processesEnabled) {
            $allowed = array_merge($allowed, [self::START_PROCESS]);
        }
        if ($gitEnabled) {
            $allowed = array_merge($allowed, [self::GIT_STATUS, self::GIT_DIFF, self::PROPOSE_GIT_STAGE]);
        }

        $name = $data['action'] ?? null;
        if (!is_string($name) || !in_array($name, $allowed, true)) {
            throw new \InvalidArgumentException(
                'Azione sconosciuta. Usa esattamente una fra: ' . implode(', ', $allowed) . '.'
            );
        }

        return match ($name) {
            self::ANSWER => new self(self::ANSWER),
            self::FIND_FILES, self::SEARCH_TEXT => new self($name, query: self::query($data, $limits)),
            self::LIST_DIR => new self(self::LIST_DIR, path: self::directory($data)),
            self::READ_FILE => new self(self::READ_FILE, path: self::file($data)),
            self::RUN_CHECK => self::runCheck($data),
            self::RUN_COMMAND => self::runCommand($data),
            self::START_PROCESS => self::startProcess($data),
            self::GIT_STATUS => new self(self::GIT_STATUS),
            self::GIT_DIFF => new self(self::GIT_DIFF, gitDiffStaged: self::gitDiffStaged($data)),
            self::PROPOSE_GIT_STAGE => new self(self::PROPOSE_GIT_STAGE, stagePaths: self::gitStagePaths($data)),
            self::PROPOSE_PATCH => new self(
                self::PROPOSE_PATCH,
                proposal: CodePatchProposal::fromActionData($data, $patchLimits ?? CodePatchLimits::defaults())
            ),
            self::PROPOSE_FILE => new self(
                self::PROPOSE_FILE,
                wholeFile: self::wholeFile($data, $patchLimits ?? CodePatchLimits::defaults())
            ),
        };
    }

    /** True se l'azione conclude il ciclo (nessuno strumento da eseguire). */
    public function isAnswer(): bool
    {
        return $this->name === self::ANSWER;
    }

    /** True se l'azione è una PROPOSTA di modifica: TERMINALE, non esegue né scrive nulla. */
    public function isProposal(): bool
    {
        return $this->name === self::PROPOSE_PATCH;
    }

    /** True se l'azione è una VERIFICA (Fase 5): NON terminale, non scrive, esegue un profilo. */
    public function isRunCheck(): bool
    {
        return $this->name === self::RUN_CHECK;
    }

    public function isWholeFileProposal(): bool
    {
        return $this->name === self::PROPOSE_FILE;
    }

    /** True se l'azione è una proposta di COMANDO locale (Fase 6): TERMINALE, non esegue nulla. */
    public function isRunCommand(): bool
    {
        return $this->name === self::RUN_COMMAND;
    }

    /** True se l'azione è una proposta di PROCESSO persistente (Fase 7): TERMINALE, non avvia nulla. */
    public function isStartProcess(): bool
    {
        return $this->name === self::START_PROCESS;
    }

    /** True se l'azione è la lettura dello STATO Git (Fase 8): NON terminale, SOLO lettura. */
    public function isGitStatus(): bool
    {
        return $this->name === self::GIT_STATUS;
    }

    /** True se l'azione è la lettura di un DIFF Git (Fase 8): NON terminale, SOLO lettura. */
    public function isGitDiff(): bool
    {
        return $this->name === self::GIT_DIFF;
    }

    /** True se l'azione è una PROPOSTA di staging selettivo (Fase 8): TERMINALE, non esegue `git add`. */
    public function isProposeGitStage(): bool
    {
        return $this->name === self::PROPOSE_GIT_STAGE;
    }

    /**
     * Chiave CANONICA dell'azione: due richieste che chiedono la STESSA cosa hanno la stessa
     * chiave, anche se il modello le ha scritte in modo diverso (`./app/Foo.php` e `app/Foo.php`
     * sono già normalizzati in parse(); `Login` e ` login ` diventano la stessa query).
     *
     * Serve al ciclo per non rieseguire due volte lo stesso strumento nello stesso turno: un
     * modello che ripete un'azione già completata brucia iterazioni, rilegge il filesystem e
     * gonfia evidenze e audit senza aggiungere nulla.
     */
    public function key(): string
    {
        return match ($this->name) {
            self::ANSWER => self::ANSWER,
            self::LIST_DIR, self::READ_FILE => $this->name . ':' . $this->path,
            self::RUN_CHECK => self::RUN_CHECK . ':' . $this->profileId . ':' . $this->path,
            self::RUN_COMMAND => self::RUN_COMMAND . ':' . $this->commandProgram . ':' . implode(' ', $this->commandArgs),
            self::START_PROCESS => self::START_PROCESS . ':' . $this->processProfile . ':' . $this->processPort . ':' . $this->processDir,
            self::GIT_STATUS => self::GIT_STATUS,
            self::GIT_DIFF => self::GIT_DIFF . ':' . ($this->gitDiffStaged ? 'staged' : 'unstaged'),
            self::PROPOSE_GIT_STAGE => self::PROPOSE_GIT_STAGE . ':' . implode(',', $this->stagePaths),
            default => $this->name . ':' . self::normalizeQuery($this->query),
        };
    }

    /** Etichetta breve dell'azione, per il messaggio di "già eseguita" (mai contenuto di file). */
    public function label(): string
    {
        return match ($this->name) {
            self::LIST_DIR => $this->path === '' ? 'list_dir (radice)' : 'list_dir ' . $this->path,
            self::READ_FILE => 'read_file ' . $this->path,
            self::RUN_CHECK => 'run_check ' . $this->profileId . ($this->path === '' ? '' : ' ' . $this->path),
            self::RUN_COMMAND => 'run_command ' . $this->commandProgram,
            self::START_PROCESS => 'start_process ' . $this->processProfile . ' :' . $this->processPort,
            self::GIT_STATUS => 'git_status',
            self::GIT_DIFF => 'git_diff (' . ($this->gitDiffStaged ? 'in stage' : 'non in stage') . ')',
            self::PROPOSE_GIT_STAGE => 'propose_git_stage (' . count($this->stagePaths) . ' file)',
            self::ANSWER => self::ANSWER,
            default => $this->name . ' "' . self::normalizeQuery($this->query) . '"',
        };
    }

    /** Minuscole e spazi collassati: la stessa ricerca scritta in due modi resta una sola. */
    private static function normalizeQuery(string $query): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($query)) ?? $query);
    }

    /**
     * Verifica (Fase 5): richiede un `profile` (id curato) e, per i profili su file, un `path`
     * RELATIVO. Qui si valida SOLO la forma: la disponibilità del profilo e il fatto che il file sia
     * già stato letto restano decisi a valle (server), come per gli altri strumenti.
     *
     * @param array<string, mixed> $data
     */
    private static function runCheck(array $data): self
    {
        $rawProfile = $data['profile'] ?? null;
        if (!is_string($rawProfile)) {
            throw new \InvalidArgumentException('Manca il campo "profile" (stringa) per run_check.');
        }
        $profile = strtolower(trim($rawProfile));
        if (preg_match('/^[a-z][a-z0-9-]{1,39}$/', $profile) !== 1) {
            throw new \InvalidArgumentException('Il campo "profile" non è un identificatore valido.');
        }

        // `path` è opzionale: i profili whole-project non lo usano. Se presente dev'essere relativo.
        $rawPath = $data['path'] ?? '';
        if (!is_string($rawPath)) {
            throw new \InvalidArgumentException('Il campo "path" deve essere una stringa.');
        }
        $path = self::normalize($rawPath);
        if ($path !== '') {
            self::assertRelative($path);
        }

        return new self(self::RUN_CHECK, path: $path, profileId: $profile);
    }

    /**
     * Comando locale (Fase 6): richiede un `program` (nome, non un path) e `args` (lista di stringhe).
     * Qui si valida SOLO la forma minima: il registro chiuso, la policy argv, la risoluzione in bin
     * fidate e il bind dei path restano decisi a valle (CodeCommandTool/servizio), come per gli altri
     * strumenti. Nessun path/programma con separatori: il programma è un NOME risolto server-side.
     *
     * @param array<string, mixed> $data
     */
    private static function runCommand(array $data): self
    {
        $rawProgram = $data['program'] ?? null;
        if (!is_string($rawProgram)) {
            throw new \InvalidArgumentException('Manca il campo "program" (stringa) per run_command.');
        }
        $program = strtolower(trim($rawProgram));
        if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $program) !== 1) {
            throw new \InvalidArgumentException('Il campo "program" non è un nome di programma valido.');
        }

        $rawArgs = $data['args'] ?? [];
        if (!is_array($rawArgs)) {
            throw new \InvalidArgumentException('Il campo "args" deve essere una lista di stringhe.');
        }
        $args = [];
        foreach ($rawArgs as $arg) {
            if (!is_string($arg)) {
                throw new \InvalidArgumentException('Ogni elemento di "args" deve essere una stringa.');
            }
            $args[] = $arg;
        }

        return new self(self::RUN_COMMAND, commandProgram: $program, commandArgs: array_values($args));
    }

    /**
     * Processo persistente (Fase 7): richiede un `profile` (id, l'unico ammesso è `php-server`), una
     * `port` numerica e un `directory` RELATIVO opzionale (docroot; '' = radice). Qui si valida SOLO
     * la forma minima: il profilo ammesso, l'intervallo di porta, il confine del docroot e la
     * disponibilità restano decisi a valle (CodeProcessTool/servizio). L'host NON è nel protocollo:
     * lo impone il server (127.0.0.1). Nessun programma/argv arbitrario.
     *
     * @param array<string, mixed> $data
     */
    private static function startProcess(array $data): self
    {
        $rawProfile = $data['profile'] ?? null;
        if (!is_string($rawProfile)) {
            throw new \InvalidArgumentException('Manca il campo "profile" (stringa) per start_process.');
        }
        $profile = strtolower(trim($rawProfile));
        if (preg_match('/^[a-z][a-z0-9-]{1,39}$/', $profile) !== 1) {
            throw new \InvalidArgumentException('Il campo "profile" non è un identificatore valido.');
        }

        $rawPort = $data['port'] ?? null;
        if (is_string($rawPort) && preg_match('/^\d{1,5}$/', trim($rawPort)) === 1) {
            $rawPort = (int) trim($rawPort);
        }
        if (!is_int($rawPort)) {
            throw new \InvalidArgumentException('Manca il campo "port" (numero) per start_process.');
        }
        if ($rawPort < 1 || $rawPort > 65535) {
            throw new \InvalidArgumentException('La "port" non è un numero di porta valido.');
        }

        $rawDir = $data['directory'] ?? '';
        if (!is_string($rawDir)) {
            throw new \InvalidArgumentException('Il campo "directory" deve essere una stringa.');
        }
        $dir = self::normalize($rawDir);
        if ($dir !== '') {
            self::assertRelative($dir);
        }

        return new self(self::START_PROCESS, processProfile: $profile, processPort: $rawPort, processDir: $dir);
    }

    /**
     * Diff Git (Fase 8): scelta TIPIZZATA `mode` fra "staged" e "unstaged" (nessun percorso: il diff è
     * costruito server-side sui soli percorsi non sensibili/runtime). Per tolleranza si accetta anche
     * un booleano `staged`. Un valore mancante o diverso è un errore-dato riproponibile al modello.
     *
     * @param array<string, mixed> $data
     */
    private static function gitDiffStaged(array $data): bool
    {
        $mode = $data['mode'] ?? null;
        if (is_string($mode)) {
            $mode = strtolower(trim($mode));
            if ($mode === 'staged') {
                return true;
            }
            if ($mode === 'unstaged') {
                return false;
            }
            throw new \InvalidArgumentException('Il campo "mode" di git_diff deve essere "staged" o "unstaged".');
        }
        if (array_key_exists('staged', $data) && is_bool($data['staged'])) {
            return $data['staged'];
        }

        throw new \InvalidArgumentException('Manca il campo "mode" ("staged" o "unstaged") per git_diff.');
    }

    /**
     * Percorsi di staging (Fase 8): lista NON vuota di stringhe RELATIVE e canoniche. Qui si valida la
     * sola FORMA (l'ammissibilità reale è a valle, contro lo stato Git): relativi, senza "..",
     * assoluti, backslash o NUL, e senza forme da opzione o pathspec-magic (`-…`, `:…`). I percorsi
     * sono normalizzati, DEDUPLICATI e ORDINATI in modo deterministico. Nessun valore ricevuto compare
     * nei messaggi d'errore (potrebbe essere un percorso sensibile o testo ostile).
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private static function gitStagePaths(array $data): array
    {
        $raw = $data['paths'] ?? null;
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('Manca il campo "paths" (lista di stringhe) per propose_git_stage.');
        }
        $paths = [];
        foreach ($raw as $entry) {
            if (!is_string($entry)) {
                throw new \InvalidArgumentException('Ogni elemento di "paths" deve essere una stringa.');
            }
            $path = self::normalize($entry);
            if ($path === '') {
                throw new \InvalidArgumentException('Un percorso di "paths" è vuoto.');
            }
            self::assertRelative($path);
            // Anti option/pathspec-magic: un percorso non può iniziare con "-" (opzione) né ":" (magic).
            if ($path[0] === '-' || $path[0] === ':') {
                throw new \InvalidArgumentException('Percorso non valido: non può iniziare con "-" o ":".');
            }
            $paths[$path] = true; // dedup
        }
        if ($paths === []) {
            throw new \InvalidArgumentException('La lista "paths" non può essere vuota.');
        }
        $unique = array_keys($paths);
        sort($unique, SORT_STRING); // ordine deterministico, indipendente dall'input

        return $unique;
    }

    /** @return array{path:string,content:string} */
    private static function wholeFile(array $data, CodePatchLimits $limits): array
    {
        $path = self::file($data);
        $content = $data['content'] ?? null;
        if (!is_string($content)) {
            throw new \InvalidArgumentException('Manca il campo "content" (stringa) per propose_file.');
        }
        if (strpos($content, "\0") !== false) {
            throw new \InvalidArgumentException('Il contenuto di propose_file non può contenere byte NUL.');
        }
        if (strlen($content) > $limits->maxFileBytes) {
            throw new \InvalidArgumentException('Il contenuto di propose_file supera il limite ammesso.');
        }

        return ['path' => $path, 'content' => $content];
    }

    /**
     * Query di ricerca: solo scalare stringa, senza caratteri di controllo (una query multilinea
     * o con NUL sarebbe un tentativo di iniettare struttura nel dialogo), tagliata al tetto.
     *
     * @param array<string, mixed> $data
     */
    private static function query(array $data, CodeAgentLimits $limits): string
    {
        $raw = $data['query'] ?? null;
        if (!is_string($raw)) {
            throw new \InvalidArgumentException('Manca il campo "query" (stringa) per questa azione.');
        }
        $clean = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', Utf8::clean($raw)) ?? '');
        if ($clean === '') {
            throw new \InvalidArgumentException('La "query" non può essere vuota.');
        }

        return Utf8::cut($clean, $limits->maxQueryChars);
    }

    /**
     * Directory: '' (root) è ammesso; qualunque altro valore deve essere relativo e canonico.
     *
     * @param array<string, mixed> $data
     */
    private static function directory(array $data): string
    {
        $raw = $data['path'] ?? '';
        if (!is_string($raw)) {
            throw new \InvalidArgumentException('Il campo "path" deve essere una stringa.');
        }
        $path = self::normalize($raw);
        if ($path === '') {
            return '';
        }
        self::assertRelative($path);

        return $path;
    }

    /**
     * File: il percorso è obbligatorio, relativo e canonico. Assoluti, `..` e backslash sono
     * rifiutati QUI per forma, e comunque non passerebbero il PathGuard.
     *
     * @param array<string, mixed> $data
     */
    private static function file(array $data): string
    {
        $raw = $data['path'] ?? null;
        if (!is_string($raw)) {
            throw new \InvalidArgumentException('Manca il campo "path" (stringa) per questa azione.');
        }
        $path = self::normalize($raw);
        if ($path === '') {
            throw new \InvalidArgumentException('Il "path" del file non può essere vuoto.');
        }
        self::assertRelative($path);

        return $path;
    }

    /** Ripulitura MINIMA e conservativa: `./` iniziale e `/` finale. Nessun'altra indulgenza. */
    private static function normalize(string $path): string
    {
        $clean = trim(Utf8::clean($path));
        while (str_starts_with($clean, './')) {
            $clean = substr($clean, 2);
        }

        return rtrim($clean, '/');
    }

    /**
     * Il messaggio torna al modello: dice COSA è sbagliato senza mai citare il valore ricevuto
     * (potrebbe essere testo ostile proveniente da un file letto poco prima).
     */
    private static function assertRelative(string $path): void
    {
        try {
            RelativePath::assert($path);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException(
                'Percorso non valido: usa un percorso RELATIVO alla cartella, senza "..", senza "/" iniziale.'
            );
        }
    }
}
