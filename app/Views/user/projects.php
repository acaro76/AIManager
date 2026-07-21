<?php use App\Core\View; ?>
<section class="user-project-picker">
    <div>
        <p class="eyebrow">Chat progetto</p>
        <h2>Scegli un progetto</h2>
    </div>
    <div class="user-project-list">
        <?php foreach ($projects as $project): ?>
            <a href="/chat?project_id=<?= (int) $project['id'] ?>">
                <strong><?= View::e($project['name']) ?></strong>
                <small><?= View::e($project['description'] ?: 'Apri le conversazioni del progetto') ?></small>
            </a>
        <?php endforeach; ?>
        <?php if (!$projects): ?><p class="empty">Nessun progetto disponibile.</p><?php endif; ?>
    </div>
</section>
