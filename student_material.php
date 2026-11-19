
<?php
session_start();

require_once 'database.php';
require_once 'viewer_helper.php';

/* =========================================
   LOGIN AND ROLE VALIDATION
========================================= */

if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'student'
) {
    header("Location: login.php");
    exit;
}

$student_id = (int) $_SESSION['user_id'];

if (!isset($_GET['id']) || !ctype_digit((string)$_GET['id'])) {
    die("Class not found");
}

$class_id = (int) $_GET['id'];

/* =========================================
   HELPER FUNCTIONS
========================================= */

function materialEscape($value) {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function materialProfileImage($filename) {

    $filename = trim((string)($filename ?? ''));

    $defaultImages = [
        '',
        'default.png',
        'default.jpg',
        'default.jpeg',
        'default.svg',
        'null'
    ];

    if (in_array(strtolower($filename), $defaultImages, true)) {
        return null;
    }

    $safeName = basename($filename);

    $path = __DIR__ . '/uploads/' . $safeName;

    if (
        $safeName !== '' &&
        is_file($path) &&
        is_readable($path)
    ) {
        return $safeName;
    }

    return null;
}

function material_icon_for($filename) {

    $ext = strtolower(
        pathinfo((string)$filename, PATHINFO_EXTENSION)
    );

    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
        return 'fa-file-image';
    }

    if ($ext === 'pdf') return 'fa-file-pdf';

    if (in_array($ext, ['doc', 'docx'])) {
        return 'fa-file-word';
    }

    if (in_array($ext, ['ppt', 'pptx'])) {
        return 'fa-file-powerpoint';
    }

    if (in_array($ext, ['xls', 'xlsx'])) {
        return 'fa-file-excel';
    }

    if (in_array($ext, ['mp4', 'mov', 'avi'])) {
        return 'fa-file-video';
    }

    return 'fa-file';
}

/* =========================================
   GET CURRENT STUDENT
========================================= */

$stmt = mysqli_prepare(
    $conn,
    "SELECT id, name, profile_image
     FROM users
     WHERE id = ?
       AND role = 'student'
     LIMIT 1"
);

if (!$stmt) {
    die("Unable to load student information.");
}

mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);

$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

mysqli_stmt_close($stmt);

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit;
}

$studentProfileImage = materialProfileImage(
    $user['profile_image'] ?? ''
);

/* =========================================
   CHECK ACTIVE CLASS ENROLLMENT
========================================= */

$stmt = mysqli_prepare(
    $conn,
    "SELECT id
     FROM class_students
     WHERE class_id = ?
       AND student_id = ?
       AND (archived = 0 OR archived IS NULL)
     LIMIT 1"
);

if (!$stmt) {
    die("Unable to verify class enrollment.");
}

mysqli_stmt_bind_param($stmt, "ii", $class_id, $student_id);
mysqli_stmt_execute($stmt);

$isEnrolled = mysqli_num_rows(
    mysqli_stmt_get_result($stmt)
) > 0;

mysqli_stmt_close($stmt);

if (!$isEnrolled) {
    http_response_code(403);
    die("You are not enrolled in this class.");
}

/* =========================================
   GET CLASS INFORMATION
========================================= */

$stmt = mysqli_prepare(
    $conn,
    "SELECT
        c.*,
        u.name AS teacher_name,
        u.profile_image AS teacher_profile_image
     FROM classes c
     LEFT JOIN users u
        ON c.teacher_id = u.id
     WHERE c.id = ?
     LIMIT 1"
);

if (!$stmt) {
    die("Unable to load class information.");
}

mysqli_stmt_bind_param($stmt, "i", $class_id);
mysqli_stmt_execute($stmt);

$class = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

mysqli_stmt_close($stmt);

if (!$class) {
    die("Class not found.");
}

/* =========================================
   PRIMARY TEACHER INFORMATION
========================================= */

$teacher = null;

$teacher_id = (int)($class['teacher_id'] ?? 0);

if ($teacher_id > 0) {

    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, name, profile_image
         FROM users
         WHERE id = ?
         LIMIT 1"
    );

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $teacher_id);
        mysqli_stmt_execute($stmt);

        $teacher = mysqli_fetch_assoc(
            mysqli_stmt_get_result($stmt)
        );

        mysqli_stmt_close($stmt);
    }
}

$teacherName = trim((string)($teacher['name'] ?? ''));

if ($teacherName === '') {
    $teacherName = 'Instructor';
}

$teacherProfileImage = materialProfileImage(
    $teacher['profile_image'] ?? ''
);

/* =========================================
   CHECK MATERIALS CREATED_BY COLUMN
========================================= */

$hasMaterialCreatedBy = false;

$columnCheck = mysqli_query(
    $conn,
    "SHOW COLUMNS FROM materials LIKE 'created_by'"
);

if ($columnCheck && mysqli_num_rows($columnCheck) > 0) {
    $hasMaterialCreatedBy = true;
}

if ($columnCheck) {
    mysqli_free_result($columnCheck);
}

/* =========================================
   GET MATERIALS AND ACTUAL AUTHORS
========================================= */

if ($hasMaterialCreatedBy) {

    $materialsSQL = "
        SELECT
            m.*,
            COALESCE(
                NULLIF(author.name, ''),
                NULLIF(primary_teacher.name, ''),
                'Instructor'
            ) AS author_name,
            COALESCE(
                author.profile_image,
                primary_teacher.profile_image
            ) AS author_profile_image
        FROM materials m
        LEFT JOIN classes c
            ON m.class_id = c.id
        LEFT JOIN users author
            ON m.created_by = author.id
        LEFT JOIN users primary_teacher
            ON c.teacher_id = primary_teacher.id
        WHERE m.class_id = ?
        ORDER BY m.id DESC
    ";

} else {

    $materialsSQL = "
        SELECT
            m.*,
            COALESCE(
                NULLIF(primary_teacher.name, ''),
                'Instructor'
            ) AS author_name,
            primary_teacher.profile_image AS author_profile_image
        FROM materials m
        LEFT JOIN classes c
            ON m.class_id = c.id
        LEFT JOIN users primary_teacher
            ON c.teacher_id = primary_teacher.id
        WHERE m.class_id = ?
        ORDER BY m.id DESC
    ";
}

$stmt = mysqli_prepare($conn, $materialsSQL);

if (!$stmt) {
    die("Unable to load materials.");
}

mysqli_stmt_bind_param($stmt, "i", $class_id);
mysqli_stmt_execute($stmt);

$materialsResult = mysqli_stmt_get_result($stmt);

$materials = [];

while ($row = mysqli_fetch_assoc($materialsResult)) {

    $row['author_profile_image_file'] = materialProfileImage(
        $row['author_profile_image'] ?? ''
    );

    $materials[] = $row;
}

mysqli_stmt_close($stmt);

/* =========================================
   GET MATERIAL ATTACHMENTS
========================================= */

$attachmentsByMaterial = [];

if (!empty($materials)) {

    $materialIds = array_map(
        fn($m) => (int)$m['id'],
        $materials
    );

    $placeholders = implode(
        ',',
        array_fill(0, count($materialIds), '?')
    );

    $attachmentSQL = "
        SELECT *
        FROM material_attachments
        WHERE material_id IN ($placeholders)
        ORDER BY id ASC
    ";

    $attachStmt = mysqli_prepare($conn, $attachmentSQL);

    if ($attachStmt) {

        $types = str_repeat('i', count($materialIds));

        mysqli_stmt_bind_param(
            $attachStmt,
            $types,
            ...$materialIds
        );

        mysqli_stmt_execute($attachStmt);

        $attachResult = mysqli_stmt_get_result($attachStmt);

        while ($attachment = mysqli_fetch_assoc($attachResult)) {

            $materialAttachmentId = (int)$attachment['material_id'];

            $attachmentsByMaterial[$materialAttachmentId][] = $attachment;
        }

        mysqli_stmt_close($attachStmt);
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        Materials – <?php echo materialEscape($class['class_name']); ?>
    </title>

    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <link rel="stylesheet" href="student_class.css">
    <link rel="stylesheet" href="student_material.css">

</head>

<body>

<!-- SIDEBAR OVERLAY -->

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- SIDEBAR -->

<div class="sidebar" id="sidebar">

    <div class="sidebar-header">

        <button
            class="toggle-btn"
            onclick="toggleSidebar()"
            aria-label="Toggle menu"
        >
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
            <a href="student_dashboard.php">
                <i class="fa-solid fa-house"></i>
                <span>Home</span>
            </a>
        </li>

        <li>
            <a href="student_class_page.php" class="active">
                <i class="fa-solid fa-users"></i>
                <span>Classes</span>
            </a>
        </li>

        <li>
            <a href="student_archived_classes.php">
                <i class="fa-solid fa-box-archive"></i>
                <span>Archived</span>
            </a>
        </li>

        <li>
            <a href="student_assignments.php">
                <i class="fa-solid fa-clipboard-list"></i>
                <span>Assignments</span>
            </a>
        </li>

        <li>
            <a href="student_messages.php">
                <i class="fa-regular fa-message"></i>
                <span>Messages</span>
            </a>
        </li>

        <li>
            <a href="student_performance.php">
                <i class="fa-solid fa-chart-pie"></i>
                <span>Performance</span>
            </a>
        </li>

        <li>
            <a href="student_resources.php">
                <i class="fa-solid fa-book-open"></i>
                <span>Resources</span>
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


<!-- MAIN CONTENT -->

<div class="main-content" id="mainContent">

    <!-- TOPBAR -->

    <div class="topbar">

        <div class="navbar-left">

            <button
                class="mobile-menu"
                onclick="mobileSidebar()"
                aria-label="Open menu"
                aria-expanded="false"
            >
                <i class="fa-solid fa-bars"></i>
            </button>

            <img
                src="img/classroom logo.png"
                class="navbar-logo"
                alt="Logo"
            >

        </div>

        <div class="navbar-right">

            <a href="profile.php" class="profile-icon" title="My Profile">

                <?php if ($studentProfileImage): ?>

                    <img
                        src="uploads/<?php echo materialEscape(rawurlencode($studentProfileImage)); ?>"
                        alt="Profile"
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


    <!-- CLASS NAVIGATION -->

    <div class="class-nav">

        <a href="student_class.php?id=<?php echo $class_id; ?>">
            Stream
        </a>

        <a href="student_assessment.php?id=<?php echo $class_id; ?>">
            Assignments
        </a>

        <a
            href="student_material.php?id=<?php echo $class_id; ?>"
            class="nav-active"
        >
            Materials
        </a>

        <a href="student_people.php?id=<?php echo $class_id; ?>">
            People
        </a>

    </div>


    <!-- MATERIALS CONTENT -->

    <div class="materials-page">

        <?php if (count($materials) === 0): ?>

            <div class="empty-state">

                <i class="fa-solid fa-book-open"></i>

                <p>No materials have been posted yet.</p>

            </div>

        <?php else: ?>

            <div class="topic-block">

                <div class="topic-header-bar">

                    <div class="topic-header-left">

                        <button
                            class="topic-collapse-btn"
                            onclick="toggleTopic('topic-main')"
                            aria-label="Collapse materials"
                        >
                            <i class="fa-solid fa-chevron-down"></i>
                        </button>

                        <span class="topic-name">
                            Materials
                        </span>

                    </div>

                </div>


                <div class="topic-body" id="topic-main">

                    <?php foreach ($materials as $material): ?>

                        <?php

                        $attachments = $attachmentsByMaterial[
                            $material['id']
                        ] ?? [];

                        /* LEGACY SINGLE FILE AND LINK */

                        if (!empty($material['file'])) {

                            $attachments[] = [
                                'type' => 'file',
                                'value' => $material['file']
                            ];
                        }

                        if (!empty($material['link'])) {

                            $attachments[] = [
                                'type' => 'link',
                                'value' => $material['link']
                            ];
                        }

                        /* LEAD ICON */

                        $leadIcon = 'fa-file';

                        foreach ($attachments as $a) {

                            if (($a['type'] ?? '') === 'file') {

                                $leadIcon = material_icon_for(
                                    $a['value'] ?? ''
                                );

                                break;
                            }

                            if (($a['type'] ?? '') === 'link') {
                                $leadIcon = 'fa-link';
                            }
                        }

                        /* MODAL ATTACHMENT PAYLOAD */

                        $payloadAttachments = [];

                        foreach ($attachments as $a) {

                            $attachmentType = $a['type'] ?? '';
                            $attachmentValue = $a['value'] ?? '';

                            if ($attachmentType === 'file') {

                                $safeFileName = basename($attachmentValue);

                                $v = get_viewer_link(
                                    'uploads/' . $safeFileName
                                );

                                $payloadAttachments[] = [
                                    'type' => 'file',
                                    'value' => $safeFileName,
                                    'url' => $v['url'],
                                    'target' => $v['target']
                                ];

                            } elseif ($attachmentType === 'link') {

                                $payloadAttachments[] = [
                                    'type' => 'link',
                                    'value' => $attachmentValue
                                ];
                            }
                        }

                        $createdAt = $material['created_at'] ?? '';

                        $postedDate = $createdAt
                            ? date("M d, Y · g:i A", strtotime($createdAt))
                            : '';

                        $payload = [
                            'title' => $material['title'] ?? '',
                            'description' => $material['description'] ?? '',
                            'posted' => $postedDate,
                            'author_name' => $material['author_name'] ?? 'Instructor',
                            'author_image' => $material['author_profile_image_file'] ?? '',
                            'attachments' => $payloadAttachments
                        ];

                        $payloadJson = htmlspecialchars(
                            json_encode(
                                $payload,
                                JSON_HEX_TAG |
                                JSON_HEX_APOS |
                                JSON_HEX_QUOT |
                                JSON_HEX_AMP
                            ),
                            ENT_QUOTES,
                            'UTF-8'
                        );

                        ?>

                        <div class="material-item">

                            <div class="material-top-row">

                                <div
                                    class="material-title-wrap material-open"
                                    role="button"
                                    tabindex="0"
                                    data-material="<?php echo $payloadJson; ?>"
                                >

                                    <div class="material-icon">

                                        <i class="fa-solid <?php echo materialEscape($leadIcon); ?>"></i>

                                    </div>

                                    <div>

                                        <span class="material-title">
                                            <?php echo materialEscape($material['title'] ?? 'Untitled Material'); ?>
                                        </span>

                                        <div class="material-meta">

                                            Posted
                                            <?php
                                            echo $createdAt
                                                ? materialEscape(date("M d, Y", strtotime($createdAt)))
                                                : 'Unknown date';
                                            ?>

                                            <?php if (count($attachments) > 1): ?>

                                                &middot;
                                                <?php echo count($attachments); ?>
                                                attachments

                                            <?php endif; ?>

                                        </div>

                                    </div>

                                </div>

                            </div>


                            <?php if (
                                !empty($material['description']) ||
                                count($attachments) > 0
                            ): ?>

                                <div class="material-body">

                                    <?php if (!empty($material['description'])): ?>

                                        <p class="material-description">
                                            <?php
                                            echo nl2br(
                                                materialEscape($material['description'])
                                            );
                                            ?>
                                        </p>

                                    <?php endif; ?>


                                    <?php foreach ($attachments as $a): ?>

                                        <?php

                                        $attachmentType = $a['type'] ?? '';
                                        $attachmentValue = $a['value'] ?? '';

                                        ?>

                                        <?php if ($attachmentType === 'file'): ?>

                                            <?php

                                            $safeFileName = basename($attachmentValue);

                                            $view = get_viewer_link(
                                                'uploads/' . $safeFileName
                                            );

                                            ?>

                                            <a
                                                href="<?php echo materialEscape($view['url']); ?>"
                                                class="material-attachment"
                                                target="<?php echo materialEscape($view['target']); ?>"
                                                rel="noopener noreferrer"
                                                title="<?php echo materialEscape($safeFileName); ?>"
                                            >

                                                <i class="fa-solid <?php echo material_icon_for($safeFileName); ?> me-2"></i>

                                                <?php echo materialEscape($safeFileName); ?>

                                            </a>

                                        <?php elseif ($attachmentType === 'link'): ?>

                                            <?php

                                            $validLink = filter_var(
                                                $attachmentValue,
                                                FILTER_VALIDATE_URL
                                            );

                                            $linkScheme = strtolower(
                                                parse_url($attachmentValue, PHP_URL_SCHEME) ?? ''
                                            );

                                            ?>

                                            <?php if ($validLink && in_array($linkScheme, ['http', 'https'], true)): ?>

                                                <a
                                                    href="<?php echo materialEscape($attachmentValue); ?>"
                                                    class="material-attachment"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    title="<?php echo materialEscape($attachmentValue); ?>"
                                                >

                                                    <i class="fa-solid fa-link me-2"></i>

                                                    <?php echo materialEscape($attachmentValue); ?>

                                                </a>

                                            <?php endif; ?>

                                        <?php endif; ?>

                                    <?php endforeach; ?>

                                </div>

                            <?php endif; ?>

                        </div>

                    <?php endforeach; ?>

                </div>

            </div>

        <?php endif; ?>

    </div>

</div>


<!-- MATERIAL DETAIL MODAL -->

<div
    class="modal fade material-detail-modal"
    id="materialDetailModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content material-modal-content">


            <!-- MODAL HEADER -->

            <div class="material-modal-header">

                <div class="material-title-area">

                    <div class="material-title-icon">

                        <i class="fa-solid fa-book-open"></i>

                    </div>

                    <h5
                        class="material-modal-title"
                        id="mdTitle"
                    ></h5>

                </div>

                <button
                    type="button"
                    class="material-close-btn"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                >

                    <i class="fa-solid fa-xmark"></i>

                </button>

            </div>


            <!-- MODAL BODY -->

            <div class="material-modal-body">


                <!-- MATERIAL AUTHOR -->

                <div class="material-teacher-row">

                    <div class="material-teacher-info">

                        <img
                            id="mdAuthorImage"
                            src=""
                            class="material-teacher-avatar"
                            alt="Instructor"
                            style="display:none;"
                        >

                        <div
                            id="mdAuthorFallback"
                            class="material-teacher-avatar material-avatar-default"
                        >

                            <i class="fa-solid fa-user"></i>

                        </div>

                        <div class="material-teacher-details">

                            <strong id="mdAuthorName">
                                Instructor
                            </strong>

                            <span id="mdPosted"></span>

                        </div>

                    </div>

                    <a
                        href="#"
                        class="material-bookmark-btn"
                        id="mdBookmark"
                        aria-label="Bookmark"
                    >

                        <i class="fa-regular fa-bookmark"></i>

                    </a>

                </div>


                <div class="material-divider"></div>


                <!-- DESCRIPTION -->

                <div
                    class="material-caption"
                    id="mdCaption"
                ></div>


                <!-- LINKS -->

                <div id="mdLinks"></div>


                <!-- FILES -->

                <div
                    class="material-file-grid"
                    id="mdFiles"
                ></div>


            </div>

        </div>

    </div>

</div>


<!-- BOOTSTRAP -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


<script>

/* =========================================
   SIDEBAR
========================================= */

const sidebarEl = document.getElementById('sidebar');
const mainEl = document.getElementById('mainContent');
const overlayEl = document.getElementById('sidebarOverlay');
const menuBtn = document.querySelector('.mobile-menu');

function isMobile() {
    return window.innerWidth <= 768;
}

function toggleSidebar() {

    if (isMobile()) {
        mobileSidebar();
        return;
    }

    sidebarEl.classList.toggle('collapsed');
    mainEl.classList.toggle('expanded');
}

function mobileSidebar() {

    const open = sidebarEl.classList.toggle('show');

    overlayEl.classList.toggle('show', open);

    document.body.classList.toggle('no-scroll', open);

    menuBtn?.setAttribute('aria-expanded', String(open));
}

function closeMobileSidebar() {

    sidebarEl.classList.remove('show');

    overlayEl.classList.remove('show');

    document.body.classList.remove('no-scroll');

    menuBtn?.setAttribute('aria-expanded', 'false');
}

document.querySelectorAll('.sidebar a').forEach(a => {

    a.addEventListener('click', () => {

        if (isMobile()) {
            closeMobileSidebar();
        }

    });

});

overlayEl.addEventListener('click', closeMobileSidebar);

document.addEventListener('keydown', e => {

    if (e.key === 'Escape') {
        closeMobileSidebar();
    }

});

window.addEventListener('resize', () => {

    if (!isMobile()) {
        closeMobileSidebar();
    }

});


/* =========================================
   COLLAPSE MATERIAL TOPIC
========================================= */

function toggleTopic(id) {

    const body = document.getElementById(id);

    if (!body) return;

    const icon = body.previousElementSibling
        .querySelector('.topic-collapse-btn i');

    body.classList.toggle('collapsed');

    icon.classList.toggle('fa-chevron-down');

    icon.classList.toggle('fa-chevron-right');
}


/* =========================================
   MATERIAL DETAIL MODAL
========================================= */

(function(){

    const modalEl = document.getElementById('materialDetailModal');

    if (!modalEl) return;

    const modal = new bootstrap.Modal(modalEl);

    const IMG_EXT = [
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp'
    ];

    const extOf = name => {

        return String(name)
            .split('.')
            .pop()
            .toLowerCase();

    };


    /* FILE ICON */

    function iconFor(name) {

        const ext = extOf(name);

        if (IMG_EXT.includes(ext)) {
            return 'fa-file-image';
        }

        if (ext === 'pdf') {
            return 'fa-file-pdf';
        }

        if (['doc', 'docx'].includes(ext)) {
            return 'fa-file-word';
        }

        if (['ppt', 'pptx'].includes(ext)) {
            return 'fa-file-powerpoint';
        }

        if (['xls', 'xlsx'].includes(ext)) {
            return 'fa-file-excel';
        }

        if (['mp4', 'mov', 'avi'].includes(ext)) {
            return 'fa-file-video';
        }

        return 'fa-file';
    }


    /* OPEN MATERIAL */

    function openMaterial(data) {

        document.getElementById('mdTitle').textContent =
            data.title || '';

        document.getElementById('mdPosted').textContent =
            'Posted ' + (data.posted || '');


        /* AUTHOR INFORMATION */

        const authorName =
            document.getElementById('mdAuthorName');

        const authorImage =
            document.getElementById('mdAuthorImage');

        const authorFallback =
            document.getElementById('mdAuthorFallback');

        authorName.textContent =
            data.author_name || 'Instructor';

        if (data.author_image) {

            authorImage.src =
                'uploads/' + encodeURIComponent(data.author_image);

            authorImage.alt =
                data.author_name || 'Instructor';

            authorImage.style.display = 'block';

            authorFallback.style.display = 'none';

            authorImage.onerror = function() {

                this.style.display = 'none';

                authorFallback.style.display = 'flex';

            };

        } else {

            authorImage.removeAttribute('src');

            authorImage.style.display = 'none';

            authorFallback.style.display = 'flex';

        }


        /* DESCRIPTION */

        const caption =
            document.getElementById('mdCaption');

        caption.textContent =
            data.description || 'No caption';

        caption.classList.toggle(
            'material-caption-empty',
            !data.description
        );

        caption.style.whiteSpace = 'pre-wrap';


        /* ATTACHMENTS */

        const links =
            document.getElementById('mdLinks');

        const files =
            document.getElementById('mdFiles');

        links.innerHTML = '';

        files.innerHTML = '';


        (data.attachments || []).forEach(a => {

            /* LINKS */

            if (a.type === 'link') {

                const l = document.createElement('a');

                l.className = 'material-link-box';

                l.href = a.value;

                l.target = '_blank';

                l.rel = 'noopener noreferrer';

                l.innerHTML =
                    '<i class="fa-solid fa-link"></i><span></span>';

                l.querySelector('span').textContent =
                    a.value;

                links.appendChild(l);

                return;
            }


            /* FILES */

            const rawUrl =
                'uploads/' + encodeURIComponent(a.value);

            const openUrl =
                a.url || rawUrl;

            const target =
                a.target || '_blank';

            const isImg =
                IMG_EXT.includes(extOf(a.value));


            const item =
                document.createElement('div');

            item.className =
                'material-file-item';


            /* FILE PREVIEW */

            const prev =
                document.createElement('a');

            prev.href = openUrl;

            prev.target = target;

            prev.rel = 'noopener noreferrer';

            prev.className =
                'material-file-preview';


            if (isImg) {

                const img =
                    document.createElement('img');

                img.src = rawUrl;

                img.alt = a.value;

                img.loading = 'lazy';

                prev.appendChild(img);

            } else {

                const icon =
                    document.createElement('i');

                icon.className =
                    'fa-solid ' + iconFor(a.value);

                prev.appendChild(icon);

            }


            /* FILE NAME */

            const name =
                document.createElement('a');

            name.href = openUrl;

            name.target = target;

            name.rel = 'noopener noreferrer';

            name.className =
                'material-file-name';

            name.textContent =
                a.value;


            item.append(prev, name);

            files.appendChild(item);

        });


        modal.show();

    }


    /* MATERIAL CLICK EVENTS */

    document.querySelectorAll('.material-open').forEach(el => {

        const go = () => {

            try {

                const data = JSON.parse(
                    el.dataset.material
                );

                openMaterial(data);

            } catch (err) {

                console.error(
                    'Could not open material:',
                    err
                );

            }

        };

        el.addEventListener('click', go);

        el.addEventListener('keydown', e => {

            if (
                e.key === 'Enter' ||
                e.key === ' '
            ) {

                e.preventDefault();

                go();

            }

        });

    });


    /* BOOKMARK BUTTON */

    document.getElementById('mdBookmark')
        .addEventListener('click', function(e) {

            e.preventDefault();

            const i = this.querySelector('i');

            i.classList.toggle('fa-regular');

            i.classList.toggle('fa-solid');

        });

})();

</script>

</body>
</html>
