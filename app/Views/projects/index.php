<?php use App\Core\View; ?>
<?php
$isArchivedTab = ($selectedTab ?? 'active') === 'archived';
$projects = $isArchivedTab ? ($archivedProjects ?? []) : ($activeProjects ?? []);
?>
<section class="panel">
    <div class="panel-head">
        <div>
            <h2>Progetti</h2>
            <div class="project-tabs" aria-label="Stato progetti">
                <a class="button small <?= !$isArchivedTab ? '' : 'ghost' ?>" href="/projects">Attivi (<?= count($activeProjects ?? []) ?>)</a>
                <a class="button small <?= $isArchivedTab ? '' : 'ghost' ?>" href="/projects?tab=archived">Archiviati (<?= count($archivedProjects ?? []) ?>)</a>
            </div>
        </div>
        <?php if (!$isArchivedTab): ?><a class="button" href="/projects/create">Nuovo progetto</a><?php endif; ?>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Nome</th><th>Provider</th><th>Aggiornato</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($projects as $project): ?>
                <?php $projectArchived = (string) ($project['status'] ?? 'active') === 'archived'; ?>
                <tr>
                    <td><strong><?= View::e($project['name']) ?></strong><br><small><?= View::e($project['description']) ?></small></td>
                    <td><?= View::e($project['provider']) ?></td>
                    <td><?= View::e(date('d/m/Y H:i', strtotime($project['updated_at']))) ?></td>
                    <td>
                        <div class="actions compact-actions">
                            <a class="button small ghost" href="/projects/show?id=<?= (int) $project['id'] ?>"><?= $projectArchived ? 'Consulta' : 'Apri' ?></a>
                            <?php if ($projectArchived): ?>
                                <form method="post" action="/projects/restore">
                                    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                                    <input type="hidden" name="id" value="<?= (int) $project['id'] ?>">
                                    <button class="button small" type="submit">Ripristina</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="/projects/archive" data-confirm="Archiviare il progetto? Le sessioni attive verranno consolidate nel Brain e il progetto diventera sola lettura.">
                                    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                                    <input type="hidden" name="id" value="<?= (int) $project['id'] ?>">
                                    <button class="button small archive-button" type="submit">Archivia</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (!$projects): ?><p class="empty"><?= $isArchivedTab ? 'Nessun progetto archiviato.' : 'Nessun progetto attivo.' ?></p><?php endif; ?>
</section>
