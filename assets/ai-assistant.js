// AI Assistant — self-contained module.
// Uses inline SVG icons (no lucide dependency) to avoid full-document icon rescans.
// Reads page context from window.LATRA_ASSISTANT (set server-side in Layout).
(function () {
    'use strict';

    if (window.LATRAAssistant) return;
    window.LATRAAssistant = true;

    var CONFIG = window.LATRA_ASSISTANT || { page: '', suggestions: [] };
    var PAGE_CONTEXT = {
        page: CONFIG.page || '',
        vehicle_id: CONFIG.vehicle_id || 0,
        vehicle_reg_no: CONFIG.vehicle_reg_no || ''
    };

    var DEFAULTS = [
        "What's happening today?",
        'Show failed LATRA submissions',
        'Check system health',
        'Summarize today\'s activity'
    ];
    var SUGGESTIONS = CONFIG.suggestions && CONFIG.suggestions.length ? CONFIG.suggestions : DEFAULTS;

    var WELCOME = 'Hello. I\'m your Wialon → LATRA Assistant.\n\n'
        + 'What would you like to check?';

    var STORAGE_KEY = 'latra_ai_conv';
    var conversationId = loadConversation();
    var busy = false;
    var stopController = null;
    var timedOut = false;
    var fab, panel, body, input, sendBtn, statusDot, dotsTimer;

    function loadConversation() {
        try {
            var v = localStorage.getItem(STORAGE_KEY);
            if (v && /^[A-Za-z0-9_-]{8,80}$/.test(v)) return v;
        } catch (e) { /* private mode */ }
        return newConversationId();
    }

    function newConversationId() {
        var hex = '';
        var arr = new Uint8Array(10);
        (window.crypto && window.crypto.getRandomValues) ? crypto.getRandomValues(arr) : arr.fill(0);
        for (var i = 0; i < arr.length; i++) hex += ('0' + arr[i].toString(16)).slice(-2);
        return 'ai_' + hex;
    }

    function persistConversation() {
        try { localStorage.setItem(STORAGE_KEY, conversationId); } catch (e) { /* ignore */ }
    }

    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.content : '';
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    // ------------------------------------------------------------------
    // Markdown rendering (escape-first; model HTML is never injected raw)
    // ------------------------------------------------------------------

    function renderInline(html) {
        // safe: input is already HTML-escaped
        html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
        html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/\[([^\]]+)\]\(((?:https?:\/\/|\/|\.)[^)\s]+)\)/g, function (m, t, u) {
            if (/^https?:\/\//i.test(u)) return '<a href="' + u + '" target="_blank" rel="noopener noreferrer">' + t + '</a>';
            return t;
        });
        html = html.replace(/(^|[^"\'])(https?:\/\/[^\s<]+)/g, '$1<a href="$2" target="_blank" rel="noopener noreferrer">$2</a>');
        return html;
    }

    function renderMarkdown(src) {
        var safe = esc(src);
        var out = '';
        // split code fences first
        var parts = safe.split(/```/);
        for (var p = 0; p < parts.length; p++) {
            if (p % 2 === 1) {
                var code = parts[p].replace(/^[a-z0-9_-]*\n/i, ''); // optional language tag line
                out += '<pre><code class="ai-code">' + code + '</code><button type="button" class="ai-copy" data-copy="1">Copy</button></pre>';
                continue;
            }
            out += renderBlocks(parts[p]);
        }
        return out;
    }

    function renderBlocks(part) {
        var lines = part.split('\n');
        var html = '';
        var i = 0;
        var para = [];
        function flushPara() {
            if (para.length) {
                html += '<p>' + renderInline(para.join('<br>')) + '</p>';
                para = [];
            }
        }
        function listMode(ordered) {
            var items = [];
            var rg = ordered ? /^\d+\.\s+(.*)$/ : /^[-*]\s+(.*)$/;
            while (i < lines.length && rg.test(lines[i])) {
                items.push('<li>' + renderInline(lines[i].replace(rg, '$1')) + '</li>');
                i++;
            }
            if (items.length) {
                html += (ordered ? '<ol>' : '<ul>') + items.join('') + (ordered ? '</ol>' : '</ul>');
            }
        }
        while (i < lines.length) {
            var line = lines[i];
            var t = line.trim();
            if (t === '') { flushPara(); i++; continue; }
            // table: consecutive lines containing |
            if (/^\|/.test(t) || /[|]/.test(t)) {
                flushPara();
                var rows = [];
                while (i < lines.length && /[|]/.test(lines[i].trim())) {
                    var cells = lines[i].trim().split('|');
                    if (cells.length && cells[0].trim() === '') cells.shift();
                    if (cells.length && cells[cells.length - 1].trim() === '') cells.pop();
                    rows.push(cells);
                    i++;
                }
                if (rows.length > 1) {
                    html += '<table><thead><tr>' + rows[0].map(function (c) { return '<th>' + renderInline(c) + '</th>'; }).join('') + '</tr></thead><tbody>';
                    for (var r = 1; r < rows.length; r++) {
                        html += '<tr>' + rows[r].map(function (c) { return '<td>' + renderInline(c) + '</td>'; }).join('') + '</tr>';
                    }
                    html += '</tbody></table>';
                } else {
                    html += '<p>' + renderInline(rows[0] && rows[0][0] || t) + '</p>';
                }
                continue;
            }
            var h = /^(#{1,4})\s+(.*)$/.exec(t);
            if (h) { flushPara(); html += '<h' + h[1].length + '>' + renderInline(h[2]) + '</h' + h[1].length + '>'; i++; continue; }
            if (/^[-*]\s+/.test(t)) { flushPara(); listMode(false); continue; }
            if (/^\d+\.\s+/.test(t)) { flushPara(); listMode(true); continue; }
            if (/^>\s?/.test(t)) {
                flushPara();
                var q = [];
                while (i < lines.length && /^>\s?/.test(lines[i].trim())) { q.push(lines[i].replace(/^>\s?/, '')); i++; }
                html += '<blockquote>' + renderInline(q.join('<br>')) + '</blockquote>';
                continue;
            }
            para.push(t);
            i++;
        }
        flushPara();
        return html;
    }

    // ------------------------------------------------------------------
    // DOM construction (static trusted strings only)
    // ------------------------------------------------------------------

    // Inline SVG (no lucide dependency here — avoids full-document icon rescans).
    function svgIcon(name, size) {
        var paths = {
            'sparkles': '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/>',
            'arrow-up': '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>',
            'square': '<rect width="18" height="18" x="3" y="3" rx="2"/>',
            'chevron-down': '<path d="m6 9 6 6 6-6"/>',
            'chevron-up': '<path d="m18 15-6-6-6 6"/>',
            'x': '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>'
        };
        var sz = size || 18;
        return '<svg class="ai-ico" width="' + sz + '" height="' + sz + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (paths[name] || '') + '</svg>';
    }

    function buildUI() {
        fab = document.createElement('button');
        fab.type = 'button';
        fab.className = 'ai-fab';
        fab.setAttribute('aria-label', 'Open AI Assistant');
        fab.title = 'Open AI Assistant';
        fab.innerHTML = '<span class="ai-fab-ico">' + svgIcon('sparkles', 22) + '</span>';
        fab.addEventListener('click', togglePanel);

        panel = document.createElement('section');
        panel.className = 'ai-panel ai-closed';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'false');
        panel.setAttribute('aria-label', 'LATRA Assistant chat');
        panel.innerHTML =
            '<div class="ai-header">'
            + '<span class="ai-h-ico">' + svgIcon('sparkles', 17) + '</span>'
            + '<div class="ai-h-meta">'
            + '<div class="ai-h-title">LATRA Assistant</div>'
            + '<div class="ai-h-sub"><span class="ai-status-dot" id="aiStatusDot"></span><span id="aiStatusText">Ready</span> · Wialon → LATRA Operations</div>'
            + '</div>'
            + '<button type="button" class="ai-min" id="aiMin" aria-label="Minimize">' + svgIcon('chevron-down', 15) + '</button>'
            + '<button type="button" class="ai-close" id="aiClose" aria-label="Close">' + svgIcon('x', 15) + '</button>'
            + '</div>'
            + '<div class="ai-body" id="aiBody" aria-live="polite"></div>'
            + '<div class="ai-input-wrap">'
            + '<textarea class="ai-input" id="aiInput" rows="1" placeholder="Ask here..."></textarea>'
            + '<button type="button" class="ai-send" id="aiSend" aria-label="Send message" disabled>' + svgIcon('arrow-up', 16) + '</button>'
            + '</div>'
            + '<div class="ai-input-actions">'
            + '<button type="button" class="ai-new-conv" id="aiNewConv">New conversation</button>'
            + '<span style="font-size:11px;color:var(--ai-muted)">Enter to send · Shift+Enter for new line</span>'
            + '</div>';

        document.body.appendChild(fab);
        document.body.appendChild(panel);

        body = panel.querySelector('#aiBody');
        input = panel.querySelector('#aiInput');
        sendBtn = panel.querySelector('#aiSend');
        statusDot = panel.querySelector('#aiStatusDot');

        panel.querySelector('#aiClose').addEventListener('click', closePanel);
        panel.querySelector('#aiMin').addEventListener('click', toggleMinimize);
        panel.querySelector('#aiNewConv').addEventListener('click', newConversation);
        sendBtn.addEventListener('click', function () {
            if (busy) { stopGeneration(); } else { sendMessage(); }
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
        });
        input.addEventListener('input', function () {
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, 120) + 'px';
            sendBtn.disabled = busy || input.value.trim() === '';
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !panel.classList.contains('ai-closed') && !panel.classList.contains('ai-minimized')) {
                closePanel();
            }
        });
        panel.addEventListener('click', function (e) {
            var copy = e.target.closest('.ai-copy');
            if (copy) {
                var codeEl = copy.parentNode.querySelector('code');
                if (codeEl && navigator.clipboard) {
                    navigator.clipboard.writeText(codeEl.textContent).then(function () {
                        var t = copy.textContent; copy.textContent = 'Copied';
                        setTimeout(function () { copy.textContent = t; }, 1400);
                    });
                }
            }
        });

        addWelcome();
    }

    function addWelcome() {
        body.innerHTML = '';
        var w = document.createElement('div');
        w.className = 'ai-welcome';
        w.innerHTML = '<div class="ai-w-head">Welcome</div>' + renderMarkdown(WELCOME);
        var chips = document.createElement('div');
        chips.className = 'ai-chips';
        SUGGESTIONS.forEach(function (s) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'ai-chip';
            b.textContent = s;
            b.addEventListener('click', function () {
                if (!busy) { input.value = s; sendBtn.disabled = false; sendMessage(); }
            });
            chips.appendChild(b);
        });
        w.appendChild(chips);
        body.appendChild(w);
    }

    function togglePanel() {
        if (panel.classList.contains('ai-closed')) openPanel(); else closePanel();
    }

    function openPanel() {
        panel.classList.remove('ai-closed');
        fab.setAttribute('aria-label', 'Close AI Assistant');
        fab.title = 'Close AI Assistant';
        setTimeout(function () { input.focus(); }, 120);
    }

    function closePanel() {
        panel.classList.add('ai-closed');
        fab.setAttribute('aria-label', 'Open AI Assistant');
        fab.title = 'Open AI Assistant';
        fab.focus();
    }

    function toggleMinimize() {
        var min = panel.querySelector('#aiMin');
        if (panel.classList.contains('ai-minimized')) {
            panel.classList.remove('ai-minimized');
            min.innerHTML = svgIcon('chevron-down', 15);
            min.setAttribute('aria-label', 'Minimize');
        } else {
            panel.classList.add('ai-minimized');
            min.innerHTML = svgIcon('chevron-up', 15);
            min.setAttribute('aria-label', 'Restore');
        }
    }

    function newConversation() {
        stopController && stopController.abort();
        stopElapsed();
        busy = false;
        conversationId = newConversationId();
        persistConversation();
        addWelcome();
        setStatus(true);
        updateSend();
    }

    function setStatus(ready) {
        if (ready) {
            statusDot.classList.remove('ai-busy');
            panel.querySelector('#aiStatusText').textContent = 'Ready';
        } else {
            statusDot.classList.add('ai-busy');
            panel.querySelector('#aiStatusText').textContent = 'Thinking...';
        }
    }

    // ------------------------------------------------------------------
    // Message I/O
    // ------------------------------------------------------------------

    function appendEl(cls, inner) {
        var item = document.createElement('div');
        item.className = cls;
        item.innerHTML = inner;
        body.appendChild(item);
        scrollBottom();
    }

    function appendUser(text) {
        appendEl('ai-msg ai-user', '<span class="ai-avatar">You</span><div class="ai-bubble ai-bd">' + renderInline(esc(text)) + '</div>');
    }

    function appendAssistant(text) {
        appendEl('ai-msg ai-assistant', '<span class="ai-avatar">' + svgIcon('sparkles', 13) + '</span><div class="ai-bubble ai-bd">' + renderMarkdown(text) + '</div>');
    }

    function appendKicker(text) {
        appendEl('ai-msg ai-error', '<span class="ai-avatar">AI</span><div class="ai-bubble">' + esc(text) + '</div>');
    }

    function appendInfo(text) {
        appendEl('ai-msg ai-system', '<span class="ai-avatar">AI</span><div class="ai-bubble">' + esc(text) + '</div>');
    }

    var thinkingEl = null;
    var elapsedTimer = null;
    var elapsedStart = 0;
    function updateElapsed() {
        if (!thinkingEl || (!panel.classList.contains('ai-closed') && panel.classList.contains('ai-minimized'))) return;
        var el = thinkingEl.querySelector('.ai-elapsed');
        if (el) el.textContent = ' · ' + Math.round((Date.now() - elapsedStart) / 1000) + 's';
    }
    function startElapsed() {
        elapsedStart = Date.now();
        stopElapsed();
        elapsedTimer = setInterval(updateElapsed, 1000);
        updateElapsed();
    }
    function stopElapsed() {
        if (elapsedTimer) { clearInterval(elapsedTimer); elapsedTimer = null; }
    }
    function showThinking() {
        thinkingEl = document.createElement('div');
        thinkingEl.className = 'ai-msg ai-assistant';
        thinkingEl.innerHTML = '<span class="ai-avatar">' + svgIcon('sparkles', 13) + '</span>'
            + '<div class="ai-bubble"><div class="ai-thinking"><span class="ai-dots"><i></i><i></i><i></i></span> Thinking...<span class="ai-elapsed"></span></div></div>';
        body.appendChild(thinkingEl);
        scrollBottom();
    }
    function hideThinking() {
        if (thinkingEl) { thinkingEl.remove(); thinkingEl = null; }
    }

    function scrollBottom() {
        body.scrollTop = body.scrollHeight;
    }

    function sendMessage() {
        var text = input.value.trim();
        if (!text || busy) return;
        appendUser(text);
        input.value = '';
        input.style.height = 'auto';
        timedOut = false;
        busy = true;
        setStatus(false);
        updateSend();
        showThinking();
        startElapsed();

        stopController = null;
        if (navigator.onLine === false) {
            hideThinking(); finishBusy();
            appendKicker('You appear to be offline. Please check your connection.');
            return;
        }

        var controller = new AbortController();
        stopController = controller;
        var guardTimer = setTimeout(function () { timedOut = true; controller.abort(); }, 120000);

        fetch('ai_chat.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf()
            },
            body: JSON.stringify({
                message: text,
                conversation_id: conversationId,
                context: PAGE_CONTEXT
            }),
            signal: controller.signal
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            hideThinking();
            if (data && data.success && data.message && data.message.content) {
                if (data.conversation_id) { conversationId = data.conversation_id; persistConversation(); }
                appendAssistant(data.message.content);
            } else if (data && data.error) {
                appendKicker(data.error.message || 'Something went wrong. Please try again.');
            } else {
                appendKicker('Unexpected response from the assistant service.');
            }
        })
        .catch(function (err) {
            hideThinking();
            if (err && err.name === 'AbortError') {
                appendInfo(timedOut ? 'The assistant took too long. Please try again with a shorter or more focused question.' : 'Generation stopped.');
            } else {
                appendKicker('Could not reach the assistant service. Your system is still operating normally.');
            }
        })
        .finally(function () {
            clearTimeout(guardTimer);
            finishBusy();
        });
    }

    function stopGeneration() {
        if (stopController) stopController.abort();
    }

    function finishBusy() {
        stopElapsed();
        busy = false;
        stopController = null;
        setStatus(true);
        updateSend();
        input.focus();
    }

    function updateSend() {
        sendBtn.disabled = busy || input.value.trim() === '';
        if (busy) {
            sendBtn.classList.add('ai-stop');
            sendBtn.innerHTML = svgIcon('square', 13);
            sendBtn.setAttribute('aria-label', 'Stop generation');
        } else {
            sendBtn.classList.remove('ai-stop');
            sendBtn.innerHTML = svgIcon('arrow-up', 16);
            sendBtn.setAttribute('aria-label', 'Send message');
        }
    }

    // ------------------------------------------------------------------

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', buildUI);
    } else {
        buildUI();
    }
})();