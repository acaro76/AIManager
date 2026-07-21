<?php use App\Core\View; ?>
<?php
$latestLog = $latestProviderLog ?? null;
$lastAssistant = null;
foreach (array_reverse($messages ?? []) as $row) {
    if (($row['role'] ?? '') === 'assistant' && !empty($row['provider'])) {
        $lastAssistant = $row;
        break;
    }
}
$activeProviderKey = (string) (($latestLog['provider'] ?? '') ?: ($lastAssistant['provider'] ?? $selectedProvider ?? 'auto'));
$activeProvider = ($providerConfigs ?? [])[$activeProviderKey] ?? null;
$activeModel = (string) (($latestLog['model'] ?? '') ?: ($lastAssistant['model'] ?? ($activeProvider['model'] ?? '-')));
$activeStatus = (string) ($activeProvider['status'] ?? ($activeProviderKey === 'auto' ? 'auto' : 'unknown'));
$activeStatusSuffix = in_array($activeStatus, ['online', 'offline', 'auto'], true) ? ' · stato ' . $activeStatus : '';
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
<?php if (!$project): ?>
    <section class="user-empty">
        <strong>Nessun progetto disponibile</strong>
        <p>Crea il primo progetto dalla sezione Progetti.</p>
    </section>
<?php else: ?>
    <section class="user-chat-layout">
        <section class="chat-shell user-chat-shell">
            <div class="chat-provider-status chat-provider-status-action" data-provider-live>
                <div>
                    <span>AI</span>
                    <strong data-provider-badge><?= View::e(strtoupper($activeProviderKey)) ?></strong>
                    <small data-provider-summary><?= View::e($activeModel) ?><?= View::e($activeStatusSuffix) ?></small>
                </div>
                <?php if (empty($isFreeChat)): ?>
                    <?php $workspaceHref = '/workspace/session?id=' . (int) $activeSession['id']; ?>
                    <a class="button small ghost" href="<?= View::e($workspaceHref) ?>">Workspace</a>
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
                            <?php if ($message['role'] === 'assistant' && !empty($message['provider'])): ?>
                                <span class="chat-meta"><?= View::e($message['provider']) ?> · <?= View::e($message['model']) ?> · <?= (int) $message['tokens_input'] ?> / <?= (int) $message['tokens_output'] ?> token</span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
                <?php if (!$messages): ?>
                    <div class="chat-empty">
                        <strong>Inizia la conversazione</strong>
                        <p class="chat-empty-hint">Chiedi qualcosa, genera un'immagine o cerca sul web. Puoi anche allegare un documento con <span class="chat-empty-key">+</span>.</p>
                        <div class="chat-suggestions">
                            <button type="button" class="chat-suggestion" data-suggestion="Genera un'immagine di ">🖼️ Genera un'immagine</button>
                            <button type="button" class="chat-suggestion" data-suggestion="Cerca sul web: ">🌐 Cerca sul web</button>
                            <button type="button" class="chat-suggestion" data-suggestion="Spiegami ">💡 Spiega un concetto</button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="chat-footer">
                <form class="chat-compose" method="post" action="/workspace/chat/stream" enctype="multipart/form-data">
                    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                    <input type="hidden" name="session_id" value="<?= (int) $activeSession['id'] ?>">
                    <input type="hidden" name="surface" value="chat">
                    <input type="hidden" name="provider" value="<?= View::e($selectedProvider) ?>">
                    <input class="chat-file-input" type="file" name="attachments[]" multiple data-chat-files>
                    <button class="button ghost chat-attach-button" type="button" data-chat-attach aria-label="Allega file">+</button>
                    <div class="chat-input-field">
                        <div class="chat-attachments" data-chat-attachments></div>
                        <textarea name="prompt" rows="3" required placeholder="Scrivi un messaggio… chiedi, genera un'immagine o cerca sul web"></textarea>
                    </div>
                    <button class="button chat-send-button" type="submit" aria-label="Invia messaggio">
                        <span class="send-icon" aria-hidden="true"></span>
                        <span class="stop-icon" aria-hidden="true"></span>
                    </button>
                </form>
            </div>
        </section>
    </section>
<?php endif; ?>
