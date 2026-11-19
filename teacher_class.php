<?php
session_start();

require_once 'database.php';
require_once 'auth_helpers.php';

/* ==========================================
   LOGIN AND ROLE VALIDATION
========================================== */

if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$teacher_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

if (!in_array($role, ['teacher', 'program_chair'], true)) {
    header("Location: login.php");
    exit();
}

/* ==========================================
   HELPER FUNCTIONS
========================================== */

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

function profileImageUrl($value) {
    $value = trim((string)($value ?? ''));

    if ($value === '' || strtolower($value) === 'null') {
        return '';
    }

    $value = str_replace('\\', '/', $value);

    if (strpos($value, '..') !== false) {
        return '';
    }

    $name = basename($value);

    if (in_array(strtolower($name), [
        'default.png',
        'default.jpg',
        'default.jpeg',
        'default.svg'
    ])) {
        return '';
    }

    $candidates = [
        ltrim($value, '/'),
        'uploads/' . ltrim($value, '/'),
        'uploads/' . $name
    ];

    foreach ($candidates as $rel) {
        if (is_file(__DIR__ . '/' . $rel)) {
            return implode(
                '/',
                array_map('rawurlencode', explode('/', $rel))
            );
        }
    }

    return '';
}

function avatarHtml(
    $value,
    $class = 'profile-pic',
    $defaultClass = 'default-profile'
) {
    $url = profileImageUrl($value);

    if ($url !== '') {
        return '<img src="' .
            htmlspecialchars($url, ENT_QUOTES, 'UTF-8') .
            '" class="' . $class . '" alt="">';
    }

    return '<div class="' . $class . ' ' . $defaultClass . '">
                <i class="fa-solid fa-user"></i>
            </div>';
}

/* ==========================================
   FETCH LOGGED-IN USER
========================================== */

$userStmt = mysqli_prepare(
    $conn,
    "SELECT id, name, profile_image, role
     FROM users
     WHERE id = ?
     LIMIT 1"
);

if (!$userStmt) {
    die("Database error: " . htmlspecialchars(mysqli_error($conn)));
}

mysqli_stmt_bind_param($userStmt, "i", $teacher_id);
mysqli_stmt_execute($userStmt);

$userResult = mysqli_stmt_get_result($userStmt);
$user = mysqli_fetch_assoc($userResult);

mysqli_stmt_close($userStmt);

if (!$user || $user['role'] !== $role) {
    session_destroy();
    header("Location: login.php");
    exit();
}

/* ==========================================
   GET CLASS ID
========================================== */

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Class not found.");
}

$id = (int)$_GET['id'];

if ($id <= 0) {
    die("Invalid class ID.");
}

/* ==========================================
   FETCH CLASS
========================================== */

$classStmt = mysqli_prepare(
    $conn,
    "SELECT c.*
     FROM classes c
     WHERE c.id = ?
     LIMIT 1"
);

if (!$classStmt) {
    die("Database error: " . htmlspecialchars(mysqli_error($conn)));
}

mysqli_stmt_bind_param($classStmt, "i", $id);
mysqli_stmt_execute($classStmt);

$classResult = mysqli_stmt_get_result($classStmt);
$class = mysqli_fetch_assoc($classResult);

mysqli_stmt_close($classStmt);

if (!$class) {
    http_response_code(404);
    die("Class not found.");
}

/* ==========================================
   CHECK CLASS ACCESS
========================================== */

$hasAccess = false;

if ($role === 'teacher') {

    // Primary instructor or additional assigned instructor.
    $accessStmt = mysqli_prepare(
        $conn,
        "SELECT c.id
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
        die("Class access database error: " .
            htmlspecialchars(mysqli_error($conn)));
    }

    mysqli_stmt_bind_param(
        $accessStmt,
        "iii",
        $id,
        $teacher_id,
        $teacher_id
    );

    mysqli_stmt_execute($accessStmt);

    $accessResult = mysqli_stmt_get_result($accessStmt);
    $hasAccess = mysqli_num_rows($accessResult) > 0;

    mysqli_stmt_close($accessStmt);

} elseif ($role === 'program_chair') {

    // Program chair access is handled by your existing helper.
    $hasAccess = (bool)getClassAccess(
        $conn,
        $id,
        $teacher_id,
        $role
    );
}

if (!$hasAccess) {
    http_response_code(403);
    die("Access denied. You are not authorized to access this class.");
}

/* ==========================================
   GET PRIMARY INSTRUCTOR NAME
========================================== */

$classTeacherName = 'No instructor assigned';

if (!empty($class['teacher_id'])) {

    $teacherStmt = mysqli_prepare(
        $conn,
        "SELECT name
         FROM users
         WHERE id = ?
         LIMIT 1"
    );

    if ($teacherStmt) {
        $primaryTeacherId = (int)$class['teacher_id'];

        mysqli_stmt_bind_param(
            $teacherStmt,
            "i",
            $primaryTeacherId
        );

        mysqli_stmt_execute($teacherStmt);

        $teacherResult = mysqli_stmt_get_result($teacherStmt);
        $primaryTeacher = mysqli_fetch_assoc($teacherResult);

        if ($primaryTeacher) {
            $classTeacherName = $primaryTeacher['name'];
        }

        mysqli_stmt_close($teacherStmt);
    }
}

/* ==========================================
   PROFILE IMAGE (TOPBAR)
========================================== */

$topbarImageUrl = profileImageUrl(
    $user['profile_image'] ?? ''
);

/* ==========================================
   ANNOUNCEMENT ATTACHMENTS
========================================== */

$attachMap = [];

$attStmt = mysqli_prepare(
    $conn,
    "SELECT aa.*
     FROM announcement_attachments aa
     INNER JOIN announcements a
        ON aa.announcement_id = a.id
     WHERE a.class_id = ?
     ORDER BY aa.id ASC"
);

if ($attStmt) {
    mysqli_stmt_bind_param($attStmt, "i", $id);
    mysqli_stmt_execute($attStmt);

    $attResult = mysqli_stmt_get_result($attStmt);

    while ($a = mysqli_fetch_assoc($attResult)) {
        $attachMap[$a['announcement_id']][] = $a;
    }

    mysqli_stmt_close($attStmt);
}

/* ==========================================
   MATERIAL ATTACHMENTS
========================================== */

$matAttachMap = [];

$matStmt = mysqli_prepare(
    $conn,
    "SELECT ma.*
     FROM material_attachments ma
     INNER JOIN materials m
        ON ma.material_id = m.id
     WHERE m.class_id = ?
     ORDER BY ma.id ASC"
);

if ($matStmt) {
    mysqli_stmt_bind_param($matStmt, "i", $id);
    mysqli_stmt_execute($matStmt);

    $matResult = mysqli_stmt_get_result($matStmt);

    while ($a = mysqli_fetch_assoc($matResult)) {
        $matAttachMap[$a['material_id']][] = $a;
    }

    mysqli_stmt_close($matStmt);
}


/* ==========================================
   LOAD CLASS STREAM
========================================== */

$streamStmt = mysqli_prepare(
    $conn,
    "SELECT * FROM (

        /* ANNOUNCEMENTS */
        SELECT
            a.id,
            a.content,
            a.image,
            a.link,
            a.created_at,
            COALESCE(u.name, 'Unknown Teacher') AS name,
            u.profile_image,
            'announcement' AS post_type,
            NULL AS title,
            NULL AS description,
            NULL AS file,
            NULL AS due_date,
            NULL AS points,
            NULL AS assessment_type,
            a.pinned AS pinned

        FROM announcements a

        LEFT JOIN users u
            ON a.teacher_id = u.id

        WHERE a.class_id = ?

        UNION ALL

        /* ASSESSMENTS */
        SELECT
            ass.id,
            NULL AS content,
            ass.image,
            ass.link,
            ass.created_at,
            COALESCE(
                creator.name,
                primary_teacher.name,
                'Unknown Teacher'
            ) AS name,
            COALESCE(
                creator.profile_image,
                primary_teacher.profile_image
            ) AS profile_image,
            'assessment' AS post_type,
            ass.title,
            ass.instructions AS description,
            ass.attachment AS file,
            ass.due_date,
            ass.points,
            ass.type AS assessment_type,
            ass.pinned AS pinned

        FROM assessments ass

        LEFT JOIN classes c
            ON ass.class_id = c.id

        LEFT JOIN users creator
            ON ass.created_by = creator.id

        LEFT JOIN users primary_teacher
            ON c.teacher_id = primary_teacher.id

        WHERE ass.class_id = ?

        UNION ALL

        /* MATERIALS */
        SELECT
            m.id,
            NULL AS content,
            NULL AS image,
            m.link,
            m.created_at,
            COALESCE(
                creator.name,
                primary_teacher.name,
                'Unknown Teacher'
            ) AS name,
            COALESCE(
                creator.profile_image,
                primary_teacher.profile_image
            ) AS profile_image,
            'material' AS post_type,
            m.title,
            m.description,
            m.file,
            NULL AS due_date,
            NULL AS points,
            NULL AS assessment_type,
            m.pinned AS pinned

        FROM materials m

        LEFT JOIN classes c
            ON m.class_id = c.id

        LEFT JOIN users creator
            ON m.created_by = creator.id

        LEFT JOIN users primary_teacher
            ON c.teacher_id = primary_teacher.id

        WHERE m.class_id = ?

    ) AS stream_data

    ORDER BY pinned DESC, created_at DESC"
);

if (!$streamStmt) {
    die("Stream SQL Error: " . htmlspecialchars(mysqli_error($conn)));
}

mysqli_stmt_bind_param(
    $streamStmt,
    "iii",
    $id,
    $id,
    $id
);

mysqli_stmt_execute($streamStmt);

$stream = mysqli_stmt_get_result($streamStmt);

if (!$stream) {
    die("Unable to load class stream.");
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo htmlspecialchars($class['class_name'], ENT_QUOTES, 'UTF-8'); ?></title>

    <link rel="stylesheet" href="teacher_class.css?v=6">
    <link rel="stylesheet" href="class_chat_actions.css?v=2">
    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">

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

        <button class="sidebar-close-btn" onclick="closeMobileSidebar()" aria-label="Close menu">
            <i class="fa-solid fa-xmark"></i>
        </button>

    </div>

    <ul>
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


    </ul>

    <div class="logout-btn">
        <a href="logout.php">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Logout</span>
        </a>
    </div>

</div>

<!-- SIDEBAR OVERLAY -->
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

            <div class="meet-control">

                <?php if (!empty($class['meet_link'])): ?>

                    <a href="<?php echo htmlspecialchars($class['meet_link'], ENT_QUOTES, 'UTF-8'); ?>"
                       target="_blank"
                       rel="noopener noreferrer"
                       class="meet-button"
                       title="Join Google Meet">

                        <i class="fa-solid fa-video"></i>

                    </a>

                    <button type="button"
                            class="meet-edit-btn"
                            title="Edit Meet link"
                            onclick="openMeetModal()">

                        <i class="fa-solid fa-pen"></i>

                    </button>

                <?php else: ?>

                    <button type="button"
                            class="meet-button meet-start"
                            title="Set up Google Meet"
                            onclick="openMeetModal()">

                        <i class="fa-solid fa-video"></i>

                    </button>

                <?php endif; ?>

            </div>

            <a href="profile.php" class="profile-icon">

                <?php if ($topbarImageUrl !== ''): ?>

                    <img
                        src="<?php echo htmlspecialchars($topbarImageUrl, ENT_QUOTES, 'UTF-8'); ?>"
                        alt="Profile"
                        onerror="this.style.display='none'; this.parentElement.insertAdjacentHTML('beforeend','<i class=&quot;fa-solid fa-user&quot;></i>');"
                    >

                <?php else: ?>

                    <i class="fa-solid fa-user"></i>

                <?php endif; ?>

            </a>

        </div>

    </div>

    <!-- CLASS NAVIGATION -->
    <div class="class-nav">

        <a href="#" class="nav-active">Stream</a>

        <a href="teacher_assessment.php?id=<?php echo $id; ?>">
            Assessments
        </a>

        <a href="teacher_material.php?id=<?php echo $id; ?>">
            Materials
        </a>

        <a href="teacher_people.php?id=<?php echo $id; ?>">
            People
        </a>

        <a href="teacher_grades.php?id=<?php echo $id; ?>">
            Grades
        </a>

    </div>

    <div class="container py-4">

        <!-- CLASS BANNER -->
        <div class="class-banner mb-4">

            <h2>
                <?php echo htmlspecialchars($class['class_name'], ENT_QUOTES, 'UTF-8'); ?>
            </h2>

            <h5>
                <?php echo htmlspecialchars($classTeacherName, ENT_QUOTES, 'UTF-8'); ?>
            </h5>

            <p>
                <?php echo htmlspecialchars($class['section'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                |
                <?php echo htmlspecialchars($class['schedule'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
            </p>

            <?php if (!empty($class['program'])): ?>
                <small>
                    <?php echo htmlspecialchars($class['program'], ENT_QUOTES, 'UTF-8'); ?>
                </small>
            <?php endif; ?>

        </div>

        <div class="row g-4">

            <!-- CLASS CODE -->
            <div class="col-lg-3">

                <div class="class-code">

                    <h6>Class Code</h6>

                    <strong>
                        <?php echo htmlspecialchars($class['class_code'], ENT_QUOTES, 'UTF-8'); ?>
                    </strong>

                </div>

            </div>

            <!-- ANNOUNCEMENTS AND STREAM -->
            <div class="col-lg-9">

                <!-- ANNOUNCEMENT FORM -->
                <div class="announce-box mb-4">

                    <form action="save_announcement.php"
                          method="POST"
                          enctype="multipart/form-data"
                          class="announcement-form"
                          id="announceForm">

                        <input type="hidden" name="class_id" value="<?php echo $id; ?>">

                        <textarea
                            class="announce-input"
                            name="content"
                            id="announceText"
                            placeholder="Announce something to your class"
                        ></textarea>

                        <input type="file"
                               name="attachments[]"
                               id="attachmentsInput"
                               multiple
                               hidden>

                        <div id="linksHidden"></div>

                        <div id="attachList" class="attach-list"></div>

                        <div id="attachMsg" class="attach-msg"></div>

                        <div class="announce-actions">

                            <div class="left-actions">

                                <button type="button"
                                        class="icon-btn"
                                        onclick="document.getElementById('pickImages').click()"
                                        title="Attach images">

                                    <i class="fa-regular fa-image"></i>

                                </button>

                                <button type="button"
                                        class="icon-btn"
                                        onclick="document.getElementById('pickFiles').click()"
                                        title="Attach files">

                                    <i class="fa-solid fa-paperclip"></i>

                                </button>

                                <button type="button"
                                        class="icon-btn"
                                        onclick="openLinkModal()"
                                        title="Attach link">

                                    <i class="fa-solid fa-link"></i>

                                </button>

                                <span class="attach-counter" id="attachCounter">
                                    0/10
                                </span>

                            </div>

                            <button type="submit" class="send-btn" title="Post">
                                <i class="fa-regular fa-paper-plane"></i>
                            </button>

                        </div>

                        <input type="file" id="pickImages" accept="image/*" multiple hidden>

                        <input type="file" id="pickFiles" multiple hidden>

                    </form>

                </div>

                <!-- WELCOME CARD -->
                <div class="stream-post mb-3">

                    <div class="card shadow-sm border-0">

                        <div class="card-body">

                            <h6 class="fw-bold">
                                Welcome to <?php echo htmlspecialchars($class['class_name'], ENT_QUOTES, 'UTF-8'); ?>
                            </h6>

                            <p class="text-muted mb-0">
                                Announcements posted by the teacher will appear here.
                            </p>

                        </div>

                    </div>

                </div>

                <!-- STREAM POSTS -->
                <?php while ($row = mysqli_fetch_assoc($stream)): ?>

                    <?php if ($row['post_type'] === 'announcement'): ?>

                        <div class="announcement-card mb-3">

                            <div class="announcement-header">

                                <div class="header-left">

                                    <?php echo avatarHtml($row['profile_image']); ?>

                                    <div>

                                        <h6 class="mb-0">
                                            <?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>
                                        </h6>

                                        <small class="text-muted">
                                            <?php echo date("M d, Y h:i A", strtotime($row['created_at'])); ?>
                                        </small>

                                    </div>

                                </div>

                                <div class="dropdown">

                                    <button class="option-btn"
                                            type="button"
                                            data-bs-toggle="dropdown"
                                            aria-expanded="false">

                                        <i class="fa-solid fa-ellipsis-vertical"></i>

                                    </button>

                                    <ul class="dropdown-menu dropdown-menu-end">

                                        <li>
                                            <a class="dropdown-item"
                                               href="pin_announcement.php?id=<?php echo (int)$row['id']; ?>">

                                                <?php if ($row['pinned']): ?>
                                                    <i class="fa-solid fa-thumbtack me-2 text-warning"></i>
                                                    Unpin
                                                <?php else: ?>
                                                    <i class="fa-solid fa-thumbtack me-2"></i>
                                                    Pin
                                                <?php endif; ?>

                                            </a>
                                        </li>

                                        <li>
                                            <a class="dropdown-item"
                                               href="edit_announcement.php?id=<?php echo (int)$row['id']; ?>">

                                                <i class="fa-solid fa-pen me-2"></i>
                                                Edit

                                            </a>
                                        </li>

                                        <li><hr class="dropdown-divider"></li>

                                        <li>
                                            <a class="dropdown-item text-danger"
                                               href="delete_announcement.php?id=<?php echo (int)$row['id']; ?>"
                                               onclick="return confirm('Delete this announcement?')">

                                                <i class="fa-solid fa-trash me-2"></i>
                                                Delete

                                            </a>
                                        </li>

                                    </ul>

                                </div>

                            </div>

                            <div class="announcement-content mt-3">

                                <?php if ($row['pinned']): ?>
                                    <span class="pinned-label">
                                        <i class="fa-solid fa-thumbtack me-1"></i>
                                        Pinned
                                    </span>
                                <?php endif; ?>

                                <?php if (trim((string)$row['content']) !== ''): ?>
                                    <p>
                                        <?php echo nl2br(htmlspecialchars($row['content'], ENT_QUOTES, 'UTF-8')); ?>
                                    </p>
                                <?php endif; ?>

                                <?php if (!empty($row['link'])): ?>
                                    <a href="<?php echo htmlspecialchars($row['link'], ENT_QUOTES, 'UTF-8'); ?>"
                                       target="_blank"
                                       rel="noopener noreferrer">
                                        <?php echo htmlspecialchars($row['link'], ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                <?php endif; ?>

                                <?php if (!empty($row['image'])): ?>
                                    <img
                                        src="uploads/<?php echo htmlspecialchars(basename($row['image']), ENT_QUOTES, 'UTF-8'); ?>"
                                        class="announcement-image"
                                        alt="Announcement image"
                                    >
                                <?php endif; ?>

                                <?php
                                $atts = $attachMap[$row['id']] ?? [];

                                $aImgs = [];
                                $aFiles = [];
                                $aLinks = [];

                                foreach ($atts as $a) {
                                    if ($a['type'] === 'image') {
                                        $aImgs[] = $a;
                                    } elseif ($a['type'] === 'file') {
                                        $aFiles[] = $a;
                                    } elseif ($a['type'] === 'link') {
                                        $aLinks[] = $a;
                                    }
                                }
                                ?>

                                <?php if ($atts): ?>

                                    <div class="post-attachments">

                                        <?php if ($aImgs): ?>

                                            <div class="post-images">

                                                <?php foreach ($aImgs as $a): ?>

                                                    <a href="uploads/<?php echo htmlspecialchars(basename($a['path']), ENT_QUOTES, 'UTF-8'); ?>"
                                                       target="_blank">

                                                        <img
                                                            src="uploads/<?php echo htmlspecialchars(basename($a['path']), ENT_QUOTES, 'UTF-8'); ?>"
                                                            alt="<?php echo htmlspecialchars($a['original_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                        >

                                                    </a>

                                                <?php endforeach; ?>

                                            </div>

                                        <?php endif; ?>

                                        <?php foreach ($aFiles as $a):

                                            $fname = $a['original_name'] ?: basename($a['path']);
                                            $fext = pathinfo($a['path'], PATHINFO_EXTENSION);

                                        ?>

                                            <a class="post-file"
                                               href="uploads/<?php echo htmlspecialchars(basename($a['path']), ENT_QUOTES, 'UTF-8'); ?>"
                                               target="_blank"
                                               download="<?php echo htmlspecialchars($fname, ENT_QUOTES, 'UTF-8'); ?>">

                                                <i class="fa-solid <?php echo fileIconClass($fext); ?>"></i>

                                                <span>
                                                    <?php echo htmlspecialchars($fname, ENT_QUOTES, 'UTF-8'); ?>
                                                </span>

                                            </a>

                                        <?php endforeach; ?>

                                        <?php foreach ($aLinks as $a): ?>

                                            <a class="post-link"
                                               href="<?php echo htmlspecialchars($a['path'], ENT_QUOTES, 'UTF-8'); ?>"
                                               target="_blank"
                                               rel="noopener noreferrer">

                                                <i class="fa-solid fa-link"></i>

                                                <span>
                                                    <?php echo htmlspecialchars($a['path'], ENT_QUOTES, 'UTF-8'); ?>
                                                </span>

                                            </a>

                                        <?php endforeach; ?>

                                    </div>

                                <?php endif; ?>

                            </div>

                        </div>

                    <?php elseif ($row['post_type'] === 'assessment'): ?>

                        <div class="announcement-card assessment-post stream-row-card mb-3">

                            <div class="announcement-header-inline">

                                <a href="teacher_submissions.php?id=<?php echo (int)$row['id']; ?>"
                                   class="stream-row">

                                    <div class="stream-row-icon <?php echo strtolower($row['assessment_type'] ?? '') === 'quiz' ? 'icon-quiz' : 'icon-assignment'; ?>">
                                        <i class="fa-solid fa-clipboard"></i>
                                    </div>

                                    <div class="stream-row-body">

                                        <div class="stream-row-title">

                                            <?php if ($row['pinned']): ?>
                                                <span class="pinned-label me-2">
                                                    <i class="fa-solid fa-thumbtack me-1"></i>
                                                    Pinned
                                                </span>
                                            <?php endif; ?>

                                            <strong>
                                                <?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>
                                            </strong>

                                            posted a new

                                            <?php echo htmlspecialchars(strtolower($row['assessment_type'] ?? 'assessment'), ENT_QUOTES, 'UTF-8'); ?>:

                                            <strong>
                                                <?php echo htmlspecialchars($row['title'] ?? 'Untitled assessment', ENT_QUOTES, 'UTF-8'); ?>
                                            </strong>

                                        </div>

                                        <div class="stream-row-meta">

                                            <span>
                                                <?php echo date("M d, Y h:i A", strtotime($row['created_at'])); ?>
                                            </span>

                                            <span class="dot"></span>

                                            <span>
                                                Due
                                                <?php
                                                echo !empty($row['due_date'])
                                                    ? date("M d, Y", strtotime($row['due_date']))
                                                    : 'No due date';
                                                ?>
                                            </span>

                                            <span class="dot"></span>

                                            <span>
                                                <?php echo htmlspecialchars($row['points'] ?? '0', ENT_QUOTES, 'UTF-8'); ?> points
                                            </span>

                                        </div>

                                    </div>

                                </a>

                                <div class="dropdown">

                                    <button class="option-btn"
                                            type="button"
                                            data-bs-toggle="dropdown"
                                            aria-expanded="false">
                                        <i class="fa-solid fa-ellipsis-vertical"></i>
                                    </button>

                                    <ul class="dropdown-menu dropdown-menu-end">

                                        <li>
                                            <a class="dropdown-item"
                                               href="pin_assessment.php?id=<?php echo (int)$row['id']; ?>">

                                                <?php if ($row['pinned']): ?>
                                                    <i class="fa-solid fa-thumbtack me-2 text-warning"></i>
                                                    Unpin
                                                <?php else: ?>
                                                    <i class="fa-solid fa-thumbtack me-2"></i>
                                                    Pin
                                                <?php endif; ?>

                                            </a>
                                        </li>

                                        <li>
                                            <a class="dropdown-item"
                                               href="edit_assessment.php?id=<?php echo (int)$row['id']; ?>&class_id=<?php echo $id; ?>">
                                                <i class="fa-solid fa-pen me-2"></i>
                                                Edit
                                            </a>
                                        </li>

                                        <li><hr class="dropdown-divider"></li>

                                        <li>
                                            <a class="dropdown-item text-danger"
                                               href="delete_assessment.php?id=<?php echo (int)$row['id']; ?>&class_id=<?php echo $id; ?>"
                                               onclick="return confirm('Delete this assessment?')">
                                                <i class="fa-solid fa-trash me-2"></i>
                                                Delete
                                            </a>
                                        </li>

                                    </ul>

                                </div>

                            </div>

                        </div>

                    <?php elseif ($row['post_type'] === 'material'): ?>

                        <div class="announcement-card material-post stream-row-card mb-3">

                            <div class="announcement-header-inline">

                                <a href="#"
                                   class="stream-row"
                                   data-bs-toggle="modal"
                                   data-bs-target="#materialDetail<?php echo (int)$row['id']; ?>">

                                    <div class="stream-row-icon icon-material">
                                        <i class="fa-solid fa-book-open"></i>
                                    </div>

                                    <div class="stream-row-body">

                                        <div class="stream-row-title">

                                            <?php if ($row['pinned']): ?>
                                                <span class="pinned-label me-2">
                                                    <i class="fa-solid fa-thumbtack me-1"></i>
                                                    Pinned
                                                </span>
                                            <?php endif; ?>

                                            <strong>
                                                <?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>
                                            </strong>

                                            posted a new material:

                                            <strong>
                                                <?php echo htmlspecialchars($row['title'] ?? 'Untitled material', ENT_QUOTES, 'UTF-8'); ?>
                                            </strong>

                                        </div>

                                        <div class="stream-row-meta">
                                            <span>
                                                <?php echo date("M d, Y h:i A", strtotime($row['created_at'])); ?>
                                            </span>
                                        </div>

                                    </div>

                                </a>

                                <div class="dropdown">

                                    <button class="option-btn"
                                            type="button"
                                            data-bs-toggle="dropdown"
                                            aria-expanded="false">
                                        <i class="fa-solid fa-ellipsis-vertical"></i>
                                    </button>

                                    <ul class="dropdown-menu dropdown-menu-end">

                                        <li>
                                            <a class="dropdown-item"
                                               href="pin_material.php?id=<?php echo (int)$row['id']; ?>">

                                                <?php if ($row['pinned']): ?>
                                                    <i class="fa-solid fa-thumbtack me-2 text-warning"></i>
                                                    Unpin
                                                <?php else: ?>
                                                    <i class="fa-solid fa-thumbtack me-2"></i>
                                                    Pin
                                                <?php endif; ?>

                                            </a>
                                        </li>

                                        <li>
                                            <a class="dropdown-item"
                                               href="edit_material.php?id=<?php echo (int)$row['id']; ?>&class_id=<?php echo $id; ?>">
                                                <i class="fa-solid fa-pen me-2"></i>
                                                Edit
                                            </a>
                                        </li>

                                        <li><hr class="dropdown-divider"></li>

                                        <li>
                                            <a class="dropdown-item text-danger"
                                               href="delete_material.php?id=<?php echo (int)$row['id']; ?>&class_id=<?php echo $id; ?>"
                                               onclick="return confirm('Delete this material?')">
                                                <i class="fa-solid fa-trash me-2"></i>
                                                Delete
                                            </a>
                                        </li>

                                    </ul>

                                </div>

                            </div>

                        </div>

                        <!-- MATERIAL DETAIL MODAL -->
                        <div class="modal fade material-detail-modal"
                             id="materialDetail<?php echo (int)$row['id']; ?>"
                             tabindex="-1"
                             aria-hidden="true">

                            <div class="modal-dialog modal-dialog-centered">

                                <div class="modal-content material-modal-content">

                                    <div class="material-modal-header">

                                        <div class="material-title-area">

                                            <div class="material-title-icon">
                                                <i class="fa-solid fa-book-open"></i>
                                            </div>

                                            <h5 class="material-modal-title">
                                                <?php echo htmlspecialchars($row['title'] ?? 'Untitled material', ENT_QUOTES, 'UTF-8'); ?>
                                            </h5>

                                        </div>

                                        <button type="button"
                                                class="material-close-btn"
                                                data-bs-dismiss="modal"
                                                aria-label="Close">
                                            <i class="fa-solid fa-xmark"></i>
                                        </button>

                                    </div>

                                    <div class="material-modal-body">

                                        <div class="material-teacher-row">

                                            <div class="material-teacher-info">

                                                <?php echo avatarHtml($row['profile_image'], 'material-teacher-avatar', 'material-avatar-default'); ?>

                                                <div class="material-teacher-details">

                                                    <strong>
                                                        <?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>
                                                    </strong>

                                                    <span>
                                                        <?php echo date("M d, Y h:i A", strtotime($row['created_at'])); ?>
                                                    </span>

                                                </div>

                                            </div>

                                            <a href="pin_material.php?id=<?php echo (int)$row['id']; ?>"
                                               class="material-bookmark-btn"
                                               title="<?php echo $row['pinned'] ? 'Unpin material' : 'Pin material'; ?>">

                                                <i class="<?php echo $row['pinned'] ? 'fa-solid' : 'fa-regular'; ?> fa-bookmark"></i>

                                            </a>

                                        </div>

                                        <div class="material-divider"></div>

                                        <?php if (!empty($row['description'])): ?>
                                            <div class="material-caption">
                                                <?php echo nl2br(htmlspecialchars($row['description'], ENT_QUOTES, 'UTF-8')); ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="material-caption material-caption-empty">
                                                No caption provided.
                                            </div>
                                        <?php endif; ?>

                                        <?php
                                        $mAtts = $matAttachMap[$row['id']] ?? [];

                                        if (!empty($row['file'])) {
                                            $mAtts[] = [
                                                'type' => 'file',
                                                'value' => $row['file']
                                            ];
                                        }

                                        if (!empty($row['link'])) {
                                            $mAtts[] = [
                                                'type' => 'link',
                                                'value' => $row['link']
                                            ];
                                        }

                                        $mLinks = [];
                                        $mFiles = [];

                                        foreach ($mAtts as $ma) {
                                            if ($ma['type'] === 'link') {
                                                $mLinks[] = $ma['value'];
                                            } elseif ($ma['type'] === 'file') {
                                                $mFiles[] = $ma['value'];
                                            }
                                        }

                                        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                                        ?>

                                        <!-- MATERIAL LINKS -->
                                        <?php foreach ($mLinks as $lnk): ?>

                                            <a href="<?php echo htmlspecialchars($lnk, ENT_QUOTES, 'UTF-8'); ?>"
                                               target="_blank"
                                               rel="noopener noreferrer"
                                               class="material-link-box">

                                                <i class="fa-solid fa-link"></i>

                                                <span>
                                                    <?php echo htmlspecialchars($lnk, ENT_QUOTES, 'UTF-8'); ?>
                                                </span>

                                                <i class="fa-solid fa-arrow-up-right-from-square"></i>

                                            </a>

                                        <?php endforeach; ?>

                                        <!-- MATERIAL FILES -->
                                        <?php if ($mFiles): ?>

                                            <div class="material-file-grid">

                                                <?php foreach ($mFiles as $mf):

                                                    $fileName = basename($mf);
                                                    $ext = strtolower(pathinfo($mf, PATHINFO_EXTENSION));
                                                    $url = 'uploads/' . rawurlencode($fileName);

                                                ?>

                                                    <div class="material-file-item">

                                                        <?php if (in_array($ext, $imageExtensions)): ?>

                                                            <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>"
                                                               target="_blank"
                                                               rel="noopener"
                                                               class="material-file-preview image-preview">

                                                                <img
                                                                    src="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>"
                                                                    alt="<?php echo htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8'); ?>"
                                                                >

                                                            </a>

                                                        <?php else: ?>

                                                            <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>"
                                                               target="_blank"
                                                               rel="noopener"
                                                               class="material-file-preview">

                                                                <i class="fa-solid <?php echo fileIconClass($ext); ?>"></i>

                                                            </a>

                                                        <?php endif; ?>

                                                        <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>"
                                                           target="_blank"
                                                           rel="noopener"
                                                           class="material-file-name">

                                                            <?php echo htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8'); ?>

                                                        </a>

                                                    </div>

                                                <?php endforeach; ?>

                                            </div>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            </div>

                        </div>

                    <?php endif; ?>

                <?php endwhile; ?>

                <?php mysqli_stmt_close($streamStmt); ?>

            </div>

        </div>

    </div>

</div>

<!-- FLOATING GROUP CHAT -->
<div class="gchat-widget" id="gchatWidget">

    <div class="gchat-header">

        <div class="gchat-header-left">
            <i class="fa-regular fa-comments me-2"></i>
            <span>Group Chat</span>
        </div>

        <button class="gchat-close-btn" onclick="toggleClassChat()">X</button>

    </div>

    <div class="gchat-body" id="gchatBody">

        <div class="gchat-empty" id="gchatEmpty">
            No messages yet. Say hello! 👋
        </div>

    </div>

    <div class="gchat-preview" id="gchatPreview" hidden></div>

    <div class="gchat-footer">

        <label for="gchatFileUpload" class="gchat-icon-btn" title="Attach file">
            <i class="fa-solid fa-paperclip"></i>
        </label>

        <input type="file"
               id="gchatFileUpload"
               accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.csv,.txt,.zip,.rar"
               hidden>

        <label for="gchatImageUpload" class="gchat-icon-btn" title="Send image">
            <i class="fa-regular fa-image"></i>
        </label>

        <input type="file"
               id="gchatImageUpload"
               accept="image/*"
               hidden>

        <textarea id="gchatInput"
                  class="gchat-textarea"
                  placeholder="Aa (paste a link to share it)"
                  rows="1"></textarea>

        <button class="gchat-send-btn" id="gchatSendBtn" title="Send">
            <i class="fa-regular fa-paper-plane"></i>
        </button>

    </div>

</div>

<button class="gchat-fab"
        id="gchatFab"
        onclick="toggleClassChat()"
        title="Group Chat">

    <i class="fa-regular fa-comments"></i>

</button>

<!-- GOOGLE MEET MODAL -->
<div class="modal fade" id="meetModal" tabindex="-1">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content meet-modal-content">

            <div class="modal-header">

                <h5 class="modal-title fw-bold">
                    Google Meet Link
                </h5>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal">
                </button>

            </div>

            <div class="modal-body">

                <p class="meet-modal-desc">

                    1. Click "Create Google Meet" to generate a new meeting on Google.<br>

                    2. Once the meeting opens, copy its link (e.g. https://meet.google.com/abc-defg-hij).<br>

                    3. Paste it below and save. This becomes the permanent Meet link for this class — students will use the same link.

                </p>

                <a href="https://meet.google.com/new"
                   target="_blank"
                   rel="noopener"
                   class="btn-create-meet">

                    <i class="fa-solid fa-video me-2"></i>
                    Create Google Meet

                </a>

                <div class="meet-input-group">

                    <input type="text"
                           id="meetLinkInput"
                           class="meet-link-input"
                           placeholder="https://meet.google.com/xxx-xxxx-xxx"
                           value="<?php echo htmlspecialchars($class['meet_link'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

                    <div id="meetLinkError" class="meet-link-error"></div>

                </div>

            </div>

            <div class="modal-footer border-0">

                <button type="button"
                        class="btn-modal-cancel"
                        data-bs-dismiss="modal">
                    Cancel
                </button>

                <button type="button"
                        class="btn-modal-save"
                        id="saveMeetBtn"
                        onclick="saveMeetLink()">
                    Save
                </button>

            </div>

        </div>

    </div>

</div>

<!-- ANNOUNCEMENT LINK MODAL -->
<div class="modal fade" id="linkModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content meet-modal-content">

            <div class="modal-header">

                <h5 class="modal-title fw-bold">
                    Add link
                </h5>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal">
                </button>

            </div>

            <div class="modal-body">

                <input type="text"
                       id="announceLinkInput"
                       class="meet-link-input"
                       placeholder="https://example.com"
                       autocomplete="off">

                <div id="announceLinkError" class="meet-link-error"></div>

            </div>

            <div class="modal-footer border-0">

                <button type="button"
                        class="btn-modal-cancel"
                        data-bs-dismiss="modal">
                    Cancel
                </button>

                <button type="button"
                        class="btn-modal-save"
                        onclick="saveAnnounceLink()">
                    Add link
                </button>

            </div>

        </div>

    </div>

</div>

<script src="class_chat_actions.js?v=2"></script>
<script src="class_chat_unread.js?v=2"></script>
<script src="class_chat_mentions.js?v=1"></script>

<!-- GROUP CHAT JAVASCRIPT -->
<script>
(function () {

    const CLASS_ID = <?php echo json_encode($id); ?>;
    const FETCH_URL = 'class_chat_fetch.php';
    const SEND_URL = 'class_chat_send.php';

    const POLL_MS = 3000;
    const MAX_BYTES = 10 * 1024 * 1024;

    let lastId = 0;
    let timer = null;
    let isOpen = false;
    let polling = false;
    let sending = false;
    let pendingFile = null;

    const widget = document.getElementById('gchatWidget');
    const fab = document.getElementById('gchatFab');
    const body = document.getElementById('gchatBody');
    const empty = document.getElementById('gchatEmpty');
    const input = document.getElementById('gchatInput');
    const sendBtn = document.getElementById('gchatSendBtn');
    const imgInput = document.getElementById('gchatImageUpload');
    const fileInput = document.getElementById('gchatFileUpload');
    const preview = document.getElementById('gchatPreview');

    ChatMentions.init({ meId: <?php echo (int)$teacher_id; ?> });
    ChatMentions.attach({ input: input, classId: CLASS_ID });

    ChatActions.init({
        classId: CLASS_ID,
        chatBody: body,
        linkify: ChatMentions.render,
        emptyId: 'gchatEmpty',
        classes: {
            row: 'gchat-row',
            bubble: 'gchat-bubble',
            time: 'gchat-time',
            col: 'gchat-col'
        }
    });

    const fabBadge = document.createElement('b');
    fabBadge.className = 'gchat-fab-badge';
    fabBadge.hidden = true;
    fab.appendChild(fabBadge);

    ChatUnread.onUpdate(function (u) {
        const n = isOpen ? 0 : (u.counts[CLASS_ID] || 0);
        const mn = isOpen ? 0 : ((u.mentions && u.mentions[CLASS_ID]) || 0);

        fabBadge.textContent = (mn > 0 ? '@' : '') + (n > 99 ? '99+' : n);
        fabBadge.classList.toggle('has-mention', mn > 0);
        fabBadge.title = mn > 0 ? 'You were mentioned' : '';
        fabBadge.hidden = n === 0;
    });

    function markReadIfVisible() {
        if (isOpen && lastId > 0 && !document.hidden) {
            ChatUnread.markRead(CLASS_ID, lastId);
        }
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) markReadIfVisible();
    });

    window.toggleClassChat = function () {
        isOpen = !isOpen;

        widget.classList.toggle('open', isOpen);
        fab.classList.toggle('hidden', isOpen);

        if (isOpen) {
            startPolling();
            scrollBottom();
            input.focus();
        } else {
            stopPolling();
        }
    };

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[c]));
    }

    function linkify(text) {
        return esc(text).replace(
            /(https?:\/\/[^\s<]+)/g,
            '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>'
        );
    }

    function fmtTime(raw) {
        const d = new Date(String(raw).replace(' ', 'T'));

        return d.toLocaleTimeString([], {
            hour: 'numeric',
            minute: '2-digit'
        });
    }

    function scrollBottom() {
        body.scrollTop = body.scrollHeight;
    }

    function imageHtml(url, alt) {
        return `
            <a href="${url}" target="_blank" rel="noopener">
                <img src="${url}" class="gchat-img" alt="${esc(alt)}">
            </a>
        `;
    }

    function fileHtml(url, name) {
        return `
            <a href="${url}"
               target="_blank"
               rel="noopener"
               download="${esc(name)}"
               class="gchat-file">
                <i class="fa-solid fa-paperclip"></i>
                <span>${esc(name)}</span>
            </a>
        `;
    }

    const DEFAULT_IMGS = ['', 'default.png', 'default.jpg', 'default.jpeg', 'null'];

    function profileSrc(value) {
        const raw = String(value || '').trim().replace(/\\/g, '/');

        if (raw.includes('..')) return '';

        const name = raw.split('/').pop();

        if (DEFAULT_IMGS.includes(name.toLowerCase())) return '';

        if (raw.includes('/')) {
            return encodeURI(raw.replace(/^\/+/, ''));
        }

        return 'uploads/' + encodeURIComponent(name);
    }

    function renderMsg(m) {

        if (empty) empty.style.display = 'none';

        const row = document.createElement('div');
        row.className = 'gchat-row ' + (m.is_me ? 'me' : 'other');

        const src = profileSrc(m.profile_image);

        const avatar = src
            ? `<img src="${esc(src)}" class="gchat-avatar" alt="">`
            : `<div class="gchat-avatar gchat-avatar-def"><i class="fa-solid fa-user"></i></div>`;

        let content = '';
        const msg = (m.message || '').trim();

        if (m.attachment) {
            const url = 'uploads/' + encodeURI(m.attachment);

            content += (m.attachment_type === 'image')
                ? imageHtml(url, m.attachment_name)
                : fileHtml(url, m.attachment_name || 'File');
        }

        const oldImg = msg.match(/^\[image:(.+)\]$/);
        const oldFile = msg.match(/^\[file:(.+)\]$/);

        if (oldImg) {
            content += imageHtml('uploads/' + encodeURI(oldImg[1]), 'image');
        } else if (oldFile) {
            content += fileHtml(
                'uploads/' + encodeURI(oldFile[1]),
                oldFile[1].replace(/^\d+_/, '')
            );
        } else if (msg !== '') {
            content += `<div class="gchat-bubble">${ChatMentions.render(m.message, m.mentions)}</div>`;
        }

        if (m.is_deleted) {
            content = '<div class="gchat-bubble msg-deleted"><i class="fa-solid fa-ban"></i> This message was deleted</div>';
        }

        row.innerHTML = `
            ${m.is_me ? '' : avatar}
            <div class="gchat-col">
                ${m.is_me ? '' : `<div class="gchat-name">${esc(m.name)}</div>`}
                ${content}
                <div class="gchat-time">${fmtTime(m.created_at)}</div>
            </div>
            ${m.is_me ? avatar : ''}
        `;

        row.querySelectorAll('img.gchat-avatar').forEach(img => {
            img.addEventListener('error', () => {
                const d = document.createElement('div');
                d.className = 'gchat-avatar gchat-avatar-def';
                d.innerHTML = '<i class="fa-solid fa-user"></i>';
                img.replaceWith(d);
            });
        });

        body.appendChild(row);
        ChatActions.decorate(row, m);

        lastId = Math.max(lastId, Number(m.id));
    }

    async function poll() {

        if (polling) return;

        polling = true;

        try {

            const r = await fetch(
                `${FETCH_URL}?class_id=${CLASS_ID}&after_id=${lastId}`
            );

            if (!r.ok) {
                throw new Error('HTTP ' + r.status);
            }

            const d = await r.json();

            if (d.messages && d.messages.length) {
                d.messages.forEach(renderMsg);
                scrollBottom();
                markReadIfVisible();
            } else if (d.error) {
                console.error('Chat poll error:', d.error);
            }

        } catch (e) {
            console.error('Chat poll:', e);
        } finally {
            polling = false;
        }
    }

    async function tick() {
        await poll();
        await ChatActions.sync();
    }

    function startPolling() {
        tick();

        if (!timer) {
            timer = setInterval(tick, POLL_MS);
        }
    }

    function stopPolling() {
        clearInterval(timer);
        timer = null;
    }

    /* ATTACHMENT PICKING */

    function setPending(file, kind) {

        if (!file) return;

        if (file.size > MAX_BYTES) {
            alert('File is too large (max 10MB).');
            imgInput.value = '';
            fileInput.value = '';
            return;
        }

        pendingFile = file;

        if (kind === 'image') {
            fileInput.value = '';
        } else {
            imgInput.value = '';
        }

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
        x.type = 'button';
        x.className = 'gchat-preview-x';
        x.textContent = '×';
        x.onclick = clearPending;

        preview.append(name, x);
    }

    function clearPending() {
        pendingFile = null;
        imgInput.value = '';
        fileInput.value = '';
        preview.hidden = true;
        preview.innerHTML = '';
    }

    imgInput.addEventListener('change', () => {
        setPending(imgInput.files[0], 'image');
    });

    fileInput.addEventListener('change', () => {
        setPending(fileInput.files[0], 'file');
    });

    /* SENDING MESSAGES */

    async function sendMessage() {

        if (sending) return;

        const text = input.value.trim();

        if (!text && !pendingFile) return;

        sending = true;

        const fd = new FormData();

        fd.append('class_id', CLASS_ID);
        fd.append('message', text);

        if (pendingFile) {
            fd.append('attachment', pendingFile);
        }

        input.disabled = true;
        sendBtn.disabled = true;

        try {

            const r = await fetch(SEND_URL, {
                method: 'POST',
                body: fd
            });

            const raw = await r.text();

            let d;

            try {
                d = JSON.parse(raw);
            } catch (e) {

                console.error('Send: non-JSON response', r.status, raw);

                alert(
                    'Server error (HTTP ' + r.status + '):\n' +
                    raw.slice(0, 300)
                );

                return;
            }

            if (d.success) {
                input.value = '';
                input.style.height = 'auto';
                clearPending();
                await poll();
            } else {
                alert(d.error || 'Send failed');
            }

        } catch (e) {
            console.error('Chat send:', e);
            alert('Send failed: ' + e.message);
        } finally {
            sending = false;
            input.disabled = false;
            sendBtn.disabled = false;
            input.focus();
        }
    }

    sendBtn.addEventListener('click', sendMessage);

    input.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    input.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 80) + 'px';
    });

})();
</script>

<!-- SIDEBAR JAVASCRIPT -->
<script>

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

function toggleStreamContent(e, headerEl) {
    if (e.target.closest('.dropdown')) return;

    const card = headerEl.closest('.announcement-card');

    if (!card) return;

    const content = card.querySelector('.stream-collapsible');
    const icon = headerEl.querySelector('.stream-toggle-icon');

    if (!content || !icon) return;

    content.classList.toggle('show');
    icon.classList.toggle('rotated');
}

</script>

<!-- ANNOUNCEMENT ATTACHMENTS JAVASCRIPT -->
<script>

(function () {

    const MAX_ITEMS = 10;
    const MAX_MB = 10;

    const IMG_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

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

        msgTimer = setTimeout(() => {
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

        items.forEach(it => {
            if (it.kind === 'file') {
                dt.items.add(it.file);
            }
        });

        realInput.files = dt.files;
    }

    function syncLinkInputs() {
        linksHidden.innerHTML = '';

        items.forEach(it => {
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

        items.forEach((it, index) => {

            const chip = document.createElement('div');
            chip.className = 'attach-chip';

            let label;

            if (it.kind === 'file' && IMG_EXT.includes(ext(it.file.name))) {

                const img = document.createElement('img');

                img.className = 'attach-thumb';
                img.src = it.url;
                img.alt = '';

                chip.appendChild(img);
                label = it.file.name;

            } else {

                const i = document.createElement('i');

                i.className = 'fa-solid type-icon ' +
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

            rm.addEventListener('click', () => removeItem(index));

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

        Array.from(fileList).forEach(f => {

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

            const duplicate = items.some(it =>
                it.kind === 'file' &&
                it.file.name === f.name &&
                it.file.size === f.size &&
                it.file.lastModified === f.lastModified
            );

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

    pickImages.addEventListener('change', () => {
        addFiles(pickImages.files);
        pickImages.value = '';
    });

    pickFiles.addEventListener('change', () => {
        addFiles(pickFiles.files);
        pickFiles.value = '';
    });

    /* LINK MODAL */

    window.openLinkModal = function () {

        const errorBox = document.getElementById('announceLinkError');

        document.getElementById('announceLinkInput').value = '';
        errorBox.textContent = '';

        if (total() >= MAX_ITEMS) {
            showMsg(
                'You can attach up to ' + MAX_ITEMS +
                ' items (images, files and links combined).'
            );
            return;
        }

        bootstrap.Modal.getOrCreateInstance(
            document.getElementById('linkModal')
        ).show();
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

        if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
            errorBox.textContent = 'Only http:// and https:// links are allowed.';
            return;
        }

        if (total() >= MAX_ITEMS) {
            errorBox.textContent = 'You can attach up to ' + MAX_ITEMS + ' items.';
            return;
        }

        if (items.some(it => it.kind === 'link' && it.url === link)) {
            errorBox.textContent = 'This link is already attached.';
            return;
        }

        items.push({
            kind: 'link',
            url: link
        });

        render();

        bootstrap.Modal.getOrCreateInstance(
            document.getElementById('linkModal')
        ).hide();
    };

    document.getElementById('announceLinkInput')
        .addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                window.saveAnnounceLink();
            }
        });

    document.getElementById('linkModal')
        .addEventListener('shown.bs.modal', function () {
            document.getElementById('announceLinkInput').focus();
        });

    /* PREVENT EMPTY ANNOUNCEMENTS */

    form.addEventListener('submit', function (e) {
        if (textArea.value.trim() === '' && total() === 0) {
            e.preventDefault();

            showMsg(
                'Write something or attach an image, file or link first.'
            );
        }
    });

    render();

})();

</script>

<!-- GOOGLE MEET JAVASCRIPT -->
<script>

const CLASS_ID_FOR_MEET = <?php echo json_encode($id); ?>;

function openMeetModal() {
    const modalEl = document.getElementById('meetModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
}

async function saveMeetLink() {

    const input = document.getElementById('meetLinkInput');
    const errorBox = document.getElementById('meetLinkError');

    const link = input.value.trim();

    errorBox.textContent = '';

    const pattern = /^https:\/\/meet\.google\.com\/[a-z0-9-]+$/i;

    if (!pattern.test(link)) {
        errorBox.textContent =
            'Invalid link format. It should look like https://meet.google.com/xxx-xxxx-xxx';
        return;
    }

    const saveBtn = document.getElementById('saveMeetBtn');

    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving...';

    try {

        const res = await fetch('save_meet_link.php', {
            method: 'POST',
            body: new URLSearchParams({
                class_id: CLASS_ID_FOR_MEET,
                meet_link: link
            })
        });

        const data = await res.json();

        if (data.success) {
            location.reload();
        } else {
            errorBox.textContent = data.error || 'Save failed. Please try again.';
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save';
        }

    } catch (e) {

        errorBox.textContent = 'Network error. Please try again.';
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save';

    }
}

</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
