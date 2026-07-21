<?php use App\Core\View; ?>
<?php $isEdit = (bool) $project; ?>
<?php $isArchived = $isEdit && (string) ($project['status'] ?? 'active') === 'archived'; ?>
<?php $settingsReturn = $isEdit ? '/projects/edit?id=' . (int) $project['id'] : '/projects'; ?>
<?php
$memoryExcerpt = static function (string $content): string {
    $text = trim(preg_replace('/\s+/u', ' ', $content) ?? $content);
    return mb_strlen($text) > 260 ? mb_substr($text, 0, 260) . '...' : $text;
};
$memoryFileMeta = static function (array $item): array {
    $data = json_decode((string) ($item['metadata_json'] ?? '{}'), true);
    return is_array($data) ? $data : [];
};
$memoryLineCount = static function (string $content): int {
    $text = trim($content);
    return $text === '' ? 0 : substr_count($text, "\n") + 1;
};
?>
<?php if ($isEdit): ?>
    <section class="project-settings-return">
        <a class="button ghost" href="/workspace?id=<?= (int) $project['id'] ?>">Torna al Workspace</a>
    </section>
<?php endif; ?>
<?php if ($isArchived): ?>
    <div class="notice archived-notice">Progetto archiviato: contenuti e memoria sono consultabili, ma non modificabili. Ripristinalo per riprendere il lavoro.</div>
<?php endif; ?>
<form class="panel form" method="post" action="<?= $isEdit ? '/projects/update' : '/projects' ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $project['id'] ?>"><?php endif; ?>

    <div class="form-grid">
        <label>Nome
            <input required name="name" value="<?= View::e($project['name'] ?? '') ?>" placeholder="Nome progetto" <?= $isArchived ? 'readonly' : '' ?>>
        </label>
        <label>Provider
            <select name="provider" <?= $isArchived ? 'disabled' : '' ?>>
                <option value="auto" <?= ($project['provider'] ?? $defaultProvider) === 'auto' ? 'selected' : '' ?>>AUTO</option>
                <?php foreach ($providers as $key => $provider): ?>
                    <option value="<?= View::e($key) ?>" <?= ($project['provider'] ?? $defaultProvider) === $key ? 'selected' : '' ?>><?= View::e($provider['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>

    <label>Descrizione
        <textarea name="description" rows="3" <?= $isArchived ? 'readonly' : '' ?>><?= View::e($project['description'] ?? '') ?></textarea>
    </label>

    <label>System prompt
        <textarea name="system_prompt" rows="7" <?= $isArchived ? 'readonly' : '' ?>><?= View::e($project['system_prompt'] ?? '') ?></textarea>
    </label>

    <div class="actions">
        <?php if (!$isArchived): ?><button class="button" type="submit"><?= $isEdit ? 'Salva modifiche' : 'Crea progetto' ?></button><?php endif; ?>
        <a class="button ghost" href="<?= $isEdit ? '/workspace?id=' . (int) $project['id'] : '/projects' ?>"><?= $isEdit ? 'Torna al Workspace' : 'Annulla' ?></a>
        <?php if ($isEdit && !$isArchived): ?>
            <button class="button ghost" type="submit" form="archive-project">Archivia</button>
        <?php elseif ($isArchived): ?>
            <button class="button" type="submit" form="restore-project">Ripristina</button>
            <button class="button danger" type="submit" form="delete-project">Elimina</button>
        <?php endif; ?>
    </div>
</form>

<?php if ($isEdit): ?>
    <section class="panel project-memory-settings">
        <div class="panel-head">
            <h2>Memoria progetto</h2>
        </div>
        <?php if (!$isArchived): ?>
        <form class="form project-memory-form" method="post" action="/memory" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <input type="hidden" name="return_to" value="<?= View::e($settingsReturn) ?>">
            <input type="hidden" name="memory_type" value="knowledge">
            <label>Titolo
                <input name="title" placeholder="Se vuoto, usa il nome del documento">
            </label>
            <label>Documento
                <input name="memory_file" type="file" accept=".txt,.md,.csv,.json,.xml,.html,.css,.js,.ts,.php,.py,.sql,.log,.yml,.yaml,.pdf,.doc,.docx,.xls,.xlsx">
            </label>
            <label>Contenuto
                <textarea name="content" rows="4" placeholder="Opzionale se carichi un documento leggibile"></textarea>
            </label>
            <div class="form-grid">
                <label>Tag
                    <input name="tags" placeholder="cliente, vincolo, workflow">
                </label>
                <label>Importanza
                    <input name="importance" type="number" min="1" max="5" value="3">
                </label>
            </div>
            <button class="button" type="submit">Salva memoria</button>
        </form>
        <?php endif; ?>

        <div class="project-memory-list">
            <?php foreach (($memories ?? []) as $item): ?>
                <?php $fileMeta = $memoryFileMeta($item); ?>
                <article class="memory-card compact">
                    <div class="memory-card-head">
                        <div>
                            <h3><?= View::e($item['title']) ?></h3>
                            <small><?= View::e($item['memory_type'] ?? 'note') ?><?= ($item['tags'] ?? '') !== '' ? ' · ' . View::e($item['tags']) : '' ?></small>
                        </div>
                        <?php if (!empty($fileMeta['file_extension'])): ?>
                            <span class="memory-file-badge"><?= View::e(strtoupper((string) $fileMeta['file_extension'])) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($fileMeta['file_name'])): ?>
                        <div class="memory-file-summary">
                            <span><?= View::e((string) $fileMeta['file_name']) ?></span>
                            <small><?= (int) $memoryLineCount((string) $item['content']) ?> righe</small>
                        </div>
                    <?php else: ?>
                        <p class="memory-excerpt"><?= View::e($memoryExcerpt((string) $item['content'])) ?></p>
                    <?php endif; ?>
                    <?php if (!$isArchived): ?><form method="post" action="/memory/delete" data-confirm="Eliminare questa memoria?">
                        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                        <input type="hidden" name="return_to" value="<?= View::e($settingsReturn) ?>">
                        <button class="link-danger" type="submit">Elimina</button>
                    </form><?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?php if (empty($memories)): ?><p class="empty">Nessuna memoria salvata per questo progetto.</p><?php endif; ?>
        </div>
    </section>

    <?php if (!$isArchived): ?>
    <form id="archive-project" method="post" action="/projects/archive" data-confirm="Archiviare il progetto? Le sessioni attive verranno consolidate nel Brain e il progetto diventera sola lettura.">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int) $project['id'] ?>">
    </form>
    <?php else: ?>
    <form id="restore-project" method="post" action="/projects/restore">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int) $project['id'] ?>">
    </form>
    <form id="delete-project" method="post" action="/projects/delete" data-confirm="Eliminare definitivamente questo progetto archiviato e tutti i suoi dati?">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int) $project['id'] ?>">
    </form>
    <?php endif; ?>
<?php endif; ?>
