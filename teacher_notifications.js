(function () {
    const badge   = document.getElementById('notifBadge');
    const list    = document.getElementById('notifList');
    const markAll = document.getElementById('markAllReadBtn');
    if (!badge || !list) return;

    let items = [];

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c =>
            ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function timeAgo(raw) {
        const d = new Date(String(raw).replace(' ', 'T'));
        const s = Math.floor((Date.now() - d.getTime()) / 1000);
        if (s < 60)     return 'Just now';
        if (s < 3600)   return Math.floor(s / 60) + 'm ago';
        if (s < 86400)  return Math.floor(s / 3600) + 'h ago';
        if (s < 604800) return Math.floor(s / 86400) + 'd ago';
        return d.toLocaleDateString();
    }

    function render(unread) {
        if (unread > 0) {
            badge.textContent = unread > 99 ? '99+' : unread;
            badge.classList.remove('d-none');
            markAll && markAll.classList.remove('d-none');
        } else {
            badge.classList.add('d-none');
            markAll && markAll.classList.add('d-none');
        }

        if (!items.length) {
            list.innerHTML = '<div class="notif-empty">No new notifications</div>';
            return;
        }

        list.innerHTML = items.map((n, i) => {
            const isReview = n.type === 'review';
            const icon  = isReview ? 'fa-clipboard-check' : 'fa-at';
            const color = isReview ? '#e08a2c' : '#3f87b5';
            const head  = isReview
                ? `<strong>${esc(n.class_name)}</strong> deadline ended · ready to check`
                : `<strong>${esc(n.sender_name)}</strong> mentioned you in the group chat · ${esc(n.class_name)}`;
            const foot  = isReview
                ? `<small style="color:#e08a2c;font-weight:600">${esc(n.info)}</small>`
                : `<small style="color:#888">${timeAgo(n.created_at)}</small>`;

            return `
            <a href="#" class="notif-item ${n.is_read ? '' : 'unread'}" data-i="${i}"
               style="display:flex;gap:10px;padding:10px 14px;text-decoration:none;color:inherit;
                      border-bottom:1px solid #eee;${n.is_read ? '' : 'background:#eef5fb;'}">
                <i class="fa-solid ${icon}" style="margin-top:4px;color:${color}"></i>
                <div style="min-width:0">
                    <div style="font-size:13px">${head}</div>
                    <div style="font-size:13px;color:#555;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(n.title)}</div>
                    ${foot}
                </div>
            </a>`;
        }).join('');
    }

    async function load() {
        try {
            const r = await fetch('teacher_notifications_fetch.php', { cache: 'no-store' });
            const d = await r.json();
            if (!d.success) return;
            items = d.items;
            render(d.unread);
        } catch (e) { console.error('notifications:', e); }
    }

    function markRead(arr) {
        const body = new URLSearchParams({
            items: JSON.stringify(arr.map(n => ({ type: n.type, id: n.id })))
        });
        return fetch('notifications_read.php', { method: 'POST', body });
    }

    list.addEventListener('click', async function (e) {
        const a = e.target.closest('.notif-item');
        if (!a) return;
        e.preventDefault();
        const n = items[a.dataset.i];
        if (!n.is_read) { try { await markRead([n]); } catch (_) {} }
        window.location.href = n.url;
    });

    markAll && markAll.addEventListener('click', async function () {
        const unread = items.filter(n => !n.is_read);
        if (!unread.length) return;
        await markRead(unread);
        load();
    });

    load();
    setInterval(load, 15000);
})();