/* class_chat_unread.js
   "Missed messages" badges (with an "@" when someone mentioned you).
   - Sidebar "Messages" link: total unread (works on ANY page that includes this script)
   - Messages page: per-class badge (via ChatUnread.onUpdate)
   - Class page: badge on the floating chat bubble (via ChatUnread.onUpdate)  */
window.ChatUnread = (function () {
    const UNREAD_URL = 'class_chat_unread.php';
    const MARK_URL   = 'class_chat_mark_read.php';
    const POLL_MS    = 5000;

    let counts = {}, total = 0;
    let mentions = {}, mentionsTotal = 0;
    let timer = null, polling = false;
    let version = 0;                    // bumps on every markRead, so stale poll results are ignored
    const lastMarked = {};              // classId -> highest id already sent to the server
    const listeners = [];
    const baseTitle = document.title;
    let sidebarBadge = null;

    /* ---------- styles (injected, so no extra CSS file is needed) ---------- */
    const style = document.createElement('style');
    style.textContent = `
    .chat-unread-badge, .msg-class-unread, .gchat-fab-badge {
        display: inline-flex; align-items: center; justify-content: center;
        min-width: 20px; height: 20px; padding: 0 6px; border-radius: 10px;
        background: #dc3545; color: #fff; font-size: 11px; font-weight: 700;
        line-height: 1; font-style: normal;
    }
    .chat-unread-badge.has-mention, .msg-class-unread.has-mention, .gchat-fab-badge.has-mention {
        background: #e8590c;
    }
    .chat-unread-badge[hidden], .gchat-fab-badge[hidden] { display: none; }
    .chat-unread-badge { margin-left: auto; }
    .sidebar a.has-chat-badge { position: relative; }
    .sidebar.collapsed .chat-unread-badge {
        position: absolute; top: 6px; right: 12px; min-width: 16px; height: 16px;
        font-size: 10px; padding: 0 4px; margin: 0;
    }
    .msg-class-unread { margin-left: 6px; flex-shrink: 0; }
    .msg-class-item.has-unread .msg-class-name,
    .msg-class-item.has-unread .msg-class-preview { font-weight: 700; color: #1a2d3e; }
    .gchat-fab-badge {
        position: absolute; top: -4px; right: -4px; border: 2px solid #fff;
        box-sizing: content-box; min-width: 16px; height: 16px;
    }
    @media (max-width: 768px) {
        .sidebar.collapsed .chat-unread-badge {
            position: static; min-width: 20px; height: 20px; font-size: 11px; padding: 0 6px; margin-left: auto;
        }
    }`;
    document.head.appendChild(style);

    /* ---------- helpers ---------- */
    function label(n) { return n > 99 ? '99+' : String(n); }
    function sum(obj) { return Object.keys(obj).reduce(function (t, k) { return t + (obj[k] || 0); }, 0); }

    function renderSidebar() {
        if (!sidebarBadge) {
            const link = document.querySelector('.sidebar a[href$="messages.php"]');
            if (!link) return;
            link.classList.add('has-chat-badge');
            sidebarBadge = document.createElement('b');
            sidebarBadge.className = 'chat-unread-badge';
            sidebarBadge.hidden = true;
            link.appendChild(sidebarBadge);
        }
        sidebarBadge.textContent = (mentionsTotal > 0 ? '@' : '') + label(total);
        sidebarBadge.classList.toggle('has-mention', mentionsTotal > 0);
        sidebarBadge.title = mentionsTotal > 0 ? 'You were mentioned' : '';
        sidebarBadge.hidden = total === 0;
    }

    function payload() {
        return { total: total, counts: counts, mentions: mentions, mentionsTotal: mentionsTotal };
    }

    function emit() {
        renderSidebar();
        document.title = total > 0 ? '(' + label(total) + ') ' + baseTitle : baseTitle;
        listeners.forEach(function (fn) {
            try { fn(payload()); } catch (e) { console.error(e); }
        });
    }

    /* ---------- public API ---------- */
    function onUpdate(fn) {
        listeners.push(fn);
        fn(payload());
    }

    async function refresh() {
        if (polling) return;
        polling = true;
        const v = version;
        try {
            const r = await fetch(UNREAD_URL, { cache: 'no-store' });
            const d = await r.json();
            if (d.success && v === version) {         // ignore results that raced with a markRead
                counts = (d.classes && !Array.isArray(d.classes)) ? d.classes : {};
                mentions = (d.mentions && !Array.isArray(d.mentions)) ? d.mentions : {};
                total = d.total || 0;
                mentionsTotal = d.mentions_total || 0;
                emit();
            }
        } catch (e) { /* offline or logged out: keep the old numbers */ }
        finally { polling = false; }
    }

    async function markRead(classId, lastId) {
        classId = Number(classId); lastId = Number(lastId);
        if (!classId || !lastId) return;
        if (lastMarked[classId] && lastMarked[classId] >= lastId) return;   // already told the server
        lastMarked[classId] = lastId;

        version++;
        counts[classId] = 0;
        mentions[classId] = 0;
        total = sum(counts);
        mentionsTotal = sum(mentions);
        emit();

        try {
            const fd = new FormData();
            fd.append('class_id', classId);
            fd.append('last_id', lastId);
            await fetch(MARK_URL, { method: 'POST', body: fd });
        } catch (e) { console.error('markRead failed:', e); }
        refresh();
    }

    function start() {
        refresh();
        if (!timer) timer = setInterval(function () { if (!document.hidden) refresh(); }, POLL_MS);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
    }

    start();

    return { onUpdate: onUpdate, refresh: refresh, markRead: markRead };
})();