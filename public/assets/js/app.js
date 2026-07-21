(function () {
    const savedTheme = localStorage.getItem('aimanager-theme') || 'dark';
    if (savedTheme) {
        document.documentElement.dataset.theme = savedTheme;
    }

    document.querySelector('[data-theme-toggle]')?.addEventListener('click', function () {
        const themes = ['light', 'dark', 'blue'];
        const current = document.documentElement.dataset.theme || 'dark';
        const next = themes[(themes.indexOf(current) + 1) % themes.length] || 'dark';
        document.documentElement.dataset.theme = next;
        localStorage.setItem('aimanager-theme', next);
    });

    const themeSetting = document.querySelector('[data-theme-setting]');
    if (themeSetting) {
        const allowedThemes = ['light', 'dark', 'blue'];
        const applyThemeSetting = function () {
            const next = allowedThemes.includes(themeSetting.value) ? themeSetting.value : 'dark';
            document.documentElement.dataset.theme = next;
            localStorage.setItem('aimanager-theme', next);
        };

        applyThemeSetting();
        themeSetting.addEventListener('change', function () {
            applyThemeSetting();
            themeSetting.form?.requestSubmit();
        });
    }

    ['dragover', 'drop'].forEach(function (eventName) {
        document.addEventListener(eventName, function (event) {
            const hasFiles = event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files.length > 0;
            const handledDropZone = event.target.closest('[data-provider-drop-form]');
            if (hasFiles && !handledDropZone) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.dataset.confirm || 'Confermi?')) {
                event.preventDefault();
            }
        });
    });

    document.querySelector('form[data-terminal]')?.addEventListener('submit', async function (event) {
        event.preventDefault();
        const form = event.currentTarget;
        const button = form.querySelector('button');
        const original = button ? button.textContent : '';
        if (button) {
            button.disabled = true;
            button.textContent = 'Apertura...';
        }
        try {
            const response = await fetch('/system/terminal', {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'fetch' }
            });
            const result = await response.json();
            if (!result.ok) {
                window.alert(result.message || 'Impossibile aprire il terminale.');
            }
        } catch (error) {
            window.alert('Impossibile aprire il terminale.');
        } finally {
            if (button) {
                button.disabled = false;
                button.textContent = original;
            }
        }
    });

    // Conferma dell'arresto con <dialog> nativo, stesso componente della revoca cartella Code.
    // Risolve `true` SOLO su conferma esplicita: Annulla ed Escape lasciano returnValue diverso.
    const shutdownDialog = document.querySelector('[data-shutdown-dialog]');
    function confirmShutdown() {
        // Senza <dialog> nativo si ricade sul confirm del browser: mai un arresto non confermato.
        if (!shutdownDialog || typeof shutdownDialog.showModal !== 'function') {
            return Promise.resolve(window.confirm('Fermare AIManager? Il server locale verrà chiuso e la pagina non funzionerà finché non lo riavvii.'));
        }
        return new Promise(function (resolve) {
            // `close()` senza argomento NON azzera returnValue: va ripulito a ogni apertura,
            // altrimenti un Escape dopo una conferma precedente fermerebbe il server da solo.
            shutdownDialog.returnValue = '';
            shutdownDialog.addEventListener('close', function () {
                resolve(shutdownDialog.returnValue === 'confirm');
            }, { once: true });
            shutdownDialog.showModal();
        });
    }

    document.querySelector('form[data-shutdown]')?.addEventListener('submit', async function (event) {
        event.preventDefault();
        // `currentTarget` vale solo durante il dispatch: va letto PRIMA di attendere la conferma,
        // altrimenti dopo l'await sarebbe null e il flusso di arresto si romperebbe.
        const form = event.currentTarget;
        if (!(await confirmShutdown())) {
            return;
        }
        const button = form.querySelector('button');
        if (button) {
            button.disabled = true;
            button.textContent = 'Arresto in corso...';
        }
        let shutdownResult = null;
        try {
            const response = await fetch('/system/stop', {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'fetch' }
            });
            shutdownResult = await response.json();
            if (!shutdownResult.ok) {
                window.alert(shutdownResult.message || 'Impossibile fermare il server.');
                if (button) {
                    button.disabled = false;
                    button.textContent = 'Ferma AIManager';
                }
                return;
            }
        } catch (error) {
            // Il server puo' chiudersi mentre risponde: trattalo comunque come arresto riuscito.
        }
        const overlay = document.createElement('div');
        overlay.className = 'shutdown-overlay';
        overlay.innerHTML = '<div class="shutdown-card">'
            + '<h2>AIManager fermato</h2>'
            + '<p>Il server locale è stato chiuso. Puoi chiudere questa scheda.</p>'
            + '<p class="shutdown-hint">Per riaprirlo: doppio click su AIManager sul Desktop.</p>'
            + '</div>';
        const manualInstructions = shutdownResult?.process_cleanup?.manual_instructions || '';
        if (manualInstructions) {
            const warning = document.createElement('pre');
            warning.className = 'shutdown-process-warning';
            warning.textContent = manualInstructions;
            overlay.querySelector('.shutdown-card')?.appendChild(warning);
        }
        document.body.appendChild(overlay);
    });

    document.querySelectorAll('[data-provider-test]').forEach(function (button) {
        button.addEventListener('click', async function () {
            const form = button.closest('[data-provider-form]');
            const output = form.querySelector('.test-output');
            const data = new FormData(form);
            button.disabled = true;
            output.textContent = 'Test in corso...';

            try {
                const response = await fetch('/providers/test', {
                    method: 'POST',
                    body: data,
                    headers: { 'X-Requested-With': 'fetch' }
                });
                const result = await response.json();
                output.textContent = result.message || (result.ok ? 'OK' : 'Errore');
                output.style.color = result.ok ? 'var(--primary)' : 'var(--danger)';
            } catch (error) {
                output.textContent = 'Test non riuscito.';
                output.style.color = 'var(--danger)';
            } finally {
                button.disabled = false;
            }
        });
    });

    document.querySelectorAll('[data-web-provider-test]').forEach(function (button) {
        button.addEventListener('click', async function () {
            const form = button.closest('[data-web-provider-form]');
            const output = form.querySelector('.test-output');
            const data = new FormData(form);
            button.disabled = true;
            output.textContent = 'Ricerca in corso...';
            output.style.color = 'var(--muted)';

            try {
                const response = await fetch('/providers/web/test', {
                    method: 'POST',
                    body: data,
                    headers: { 'X-Requested-With': 'fetch' }
                });
                const result = await response.json();
                output.textContent = result.message || (result.ok ? 'OK' : 'Errore');
                output.style.color = result.ok ? 'var(--primary)' : 'var(--danger)';
            } catch (error) {
                output.textContent = 'Test ricerca non riuscito.';
                output.style.color = 'var(--danger)';
            } finally {
                button.disabled = false;
            }
        });
    });

    document.querySelectorAll('[data-image-provider-test]').forEach(function (button) {
        button.addEventListener('click', async function () {
            const form = button.closest('[data-image-provider-form]');
            const output = form.querySelector('.test-output');
            const data = new FormData(form);
            button.disabled = true;
            output.textContent = 'Generazione in corso...';
            output.style.color = 'var(--muted)';

            try {
                const response = await fetch('/providers/image/test', {
                    method: 'POST',
                    body: data,
                    headers: { 'X-Requested-With': 'fetch' }
                });
                const result = await response.json();
                output.textContent = result.message || (result.ok ? 'OK' : 'Errore');
                output.style.color = result.ok ? 'var(--primary)' : 'var(--danger)';
            } catch (error) {
                output.textContent = 'Test generazione non riuscito.';
                output.style.color = 'var(--danger)';
            } finally {
                button.disabled = false;
            }
        });
    });

    document.querySelectorAll('[data-secret-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            const input = button.closest('label')?.querySelector('[data-secret-input]');
            if (!input) {
                return;
            }

            const showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            button.textContent = showing ? 'Mostra' : 'Nascondi';
        });
    });

    document.querySelectorAll('[data-provider-models]').forEach(function (button) {
        button.addEventListener('click', async function () {
            const form = button.closest('[data-provider-form]');
            const provider = form?.querySelector('input[name="provider"]')?.value || '';
            const datalist = form?.querySelector('datalist');
            const output = form?.querySelector('.test-output');
            if (!provider || !datalist) {
                return;
            }

            button.disabled = true;
            if (output) {
                output.textContent = 'Recupero modelli...';
                output.style.color = 'var(--muted)';
            }

            try {
                const response = await fetch(`/providers/models?provider=${encodeURIComponent(provider)}`);
                const result = await response.json();
                datalist.innerHTML = (result.models || []).map(function (model) {
                    return `<option value="${String(model).replace(/[&<>"']/g, function (char) {
                        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
                    })}"></option>`;
                }).join('');
                if (output) {
                    output.textContent = result.models?.length ? `${result.models.length} modelli disponibili.` : 'Nessun modello recuperato.';
                }
            } catch (error) {
                if (output) {
                    output.textContent = 'Recupero modelli non riuscito.';
                    output.style.color = 'var(--danger)';
                }
            } finally {
                button.disabled = false;
            }
        });
    });

    document.querySelectorAll('[data-provider-drop-form]').forEach(function (form) {
        const drop = form.querySelector('[data-provider-drop]');
        const input = form.querySelector('input[type="file"]');
        const picker = form.querySelector('[data-provider-file-button]');
        if (!drop || !input) {
            return;
        }

        picker?.addEventListener('click', function () {
            input.click();
        });

        input.addEventListener('change', function () {
            if (input.files.length > 0) {
                form.submit();
            }
        });

        ['dragenter', 'dragover'].forEach(function (name) {
            drop.addEventListener(name, function (event) {
                event.preventDefault();
                drop.classList.add('is-dragging');
            });
        });

        ['dragleave', 'drop'].forEach(function (name) {
            drop.addEventListener(name, function (event) {
                event.preventDefault();
                drop.classList.remove('is-dragging');
            });
        });

        drop.addEventListener('drop', function (event) {
            if (!event.dataTransfer || event.dataTransfer.files.length === 0) {
                return;
            }

            input.files = event.dataTransfer.files;
            form.submit();
        });
    });

    const chatScroll = document.querySelector('[data-chat-scroll]');
    if (chatScroll) {
        chatScroll.scrollTop = chatScroll.scrollHeight;
    }

    // Renderer condiviso con la chat Code: markdown.js è caricato prima di questo file.
    const escapeHtml = window.AIManagerMarkdown.escapeHtml;
    const renderMarkdown = window.AIManagerMarkdown.render;

    const formatDate = function (value) {
        const date = value ? new Date(value) : new Date();
        if (Number.isNaN(date.getTime())) {
            return '';
        }

        return date.toLocaleString('it-IT', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        }).replace(',', '');
    };

    const chatBottomDistance = function (messages) {
        return messages.scrollHeight - messages.scrollTop - messages.clientHeight;
    };

    const isNearChatBottom = function (messages) {
        return chatBottomDistance(messages) < 80;
    };

    const isAtChatBottom = function (messages) {
        return chatBottomDistance(messages) < 6;
    };

    const scrollChat = function (messages, force) {
        if (!force && !isNearChatBottom(messages)) {
            return;
        }

        messages.scrollTop = messages.scrollHeight;
    };

    const provisionalTitle = function (title) {
        return ['chat libera', 'nuova conversazione'].includes(String(title || '').trim().toLowerCase());
    };

    const promptTitle = function (prompt) {
        const stopWords = new Set([
            'ciao', 'salve', 'buongiorno', 'buonasera', 'hey', 'mi', 'puoi', 'potresti',
            'per', 'favore', 'aiuti', 'aiutami', 'vorrei', 'voglio', 'fare', 'fammi',
            'dimmi', 'spiegami', 'scrivimi', 'una', 'uno', 'un', 'il', 'lo', 'la',
            'gli', 'le', 'di', 'del', 'della', 'dei', 'degli', 'delle', 'che',
            'cosa', 'come', 'sono', 'sei'
        ]);
        const greetings = new Set(['ciao', 'salve', 'buongiorno', 'buonasera', 'hey']);
        const words = String(prompt).replace(/[^\p{L}\p{N}\s-]+/gu, ' ').trim().split(/\s+/);
        let selected = words.filter(function (word) {
            word = word.trim();
            return word.length >= 3 && !stopWords.has(word.toLowerCase());
        }).slice(0, 5);
        if (selected.length === 0) {
            selected = words.filter(function (word) {
                word = word.trim();
                return word !== '' && !greetings.has(word.toLowerCase());
            }).slice(0, 5);
        }

        const title = selected.join(' ').slice(0, 42).trim();
        return title ? title.charAt(0).toUpperCase() + title.slice(1) : 'Nuova conversazione';
    };

    const updateFreeChatTitle = function (sessionId, title) {
        if (!sessionId || !title) {
            return;
        }

        const link = document.querySelector(`[data-free-chat-session="${sessionId}"]`);
        if (link) {
            link.textContent = title;
        }
    };

    const requestId = function () {
        if (window.crypto?.randomUUID) {
            return window.crypto.randomUUID();
        }

        return `req_${Date.now()}_${Math.random().toString(16).slice(2)}`;
    };

    const delay = function (ms) {
        return new Promise(function (resolve) {
            setTimeout(resolve, ms);
        });
    };

    const stopChatRequest = function (form) {
        const id = form._chatRequestId || '';
        const csrf = form.querySelector('input[name="_csrf"]')?.value || '';
        if (!id || !csrf) {
            return Promise.resolve();
        }

        const data = new FormData();
        data.append('_csrf', csrf);
        data.append('request_id', id);

        return fetch('/workspace/chat/stop', {
            method: 'POST',
            body: data,
            headers: { 'X-Requested-With': 'fetch' }
        }).catch(function () {});
    };

    const fileIcon = function (name) {
        const ext = (String(name).split('.').pop() || '').toLowerCase();
        if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'heic'].includes(ext)) return '🖼️';
        if (ext === 'pdf') return '📕';
        if (['doc', 'docx'].includes(ext)) return '📘';
        if (['xls', 'xlsx', 'csv'].includes(ext)) return '📗';
        return '📄';
    };

    const fileChipHtml = function (name) {
        return `<span class="chat-file-card"><span class="chat-file-card__icon" aria-hidden="true">${fileIcon(name)}</span><span class="chat-file-card__name">${escapeHtml(name)}</span></span>`;
    };

    const messageMarkup = function (role, content, createdAt, meta, attachments) {
        const label = role === 'user' ? 'Tu' : 'AI';
        const safeContent = role === 'assistant' ? renderMarkdown(content) : escapeHtml(content).replace(/\n/g, '<br>');
        const metaLine = meta && role === 'assistant'
            ? `<span class="chat-meta">${escapeHtml(meta)}</span>`
            : '';
        const files = Array.isArray(attachments) ? attachments : [];
        const filesLine = files.length
            ? `<div class="chat-bubble-files">${files.map(fileChipHtml).join('')}</div>`
            : '';

        return `
            <article class="chat-message ${role}">
                <div class="chat-bubble">
                    <small>${label} · ${escapeHtml(formatDate(createdAt))}</small>
                    ${filesLine}
                    <div class="chat-content">${safeContent}</div>
                    ${metaLine}
                </div>
            </article>
        `;
    };

    const thinkingMarkup = function () {
        return `
            <article class="chat-message assistant thinking-message" data-thinking-message>
                <div class="chat-bubble thinking-bubble">
                    <span class="thinking-mark" role="status" aria-label="AI sta elaborando">
                        <span class="thinking-shape shape-a"></span>
                        <span class="thinking-shape shape-b"></span>
                        <span class="thinking-shape shape-c"></span>
                    </span>
                </div>
            </article>
        `;
    };

    const streamingAssistantMarkup = function () {
        return `
            <article class="chat-message assistant" data-streaming-message>
                <div class="chat-bubble">
                    <small>AI · ${escapeHtml(formatDate(new Date().toISOString()))}</small>
                    <details class="chat-reasoning" data-streaming-reasoning hidden>
                        <summary>🧠 Ragionamento</summary>
                        <div class="chat-reasoning-body" data-streaming-reasoning-body></div>
                    </details>
                    <details class="chat-sources" data-streaming-sources hidden>
                        <summary>🌐 Fonti web</summary>
                        <ul class="chat-sources-body" data-streaming-sources-body></ul>
                    </details>
                    <div class="chat-images" data-streaming-images hidden></div>
                    <div class="chat-content" data-streaming-content></div>
                    <span class="chat-meta" data-streaming-meta></span>
                </div>
            </article>
        `;
    };

    const updateProviderLive = function (assistant) {
        if (!assistant || !assistant.provider) {
            return;
        }

        document.querySelectorAll('[data-provider-badge]').forEach(function (badge) {
            badge.textContent = assistant.provider.toUpperCase();
        });

        document.querySelectorAll('[data-provider-live]').forEach(function (panel) {
            const summary = panel.querySelector('[data-provider-summary]');
            if (summary) {
                summary.textContent = `${assistant.model} · stato online`;
            }
        });
    };

    document.addEventListener('click', function (event) {
        const chip = event.target.closest('[data-suggestion]');
        if (!chip) {
            return;
        }

        const textarea = document.querySelector('form.chat-compose textarea[name="prompt"]');
        if (!textarea) {
            return;
        }

        textarea.value = chip.getAttribute('data-suggestion') || '';
        textarea.focus();
        textarea.setSelectionRange(textarea.value.length, textarea.value.length);
    });

    document.querySelectorAll('form.chat-compose').forEach(function (form) {
        const fileInput = form.querySelector('[data-chat-files]');
        const attachButton = form.querySelector('[data-chat-attach]');
        const attachmentList = form.querySelector('[data-chat-attachments]');

        const MAX_ATTACHMENT_BYTES = 52428800; // 50 MB, allineato al limite del server
        const ALLOWED_EXTENSIONS = ['txt', 'md', 'csv', 'json', 'xml', 'html', 'css', 'js', 'ts', 'php', 'py', 'sql', 'log', 'yml', 'yaml', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'webp', 'gif'];

        // Motivo per cui un file verrebbe scartato dal backend (dimensione o tipo), allineato
        // a ChatAttachmentService::valid(). Stringa vuota = accettato. Serve a non mostrare come
        // allegato cio' che il server scarterebbe, avvisando invece subito l'utente.
        const attachmentRejectReason = function (file) {
            if (file.size <= 0) {
                return 'file vuoto';
            }
            if (file.size > MAX_ATTACHMENT_BYTES) {
                return 'hai superato il limite di 50 MB';
            }
            const ext = (file.name.split('.').pop() || '').toLowerCase();
            if (!ALLOWED_EXTENSIONS.includes(ext)) {
                return 'tipo di file non supportato';
            }
            return '';
        };

        const renderAttachments = function () {
            if (!fileInput || !attachmentList) {
                return;
            }

            const all = Array.from(fileInput.files || []);
            const rejected = all.filter((file) => attachmentRejectReason(file) !== '');

            // File non validi (troppo grandi o tipo non ammesso): toglili dall'input, cosi'
            // non vengono inviati ne' mostrati come allegati. L'utente viene avvisato.
            if (rejected.length > 0) {
                const kept = new DataTransfer();
                all.filter((file) => attachmentRejectReason(file) === '').forEach((file) => kept.items.add(file));
                fileInput.files = kept.files;
            }

            const chips = Array.from(fileInput.files || []).map(function (file) {
                const isImage = (file.type || '').startsWith('image/');
                const preview = isImage
                    ? `<img class="compose-file__thumb" src="${URL.createObjectURL(file)}" alt="">`
                    : `<span class="compose-file__icon" aria-hidden="true">${fileIcon(file.name)}</span>`;
                return `<span class="compose-file">${preview}<span class="compose-file__name">${escapeHtml(file.name)}</span></span>`;
            }).join('');

            const warnings = rejected.map(function (file) {
                return `<span class="compose-file compose-file--rejected">${escapeHtml(file.name)}: ${attachmentRejectReason(file)}, file non allegato</span>`;
            }).join('');

            attachmentList.innerHTML = chips + warnings;
        };

        attachButton?.addEventListener('click', function () {
            fileInput?.click();
        });

        fileInput?.addEventListener('change', renderAttachments);

        form.addEventListener('dragover', function (event) {
            if (event.dataTransfer && event.dataTransfer.files.length > 0) {
                event.preventDefault();
                form.classList.add('is-dragging');
            }
        });

        form.addEventListener('dragleave', function () {
            form.classList.remove('is-dragging');
        });

        form.addEventListener('drop', function (event) {
            if (!fileInput || !event.dataTransfer || event.dataTransfer.files.length === 0) {
                return;
            }

            event.preventDefault();
            form.classList.remove('is-dragging');
            fileInput.files = event.dataTransfer.files;
            renderAttachments();
        });

        form.addEventListener('submit', async function (event) {
            if (form.classList.contains('is-thinking')) {
                event.preventDefault();
                stopChatRequest(form);
                form._chatAbortController?.abort();
                return;
            }

            const messages = form.closest('.chat-shell')?.querySelector('[data-chat-scroll]');
            const textarea = form.querySelector('textarea[name="prompt"]');
            const button = form.querySelector('button[type="submit"]');
            const sessionId = form.querySelector('input[name="session_id"]')?.value || '';
            const prompt = textarea?.value.trim() || '';
            if (!messages || !textarea || prompt === '') {
                return;
            }

            event.preventDefault();

            const currentFreeChat = document.querySelector(`[data-free-chat-session="${sessionId}"]`);
            if (currentFreeChat && provisionalTitle(currentFreeChat.textContent)) {
                updateFreeChatTitle(sessionId, promptTitle(prompt));
            }

            messages.querySelector('.chat-empty')?.remove();
            const selectedFiles = Array.from(fileInput?.files || []).map((file) => file.name);
            // Allegati come schede-file con icona DENTRO la bolla (non piu' come testo
            // "Allegati: ..."). Le chip pendenti sotto il compose restano l'anteprima
            // di cosa stai per inviare.
            messages.insertAdjacentHTML('beforeend', messageMarkup('user', prompt, new Date().toISOString(), null, selectedFiles));
            const myUserBubble = messages.lastElementChild;
            // Riconcilia gli allegati della bolla utente con quelli DAVVERO accettati dal server
            // (payload.attachments): se il backend ne ha scartato qualcuno (MIME reale non valido,
            // file vuoto, ...), toglilo dalla bolla e avvisa. Va chiamata sia su 'done' sia su
            // 'error', cosi' vale anche se il provider fallisce dopo lo scarto (audit).
            const reconcileBubbleAttachments = function (payload) {
                if (!myUserBubble || !selectedFiles.length || !payload || !Array.isArray(payload.attachments)) {
                    return;
                }
                const accepted = payload.attachments.map((item) => item.name);
                if (accepted.length >= selectedFiles.length) {
                    return;
                }
                const filesDiv = myUserBubble.querySelector('.chat-bubble-files');
                if (!filesDiv) {
                    return;
                }
                const rejected = selectedFiles.filter((n) => !accepted.includes(n));
                const chips = accepted.map(fileChipHtml).join('');
                const note = rejected.length
                    ? `<span class="chat-file-card chat-file-card--rejected">${escapeHtml(rejected.join(', '))}: non accettato dal server</span>`
                    : '';
                filesDiv.innerHTML = chips + note;
            };
            messages.insertAdjacentHTML('beforeend', thinkingMarkup());
            // Riferimento alla NOSTRA bolla in attesa: senza questo, se un lavoro in
            // background sta gia' rendendo la sua risposta, ensureAssistant prenderebbe
            // "la prima" bolla in attesa (quella del job) e mescolerebbe le risposte.
            const myThinking = messages.lastElementChild;
            scrollChat(messages, true);

            const data = new FormData(form);
            const id = requestId();
            data.append('request_id', id);
            const abortController = new AbortController();
            form._chatAbortController = abortController;
            form._chatRequestId = id;
            form.classList.add('is-thinking');
            button.setAttribute('aria-label', 'Interrompi elaborazione');
            textarea.value = '';
            let handleSpaceFlush = null;
            let handleStreamingScroll = null;

            try {
                const response = await fetch('/workspace/chat/stream', {
                    method: 'POST',
                    body: data,
                    headers: { 'X-Requested-With': 'fetch' },
                    signal: abortController.signal
                });
                const reader = response.body?.getReader();
                const decoder = new TextDecoder();
                let buffer = '';
                let streamedContent = '';
                let streamedReasoning = '';
                let assistantNode = null;
                let assistantText = null;
                let assistantMeta = null;
                let assistantReasoning = null;
                let assistantReasoningBody = null;
                let assistantSources = null;
                let assistantSourcesBody = null;
                let assistantImages = null;
                let finalResult = null;
                let shouldStickToBottom = true;
                let userDetachedFromStream = false;
                let lastStreamScrollTop = messages.scrollTop;
                let pendingContent = '';
                let typewriterRunning = false;
                let typewriterIdleResolve = null;

                const rememberScrollIntent = function () {
                    if (userDetachedFromStream && !isAtChatBottom(messages)) {
                        shouldStickToBottom = false;
                        return;
                    }

                    shouldStickToBottom = isNearChatBottom(messages);
                };

                const keepChatReadable = function (force) {
                    if (force || shouldStickToBottom) {
                        scrollChat(messages, true);
                    }
                };

                handleStreamingScroll = function () {
                    const distance = chatBottomDistance(messages);
                    const movedUp = messages.scrollTop < lastStreamScrollTop - 2;
                    lastStreamScrollTop = messages.scrollTop;

                    if (movedUp) {
                        userDetachedFromStream = true;
                        shouldStickToBottom = false;
                        return;
                    }

                    if (distance < 6) {
                        userDetachedFromStream = false;
                        shouldStickToBottom = true;
                    }
                };

                messages.addEventListener('scroll', handleStreamingScroll, { passive: true });

                const renderStreamedContent = function () {
                    if (assistantText) {
                        assistantText.innerHTML = renderMarkdown(streamedContent);
                    }
                    keepChatReadable();
                };

                const resolveTypewriterIdle = function () {
                    if (typewriterIdleResolve) {
                        typewriterIdleResolve();
                        typewriterIdleResolve = null;
                    }
                };

                const waitForTypewriterIdle = function () {
                    if (!typewriterRunning && pendingContent === '') {
                        return Promise.resolve();
                    }

                    return new Promise(function (resolve) {
                        typewriterIdleResolve = resolve;
                    });
                };

                const typeQueuedContent = async function () {
                    if (typewriterRunning) {
                        return;
                    }

                    typewriterRunning = true;
                    while (pendingContent !== '') {
                        const next = pendingContent.slice(0, 2);
                        pendingContent = pendingContent.slice(next.length);
                        streamedContent += next;
                        renderStreamedContent();
                        await delay(42);
                    }
                    typewriterRunning = false;
                    resolveTypewriterIdle();
                };

                const queueStreamText = function (text) {
                    const value = String(text || '');
                    if (value === '') {
                        return;
                    }

                    pendingContent += value;
                    typeQueuedContent();
                };

                const flushQueuedContent = function () {
                    if (pendingContent === '') {
                        return;
                    }

                    streamedContent += pendingContent;
                    pendingContent = '';
                    renderStreamedContent();
                    resolveTypewriterIdle();
                };

                handleSpaceFlush = function (keyboardEvent) {
                    if (!form.classList.contains('is-thinking') || pendingContent === '' || keyboardEvent.code !== 'Space' || keyboardEvent.metaKey || keyboardEvent.ctrlKey || keyboardEvent.altKey) {
                        return;
                    }

                    keyboardEvent.preventDefault();
                    flushQueuedContent();
                };

                document.addEventListener('keydown', handleSpaceFlush);

                const ensureAssistant = function () {
                    if (assistantNode) {
                        return;
                    }

                    // Declassa eventuali bolle di streaming rimaste dai turni precedenti, cosi'
                    // questo turno non le riusa/sovrascrive: diventano messaggi statici definitivi.
                    messages.querySelectorAll('[data-streaming-message]').forEach(function (node) {
                        node.removeAttribute('data-streaming-message');
                    });

                    // Converte LA NOSTRA bolla in attesa (non "la prima"): cosi' non
                    // rubiamo la bolla di un lavoro in background che sta rispondendo.
                    const thinking = (myThinking && myThinking.isConnected && myThinking.hasAttribute('data-thinking-message'))
                        ? myThinking
                        : null;
                    if (thinking) {
                        thinking.insertAdjacentHTML('beforebegin', streamingAssistantMarkup());
                        assistantNode = thinking.previousElementSibling;
                        thinking.remove();
                    } else {
                        messages.insertAdjacentHTML('beforeend', streamingAssistantMarkup());
                        const streamingNodes = messages.querySelectorAll('[data-streaming-message]');
                        assistantNode = streamingNodes[streamingNodes.length - 1] || null;
                    }
                    assistantText = assistantNode?.querySelector('[data-streaming-content]');
                    assistantMeta = assistantNode?.querySelector('[data-streaming-meta]');
                    assistantReasoning = assistantNode?.querySelector('[data-streaming-reasoning]');
                    assistantReasoningBody = assistantNode?.querySelector('[data-streaming-reasoning-body]');
                    assistantSources = assistantNode?.querySelector('[data-streaming-sources]');
                    assistantSourcesBody = assistantNode?.querySelector('[data-streaming-sources-body]');
                    assistantImages = assistantNode?.querySelector('[data-streaming-images]');
                };

                const handleEvent = async function (name, payload) {
                    if (name === 'reset') {
                        // Un provider ha fallito dopo aver mostrato testo parziale (es. stream
                        // Gemini troncato): azzera il parziale, il provider di fallback riparte pulito.
                        streamedContent = '';
                        pendingContent = '';
                        streamedReasoning = '';
                        if (assistantText) {
                            assistantText.innerHTML = '';
                        }
                        if (assistantReasoningBody) {
                            assistantReasoningBody.textContent = '';
                        }
                        if (assistantReasoning) {
                            assistantReasoning.hidden = true;
                        }
                        return;
                    }

                    if (name === 'sources') {
                        rememberScrollIntent();
                        ensureAssistant();
                        const results = Array.isArray(payload.results) ? payload.results : [];
                        const sourceError = String(payload.error || '');
                        if ((results.length || sourceError) && assistantSources && assistantSourcesBody) {
                            const links = results.map(function (item) {
                                const url = String(item.url || '');
                                const title = String(item.title || url) || 'Fonte';
                                if (!url) {
                                    return `<li>${escapeHtml(title)}</li>`;
                                }
                                return `<li><a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(title)}</a></li>`;
                            }).join('');
                            const errorItem = sourceError
                                ? `<li class="chat-sources-error">Ricerca web non riuscita: ${escapeHtml(sourceError)}</li>`
                                : '';
                            assistantSourcesBody.innerHTML = links + errorItem;
                            assistantSources.hidden = false;
                            assistantSources.open = true;
                        }
                        keepChatReadable();
                        return;
                    }

                    if (name === 'image') {
                        rememberScrollIntent();
                        ensureAssistant();
                        const url = String(payload.url || '');
                        if (url && assistantImages) {
                            const alt = escapeHtml(String(payload.prompt || 'immagine generata'));
                            const downloadUrl = `${url}${url.includes('?') ? '&' : '?'}download=1`;
                            assistantImages.insertAdjacentHTML('beforeend', `<figure class="chat-image"><img src="${escapeHtml(url)}" alt="${alt}" loading="lazy"><a class="chat-image-download" href="${escapeHtml(downloadUrl)}" download>Salva immagine</a></figure>`);
                            assistantImages.hidden = false;
                        }
                        keepChatReadable();
                        return;
                    }

                    if (name === 'reasoning') {
                        rememberScrollIntent();
                        ensureAssistant();
                        streamedReasoning += payload.text || '';
                        if (assistantReasoning && assistantReasoningBody) {
                            assistantReasoning.hidden = false;
                            assistantReasoning.open = true;
                            assistantReasoningBody.textContent = streamedReasoning;
                        }
                        keepChatReadable();
                        return;
                    }

                    if (name === 'delta') {
                        rememberScrollIntent();
                        ensureAssistant();
                        if (assistantReasoning && streamedReasoning !== '') {
                            assistantReasoning.open = false;
                        }
                        if (assistantSources && !assistantSources.hidden) {
                            assistantSources.open = false;
                        }
                        queueStreamText(payload.text || '');
                        await delay(0);
                        return;
                    }

                    if (name === 'done') {
                        finalResult = payload;
                        const assistant = payload.assistant || null;
                        if (payload.session_title) {
                            updateFreeChatTitle(sessionId, payload.session_title);
                        }
                        if (assistant) {
                            rememberScrollIntent();
                            ensureAssistant();
                            const knownContent = streamedContent + pendingContent;
                            if (assistant.content && assistant.content !== knownContent) {
                                if (assistant.content.startsWith(knownContent)) {
                                    queueStreamText(assistant.content.slice(knownContent.length));
                                } else {
                                    streamedContent = '';
                                    pendingContent = '';
                                    queueStreamText(assistant.content);
                                }
                                await delay(0);
                            }
                            if (assistantMeta && assistant.provider) {
                                assistantMeta.textContent = `${assistant.provider} · ${assistant.model} · ${assistant.tokens_input} / ${assistant.tokens_output} token`;
                            }
                            updateProviderLive(assistant);
                            keepChatReadable();
                        }
                        reconcileBubbleAttachments(payload);
                        return;
                    }

                    if (name === 'error') {
                        finalResult = payload;
                        reconcileBubbleAttachments(payload);
                    }
                };

                const parseEvents = async function () {
                    const parts = buffer.split('\n\n');
                    buffer = parts.pop() || '';
                    for (const part of parts) {
                        let name = 'message';
                        let data = '';
                        part.split('\n').forEach(function (line) {
                            if (line.startsWith('event:')) {
                                name = line.slice(6).trim();
                            } else if (line.startsWith('data:')) {
                                data += line.slice(5).trim();
                            }
                        });
                        if (data !== '') {
                            try {
                                await handleEvent(name, JSON.parse(data));
                            } catch (error) {
                                await handleEvent('error', { message: 'Risposta streaming non valida.' });
                            }
                        }
                    }
                };

                if (!reader) {
                    throw new Error('Streaming non supportato dal browser.');
                }

                while (true) {
                    const read = await reader.read();
                    if (read.done) {
                        break;
                    }
                    buffer += decoder.decode(read.value, { stream: true });
                    await parseEvents();
                }
                buffer += decoder.decode();
                await parseEvents();
                await waitForTypewriterIdle();

                if (!response.ok || (finalResult && finalResult.ok === false)) {
                    const thinking = messages.querySelector('[data-thinking-message]');
                    if (thinking) {
                        thinking.outerHTML = messageMarkup('assistant', finalResult?.message || 'Non sono riuscito a ottenere una risposta.', new Date().toISOString());
                    } else if (!assistantNode) {
                        messages.insertAdjacentHTML('beforeend', messageMarkup('assistant', finalResult?.message || 'Non sono riuscito a ottenere una risposta.', new Date().toISOString()));
                    }
                }
                keepChatReadable();
            } catch (error) {
                if (error.name === 'AbortError') {
                    messages.querySelector('[data-thinking-message]')?.remove();
                    scrollChat(messages);
                    return;
                }

                const thinking = messages.querySelector('[data-thinking-message]');
                if (thinking) {
                    thinking.outerHTML = messageMarkup('assistant', 'Non sono riuscito a ottenere una risposta.', new Date().toISOString());
                }
                scrollChat(messages);
            } finally {
                if (handleSpaceFlush) {
                    document.removeEventListener('keydown', handleSpaceFlush);
                }
                if (handleStreamingScroll) {
                    messages.removeEventListener('scroll', handleStreamingScroll);
                }
                form.classList.remove('is-thinking');
                form._chatAbortController = null;
                form._chatRequestId = null;
                button.setAttribute('aria-label', 'Invia messaggio');
                if (fileInput) {
                    fileInput.value = '';
                    renderAttachments();
                }
                textarea.focus();
            }
        });
    });


})();
