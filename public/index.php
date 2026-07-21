<?php

declare(strict_types=1);

// Fase 10 / Step 2 — mai dettagli d'errore verso il browser (solo nel log). Prima di tutto.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

require dirname(__DIR__) . '/app/Core/autoload.php';

// Confine HTTP LOCALE (prima del boot): Host in allow-list storica ED client su loopback
// (REMOTE_ADDR, mai header forwarded). Una richiesta remota con Host: localhost è rifiutata.
if (!App\Core\Security\LocalHttpGuard::isAllowed($_SERVER['HTTP_HOST'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '')) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Bad Request: Host non autorizzato o client non locale.';
    exit;
}

// Security Headers
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

use App\Controllers\DashboardController;
use App\Controllers\ChatController;
use App\Controllers\MediaController;
use App\Controllers\MemoryController;
use App\Controllers\PluginController;
use App\Controllers\ProjectController;
use App\Controllers\ProviderController;
use App\Controllers\SettingController;
use App\Controllers\SessionController;
use App\Controllers\SystemController;
use App\Controllers\WorkspaceController;
use App\Controllers\UserChatController;
use App\Controllers\CodeController;
use App\Core\App;
use App\Core\Request;
use App\Core\Router;
use App\Core\Security\ErrorBoundary;

// Confine: boot, registrazione route e dispatch. Un Throwable non gestito diventa un 500 GENERICO,
// con il dettaglio SOLO nel log (nessuna traccia tecnica verso il browser).
try {
$app = App::boot(dirname(__DIR__));
$router = new Router($app);

$router->get('/', [DashboardController::class, 'index']);
$router->get('/media/attachment', [MediaController::class, 'attachment']);
$router->get('/chat', [UserChatController::class, 'index']);
$router->get('/chat/free', [UserChatController::class, 'free']);
$router->get('/choose-project', [UserChatController::class, 'chooseProject']);
$router->get('/projects', [ProjectController::class, 'index']);
$router->get('/projects/create', [ProjectController::class, 'create']);
$router->post('/projects', [ProjectController::class, 'store']);
$router->get('/projects/show', [ProjectController::class, 'show']);
$router->get('/projects/edit', [ProjectController::class, 'edit']);
$router->get('/workspace', [WorkspaceController::class, 'show']);
$router->get('/workspace/chat', [WorkspaceController::class, 'show']);
$router->get('/workspace/session', [WorkspaceController::class, 'show']);
$router->get('/workspace/execution/export', [WorkspaceController::class, 'exportExecutionState']);
$router->post('/workspace/chat/stream', [ChatController::class, 'stream']);
$router->post('/workspace/chat/stop', [ChatController::class, 'stop']);
$router->post('/workspace/sessions', [SessionController::class, 'store']);
$router->post('/workspace/sessions/rename', [SessionController::class, 'rename']);
$router->post('/workspace/sessions/archive', [SessionController::class, 'archive']);
$router->post('/projects/update', [ProjectController::class, 'update']);
$router->post('/projects/archive', [ProjectController::class, 'archive']);
$router->post('/projects/restore', [ProjectController::class, 'restore']);
$router->post('/projects/delete', [ProjectController::class, 'delete']);

$router->get('/code', [CodeController::class, 'index']);
$router->post('/code/open', [CodeController::class, 'open']);
$router->post('/code/folder/pick', [CodeController::class, 'pickFolder']);
$router->get('/code/workspace', [CodeController::class, 'workspace']);
$router->get('/code/children', [CodeController::class, 'children']);
$router->get('/code/file', [CodeController::class, 'file']);
$router->post('/code/revoke', [CodeController::class, 'revoke']);
$router->post('/code/session/create', [CodeController::class, 'createSession']);
$router->post('/code/session/rename', [CodeController::class, 'renameSession']);
$router->post('/code/chat', [CodeController::class, 'chat']);
$router->post('/code/chat/stop', [CodeController::class, 'stopChat']);
$router->post('/code/patch/apply', [CodeController::class, 'applyPatch']);
$router->post('/code/patch/complete', [CodeController::class, 'completeAppliedPatch']);
$router->post('/code/patch/reject', [CodeController::class, 'rejectPatch']);
$router->post('/code/patch/rollback', [CodeController::class, 'rollbackPatch']);
$router->post('/code/command/confirm', [CodeController::class, 'confirmCommand']);
$router->post('/code/command/reject', [CodeController::class, 'rejectCommand']);
$router->post('/code/command/stop', [CodeController::class, 'stopCommand']);
$router->post('/code/process/confirm', [CodeController::class, 'confirmProcess']);
$router->post('/code/process/reject', [CodeController::class, 'rejectProcess']);
$router->post('/code/process/stop', [CodeController::class, 'stopProcess']);
$router->post('/code/git/stage/confirm', [CodeController::class, 'confirmGitStage']);
$router->post('/code/git/reject', [CodeController::class, 'rejectGit']);
$router->post('/code/git/commit/propose', [CodeController::class, 'proposeGitCommit']);
$router->post('/code/git/commit/create', [CodeController::class, 'createGitCommit']);
$router->post('/code/git/commit/confirm', [CodeController::class, 'confirmGitCommit']);

$router->get('/memory', [MemoryController::class, 'index']);
$router->post('/memory', [MemoryController::class, 'store']);
$router->post('/memory/delete', [MemoryController::class, 'delete']);
$router->post('/memory/search', [MemoryController::class, 'search']);

$router->get('/providers', [ProviderController::class, 'index']);
$router->get('/providers/show', [ProviderController::class, 'show']);
$router->get('/providers/web/show', [ProviderController::class, 'showWeb']);
$router->get('/providers/image/show', [ProviderController::class, 'showImage']);
$router->post('/providers/save', [ProviderController::class, 'save']);
$router->post('/providers/web/save', [ProviderController::class, 'saveWeb']);
$router->post('/providers/image/save', [ProviderController::class, 'saveImage']);
$router->post('/providers/toggle', [ProviderController::class, 'toggle']);
$router->post('/providers/web/toggle', [ProviderController::class, 'toggleWeb']);
$router->post('/providers/image/toggle', [ProviderController::class, 'toggleImage']);
$router->post('/providers/test', [ProviderController::class, 'test']);
$router->post('/providers/web/test', [ProviderController::class, 'testWeb']);
$router->post('/providers/image/test', [ProviderController::class, 'testImage']);
$router->get('/providers/models', [ProviderController::class, 'models']);

$router->get('/plugins', [PluginController::class, 'index']);
$router->post('/plugins/toggle', [PluginController::class, 'toggle']);
$router->post('/plugins/refresh', [PluginController::class, 'refresh']);

$router->post('/settings/theme', [SettingController::class, 'theme']);

$router->get('/system/health', [SystemController::class, 'health']);
$router->post('/system/stop', [SystemController::class, 'stop']);
$router->post('/system/terminal', [SystemController::class, 'terminal']);

$router->dispatch(Request::capture());
} catch (\Throwable $e) {
    $response = ErrorBoundary::handle($e, static fn (string $message): bool => (bool) error_log($message));
    http_response_code($response['status']);
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo $response['body'];
}
