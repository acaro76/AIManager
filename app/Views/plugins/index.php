<?php use App\Core\View; ?>
<section class="panel">
    <div class="panel-head">
        <h2>Plugin installati</h2>
        <form method="post" action="/plugins/refresh">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
            <button class="button small" type="submit">Rileggi plugin</button>
        </form>
    </div>
    <div class="plugin-list">
        <?php foreach ($plugins as $plugin): ?>
            <article class="plugin-row">
                <div>
                    <strong><?= View::e($plugin['name']) ?></strong>
                    <small><?= View::e($plugin['slug']) ?> · v<?= View::e($plugin['version']) ?></small>
                    <p><?= View::e($plugin['description']) ?></p>
                </div>
                <form method="post" action="/plugins/toggle">
                    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                    <input type="hidden" name="slug" value="<?= View::e($plugin['slug']) ?>">
                    <button class="button <?= $plugin['enabled'] ? 'ghost' : '' ?>" type="submit"><?= $plugin['enabled'] ? 'Disattiva' : 'Attiva' ?></button>
                </form>
            </article>
        <?php endforeach; ?>
        <?php if (!$plugins): ?><p class="empty">Nessun plugin rilevato.</p><?php endif; ?>
    </div>
</section>
