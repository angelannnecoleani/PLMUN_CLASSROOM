
<?php
session_start();
include 'database.php';
include 'auth_helpers.php';

$role = $_SESSION['role'] ?? '';

// Must be logged in as a teacher or program chair
if (empty($_SESSION['user_id']) || !in_array($role, ['teacher', 'program_chair'], true)) {
    header("Location: login.php");
    exit;
}

$teacher_id = (int)$_SESSION['user_id'];
$isChair = ($role === 'program_chair');

if (!isset($_GET['id'])) {
    die("Class not found");
}

$class_id = (int)$_GET['id'];

/* =========================================
   VERIFY CLASS ACCESS
========================================= */

$class = false;

if ($role === 'teacher') {

    // Allow primary instructor and additional assigned instructors.
    $accessStmt = mysqli_prepare(
        $conn,
        "SELECT c.*
         FROM classes c
         WHERE c.id = ?
         AND (
             c.teacher_id = ?
             OR EXISTS (
                 SELECT 1
                 FROM class_instructors ci
                 WHERE ci.class_id = c.id
                 AND ci.teacher_id = ?
             )
         )
         LIMIT 1"
    );

    if (!$accessStmt) {
        die("Database error: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param(
        $accessStmt,
        "iii",
        $class_id,
        $teacher_id,
        $teacher_id
    );

    mysqli_stmt_execute($accessStmt);

    $result = mysqli_stmt_get_result($accessStmt);
    $class = mysqli_fetch_assoc($result);

    mysqli_stmt_close($accessStmt);

} elseif ($role === 'program_chair') {

    // Preserve existing Program Chair access.
    $class = getClassAccess(
        $conn,
        $class_id,
        $teacher_id,
        $role
    );
}

if (!$class) {
    http_response_code(403);
    die("Class not found or you do not have access to it.");
}

/* =========================================
   FETCH CURRENT USER
========================================= */

$userStmt = mysqli_prepare(
    $conn,
    "SELECT * FROM users WHERE id = ? LIMIT 1"
);

if (!$userStmt) {
    die("Database error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($userStmt, "i", $teacher_id);
mysqli_stmt_execute($userStmt);

$userResult = mysqli_stmt_get_result($userStmt);
$user = mysqli_fetch_assoc($userResult);

mysqli_stmt_close($userStmt);

if (!$user) {
    die("User not found.");
}

/* =========================================
   TEACHER DISPLAY NAME
========================================= */

$teacherName = trim(
    ($user['first_name'] ?? '') . ' ' .
    ($user['last_name'] ?? '')
);

if ($teacherName === '') {
    $teacherName = $user['name']
        ?? $user['fullname']
        ?? $user['username']
        ?? 'Teacher';
}

/* =========================================
   PROFILE IMAGE VALIDATION
========================================= */

$rawProfileFile = trim((string)($user['profile_image'] ?? ''));

// Support both "photo.jpg" and "uploads/photo.jpg"
$profileFile = basename(
    str_replace('\\', '/', $rawProfileFile)
);

$defaultImages = [
    '',
    'default.png',
    'default.jpg',
    'default.jpeg',
    'default.svg',
    'null'
];

$hasProfileImage = !in_array(
    strtolower($profileFile),
    $defaultImages,
    true
) && is_file(__DIR__ . '/uploads/' . $profileFile)
  && is_readable(__DIR__ . '/uploads/' . $profileFile);

$profileImageUrl = $hasProfileImage
    ? 'uploads/' . rawurlencode($profileFile)
    : '';

/* =========================================
   FETCH MATERIALS
========================================= */

$materialsStmt = mysqli_prepare(
    $conn,
    "SELECT *
     FROM materials
     WHERE class_id = ?
     ORDER BY id DESC"
);

if (!$materialsStmt) {
    die("Database error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($materialsStmt, "i", $class_id);
mysqli_stmt_execute($materialsStmt);

$materialsQuery = mysqli_stmt_get_result($materialsStmt);

/* =========================================
   MATERIAL ICON HELPER
========================================= */

function material_icon_for($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
        return 'fa-file-image';
    }

    if ($ext === 'pdf') {
        return 'fa-file-pdf';
    }

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
   PRE-LOAD ALL MATERIALS
========================================= */

$materials = [];

while ($row = mysqli_fetch_assoc($materialsQuery)) {
    $materials[] = $row;
}

mysqli_stmt_close($materialsStmt);

/* =========================================
   FETCH ALL MATERIAL ATTACHMENTS
========================================= */

$attachmentsByMaterial = [];

if (count($materials) > 0) {

    $ids = array_map(
        fn($m) => (int)$m['id'],
        $materials
    );

    $idList = implode(',', $ids);

    $attachQuery = mysqli_query(
        $conn,
        "SELECT *
         FROM material_attachments
         WHERE material_id IN ($idList)
         ORDER BY id ASC"
    );

    if ($attachQuery) {
        while ($a = mysqli_fetch_assoc($attachQuery)) {
            $attachmentsByMaterial[$a['material_id']][] = $a;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        Materials – <?php echo htmlspecialchars($class['class_name']); ?>
    </title>

    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">

    <link rel="stylesheet" href="teacher_class.css">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <link rel="stylesheet" href="teacher_material.css">

    <style>
        /* =========================================
           TOPBAR PROFILE IMAGE
        ========================================= */

        .profile-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border-radius: 50%;
        }

        .profile-icon img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .profile-icon .profile-fallback {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
        }

        .profile-icon .profile-fallback[hidden] {
            display: none !important;
        }

        /* =========================================
           MATERIAL MODAL TEACHER AVATAR
        ========================================= */

        .material-teacher-avatar-wrapper {
            width: 42px;
            height: 42px;
            min-width: 42px;
            position: relative;
            border-radius: 50%;
            overflow: hidden;
            background: #e9eef3;
        }

        .material-teacher-avatar-wrapper .material-teacher-avatar {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .material-teacher-avatar-wrapper .material-avatar-default {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            background: #e9eef3;
            color: #64748b;
            font-size: 19px;
        }

        .material-teacher-avatar-wrapper [hidden] {
            display: none !important;
        }
    </style>
</head>

<body class="materials-page">

<!-- SIDEBAR OVERLAY -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- SIDEBAR -->
<div class="sidebar" id="sidebar">

    <div class="sidebar-header">

        <button
            class="toggle-btn"
            onclick="toggleSidebar()"
            aria-label="Collapse menu"
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

        <?php if ($isChair): ?>

            <li>
                <a href="program_chair_dashboard.php">
                    <i class="fa-solid fa-house"></i>
                    <span>Home</span>
                </a>
            </li>

        <?php else: ?>

            <li>
                <a href="teacher_dashboard.php">
                    <i class="fa-solid fa-house"></i>
                    <span>Home</span>
                </a>
            </li>

            <li>
                <a href="teacher_classpage.php">
                    <i class="fa-solid fa-users"></i>
                    <span>Classes</span>
                </a>
            </li>

            <li>
                <a href="teacher_to_review.php">
                    <i class="fa-solid fa-clipboard-list"></i>
                    <span>To Review</span>
                </a>
            </li>

            <li>
                <a href="teacher_messages.php">
                    <i class="fa-regular fa-message"></i>
                    <span>Messages</span>
                </a>
            </li>

            <li>
                <a href="teacher_performance.php">
                    <i class="fa-solid fa-chart-pie"></i>
                    <span>Performance</span>
                </a>
            </li>

        <?php endif; ?>

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

            <a href="profile.php" class="profile-icon" aria-label="Profile">

                <?php if ($hasProfileImage): ?>

                    <img
                        src="<?php echo htmlspecialchars($profileImageUrl, ENT_QUOTES, 'UTF-8'); ?>"
                        alt="Profile"
                        onerror="this.hidden=true; this.nextElementSibling.hidden=false;"
                    >

                <?php endif; ?>

                <i
                    class="fa-solid fa-user profile-fallback"
                    <?php echo $hasProfileImage ? 'hidden' : ''; ?>
                ></i>

            </a>

        </div>

    </div>

    <!-- CLASS NAV -->
    <div class="class-nav">

        <?php if ($isChair): ?>

            <a href="program_chair_class.php?id=<?php echo $class_id; ?>&tab=stream">
                Stream
            </a>

            <a href="teacher_assessment.php?id=<?php echo $class_id; ?>">
                Assessments
            </a>

            <a
                href="teacher_material.php?id=<?php echo $class_id; ?>"
                class="nav-active"
            >
                Materials
            </a>

            <a href="program_chair_class.php?id=<?php echo $class_id; ?>&tab=people">
                People
            </a>

        <?php else: ?>

            <a href="teacher_class.php?id=<?php echo $class_id; ?>">
                Stream
            </a>

            <a href="teacher_assessment.php?id=<?php echo $class_id; ?>">
                Assessments
            </a>

            <a
                href="teacher_material.php?id=<?php echo $class_id; ?>"
                class="nav-active"
            >
                Materials
            </a>

            <a href="teacher_people.php?id=<?php echo $class_id; ?>">
                People
            </a>

            <a href="teacher_grades.php?id=<?php echo $class_id; ?>">
                Grades
            </a>

        <?php endif; ?>

    </div>

    <!-- PAGE CONTENT -->
    <div class="container py-4">

        <!-- ACTIONS -->
        <div class="page-actions">

            <h1 class="page-title">Materials</h1>

            <button
                class="create-btn"
                data-bs-toggle="modal"
                data-bs-target="#materialModal"
            >
                <i class="fa-solid fa-plus"></i>
                Create
            </button>

        </div>

        <!-- MATERIALS LIST -->
        <?php if (count($materials) === 0): ?>

            <div class="empty-state">
                <i class="fa-solid fa-book-open"></i>
                <p>
                    No materials yet. Click
                    <strong>Create</strong>
                    to add one.
                </p>
            </div>

        <?php else: ?>

            <div class="topic-block" data-topic="none">

                <!-- TOPIC HEADER -->
                <div class="topic-header-bar">

                    <div class="topic-header-left">

                        <button
                            class="topic-collapse-btn"
                            onclick="toggleTopic('topic-main')"
                            aria-label="Toggle materials"
                        >
                            <i class="fa-solid fa-chevron-down"></i>
                        </button>

                        <span class="topic-name">Materials</span>

                        <span class="count-pill">
                            <?php echo count($materials); ?>
                        </span>

                    </div>

                </div>

                <!-- MATERIAL ROWS -->
                <div class="topic-body" id="topic-main">

                    <?php foreach ($materials as $material):

                        $attachments = $attachmentsByMaterial[$material['id']] ?? [];

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

                        $leadIcon = 'fa-file';

                        foreach ($attachments as $a) {

                            if ($a['type'] === 'file') {
                                $leadIcon = material_icon_for($a['value']);
                                break;
                            }

                            if ($a['type'] === 'link') {
                                $leadIcon = 'fa-link';
                            }
                        }

                        $payload = [
                            'title' => $material['title'] ?? '',
                            'description' => $material['description'] ?? '',
                            'posted' => date(
                                "M d, Y · g:i A",
                                strtotime($material['created_at'])
                            ),
                            'attachments' => array_values(
                                array_map(
                                    fn($a) => [
                                        'type' => $a['type'],
                                        'value' => $a['value']
                                    ],
                                    $attachments
                                )
                            )
                        ];

                        $payloadJson = htmlspecialchars(
                            json_encode($payload),
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

                                    <i class="fa-solid <?php echo $leadIcon; ?> material-icon"></i>

                                    <div>

                                        <span class="material-title">
                                            <?php echo htmlspecialchars($material['title']); ?>
                                        </span>

                                        <div class="material-meta">

                                            Posted
                                            <?php echo date(
                                                "M d, Y",
                                                strtotime($material['created_at'])
                                            ); ?>

                                            <?php if (count($attachments) > 1): ?>
                                                &middot;
                                                <?php echo count($attachments); ?>
                                                attachments
                                            <?php endif; ?>

                                        </div>

                                    </div>

                                </div>

                                <!-- THREE-DOT MENU -->
                                <div class="dropdown">

                                    <button
                                        class="option-btn"
                                        type="button"
                                        data-bs-toggle="dropdown"
                                        data-bs-boundary="viewport"
                                        aria-label="Material options"
                                    >
                                        <i class="fa-solid fa-ellipsis-vertical"></i>
                                    </button>

                                    <ul class="dropdown-menu dropdown-menu-end">

                                        <li>
                                            <a
                                                class="dropdown-item"
                                                href="edit_material.php?id=<?php echo (int)$material['id']; ?>&class_id=<?php echo $class_id; ?>"
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
                                                href="delete_material.php?id=<?php echo (int)$material['id']; ?>&class_id=<?php echo $class_id; ?>"
                                                onclick="return confirm('Delete this material?')"
                                            >
                                                <i class="fa-solid fa-trash me-2"></i>
                                                Delete
                                            </a>
                                        </li>

                                    </ul>

                                </div>

                            </div>

                            <!-- DESCRIPTION + ATTACHMENTS -->
                            <?php if (!empty($material['description']) || count($attachments) > 0): ?>

                                <div class="material-body">

                                    <?php if (!empty($material['description'])): ?>

                                        <p class="material-description">
                                            <?php
                                            echo nl2br(
                                                htmlspecialchars($material['description'])
                                            );
                                            ?>
                                        </p>

                                    <?php endif; ?>

                                    <?php foreach ($attachments as $a): ?>

                                        <?php if ($a['type'] === 'file'): ?>

                                            <a
                                                href="uploads/<?php echo htmlspecialchars(rawurlencode(basename(str_replace('\\', '/', $a['value'])))); ?>"
                                                class="material-attachment"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >

                                                <i class="fa-solid <?php echo material_icon_for($a['value']); ?>"></i>

                                                <span>
                                                    <?php echo htmlspecialchars($a['value']); ?>
                                                </span>

                                            </a>

                                        <?php else: ?>

                                            <a
                                                href="<?php echo htmlspecialchars($a['value']); ?>"
                                                class="material-attachment"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >

                                                <i class="fa-solid fa-link"></i>

                                                <span>
                                                    <?php echo htmlspecialchars($a['value']); ?>
                                                </span>

                                            </a>

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

<!-- =========================================
     MATERIAL DETAIL MODAL
========================================= -->

<div
    class="modal fade material-detail-modal"
    id="materialDetailModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content material-modal-content">

            <div class="material-modal-header">

                <div class="material-title-area">

                    <div class="material-title-icon">
                        <i class="fa-solid fa-book-open"></i>
                    </div>

                    <h5 class="material-modal-title" id="mdTitle"></h5>

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

            <div class="material-modal-body">

                <!-- TEACHER PROFILE AND POST DETAILS -->
                <div class="material-teacher-row">

                    <div class="material-teacher-info">

                        <!-- FIXED TEACHER AVATAR -->
                        <div class="material-teacher-avatar-wrapper">

                            <?php if ($hasProfileImage): ?>

                                <img
                                    src="<?php echo htmlspecialchars($profileImageUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                    class="material-teacher-avatar"
                                    alt="<?php echo htmlspecialchars($teacherName, ENT_QUOTES, 'UTF-8'); ?>"
                                    onerror="this.hidden=true; this.nextElementSibling.hidden=false;"
                                >

                            <?php endif; ?>

                            <div
                                class="material-teacher-avatar material-avatar-default"
                                <?php echo $hasProfileImage ? 'hidden' : ''; ?>
                            >
                                <i class="fa-solid fa-user"></i>
                            </div>

                        </div>

                        <div class="material-teacher-details">

                            <strong>
                                <?php echo htmlspecialchars($teacherName); ?>
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

                <div class="material-caption" id="mdCaption"></div>

                <div id="mdLinks"></div>

                <div class="material-file-grid" id="mdFiles"></div>

            </div>

        </div>

    </div>

</div>

<!-- =========================================
     CREATE MATERIAL MODAL
========================================= -->

<div
    class="modal fade"
    id="materialModal"
    tabindex="-1"
>

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content assessment-modal">

            <div class="modal-header border-0 pb-0">

                <h5 class="modal-title fw-bold">
                    <i class="fa-solid fa-book-open me-2 text-primary"></i>
                    Create New Material
                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                ></button>

            </div>

            <div class="modal-body">

                <form
                    action="save_material.php"
                    method="POST"
                    enctype="multipart/form-data"
                    id="materialForm"
                >

                    <input
                        type="hidden"
                        name="class_id"
                        value="<?php echo $class_id; ?>"
                    >

                    <!-- TITLE -->
                    <div class="mb-3">

                        <input
                            type="text"
                            name="title"
                            class="form-control assessment-input"
                            placeholder="Title*"
                            required
                        >

                    </div>

                    <!-- INSTRUCTIONS + ATTACHMENT TOOLBAR -->
                    <div class="mb-3 instruction-box">

                        <textarea
                            name="description"
                            placeholder="Instructions (optional)"
                        ></textarea>

                        <div
                            class="attachment-preview-list"
                            id="attachmentPreviewList"
                        ></div>

                        <div id="linkFieldsWrap"></div>

                        <div class="instruction-footer">

                            <div class="attachment-icons">

                                <label title="Add images" class="mb-0">

                                    <i class="fa-solid fa-image"></i>

                                    <input
                                        type="file"
                                        name="images[]"
                                        id="imageInput"
                                        accept="image/*"
                                        multiple
                                        hidden
                                    >

                                </label>

                                <label title="Attach files" class="mb-0">

                                    <i class="fa-solid fa-paperclip"></i>

                                    <input
                                        type="file"
                                        name="files[]"
                                        id="fileInput"
                                        multiple
                                        hidden
                                    >

                                </label>

                                <i
                                    class="fa-solid fa-link"
                                    title="Add a link"
                                    id="addLinkBtn"
                                ></i>

                            </div>

                        </div>

                    </div>

                    <div class="modal-actions">

                        <button
                            type="button"
                            class="btn-modal-cancel"
                            data-bs-dismiss="modal"
                        >
                            Cancel
                        </button>

                        <button
                            type="submit"
                            class="btn-modal-save"
                        >
                            <i class="fa-solid fa-floppy-disk me-2"></i>
                            Save
                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>

</div>

<!-- BOOTSTRAP -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

/* =========================================
   SIDEBAR FUNCTIONS
========================================= */

function toggleSidebar() {

    document.getElementById('sidebar')
        .classList.toggle('collapsed');

    document.getElementById('mainContent')
        .classList.toggle('expanded');

}

function openMobileSidebar() {

    document.getElementById('sidebar')
        .classList.add('show');

    document.getElementById('sidebarOverlay')
        .classList.add('show');

    document.body.classList.add('no-scroll');

}

function closeMobileSidebar() {

    document.getElementById('sidebar')
        .classList.remove('show');

    document.getElementById('sidebarOverlay')
        .classList.remove('show');

    document.body.classList.remove('no-scroll');

}

function mobileSidebar() {

    const sidebar = document.getElementById('sidebar');

    sidebar.classList.contains('show')
        ? closeMobileSidebar()
        : openMobileSidebar();

}

document.getElementById('sidebarOverlay')
    .addEventListener('click', closeMobileSidebar);

document.addEventListener('keydown', function(e) {

    if (e.key === 'Escape') {
        closeMobileSidebar();
    }

});

document.querySelectorAll(
    '.sidebar ul li a, .sidebar .logout-btn a'
).forEach(function(link) {

    link.addEventListener('click', function() {

        if (
            document.getElementById('sidebar')
                .classList.contains('show')
        ) {
            closeMobileSidebar();
        }

    });

});

/* =========================================
   COLLAPSE MATERIAL TOPIC
========================================= */

function toggleTopic(id) {

    const body = document.getElementById(id);

    const icon = body.previousElementSibling
        .querySelector('.topic-collapse-btn i');

    body.classList.toggle('collapsed');

    icon.classList.toggle('fa-chevron-down');
    icon.classList.toggle('fa-chevron-right');

}

/* =========================================
   SCROLL ACTIVE CLASS TAB INTO VIEW
========================================= */

(function() {

    const active = document.querySelector(
        '.class-nav .nav-active'
    );

    if (active) {
        active.scrollIntoView({
            inline: 'center',
            block: 'nearest'
        });
    }

})();

/* =========================================
   CREATE MATERIAL
   MULTIPLE IMAGES / FILES / LINKS
========================================= */

(function() {

    let selectedImages = [];
    let selectedFiles = [];

    const imageInput = document.getElementById('imageInput');
    const fileInput = document.getElementById('fileInput');

    const previewList = document.getElementById(
        'attachmentPreviewList'
    );

    const linkFieldsWrap = document.getElementById(
        'linkFieldsWrap'
    );

    const addLinkBtn = document.getElementById('addLinkBtn');

    const materialModal = document.getElementById('materialModal');
    const materialForm = document.getElementById('materialForm');

    imageInput.addEventListener('change', function() {

        selectedImages = Array.from(this.files);

        renderPreview();

    });

    fileInput.addEventListener('change', function() {

        selectedFiles = Array.from(this.files);

        renderPreview();

    });

    addLinkBtn.addEventListener('click', function() {

        const row = document.createElement('div');

        row.className = 'link-field-row';

        row.innerHTML = `
            <i class="fa-solid fa-link"></i>
            <input
                type="url"
                name="links[]"
                class="link-field-input"
                placeholder="https://..."
            >
            <button
                type="button"
                class="link-field-remove"
                aria-label="Remove link"
            >&times;</button>
        `;

        row.querySelector('.link-field-remove')
            .addEventListener('click', () => row.remove());

        linkFieldsWrap.appendChild(row);

        row.querySelector('input').focus();

    });

    function renderPreview() {

        previewList.innerHTML = '';

        selectedImages.forEach((f, i) => {
            previewList.appendChild(
                makeChip(f.name, 'image', i)
            );
        });

        selectedFiles.forEach((f, i) => {
            previewList.appendChild(
                makeChip(f.name, 'file', i)
            );
        });

    }

    function makeChip(name, kind, index) {

        const chip = document.createElement('span');

        chip.className = 'attachment-chip';

        chip.innerHTML = `
            <i class="fa-solid ${
                kind === 'image'
                    ? 'fa-file-image'
                    : 'fa-paperclip'
            } me-1"></i>

            <span class="attachment-chip-name"></span>

            <button
                type="button"
                class="chip-remove"
                aria-label="Remove"
            >&times;</button>
        `;

        chip.querySelector('.attachment-chip-name')
            .textContent = name;

        chip.querySelector('.chip-remove')
            .addEventListener('click', function() {

                if (kind === 'image') {
                    selectedImages.splice(index, 1);
                } else {
                    selectedFiles.splice(index, 1);
                }

                syncInput(kind);

                renderPreview();

            });

        return chip;

    }

    function syncInput(kind) {

        const dt = new DataTransfer();

        const arr = kind === 'image'
            ? selectedImages
            : selectedFiles;

        arr.forEach(f => dt.items.add(f));

        (kind === 'image' ? imageInput : fileInput).files = dt.files;

    }

    materialModal.addEventListener(
        'hidden.bs.modal',
        function() {

            materialForm.reset();

            selectedImages = [];
            selectedFiles = [];

            linkFieldsWrap.innerHTML = '';

            renderPreview();

        }
    );

})();

/* =========================================
   MATERIAL DETAIL MODAL
========================================= */

(function() {

    const modalEl = document.getElementById(
        'materialDetailModal'
    );

    if (!modalEl) return;

    const modal = new bootstrap.Modal(modalEl);

    const IMG_EXT = [
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp'
    ];

    function extOf(name) {

        return String(name)
            .split('.')
            .pop()
            .toLowerCase();

    }

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

    function openMaterial(data) {

        document.getElementById('mdTitle').textContent =
            data.title || '';

        document.getElementById('mdPosted').textContent =
            'Posted ' + (data.posted || '');

        const caption = document.getElementById('mdCaption');

        caption.textContent =
            data.description || 'No caption';

        caption.classList.toggle(
            'material-caption-empty',
            !data.description
        );

        caption.style.whiteSpace = 'pre-wrap';

        const links = document.getElementById('mdLinks');
        const files = document.getElementById('mdFiles');

        links.innerHTML = '';
        files.innerHTML = '';

        (data.attachments || []).forEach(function(a) {

            if (a.type === 'link') {

                const l = document.createElement('a');

                l.className = 'material-link-box';
                l.href = a.value;
                l.target = '_blank';
                l.rel = 'noopener noreferrer';

                l.innerHTML = `
                    <i class="fa-solid fa-link"></i>
                    <span></span>
                `;

                l.querySelector('span').textContent = a.value;

                links.appendChild(l);

            } else {

                // Normalize attachment filename.
                const filename = String(a.value)
                    .replace(/\\/g, '/')
                    .split('/')
                    .pop();

                const url = 'uploads/' +
                    encodeURIComponent(filename);

                const isImg = IMG_EXT.includes(
                    extOf(filename)
                );

                const item = document.createElement('div');

                item.className = 'material-file-item';

                const prev = document.createElement('a');

                prev.href = url;
                prev.target = '_blank';
                prev.rel = 'noopener noreferrer';

                prev.className =
                    'material-file-preview' +
                    (isImg ? ' image-preview' : '');

                if (isImg) {

                    const img = document.createElement('img');

                    img.src = url;
                    img.alt = filename;

                    img.onerror = function() {
                        this.style.display = 'none';
                    };

                    prev.appendChild(img);

                } else {

                    prev.innerHTML =
                        '<i class="fa-solid ' +
                        iconFor(filename) +
                        '"></i>';

                }

                const name = document.createElement('a');

                name.href = url;
                name.target = '_blank';
                name.rel = 'noopener noreferrer';

                name.className = 'material-file-name';
                name.textContent = filename;

                item.appendChild(prev);
                item.appendChild(name);

                files.appendChild(item);

            }

        });

        modal.show();

    }

    document.querySelectorAll('.material-open')
        .forEach(function(el) {

            function go() {

                try {

                    openMaterial(
                        JSON.parse(el.dataset.material)
                    );

                } catch(err) {

                    console.error(
                        'Could not open material:',
                        err
                    );

                }

            }

            el.addEventListener('click', go);

            el.addEventListener('keydown', function(e) {

                if (
                    e.key === 'Enter' ||
                    e.key === ' '
                ) {

                    e.preventDefault();
                    go();

                }

            });

        });

    /* =========================================
       BOOKMARK BUTTON
    ========================================= */

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