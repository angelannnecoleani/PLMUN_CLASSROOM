/* class_chat_mentions.js
   @mentions for the class group chat:
   - type "@" in the message box to pick a classmate / teacher
   - mentioned names are highlighted in the chat
   Shared by the Messages pages and the floating chat on the class pages. */
window.ChatMentions = (function () {
    const MEMBERS_URL = 'class_chat_members.php';

    let meId = 0;
    let members = [];
    let input = null, popup = null;
    let items = [], active = 0;

    /* ---------- styles (injected) ---------- */
    const style = document.createElement('style');
    style.textContent = `
    .mention {
        color: #3f87b5; background: rgba(63,135,181,.12);
        border-radius: 4px; padding: 0 3px; font-weight: 600;
    }
    .mention.mention-me, .mention.mention-all { background: #fff3cd; color: #8a5a00; }
    .msg-row.me .mention, .gchat-row.me .mention { background: rgba(255,255,255,.25); color: #fff; }

    .mention-popup {
        position: fixed; z-index: 2100; display: none; overflow: hidden;
        background: #fff; border: 1px solid #e0e0e0; border-radius: 12px;
        box-shadow: 0 6px 24px rgba(0,0,0,.18); padding: 4px;
    }
    .mention-popup.show { display: block; }
    .mention-item {
        display: flex; align-items: center; gap: 10px;
        padding: 8px 10px; border-radius: 8px; cursor: pointer;
    }
    .mention-item.active, .mention-item:hover { background: #f0f4f8; }
    .mention-avatar {
        width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
        background: #c8d8e8; color: #3f87b5; font-weight: 700; font-size: 13px;
        display: flex; align-items: center; justify-content: center;
    }
    .mention-name {
        font-size: 14px; font-weight: 600; color: #1a2d3e; min-width: 0;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .mention-role { font-size: 11px; color: #888; margin-left: auto; flex-shrink: 0; }`;
    document.head.appendChild(style);

    /* ---------- rendering ---------- */
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    // Escape first, then turn http(s) URLs into clickable links
    function linkify(text) {
        return esc(text).replace(/(https?:\/\/[^\s<]+)/g,
            '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
    }
    function isWord(ch) { return !!ch && /[\p{L}\p{N}_]/u.test(ch); }

    /* Message text -> safe HTML, with links and highlighted @mentions.
       `mentions` = [{id, name}, ...] as sent by the server. */
    function render(text, mentions) {
        text = String(text == null ? '' : text);
        const list = (mentions || [])
            .map(function (m) { return { id: Number(m.id), name: String(m.name) }; })
            .sort(function (a, b) { return b.name.length - a.name.length; });
        if (text.indexOf('@') === -1) return linkify(text);

        const lower = text.toLowerCase();
        const ranges = [];

        list.forEach(function (m) {
            const needle = ('@' + m.name).toLowerCase();
            let from = 0, i;
            while ((i = lower.indexOf(needle, from)) !== -1) {
                const end = i + needle.length;
                const ok = !isWord(i > 0 ? text[i - 1] : '') &&
                           !isWord(end < text.length ? text[end] : '') &&
                           !ranges.some(function (r) { return i < r.end && end > r.start; });
                if (ok) ranges.push({ start: i, end: end, id: m.id });
                from = ok ? end : i + 1;
            }
        });
        // "@all" / "@everyone" notify the whole class
        const allRe = /@(?:all|everyone)/gi;
        let am;
        while ((am = allRe.exec(text)) !== null) {
            const s = am.index, e = s + am[0].length;
            const ok = !isWord(s > 0 ? text[s - 1] : '') &&
                       !isWord(e < text.length ? text[e] : '') &&
                       !ranges.some(function (r) { return s < r.end && e > r.start; });
            if (ok) ranges.push({ start: s, end: e, id: -1, all: true });
        }

        if (!ranges.length) return linkify(text);

        ranges.sort(function (a, b) { return a.start - b.start; });

        let html = '', cursor = 0;
        ranges.forEach(function (r) {
            html += linkify(text.slice(cursor, r.start));
            html += '<span class="mention' + (r.all ? ' mention-all' : (r.id === meId ? ' mention-me' : '')) + '">' +
                    esc(text.slice(r.start, r.end)) + '</span>';
            cursor = r.end;
        });
        html += linkify(text.slice(cursor));
        return html;
    }

    /* ---------- autocomplete ---------- */
    function init(o) { meId = Number((o && o.meId) || 0); }

    function currentToken() {
        const pos = input.selectionStart;
        const before = input.value.slice(0, pos);
        const m = before.match(/(^|\s)@([^@\n]{0,30})$/);
        if (!m) return null;
        return { start: pos - m[2].length - 1, end: pos, query: m[2] };
    }

    function hide() { if (popup) popup.classList.remove('show'); items = []; }

    function position() {
        const r = input.getBoundingClientRect();
        const width = Math.min(Math.max(r.width, 240), window.innerWidth - 16);
        const left = Math.max(8, Math.min(r.left, window.innerWidth - width - 8));
        popup.style.width = width + 'px';
        popup.style.left = left + 'px';
        popup.style.bottom = (window.innerHeight - r.top + 8) + 'px';
        popup.style.top = 'auto';
    }

    function setActive(i) {
        active = i;
        Array.prototype.forEach.call(popup.children, function (el, idx) {
            el.classList.toggle('active', idx === active);
        });
    }

    function choose(m) {
        const tok = currentToken();
        if (!tok || !m) { hide(); return; }
        const insert = '@' + m.name + ' ';
        input.value = input.value.slice(0, tok.start) + insert + input.value.slice(tok.end);
        const pos = tok.start + insert.length;
        input.setSelectionRange(pos, pos);
        hide();
        input.dispatchEvent(new Event('input', { bubbles: true }));   // lets the page auto-size the box
        input.focus();
    }

    function update() {
        const tok = currentToken();
        if (!tok || !members.length) { hide(); return; }

        const q = tok.query.toLowerCase();

        // "@Juan Dela Cruz " / "@all " (finished + space) = don't reopen the list
        if (/\s$/.test(tok.query) &&
            (q.trim() === 'all' || q.trim() === 'everyone' ||
             members.some(function (m) { return m.name.toLowerCase() === q.trim(); }))) { hide(); return; }

        const starts = [], contains = [];
        members.forEach(function (m) {
            const n = m.name.toLowerCase();
            if (n.indexOf(q) === 0) starts.push(m);
            else if (n.indexOf(q) !== -1) contains.push(m);
        });
        items = starts.concat(contains).slice(0, 6);

        // NEW: "@all" entry on top (matches "", "a", "al", "all", "e", "ev", ... "everyone")
        if (q === '' || 'all'.indexOf(q) === 0 || 'everyone'.indexOf(q) === 0) {
            items.unshift({ id: 0, name: (q !== '' && 'all'.indexOf(q) !== 0) ? 'everyone' : 'all', role: 'all' });
            items = items.slice(0, 6);
        }
        if (!items.length) { hide(); return; }

        popup.innerHTML = '';
        items.forEach(function (m, idx) {
            const row = document.createElement('div');
            row.className = 'mention-item';
            row.setAttribute('role', 'option');

            const av = document.createElement('div');
            av.className = 'mention-avatar';
            av.textContent = m.role === 'all' ? '@' : (m.name.trim().charAt(0) || '?').toUpperCase();

            const nm = document.createElement('div');
            nm.className = 'mention-name';
            nm.textContent = m.name;

            const role = document.createElement('div');
            role.className = 'mention-role';
            role.textContent = m.role === 'all'
                ? 'Notify everyone'
                : (m.role === 'teacher'
                    ? 'Primary Instructor'
                    : (m.role === 'co_instructor'
                        ? 'Co-Instructor'
                        : 'Student'));

            row.append(av, nm, role);
            row.addEventListener('mousedown', function (e) { e.preventDefault(); });   // keep focus in the box
            row.addEventListener('click', function () { choose(m); });
            row.addEventListener('mousemove', function () { if (active !== idx) setActive(idx); });
            popup.appendChild(row);
        });

        popup.classList.add('show');
        position();
        setActive(0);
    }

    async function loadMembers(classId) {
        try {
            const r = await fetch(MEMBERS_URL + '?class_id=' + encodeURIComponent(classId));
            const d = await r.json();
            if (d.success) {
                members = (d.members || []).filter(function (m) { return Number(m.id) !== meId; });
            }
        } catch (e) { console.error('mentions: could not load members', e); }
    }

    /* Call once per message box.  Must run BEFORE the page adds its own Enter-to-send handler. */
    function attach(o) {
        input = o.input;
        loadMembers(o.classId);

        popup = document.createElement('div');
        popup.className = 'mention-popup';
        popup.setAttribute('role', 'listbox');
        document.body.appendChild(popup);

        input.addEventListener('keydown', function (e) {
            if (e.isComposing || !popup.classList.contains('show') || !items.length) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault(); setActive((active + 1) % items.length);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault(); setActive((active - 1 + items.length) % items.length);
            } else if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault(); e.stopImmediatePropagation();   // don't send the message
                choose(items[active]);
            } else if (e.key === 'Escape') {
                e.preventDefault(); hide();
            }
        }, true);

        input.addEventListener('input', update);
        input.addEventListener('click', update);
        input.addEventListener('keyup', function (e) {
            if (['ArrowUp', 'ArrowDown', 'Enter', 'Tab', 'Escape'].indexOf(e.key) !== -1) return;
            update();
        });
        input.addEventListener('blur', function () { setTimeout(hide, 150); });
        window.addEventListener('resize', hide);
    }

    return { init: init, attach: attach, render: render };
})();