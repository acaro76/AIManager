<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Cancellation\CancellationStore;
use App\Core\Code\CodeChatSchema;
use App\Core\Code\CodeConversationRepository;
use App\Core\Code\CodePatchMutationService;
use App\Core\Code\CodePatchOperationRepository;
use App\Core\Code\CodePostApplyService;
use App\Core\Code\CodePatchSchema;
use App\Core\Code\CodePatchStore;
use App\Core\Code\CommandConfirmService;
use App\Core\Code\CommandRunRepository;
use App\Core\Code\CommandRunSchema;
use App\Core\Code\ProcessConfirmService;
use App\Core\Code\ProcessRunRecord;
use App\Core\Code\ProcessRunRepository;
use App\Core\Code\ProcessRunSchema;
use App\Core\Code\CodeProviderMode;
use App\Core\Code\CodeSessionRepository;
use App\Core\Code\CodeSseWriter;
use App\Core\Code\CodeUploadReader;
use App\Core\Code\CodeWorkspaceException;
use App\Core\Code\CodeWorkspaceRepository;
use App\Core\Code\MacFolderPicker;
use App\Core\Code\RelativePath;
use App\Core\Code\Utf8;
use App\Core\Request;
use App\Core\Response;
use App\Services\CodeChatService;
use App\Services\ConversationTitleService;

/**
 * Code — ambiente top-level autonomo (`/code`): elenco dei workspace (cartelle), apertura di
 * una cartella, ambiente conversazionale confinato, revoca, e la
 * chat Code read-only (F1.5) in streaming SSE.
 *
 * Nessun legame con `projects` / sessioni LLM / ContextEngine. La cartella È il progetto Code.
 * La root arriva SOLO da un campo del form (POST + CSRF), mai dal prompt.
 *
 * SCHEMA CHAT: finché la migrazione dedicata non è applicata, le tabelle chat NON esistono.
 * Ogni superficie consulta prima CodeChatSchema::state() e, se non è `ready`, NON esegue
 * alcuna query sulle tabelle dedicate (stato non interattivo, oppure fail closed).
 */
final class CodeController extends BaseController
{
    /** Turni di storico mostrati nella pagina. */
    private const HISTORY_TURNS = 50;

    /**
     * Formato accettato da CancellationStore: un id fuori formato verrebbe silenziosamente
     * ridotto a stringa vuota, rendendo lo Stop inefficace. Qui si valida ESPLICITAMENTE.
     */
    private const REQUEST_ID_PATTERN = '/^[A-Za-z0-9_-]{12,80}$/';
    private const FILE_PREVIEW_BYTES = 131072;

    public function index(Request $request): void
    {
        $workspaceRepo = new CodeWorkspaceRepository($this->app->db);

        // Entrare in Code significa riprendere il lavoro: prima l'ultima sessione attiva
        // appartenente a una root ancora valida, poi l'ultima cartella valida senza sessioni.
        $showAuthorize = (string) $request->input('add', '') === '1';
        if (!$showAuthorize && CodeChatSchema::state($this->app->db) === CodeChatSchema::STATE_READY) {
            foreach ((new CodeSessionRepository($this->app->db))->recentActiveAcrossWorkspaces() as $recent) {
                $workspace = $workspaceRepo->findById((int) $recent['workspace_id']);
                if ($workspace !== null && $workspace->rootIsValid()) {
                    Response::redirect('/code/workspace?id=' . $workspace->id . '&session_id=' . (int) $recent['id']);
                }
            }
        }
        if (!$showAuthorize) {
            foreach ($workspaceRepo->activeByRecentUse() as $workspace) {
                if ($workspace->rootIsValid()) {
                    Response::redirect('/code/workspace?id=' . $workspace->id);
                }
            }
        }

        $this->view('code/index', [
            'title' => 'Code',
            'hideTopbar' => true,
            'workspaces' => $workspaceRepo->all(),
        ]);
    }

    public function open(Request $request): never
    {
        $this->guard($request);
        $path = trim((string) $request->input('path', ''));

        try {
            $workspace = (new CodeWorkspaceRepository($this->app->db))->authorizeRoot($path);
            $sessionId = null;
            if (CodeChatSchema::state($this->app->db) === CodeChatSchema::STATE_READY) {
                $sessionRepo = new CodeSessionRepository($this->app->db);
                foreach ($sessionRepo->listByWorkspace($workspace->id) as $candidate) {
                    if ((string) $candidate['status'] === 'active') {
                        $sessionId = (int) $candidate['id'];
                        break;
                    }
                }
                $sessionId ??= $sessionRepo->create($workspace->id, 'Nuova sessione');
            }
        } catch (CodeWorkspaceException $exception) {
            $this->flash('Impossibile aprire la cartella: ' . $exception->getMessage(), '/code');
        } catch (\Throwable $exception) {
            error_log('[code] open error: ' . $exception->getMessage());
            $this->flash('Impossibile aprire la cartella per un errore interno.', '/code');
        }

        $target = '/code/workspace?id=' . $workspace->id;
        if ($sessionId !== null) {
            $target .= '&session_id=' . $sessionId;
        }
        Response::redirect($target);
    }

    /** Apre il selettore nativo; l'autorizzazione resta nell'azione POST `open`. */
    public function pickFolder(Request $request): never
    {
        $this->guard($request);
        try {
            $path = (new MacFolderPicker())->pick();
        } catch (\RuntimeException $exception) {
            Response::json(['ok' => false, 'message' => $exception->getMessage()], 501);
        }
        if ($path === null) {
            Response::json(['ok' => false, 'cancelled' => true, 'message' => 'Selezione annullata.']);
        }
        Response::json(['ok' => true, 'path' => $path]);
    }

    public function workspace(Request $request): void
    {
        $repo = new CodeWorkspaceRepository($this->app->db);
        $workspace = $repo->findById((int) $request->input('id'));

        if ($workspace === null) {
            $this->flash('Workspace non disponibile.', '/code');
        }

        // La pagina non esegue più uno scan preventivo: il recupero mirato avviene soltanto
        // dopo una domanda. Qui basta verificare che la root autorizzata sia ancora valida.
        $accessRevoked = $workspace->status === 'revoked';
        $error = $accessRevoked || $workspace->rootIsValid()
            ? null
            : 'La cartella non è più valida (spostata, eliminata o inaccessibile). Riautorizzala.';

        // Chat: si consulta lo STATO dello schema prima di qualunque query. Se le tabelle non
        // ci sono (migrazione non ancora applicata) la pagina resta pienamente funzionante.
        $chatState = CodeChatSchema::state($this->app->db);
        $sessions = [];
        $session = null;
        $history = [];
        $historyCommands = [];
        $historyProcesses = [];
        $historyGit = [];
        $activeProcesses = [];
        $patchProposals = [];

        if ($chatState === CodeChatSchema::STATE_READY) {
            $sessionRepo = new CodeSessionRepository($this->app->db);
            $sessions = $sessionRepo->listByWorkspace($workspace->id);

            $sessionId = (int) $request->input('session_id', 0);
            if ($sessionId < 1) {
                foreach ($sessions as $candidate) {
                    if ((string) $candidate['status'] === 'active') {
                        Response::redirect('/code/workspace?id=' . $workspace->id . '&session_id=' . (int) $candidate['id']);
                    }
                }
            }
            if ($sessionId > 0) {
                // Selezione SEMPRE scoped: una root non apre le sessioni di un'altra.
                $session = $sessionRepo->findForWorkspace($sessionId, $workspace->id);
                if ($session !== null) {
                    $history = (new CodeConversationRepository($this->app->db))
                        ->historyForWorkspace($sessionId, $workspace->id, self::HISTORY_TURNS);
                    $patchProposals = $this->loadProposals($workspace->id, $sessionId);
                    // Comandi per turno (Fase 6): card ricostruite dopo refresh (pendenti + terminali).
                    // La tabella può non esistere (036 non applicata): in tal caso nessun comando.
                    if (CommandRunSchema::state($this->app->db) === CommandRunSchema::STATE_READY) {
                        // Fase 10 / Step 3 — manutenzione già esistente PRIMA di ricostruire le card:
                        // proposte scadute → expired, orfani running → error (solo DB, nessun segnale).
                        $this->commandService()->maintain();
                        $historyCommands = (new CommandRunRepository($this->app->db))
                            ->forHistory($workspace->id, $sessionId);
                    }
                    // Processi per turno (Fase 7): card ricostruite dopo refresh (pendenti + terminali).
                    // Prima si riconciliano le righe attive con la realtà (processo morto → orphaned,
                    // solo DB, nessun segnale). La tabella può non esistere (037 non applicata).
                    if (ProcessRunSchema::state($this->app->db) === ProcessRunSchema::STATE_READY) {
                        if ((bool) ($this->app->config['code']['processes'] ?? false)) {
                            $this->processService()->maintain($workspace->id, $sessionId);
                        }
                        $processRepo = new ProcessRunRepository($this->app->db);
                        $historyProcesses = $processRepo->forHistory($workspace->id, $sessionId);
                        $activeProcesses = array_map(
                            static fn (array $row): array => ProcessRunRecord::fromRow($row),
                            $processRepo->listActive($workspace->id, $sessionId)
                        );
                    }
                    if ($this->app->db->fetch("SELECT name FROM sqlite_master WHERE type='table' AND name='code_git_operations'") !== null) {
                        // Fase 10 / Step 3 — scadi le proposte Git oltre TTL PRIMA di leggerne lo storico.
                        $gitRepo = new \App\Core\Code\GitOperationRepository($this->app->db);
                        $gitRepo->expire();
                        $historyGit = $gitRepo->forHistory($workspace->id, $sessionId);
                    }
                }
            }
        }

        $this->view('code/workspace', [
            'title' => 'Code · ' . basename($workspace->rootPath),
            'hideTopbar' => true,
            'workspace' => $workspace,
            'accessRevoked' => $accessRevoked,
            'error' => $error,
            'chatState' => $chatState,
            'sessions' => $sessions,
            'session' => $session,
            'history' => $history,
            'historyCommands' => $historyCommands,
            'historyProcesses' => $historyProcesses,
            'historyGit' => $historyGit,
            'activeProcesses' => $activeProcesses,
            'patchProposals' => $patchProposals,
            'commandsEnabled' => (bool) ($this->app->config['code']['commands'] ?? false),
            'processesEnabled' => (bool) ($this->app->config['code']['processes'] ?? false),
            'writeEnabled' => (bool) ($this->app->config['code']['write'] ?? false),
            'codeProviderMode' => CodeProviderMode::resolve($this->app->config['code']['provider_mode'] ?? null),
        ]);
    }

    public function revoke(Request $request): never
    {
        $this->guard($request);
        $id = (int) $request->input('id');

        try {
            (new CodeWorkspaceRepository($this->app->db))->revoke($id);
        } catch (CodeWorkspaceException $exception) {
            $this->flash('Revoca non riuscita: ' . $exception->getMessage(), '/code');
        } catch (\Throwable $exception) {
            error_log('[code] revoke error: ' . $exception->getMessage());
            $this->flash('Revoca non riuscita per un errore interno.', '/code');
        }

        // Nessun flash di successo: il notice globale viene reso SOPRA la superficie Code e ne rompe il
        // layout a tutta altezza. L'esito riuscito si vede già dalla cartella, che passa a revocata.
        Response::redirect('/code');
    }

    /**
     * Creazione ESPLICITA di una sessione Code: azione POST (CSRF), mai una scrittura implicita
     * su GET. Poi redirect allo stesso workspace con `session_id`.
     */
    public function createSession(Request $request): never
    {
        $this->guard($request);

        if (CodeChatSchema::state($this->app->db) !== CodeChatSchema::STATE_READY) {
            $this->flash('Chat Code non disponibile.', '/code');
        }

        $workspaceId = (int) $request->input('workspace_id');
        $workspace = (new CodeWorkspaceRepository($this->app->db))->findById($workspaceId);
        if ($workspace === null || $workspace->status !== 'active') {
            $this->flash('Workspace non disponibile.', '/code');
        }

        try {
            $sessionId = (new CodeSessionRepository($this->app->db))->create($workspaceId, 'Nuova sessione');
        } catch (CodeWorkspaceException $exception) {
            $this->flash('Impossibile creare la sessione Code.', '/code/workspace?id=' . $workspaceId);
        } catch (\Throwable $exception) {
            error_log('[code] session create error: ' . $exception->getMessage());
            $this->flash('Impossibile creare la sessione Code.', '/code/workspace?id=' . $workspaceId);
        }

        Response::redirect('/code/workspace?id=' . $workspaceId . '&session_id=' . $sessionId);
    }

    /** Correzione manuale facoltativa del titolo generato automaticamente. */
    public function renameSession(Request $request): never
    {
        $this->guard($request);
        $workspaceId = (int) $request->input('workspace_id');
        $sessionId = (int) $request->input('session_id');
        try {
            $sessions = new CodeSessionRepository($this->app->db);
            $session = $sessions->findForWorkspace($sessionId, $workspaceId);
            if ($session === null || (new ConversationTitleService())->isProvisional((string) $session['title'])) {
                throw new CodeWorkspaceException('Il titolo può essere corretto dopo la denominazione automatica.');
            }
            $sessions->renameForWorkspace(
                $sessionId,
                $workspaceId,
                (string) $request->input('title', '')
            );
        } catch (\InvalidArgumentException|CodeWorkspaceException $exception) {
            $this->flash($exception->getMessage(), '/code/workspace?id=' . $workspaceId . '&session_id=' . $sessionId);
        }
        Response::redirect('/code/workspace?id=' . $workspaceId . '&session_id=' . $sessionId);
    }

    /** Elenco lazy dei figli immediati, sempre attraverso CodeWorkspace::children(). */
    public function children(Request $request): never
    {
        $workspace = $this->activeWorkspace((int) $request->input('workspace_id'));
        $path = (string) $request->input('path', '');
        try {
            if ($path !== '') {
                RelativePath::assert($path);
            }
            $children = $workspace->children($path);
        } catch (\InvalidArgumentException|CodeWorkspaceException $exception) {
            Response::json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
        Response::json(['ok' => true, 'children' => $children]);
    }

    /** Anteprima testuale read-only, confinata e con tetto byte fisso. */
    public function file(Request $request): never
    {
        $workspace = $this->activeWorkspace((int) $request->input('workspace_id'));
        $path = (string) $request->input('path', '');
        $line = max(1, (int) $request->input('line', 1));
        try {
            RelativePath::assert($path);
            $content = $workspace->readLimited($path, self::FILE_PREVIEW_BYTES);
        } catch (\InvalidArgumentException|CodeWorkspaceException $exception) {
            Response::json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
        if (str_contains($content, "\0")) {
            Response::json(['ok' => false, 'message' => 'Il file non è un documento di testo consultabile.'], 415);
        }
        Response::json([
            'ok' => true,
            'path' => str_replace('\\', '/', $path),
            'content' => Utf8::clean($content),
            'focus_line' => $line,
            'max_bytes' => self::FILE_PREVIEW_BYTES,
        ]);
    }

    /** Chat Code in streaming SSE (read-only). */
    public function chat(Request $request): never
    {
        $this->guard($request);

        $sse = new CodeSseWriter();

        if (CodeChatSchema::state($this->app->db) !== CodeChatSchema::STATE_READY) {
            $this->streamHeaders(409);
            $sse->error('Chat Code non disponibile.');
            exit;
        }

        $workspaceId = (int) $request->input('workspace_id');
        $sessionId = (int) $request->input('session_id');
        $prompt = trim((string) $request->input('prompt', ''));
        if ($prompt === '') {
            $this->streamHeaders(422);
            $sse->error('Scrivi un messaggio prima di inviare.');
            exit;
        }

        // Cancellazione: stesso request_id usato dallo Stop. Un id mancante o fuori formato
        // renderebbe lo Stop inefficace (token vuoto): si fallisce PRIMA di creare il token e
        // prima di avviare il service (nessuna scrittura, nessun turno user).
        $requestId = (string) $request->input('request_id', '');
        if (!$this->isValidRequestId($requestId)) {
            $this->streamHeaders(422);
            $sse->error('Richiesta non valida: identificativo mancante o non valido.');
            exit;
        }

        try {
            $attachments = (new CodeUploadReader())->read($_FILES['attachments'] ?? null);
        } catch (\InvalidArgumentException $exception) {
            $this->streamHeaders(422);
            $sse->error($exception->getMessage());
            exit;
        }

        $cancellations = $this->cancellations();
        $cancellations->prune();
        $cancellations->begin($requestId);
        // A fine richiesta (anche su abort) si pulisce SOLO il proprio file: nessuna
        // interferenza con altre richieste/sessioni in corso.
        register_shutdown_function(static function () use ($cancellations, $requestId): void {
            $cancellations->finish($requestId);
        });
        $token = $cancellations->token($requestId);

        $this->streamHeaders();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $this->disableOutputBuffering();

        // Provider mode deciso dalla CONFIGURAZIONE del server (nessun selettore in UI):
        // env AIMANAGER_CODE_PROVIDER_MODE → config code.provider_mode → default 'auto'.
        $providerMode = CodeProviderMode::resolve($this->app->config['code']['provider_mode'] ?? null);

        // Ciclo agente read-only: scelta di SERVER (nessun selettore in UI), come il provider mode.
        $agentEnabled = (bool) ($this->app->config['code']['agent'] ?? true);
        // Modifica sicura (Fase 4): abilita `propose_patch`. La proposta non scrive nulla; la
        // scrittura resta gated da conferma esplicita. Anch'essa è una scelta di server.
        $writeEnabled = (bool) ($this->app->config['code']['write'] ?? false);
        $patchStorageDir = $this->app->config['paths']['storage'] . '/code_patches';

        // Verifica del codice (Fase 5): scelta di SERVER (nessun selettore in UI). Abilita `run_check`
        // sui soli profili curati e rilevati. `verify_profiles` (null = tutti i curati) restringe.
        $verifyEnabled = (bool) ($this->app->config['code']['verify'] ?? false);
        $verifyProfilesCfg = $this->app->config['code']['verify_profiles'] ?? null;
        $verifyProfiles = is_array($verifyProfilesCfg) ? array_values(array_filter($verifyProfilesCfg, 'is_string')) : null;

        // Comandi locali (Fase 6): scelta di SERVER. Abilita `run_command` sulle sole utility di
        // lettura del registro chiuso. Ogni comando resta gated da conferma esplicita.
        $commandsEnabled = (bool) ($this->app->config['code']['commands'] ?? false);
        $commandStorageDir = $this->app->config['paths']['storage'] . '/code_commands';

        // Processi persistenti (Fase 7): scelta di SERVER. Abilita `start_process` sul solo profilo
        // curato (server PHP locale). Ogni avvio resta gated da conferma esplicita.
        $processesEnabled = (bool) ($this->app->config['code']['processes'] ?? false);

        // Git assistito READ-ONLY (Fase 8): scelta di SERVER. Abilita `git_status`/`git_diff` nel ciclo.
        // Nessuna operazione modificante o di rete; default disabilitato.
        $gitEnabled = (bool) ($this->app->config['code']['git'] ?? false);

        try {
            $result = (new CodeChatService(
                $this->app->db,
                agentEnabled: $agentEnabled,
                writeEnabled: $writeEnabled,
                patchStorageDir: $patchStorageDir,
                verifyEnabled: $verifyEnabled,
                verifyProfiles: $verifyProfiles,
                commandsEnabled: $commandsEnabled,
                commandStorageDir: $commandStorageDir,
                processesEnabled: $processesEnabled,
                gitEnabled: $gitEnabled
            ))->stream(
                $workspaceId,
                $sessionId,
                $prompt,
                static function (string $text, string $channel = 'content') use ($sse): void {
                    $sse->delta($text, $channel);
                },
                $token,
                $providerMode,
                $attachments
            );
        } catch (CodeWorkspaceException $exception) {
            // Validazioni iniziali (ownership, sessione archiviata, workspace revocato): nessuna
            // scrittura è avvenuta. Messaggio operativo, non tecnico.
            $sse->error($exception->getMessage());
            exit;
        } catch (\Throwable $exception) {
            error_log('[code] chat error: ' . $exception->getMessage());
            $sse->error('Errore interno durante la richiesta Code.');
            exit;
        }

        // Esito strutturato: status dice al client se il parziale è valido o va scartato.
        $sse->done($result);
        exit;
    }

    /** Stop: annulla la richiesta con lo STESSO request_id. */
    public function stopChat(Request $request): never
    {
        $this->guard($request);
        $requestId = (string) $request->input('request_id', '');
        if (!$this->isValidRequestId($requestId)) {
            Response::json(['ok' => false, 'message' => 'Identificativo di richiesta non valido.'], 422);
        }

        $accepted = $this->cancellations()->cancelPendingOrActive($requestId);

        Response::json([
            'ok' => $accepted,
            'message' => $accepted ? 'Richiesta interrotta.' : 'Richiesta già conclusa.',
        ], $accepted ? 200 : 409);
    }

    /**
     * Fase 4 — conferma ESPLICITA dell'applicazione di una proposta (POST + CSRF), legata a
     * operation_id + patch_digest e allo scope workspace/sessione. È l'UNICO ingresso alla
     * scrittura nel filesystem, e non parte mai da solo: nessuna scrittura senza questa conferma.
     */
    public function applyPatch(Request $request): never
    {
        $this->guard($request);
        $this->requirePatchSchema();
        $res = $this->patchService()->apply(
            (int) $request->input('workspace_id'),
            (int) $request->input('session_id'),
            (string) $request->input('operation_id', ''),
            (string) $request->input('patch_digest', '')
        );
        if (($res['status'] ?? '') === 'applied') {
            try {
                $res['completion'] = $this->postApplyService()->complete(
                    (int) $request->input('workspace_id'),
                    (int) $request->input('session_id'),
                    (string) $request->input('operation_id', ''),
                    (bool) ($this->app->config['code']['git'] ?? false),
                );
            } catch (\Throwable $exception) {
                error_log('[code] post-apply error: ' . $exception->getMessage());
                $res['completion'] = [
                    'ok' => false,
                    'status' => 'error',
                    'message' => 'File applicato. Verifica finale non disponibile.',
                ];
            }
        }
        Response::json($res, $this->patchHttpStatus($res['status']));
    }

    /** Recupera in modo idempotente la conclusione di una modifica già applicata. */
    public function completeAppliedPatch(Request $request): never
    {
        $this->guard($request);
        $this->requirePatchSchema();
        try {
            $res = $this->postApplyService()->complete(
                (int) $request->input('workspace_id'),
                (int) $request->input('session_id'),
                (string) $request->input('operation_id', ''),
                (bool) ($this->app->config['code']['git'] ?? false),
            );
        } catch (\Throwable $exception) {
            error_log('[code] post-apply recovery error: ' . $exception->getMessage());
            $res = ['ok' => false, 'status' => 'error', 'message' => 'Verifica finale non disponibile.'];
        }
        Response::json($res, ($res['ok'] ?? false) ? 200 : (($res['status'] ?? '') === 'not_found' ? 404 : 409));
    }

    /** Fase 4 — rifiuto esplicito di una proposta pendente (nessuna scrittura). */
    public function rejectPatch(Request $request): never
    {
        $this->guard($request);
        $this->requirePatchSchema();
        $res = $this->patchService()->reject(
            (int) $request->input('workspace_id'),
            (int) $request->input('session_id'),
            (string) $request->input('operation_id', '')
        );
        Response::json($res, $this->patchHttpStatus($res['status']));
    }

    /** Fase 4 — annullamento (rollback locale) di un'operazione applicata, indipendente da Git. */
    public function rollbackPatch(Request $request): never
    {
        $this->guard($request);
        $this->requirePatchSchema();
        $res = $this->patchService()->rollback(
            (int) $request->input('workspace_id'),
            (int) $request->input('session_id'),
            (string) $request->input('operation_id', '')
        );
        Response::json($res, $this->patchHttpStatus($res['status']));
    }

    /**
     * Fase 6 — conferma ESPLICITA ed esecuzione di un comando locale (POST + CSRF), legata a
     * `command_id` + `digest` e allo scope workspace/sessione, MONOUSO. È l'UNICO ingresso
     * all'esecuzione. La cancellazione usa il `command_id` come id: lo Stop dedicato lo segnala.
     */
    public function confirmCommand(Request $request): never
    {
        $this->guard($request);
        $this->requireCommandSchema();

        $workspaceId = (int) $request->input('workspace_id');
        $sessionId = (int) $request->input('session_id');
        $commandId = (string) $request->input('command_id', '');
        $digest = (string) $request->input('digest', '');
        if (!$this->isValidRequestId($commandId)) {
            Response::json(['ok' => false, 'status' => 'not_found', 'message' => 'Comando non valido.'], 404);
        }

        // Cancellazione keyed sul command_id: lo Stop dedicato scrive il marker, questa esecuzione
        // lo osserva. A fine richiesta si pulisce SOLO il proprio file.
        $cancellations = $this->cancellations();
        $cancellations->prune();
        $cancellations->begin($commandId);
        register_shutdown_function(static function () use ($cancellations, $commandId): void {
            $cancellations->finish($commandId);
        });
        $token = $cancellations->token($commandId);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $res = $this->commandService()->confirm($workspaceId, $sessionId, $commandId, $digest, $token);
        Response::json($res, $this->commandHttpStatus($res['status']));
    }

    /** Fase 6 — rifiuto esplicito di una proposta di comando pendente (nessuna esecuzione). */
    public function rejectCommand(Request $request): never
    {
        $this->guard($request);
        $this->requireCommandSchema();
        $res = $this->commandService()->reject(
            (int) $request->input('workspace_id'),
            (int) $request->input('session_id'),
            (string) $request->input('command_id', '')
        );
        Response::json($res, $this->commandHttpStatus($res['status']));
    }

    /**
     * Fase 6 — Stop DEDICATO all'esecuzione confermata in corso. Scrive il marker di cancellazione
     * (keyed sul command_id) che il runner osserva: l'esecuzione finalizza a `killed`. NON tocca il
     * turno chat già persistito (correzione #5).
     */
    public function stopCommand(Request $request): never
    {
        $this->guard($request);
        $this->requireCommandSchema();

        $workspaceId = (int) $request->input('workspace_id');
        $sessionId = (int) $request->input('session_id');
        $commandId = (string) $request->input('command_id', '');
        if (!$this->isValidRequestId($commandId)) {
            Response::json(['ok' => false, 'status' => 'not_found', 'message' => 'Comando non valido.'], 404);
        }
        // Solo se il comando appartiene allo scope ed è ancora pending/running: nessuna azione
        // altrimenti (idempotente, nessun marker orfano su un comando estraneo).
        if (!$this->commandService()->isRunningInScope($commandId, $workspaceId, $sessionId)) {
            Response::json(['ok' => false, 'status' => 'not_found', 'message' => 'Nessun comando in corso.'], 404);
        }
        $this->cancellations()->cancelPendingOrActive($commandId);
        Response::json(['ok' => true, 'status' => 'stopping']);
    }

    private function commandService(): CommandConfirmService
    {
        return new CommandConfirmService(
            $this->app->db,
            $this->app->config['paths']['storage'] . '/code_commands',
            $this->app->config['paths']['storage'] . '/code_runtime'
        );
    }

    /**
     * Fase 7 — conferma ESPLICITA ed AVVIO di un processo persistente (POST + CSRF), legata a
     * `process_id` + `digest` e allo scope workspace/sessione, MONOUSO. È l'UNICO ingresso all'avvio.
     */
    public function confirmProcess(Request $request): never
    {
        $this->guard($request);
        $this->requireProcessSchema();
        $res = $this->processService()->confirm(
            (int) $request->input('workspace_id'),
            (int) $request->input('session_id'),
            (string) $request->input('process_id', ''),
            (string) $request->input('digest', '')
        );
        Response::json($res, $this->processHttpStatus($res['status']));
    }

    /** Fase 7 — rifiuto esplicito di una proposta di processo pendente (nessun avvio). */
    public function rejectProcess(Request $request): never
    {
        $this->guard($request);
        $this->requireProcessSchema();
        $res = $this->processService()->reject(
            (int) $request->input('workspace_id'),
            (int) $request->input('session_id'),
            (string) $request->input('process_id', '')
        );
        Response::json($res, $this->processHttpStatus($res['status']));
    }

    /**
     * Fase 7 — ARRESTO di un processo persistente in corso. Verifica l'IDENTITÀ prima di segnalare;
     * se non verificabile o PID riciclato, marca `orphaned`/`error` SENZA segnalare. Idempotente.
     */
    public function stopProcess(Request $request): never
    {
        $this->guard($request);
        $this->requireProcessSchema();
        $res = $this->processService()->stop(
            (int) $request->input('workspace_id'),
            (int) $request->input('session_id'),
            (string) $request->input('process_id', '')
        );
        Response::json($res, $this->processHttpStatus($res['status']));
    }

    private function processService(): ProcessConfirmService
    {
        return new ProcessConfirmService(
            $this->app->db,
            $this->app->config['paths']['storage'] . '/code_process_runtime'
        );
    }

    public function confirmGitStage(Request $request): never
    {
        $this->guard($request); $this->requireGitSchema();
        $res=$this->gitConfirmService()->confirmStage((int)$request->input('workspace_id'),(int)$request->input('session_id'),(string)$request->input('operation_id',''),(string)$request->input('digest',''));
        Response::json($res,$this->gitHttpStatus($res['status']));
    }

    public function rejectGit(Request $request): never
    {
        $this->guard($request); $this->requireGitSchema();
        $res=$this->gitConfirmService()->reject((int)$request->input('workspace_id'),(int)$request->input('session_id'),(string)$request->input('operation_id',''));
        Response::json($res,$this->gitHttpStatus($res['status']));
    }

    public function proposeGitCommit(Request $request): never
    {
        $this->guard($request); $this->requireGitSchema();
        $res=$this->gitConfirmService()->proposeCommit((int)$request->input('workspace_id'),(int)$request->input('session_id'),(string)$request->input('operation_id',''),(string)$request->input('message',''));
        Response::json($res,$this->gitHttpStatus($res['status']));
    }

    public function createGitCommit(Request $request): never
    {
        $this->guard($request); $this->requireGitSchema();
        $res=$this->gitConfirmService()->createCommit((int)$request->input('workspace_id'),(int)$request->input('session_id'),(string)$request->input('operation_id',''),(string)$request->input('message',''));
        Response::json($res,$this->gitHttpStatus($res['status']));
    }

    public function confirmGitCommit(Request $request): never
    {
        $this->guard($request); $this->requireGitSchema();
        $res=$this->gitConfirmService()->confirmCommit((int)$request->input('workspace_id'),(int)$request->input('session_id'),(string)$request->input('operation_id',''),(string)$request->input('digest',''));
        Response::json($res,$this->gitHttpStatus($res['status']));
    }

    private function gitConfirmService(): \App\Core\Code\GitConfirmService
    {
        return \App\Core\Code\GitConfirmService::withDefaults($this->app->db);
    }

    private function requireGitSchema(): void
    {
        $row=$this->app->db->fetch("SELECT name FROM sqlite_master WHERE type='table' AND name='code_git_operations'");
        if($row===null) Response::json(['ok'=>false,'status'=>'unavailable','message'=>'Git assistito non disponibile.'],409);
    }

    private function gitHttpStatus(string $status): int
    {
        return match($status){'staged','committed','rejected','commit_pending'=>200,'not_found'=>404,default=>409};
    }

    /** Fail closed: senza lo schema dei processi non si conferma/avvia/arresta nulla. */
    private function requireProcessSchema(): void
    {
        if (ProcessRunSchema::state($this->app->db) !== ProcessRunSchema::STATE_READY) {
            Response::json(['ok' => false, 'status' => 'unavailable', 'message' => 'Processi non disponibili.'], 409);
        }
    }

    /** @return int codice HTTP per l'esito strutturato del process service */
    private function processHttpStatus(string $status): int
    {
        return match ($status) {
            'running', 'stopped', 'rejected', 'orphaned', 'failed' => 200,
            'not_found' => 404,
            default => 409, // unavailable | denied | stale | expired | error
        };
    }

    /** Fail closed: senza lo schema dei comandi non si conferma/esegue nulla. */
    private function requireCommandSchema(): void
    {
        if (CommandRunSchema::state($this->app->db) !== CommandRunSchema::STATE_READY) {
            Response::json(['ok' => false, 'status' => 'unavailable', 'message' => 'Comandi non disponibili.'], 409);
        }
    }

    /** @return int codice HTTP per l'esito strutturato del confirm service */
    private function commandHttpStatus(string $status): int
    {
        return match ($status) {
            'completed', 'failed', 'timed_out', 'killed', 'rejected' => 200,
            'not_found' => 404,
            default => 409, // unavailable | denied | stale | expired | error
        };
    }

    /**
     * Proposte da ri-mostrare nel flusso chat: pendenti (`proposed`) e riepiloghi conclusi
     * (`applied`, `rejected`). Mappa per turno assistant. Il diff arriva dal
     * payload locale; se manca, la card resta minimale (solo percorsi). Nessun contatto col
     * filesystem del workspace.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function loadProposals(int $workspaceId, int $sessionId): array
    {
        if (CodePatchSchema::state($this->app->db) !== CodePatchSchema::STATE_READY) {
            return [];
        }
        $store = new CodePatchStore($this->patchStorageDir());
        $rows = (new CodePatchOperationRepository($this->app->db))
            ->listForSession($workspaceId, $sessionId, ['proposed', 'applied', 'rejected']);

        $byTurn = [];
        foreach ($rows as $row) {
            // Fase 10 / Step 3 — una proposta scaduta (proposed oltre expires_at) non ricompare come
            // pendente. Filtro di sola lettura: nessuna mutazione del workspace durante una GET.
            if (!CodePatchOperationRepository::isPendingVisible($row)) {
                continue;
            }
            $assistantId = $row['assistant_conversation_id'] === null ? 0 : (int) $row['assistant_conversation_id'];
            if ($assistantId < 1) {
                continue; // senza turno a cui agganciare la card, non la si mostra inline
            }
            $operationId = (string) $row['operation_id'];
            $payload = $store->read($operationId);

            $files = [];
            if ($payload !== null) {
                foreach ($payload['operations'] as $op) {
                    $files[] = [
                        'path' => (string) $op['path'],
                        'op' => (string) $op['op'],
                        'added' => (int) $op['added'],
                        'removed' => (int) $op['removed'],
                        'diff' => (string) $op['diff'],
                    ];
                }
            } else {
                foreach ((array) json_decode((string) $row['files_json'], true) as $meta) {
                    if (is_array($meta)) {
                        $files[] = ['path' => (string) ($meta['path'] ?? ''), 'op' => (string) ($meta['op'] ?? ''), 'added' => 0, 'removed' => 0, 'diff' => ''];
                    }
                }
            }

            $byTurn[$assistantId][] = [
                'operation_id' => $operationId,
                'patch_digest' => (string) $row['patch_digest'],
                'status' => (string) $row['status'],
                'files' => $files,
            ];
        }

        return $byTurn;
    }

    private function patchService(): CodePatchMutationService
    {
        return new CodePatchMutationService($this->app->db, $this->patchStorageDir());
    }

    private function postApplyService(): CodePostApplyService
    {
        return new CodePostApplyService($this->app->db);
    }

    private function patchStorageDir(): string
    {
        return $this->app->config['paths']['storage'] . '/code_patches';
    }

    /** Fail closed: senza lo schema delle operazioni patch non si applica/annulla nulla. */
    private function requirePatchSchema(): void
    {
        if (CodePatchSchema::state($this->app->db) !== CodePatchSchema::STATE_READY) {
            Response::json(['ok' => false, 'status' => 'unavailable', 'message' => 'Modifica sicura non disponibile.'], 409);
        }
    }

    /** @return int codice HTTP per l'esito strutturato del mutation service */
    private function patchHttpStatus(string $status): int
    {
        return match ($status) {
            'applied', 'rolled_back', 'rejected' => 200,
            'not_found' => 404,
            default => 409, // busy | denied | stale | expired | failed | rollback_denied | rollback_cancelled
        };
    }

    /** Un id fuori formato non sarebbe cancellabile: va rifiutato, non ignorato. */
    private function isValidRequestId(string $requestId): bool
    {
        return preg_match(self::REQUEST_ID_PATTERN, $requestId) === 1;
    }

    private function cancellations(): CancellationStore
    {
        return new CancellationStore($this->app->config['paths']['storage'] . '/cancellations');
    }

    private function activeWorkspace(int $id): \App\Core\Code\CodeWorkspace
    {
        $workspace = (new CodeWorkspaceRepository($this->app->db))->findById($id);
        if ($workspace === null || $workspace->status !== 'active') {
            Response::json(['ok' => false, 'message' => 'Workspace non disponibile o revocato.'], 404);
        }
        return $workspace;
    }

    private function streamHeaders(int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
    }

    private function disableOutputBuffering(): void
    {
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        ob_implicit_flush(true);
    }
}
