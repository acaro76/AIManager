<?php use App\Core\View; ?>
<?php if (empty($providerReady)): ?>
    <section class="panel getting-started" aria-labelledby="getting-started-title">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Primo utilizzo</p>
                <h2 id="getting-started-title">Collega una IA e prova la prima chat</h2>
            </div>
            <a class="button" href="/providers">Configura Provider</a>
        </div>
        <ol class="getting-started-steps">
            <li><strong>Scegli locale o cloud.</strong> LM Studio resta sul Mac ma va installato e avviato separatamente; i provider cloud richiedono una chiave personale.</li>
            <li><strong>Configura e attiva.</strong> Inserisci endpoint, modello e credenziale nella scheda Provider.</li>
            <li><strong>Esegui Test, poi Salva.</strong> Quando il test riesce, apri <a href="/chat/free?new=1">Nuova chat</a>.</li>
        </ol>
    </section>
<?php endif; ?>

<section class="stats-grid">
    <article class="stat">
        <span>Progetti</span>
        <strong><?= (int) $stats['projects']['total'] ?></strong>
        <small><?= (int) $stats['projects']['active'] ?> attivi · <?= (int) $stats['projects']['archived'] ?> archiviati</small>
    </article>
    <article class="stat">
        <span>Contesto</span>
        <strong><?= (int) $stats['memories'] ?></strong>
        <small>Memorie nei progetti</small>
    </article>
    <article class="stat">
        <span>Provider</span>
        <strong><?= (int) $stats['providers'] ?></strong>
        <small>Abilitati</small>
    </article>
    <article class="stat">
        <span>Plugin</span>
        <strong><?= (int) $stats['plugins'] ?></strong>
        <small>Attivi</small>
    </article>
</section>

<section class="grid two">
    <div class="panel">
        <div class="panel-head">
            <h2>Progetti recenti</h2>
            <a class="button small" href="/projects/create">Nuovo</a>
        </div>
        <div class="list">
            <?php foreach ($projects as $project): ?>
                <a class="list-row" href="/projects/show?id=<?= (int) $project['id'] ?>">
                    <span>
                        <strong><?= View::e($project['name']) ?></strong>
                        <small>Workspace</small>
                    </span>
                    <span class="chevron">›</span>
                </a>
            <?php endforeach; ?>
            <?php if (!$projects): ?><p class="empty">Nessun progetto ancora.</p><?php endif; ?>
        </div>
    </div>
</section>

<?php if ($pluginWidgets): ?>
    <section class="panel">
        <div class="panel-head"><h2>Widget plugin</h2></div>
        <div class="plugin-grid">
            <?php foreach ($pluginWidgets as $widget): ?>
                <?= is_string($widget) ? $widget : '' ?>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
