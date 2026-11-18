/* class_chat_actions.js
   Edit / delete for your own chat messages.
   Shared by teacher_messages.php and student_messages.php */
window.ChatActions = (function () {
    const EDIT_URL    = 'class_chat_edit.php';
    const DELETE_URL  = 'class_chat_delete.php';
    const CHANGES_URL = 'class_chat_changes.php';

    let classId = 0, chatBody = null, linkify = null;
    let since = '1970-01-01 00:00:00';
    let syncing = false;
    let menu = null, menuRow = null, inited = false;

    // Class names of the chat markup. Defaults = Messages page.
    // The floating widget (gchat-*) passes its own names to init().
    const cls = { row: 'msg-row', bubble: 'msg-bubble', time: 'msg-time', col: 'msg-col', emptyId: 'msgChatEmpty' };
    const sel = {
        bubble:  () => '.' + cls.bubble + ':not(.msg-deleted)',
        time:    () => '.' + cls.time,
        col:     () => '.' + cls.col,
        rowById: (id) => '.' + cls.row + '[data-id="' + id + '"]'
    };

    /* ---------- setup ---------- */
    function init(opts) {
        if (inited) return;
        inited = true;
        if (opts.classes) Object.assign(cls, opts.classes);
        if (opts.emptyId) cls.emptyId = opts.emptyId;
        classId  = opts.classId;
        chatBody = opts.chatBody;
        linkify  = opts.linkify;
        buildMenu();
        chatBody.addEventListener('scroll', closeMenu, { passive: true });
        window.addEventListener('resize', closeMenu);
    }

    function buildMenu() {
        if (menu) return;
        menu = document.createElement('div');
        menu.className = 'msg-menu';
        menu.innerHTML =
            '<button type="button" data-act="edit"><i class="fa-solid fa-pen"></i>Edit</button>' +
            '<button type="button" data-act="delete" class="danger"><i class="fa-solid fa-trash"></i>Delete</button>';
        document.body.appendChild(menu);

        menu.addEventListener('click', function (e) {
            const b = e.target.closest('button[data-act]');
            if (!b || !menuRow) return;
            const row = menuRow;
            closeMenu();
            if (b.dataset.act === 'edit') startEdit(row); else deleteMessage(row);
        });
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.msg-menu') && !e.target.closest('.msg-menu-btn')) closeMenu();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeMenu();
        });
    }

    function openMenu(row, btn) {
        if (menu.classList.contains('show') && menuRow === row) { closeMenu(); return; }
        menuRow = row;
        // text-less messages (images/files) can only be deleted
        menu.querySelector('[data-act="edit"]').style.display =
            row.querySelector(sel.bubble()) ? '' : 'none';
        menu.classList.add('show');

        const r = btn.getBoundingClientRect();
        const w = menu.offsetWidth, h = menu.offsetHeight;
        const left = Math.max(8, Math.min(r.right - w, window.innerWidth - w - 8));
        let top = r.bottom + 4;
        if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 4);
        menu.style.left = left + 'px';
        menu.style.top  = top + 'px';
    }

    function closeMenu() {
        if (menu) menu.classList.remove('show');
        menuRow = null;
    }

    /* ---------- called by renderMsg() for every message ---------- */
    function decorate(row, m) {
        row.dataset.id  = m.id;
        row.dataset.raw = m.message || '';
        if (m.is_deleted) { row.dataset.deleted = '1'; return; }   // placeholder only, no menu

        const time = row.querySelector(sel.time());
        if (!time) return;

        if (m.edited_at) markEdited(row);

        if (m.is_me) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'msg-menu-btn';
            btn.setAttribute('aria-label', 'Message options');
            btn.innerHTML = '<i class="fa-solid fa-ellipsis-vertical"></i>';
            btn.addEventListener('click', function () { openMenu(row, btn); });
            time.appendChild(btn);
        }
    }

    function markEdited(row) {
        const time = row.querySelector(sel.time());
        if (!time || time.querySelector('.msg-edited-tag')) return;
        const tag = document.createElement('span');
        tag.className = 'msg-edited-tag';
        tag.innerHTML = '<i class="fa-solid fa-pen"></i> Edited';
        const btn = time.querySelector('.msg-menu-btn');
        if (btn) time.insertBefore(tag, btn); else time.appendChild(tag);
    }

    /* ---------- network helper ---------- */
    async function post(url, fd) {
        const r = await fetch(url, { method: 'POST', body: fd });
        const raw = await r.text();
        try { return JSON.parse(raw); }
        catch (e) { throw new Error('Server error (HTTP ' + r.status + ')'); }
    }

    /* ---------- edit ---------- */
    function startEdit(row) {
        const bubble = row.querySelector(sel.bubble());
        if (!bubble || row.querySelector('.msg-edit-box')) return;

        const box = document.createElement('div');
        box.className = 'msg-edit-box';

        const ta = document.createElement('textarea');
        ta.className = 'msg-edit-input';
        ta.rows = 1;
        ta.value = row.dataset.raw || '';

        const actions = document.createElement('div');
        actions.className = 'msg-edit-actions';
        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button'; cancelBtn.className = 'msg-edit-cancel'; cancelBtn.textContent = 'Cancel';
        const saveBtn = document.createElement('button');
        saveBtn.type = 'button'; saveBtn.className = 'msg-edit-save'; saveBtn.textContent = 'Save';
        actions.append(cancelBtn, saveBtn);
        box.append(ta, actions);

        bubble.style.display = 'none';
        bubble.after(box);

        function autosize() {
            ta.style.height = 'auto';
            ta.style.height = Math.min(ta.scrollHeight, 140) + 'px';
        }
        function cancel() {
            box.remove();
            bubble.style.display = '';
        }
        async function save() {
            const text = ta.value.trim();
            if (text === '') { ta.focus(); return; }
            if (text === row.dataset.raw) { cancel(); return; }

            saveBtn.disabled = true;
            try {
                const fd = new FormData();
                fd.append('message_id', row.dataset.id);
                fd.append('message', text);
                const d = await post(EDIT_URL, fd);
                if (d.success) {
                    applyEdit(row, d.message, d.mentions);
                } else {
                    alert(d.error || 'Could not edit the message');
                    saveBtn.disabled = false;
                }
            } catch (e) {
                alert('Could not edit the message: ' + e.message);
                saveBtn.disabled = false;
            }
        }

        cancelBtn.addEventListener('click', cancel);
        saveBtn.addEventListener('click', save);
        ta.addEventListener('input', autosize);
        ta.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); save(); }
            else if (e.key === 'Escape') { e.preventDefault(); cancel(); }
        });

        autosize();
        ta.focus();
        ta.setSelectionRange(ta.value.length, ta.value.length);
    }

    function applyEdit(row, text, mentions) {
        row.dataset.raw = text;
        const bubble = row.querySelector(sel.bubble());
        if (bubble) {
            bubble.innerHTML = linkify(text, mentions);
            bubble.style.display = '';
        }
        const box = row.querySelector('.msg-edit-box');
        if (box) box.remove();
        markEdited(row);
    }

    /* ---------- delete ---------- */
    async function deleteMessage(row) {
        if (!confirm('Delete this message? This cannot be undone.')) return;
        try {
            const fd = new FormData();
            fd.append('message_id', row.dataset.id);
            const d = await post(DELETE_URL, fd);
            if (d.success) markDeleted(row);
            else alert(d.error || 'Could not delete the message');
        } catch (e) {
            alert('Could not delete the message: ' + e.message);
        }
    }

    /* Replace a message with a "This message was deleted" placeholder */
    function markDeleted(row) {
        if (row.dataset.deleted === '1' && row.querySelector('.msg-deleted')) return;
        row.dataset.deleted = '1';
        row.dataset.raw = '';
        closeMenu();

        const col  = row.querySelector(sel.col());
        const time = row.querySelector(sel.time());
        if (!col || !time) return;

        col.querySelectorAll(':scope > a, :scope > .' + cls.bubble + ', :scope > .msg-edit-box')
           .forEach(function (el) { el.remove(); });

        const ph = document.createElement('div');
        ph.className = cls.bubble + ' msg-deleted';
        ph.innerHTML = '<i class="fa-solid fa-ban"></i> This message was deleted';
        col.insertBefore(ph, time);

        const tag = time.querySelector('.msg-edited-tag');
        if (tag) tag.remove();
        const btn = time.querySelector('.msg-menu-btn');
        if (btn) btn.remove();
    }

    function removeRow(row) {
        row.remove();
        if (!chatBody.querySelector('.' + cls.row)) {
            const empty = document.getElementById(cls.emptyId);
            if (empty) empty.style.display = '';
        }
    }

    /* ---------- keep other windows in sync (polled) ---------- */
    async function sync() {
        if (syncing || !classId) return;
        syncing = true;
        try {
            const r = await fetch(CHANGES_URL + '?class_id=' + classId + '&since=' + encodeURIComponent(since));
            const d = await r.json();
            if (d.success) {
                (d.deleted || []).forEach(function (id) {
                    const row = chatBody.querySelector(sel.rowById(id));
                    if (row) markDeleted(row);
                });
                (d.edited || []).forEach(function (e) {
                    const row = chatBody.querySelector(sel.rowById(e.id));
                    if (row && row.dataset.deleted !== '1' && !row.querySelector('.msg-edit-box')) applyEdit(row, e.message, e.mentions);
                });
                if (d.server_time) since = d.server_time;
            }
        } catch (e) {
            console.error('chat sync error:', e);
        } finally {
            syncing = false;
        }
    }

    return { init: init, decorate: decorate, sync: sync };
})();