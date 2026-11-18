<?php
session_start();
include 'database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$student_id = (int) $_SESSION['user_id'];

/* =========================================
   GET STUDENT INFORMATION
========================================= */

$userStmt = mysqli_prepare(
    $conn,
    "SELECT id, name, profile_image FROM users WHERE id = ? LIMIT 1"
);

mysqli_stmt_bind_param($userStmt, "i", $student_id);
mysqli_stmt_execute($userStmt);

$userResult = mysqli_stmt_get_result($userStmt);
$user = mysqli_fetch_assoc($userResult);

mysqli_stmt_close($userStmt);

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

/* =========================================
   PROFILE IMAGE VALIDATION
   Show user icon if image is missing,
   invalid, unreadable, or default.
========================================= */

$profileImageFile = '';
$hasProfileImage = false;

$rawProfileImage = trim((string)($user['profile_image'] ?? ''));

// Support either filename-only values or values such as uploads/photo.jpg.
$safeName = basename(str_replace('\\', '/', $rawProfileImage));

$defaultImages = [
    '',
    'default.png',
    'default.jpg',
    'default.jpeg',
    'default.svg',
    'null'
];

if (
    $safeName !== '' &&
    !in_array(strtolower($safeName), $defaultImages, true)
) {
    $imagePath = __DIR__ . '/uploads/' . $safeName;

    if (is_file($imagePath) && is_readable($imagePath)) {
        $profileImageFile = $safeName;
        $hasProfileImage = true;
    }
}

// Get all classes this student is enrolled in (+ last message / attachment type for the preview)
// CHANGED: deleted messages are ignored in the preview
$classesQuery = mysqli_query($conn, "
    SELECT classes.*,
           teacher.name AS teacher_name,
           (SELECT message FROM class_chat_messages
            WHERE class_chat_messages.class_id = classes.id
              AND class_chat_messages.is_deleted = 0
            ORDER BY id DESC LIMIT 1) AS last_message,
           (SELECT attachment_type FROM class_chat_messages
            WHERE class_chat_messages.class_id = classes.id
              AND class_chat_messages.is_deleted = 0
            ORDER BY id DESC LIMIT 1) AS last_attachment_type,
           (SELECT created_at FROM class_chat_messages
            WHERE class_chat_messages.class_id = classes.id
              AND class_chat_messages.is_deleted = 0
            ORDER BY id DESC LIMIT 1) AS last_message_time
    FROM class_students
    INNER JOIN classes ON class_students.class_id = classes.id
    INNER JOIN users AS teacher ON classes.teacher_id = teacher.id
    WHERE class_students.student_id = '$student_id'
    ORDER BY last_message_time DESC, classes.id DESC
");

$active_class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;

// Default to first class
if (!$active_class_id && mysqli_num_rows($classesQuery) > 0) {
    mysqli_data_seek($classesQuery, 0);
    $first = mysqli_fetch_assoc($classesQuery);
    $active_class_id = $first['id'];
    mysqli_data_seek($classesQuery, 0);
}

// Get active class info
$activeClass = null;
if ($active_class_id) {
    $r = mysqli_query($conn, "
        SELECT classes.*, users.name AS teacher_name
        FROM classes
        INNER JOIN class_students ON classes.id = class_students.class_id
        INNER JOIN users ON classes.teacher_id = users.id
        WHERE classes.id = '$active_class_id'
        AND class_students.student_id = '$student_id'
    ");
    $activeClass = mysqli_fetch_assoc($r);
}

/* MOBILE: only open the chat panel straight away when the student
   actually picked a class (?class_id=...). With no class_id in the URL
   the phone shows the class list first. */
$openChatOnMobile = isset($_GET['class_id']) && $activeClass;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, interactive-widget=resizes-content">
    <title>Messages</title>
    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">
    <link rel="stylesheet" href="student_messages.css">
    <!-- chat attachment styles -->
    <link rel="stylesheet" href="student_messages_chat.css?v=1">
    <!-- NEW: edit / delete message styles -->
    <link rel="stylesheet" href="class_chat_actions.css?v=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <button class="toggle-btn" onclick="toggleSidebar()">
            <i class="fa-solid fa-bars"></i>
        </button>
    </div>
    <ul>
        <li><a href="student_dashboard.php"><i class="fa-solid fa-house"></i><span>Home</span></a></li>
        <li><a href="student_class_page.php"><i class="fa-solid fa-users"></i><span>Classes</span></a></li>
        <li><a href="student_archived_classes.php"><i class="fa-solid fa-box-archive"></i><span>Archived</span></a></li>
        <li><a href="student_assignments.php"><i class="fa-solid fa-clipboard-list"></i><span>Assignment</span></a></li>
        <li><a href="student_messages.php" class="active"><i class="fa-regular fa-message"></i><span>Messages</span></a></li>
        <li><a href="student_performance.php"><i class="fa-solid fa-chart-pie"></i><span>Performance</span></a></li>
        <li><a href="student_resources.php"><i class="fa-solid fa-book-open"></i><span>Resources</span></a></li>
    </ul>
    <div class="logout-btn">
        <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a>
    </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileSidebar()"></div>

<!-- MAIN CONTENT -->
<div class="main-content" id="mainContent">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="navbar-left">
            <button class="mobile-menu" onclick="mobileSidebar()">
                <i class="fa-solid fa-bars"></i>
            </button>
            <img src="img/classroom logo.png" class="navbar-logo" alt="Logo">
        </div>
        <div class="navbar-right">

            <a href="profile.php" class="profile-icon" title="My Profile">

                <?php if ($hasProfileImage): ?>

                    <img
                        src="uploads/<?php echo htmlspecialchars(rawurlencode($profileImageFile), ENT_QUOTES, 'UTF-8'); ?>"
                        alt="Profile Picture"
                        class="profile-image"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                    >

                    <span class="profile-fallback" style="display:none;">
                        <i class="fa-solid fa-user"></i>
                    </span>

                <?php else: ?>

                    <span class="profile-fallback">
                        <i class="fa-solid fa-user"></i>
                    </span>

                <?php endif; ?>

            </a>

        </div>
    </div>

    <!-- MESSAGES LAYOUT -->
    <div class="messages-layout">

        <!-- LEFT: Class list -->
        <div class="msg-sidebar<?php echo $openChatOnMobile ? ' hidden-mobile' : ''; ?>" id="msgSidebar">

            <div class="msg-sidebar-header">
                <h5>Messages</h5>
            </div>

            <div class="msg-search-box">
                <i class="fa-solid fa-magnifying-glass msg-search-icon"></i>
                <input type="text" id="classSearch" class="msg-search-input" placeholder="Search messages...">
            </div>

            <div class="msg-class-list">
                <?php if (mysqli_num_rows($classesQuery) === 0): ?>
                    <div class="msg-empty-list">
                        <i class="fa-solid fa-users-slash"></i>
                        <p>You are not enrolled in any class yet.</p>
                    </div>
                <?php endif; ?>

                <?php while ($cls = mysqli_fetch_assoc($classesQuery)): ?>
                <a href="student_messages.php?class_id=<?php echo $cls['id']; ?>"
                   class="msg-class-item <?php echo ($cls['id'] == $active_class_id) ? 'active' : ''; ?>"
                   data-name="<?php echo htmlspecialchars(strtolower($cls['class_name'] . ' ' . $cls['section'])); ?>">

                    <div class="msg-class-avatar">
                        <i class="fa-solid fa-users"></i>
                    </div>

                    <div class="msg-class-info">
                        <div class="msg-class-name"><?php echo htmlspecialchars($cls['class_name']); ?></div>
                        <?php if (!empty($cls['section'])): ?>
                            <div class="msg-class-section"><?php echo htmlspecialchars($cls['section']); ?></div>
                        <?php endif; ?>
                        <div class="msg-class-preview">
                            <?php
                            $preview = trim((string)($cls['last_message'] ?? ''));
                            // old-format messages
                            $preview = preg_replace('/^\[image:.*?\]$/', '📷 Image', $preview);
                            $preview = preg_replace('/^\[file:.*?\]$/', '📎 File', $preview);

                            // new-format attachments (message text may be empty)
                            if ($preview === '' && !empty($cls['last_attachment_type'])) {
                                $preview = ($cls['last_attachment_type'] === 'image') ? '📷 Image' : '📎 File';
                            }

                            if ($preview !== '') {
                                echo htmlspecialchars(mb_substr($preview, 0, 40)) . (mb_strlen($preview) > 40 ? '...' : '');
                            } else {
                                echo '<em>No messages yet</em>';
                            }
                            ?>
                        </div>
                    </div>

                    <?php if (!empty($cls['last_message_time'])): ?>
                    <div class="msg-class-time">
                        <?php
                        $ts   = strtotime($cls['last_message_time']);
                        $diff = time() - $ts;
                        if ($diff < 60)        echo 'Just now';
                        elseif ($diff < 3600)  echo floor($diff/60) . 'm';
                        elseif ($diff < 86400) echo floor($diff/3600) . 'h';
                        else                   echo date('M d', $ts);
                        ?>
                    </div>
                    <?php endif; ?>

                </a>
                <?php endwhile; ?>
            </div>
        </div>

        <!-- RIGHT: Chat area -->
        <div class="msg-chat-area<?php echo $openChatOnMobile ? ' visible-mobile' : ''; ?>" id="msgChatArea">

            <?php if ($activeClass): ?>

            <!-- CHAT HEADER -->
            <div class="msg-chat-header">
                <button class="msg-back-btn" onclick="showClassList()" aria-label="Back to class list">
                    <i class="fa-solid fa-arrow-left"></i>
                </button>
                <div class="msg-chat-avatar">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div class="msg-chat-title">
                    <strong>
                        <?php echo htmlspecialchars($activeClass['class_name']); ?>
                        <?php if (!empty($activeClass['section'])): ?>
                            &mdash; <?php echo htmlspecialchars($activeClass['section']); ?>
                        <?php endif; ?>
                    </strong>
                    <small>
                        <i class="fa-solid fa-chalkboard-teacher me-1"></i>
                        <?php echo htmlspecialchars($activeClass['teacher_name']); ?>
                    </small>
                </div>
                <a href="student_class.php?id=<?php echo $activeClass['id']; ?>" class="msg-view-class-btn" title="View Class">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    <span class="msg-view-class-text">View Class</span>
                </a>
            </div>

            <!-- CHAT BODY -->
            <div class="msg-chat-body" id="msgChatBody">
                <div class="msg-chat-empty" id="msgChatEmpty">No messages yet. Say hello! 👋</div>
            </div>

            <!-- Pending attachment preview -->
            <div class="msg-preview" id="msgPreview" hidden></div>

            <!-- CHAT FOOTER -->
            <div class="msg-chat-footer">

                <label for="msgFileUpload" class="msg-icon-btn" title="Attach file">
                    <i class="fa-solid fa-paperclip"></i>
                </label>
                <input type="file" id="msgFileUpload"
                       accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.csv,.txt,.zip,.rar"
                       hidden>

                <label for="msgImageUpload" class="msg-icon-btn" title="Send image">
                    <i class="fa-regular fa-image"></i>
                </label>
                <input type="file" id="msgImageUpload" accept="image/*" hidden>

                <textarea id="msgInput" class="msg-textarea" placeholder="Send a message (paste a link to share it)" rows="1"></textarea>

                <button class="msg-send-btn" id="msgSendBtn" aria-label="Send">
                    <i class="fa-regular fa-paper-plane"></i>
                </button>

            </div>

            <?php else: ?>
            <div class="msg-no-chat">
                <i class="fa-regular fa-comments"></i>
                <p>Select a class to view its group chat</p>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<!-- NEW: edit / delete message logic -->
<script src="class_chat_actions.js?v=2"></script>
<!-- NEW: unread (missed message) badges -->
<script src="class_chat_unread.js?v=2"></script>
<!-- NEW: @mentions -->
<script src="class_chat_mentions.js?v=1"></script>

<script>
<?php if ($activeClass): ?>
(function () {
    const CLASS_ID  = <?php echo (int)$active_class_id; ?>;
    const FETCH_URL = 'class_chat_fetch.php';
    const SEND_URL  = 'class_chat_send.php';
    const POLL_MS   = 3000;
    const MAX_BYTES = 10 * 1024 * 1024;

    let lastId = 0, timer = null, polling = false, sending = false;
    let pendingFile = null;

    const chatBody  = document.getElementById('msgChatBody');
    const chatEmpty = document.getElementById('msgChatEmpty');
    const input     = document.getElementById('msgInput');
    const sendBtn   = document.getElementById('msgSendBtn');
    const fileInput = document.getElementById('msgFileUpload');
    const imgInput  = document.getElementById('msgImageUpload');
    const preview   = document.getElementById('msgPreview');

    // NEW: @mentions (type @ to pick someone)
    ChatMentions.init({ meId: <?php echo (int)$student_id; ?> });
    ChatMentions.attach({ input: input, classId: CLASS_ID });


    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c =>
            ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    // Escape first, then turn http(s) URLs into clickable links
    function linkify(text) {
        return esc(text).replace(/(https?:\/\/[^\s<]+)/g,
            '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
    }
    function fmtTime(raw) {
        const d = new Date(String(raw).replace(' ', 'T'));
        return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }
    function scrollBottom() { chatBody.scrollTop = chatBody.scrollHeight; }

    function imageHtml(url, alt) {
        return `<a href="${url}" target="_blank" rel="noopener">
                    <img src="${url}" class="msg-bubble-image" alt="${esc(alt)}">
                </a>`;
    }
    function fileHtml(url, name) {
        return `<a href="${url}" target="_blank" rel="noopener" download="${esc(name)}" class="msg-file-bubble">
                    <i class="fa-solid fa-paperclip me-2"></i>${esc(name)}
                </a>`;
    }

    // Build a safe profile avatar. If the filename is missing or the image
    // cannot be loaded, display the default user icon instead.
    function createAvatarHtml(profileImage) {
        const rawName = String(profileImage ?? '').trim();
        const fileName = rawName.replace(/\\/g, '/').split('/').pop();

        const defaultImages = [
            '',
            'default.png',
            'default.jpg',
            'default.jpeg',
            'default.svg',
            'null'
        ];

        if (!fileName || defaultImages.includes(fileName.toLowerCase())) {
            return `
                <span class="msg-avatar-wrap">
                    <span class="msg-avatar msg-avatar-def">
                        <i class="fa-solid fa-user"></i>
                    </span>
                </span>
            `;
        }

        const imageUrl = 'uploads/' + encodeURIComponent(fileName);

        return `
            <span class="msg-avatar-wrap">
                <img
                    src="${imageUrl}"
                    class="msg-avatar"
                    alt="User profile"
                    onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                >
                <span class="msg-avatar msg-avatar-def" style="display:none;">
                    <i class="fa-solid fa-user"></i>
                </span>
            </span>
        `;
    }

    function renderMsg(m) {
        if (chatEmpty) chatEmpty.style.display = 'none';

        const row = document.createElement('div');
        row.className = 'msg-row ' + (m.is_me ? 'me' : 'other');

        const avatar = createAvatarHtml(m.profile_image);

        let content = '';
        const msg = (m.message || '').trim();

        // New format: attachment columns
        if (m.attachment) {
            const url = 'uploads/' + encodeURI(m.attachment);
            content += (m.attachment_type === 'image')
                ? imageHtml(url, m.attachment_name)
                : fileHtml(url, m.attachment_name || 'File');
        }

        // Old format: "[image:xxx]" / "[file:xxx]" stored in the message text
        const oldImg  = msg.match(/^\[image:(.+)\]$/);
        const oldFile = msg.match(/^\[file:(.+)\]$/);

        if (oldImg) {
            content += imageHtml('uploads/' + encodeURI(oldImg[1]), 'image');
        } else if (oldFile) {
            content += fileHtml('uploads/' + encodeURI(oldFile[1]), oldFile[1].replace(/^\d+_/, ''));
        } else if (msg !== '') {
            content += `<div class="msg-bubble">${ChatMentions.render(m.message, m.mentions)}</div>`;
        }

        // NEW: deleted messages show a placeholder
        if (m.is_deleted) {
            content = '<div class="msg-bubble msg-deleted"><i class="fa-solid fa-ban"></i> This message was deleted</div>';
        }

        row.innerHTML = `
            ${m.is_me ? '' : avatar}
            <div class="msg-col">
                ${m.is_me ? '' : `<div class="msg-sender-name">${esc(m.name)}</div>`}
                ${content}
                <div class="msg-time">${fmtTime(m.created_at)}</div>
            </div>
            ${m.is_me ? avatar : ''}
        `;
        chatBody.appendChild(row);
        ChatActions.decorate(row, m);   // NEW: adds the ⋮ menu + "edited" tag
        lastId = Math.max(lastId, Number(m.id));
    }

    async function poll() {
        if (polling) return;
        polling = true;
        try {
            const r = await fetch(`${FETCH_URL}?class_id=${CLASS_ID}&after_id=${lastId}`);
            const d = await r.json();
            if (d.messages && d.messages.length > 0) {
                d.messages.forEach(renderMsg);
                scrollBottom();
                markReadIfVisible();   // NEW
            } else if (d.error) {
                console.error('chat poll error:', d.error);
            }
        } catch (e) { console.error('poll error:', e); }
        finally { polling = false; }
    }

    // CHANGED: each tick also syncs edits / deletes made by other people
    async function tick() { await poll(); await ChatActions.sync(); }

    // NEW: only count messages as "read" while this chat is actually on screen
    function chatVisible() {
        const area = document.getElementById('msgChatArea');
        return !document.hidden &&
               (window.innerWidth > 768 || area.classList.contains('visible-mobile'));
    }
    function markReadIfVisible() {
        if (lastId > 0 && chatVisible()) ChatUnread.markRead(CLASS_ID, lastId);
    }
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) markReadIfVisible();
    });

    function startPolling() {
        ChatActions.init({ classId: CLASS_ID, chatBody: chatBody, linkify: ChatMentions.render });
        tick();
        if (!timer) timer = setInterval(tick, POLL_MS);
    }

    /* ---- attachment picking ---- */
    function setPending(file, kind) {
        if (!file) return;
        if (file.size > MAX_BYTES) {
            alert('File is too large (max 10MB).');
            imgInput.value = ''; fileInput.value = '';
            return;
        }
        pendingFile = file;
        // only one attachment at a time
        if (kind === 'image') fileInput.value = ''; else imgInput.value = '';

        preview.hidden = false;
        preview.innerHTML = '';
        if (kind === 'image') {
            const img = document.createElement('img');
            img.src = URL.createObjectURL(file);
            preview.appendChild(img);
        } else {
            preview.insertAdjacentHTML('beforeend', '<i class="fa-solid fa-file"></i>');
        }
        const name = document.createElement('span');
        name.textContent = file.name;
        const x = document.createElement('button');
        x.type = 'button'; x.className = 'msg-preview-x'; x.textContent = '×';
        x.onclick = clearPending;
        preview.append(name, x);
    }
    function clearPending() {
        pendingFile = null;
        imgInput.value = ''; fileInput.value = '';
        preview.hidden = true; preview.innerHTML = '';
    }
    imgInput.addEventListener('change',  () => setPending(imgInput.files[0],  'image'));
    fileInput.addEventListener('change', () => setPending(fileInput.files[0], 'file'));

    /* ---- sending ---- */
    async function sendMessage() {
        if (sending) return;
        const text = input.value.trim();
        if (!text && !pendingFile) return;

        sending = true;
        const fd = new FormData();
        fd.append('class_id', CLASS_ID);
        fd.append('message', text);
        if (pendingFile) fd.append('attachment', pendingFile);

        input.disabled = true; sendBtn.disabled = true;
        try {
            const r = await fetch(SEND_URL, { method: 'POST', body: fd });
            const raw = await r.text();
            let d;
            try { d = JSON.parse(raw); }
            catch (e) {
                console.error('send: non-JSON response', r.status, raw);
                alert('Server error (HTTP ' + r.status + '):\n' + raw.slice(0, 300));
                return;
            }

            if (d.success) {
                input.value = ''; input.style.height = 'auto';
                clearPending();
                await poll();
            } else {
                alert(d.error || 'Send failed');
            }
        } catch (e) {
            console.error('chat send:', e);
            alert('Send failed: ' + e.message);
        } finally {
            sending = false;
            input.disabled = false; sendBtn.disabled = false; input.focus();
        }
    }

    sendBtn.addEventListener('click', sendMessage);

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    input.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 100) + 'px';
    });

    // When the on-screen keyboard opens, keep the latest message in view
    input.addEventListener('focus', function () {
        setTimeout(scrollBottom, 300);
    });

    startPolling();
})();
<?php endif; ?>

// NEW: unread badge on each class in the list
ChatUnread.onUpdate(function (u) {
    document.querySelectorAll('.msg-class-item').forEach(function (item) {
        const m = (item.getAttribute('href') || '').match(/class_id=(\d+)/);
        if (!m) return;
        const n = u.counts[m[1]] || 0;
        let b = item.querySelector('.msg-class-unread');
        item.classList.toggle('has-unread', n > 0);
        if (n > 0) {
            if (!b) {
                b = document.createElement('b');
                b.className = 'msg-class-unread';
                item.appendChild(b);
            }
            const mn = (u.mentions && u.mentions[m[1]]) || 0;
            b.textContent = (mn > 0 ? '@' : '') + (n > 99 ? '99+' : n);
            b.classList.toggle('has-mention', mn > 0);
            b.title = mn > 0 ? 'You were mentioned' : '';
        } else if (b) {
            b.remove();
        }
    });
});

// Search filter
document.getElementById('classSearch').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.msg-class-item').forEach(function(item) {
        item.style.display = item.dataset.name.includes(q) ? 'flex' : 'none';
    });
});

/* ── Mobile panel switching (class list <-> chat) ── */
function showChat() {
    document.getElementById('msgSidebar').classList.add('hidden-mobile');
    document.getElementById('msgChatArea').classList.add('visible-mobile');
}

function showClassList() {
    document.getElementById('msgSidebar').classList.remove('hidden-mobile');
    document.getElementById('msgChatArea').classList.remove('visible-mobile');
}

/* ── App sidebar (desktop collapse + mobile drawer) ── */
function toggleSidebar() {
    if (window.innerWidth > 768) {
        document.getElementById('sidebar').classList.toggle('collapsed');
        document.getElementById('mainContent').classList.toggle('expanded');
    } else {
        closeMobileSidebar();
    }
}

function mobileSidebar() {
    const sidebar = document.getElementById('sidebar');
    sidebar.classList.toggle('show');
    document.getElementById('sidebarOverlay').classList.toggle('show', sidebar.classList.contains('show'));
}

function closeMobileSidebar() {
    document.getElementById('sidebar').classList.remove('show');
    document.getElementById('sidebarOverlay').classList.remove('show');
}

window.addEventListener('resize', function() {
    if (window.innerWidth > 768) {
        closeMobileSidebar();
        document.getElementById('msgSidebar').classList.remove('hidden-mobile');
        document.getElementById('msgChatArea').classList.remove('visible-mobile');
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>