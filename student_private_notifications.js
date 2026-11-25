(function () {
    const badge = document.getElementById('notifBadge');
    const list  = document.getElementById('notifList');
    if (!badge || !list) return;

    const ENDPOINT = 'student_private_comments_fetch.php';

    let pcItems      = [];
    let pcUnread     = 0;
    let baseCount    = 0;      // the count shown by the page's own notification script
    let writingBadge = false;
    let writingList  = false;

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

    /* The number the existing script put on the bell. */
    function readBase() {
        if (badge.classList.contains('d-none')) return 0;

        const t = badge.textContent.trim();

        if (t === '99+') return 99;

        const n = parseInt(t, 10);

        return isNaN(n) ? 0 : n;
    }

    /* Bell number = existing notifications + unread private comment replies. */
    function writeBadge() {
        writingBadge = true;

        const total = baseCount + pcUnread;

        if (total > 0) {
            badge.textContent = total > 99 ? '99+' : String(total);
            badge.classList.remove('d-none');
        } else {
            badge.classList.add('d-none');
        }

        setTimeout(function () { writingBadge = false; }, 0);
    }

    /* Puts the private comment items at the top of the dropdown. */
    function renderBlock() {
        writingList = true;

        const old = list.querySelector('[data-pc-block]');
        if (old) old.remove();

        if (pcItems.length) {

            list.querySelectorAll('.notif-empty').forEach(function (el) {
                el.remove();
            });

            const wrap = document.createElement('div');
            wrap.setAttribute('data-pc-block', '1');

            wrap.innerHTML = pcItems.map(function (n) {
                return `
                <a href="${esc(n.url)}" class="pc-notif-item"
                   style="display:flex;gap:10px;padding:10px 14px;text-decoration:none;color:inherit;
                          border-bottom:1px solid #eee;${n.is_read ? '' : 'background:#eef5fb;'}">
                    <i class="fa-solid fa-comments" style="margin-top:4px;color:#1a73e8"></i>
                    <div style="min-width:0">
                        <div style="font-size:13px">
                            <strong>${esc(n.sender_name)}</strong> replied to your private comment · ${esc(n.class_name)}
                        </div>
                        <div style="font-size:13px;color:#555;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(n.title)}</div>
                        <small style="color:#888">${esc(n.assessment_title)} · ${timeAgo(n.created_at)}</small>
                    </div>
                </a>`;
            }).join('');

            list.insertBefore(wrap, list.firstChild);
        }

        setTimeout(function () { writingList = false; }, 0);
    }

    async function load() {
        try {
            const r = await fetch(ENDPOINT, { cache: 'no-store' });
            const d = await r.json();

            if (!d.success) return;

            pcItems  = d.items || [];
            pcUnread = Number(d.unread) || 0;

            renderBlock();
            writeBadge();
        } catch (e) {
            console.error('private comment notifications:', e);
        }
    }

    /* When the page's own script updates the bell number, add ours on top. */
    new MutationObserver(function () {
        if (writingBadge) return;

        baseCount = readBase();
        writeBadge();
    }).observe(badge, {
        childList: true,
        characterData: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['class']
    });

    /* When the page's own script redraws the dropdown, put ours back. */
    new MutationObserver(function () {
        if (writingList) return;

        renderBlock();
    }).observe(list, { childList: true });

    baseCount = readBase();

    load();
    setInterval(load, 15000);
})();