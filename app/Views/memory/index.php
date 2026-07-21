<?php use App\Core\View; ?>
<?php
$memoryExcerpt = static function (string $content): string {
    $text = trim(preg_replace('/\s+/u', ' ', $content) ?? $content);
    return mb_strlen($text) > 320 ? mb_substr($text, 0, 320) . '...' : $text;
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
<section class="grid two">
    <form class="panel form" method="post" action="/memory" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
        <h2>Nuovo elemento</h2>
        <?php if (!$activeProjects): ?>
            <p class="empty">Crea prima un progetto: ogni informazione deve appartenere a un Workspace.</p>
        <?php endif; ?>
        <label>Titolo <input name="title" placeholder="Se vuoto, usa il nome del documento"></label>
        <label>Progetto
            <select name="project_id" required>
                <?php foreach ($activeProjects as $project): ?>
                    <option value="<?= (int) $project['id'] ?>" <?= $selectedProject === (int) $project['id'] ? 'selected' : '' ?>><?= View::e($project['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <input type="hidden" name="memory_type" value="knowledge">
        <label>Documento <input name="memory_file" type="file" accept=".txt,.md,.csv,.json,.xml,.html,.css,.js,.ts,.php,.py,.sql,.log,.yml,.yaml,.pdf,.doc,.docx,.xls,.xlsx"></label>
        <label>Contenuto <textarea name="content" rows="7" placeholder="Opzionale se carichi un documento leggibile"></textarea></label>
        <div class="form-grid">
            <label>Tag <input name="tags" placeholder="workflow, cliente, vincoli"></label>
            <label>Importanza <input name="importance" type="number" min="1" max="5" value="3"></label>
        </div>
        <button class="button" type="submit" <?= !$activeProjects ? 'disabled' : '' ?>>Salva memoria</button>
    </form>

    <div class="panel">
        <?php if ($selectedProjectArchived): ?><div class="notice archived-notice">Memoria di un progetto archiviato: consultazione in sola lettura.</div><?php endif; ?>
        <form class="search" method="get" action="/memory">
            <select name="project_id">
                <?php foreach ($projects as $project): ?>
                    <option value="<?= (int) $project['id'] ?>" <?= $selectedProject === (int) $project['id'] ? 'selected' : '' ?>><?= View::e($project['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <input name="q" value="<?= View::e($query) ?>" placeholder="Cerca nella memoria">
            <button class="button small" type="submit">Cerca</button>
        </form>
        <div class="list memory-list">
            <?php foreach ($memories as $item): ?>
                <?php $fileMeta = $memoryFileMeta($item); ?>
                <article class="memory-card">
                    <div class="memory-card-head">
                        <div>
                            <h3><?= View::e($item['title']) ?></h3>
                            <small><?= View::e($item['memory_type'] ?? 'note') ?> · <?= View::e($item['project_name']) ?><?= ($item['tags'] ?? '') !== '' ? ' · ' . View::e($item['tags']) : '' ?></small>
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
                    <?php if (!$selectedProjectArchived): ?><form method="post" action="/memory/delete" data-confirm="Eliminare questa memoria?">
                        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                        <button class="link-danger" type="submit">Elimina</button>
                    </form><?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?php if (!$memories): ?><p class="empty">Nessun elemento per questo progetto.</p><?php endif; ?>
        </div>
    </div>
</section>
