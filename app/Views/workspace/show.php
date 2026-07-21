<?php use App\Core\View; ?>
<?php
$session = $activeSession ?? null;
$isProjectArchived = (string) ($project['status'] ?? 'active') === 'archived';
$isSessionArchived = $session && (string) ($session['status'] ?? 'active') === 'archived';
$isReadOnly = $isProjectArchived || $isSessionArchived;
$state = $executionState ?? null;
$latestLog = $latestProviderLog ?? null;
$lastAssistant = null;
foreach (array_reverse($messages ?? []) as $row) {
    if (($row['role'] ?? '') === 'assistant' && !empty($row['provider'])) {
        $lastAssistant = $row;
        break;
    }
}
$activeProviderKey = (string) (($latestLog['provider'] ?? '') ?: ($lastAssistant['provider'] ?? ($state?->currentProvider ?: ($project['provider'] ?? 'auto'))));
$activeProvider = $providerConfigs[$activeProviderKey] ?? null;
$activeModel = (string) (($latestLog['model'] ?? '') ?: ($lastAssistant['model'] ?? ($activeProvider['model'] ?? '-')));
$activeStatus = (string) ($activeProvider['status'] ?? ($activeProviderKey === 'auto' ? 'auto' : 'unknown'));
$activeStatusSuffix = in_array($activeStatus, ['online', 'offline', 'auto'], true) ? ' · stato ' . $activeStatus : '';
$executionData = $state ? $state->toPersistence() : [];
$short = fn (string $text, int $limit = 120): string => mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1) . '...' : $text;
$fileIcon = static function (array $attachment): string {
    $extension = strtolower((string) ($attachment['extension'] ?? ''));
    if (!empty($attachment['is_image']) || in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'heic'], true)) {
        return 'IMG';
    }
    if ($extension === 'pdf') {
        return 'PDF';
    }
    if (in_array($extension, ['doc', 'docx'], true)) {
        return 'DOC';
    }
    if (in_array($extension, ['xls', 'xlsx', 'csv'], true)) {
        return 'XLS';
    }

    return $extension !== '' ? strtoupper(mb_substr($extension, 0, 3)) : 'FILE';
};
?>

<section class="workspace-command workspace-command-compact">
    <div>
        <p class="eyebrow">Progetto</p>
        <h2><?= View::e($project['name']) ?></h2>
        <p><?= View::e($project['description'] ?: 'Ambiente operativo del progetto') ?></p>
    </div>
    <div class="workspace-actions">
        <span class="workspace-provider-pill" data-provider-live>
            <strong><?= View::e(strtoupper((string) $activeProviderKey)) ?></strong>
            <small data-provider-summary><?= View::e($activeModel) ?><?= View::e($activeStatusSuffix) ?></small>
        </span>
        <a class="button ghost" href="/projects/edit?id=<?= (int) $project['id'] ?>">Impostazioni</a>
    </div>
</section>

<?php if ($isProjectArchived): ?>
    <div class="notice archived-notice">Progetto archiviato: workspace in sola lettura. Puoi consultare sessioni, messaggi e memoria oppure ripristinare il progetto dalle impostazioni.</div>
<?php elseif ($isSessionArchived): ?>
    <div class="notice archived-notice">Sessione archiviata: conversazione in sola lettura. Crea o seleziona una sessione attiva per continuare.</div>
<?php endif; ?>

<section class="panel workspace-sessions-strip">
    <div class="workspace-session-row">
        <?php foreach ($sessions as $item): ?>
            <a class="ops-session <?= $session && (int) $session['id'] === (int) $item['id'] ? 'active' : '' ?>" href="/workspace/session?id=<?= (int) $item['id'] ?>">
                <strong><?= View::e($item['title']) ?></strong>
                <small><?= View::e($item['status']) ?> · <?= (int) $item['message_count'] ?> messaggi</small>
            </a>
        <?php endforeach; ?>
    </div>
    <?php if (!$isProjectArchived): ?><form class="ops-session-create" method="post" action="/workspace/sessions">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
        <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
        <input name="title" required placeholder="Nuova sessione">
        <button class="button small" type="submit">Crea</button>
    </form><?php endif; ?>
    <?php if (!$isReadOnly): ?><a class="button small ghost workspace-simple-chat" href="/chat?project_id=<?= (int) $project['id'] ?>&session_id=<?= (int) ($session['id'] ?? 0) ?>">Chat semplificata</a><?php endif; ?>
</section>

<section class="workspace-operating workspace-chat-first">
    <main class="ops-chat">
        <section class="chat-shell workspace-chat-shell">
            <div class="chat-head">
                <div>
                    <h2><?= View::e($session['title'] ?? 'Sessione') ?></h2>
                    <small><?= View::e($activeProviderKey === 'auto' ? 'AI automatica' : strtoupper($activeProviderKey)) ?> · <?= View::e($activeModel) ?></small>
                </div>
                <?php if ($session && !$isProjectArchived && $session['status'] !== 'archived'): ?>
                    <form method="post" action="/workspace/sessions/archive" data-confirm="Archiviare questa sessione e aggiornare il Brain?">
                        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                        <input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
                        <button class="button ghost" type="submit">Archivia</button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="chat-messages" data-chat-scroll>
                <?php foreach ($messages as $message): ?>
                    <article class="chat-message <?= $message['role'] === 'user' ? 'user' : 'assistant' ?>">
                        <div class="chat-bubble">
                            <small><?= $message['role'] === 'user' ? 'Tu' : 'AI' ?> · <?= View::e(date('d/m/Y H:i', strtotime($message['created_at']))) ?></small>
                            <?php if (!empty($message['attachments'])): ?>
                                <div class="chat-bubble-files">
                                    <?php foreach ($message['attachments'] as $attachment): ?>
                                        <?php if (!empty($attachment['is_image'])): ?>
                                            <figure class="chat-image">
                                                <img src="/media/attachment?id=<?= (int) $attachment['id'] ?>" alt="<?= View::e((string) $attachment['name']) ?>" loading="lazy">
                                                <a class="chat-image-download" href="/media/attachment?id=<?= (int) $attachment['id'] ?>&amp;download=1" download="<?= View::e((string) $attachment['name']) ?>">Salva immagine</a>
                                            </figure>
                                        <?php else: ?>
                                            <span class="chat-file-card">
                                                <span class="chat-file-card__icon" aria-hidden="true"><?= View::e($fileIcon($attachment)) ?></span>
                                                <span class="chat-file-card__name"><?= View::e((string) $attachment['name']) ?></span>
                                            </span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <div class="chat-content"><?= View::messageContent($message['content']) ?></div>
                            <?php if ($message['role'] === 'assistant'): ?>
                                <span class="chat-meta"><?= View::e($message['provider']) ?> · <?= View::e($message['model']) ?> · <?= (int) $message['tokens_input'] ?> / <?= (int) $message['tokens_output'] ?> token</span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
                <?php if (!$messages && !$isReadOnly): ?>
                    <div class="chat-empty">
                        <strong>Workspace pronto</strong>
                        <p class="chat-empty-hint">Scrivi il primo messaggio: Context Engine e Provider lavorano da questa sessione. Puoi chiedere, generare un'immagine o cercare sul web.</p>
                        <div class="chat-suggestions">
                            <button type="button" class="chat-suggestion" data-suggestion="Genera un'immagine di ">🖼️ Genera un'immagine</button>
                            <button type="button" class="chat-suggestion" data-suggestion="Cerca sul web: ">🌐 Cerca sul web</button>
                            <button type="button" class="chat-suggestion" data-suggestion="Analizza ">💡 Analizza un tema</button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!$isReadOnly && $session): ?><div class="chat-footer">
                <form class="chat-compose" method="post" action="/workspace/chat/stream" enctype="multipart/form-data">
                    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                    <input type="hidden" name="session_id" value="<?= (int) ($session['id'] ?? 0) ?>">
                    <input type="hidden" name="surface" value="workspace">
                    <input class="chat-file-input" type="file" name="attachments[]" multiple data-chat-files>
                    <button class="button ghost chat-attach-button" type="button" data-chat-attach aria-label="Allega file">+</button>
                    <div class="chat-input-field">
                        <div class="chat-attachments" data-chat-attachments></div>
                        <textarea name="prompt" rows="3" required placeholder="Scrivi qui il lavoro da fare in questa sessione"></textarea>
                    </div>
                    <button class="button chat-send-button" type="submit" aria-label="Invia messaggio">
                        <span class="send-icon" aria-hidden="true"></span>
                        <span class="stop-icon" aria-hidden="true"></span>
                    </button>
                </form>
            </div><?php endif; ?>
        </section>
    </main>
</section>
