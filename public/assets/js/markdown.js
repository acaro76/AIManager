/**
 * Renderer Markdown condiviso fra la chat LLM (app.js) e la chat Code (code-chat.js).
 *
 * È il gemello client di View::messageContent()/inlineMarkdown() in app/Core/View.php: le due
 * implementazioni devono restare allineate, perché la stessa risposta viene resa da questo file
 * durante lo streaming e dal PHP dopo il refresh. Ogni modifica qui va replicata là (e viceversa);
 * tests/MarkdownRenderTest.php blocca l'equivalenza sui casi coperti.
 *
 * Sicurezza: l'escape precede qualunque markdown, quindi il testo del modello non può mai
 * introdurre HTML. I link sono ammessi solo con schema http/https, per costruzione: qualunque
 * altro schema non viene riconosciuto come link e resta testo letterale.
 */
(function () {
    'use strict';

    const escapeHtml = function (value) {
        return String(value).replace(/[&<>"']/g, function (char) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
        });
    };

    const linkDomain = function (url) {
        try {
            const host = new URL(url).hostname.replace(/^www\./i, '').toLowerCase();
            return host.endsWith('wikipedia.org') ? 'wikipedia' : host;
        } catch (error) {
            return 'web';
        }
    };

    const inlineEmphasis = function (html) {
        return html
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/(^|[^*])\*([^*]+)\*(?!\*)/g, '$1<em>$2</em>')
            .replace(/\[([^\]]+)\]\((https?:\/\/[^)\s<]+)\)/g, function (_match, label, url) {
                const visibleLabel = /^web:search_result:\d+$/i.test(label) ? `Fonte: ${linkDomain(url.replace(/&amp;/g, '&'))}` : label;
                return `<a href="${escapeHtml(url.replace(/&amp;/g, '&'))}" target="_blank" rel="noopener noreferrer">${escapeHtml(visibleLabel)}</a>`;
            });
    };

    const inlineMarkdown = function (value) {
        // I segmenti `code` sono isolati prima di enfasi e link, così un backtick protegge davvero
        // il suo contenuto invece di lasciarlo attraversare da **/*/[]().
        return escapeHtml(value)
            .split(/(`[^`]+`)/)
            .map(function (part) {
                if (!part) {
                    return '';
                }
                if (part.length > 1 && part.charAt(0) === '`' && part.charAt(part.length - 1) === '`') {
                    return `<code>${part.slice(1, -1)}</code>`;
                }
                return inlineEmphasis(part);
            })
            .join('');
    };

    const isTableRow = function (line) {
        if (!line || line.indexOf('|') === -1) {
            return false;
        }
        return line.charAt(0) === '|' || (line.split('|').length - 1) >= 2;
    };

    const isTableSeparator = function (line) {
        return /^\s*\|?\s*:?-{1,}:?\s*(\|\s*:?-{1,}:?\s*)*\|?\s*$/.test(line);
    };

    const splitTableRow = function (line) {
        return line.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map(function (cell) {
            return cell.trim();
        });
    };

    const renderTable = function (header, rows) {
        let out = '<div class="chat-table-wrap"><table class="chat-table"><thead><tr>';
        header.forEach(function (cell) { out += `<th>${inlineMarkdown(cell)}</th>`; });
        out += '</tr></thead><tbody>';
        rows.forEach(function (row) {
            out += '<tr>';
            for (let c = 0; c < header.length; c++) {
                out += `<td>${inlineMarkdown(row[c] || '')}</td>`;
            }
            out += '</tr>';
        });
        out += '</tbody></table></div>';
        return out;
    };

    const renderMarkdown = function (value) {
        const lines = String(value || '').replace(/\r\n?/g, '\n').split('\n');
        let html = '';
        let list = '';
        const closeList = function () {
            if (list) {
                html += `</${list}>`;
                list = '';
            }
        };

        for (let i = 0; i < lines.length; i++) {
            const line = lines[i];
            const trimmed = line.trim();

            // Blocco di codice fenced: tutto ciò che sta dentro è testo letterale, mai markdown.
            // Una fence non chiusa (streaming in corso o output troncato) rende comunque un blocco,
            // così la resa live coincide con quella dello storico.
            if (/^\s*```/.test(line)) {
                closeList();
                const textFence = /^\s*```text\s*$/i.test(line);
                const buffer = [];
                let j = i + 1;
                while (j < lines.length && !/^\s*```\s*$/.test(lines[j])) {
                    buffer.push(lines[j]);
                    j++;
                }
                html += `<pre class="chat-code${textFence ? ' chat-code-text' : ''}"><code>${escapeHtml(buffer.join('\n'))}</code></pre>`;
                i = j;
                continue;
            }

            if (isTableRow(trimmed) && i + 1 < lines.length && isTableSeparator(lines[i + 1])) {
                closeList();
                const header = splitTableRow(trimmed);
                const rows = [];
                let j = i + 2;
                while (j < lines.length && isTableRow(lines[j].trim())) {
                    rows.push(splitTableRow(lines[j].trim()));
                    j++;
                }
                html += renderTable(header, rows);
                i = j - 1;
                continue;
            }

            if (!trimmed) {
                closeList();
                continue;
            }

            let match = trimmed.match(/^\[([^\]]+)\]:\s*(https?:\/\/\S+?)(?:\s+["'(](.*)["')])?\s*$/);
            if (match) {
                closeList();
                const refLabel = (match[3] || '').trim() || `Fonte: ${linkDomain(match[2])}`;
                html += `<p class="chat-ref"><a href="${escapeHtml(match[2])}" target="_blank" rel="noopener noreferrer">${escapeHtml(refLabel)}</a></p>`;
                continue;
            }

            match = line.match(/^\s*#{1,4}\s+(.+)$/);
            if (match) {
                closeList();
                html += `<h4>${inlineMarkdown(match[1])}</h4>`;
                continue;
            }

            match = line.match(/^\s*[-*]\s+(.+)$/);
            if (match) {
                if (list !== 'ul') {
                    closeList();
                    html += '<ul>';
                    list = 'ul';
                }
                html += `<li>${inlineMarkdown(match[1])}</li>`;
                continue;
            }

            match = line.match(/^\s*\d+\.\s+(.+)$/);
            if (match) {
                if (list !== 'ol') {
                    closeList();
                    html += '<ol>';
                    list = 'ol';
                }
                html += `<li>${inlineMarkdown(match[1])}</li>`;
                continue;
            }

            closeList();
            html += `<p>${inlineMarkdown(line)}</p>`;
        }

        closeList();
        return html;
    };

    window.AIManagerMarkdown = { render: renderMarkdown, escapeHtml: escapeHtml };
})();
