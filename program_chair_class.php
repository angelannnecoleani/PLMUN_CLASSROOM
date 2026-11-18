
<?php
session_start();
include 'database.php';
include 'auth_helpers.php';

/* =========================================================
   LOGIN AND ROLE CHECK
========================================================= */
if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'program_chair'
) {
    header("Location: login.php");
    exit();
}

$chair_id = (int) $_SESSION['user_id'];
$role = 'program_chair';

/* =========================================================
   HELPER FUNCTIONS
========================================================= */
function e($v) {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function fileIconClass($ext) {
    $ext = strtolower($ext);

    if ($ext === 'pdf') return 'fa-file-pdf';
    if (in_array($ext, ['doc', 'docx'])) return 'fa-file-word';
    if (in_array($ext, ['ppt', 'pptx'])) return 'fa-file-powerpoint';
    if (in_array($ext, ['xls', 'xlsx', 'csv'])) return 'fa-file-excel';
    if (in_array($ext, ['zip', 'rar'])) return 'fa-file-zipper';
    if (in_array($ext, ['mp4', 'mov', 'avi'])) return 'fa-file-video';

    return 'fa-file';
}

function fetchAllById($conn, $sql, $id) {
    $rows = [];

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return $rows;
    }

    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);

    $res = mysqli_stmt_get_result($stmt);

    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
    }

    mysqli_stmt_close($stmt);

    return $rows;
}

function avatarHtml($img) {
    $img = basename((string)$img);

    if (
        $img !== '' &&
        file_exists(__DIR__ . '/uploads/' . $img)
    ) {
        return '<img src="uploads/' . e($img) . '" class="pc-person-avatar" alt="">';
    }

    return '<div class="pc-person-avatar"><i class="fa-solid fa-user"></i></div>';
}

/* =========================================================
   FETCH PROGRAM CHAIR ACCOUNT
   NOTE: users.program IS NOT USED.
========================================================= */
$stmt = mysqli_prepare(
    $conn,
    "SELECT id, name, profile_image
     FROM users
     WHERE id = ?
       AND role = 'program_chair'
     LIMIT 1"
);

if (!$stmt) {
    die("Database error: " . e(mysqli_error($conn)));
}

mysqli_stmt_bind_param($stmt, "i", $chair_id);
mysqli_stmt_execute($stmt);

$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

mysqli_stmt_close($stmt);

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

/* =========================================================
   VALIDATE CLASS ID
========================================================= */
if (
    !isset($_GET['id']) ||
    !is_numeric($_GET['id']) ||
    (int)$_GET['id'] <= 0
) {
    die("Class not found.");
}

$id = (int) $_GET['id'];

/* =========================================================
   FETCH CLASS AND VERIFY PROGRAM CHAIR ACCESS

   The class program must match the program assigned
   to the logged-in Program Chair.

   Relationship:
   classes.program
       = programs.program_name

   program_chair_assignments.program_id
       = programs.id
========================================================= */
$stmt = mysqli_prepare(
    $conn,
    "SELECT
        c.*,
        p.program_name AS assigned_program,
        p.college_name AS assigned_college
     FROM classes c
     INNER JOIN programs p
        ON TRIM(c.program) = TRIM(p.program_name)
     INNER JOIN program_chair_assignments pca
        ON pca.program_id = p.id
     WHERE c.id = ?
       AND pca.user_id = ?
     LIMIT 1"
);

if (!$stmt) {
    die("Database error: " . e(mysqli_error($conn)));
}

mysqli_stmt_bind_param($stmt, "ii", $id, $chair_id);
mysqli_stmt_execute($stmt);

$class = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

mysqli_stmt_close($stmt);

if (!$class) {
    http_response_code(403);
    die("Access denied. This class is not under any program assigned to your account.");
}

/* =========================================================
   FETCH INSTRUCTOR INFORMATION
========================================================= */
$teacherName = 'No instructor assigned';
$teacherImage = '';

if (!empty($class['teacher_id'])) {

    $tid = (int) $class['teacher_id'];

    $stmt = mysqli_prepare(
        $conn,
        "SELECT name, profile_image
         FROM users
         WHERE id = ?
         LIMIT 1"
    );

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $tid);
        mysqli_stmt_execute($stmt);

        $teacher = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        mysqli_stmt_close($stmt);

        if ($teacher) {
            $teacherName = $teacher['name'] ?: $teacherName;
            $teacherImage = $teacher['profile_image'] ?? '';
        }
    }
}

/* =========================================================
   TAB VALIDATION
========================================================= */
$tab = $_GET['tab'] ?? 'stream';

if (!in_array($tab, ['stream', 'people'], true)) {
    $tab = 'stream';
}

/* =========================================================
   PROFILE IMAGE
========================================================= */
$profileFile = basename($user['profile_image'] ?? '');

$hasProfileImage =
    !empty($profileFile) &&
    !in_array(
        strtolower($profileFile),
        ['default.png', 'default.jpg', 'default.jpeg', 'null']
    ) &&
    file_exists(__DIR__ . '/uploads/' . $profileFile);

/* =========================================================
   TAB DATA
========================================================= */
$announcements = [];
$students = [];
$annAtt = [];

/* -------------------------
   STREAM DATA
------------------------- */
if ($tab === 'stream') {

    $announcements = fetchAllById(
        $conn,
        "SELECT
            a.*,
            u.name AS poster_name,
            u.profile_image AS poster_image
         FROM announcements a
         LEFT JOIN users u
            ON a.teacher_id = u.id
         WHERE a.class_id = ?
         ORDER BY a.pinned DESC, a.created_at DESC",
        $id
    );

    $rows = fetchAllById(
        $conn,
        "SELECT aa.*
         FROM announcement_attachments aa
         INNER JOIN announcements a
            ON aa.announcement_id = a.id
         WHERE a.class_id = ?
         ORDER BY aa.id ASC",
        $id
    );

    foreach ($rows as $r) {
        $annAtt[$r['announcement_id']][] = $r;
    }
}

/* -------------------------
   PEOPLE DATA
------------------------- */
if ($tab === 'people') {

    $students = fetchAllById(
        $conn,
        "SELECT
            u.id,
            u.name,
            u.email,
            u.profile_image
         FROM class_students cs
         INNER JOIN users u
            ON cs.student_id = u.id
         WHERE cs.class_id = ?
           AND cs.archived = 0
         ORDER BY u.name ASC",
        $id
    );
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo e($class['class_name']); ?></title>

    <link rel="stylesheet" href="teacher_class.css?v=5">
    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >
</head>

<body>

<!-- =====================================================
     SIDEBAR
===================================================== -->
<div class="sidebar" id="sidebar">

    <div class="sidebar-header">

        <button class="toggle-btn" onclick="toggleSidebar()">
            <i class="fa-solid fa-bars"></i>
        </button>

        <button
            class="sidebar-close-btn"
            onclick="closeMobileSidebar()"
            aria-label="Close menu"
        >
            <i class="fa-solid fa-xmark"></i>
        </button>

    </div>

    <ul>
        <li>
            <a href="program_chair_dashboard.php">
                <i class="fa-solid fa-house"></i>
                <span>Home</span>
            </a>
        </li>
    </ul>

    <div class="logout-btn">
        <a href="logout.php">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Logout</span>
        </a>
    </div>

</div>

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
    onclick="closeMobileSidebar()"
></div>

<div class="main-content" id="mainContent">

    <!-- =================================================
         TOPBAR
    ================================================== -->
    <div class="topbar">

        <div class="navbar-left">

            <button class="mobile-menu" onclick="mobileSidebar()">
                <i class="fa-solid fa-bars"></i>
            </button>

            <img
                src="img/classroom logo.png"
                class="navbar-logo"
                alt="Logo"
            >

        </div>

        <div class="navbar-right">

            <?php if (!empty($class['meet_link'])): ?>

                <a
                    href="<?php echo e($class['meet_link']); ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="pc-meet-button"
                >
                    <i class="fa-solid fa-video"></i>
                    <span>Join Meet</span>
                </a>

            <?php endif; ?>

            <a href="profile.php" class="profile-icon">

                <?php if ($hasProfileImage): ?>

                    <img
                        src="uploads/<?php echo e($profileFile); ?>"
                        alt="Profile"
                        onerror="this.style.display='none'; this.parentElement.insertAdjacentHTML('beforeend','<i class=&quot;fa-solid fa-user&quot;></i>');"
                    >

                <?php else: ?>

                    <i class="fa-solid fa-user"></i>

                <?php endif; ?>

            </a>

        </div>

    </div>

    <!-- =================================================
         CLASS NAVIGATION
    ================================================== -->
    <div class="class-nav">

        <a
            href="program_chair_class.php?id=<?php echo $id; ?>&tab=stream"
            class="<?php echo $tab === 'stream' ? 'nav-active' : ''; ?>"
        >
            Stream
        </a>

        <a href="teacher_assessment.php?id=<?php echo $id; ?>">
            Assessments
        </a>

        <a href="teacher_material.php?id=<?php echo $id; ?>">
            Materials
        </a>

        <a
            href="program_chair_class.php?id=<?php echo $id; ?>&tab=people"
            class="<?php echo $tab === 'people' ? 'nav-active' : ''; ?>"
        >
            People
        </a>

    </div>

    <div class="container py-4">

        <!-- =================================================
             CLASS BANNER
        ================================================== -->
        <div class="class-banner mb-4">

            <h2><?php echo e($class['class_name']); ?></h2>

            <h5><?php echo e($teacherName); ?></h5>

            <p>
                <?php echo e($class['section'] ?? ''); ?>
                |
                <?php echo e($class['schedule'] ?? ''); ?>
            </p>

            <?php if (!empty($class['assigned_program'])): ?>

                <small>
                    <?php echo e($class['assigned_program']); ?>
                    <?php if (!empty($class['assigned_college'])): ?>
                        | <?php echo e($class['assigned_college']); ?>
                    <?php endif; ?>
                </small>

            <?php endif; ?>

            <div class="pc-role-label">
                <i class="fa-solid fa-user-tie"></i>
                Program Chair View
            </div>

        </div>

        <div class="row g-4">

            <!-- =================================================
                 LEFT CLASS INFORMATION
            ================================================== -->
            <div class="col-lg-3">

                <div class="class-code">
                    <h6>Class Code</h6>
                    <strong><?php echo e($class['class_code']); ?></strong>
                </div>

                <div class="class-code">
                    <h6>Instructor</h6>
                    <strong><?php echo e($teacherName); ?></strong>
                </div>

                <?php if (!empty($class['room'])): ?>

                    <div class="class-code">
                        <h6>Room</h6>
                        <strong><?php echo e($class['room']); ?></strong>
                    </div>

                <?php endif; ?>

                <div class="class-code">
                    <h6>Assigned Program</h6>
                    <strong><?php echo e($class['assigned_program']); ?></strong>
                </div>

            </div>

            <!-- =================================================
                 RIGHT CONTENT
            ================================================== -->
            <div class="col-lg-9">

                <?php if ($tab === 'stream'): ?>

                    <!-- =========================================
                         ANNOUNCEMENT FORM
                    ========================================== -->
                    <div class="announce-box mb-4">

                        <form
                            action="save_announcement.php"
                            method="POST"
                            enctype="multipart/form-data"
                            class="announcement-form"
                            id="announceForm"
                        >

                            <input
                                type="hidden"
                                name="class_id"
                                value="<?php echo $id; ?>"
                            >

                            <textarea
                                class="announce-input"
                                name="content"
                                id="announceText"
                                placeholder="Announce something to this class"
                            ></textarea>

                            <input
                                type="file"
                                name="attachments[]"
                                id="attachmentsInput"
                                multiple
                                hidden
                            >

                            <div id="linksHidden"></div>
                            <div id="attachList" class="attach-list"></div>
                            <div id="attachMsg" class="attach-msg"></div>

                            <div class="announce-actions">

                                <div class="left-actions">

                                    <button
                                        type="button"
                                        class="icon-btn"
                                        onclick="document.getElementById('pickImages').click()"
                                        title="Attach images"
                                    >
                                        <i class="fa-regular fa-image"></i>
                                    </button>

                                    <button
                                        type="button"
                                        class="icon-btn"
                                        onclick="document.getElementById('pickFiles').click()"
                                        title="Attach files"
                                    >
                                        <i class="fa-solid fa-paperclip"></i>
                                    </button>

                                    <button
                                        type="button"
                                        class="icon-btn"
                                        onclick="openLinkModal()"
                                        title="Attach link"
                                    >
                                        <i class="fa-solid fa-link"></i>
                                    </button>

                                    <span class="attach-counter" id="attachCounter">
                                        0/10
                                    </span>

                                </div>

                                <button
                                    type="submit"
                                    class="send-btn"
                                    title="Post"
                                >
                                    <i class="fa-regular fa-paper-plane"></i>
                                </button>

                            </div>

                            <input
                                type="file"
                                id="pickImages"
                                accept="image/*"
                                multiple
                                hidden
                            >

                            <input
                                type="file"
                                id="pickFiles"
                                multiple
                                hidden
                            >

                        </form>

                    </div>

                    <!-- =========================================
                         ANNOUNCEMENTS
                    ========================================== -->

                    <?php if (!$announcements): ?>

                        <div class="pc-empty">
                            <i class="fa-regular fa-comment-dots"></i>
                            <h6>No announcements yet</h6>
                        </div>

                    <?php endif; ?>

                    <?php foreach ($announcements as $a):

                        $posterName = $a['poster_name'] ?: $teacherName;

                        $posterImage = basename(
                            (string)($a['poster_image'] ?? '')
                        );

                        $atts = $annAtt[$a['id']] ?? [];

                    ?>

                        <div class="announcement-card mb-3">

                            <div class="announcement-header">

                                <div class="header-left">

                                    <?php if (
                                        $posterImage !== '' &&
                                        file_exists(__DIR__ . '/uploads/' . $posterImage)
                                    ): ?>

                                        <img
                                            src="uploads/<?php echo e($posterImage); ?>"
                                            class="profile-pic"
                                            alt=""
                                        >

                                    <?php else: ?>

                                        <div class="profile-pic default-profile">
                                            <i class="fa-solid fa-user"></i>
                                        </div>

                                    <?php endif; ?>

                                    <div>

                                        <h6 class="mb-0">
                                            <?php echo e($posterName); ?>
                                        </h6>

                                        <small class="text-muted">
                                            <?php
                                            echo !empty($a['created_at'])
                                                ? date("M d, Y h:i A", strtotime($a['created_at']))
                                                : '';
                                            ?>
                                        </small>

                                    </div>

                                </div>

                                <div class="dropdown">

                                    <button
                                        class="option-btn"
                                        type="button"
                                        data-bs-toggle="dropdown"
                                        aria-expanded="false"
                                    >
                                        <i class="fa-solid fa-ellipsis-vertical"></i>
                                    </button>

                                    <ul class="dropdown-menu dropdown-menu-end">

                                        <li>

                                            <a
                                                class="dropdown-item"
                                                href="pin_announcement.php?id=<?php echo (int)$a['id']; ?>"
                                            >

                                                <?php if (!empty($a['pinned'])): ?>

                                                    <i class="fa-solid fa-thumbtack me-2 text-warning"></i>
                                                    Unpin

                                                <?php else: ?>

                                                    <i class="fa-solid fa-thumbtack me-2"></i>
                                                    Pin

                                                <?php endif; ?>

                                            </a>

                                        </li>

                                        <li>

                                            <a
                                                class="dropdown-item"
                                                href="edit_announcement.php?id=<?php echo (int)$a['id']; ?>"
                                            >
                                                <i class="fa-solid fa-pen me-2"></i>
                                                Edit
                                            </a>

                                        </li>

                                        <li>
                                            <hr class="dropdown-divider">
                                        </li>

                                        <li>

                                            <a
                                                class="dropdown-item text-danger"
                                                href="delete_announcement.php?id=<?php echo (int)$a['id']; ?>"
                                                onclick="return confirm('Delete this announcement?')"
                                            >
                                                <i class="fa-solid fa-trash me-2"></i>
                                                Delete
                                            </a>

                                        </li>

                                    </ul>

                                </div>

                            </div>

                            <!-- ANNOUNCEMENT CONTENT -->
                            <div class="announcement-content mt-3">

                                <?php if (!empty($a['pinned'])): ?>

                                    <span class="pinned-label">
                                        <i class="fa-solid fa-thumbtack me-1"></i>
                                        Pinned
                                    </span>

                                <?php endif; ?>

                                <?php if (trim((string)($a['content'] ?? '')) !== ''): ?>

                                    <p>
                                        <?php echo nl2br(e($a['content'])); ?>
                                    </p>

                                <?php endif; ?>

                                <?php if (!empty($a['link'])): ?>

                                    <a
                                        href="<?php echo e($a['link']); ?>"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <?php echo e($a['link']); ?>
                                    </a>

                                <?php endif; ?>

                                <?php if (!empty($a['image'])): ?>

                                    <img
                                        src="uploads/<?php echo e(basename($a['image'])); ?>"
                                        class="announcement-image"
                                        alt="Announcement image"
                                    >

                                <?php endif; ?>

                                <?php
                                $aImgs = [];
                                $aFiles = [];
                                $aLinks = [];

                                foreach ($atts as $att) {

                                    if ($att['type'] === 'image') {
                                        $aImgs[] = $att;
                                    } elseif ($att['type'] === 'file') {
                                        $aFiles[] = $att;
                                    } elseif ($att['type'] === 'link') {
                                        $aLinks[] = $att;
                                    }

                                }
                                ?>

                                <?php if ($atts): ?>

                                    <div class="post-attachments">

                                        <!-- IMAGES -->
                                        <?php if ($aImgs): ?>

                                            <div class="post-images">

                                                <?php foreach ($aImgs as $att): ?>

                                                    <a
                                                        href="uploads/<?php echo e(basename($att['path'])); ?>"
                                                        target="_blank"
                                                    >

                                                        <img
                                                            src="uploads/<?php echo e(basename($att['path'])); ?>"
                                                            alt="<?php echo e($att['original_name'] ?? ''); ?>"
                                                        >

                                                    </a>

                                                <?php endforeach; ?>

                                            </div>

                                        <?php endif; ?>

                                        <!-- FILES -->
                                        <?php foreach ($aFiles as $att):

                                            $fname = $att['original_name'] ?: basename($att['path']);

                                            $fext = pathinfo(
                                                $att['path'],
                                                PATHINFO_EXTENSION
                                            );

                                        ?>

                                            <a
                                                class="post-file"
                                                href="uploads/<?php echo e(basename($att['path'])); ?>"
                                                target="_blank"
                                                download="<?php echo e($fname); ?>"
                                            >

                                                <i class="fa-solid <?php echo fileIconClass($fext); ?>"></i>

                                                <span>
                                                    <?php echo e($fname); ?>
                                                </span>

                                            </a>

                                        <?php endforeach; ?>

                                        <!-- LINKS -->
                                        <?php foreach ($aLinks as $att): ?>

                                            <a
                                                class="post-link"
                                                href="<?php echo e($att['path']); ?>"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >

                                                <i class="fa-solid fa-link"></i>

                                                <span>
                                                    <?php echo e($att['path']); ?>
                                                </span>

                                            </a>

                                        <?php endforeach; ?>

                                    </div>

                                <?php endif; ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <!-- =========================================
                         PEOPLE TAB
                    ========================================== -->

                    <div class="pc-section-heading">
                        <i class="fa-solid fa-chalkboard-user"></i>
                        Instructor
                    </div>

                    <div class="people-section mb-4">

                        <div class="pc-person-row">

                            <?php echo avatarHtml($teacherImage); ?>

                            <div class="pc-person-info">
                                <strong><?php echo e($teacherName); ?></strong>
                            </div>

                        </div>

                    </div>

                    <div class="pc-section-heading">
                        <i class="fa-solid fa-users"></i>
                        Students (<?php echo count($students); ?>)
                    </div>

                    <div class="people-section">

                        <?php if (!$students): ?>

                            <div class="people-empty">
                                No students enrolled yet.
                            </div>

                        <?php endif; ?>

                        <?php foreach ($students as $s): ?>

                            <div class="pc-person-row">

                                <?php echo avatarHtml($s['profile_image']); ?>

                                <div class="pc-person-info">

                                    <strong>
                                        <?php echo e($s['name']); ?>
                                    </strong>

                                    <small>
                                        <?php echo e($s['email'] ?? ''); ?>
                                    </small>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </div>

</div>

<!-- =====================================================
     ANNOUNCEMENT LINK MODAL
===================================================== -->
<div
    class="modal fade"
    id="linkModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content meet-modal-content">

            <div class="modal-header">

                <h5 class="modal-title fw-bold">
                    Add link
                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>

            <div class="modal-body">

                <input
                    type="text"
                    id="announceLinkInput"
                    class="meet-link-input"
                    placeholder="https://example.com"
                    autocomplete="off"
                >

                <div
                    id="announceLinkError"
                    class="meet-link-error"
                ></div>

            </div>

            <div class="modal-footer border-0">

                <button
                    type="button"
                    class="btn-modal-cancel"
                    data-bs-dismiss="modal"
                >
                    Cancel
                </button>

                <button
                    type="button"
                    class="btn-modal-save"
                    onclick="saveAnnounceLink()"
                >
                    Add link
                </button>

            </div>

        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

/* =====================================================
   SIDEBAR
===================================================== */

function toggleSidebar() {

    if (window.innerWidth > 768) {

        document.getElementById('sidebar').classList.toggle('collapsed');

        document.getElementById('mainContent').classList.toggle('expanded');

    }

}

function mobileSidebar() {

    document.getElementById('sidebar').classList.toggle('show');

    document.getElementById('sidebarOverlay').classList.toggle('show');

    document.body.classList.toggle('no-scroll');

}

function closeMobileSidebar() {

    document.getElementById('sidebar').classList.remove('show');

    document.getElementById('sidebarOverlay').classList.remove('show');

    document.body.classList.remove('no-scroll');

}

window.addEventListener('resize', function () {

    if (window.innerWidth > 768) {
        closeMobileSidebar();
    }

});

</script>

<?php if ($tab === 'stream'): ?>

<script>

/* =====================================================
   ANNOUNCEMENT ATTACHMENTS
   Maximum 10 items: images, files and links combined
===================================================== */

(function () {

    const MAX_ITEMS = 10;
    const MAX_MB = 10;

    const IMG_EXT = [
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp'
    ];

    const form = document.getElementById('announceForm');

    const textArea = document.getElementById('announceText');

    const realInput = document.getElementById('attachmentsInput');

    const linksHidden = document.getElementById('linksHidden');

    const listEl = document.getElementById('attachList');

    const msgEl = document.getElementById('attachMsg');

    const counterEl = document.getElementById('attachCounter');

    const pickImages = document.getElementById('pickImages');

    const pickFiles = document.getElementById('pickFiles');

    let items = [];

    let msgTimer = null;

    function total() {
        return items.length;
    }

    function ext(name) {
        return (name.split('.').pop() || '').toLowerCase();
    }

    function showMsg(text) {

        msgEl.textContent = text;

        clearTimeout(msgTimer);

        msgTimer = setTimeout(function () {
            msgEl.textContent = '';
        }, 4000);

    }

    function iconFor(e) {

        if (e === 'pdf') return 'fa-file-pdf';

        if (['doc', 'docx'].includes(e)) return 'fa-file-word';

        if (['ppt', 'pptx'].includes(e)) return 'fa-file-powerpoint';

        if (['xls', 'xlsx', 'csv'].includes(e)) return 'fa-file-excel';

        if (['zip', 'rar'].includes(e)) return 'fa-file-zipper';

        return 'fa-file';

    }

    function syncFileInput() {

        const dt = new DataTransfer();

        items.forEach(function (it) {

            if (it.kind === 'file') {
                dt.items.add(it.file);
            }

        });

        realInput.files = dt.files;

    }

    function syncLinkInputs() {

        linksHidden.innerHTML = '';

        items.forEach(function (it) {

            if (it.kind !== 'link') return;

            const h = document.createElement('input');

            h.type = 'hidden';

            h.name = 'links[]';

            h.value = it.url;

            linksHidden.appendChild(h);

        });

    }

    function render() {

        listEl.innerHTML = '';

        items.forEach(function (it, index) {

            const chip = document.createElement('div');

            chip.className = 'attach-chip';

            let label;

            if (
                it.kind === 'file' &&
                IMG_EXT.includes(ext(it.file.name))
            ) {

                const img = document.createElement('img');

                img.className = 'attach-thumb';

                img.src = it.url;

                img.alt = '';

                chip.appendChild(img);

                label = it.file.name;

            } else {

                const i = document.createElement('i');

                i.className =
                    'fa-solid type-icon ' +
                    (
                        it.kind === 'link'
                            ? 'fa-link'
                            : iconFor(ext(it.file.name))
                    );

                chip.appendChild(i);

                label = it.kind === 'link'
                    ? it.url
                    : it.file.name;

            }

            const name = document.createElement('span');

            name.className = 'attach-chip-name';

            name.textContent = label;

            name.title = label;

            chip.appendChild(name);

            const rm = document.createElement('button');

            rm.type = 'button';

            rm.className = 'attach-remove';

            rm.setAttribute('aria-label', 'Remove');

            rm.innerHTML = '&times;';

            rm.addEventListener('click', function () {
                removeItem(index);
            });

            chip.appendChild(rm);

            listEl.appendChild(chip);

        });

        counterEl.textContent = total() + '/' + MAX_ITEMS;

        counterEl.classList.toggle(
            'full',
            total() >= MAX_ITEMS
        );

        syncFileInput();

        syncLinkInputs();

    }

    function removeItem(index) {

        const it = items[index];

        if (it && it.kind === 'file') {
            URL.revokeObjectURL(it.url);
        }

        items.splice(index, 1);

        render();

    }

    function addFiles(fileList) {

        let limitHit = false;

        Array.from(fileList).forEach(function (f) {

            if (total() >= MAX_ITEMS) {
                limitHit = true;
                return;
            }

            if (f.size > MAX_MB * 1024 * 1024) {

                showMsg(
                    '"' + f.name + '" is larger than ' +
                    MAX_MB + ' MB and was skipped.'
                );

                return;

            }

            const duplicate = items.some(function (it) {

                return (
                    it.kind === 'file' &&
                    it.file.name === f.name &&
                    it.file.size === f.size &&
                    it.file.lastModified === f.lastModified
                );

            });

            if (duplicate) return;

            items.push({
                kind: 'file',
                file: f,
                url: URL.createObjectURL(f)
            });

        });

        if (limitHit) {

            showMsg(
                'You can attach up to ' + MAX_ITEMS +
                ' items (images, files and links combined).'
            );

        }

        render();

    }

    pickImages.addEventListener('change', function () {

        addFiles(pickImages.files);

        pickImages.value = '';

    });

    pickFiles.addEventListener('change', function () {

        addFiles(pickFiles.files);

        pickFiles.value = '';

    });

    /* =================================================
       LINK MODAL
    ================================================= */

    window.openLinkModal = function () {

        document.getElementById('announceLinkInput').value = '';

        document.getElementById('announceLinkError').textContent = '';

        if (total() >= MAX_ITEMS) {

            showMsg(
                'You can attach up to ' + MAX_ITEMS +
                ' items (images, files and links combined).'
            );

            return;

        }

        bootstrap.Modal
            .getOrCreateInstance(
                document.getElementById('linkModal')
            )
            .show();

    };

    window.saveAnnounceLink = function () {

        const input = document.getElementById('announceLinkInput');

        const errorBox = document.getElementById('announceLinkError');

        let link = input.value.trim();

        if (!link) {

            errorBox.textContent = 'Please enter a link.';

            return;

        }

        if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(link)) {
            link = 'https://' + link;
        }

        let parsed;

        try {

            parsed = new URL(link);

        } catch (e) {

            errorBox.textContent = 'Please enter a valid link.';

            return;

        }

        if (
            parsed.protocol !== 'http:' &&
            parsed.protocol !== 'https:'
        ) {

            errorBox.textContent =
                'Only http:// and https:// links are allowed.';

            return;

        }

        if (total() >= MAX_ITEMS) {

            errorBox.textContent =
                'You can attach up to ' + MAX_ITEMS + ' items.';

            return;

        }

        if (
            items.some(function (it) {
                return it.kind === 'link' && it.url === link;
            })
        ) {

            errorBox.textContent = 'This link is already attached.';

            return;

        }

        items.push({
            kind: 'link',
            url: link
        });

        render();

        bootstrap.Modal
            .getOrCreateInstance(
                document.getElementById('linkModal')
            )
            .hide();

    };

    document
        .getElementById('announceLinkInput')
        .addEventListener('keydown', function (e) {

            if (e.key === 'Enter') {

                e.preventDefault();

                window.saveAnnounceLink();

            }

        });

    document
        .getElementById('linkModal')
        .addEventListener('shown.bs.modal', function () {

            document
                .getElementById('announceLinkInput')
                .focus();

        });

    /* =================================================
       PREVENT EMPTY ANNOUNCEMENTS
    ================================================= */

    form.addEventListener('submit', function (e) {

        if (
            textArea.value.trim() === '' &&
            total() === 0
        ) {

            e.preventDefault();

            showMsg(
                'Write something or attach an image, file or link first.'
            );

        }

    });

    render();

})();

</script>

<?php endif; ?>

</body>
</html>
