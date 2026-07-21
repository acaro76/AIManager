/** Code Fase 2: superficie autonoma, read-only e accessibile. */
(function () {
    'use strict';

    document.querySelectorAll('[data-code-folder-form]').forEach((form) => {
        const button = form.querySelector('[data-code-folder-picker]');
        const pathInput = form.querySelector('input[name="path"]');
        const csrfInput = form.querySelector('input[name="_csrf"]');
        const status = form.querySelector('[data-code-folder-picker-status]');
        if (!button || !pathInput || !csrfInput) return;
        const idleContent = button.innerHTML;

        button.addEventListener('click', async () => {
            button.disabled = true;
            button.textContent = 'Apro Finder…';
            if (status) status.textContent = '';
            const data = new FormData();
            data.append('_csrf', csrfInput.value);
            try {
                const response = await fetch('/code/folder/pick', { method: 'POST', body: data, credentials: 'same-origin' });
                const payload = await response.json();
                if (payload.ok && payload.path) {
                    pathInput.value = payload.path;
                    if (status) status.textContent = 'Cartella selezionata. Autorizzazione in corso…';
                    form.requestSubmit();
                    return;
                }
                if (!payload.cancelled && status) status.textContent = payload.message || 'Impossibile aprire Finder.';
            } catch (_) {
                if (status) status.textContent = 'Impossibile aprire Finder.';
            }
            button.disabled = false;
            button.innerHTML = idleContent;
        });
    });

    /**
     * I menu della sidebar (cartella e sessione) sono `<details>`: da soli resterebbero aperti tutti
     * insieme, sovrapponendosi fra loro e alle voci sotto. Ne resta aperto al massimo UNO, e si chiude
     * cliccando fuori o con Escape. Vale anche per la rinomina, benché contenga un campo di testo: una
     * modifica non confermata con `Rinomina` non è comunque salvata.
     * Vive sopra le guardie di `shell`/`chat` perché la sidebar c'è su ogni superficie Code.
     */
    const NAV_MENU_OPEN = 'details.code-nav-folder-menu[open], details.code-nav-session-menu[open]';
    const openNavMenus = () => document.querySelectorAll(NAV_MENU_OPEN);
    const closeNavMenus = (except) => {
        openNavMenus().forEach((menu) => { if (menu !== except) menu.open = false; });
    };
    // `toggle` non fa bubbling: si intercetta in fase di cattura.
    document.addEventListener('toggle', (event) => {
        const menu = event.target;
        if (menu instanceof HTMLElement && menu.matches(NAV_MENU_OPEN)) closeNavMenus(menu);
    }, true);
    document.addEventListener('click', (event) => {
        openNavMenus().forEach((menu) => { if (!menu.contains(event.target)) menu.open = false; });
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        // Con un dialog aperto l'Escape è suo: non deve chiudere anche il menu che sta dietro.
        if (document.querySelector('dialog[open]')) return;
        openNavMenus().forEach((menu) => {
            menu.open = false;
            menu.querySelector('summary')?.focus();
        });
    });

    /**
     * Conferma della revoca cartella con `<dialog>` nativo, al posto del confirm del browser (che resta
     * agli altri form `data-confirm`). Il form POST non viene toccato: dopo la conferma si invia QUELLO,
     * con CSRF e id invariati. `form.submit()` non rilancia l'evento submit, quindi non si rientra qui.
     */
    const revokeDialog = document.querySelector('[data-code-revoke-dialog]');
    let pendingRevokeForm = null;
    document.querySelectorAll('form[data-code-revoke-form]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (revokeDialog && typeof revokeDialog.showModal === 'function') {
                event.preventDefault();
                pendingRevokeForm = form;
                // `close()` senza argomento NON azzera returnValue: va ripulito a ogni apertura, altrimenti
                // un Escape dopo una conferma precedente revocherebbe da solo.
                revokeDialog.returnValue = '';
                revokeDialog.showModal();
                return;
            }
            // Senza `<dialog>` nativo si ricade sul confirm del browser: mai una revoca senza conferma.
            if (!window.confirm('Revocare l\'accesso a questa cartella?')) event.preventDefault();
        });
    });
    revokeDialog?.addEventListener('close', () => {
        const form = pendingRevokeForm;
        pendingRevokeForm = null;
        if (form && revokeDialog.returnValue === 'confirm') form.submit();
    });

    const shell = document.querySelector('[data-code-shell]');
    if (!shell) return;

    const workspaceId = shell.dataset.codeWorkspace || '';
    const panelButtons = shell.querySelectorAll('[data-code-panel-target]');
    const panels = shell.querySelectorAll('[data-code-panel]');
    const providerLive = shell.querySelector('[data-code-provider-live]');
    const preview = shell.querySelector('[data-code-preview]');
    const previewTitle = shell.querySelector('[data-code-preview-title]');
    const previewStatus = shell.querySelector('[data-code-preview-status]');
    const previewContent = shell.querySelector('[data-code-preview-content]');
    const closePreview = shell.querySelector('[data-code-close-preview]');
    let previewReturnFocus = null;

    function activatePanel(name) {
        const requested = Array.from(panels).find((panel) => panel.dataset.codePanel === name);
        const shouldOpen = requested && requested.hidden;
        panels.forEach((panel) => {
            const open = Boolean(shouldOpen && panel === requested);
            panel.hidden = !open;
            panel.classList.toggle('open', open);
        });
        panelButtons.forEach((button) => {
            const active = button.dataset.codePanelTarget === name;
            button.classList.toggle('active', Boolean(shouldOpen && active));
            button.setAttribute('aria-expanded', shouldOpen && active ? 'true' : 'false');
        });
        shell.classList.toggle('drawer-open', Boolean(shouldOpen));
    }
    panelButtons.forEach((button) => button.addEventListener('click', () => activatePanel(button.dataset.codePanelTarget)));
    shell.querySelectorAll('[data-code-close-panel]').forEach((button) => button.addEventListener('click', () => activatePanel('')));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && shell.classList.contains('drawer-open')) activatePanel('');
    });

    async function openFile(path, line, trigger) {
        if (!path || !preview || !previewContent) return;
        previewReturnFocus = trigger || document.activeElement;
        preview.hidden = false;
        if (closePreview) closePreview.hidden = false;
        previewContent.replaceChildren();
        previewStatus.textContent = 'Apertura del file…';
        previewTitle.textContent = path;
        try {
            const params = new URLSearchParams({ workspace_id: workspaceId, path, line: String(line || 1) });
            const response = await fetch('/code/file?' + params.toString(), { credentials: 'same-origin' });
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.message || 'File non consultabile.');
            const lines = String(payload.content || '').split('\n');
            const focusLine = Math.min(Math.max(Number(payload.focus_line || 1), 1), Math.max(lines.length, 1));
            lines.forEach((text, index) => {
                const li = document.createElement('li');
                li.textContent = text || ' ';
                li.value = index + 1;
                if (index + 1 === focusLine) {
                    li.className = 'focused';
                    li.tabIndex = -1;
                }
                previewContent.appendChild(li);
            });
            previewStatus.textContent = lines.length + ' righe · anteprima di sola lettura';
            previewTitle.focus();
            previewContent.querySelector('.focused')?.scrollIntoView({ block: 'center' });
        } catch (error) {
            previewStatus.textContent = error.message || 'File non consultabile.';
            previewTitle.focus();
        }
    }

    shell.addEventListener('click', (event) => {
        const fileButton = event.target.closest('[data-code-open-file]');
        if (fileButton) openFile(fileButton.dataset.codeOpenFile, Number(fileButton.dataset.codeLine || 1), fileButton);
    });
    closePreview?.addEventListener('click', () => {
        preview.hidden = true;
        closePreview.hidden = true;
        previewContent.replaceChildren();
        previewReturnFocus?.focus();
    });

    // Explorer lazy: espandere una directory non legge mai contenuti dei file.
    const tree = shell.querySelector('[data-code-tree]');
    const treeStatus = shell.querySelector('[data-code-tree-status]');
    const filter = shell.querySelector('[data-code-file-filter]');
    const filterResults = shell.querySelector('[data-code-file-results]');
    const inventoryNode = shell.querySelector('[data-code-inventory]');
    let inventory = [];
    try { inventory = JSON.parse(inventoryNode?.textContent || '[]'); } catch (_) { inventory = []; }

    async function toggleDirectory(button) {
        const path = button.dataset.codeDir;
        const group = tree.querySelector('[data-code-dir-children="' + CSS.escape(path) + '"]');
        const expanded = button.getAttribute('aria-expanded') === 'true';
        if (expanded) {
            button.setAttribute('aria-expanded', 'false');
            button.querySelector('span').textContent = '▸';
            group.hidden = true;
            return;
        }
        button.setAttribute('aria-expanded', 'true');
        button.querySelector('span').textContent = '▾';
        group.hidden = false;
        if (group.dataset.loaded === 'true') return;
        treeStatus.textContent = 'Caricamento cartella…';
        button.disabled = true;
        try {
            const params = new URLSearchParams({ workspace_id: workspaceId, path });
            const response = await fetch('/code/children?' + params.toString(), { credentials: 'same-origin' });
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.message || 'Cartella non leggibile.');
            payload.children.forEach((child) => {
                if (child.type === 'dir') {
                    const wrapper = document.createElement('div');
                    const childButton = document.createElement('button');
                    childButton.type = 'button';
                    childButton.className = 'code-tree-dir';
                    childButton.dataset.codeDir = child.path;
                    childButton.setAttribute('aria-expanded', 'false');
                    childButton.setAttribute('role', 'treeitem');
                    const marker = document.createElement('span');
                    marker.setAttribute('aria-hidden', 'true');
                    marker.textContent = '▸';
                    childButton.append(marker, document.createTextNode(' ' + child.path.split('/').pop()));
                    const childGroup = document.createElement('div');
                    childGroup.dataset.codeDirChildren = child.path;
                    childGroup.setAttribute('role', 'group');
                    wrapper.append(childButton, childGroup);
                    group.appendChild(wrapper);
                } else {
                    const fileButton = document.createElement('button');
                    fileButton.type = 'button';
                    fileButton.className = 'code-tree-file';
                    fileButton.dataset.codeOpenFile = child.path;
                    fileButton.dataset.codeFilterValue = child.path.toLowerCase();
                    fileButton.setAttribute('role', 'treeitem');
                    fileButton.textContent = child.path.split('/').pop();
                    fileButton.title = child.path;
                    group.appendChild(fileButton);
                }
            });
            if (!payload.children.length) {
                const empty = document.createElement('p'); empty.className = 'code-empty'; empty.textContent = 'Cartella vuota'; group.appendChild(empty);
            }
            group.dataset.loaded = 'true';
            treeStatus.textContent = payload.children.length + ' elementi';
        } catch (error) {
            button.setAttribute('aria-expanded', 'false');
            button.querySelector('span').textContent = '▸';
            treeStatus.textContent = error.message || 'Cartella non leggibile.';
        } finally {
            button.disabled = false;
        }
    }
    tree?.addEventListener('click', (event) => {
        const dir = event.target.closest('[data-code-dir]');
        if (dir) toggleDirectory(dir);
    });
    filter?.addEventListener('input', () => {
        const query = filter.value.trim().toLowerCase();
        if (filterResults) {
            filterResults.replaceChildren();
            filterResults.hidden = !query;
            if (query) {
                inventory.filter((item) => String(item.path || '').toLowerCase().includes(query)).slice(0, 100).forEach((item) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.dataset.codeOpenFile = item.path;
                    button.textContent = item.path;
                    filterResults.appendChild(button);
                });
                if (!filterResults.children.length) {
                    const p = document.createElement('p'); p.className = 'code-empty'; p.textContent = 'Nessun file trovato'; filterResults.appendChild(p);
                }
            }
        }
        tree.querySelectorAll('[data-code-filter-value]').forEach((node) => {
            node.hidden = Boolean(query) && !node.dataset.codeFilterValue.includes(query);
        });
    });

    // Chat SSE e macchina a stati.
    const chat = shell.querySelector('[data-code-chat]');
    if (!chat) return;
    const form = chat.querySelector('[data-code-chat-form]');
    const input = chat.querySelector('[data-code-chat-input]');
    const log = chat.querySelector('[data-code-chat-log]');
    const actionBtn = chat.querySelector('[data-code-chat-action]');
    const fileInput = chat.querySelector('[data-code-chat-files]');
    const attachBtn = chat.querySelector('[data-code-chat-attach]');
    const attachmentList = chat.querySelector('[data-code-chat-attachments]');
    const announcer = chat.querySelector('[data-code-chat-announcer]');
    const operationDock = chat.querySelector('[data-code-operation-dock]');
    // La banda provider e' sorella della chat, non sua discendente.
    const runningProcesses = shell.querySelector('[data-code-running-processes]');
    const runningProcessesToggle = shell.querySelector('[data-code-running-processes-toggle]');
    const runningProcessesMenu = shell.querySelector('[data-code-running-processes-menu]');
    const runningProcessesList = shell.querySelector('[data-code-running-processes-list]');
    const runningProcessesEmpty = shell.querySelector('[data-code-running-processes-empty]');
    const pendingOperationsList = shell.querySelector('[data-code-pending-operations-list]');
    const pendingOperationsEmpty = shell.querySelector('[data-code-pending-operations-empty]');
    const runningProcessesCount = shell.querySelector('[data-code-running-processes-count]');
    if (!form || !input || !log) return;

    const csrf = chat.dataset.codeCsrf || '';
    const sessionId = chat.dataset.codeSession || '';
    let activeRequestId = null;
    let activeController = null;
    let activeCommandCard = null;
    let state = 'idle';
    const rejectedOperationMessage = 'Proposta rifiutata. Nessuna modifica eseguita.';

    function beginOperationAction(card) {
        if (card.dataset.codeOperationBusy === '1') return false;
        card.dataset.codeOperationBusy = '1';
        card.querySelectorAll('button,input').forEach((control) => { control.disabled = true; });
        return true;
    }
    function endOperationAction(card) {
        delete card.dataset.codeOperationBusy;
        card.querySelectorAll('button,input').forEach((control) => { control.disabled = false; });
    }
    function hasOperationCard(type, id) {
        const attribute = {
            patch: ['[data-code-patch]', 'operationId'],
            command: ['[data-code-command]', 'commandId'],
            process: ['[data-code-process]', 'processId'],
            git: ['[data-code-git]', 'operationId'],
        }[type];
        if (!attribute || !id) return false;
        return Array.from(chat.querySelectorAll(attribute[0]))
            .some((card) => card.dataset[attribute[1]] === String(id));
    }

    // Patch e comandi confermabili hanno una sede stabile sopra il composer. I processi restano
    // invece nel turno che li ha proposti: possono vivere oltre molti messaggi e spostarli nel dock
    // romperebbe la cronologia visiva.
    if (operationDock) {
        log.querySelectorAll('[data-code-patch], [data-code-command]').forEach((card) => {
            const patchPending = card.matches('[data-code-patch]') && card.dataset.patchStatus === 'proposed';
            const commandActive = card.matches('[data-code-command]') && isCommandActive(card);
            if (patchPending || commandActive) {
                card._codeOrigin = card.parentElement;
                operationDock.appendChild(card);
            }
        });
        // L'isola è fuori dal flusso del log: senza riservarne lo spazio, l'ultimo messaggio le finisce
        // sotto e nessuno scroll lo recupera (il log è già a fondo corsa).
        if (typeof ResizeObserver === 'function') new ResizeObserver(() => syncDockSpace()).observe(operationDock);
        window.addEventListener('resize', syncDockSpace);
        syncDockSpace(true);
    }

    /**
     * Spazio in fondo al log pari all'altezza EFFETTIVA dell'isola. Il padding di base resta quello del
     * CSS (cambia con le media query): lo si rilegge azzerando prima lo stile inline. Il composer non si
     * muove — cambia solo il padding interno del log.
     */
    function syncDockSpace(forceBottom = false) {
        if (!operationDock) return;
        const atBottom = forceBottom || Math.abs(log.scrollHeight - log.clientHeight - log.scrollTop) < 2;
        log.style.paddingBottom = '';
        const base = parseFloat(getComputedStyle(log).paddingBottom) || 0;
        const dockHeight = operationDock.getBoundingClientRect().height;
        if (dockHeight > 0) log.style.paddingBottom = (base + dockHeight + 8) + 'px';
        if (atBottom) log.scrollTop = log.scrollHeight;
    }

    function hasPendingDecision() {
        return Boolean(
            operationDock?.querySelector('[data-code-patch][data-patch-status="proposed"], [data-code-command][data-command-state="pending"]')
            || log.querySelector('[data-code-process][data-process-state="pending"]')
            || log.querySelector('[data-code-git][data-state="pending"], [data-code-git][data-state="commit_pending"]')
        );
    }

    function syncProcessControl(message) {
        const working = Boolean(activeRequestId || activeCommandCard);
        if (actionBtn) {
            actionBtn.disabled = (!working && hasPendingDecision()) || state === 'stopping';
            actionBtn.setAttribute('aria-label', working ? 'Interrompi elaborazione' : 'Invia messaggio');
        }
        form.classList.toggle('is-thinking', working);
    }
    function removeThinking() { log.querySelector('[data-code-thinking]')?.remove(); }
    function showThinking() {
        if (log.querySelector('[data-code-thinking]')) return;
        const article = document.createElement('article');
        article.className = 'code-msg code-msg-assistant thinking-message';
        article.dataset.codeThinking = '';
        const bubble = document.createElement('div'); bubble.className = 'thinking-bubble';
        const mark = document.createElement('span'); mark.className = 'thinking-mark'; mark.setAttribute('role', 'status'); mark.setAttribute('aria-label', 'Code sta elaborando');
        ['a', 'b', 'c'].forEach((name) => { const shape = document.createElement('span'); shape.className = 'thinking-shape shape-' + name; mark.appendChild(shape); });
        bubble.appendChild(mark); article.appendChild(bubble); log.appendChild(article); log.scrollTop = log.scrollHeight;
    }

    function requestId() {
        if (window.crypto && crypto.getRandomValues) {
            const bytes = new Uint8Array(12); crypto.getRandomValues(bytes);
            return 'code-' + Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
        }
        return ('code-' + Math.random().toString(36).slice(2) + Date.now().toString(36) + '000000000000').slice(0, 40);
    }
    function setState(next, message) {
        state = next;
        chat.dataset.codeState = next;
        const busy = ['preparing', 'streaming', 'stopping'].includes(next);
        syncProcessControl(message);
        if (next === 'preparing') showThinking();
        if (!busy || next === 'streaming') removeThinking();
        if (announcer) announcer.textContent = message || ({ idle: 'Pronto', preparing: 'Cerco i file rilevanti…', streaming: 'Risposta in arrivo…', stopping: 'Interruzione…', success: 'Risposta completata', cancelled: 'Richiesta interrotta', error: 'Richiesta non riuscita' }[next]);
    }
    // La risposta di Code è markdown; il messaggio dell'utente resta testo puro, esattamente come
    // nello storico reso da _chat.php. `setText` è l'unico punto che scrive il corpo della bolla.
    function bubble(role, text) {
        chat.querySelector('[data-code-empty-chat]')?.remove();
        const article = document.createElement('article');
        article.className = 'code-msg code-msg-' + role;
        const label = document.createElement('span'); label.className = 'code-msg-role'; label.textContent = role === 'assistant' ? 'Code' : 'Tu';
        const assistant = role === 'assistant';
        const body = document.createElement(assistant ? 'div' : 'p');
        if (assistant) body.className = 'chat-content code-msg-content';
        const setText = (value) => {
            if (assistant) body.innerHTML = window.AIManagerMarkdown.render(value || '');
            else body.textContent = value || '';
        };
        setText(text);
        article.append(label, body); log.appendChild(article); log.scrollTop = log.scrollHeight;
        return { article, text: body, setText };
    }

    // --- Fase 4: card della proposta di modifica (Applica / Rifiuta / Annulla). -----------------
    function patchStat(files) {
        let a = 0; let r = 0;
        files.forEach((f) => { a += Number(f.added || 0); r += Number(f.removed || 0); });
        return { a, r };
    }
    function renderPatchActions(actions, status) {
        actions.replaceChildren();
        if (status === 'applied') {
            const applied = document.createElement('span'); applied.className = 'code-patch-applied'; applied.textContent = '✓ Applicata';
            const rollback = document.createElement('button'); rollback.type = 'button'; rollback.className = 'button ghost'; rollback.dataset.codePatchRollback = ''; rollback.textContent = 'Annulla';
            actions.append(applied, rollback);
        } else {
            const apply = document.createElement('button'); apply.type = 'button'; apply.className = 'button'; apply.dataset.codePatchApply = ''; apply.textContent = 'Applica';
            const reject = document.createElement('button'); reject.type = 'button'; reject.className = 'button ghost'; reject.dataset.codePatchReject = ''; reject.textContent = 'Rifiuta';
            actions.append(apply, reject);
        }
    }
    function verificationText(verification) {
        const names = { 'py-syntax': 'Sintassi Python', 'php-lint': 'Sintassi PHP', 'js-syntax': 'Sintassi JavaScript' };
        const name = names[String(verification.profile || '')] || 'Verifica';
        return name + ': ' + String(verification.label || 'non disponibile') + '.';
    }
    function hasGitCard(origin, operationId) {
        return Array.from(origin?.querySelectorAll('[data-code-git]') || [])
            .some((item) => item.dataset.operationId === String(operationId || ''));
    }
    function renderAppliedCompletion(card, completion) {
        const origin = card._codeOrigin || card.closest('.code-msg') || card.parentElement;
        const summary = card.matches('[data-code-patch-completion]') ? card : document.createElement('div');
        summary.className = 'code-patch-history code-patch-history-applied';
        summary.dataset.codePatchHistory = '';
        summary.dataset.codePatchCompletion = '';
        summary.dataset.operationId = card.dataset.operationId || '';
        summary.replaceChildren();
        const strong = document.createElement('strong');
        strong.textContent = String(completion?.summary || 'Modifica applicata.');
        summary.appendChild(strong);
        (completion?.verifications || []).forEach((verification) => {
            const detail = document.createElement('p');
            detail.className = 'code-patch-message';
            detail.dataset.postApplyDetail = '';
            detail.textContent = verificationText(verification);
            summary.appendChild(detail);
        });
        if (completion?.git?.message) {
            const detail = document.createElement('p');
            detail.className = 'code-patch-message';
            detail.dataset.postApplyDetail = '';
            detail.textContent = String(completion.git.message);
            summary.appendChild(detail);
        }
        if (!card.matches('[data-code-patch-completion]')) {
            if (origin && origin.isConnected) origin.appendChild(summary);
            card.remove();
        }
        const prose = origin?.querySelector('.code-msg-content');
        if (prose) prose.hidden = true;
        const gitCard = completion?.git?.card;
        if (gitCard && origin && !hasGitCard(origin, gitCard.operation_id)) origin.appendChild(buildGitCard(gitCard));
        syncProcessControl();
        syncDockSpace();
    }
    function compactRejectedPatch(card) {
        const summary = document.createElement('div');
        summary.className = 'code-patch-history code-patch-history-rejected';
        summary.dataset.codePatchHistory = '';
        const strong = document.createElement('strong');
        strong.textContent = 'Modifica rifiutata';
        const paths = Array.from(card.querySelectorAll('.code-patch-file-head code'))
            .map((node) => node.textContent || '').filter(Boolean);
        summary.appendChild(strong);
        if (paths.length) summary.appendChild(document.createTextNode(' · ' + paths.join(', ')));
        const origin = card._codeOrigin;
        if (origin && origin.isConnected) origin.appendChild(summary);
        card.remove();
        syncProcessControl();
    }
    function buildPatchCard(proposal) {
        const files = Array.isArray(proposal.files) ? proposal.files : [];
        const st = patchStat(files);
        const card = document.createElement('div');
        card.className = 'code-patch-card';
        card.dataset.codePatch = '';
        card.dataset.operationId = String(proposal.operation_id || '');
        card.dataset.patchDigest = String(proposal.patch_digest || '');
        card.dataset.patchStatus = 'proposed';
        const summary = document.createElement('div'); summary.className = 'code-patch-summary';
        const strong = document.createElement('strong'); strong.textContent = 'Proposta di modifica';
        const stat = document.createElement('span'); stat.className = 'code-patch-stat'; stat.textContent = files.length + ' file · +' + st.a + ' −' + st.r;
        summary.append(strong, stat);
        const list = document.createElement('div'); list.className = 'code-patch-files';
        files.forEach((f) => {
            const det = document.createElement('div'); det.className = 'code-patch-file';
            const sum = document.createElement('div'); sum.className = 'code-patch-file-head';
            const code = document.createElement('code'); code.textContent = String(f.path || '');
            const fs = document.createElement('span'); fs.className = 'code-patch-filestat'; fs.textContent = '+' + Number(f.added || 0) + ' −' + Number(f.removed || 0);
            sum.append(code, fs); det.appendChild(sum);
            if (f.diff) { const pre = document.createElement('pre'); pre.className = 'code-patch-diff'; pre.textContent = String(f.diff); det.appendChild(pre); }
            list.appendChild(det);
        });
        const actions = document.createElement('div'); actions.className = 'code-patch-actions'; actions.dataset.codePatchActions = '';
        renderPatchActions(actions, 'proposed');
        const msg = document.createElement('p'); msg.className = 'code-patch-message'; msg.dataset.codePatchMessage = ''; msg.hidden = true; msg.setAttribute('role', 'status'); msg.setAttribute('aria-live', 'polite');
        card.append(summary, list, actions, msg);
        return card;
    }
    function patchMessage(card, text) { const m = card.querySelector('[data-code-patch-message]'); if (m) { m.textContent = text || ''; m.hidden = !text; } }
    function setPatchBusy(card, busy) { card.querySelectorAll('button').forEach((b) => { b.disabled = busy; }); card.classList.toggle('is-busy', busy); }
    async function patchRequest(url, card) {
        const data = new FormData();
        data.append('_csrf', csrf); data.append('workspace_id', workspaceId); data.append('session_id', sessionId);
        data.append('operation_id', card.dataset.operationId || '');
        data.append('patch_digest', card.dataset.patchDigest || '');
        try {
            const resp = await fetch(url, { method: 'POST', body: data, credentials: 'same-origin' });
            return await resp.json();
        } catch (_) {
            return { ok: false, status: 'error', message: 'Richiesta non riuscita.' };
        }
    }
    chat.addEventListener('click', async (event) => {
        const card = event.target.closest('[data-code-patch]');
        if (!card) return;
        if (event.target.closest('[data-code-patch-apply]')) {
            setPatchBusy(card, true); patchMessage(card, 'Applico…');
            const res = await patchRequest('/code/patch/apply', card);
            if (res.status === 'applied') {
                card.dataset.patchStatus = 'applied';
                renderAppliedCompletion(card, res.completion || { summary: 'Modifica applicata.', verifications: [], git: { message: 'File salvato localmente.' } });
            } else {
                setPatchBusy(card, false);
                patchMessage(card, res.message || 'Applicazione non riuscita.');
            }
        } else if (event.target.closest('[data-code-patch-reject]')) {
            setPatchBusy(card, true);
            const res = await patchRequest('/code/patch/reject', card);
            if (res.status === 'rejected') { compactRejectedPatch(card); } else { setPatchBusy(card, false); patchMessage(card, res.message || 'Non riuscito.'); }
        } else if (event.target.closest('[data-code-patch-rollback]')) {
            setPatchBusy(card, true); patchMessage(card, 'Annullo…');
            const res = await patchRequest('/code/patch/rollback', card);
            if (res.status === 'rolled_back') {
                card.dataset.patchStatus = 'rolled_back';
                card.querySelector('[data-code-patch-actions]').replaceChildren();
                patchMessage(card, 'Modifica annullata.');
            } else {
                setPatchBusy(card, false);
                patchMessage(card, res.message || 'Annullamento non riuscito.');
            }
        }
    });

    // --- Fase 8: staging e commit hanno conferme POST distinte; nessun push implicito. ---
    function buildGitCommitForm(suggestedMessage='') {
        const form=document.createElement('div'); form.className='code-git-commit-form'; form.dataset.codeGitCommitForm='';
        const input=document.createElement('input'); input.type='text'; input.maxLength=200; input.placeholder='Messaggio del commit'; input.setAttribute('aria-label','Messaggio del commit'); input.dataset.codeGitCommitMessage=''; input.value=String(suggestedMessage||'');
        const button=document.createElement('button'); button.type='button'; button.className='button'; button.dataset.codeGitCommitCreate=''; button.textContent='Crea commit';
        form.append(input,button); return form;
    }
    const gitStateLabels={pending:'Da confermare',running:'In corso',staged:'Pronto per il commit',commit_pending:'Da confermare',committed:'Completato',rejected:'Rifiutato',expired:'Scaduto',stale:'Non più valido',denied:'Non consentito',error:'Non riuscito'};
    function setGitCardState(card,state){card.dataset.state=state;const label=card.querySelector('[data-code-git-state]');if(label)label.textContent=gitStateLabels[state]||'Non disponibile';}
    function buildGitCard(git) {
        const card=document.createElement('div'); card.className='code-command-card'; card.dataset.codeGit='';
        card.dataset.operationId=String(git.operation_id||''); card.dataset.digest=String(git.digest||''); card.dataset.kind=String(git.kind||'stage');
        card.dataset.suggestedMessage=String(git.suggested_message||'');
        const summary=document.createElement('div'); summary.className='code-command-summary';
        const strong=document.createElement('strong'); strong.textContent=git.kind==='commit'?'Commit':'File da mettere in stage';
        const state=document.createElement('span'); state.className='code-command-state'; state.dataset.codeGitState=''; summary.append(strong,state);
        const list=document.createElement('div'); list.className='code-patch-files';
        (git.selected||[]).forEach((e)=>{const p=document.createElement('code'); p.textContent=String(e.path||''); list.appendChild(p);});
        card.append(summary,list);
        if (git.kind==='commit' && git.commit_message) { const commitMessage=document.createElement('p'); commitMessage.className='code-git-commit-message'; commitMessage.textContent=String(git.commit_message); card.appendChild(commitMessage); }
        if (git.state==='pending' || git.state==='commit_pending') {
            const actions=document.createElement('div'); actions.className='code-command-actions'; actions.dataset.codeGitActions='';
            const yes=document.createElement('button'); yes.type='button'; yes.className='button'; yes.dataset.codeGitConfirm=''; yes.textContent=git.kind==='commit'?'Crea commit':'Metti in stage';
            const no=document.createElement('button'); no.type='button'; no.className='button ghost'; no.dataset.codeGitReject=''; no.textContent='Rifiuta'; actions.append(yes,no); card.appendChild(actions);
        } else if (git.kind==='stage' && git.state==='staged') {
            card.appendChild(buildGitCommitForm(card.dataset.suggestedMessage));
        }
        const msg=document.createElement('p'); msg.className='code-command-message'; msg.dataset.codeGitMessage=''; card.appendChild(msg); setGitCardState(card,String(git.state||'pending')); return card;
    }
    async function completeHistoricalPatches() {
        const cards = Array.from(log.querySelectorAll('[data-code-patch-completion]'));
        await Promise.all(cards.map(async (card) => {
            if (card.dataset.completionLoaded === '1') return;
            card.dataset.completionLoaded = '1';
            const result = await patchRequest('/code/patch/complete', card);
            if (result.ok) renderAppliedCompletion(card, result);
            else {
                const detail = document.createElement('p');
                detail.className = 'code-patch-message';
                detail.textContent = result.message || 'File applicato. Verifica finale non disponibile.';
                card.appendChild(detail);
            }
        }));
    }
    completeHistoricalPatches();
    async function gitRequest(url,card,extra={}) { const d=new FormData(); d.append('_csrf',csrf); d.append('workspace_id',workspaceId); d.append('session_id',sessionId); d.append('operation_id',card.dataset.operationId||''); d.append('digest',card.dataset.digest||''); Object.entries(extra).forEach(([k,v])=>d.append(k,v)); try{const r=await fetch(url,{method:'POST',body:d,credentials:'same-origin'});return await r.json();}catch(_){return{ok:false,status:'error',message:'Richiesta non riuscita.'};} }
    chat.addEventListener('click',async(event)=>{
        const card=event.target.closest('[data-code-git]'); if(!card)return; const msg=card.querySelector('[data-code-git-message]');
        if(event.target.closest('[data-code-git-reject]')){if(!beginOperationAction(card))return;const r=await gitRequest('/code/git/reject',card);if(r.ok){setGitCardState(card,'rejected');card.querySelector('[data-code-git-actions]')?.remove();msg.textContent=rejectedOperationMessage;}else{endOperationAction(card);msg.textContent=r.message||'Rifiuto non riuscito.';}syncProcessControl();return;}
        if(event.target.closest('[data-code-git-commit-create]')){
            const input=card.querySelector('[data-code-git-commit-message]'); const message=String(input?.value||'').trim();
            if(!message){msg.textContent='Inserisci il messaggio del commit.';input?.focus();return;}
            if(!beginOperationAction(card))return;
            const c=await gitRequest('/code/git/commit/create',card,{message});
            if(c.ok&&c.git){card.dataset.operationId=String(c.git.operation_id||card.dataset.operationId||'');card.dataset.digest=String(c.git.digest||'');card.dataset.kind='commit';delete card.dataset.codeOperationBusy;card.querySelector('.code-command-summary strong').textContent='Commit';setGitCardState(card,'committed');card.querySelector('[data-code-git-commit-form]')?.remove();const shown=document.createElement('p');shown.className='code-git-commit-message';shown.textContent=String(c.git.commit_message||message);msg.before(shown);msg.textContent='Commit creato. Nessun push eseguito.';}else{setGitCardState(card,'staged');msg.textContent=c.message||'Commit non riuscito.';endOperationAction(card);}return;
        }
        if(!event.target.closest('[data-code-git-confirm]'))return; card.querySelectorAll('button').forEach(b=>b.disabled=true);
        if(card.dataset.kind==='commit'){const r=await gitRequest('/code/git/commit/confirm',card);if(r.ok){setGitCardState(card,'committed');card.querySelector('[data-code-git-actions]')?.remove();msg.textContent='Commit creato. Nessun push eseguito.';}else{setGitCardState(card,String(r.status||'error'));msg.textContent=r.message||'Commit non riuscito.';card.querySelectorAll('button').forEach(b=>b.disabled=false);}return;}
        const r=await gitRequest('/code/git/stage/confirm',card);if(!r.ok){setGitCardState(card,String(r.status||'error'));card.querySelector('[data-code-git-actions]')?.remove();msg.textContent=r.message||'Staging non riuscito.';return;}
        setGitCardState(card,'staged'); card.querySelector('[data-code-git-actions]')?.replaceWith(buildGitCommitForm(card.dataset.suggestedMessage)); msg.textContent='Staging completato.';
    });

    // --- Fase 6: card di comando (proposta → conferma/rifiuto/stop). Nessun output persistito: la
    //     card live lo mostra durante l'esecuzione; dopo refresh resta solo lo stato. ---
    function buildCommandCard(command) {
        const card = document.createElement('div');
        card.className = 'code-command-card';
        card.dataset.codeCommand = '';
        card.dataset.commandId = String(command.command_id || '');
        card.dataset.commandDigest = String(command.digest || '');
        card.dataset.commandState = String(command.state || 'pending');
        const summary = document.createElement('div'); summary.className = 'code-command-summary';
        const strong = document.createElement('strong'); strong.textContent = 'Comando';
        const code = document.createElement('code'); code.textContent = String(command.display_summary || command.program || '');
        const state = document.createElement('span'); state.className = 'code-command-state'; state.dataset.codeCommandLabel = ''; state.textContent = String(command.label || '');
        summary.append(strong, code, state);
        const out = document.createElement('pre'); out.className = 'code-command-output'; out.dataset.codeCommandOutput = ''; out.hidden = true;
        const actions = document.createElement('div'); actions.className = 'code-command-actions'; actions.dataset.codeCommandActions = '';
        const run = document.createElement('button'); run.type = 'button'; run.className = 'button'; run.dataset.codeCommandRun = ''; run.textContent = 'Esegui';
        const rej = document.createElement('button'); rej.type = 'button'; rej.className = 'button ghost'; rej.dataset.codeCommandReject = ''; rej.textContent = 'Rifiuta';
        actions.append(run, rej);
        const msg = document.createElement('p'); msg.className = 'code-command-message'; msg.dataset.codeCommandMessage = ''; msg.hidden = true; msg.setAttribute('role', 'status'); msg.setAttribute('aria-live', 'polite');
        card.append(summary, out, actions, msg);
        return card;
    }
    /** L'isola ospita SOLO l'operazione corrente: `pending` o `running`, mai un esito. */
    function isCommandActive(card) { return ['pending', 'running'].includes(card.dataset.commandState || ''); }
    /**
     * Esito raggiunto: la card lascia l'isola e torna nel turno che l'ha proposta, senza azioni —
     * la stessa forma che il server rende dopo un refresh. Così l'isola non accumula e non copre la
     * conversazione. L'output resta leggibile nel turno fino al refresh (non è persistito).
     */
    function settleCommandCard(card) {
        if (isCommandActive(card)) { syncProcessControl(); return; }
        card.querySelector('[data-code-command-actions]')?.replaceChildren();
        const origin = card._codeOrigin;
        if (origin && origin.isConnected) { origin.appendChild(card); } else { card.remove(); }
        syncProcessControl();
        syncDockSpace();
        log.scrollTop = log.scrollHeight;
    }
    function commandMessage(card, text) { const m = card.querySelector('[data-code-command-message]'); if (m) { m.textContent = text || ''; m.hidden = !text; } }
    function commandLabel(card, text) { const l = card.querySelector('[data-code-command-label]'); if (l && text) l.textContent = text; }
    function commandOutput(card, text) { const o = card.querySelector('[data-code-command-output]'); if (o) { o.textContent = text || ''; o.hidden = !text; } }
    async function commandRequest(url, card) {
        const data = new FormData();
        data.append('_csrf', csrf); data.append('workspace_id', workspaceId); data.append('session_id', sessionId);
        data.append('command_id', card.dataset.commandId || '');
        data.append('digest', card.dataset.commandDigest || '');
        try {
            const resp = await fetch(url, { method: 'POST', body: data, credentials: 'same-origin' });
            return await resp.json();
        } catch (_) {
            return { ok: false, status: 'error', message: 'Richiesta non riuscita.' };
        }
    }
    chat.addEventListener('click', async (event) => {
        const card = event.target.closest('[data-code-command]');
        if (!card) return;
        const actions = card.querySelector('[data-code-command-actions]');
        if (event.target.closest('[data-code-command-run]')) {
            card.dataset.commandState = 'running';
            commandLabel(card, 'in esecuzione…');
            commandMessage(card, '');
            if (actions) actions.replaceChildren();
            activeCommandCard = card;
            syncProcessControl('Comando in esecuzione…');
            const res = await commandRequest('/code/command/confirm', card);
            card.dataset.commandState = String(res.status || 'error');
            commandLabel(card, (res.command && res.command.label) ? res.command.label : (res.message || 'Esito'));
            if (res.output) commandOutput(card, res.output);
            if (actions) actions.replaceChildren();
            activeCommandCard = null;
            settleCommandCard(card);
        } else if (event.target.closest('[data-code-command-reject]')) {
            if (!beginOperationAction(card)) return;
            const res = await commandRequest('/code/command/reject', card);
            if (res.status === 'rejected') {
                // Anche il rifiuto lascia traccia nel turno, come dopo un refresh (`rifiutato`).
                card.dataset.commandState = 'rejected';
                commandLabel(card, 'rifiutato');
                commandMessage(card, rejectedOperationMessage);
                settleCommandCard(card);
            } else { endOperationAction(card); commandMessage(card, res.message || 'Non riuscito.'); }
        }
    });

    // --- Fase 7: card di processo persistente (proposta → avvio/rifiuto → stop). Il log live è un
    //     estratto bounded e non fidato; dopo refresh resta lo stato, ricostruito dal server. ---
    function buildProcessCard(process) {
        const card = document.createElement('div');
        card.className = 'code-process-card';
        card.dataset.codeProcess = '';
        card.dataset.processId = String(process.process_id || '');
        card.dataset.processDigest = String(process.digest || '');
        card.dataset.processState = String(process.state || 'pending');
        const summary = document.createElement('div'); summary.className = 'code-process-summary';
        const strong = document.createElement('strong'); strong.textContent = 'Processo';
        const code = document.createElement('code'); code.textContent = String(process.display_summary || '');
        const state = document.createElement('span'); state.className = 'code-process-state'; state.dataset.codeProcessLabel = ''; state.textContent = String(process.label || '');
        summary.append(strong, code, state);
        const log = document.createElement('pre'); log.className = 'code-process-log'; log.dataset.codeProcessLog = ''; log.hidden = true;
        const actions = document.createElement('div'); actions.className = 'code-process-actions'; actions.dataset.codeProcessActions = '';
        const run = document.createElement('button'); run.type = 'button'; run.className = 'button'; run.dataset.codeProcessRun = ''; run.textContent = 'Avvia';
        const rej = document.createElement('button'); rej.type = 'button'; rej.className = 'button ghost'; rej.dataset.codeProcessReject = ''; rej.textContent = 'Rifiuta';
        actions.append(run, rej);
        const msg = document.createElement('p'); msg.className = 'code-process-message'; msg.dataset.codeProcessMessage = ''; msg.hidden = true; msg.setAttribute('role', 'status'); msg.setAttribute('aria-live', 'polite');
        card.append(summary, log, actions, msg);
        return card;
    }
    function processMessage(card, text) { const m = card.querySelector('[data-code-process-message]'); if (m) { m.textContent = text || ''; m.hidden = !text; } }
    function processLabel(card, text) { const l = card.querySelector('[data-code-process-label]'); if (l && text) l.textContent = text; }
    function processLog(card, text) { const o = card.querySelector('[data-code-process-log]'); if (o) { o.textContent = text || ''; o.hidden = !text; } }
    function processActions(card, state, canStop) {
        const actions = card.querySelector('[data-code-process-actions]');
        if (!actions) return;
        actions.replaceChildren();
        if (state === 'pending') {
            const run = document.createElement('button'); run.type = 'button'; run.className = 'button'; run.dataset.codeProcessRun = ''; run.textContent = 'Avvia';
            const rej = document.createElement('button'); rej.type = 'button'; rej.className = 'button ghost'; rej.dataset.codeProcessReject = ''; rej.textContent = 'Rifiuta';
            actions.append(run, rej);
        } else if (canStop) {
            const stop = document.createElement('button'); stop.type = 'button'; stop.className = 'button ghost'; stop.dataset.codeProcessStop = ''; stop.textContent = 'Ferma';
            actions.append(stop);
        }
    }
    function pendingOperationCards() {
        return Array.from(chat.querySelectorAll('[data-code-patch], [data-code-command], [data-code-process], [data-code-git]'))
            .filter((card) => (
                (card.matches('[data-code-patch]') && card.dataset.patchStatus === 'proposed')
                || (card.matches('[data-code-command]') && card.dataset.commandState === 'pending')
                || (card.matches('[data-code-process]') && card.dataset.processState === 'pending')
                || (card.matches('[data-code-git]') && ['pending', 'commit_pending'].includes(card.dataset.state || ''))
            ));
    }
    function pendingOperationIdentity(card) {
        if (card.matches('[data-code-patch]')) return ['patch', card.dataset.operationId || ''];
        if (card.matches('[data-code-command]')) return ['command', card.dataset.commandId || ''];
        if (card.matches('[data-code-process]')) return ['process', card.dataset.processId || ''];
        return ['git', card.dataset.operationId || ''];
    }
    function pendingOperationSummary(card) {
        if (card.matches('[data-code-patch]')) return 'Modifica proposta';
        if (card.matches('[data-code-command]')) return 'Comando · ' + (card.querySelector('.code-command-summary code')?.textContent || 'da eseguire');
        if (card.matches('[data-code-process]')) return 'Processo · ' + (card.querySelector('.code-process-summary code')?.textContent || 'da avviare');
        return card.dataset.kind === 'commit' ? 'Commit da confermare' : 'File da mettere in stage';
    }
    function findPendingOperationCard(type, id) {
        return pendingOperationCards().find((card) => {
            const identity = pendingOperationIdentity(card);
            return identity[0] === type && identity[1] === id;
        }) || null;
    }
    function syncPendingOperationsList() {
        if (!pendingOperationsList) return;
        const cards = pendingOperationCards();
        pendingOperationsList.replaceChildren();
        cards.forEach((card) => {
            const [type, id] = pendingOperationIdentity(card);
            if (!id) return;
            const item = document.createElement('div'); item.className = 'code-pending-operation-item'; item.dataset.codePendingOperationItem = '';
            item.dataset.operationType = type; item.dataset.operationId = id;
            const summary = document.createElement('span'); summary.textContent = pendingOperationSummary(card);
            const show = document.createElement('button'); show.type = 'button'; show.className = 'button ghost'; show.dataset.codePendingOperationShow = ''; show.textContent = 'Mostra';
            const cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'button ghost'; cancel.dataset.codePendingOperationCancel = ''; cancel.textContent = 'Annulla';
            item.append(summary, show, cancel); pendingOperationsList.appendChild(item);
        });
        if (pendingOperationsEmpty) pendingOperationsEmpty.hidden = cards.length > 0;
    }
    function syncRunningProcessesIndicator() {
        const processCount = runningProcessesList?.querySelectorAll('[data-code-running-process-item]').length || 0;
        const pendingCount = pendingOperationsList?.querySelectorAll('[data-code-pending-operation-item]').length || 0;
        const count = processCount + pendingCount;
        if (runningProcessesCount) runningProcessesCount.textContent = count > 0 ? ' · ' + count : '';
        runningProcessesToggle?.classList.toggle('is-active', count > 0);
        if (runningProcessesEmpty) runningProcessesEmpty.hidden = processCount > 0;
    }
    function removeRunningProcess(processId) {
        runningProcessesList?.querySelectorAll('[data-code-running-process-item]').forEach((item) => {
            if (item.dataset.processId === processId) item.remove();
        });
        syncRunningProcessesIndicator();
    }
    function upsertRunningProcess(process, card = null) {
        const processId = String(process?.process_id || card?.dataset.processId || '');
        const state = String(process?.state || card?.dataset.processState || '');
        if (!processId || !['starting', 'running'].includes(state) || !runningProcessesList) {
            if (processId) removeRunningProcess(processId);
            return;
        }
        let item = Array.from(runningProcessesList.querySelectorAll('[data-code-running-process-item]'))
            .find((row) => row.dataset.processId === processId);
        if (!item) {
            item = document.createElement('div'); item.className = 'code-running-process-item'; item.dataset.codeRunningProcessItem = '';
            const summary = document.createElement('code');
            const stop = document.createElement('button'); stop.type = 'button'; stop.className = 'button ghost'; stop.dataset.codeRunningProcessStop = ''; stop.textContent = 'Ferma';
            item.append(summary, stop); runningProcessesList.appendChild(item);
        }
        item.dataset.processId = processId;
        item.dataset.processDigest = String(process?.digest || card?.dataset.processDigest || '');
        const summary = item.querySelector('code');
        if (summary) summary.textContent = String(process?.display_summary || card?.querySelector('.code-process-summary code')?.textContent || 'Processo');
        syncRunningProcessesIndicator();
    }
    function findProcessCard(processId) {
        return Array.from(log.querySelectorAll('[data-code-process]')).find((card) => card.dataset.processId === processId) || null;
    }
    function setRunningProcessesMenu(open) {
        if (!runningProcessesMenu || !runningProcessesToggle) return;
        runningProcessesMenu.hidden = !open;
        runningProcessesToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    runningProcessesToggle?.addEventListener('click', () => setRunningProcessesMenu(Boolean(runningProcessesMenu?.hidden)));
    document.addEventListener('click', (event) => {
        if (runningProcesses && !runningProcesses.contains(event.target)) setRunningProcessesMenu(false);
    });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') setRunningProcessesMenu(false); });
    syncRunningProcessesIndicator();
    /** La card resta sempre nel turno d'origine: stato e azioni cambiano, la cronologia no. */
    function settleProcessCard(card) {
        syncProcessControl();
        syncDockSpace();
        log.scrollTop = log.scrollHeight;
    }
    async function processRequest(url, card) {
        const data = new FormData();
        data.append('_csrf', csrf); data.append('workspace_id', workspaceId); data.append('session_id', sessionId);
        data.append('process_id', card.dataset.processId || '');
        data.append('digest', card.dataset.processDigest || '');
        try {
            const resp = await fetch(url, { method: 'POST', body: data, credentials: 'same-origin' });
            return await resp.json();
        } catch (_) {
            return { ok: false, status: 'error', message: 'Richiesta non riuscita.' };
        }
    }
    runningProcesses?.addEventListener('click', async (event) => {
        const pendingItem = event.target.closest('[data-code-pending-operation-item]');
        if (pendingItem) {
            const card = findPendingOperationCard(pendingItem.dataset.operationType || '', pendingItem.dataset.operationId || '');
            if (!card) { syncPendingOperationsList(); syncRunningProcessesIndicator(); return; }
            if (event.target.closest('[data-code-pending-operation-show]')) {
                setRunningProcessesMenu(false);
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                card.classList.add('code-operation-highlight');
                window.setTimeout(() => card.classList.remove('code-operation-highlight'), 1600);
                return;
            }
            if (event.target.closest('[data-code-pending-operation-cancel]')) {
                const reject = card.querySelector('[data-code-patch-reject], [data-code-command-reject], [data-code-process-reject], [data-code-git-reject]');
                setRunningProcessesMenu(false);
                reject?.click();
                return;
            }
        }
        const runningItem = event.target.closest('[data-code-running-process-item]');
        if (!runningItem || !event.target.closest('[data-code-running-process-stop]')) return;
        const res = await processRequest('/code/process/stop', runningItem);
        const processId = runningItem.dataset.processId || '';
        const card = findProcessCard(processId);
        if (card) {
            const state = String(res.status || 'error');
            card.dataset.processState = state;
            processLabel(card, (res.process && res.process.label) ? res.process.label : (res.message || 'Arrestato'));
            processMessage(card, '');
            processActions(card, state, Boolean(res.process && res.process.can_stop));
        }
        if (!res.process || !['starting', 'running'].includes(String(res.process.state || ''))) removeRunningProcess(processId);
    });
    syncPendingOperationsList();
    syncRunningProcessesIndicator();
    if (typeof MutationObserver === 'function') {
        new MutationObserver(() => {
            syncPendingOperationsList();
            syncRunningProcessesIndicator();
        }).observe(chat, {
            subtree: true,
            childList: true,
            attributes: true,
            attributeFilter: ['data-patch-status', 'data-command-state', 'data-process-state', 'data-state'],
        });
    }
    chat.addEventListener('click', async (event) => {
        const card = event.target.closest('[data-code-process]');
        if (!card) return;
        if (event.target.closest('[data-code-process-run]')) {
            card.dataset.processState = 'starting';
            processLabel(card, 'in avvio…');
            processMessage(card, '');
            card.querySelector('[data-code-process-actions]')?.replaceChildren();
            const res = await processRequest('/code/process/confirm', card);
            const state = String(res.status || 'error');
            card.dataset.processState = state;
            processLabel(card, (res.process && res.process.label) ? res.process.label : (res.message || 'Esito'));
            // Il log di avvio non e' persistito nella cronologia: non mostrarlo solo nella vista live,
            // cosi' la card resta compatta e identica prima e dopo il refresh.
            processLog(card, '');
            processActions(card, state, Boolean(res.process && res.process.can_stop));
            upsertRunningProcess(res.process, card);
            settleProcessCard(card);
        } else if (event.target.closest('[data-code-process-reject]')) {
            if (!beginOperationAction(card)) return;
            const res = await processRequest('/code/process/reject', card);
            if (res.status === 'rejected') {
                card.dataset.processState = 'rejected';
                processLabel(card, 'rifiutato');
                processMessage(card, rejectedOperationMessage);
                card.querySelector('[data-code-process-actions]')?.replaceChildren();
                settleProcessCard(card);
            } else { endOperationAction(card); processMessage(card, res.message || 'Non riuscito.'); }
        } else if (event.target.closest('[data-code-process-stop]')) {
            processMessage(card, 'Arresto in corso…');
            const res = await processRequest('/code/process/stop', card);
            const state = String(res.status || 'error');
            card.dataset.processState = state;
            processLabel(card, (res.process && res.process.label) ? res.process.label : (res.message || 'Arrestato'));
            processMessage(card, '');
            processActions(card, state, Boolean(res.process && res.process.can_stop));
            removeRunningProcess(card.dataset.processId || '');
            settleProcessCard(card);
        }
    });

    function renderSelectedFiles() {
        if (!fileInput || !attachmentList) return;
        attachmentList.replaceChildren();
        Array.from(fileInput.files || []).forEach((file) => {
            const chip = document.createElement('span');
            chip.className = 'code-attachment-chip';
            chip.textContent = file.name;
            attachmentList.appendChild(chip);
        });
    }
    attachBtn?.addEventListener('click', () => fileInput?.click());
    fileInput?.addEventListener('change', renderSelectedFiles);

    async function stopActive() {
        const id = activeRequestId;
        const controller = activeController;
        if (!id || state === 'stopping') return;
        setState('stopping');
        if (controller) controller.abort();
        const data = new FormData(); data.append('_csrf', csrf); data.append('request_id', id);
        try { await fetch('/code/chat/stop', { method: 'POST', body: data, credentials: 'same-origin' }); } catch (_) { /* abort locale gia' avvenuto */ }
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (activeRequestId || activeCommandCard) return;
        const prompt = input.value.trim();
        if (!prompt) return;
        const selectedFiles = Array.from(fileInput?.files || []);
        const userBubble = bubble('user', prompt);
        if (selectedFiles.length) {
            const files = document.createElement('div');
            files.className = 'code-message-files';
            selectedFiles.forEach((file) => {
                const chip = document.createElement('span'); chip.textContent = file.name; files.appendChild(chip);
            });
            userBubble.article.appendChild(files);
        }
        input.value = '';
        const id = requestId();
        const controller = new AbortController();
        activeRequestId = id; activeController = controller; setState('preparing');
        let assistant = null; let partial = ''; let completed = false;
        const data = new FormData();
        data.append('_csrf', csrf); data.append('workspace_id', workspaceId); data.append('session_id', sessionId); data.append('prompt', prompt); data.append('request_id', id);
        selectedFiles.forEach((file) => data.append('attachments[]', file, file.name));

        function turnMessage(message, kind = 'error') {
            assistant?.article.remove();
            assistant = bubble('assistant', message || 'Richiesta non riuscita.');
            assistant.article.classList.add('code-msg-turn-' + kind);
            log.scrollTop = log.scrollHeight;
        }

        function eventBlock(raw) {
            let name = 'message'; let payload = {};
            raw.split('\n').forEach((line) => {
                if (line.startsWith('event: ')) name = line.slice(7).trim();
                if (line.startsWith('data: ')) { try { payload = JSON.parse(line.slice(6)); } catch (_) { payload = {}; } }
            });
            if (name === 'reset') { partial = ''; if (assistant) assistant.setText(''); }
            if (name === 'delta') {
                if (!assistant) assistant = bubble('assistant', '');
                partial += payload.text || ''; assistant.setText(partial); setState('streaming'); log.scrollTop = log.scrollHeight;
            }
            if (name === 'error') {
                completed = true;
                turnMessage(payload.message, 'error');
                setState('error');
            }
            if (name === 'done') {
                completed = true;
                if (payload.session_title) {
                        document.querySelectorAll('[data-code-session-nav="' + sessionId + '"]').forEach((link) => {
                            link.textContent = payload.session_title;
                        });
                        document.querySelectorAll('[data-code-session-title-input="' + sessionId + '"]').forEach((field) => {
                            field.value = payload.session_title;
                        });
                        if (payload.session_title_final) {
                            document.querySelectorAll('[data-code-session-rename-menu="' + sessionId + '"]').forEach((menu) => {
                                menu.hidden = false;
                            });
                        }
                    }
                if (payload.status === 'success') {
                    if (providerLive && payload.provider) {
                        const badge = providerLive.querySelector('[data-code-provider-badge]');
                        const summary = providerLive.querySelector('[data-code-provider-summary]');
                        if (badge) badge.textContent = String(payload.provider).toUpperCase();
                        if (summary) summary.textContent = (payload.model ? '· ' + payload.model + ' ' : '') + '· stato online';
                    }
                    if (payload.proposal && assistant) {
                        if (!hasOperationCard('patch', payload.proposal.operation_id)) {
                            const patchCard = buildPatchCard(payload.proposal);
                            patchCard._codeOrigin = assistant.article;
                            (operationDock || assistant.article).appendChild(patchCard);
                            log.scrollTop = log.scrollHeight;
                        }
                    }
                    if (payload.command && assistant) {
                        if (!hasOperationCard('command', payload.command.command_id)) {
                            const commandCard = buildCommandCard(payload.command);
                            commandCard._codeOrigin = assistant.article;
                            (operationDock || assistant.article).appendChild(commandCard);
                            // La card nasce fuori dal flusso del log. Misurala dopo il layout e forza il
                            // fondo: durante l'SSE una differenza di pochi pixel rende inaffidabile la
                            // normale euristica `atBottom` e lascia il messaggio sotto l'isola.
                            window.requestAnimationFrame(() => syncDockSpace(true));
                        }
                    }
                    if (payload.process && assistant) {
                        if (!hasOperationCard('process', payload.process.process_id)) {
                            const processCard = buildProcessCard(payload.process);
                            assistant.article.appendChild(processCard);
                            log.scrollTop = log.scrollHeight;
                        }
                    }
                    if (payload.git_stage && assistant) {
                        if (!hasOperationCard('git', payload.git_stage.operation_id)) {
                            assistant.article.appendChild(buildGitCard(payload.git_stage));
                            log.scrollTop = log.scrollHeight;
                        }
                    }
                    if (fileInput) fileInput.value = '';
                    renderSelectedFiles();
                    setState('success');
                } else {
                    if (providerLive && payload.provider) {
                        const badge = providerLive.querySelector('[data-code-provider-badge]');
                        const summary = providerLive.querySelector('[data-code-provider-summary]');
                        if (badge) badge.textContent = String(payload.provider).toUpperCase();
                        if (summary) summary.textContent = (payload.model ? '· ' + payload.model + ' ' : '') + '· stato errore';
                    }
                    const cancelled = payload.status === 'cancelled';
                    turnMessage(
                        cancelled ? 'Richiesta interrotta.' : (payload.message || 'Richiesta non riuscita.'),
                        cancelled ? 'cancelled' : 'error'
                    );
                    if (!cancelled && !input.value) input.value = prompt;
                    setState(cancelled ? 'cancelled' : 'error');
                }
            }
        }

        try {
            const response = await fetch('/code/chat', { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'text/event-stream' }, signal: controller.signal });
            if (!response.body) throw new Error('Stream non disponibile.');
            const reader = response.body.getReader(); const decoder = new TextDecoder(); let buffer = '';
            while (true) {
                const chunk = await reader.read();
                if (chunk.done) break;
                buffer += decoder.decode(chunk.value, { stream: true }).replace(/\r\n/g, '\n');
                let separator;
                while ((separator = buffer.indexOf('\n\n')) !== -1) { eventBlock(buffer.slice(0, separator)); buffer = buffer.slice(separator + 2); }
            }
            if (buffer.trim()) eventBlock(buffer.trim());
            if (!completed && !controller.signal.aborted) throw new Error('La connessione si è chiusa prima del completamento.');
        } catch (error) {
            const aborted = error && (error.name === 'AbortError' || controller.signal.aborted);
            turnMessage(
                aborted ? 'Richiesta interrotta.' : (error.message || 'Richiesta non riuscita.'),
                aborted ? 'cancelled' : 'error'
            );
            if (!aborted && !input.value) input.value = prompt;
            setState(aborted ? 'cancelled' : 'error');
        } finally {
            if (activeRequestId === id) {
                activeRequestId = null; activeController = null;
                window.setTimeout(() => { if (!activeRequestId) { setState('idle'); input.focus(); } }, 250);
            }
        }
    });
    actionBtn?.addEventListener('click', async (event) => {
        if (activeCommandCard) {
            event.preventDefault();
            await commandRequest('/code/command/stop', activeCommandCard);
            commandMessage(activeCommandCard, 'Interruzione richiesta…');
            return;
        }
        if (activeRequestId) {
            event.preventDefault();
            await stopActive();
        }
    });
    input.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') { event.preventDefault(); form.requestSubmit(); }
    });
})();
