<?php
use App\Core\Code\CodeChatSchema;
use App\Core\View;

$chatState = $chatState ?? CodeChatSchema::STATE_MISSING;
$session = $session ?? null;
$history = $history ?? [];
$historyCommands = $historyCommands ?? [];
$historyProcesses = $historyProcesses ?? [];
$historyGit = $historyGit ?? [];
$activeProcesses = $activeProcesses ?? [];
$patchProposals = $patchProposals ?? [];
$writeEnabled = $writeEnabled ?? false;
$commandsEnabled = $commandsEnabled ?? false;
$processesEnabled = $processesEnabled ?? false;
$accessRevoked = $accessRevoked ?? false;

// Descrizione delle capacità COERENTE con le feature flag di server (Fase 4/6).
$codeCapabilityHint = match (true) {
    $writeEnabled && $commandsEnabled => 'Code può leggere, proporre modifiche ai file e proporre comandi di sola lettura: modifiche ed esecuzioni le confermi tu.',
    $writeEnabled => 'Code può leggere e proporre modifiche ai file, che confermi tu prima dell\'applicazione.',
    $commandsEnabled => 'Code può leggere e proporre comandi di sola lettura, che confermi tu prima dell\'esecuzione.',
    default => 'Code può leggere e spiegare i file, ma non può modificarli o eseguire comandi.',
};
$codeProviderMode = $codeProviderMode ?? 'auto';
$rootLeaf = basename(rtrim($workspace->rootPath, DIRECTORY_SEPARATOR));
$authorizedFolder = $workspace->name !== '' ? $workspace->name : $rootLeaf;

/**
 * Card DISCRETA di una proposta di modifica (Fase 4): riepilogo, diff per file, e le azioni
 * Applica/Rifiuta (proposta) oppure Annulla (applicata). Il client sposta le operazioni attive
 * nella fascia fissa sopra il composer; il markup resta associato al turno per il fallback no-JS.
 */
$renderPatchCard = static function (array $card) {
    $files = $card['files'] ?? [];
    $added = 0; $removed = 0;
    foreach ($files as $f) { $added += (int) ($f['added'] ?? 0); $removed += (int) ($f['removed'] ?? 0); }
    $status = (string) ($card['status'] ?? 'proposed');
    if (in_array($status, ['applied', 'rejected'], true)) {
        $paths = array_values(array_filter(array_map(
            static fn (array $file): string => (string) ($file['path'] ?? ''),
            $files
        )));
        ?>
        <div class="code-patch-history code-patch-history-<?= View::e($status) ?>"
             data-code-patch-history
             <?php if ($status === 'applied'): ?>
                 data-code-patch-completion
                 data-operation-id="<?= View::e((string) $card['operation_id']) ?>"
             <?php endif; ?>>
            <strong>Modifica <?= $status === 'applied' ? 'applicata' : 'rifiutata' ?></strong>
            <?php if ($paths !== []): ?>
                <span>· <?= View::e(implode(', ', $paths)) ?></span>
            <?php endif; ?>
        </div>
        <?php
        return;
    }
    ?>
    <div class="code-patch-card" data-code-patch
         data-operation-id="<?= View::e((string) $card['operation_id']) ?>"
         data-patch-digest="<?= View::e((string) $card['patch_digest']) ?>"
         data-patch-status="<?= View::e($status) ?>">
        <div class="code-patch-summary">
            <strong><?= $status === 'applied' ? 'Modifica applicata' : 'Proposta di modifica' ?></strong>
            <span class="code-patch-stat"><?= count($files) ?> file · <span class="add">+<?= $added ?></span> <span class="del">−<?= $removed ?></span></span>
        </div>
        <div class="code-patch-files">
            <?php foreach ($files as $file): ?>
                <div class="code-patch-file">
                    <div class="code-patch-file-head">
                        <code><?= View::e((string) ($file['path'] ?? '')) ?></code>
                        <span class="code-patch-filestat"><span class="add">+<?= (int) ($file['added'] ?? 0) ?></span> <span class="del">−<?= (int) ($file['removed'] ?? 0) ?></span></span>
                    </div>
                    <?php if (($file['diff'] ?? '') !== ''): ?>
                        <pre class="code-patch-diff"><?= View::e((string) $file['diff']) ?></pre>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="code-patch-actions" data-code-patch-actions>
            <button type="button" class="button" data-code-patch-apply>Applica</button>
            <button type="button" class="button ghost" data-code-patch-reject>Rifiuta</button>
        </div>
        <p class="code-patch-message" data-code-patch-message role="status" aria-live="polite" hidden></p>
    </div>
    <?php
};

/**
 * Card DISCRETA di una proposta di COMANDO (Fase 6): programma, riepilogo sanificato (mai path
 * assoluti), stato ed eventuali azioni Esegui/Rifiuta (pendente). Stop vive nella fascia fissa. Nessun
 * output persistito: la card live lo mostra durante l'esecuzione. Stesso markup ricostruito lato client.
 */
$renderCommandCard = static function (array $card) {
    $state = (string) ($card['state'] ?? 'pending');
    ?>
    <div class="code-command-card" data-code-command
         data-command-id="<?= View::e((string) ($card['command_id'] ?? '')) ?>"
         data-command-digest="<?= View::e((string) ($card['digest'] ?? '')) ?>"
         data-command-state="<?= View::e($state) ?>">
        <div class="code-command-summary">
            <strong>Comando</strong>
            <code><?= View::e((string) ($card['display_summary'] ?? ($card['program'] ?? ''))) ?></code>
            <span class="code-command-state" data-code-command-label><?= View::e((string) ($card['label'] ?? '')) ?></span>
        </div>
        <pre class="code-command-output" data-code-command-output hidden></pre>
        <div class="code-command-actions" data-code-command-actions>
            <?php if ($state === 'pending'): ?>
                <button type="button" class="button" data-code-command-run>Esegui</button>
                <button type="button" class="button ghost" data-code-command-reject>Rifiuta</button>
            <?php endif; ?>
        </div>
        <p class="code-command-message" data-code-command-message role="status" aria-live="polite" hidden></p>
        <?php if ($state === 'rejected'): ?><p class="code-command-message">Proposta rifiutata. Nessuna modifica eseguita.</p><?php endif; ?>
    </div>
    <?php
};

/**
 * Card DISCRETA di una proposta di PROCESSO persistente (Fase 7): profilo, host:porta e directory
 * relativa (mai path assoluti), stato ed eventuali azioni Avvia/Rifiuta (pendente). Lo Stop compare
 * solo quando il processo è davvero in esecuzione. Stesso markup ricostruito lato client.
 */
$renderProcessCard = static function (array $card) {
    $state = (string) ($card['state'] ?? 'pending');
    $canStop = (bool) ($card['can_stop'] ?? false);
    ?>
    <div class="code-process-card" data-code-process
         data-process-id="<?= View::e((string) ($card['process_id'] ?? '')) ?>"
         data-process-digest="<?= View::e((string) ($card['digest'] ?? '')) ?>"
         data-process-state="<?= View::e($state) ?>">
        <div class="code-process-summary">
            <strong>Processo</strong>
            <code><?= View::e((string) ($card['display_summary'] ?? '')) ?></code>
            <span class="code-process-state" data-code-process-label><?= View::e((string) ($card['label'] ?? '')) ?></span>
        </div>
        <pre class="code-process-log" data-code-process-log hidden></pre>
        <div class="code-process-actions" data-code-process-actions>
            <?php if ($state === 'pending'): ?>
                <button type="button" class="button" data-code-process-run>Avvia</button>
                <button type="button" class="button ghost" data-code-process-reject>Rifiuta</button>
            <?php elseif ($canStop): ?>
                <button type="button" class="button ghost" data-code-process-stop>Ferma</button>
            <?php endif; ?>
        </div>
        <p class="code-process-message" data-code-process-message role="status" aria-live="polite" hidden></p>
        <?php if ($state === 'rejected'): ?><p class="code-process-message">Proposta rifiutata. Nessuna modifica eseguita.</p><?php endif; ?>
    </div>
    <?php
};

/** Un solo modello visivo per ogni stato Git persistito; nessun termine interno esposto. */
$renderGitCard = static function (array $card): void {
    $kind = (string) ($card['kind'] ?? 'stage');
    $state = (string) ($card['state'] ?? 'pending');
    $labels = [
        'pending' => 'Da confermare',
        'running' => 'In corso',
        'staged' => 'Pronto per il commit',
        'commit_pending' => 'Da confermare',
        'committed' => 'Completato',
        'rejected' => 'Rifiutato',
        'expired' => 'Scaduto',
        'stale' => 'Non più valido',
        'denied' => 'Non consentito',
        'error' => 'Non riuscito',
    ];
    ?>
    <div class="code-command-card" data-code-git
         data-operation-id="<?= View::e((string) ($card['operation_id'] ?? '')) ?>"
         data-digest="<?= View::e((string) ($card['digest'] ?? '')) ?>"
         data-kind="<?= View::e($kind) ?>" data-state="<?= View::e($state) ?>">
        <div class="code-command-summary">
            <strong><?= $kind === 'commit' ? 'Commit' : 'File da mettere in stage' ?></strong>
            <span class="code-command-state" data-code-git-state><?= View::e($labels[$state] ?? 'Non disponibile') ?></span>
        </div>
        <?php if (!empty($card['selected'])): ?>
            <div class="code-patch-files">
                <?php foreach ($card['selected'] as $entry): ?>
                    <?php $path = (string) ($entry['path'] ?? ''); ?>
                    <code><?= View::e($path) ?></code>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($kind === 'commit' && !empty($card['commit_message'])): ?>
            <p class="code-git-commit-message"><?= View::e((string) $card['commit_message']) ?></p>
        <?php endif; ?>
        <?php if (in_array($state, ['pending', 'commit_pending'], true)): ?>
            <div class="code-command-actions" data-code-git-actions>
                <button type="button" class="button" data-code-git-confirm><?= $kind === 'commit' ? 'Crea commit' : 'Metti in stage' ?></button>
                <button type="button" class="button ghost" data-code-git-reject>Rifiuta</button>
            </div>
        <?php elseif ($kind === 'stage' && $state === 'staged'): ?>
            <div class="code-git-commit-form" data-code-git-commit-form>
                <input type="text" maxlength="200" value="<?= View::e((string) ($card['suggested_message'] ?? '')) ?>"
                       placeholder="Messaggio del commit" aria-label="Messaggio del commit" data-code-git-commit-message>
                <button type="button" class="button" data-code-git-commit-create>Crea commit</button>
            </div>
        <?php endif; ?>
        <?php if ($state === 'rejected'): ?><p class="code-command-message">Proposta rifiutata. Nessuna modifica eseguita.</p><?php endif; ?>
        <?php if ($state === 'committed'): ?><p class="code-command-message">Commit creato. Nessun push eseguito.</p><?php endif; ?>
        <p class="code-command-message" data-code-git-message></p>
    </div>
    <?php
};
$activeCodeProvider = $codeProviderMode;
foreach (array_reverse($history) as $turn) {
    if ((string) ($turn['role'] ?? '') === 'assistant' && trim((string) ($turn['provider'] ?? '')) !== '') {
        $activeCodeProvider = (string) $turn['provider'];
        break;
    }
}
$codeProviderStatus = $activeCodeProvider === $codeProviderMode
    ? '- · stato ' . $codeProviderMode
    : '- · stato online';
?>
<section class="code-chat-surface" aria-labelledby="code-chat-title">
    <h2 class="sr-only" id="code-chat-title">Chat Code</h2>
    <div class="chat-provider-status chat-provider-status-action code-provider-status" data-code-provider-live data-code-provider-mode="<?= View::e($codeProviderMode) ?>">
        <div>
            <span>AI</span>
            <strong data-code-provider-badge><?= View::e(strtoupper($activeCodeProvider)) ?></strong>
            <small data-code-provider-summary><?= View::e($codeProviderStatus) ?></small>
        </div>
        <div class="code-running-processes" data-code-running-processes>
            <button type="button" class="code-running-processes-toggle<?= $activeProcesses !== [] ? ' is-active' : '' ?>"
                    data-code-running-processes-toggle aria-expanded="false" aria-controls="code-running-processes-menu">
                In esecuzione<span data-code-running-processes-count><?= $activeProcesses !== [] ? ' · ' . count($activeProcesses) : '' ?></span>
            </button>
            <div class="code-running-processes-menu" id="code-running-processes-menu" data-code-running-processes-menu hidden>
                <section class="code-running-processes-section" aria-labelledby="code-active-processes-title">
                    <strong id="code-active-processes-title">Processi attivi</strong>
                    <div data-code-running-processes-list>
                        <?php foreach ($activeProcesses as $process): ?>
                            <div class="code-running-process-item" data-code-running-process-item
                                 data-process-id="<?= View::e((string) ($process['process_id'] ?? '')) ?>"
                                 data-process-digest="<?= View::e((string) ($process['digest'] ?? '')) ?>">
                                <code><?= View::e((string) ($process['display_summary'] ?? '')) ?></code>
                                <button type="button" class="button ghost" data-code-running-process-stop>Ferma</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="code-running-processes-empty" data-code-running-processes-empty<?= $activeProcesses !== [] ? ' hidden' : '' ?>>Nessun processo in esecuzione.</p>
                </section>
                <section class="code-running-processes-section" aria-labelledby="code-pending-operations-title">
                    <strong id="code-pending-operations-title">In attesa di decisione</strong>
                    <div data-code-pending-operations-list></div>
                    <p class="code-running-processes-empty" data-code-pending-operations-empty>Nessuna operazione da decidere.</p>
                </section>
            </div>
        </div>
    </div>

    <?php if ($chatState !== CodeChatSchema::STATE_READY): ?>
        <div class="code-chat-blocked" role="status">
            <strong>Chat Code non disponibile</strong>
            <p><?= $chatState === CodeChatSchema::STATE_MISSING ? 'Chat Code non ancora attivata su questa installazione.' : 'Lo schema della chat non è compatibile.' ?></p>
        </div>
    <?php elseif ($session === null): ?>
        <div class="code-chat-empty">
            <?php if ($accessRevoked): ?>
                <h3>Accesso alla cartella revocato</h3>
                <p>Non ci sono conversazioni da consultare.</p>
            <?php else: ?>
                <h3>Inizia una nuova sessione</h3>
                <p>Potrai chiedere a Code di analizzare, trovare e spiegare i file di questa cartella.</p>
                <form method="post" action="/code/session/create">
                    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                    <input type="hidden" name="workspace_id" value="<?= (int) $workspace->id ?>">
                    <button class="button" type="submit">Nuova sessione</button>
                </form>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="code-chat" data-code-chat data-code-workspace="<?= (int) $workspace->id ?>"
             data-code-session="<?= (int) $session['id'] ?>" data-code-csrf="<?= View::e($csrf) ?>">
            <div class="code-chat-log" data-code-chat-log aria-label="Cronologia della conversazione">
                <?php if (!$history): ?>
                    <div class="code-chat-empty" data-code-empty-chat>
                        <h3>Chiedi qualcosa sulla cartella</h3>
                        <p><?= View::e($codeCapabilityHint) ?></p>
                    </div>
                <?php endif; ?>
                <?php foreach ($history as $turn): ?>
                    <?php $turnPatches = $patchProposals[(int) ($turn['id'] ?? 0)] ?? []; ?>
                    <?php $hasAppliedPatch = array_filter($turnPatches, static fn (array $card): bool => (string) ($card['status'] ?? '') === 'applied') !== []; ?>
                    <?php $turnCommands = $historyCommands[(int) ($turn['id'] ?? 0)] ?? []; ?>
                    <?php $turnProcesses = $historyProcesses[(int) ($turn['id'] ?? 0)] ?? []; ?>
                    <?php $turnGit = $historyGit[(int) ($turn['id'] ?? 0)] ?? []; ?>
                    <article class="code-msg code-msg-<?= View::e((string) $turn['role']) ?>">
                        <span class="code-msg-role"><?= (string) $turn['role'] === 'assistant' ? 'Code' : 'Tu' ?></span>
                        <?php if ((string) $turn['role'] === 'assistant'): ?>
                            <div class="chat-content code-msg-content"><?= $hasAppliedPatch ? 'Modifica applicata.' : View::messageContent((string) $turn['content']) ?></div>
                        <?php else: ?>
                            <p><?= nl2br(View::e((string) $turn['content'])) ?></p>
                        <?php endif; ?>
                        <?php foreach ($turnPatches as $card): ?>
                            <?php $renderPatchCard($card); ?>
                        <?php endforeach; ?>
                        <?php foreach ($turnCommands as $card): ?>
                            <?php $renderCommandCard($card); ?>
                        <?php endforeach; ?>
                        <?php foreach ($turnProcesses as $card): ?>
                            <?php $renderProcessCard($card); ?>
                        <?php endforeach; ?>
                        <?php foreach ($turnGit as $card): ?>
                            <?php $renderGitCard($card); ?>
                        <?php endforeach; ?>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="code-composer-stack">
                <div class="code-operation-dock" data-code-operation-dock aria-label="Operazione corrente"></div>
                <div class="code-composer-location" title="<?= View::e($workspace->rootPath) ?>">
                    <span aria-hidden="true">▣</span> Locale
                    <span aria-hidden="true">—</span>
                    <strong><?= View::e($authorizedFolder) ?></strong>
                </div>
                <span class="sr-only" data-code-chat-announcer role="status" aria-live="polite">Pronto</span>
                <?php if ($accessRevoked): ?>
                <div class="code-chat-blocked code-access-revoked">
                    <strong>Accesso alla cartella revocato</strong>
                    <p>La cronologia resta disponibile, ma Code non può leggere i file o ricevere nuovi messaggi.</p>
                    <form method="post" action="/code/open" data-code-folder-form>
                        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                        <input type="hidden" name="path" value="">
                        <button class="button" type="button" data-code-folder-picker>Riautorizza cartella</button>
                        <span data-code-folder-picker-status role="status" aria-live="polite"></span>
                    </form>
                </div>
                <?php elseif ((string) $session['status'] !== 'active'): ?>
                <div class="code-chat-blocked"><strong>Sessione archiviata</strong><p>La cronologia resta consultabile in sola lettura.</p></div>
                <?php else: ?>
                <form class="chat-compose code-chat-form" data-code-chat-form enctype="multipart/form-data">
                    <input class="code-file-input" type="file" name="attachments[]" multiple
                           accept=".txt,.md,.csv,.json,.xml,.html,.css,.js,.ts,.php,.py,.sql,.log,.yml,.yaml"
                           data-code-chat-files>
                    <button class="code-attach-button" type="button" data-code-chat-attach aria-label="Aggiungi file">＋</button>
                    <label class="sr-only" for="code-prompt">Messaggio per Code</label>
                    <div class="code-chat-input-field">
                        <div class="code-chat-attachments" data-code-chat-attachments></div>
                        <textarea id="code-prompt" name="prompt" rows="3" required
                                  placeholder="Chiedi di analizzare, trovare o spiegare…" data-code-chat-input></textarea>
                    </div>
                    <button class="button chat-send-button code-chat-action" type="submit"
                            data-code-chat-action aria-label="Invia messaggio">
                        <span class="send-icon" aria-hidden="true"></span>
                        <span class="stop-icon" aria-hidden="true"></span>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
