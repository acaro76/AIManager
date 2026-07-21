<?php
use App\Core\View;

/** @var \App\Core\Code\CodeWorkspace $workspace */
$session = $session ?? null;
$error = $error ?? null;
$accessRevoked = $accessRevoked ?? false;
$label = $workspace->name !== '' ? $workspace->name : basename($workspace->rootPath);
?>

<a class="skip-link" href="#code-chat-main">Vai alla chat</a>

<?php if ($error !== null): ?>
    <section class="code-workspace-unavailable" role="alert">
        <p class="eyebrow">Code · cartella non disponibile</p>
        <h1><?= View::e($label) ?></h1>
        <p><?= View::e($error) ?></p>
        <form method="post" action="/code/open">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
            <label for="code-reauthorize">Percorso della cartella</label>
            <div>
                <input id="code-reauthorize" name="path" required value="<?= View::e($workspace->rootPath) ?>">
                <button class="button" type="submit">Riautorizza</button>
            </div>
        </form>
    </section>
<?php else: ?>
    <div class="code-workspace-page" data-code-shell data-code-workspace="<?= (int) $workspace->id ?>">
        <div class="code-stage">
            <main class="code-chat-main" id="code-chat-main">
                <?php require __DIR__ . '/_chat.php'; ?>
            </main>
        </div>
    </div>
<?php endif; ?>
