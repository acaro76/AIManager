<?php

use App\Core\View;
use App\Core\Code\CodeChatSchema;
use App\Core\Code\CodeSessionRepository;
use App\Core\Code\CodeWorkspaceRepository;
use App\Core\Code\ProcessRunRepository;
use App\Core\Code\ProcessRunSchema;
use App\Core\Code\PendingOperationService;
use App\Models\Project;
use App\Models\Session;
use App\Models\Setting;
use App\Services\ConversationTitleService;

$current = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$isChatSurface = in_array($current, ['/chat', '/chat/free'], true);
$isSimpleSurface = in_array($current, ['/chat', '/chat/free', '/choose-project'], true);
// Superficie Code: ambiente isolato dai progetti/chat LLM. Qui NON si devono nemmeno
// eseguire le query a Project/Session (non basta nascondere l'HTML dopo averle fatte).
$isCodeSurface = str_starts_with($current, '/code');
$expertNav = [
    '/' => 'Dashboard',
    '/providers' => 'Provider',
    '/plugins' => 'Plugin',
];
$chatLink = '/chat/free?new=1';
$projectsLink = '/projects';
$expertActive = array_key_exists($current, $expertNav);
$cssPath = $app->root . '/public/assets/css/app.css';
$jsPath = $app->root . '/public/assets/js/app.js';
$cssVersion = (filemtime($cssPath) ?: time()) . '-' . substr((string) hash_file('crc32b', $cssPath), 0, 8);
$jsVersion = (filemtime($jsPath) ?: time()) . '-' . substr((string) hash_file('crc32b', $jsPath), 0, 8);
$shutdownActiveProcessCount = ProcessRunSchema::state($app->db) === ProcessRunSchema::STATE_READY
    ? count((new ProcessRunRepository($app->db))->listAllActive())
    : 0;
$shutdownPendingCount = (new PendingOperationService($app->db, $app->config['paths']['storage']))->countAll();
// Cache busting DEDICATO: ogni bundle ha una vita propria: usare $jsVersion (derivato da
// app.js) lascerebbe l'URL invariato quando cambia solo il client Code o il renderer condiviso.
$markdownJsPath = $app->root . '/public/assets/js/markdown.js';
$markdownJsVersion = is_file($markdownJsPath)
    ? (filemtime($markdownJsPath) ?: time()) . '-' . substr((string) hash_file('crc32b', $markdownJsPath), 0, 8)
    : (string) time();
$codeJsPath = $app->root . '/public/assets/js/code-chat.js';
$codeJsVersion = is_file($codeJsPath)
    ? (filemtime($codeJsPath) ?: time()) . '-' . substr((string) hash_file('crc32b', $codeJsPath), 0, 8)
    : (string) time();
// Su Code non si interrogano affatto i modelli/tabelle delle chat LLM.
if ($isCodeSurface) {
    $freeChatSessions = [];
} else {
    $freeChatProject = (new Project())->genericChatProject();
    $freeChatSessions = (new Session())->recentForProject((int) $freeChatProject['id'], 8);
}
$codeNavWorkspaces = [];
$codeNavSessions = [];
$codeTitleService = $isCodeSurface ? new ConversationTitleService() : null;
$currentCodeWorkspace = null;
$currentCodeWorkspaceId = $isCodeSurface ? (int) ($_GET['id'] ?? 0) : 0;
$currentCodeSessionId = $isCodeSurface ? (int) ($_GET['session_id'] ?? 0) : 0;
if ($isCodeSurface) {
    $codeWorkspaceRepo = new CodeWorkspaceRepository($app->db);
    $codeNavWorkspaces = $codeWorkspaceRepo->activeByRecentUse();
    $knownCodeWorkspaceIds = array_fill_keys(array_map(
        static fn ($workspace): int => $workspace->id,
        $codeNavWorkspaces
    ), true);
    foreach ($codeWorkspaceRepo->all() as $candidate) {
        if (!isset($knownCodeWorkspaceIds[$candidate->id])) {
            $codeNavWorkspaces[] = $candidate;
        }
    }
    foreach ($codeNavWorkspaces as $candidate) {
        if ($candidate->id === $currentCodeWorkspaceId) {
            $currentCodeWorkspace = $candidate;
            break;
        }
    }
    if (CodeChatSchema::state($app->db) === CodeChatSchema::STATE_READY) {
        $codeSessionRepo = new CodeSessionRepository($app->db);
        foreach ($codeNavWorkspaces as $codeNavWorkspace) {
            $codeNavSessions[$codeNavWorkspace->id] = $codeSessionRepo->listByWorkspace($codeNavWorkspace->id);
        }
    }
}
$appTheme = (new Setting())->get('app_theme', 'dark') ?: 'dark';
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e(($title ?? 'Dashboard') . ' - AIManager') ?></title>
    <script>
        document.documentElement.dataset.theme = localStorage.getItem('aimanager-theme') || '<?= View::e($appTheme) ?>';
    </script>
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= View::e($cssVersion) ?>">
    <!-- Renderer markdown condiviso: `defer` conserva l'ordine del documento, quindi è pronto
         prima che app.js e code-chat.js lo usino. -->
    <script defer src="/assets/js/markdown.js?v=<?= View::e($markdownJsVersion) ?>"></script>
    <script defer src="/assets/js/app.js?v=<?= View::e($jsVersion) ?>"></script>
    <?php if ($isCodeSurface): ?>
        <script defer src="/assets/js/code-chat.js?v=<?= View::e($codeJsVersion) ?>"></script>
    <?php endif; ?>
</head>
<body>
    <div class="shell <?= $isSimpleSurface ? 'user-shell' : '' ?> <?= $isChatSurface ? 'chat-surface-shell' : '' ?> <?= $isCodeSurface ? 'code-surface-shell' : '' ?>">
        <aside class="sidebar <?= $isSimpleSurface ? 'user-sidebar' : '' ?>">
            <a class="brand" href="/">
                <span class="brand-mark">AI</span>
                <span>
                    <strong>AIManager</strong>
                    <small>Centro locale</small>
                </span>
            </a>
            <nav class="nav primary-nav">
                <?php if ($isCodeSurface && $currentCodeWorkspaceId > 0 && $currentCodeWorkspace?->status === 'active'): ?>
                    <form class="code-primary-action" method="post" action="/code/session/create">
                        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                        <input type="hidden" name="workspace_id" value="<?= $currentCodeWorkspaceId ?>">
                        <button type="submit">Nuova sessione</button>
                    </form>
                <?php elseif ($isCodeSurface && $currentCodeWorkspaceId > 0): ?>
                    <form class="code-primary-action" method="post" action="/code/open" data-code-folder-form>
                        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                        <input type="hidden" name="path" value="">
                        <button type="button" data-code-folder-picker>Riautorizza cartella</button>
                        <span class="sr-only" data-code-folder-picker-status role="status" aria-live="polite"></span>
                    </form>
                <?php elseif ($isCodeSurface): ?>
                    <a href="/code#code-authorize">Nuova sessione</a>
                <?php else: ?>
                    <a class="<?= $current === '/chat/free' && isset($_GET['new']) ? 'active' : '' ?>" href="<?= View::e($chatLink) ?>">Nuova chat</a>
                <?php endif; ?>
                <a class="<?= $current === '/projects' ? 'active' : '' ?>" href="<?= View::e($projectsLink) ?>">Progetti</a>
                <?php if ($isCodeSurface): ?>
                    <div class="code-nav-heading">
                        <a class="active" href="/code">Code</a>
                        <a href="/code?add=1" aria-label="Autorizza un'altra cartella" title="Autorizza cartella">＋</a>
                    </div>
                <?php else: ?>
                    <a href="/code">Code</a>
                <?php endif; ?>
            </nav>
            <?php if ($isCodeSurface && $codeNavWorkspaces): ?>
                <div class="code-nav-tree" aria-label="Cartelle e sessioni Code">
                    <?php foreach ($codeNavWorkspaces as $codeNavWorkspace): ?>
                        <?php
                        $codeFolderLabel = $codeNavWorkspace->name !== '' ? $codeNavWorkspace->name : basename($codeNavWorkspace->rootPath);
                        $folderSessions = array_values(array_filter(
                            $codeNavSessions[$codeNavWorkspace->id] ?? [],
                            static fn (array $item): bool => (string) ($item['status'] ?? '') === 'active'
                        ));
                        $visibleSessions = array_slice($folderSessions, 0, 5);
                        $hiddenSessions = array_slice($folderSessions, 5);
                        $folderCurrent = $currentCodeWorkspaceId === $codeNavWorkspace->id;
                        ?>
                        <?php $folderRevoked = $codeNavWorkspace->status === 'revoked'; ?>
                        <section class="code-nav-folder <?= $folderCurrent ? 'current' : '' ?> <?= $folderRevoked ? 'revoked' : '' ?>">
                            <div class="code-nav-folder-row">
                                <a href="/code/workspace?id=<?= $codeNavWorkspace->id ?>" title="<?= View::e($codeNavWorkspace->rootPath) ?>">
                                    <span aria-hidden="true"><?= $folderRevoked ? '🔒' : '▱' ?></span>
                                    <strong><?= View::e($codeFolderLabel) ?></strong>
                                </a>
                                <?php if (!$folderRevoked): ?>
                                    <form method="post" action="/code/session/create" class="code-nav-new-session">
                                        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                                        <input type="hidden" name="workspace_id" value="<?= $codeNavWorkspace->id ?>">
                                        <button type="submit" aria-label="Nuova sessione in <?= View::e($codeFolderLabel) ?>" title="Nuova sessione">＋</button>
                                    </form>
                                <?php endif; ?>
                                <?php /* Azioni della cartella: menu esplicito nella riga, come i menu di sessione. Sta
                                        DENTRO la sidebar, quindi nessun ancestor con overflow lo taglia, e resta
                                        raggiungibile a ogni larghezza. */ ?>
                                <details class="code-nav-folder-menu" data-code-folder-menu="<?= $codeNavWorkspace->id ?>">
                                    <summary aria-label="Azioni per <?= View::e($codeFolderLabel) ?>" title="Azioni cartella">•••</summary>
                                    <div class="code-nav-folder-popover">
                                        <?php if ($folderRevoked): ?>
                                            <form method="post" action="/code/open" data-code-folder-form>
                                                <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                                                <input type="hidden" name="path" value="">
                                                <button type="button" data-code-folder-picker>Riautorizza</button>
                                                <span class="sr-only" data-code-folder-picker-status role="status" aria-live="polite"></span>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" action="/code/session/create">
                                                <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                                                <input type="hidden" name="workspace_id" value="<?= $codeNavWorkspace->id ?>">
                                                <button type="submit">Nuova sessione</button>
                                            </form>
                                            <?php /* La conferma di questa revoca è il dialog in fondo alla sidebar, non il
                                                    confirm nativo del browser che serve gli altri form dell'app. */ ?>
                                            <form method="post" action="/code/revoke" data-code-revoke-form>
                                                <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                                                <input type="hidden" name="id" value="<?= $codeNavWorkspace->id ?>">
                                                <button class="danger" type="submit">Revoca accesso</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </details>
                            </div>

                            <nav class="code-nav-sessions" aria-label="Sessioni di <?= View::e($codeFolderLabel) ?>">
                                <?php if (!$visibleSessions): ?>
                                    <span class="code-nav-empty">Nessuna sessione</span>
                                <?php endif; ?>
                                <?php foreach ($visibleSessions as $codeNavSession): ?>
                                    <?php
                                    $codeSessionTitle = trim((string) $codeNavSession['title']) ?: 'Sessione #' . (int) $codeNavSession['id'];
                                    $codeSessionCurrent = $folderCurrent && $currentCodeSessionId === (int) $codeNavSession['id'];
                                    $codeSessionCanRename = $codeTitleService !== null && !$codeTitleService->isProvisional($codeSessionTitle);
                                    ?>
                                    <div class="code-nav-session-row">
                                        <a class="<?= $codeSessionCurrent ? 'active' : '' ?>"
                                           data-code-session-nav="<?= (int) $codeNavSession['id'] ?>"
                                           href="/code/workspace?id=<?= $codeNavWorkspace->id ?>&session_id=<?= (int) $codeNavSession['id'] ?>">
                                            <?= View::e($codeSessionTitle) ?>
                                        </a>
                                        <details class="code-nav-session-menu" data-code-session-rename-menu="<?= (int) $codeNavSession['id'] ?>"
                                                 <?= $codeSessionCanRename ? '' : 'hidden' ?>>
                                            <summary aria-label="Correggi il titolo di <?= View::e($codeSessionTitle) ?>">•••</summary>
                                            <div class="code-nav-session-popover">
                                                <form method="post" action="/code/session/rename">
                                                    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                                                    <input type="hidden" name="workspace_id" value="<?= $codeNavWorkspace->id ?>">
                                                    <input type="hidden" name="session_id" value="<?= (int) $codeNavSession['id'] ?>">
                                                    <label>Correggi titolo
                                                        <input name="title" value="<?= View::e($codeSessionTitle) ?>" required maxlength="120"
                                                               data-code-session-title-input="<?= (int) $codeNavSession['id'] ?>">
                                                    </label>
                                                    <button type="submit">Rinomina</button>
                                                </form>
                                            </div>
                                        </details>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ($hiddenSessions): ?>
                                    <details class="code-nav-more">
                                        <summary>Mostra di più</summary>
                                        <?php foreach ($hiddenSessions as $codeNavSession): ?>
                                            <?php $codeSessionTitle = trim((string) $codeNavSession['title']) ?: 'Sessione #' . (int) $codeNavSession['id']; ?>
                                            <div class="code-nav-session-row">
                                                <a class="<?= $folderCurrent && $currentCodeSessionId === (int) $codeNavSession['id'] ? 'active' : '' ?>"
                                                   data-code-session-nav="<?= (int) $codeNavSession['id'] ?>"
                                                   href="/code/workspace?id=<?= $codeNavWorkspace->id ?>&session_id=<?= (int) $codeNavSession['id'] ?>">
                                                    <?= View::e($codeSessionTitle) ?>
                                                </a>
                                            </div>
                                        <?php endforeach; ?>
                                    </details>
                                <?php endif; ?>
                            </nav>
                        </section>
                    <?php endforeach; ?>
                </div>
                <?php /* Conferma della revoca: UNO per la sidebar, non uno per cartella. `method="dialog"`
                        chiude nativamente e porta la scelta in `returnValue`; Escape chiude con valore
                        vuoto, quindi non revoca. Il form POST resta quello della cartella. */ ?>
                <dialog class="confirm-dialog" data-code-revoke-dialog aria-labelledby="code-revoke-title">
                    <form method="dialog">
                        <h2 id="code-revoke-title">Revocare l'accesso a questa cartella?</h2>
                        <div class="confirm-dialog-actions">
                            <button class="button ghost" value="cancel" autofocus>Annulla</button>
                            <button class="button danger" value="confirm" data-code-revoke-accept>Revoca accesso</button>
                        </div>
                    </form>
                </dialog>
            <?php endif; ?>
            <?php if ($freeChatSessions): ?>
                <nav class="nav free-chat-nav" aria-label="Chat libere recenti">
                    <?php foreach ($freeChatSessions as $freeSession): ?>
                        <?php
                        $freeTitle = trim((string) $freeSession['title']);
                        if ($freeTitle === '' || $freeTitle === 'Chat libera') {
                            $freeTitle = 'Nuova conversazione';
                        }
                        $freeActive = $current === '/chat/free' && (int) ($_GET['session_id'] ?? 0) === (int) $freeSession['id'];
                        ?>
                        <a class="<?= $freeActive ? 'active' : '' ?>" data-free-chat-session="<?= (int) $freeSession['id'] ?>" href="/chat/free?session_id=<?= (int) $freeSession['id'] ?>">
                            <?= View::e($freeTitle) ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>
            <div class="sidebar-bottom">
                <details class="expert-menu" <?= $expertActive ? 'open' : '' ?>>
                    <summary>Setting</summary>
                    <nav class="nav expert-nav">
                        <?php foreach ($expertNav as $path => $label): ?>
                            <a class="<?= $current === $path ? 'active' : '' ?>" href="<?= View::e($path) ?>"><?= View::e($label) ?></a>
                        <?php endforeach; ?>
                    </nav>
                    <form class="theme-switch" method="post" action="/settings/theme">
                        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                        <span>Tema</span>
                        <select name="app_theme" data-theme-setting>
                            <option value="light" <?= $appTheme === 'light' ? 'selected' : '' ?>>Chiaro</option>
                            <option value="dark" <?= $appTheme === 'dark' ? 'selected' : '' ?>>Scuro</option>
                            <option value="blue" <?= $appTheme === 'blue' ? 'selected' : '' ?>>Notte blu</option>
                        </select>
                    </form>
                </details>
                <?php if (PHP_SAPI === 'cli-server'): ?>
                    <div class="local-controls">
                        <form method="post" action="/system/terminal" data-terminal>
                            <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                            <button type="submit" class="terminal-btn" title="Apre un Terminale con la console del server PHP dal vivo (richieste colorate)">Console server (dal vivo)</button>
                        </form>
                        <form class="shutdown" method="post" action="/system/stop" data-shutdown>
                            <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                            <button type="submit" class="shutdown-btn" title="Chiude il server locale">Ferma AIManager</button>
                        </form>
                        <?php /* Conferma dell'arresto: sta qui, accanto al form, perché entrambi esistono
                                solo col server locale. Stesso componente della revoca. */ ?>
                        <dialog class="confirm-dialog" data-shutdown-dialog aria-labelledby="shutdown-confirm-title">
                            <form method="dialog">
                                <h2 id="shutdown-confirm-title">Fermare AIManager?</h2>
                                <?php if ($shutdownActiveProcessCount > 0 && $shutdownPendingCount > 0): ?>
                                    <p>Ci sono <?= $shutdownActiveProcessCount ?> processi in esecuzione e <?= $shutdownPendingCount ?> operazioni in attesa di decisione. Fermando AIManager i processi verranno terminati e le operazioni annullate. Vuoi continuare?</p>
                                <?php elseif ($shutdownActiveProcessCount > 0): ?>
                                    <p>Ci sono <?= $shutdownActiveProcessCount ?> processi in esecuzione. Fermando AIManager verranno terminati tutti. Vuoi continuare?</p>
                                <?php elseif ($shutdownPendingCount > 0): ?>
                                    <p>Ci sono <?= $shutdownPendingCount ?> operazioni in attesa di decisione. Fermando AIManager verranno annullate. Vuoi continuare?</p>
                                <?php else: ?>
                                    <p>Il server locale verrà chiuso e la pagina non funzionerà finché non lo riavvii.</p>
                                <?php endif; ?>
                                <div class="confirm-dialog-actions">
                                    <button class="button ghost" value="cancel" autofocus>Annulla</button>
                                    <button class="button danger" value="confirm" data-shutdown-accept>Ferma AIManager</button>
                                </div>
                            </form>
                        </dialog>
                    </div>
                <?php endif; ?>
                <div class="sidebar-signature">
                    <span class="signature-mark">CL</span>
                    <span><strong>Centro locale</strong><small>Gestione AI</small></span>
                </div>
            </div>
        </aside>

        <main class="main">
            <?php if (!$isSimpleSurface && empty($hideTopbar)): ?>
            <header class="topbar">
                <div>
                    <p class="eyebrow">AIManager</p>
                    <h1><?= View::e($title ?? 'Dashboard') ?></h1>
                </div>
                <div class="topbar-actions">
                </div>
            </header>
            <?php endif; ?>

            <?php if ($flash): ?>
                <div class="notice"><?= View::e($flash) ?></div>
            <?php endif; ?>

            <?= $content ?>
        </main>
    </div>
</body>
</html>
