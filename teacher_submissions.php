<?php
session_start();

require_once 'database.php';
require_once 'auth_helpers.php';
require_once 'assessment_attachments.php';

/* =========================================
   HELPER FUNCTIONS
========================================= */

function hasValidProfileImage($file) {
    if (empty($file)) {
        return false;
    }

    if (in_array(strtolower($file), [
        'default.png',
        'default.jpg',
        'default.jpeg',
        'default.svg',
        'null'
    ], true)) {
        return false;
    }

    return file_exists(__DIR__ . '/uploads/' . basename($file));
}

function e($value) {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function attachment_icon($name) {
    $ext = strtolower(
        pathinfo((string)$name, PATHINFO_EXTENSION)
    );

    switch ($ext) {
        case 'pdf':
            return 'fa-file-pdf';

        case 'doc':
        case 'docx':
            return 'fa-file-word';

        case 'ppt':
        case 'pptx':
            return 'fa-file-powerpoint';

        case 'xls':
        case 'xlsx':
            return 'fa-file-excel';

        case 'txt':
            return 'fa-file-lines';

        default:
            return 'fa-file';
    }
}

/* =========================================
   LOGIN AND ROLE VALIDATION
========================================= */

$role = $_SESSION['role'] ?? '';

if (
    empty($_SESSION['user_id']) ||
    !in_array($role, ['teacher', 'program_chair'], true)
) {
    header("Location: login.php");
    exit();
}

$teacher_id = (int)$_SESSION['user_id'];
$isChair = ($role === 'program_chair');

/* =========================================
   VALIDATE ASSESSMENT ID
========================================= */

$assessment_id = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$assessment_id || $assessment_id <= 0) {
    http_response_code(400);
    exit("Invalid assessment.");
}

/* =========================================
   GET ASSESSMENT AND CLASS INFORMATION
========================================= */

$aStmt = mysqli_prepare($conn, "
    SELECT
        a.*,
        c.id AS class_id,
        c.class_name,
        c.section,
        c.academic_year,
        c.teacher_id AS primary_teacher_id,
        c.program
    FROM assessments a
    INNER JOIN classes c
        ON c.id = a.class_id
    WHERE a.id = ?
    LIMIT 1
");

if (!$aStmt) {
    http_response_code(500);
    exit("Unable to retrieve assessment information.");
}

mysqli_stmt_bind_param($aStmt, "i", $assessment_id);
mysqli_stmt_execute($aStmt);

$assessmentResult = mysqli_stmt_get_result($aStmt);
$assessment = mysqli_fetch_assoc($assessmentResult);

mysqli_stmt_close($aStmt);

if (!$assessment) {
    http_response_code(404);
    exit("Assessment not found.");
}

$class_id = (int)$assessment['class_id'];

/* =========================================
   CLASS ACCESS VALIDATION
========================================= */

if ($role === 'teacher') {

    // Primary instructor or assigned co-instructor.
    $accessStmt = mysqli_prepare($conn, "
        SELECT c.id
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
        LIMIT 1
    ");

    if (!$accessStmt) {
        http_response_code(500);
        exit("Unable to verify class access.");
    }

    mysqli_stmt_bind_param(
        $accessStmt,
        "iii",
        $class_id,
        $teacher_id,
        $teacher_id
    );

    mysqli_stmt_execute($accessStmt);

    $accessResult = mysqli_stmt_get_result($accessStmt);
    $hasAccess = mysqli_fetch_assoc($accessResult);

    mysqli_stmt_close($accessStmt);

    if (!$hasAccess) {
        http_response_code(403);
        exit("Access denied. You are not assigned to this class.");
    }

} elseif ($role === 'program_chair') {

    if (!getClassAccess($conn, $class_id, $teacher_id, $role)) {
        http_response_code(403);
        exit("Access denied. This class is not under your program.");
    }
}

/* =========================================
   GET LOGGED-IN USER
========================================= */

$uStmt = mysqli_prepare(
    $conn,
    "SELECT id, name, profile_image, role
     FROM users
     WHERE id = ?
     LIMIT 1"
);

if (!$uStmt) {
    http_response_code(500);
    exit("Unable to retrieve user information.");
}

mysqli_stmt_bind_param($uStmt, "i", $teacher_id);
mysqli_stmt_execute($uStmt);

$userResult = mysqli_stmt_get_result($uStmt);
$user = mysqli_fetch_assoc($userResult);

mysqli_stmt_close($uStmt);

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

/* =========================================
   FLASH MESSAGE
========================================= */

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* =========================================
   GET ACTIVE STUDENTS AND SUBMISSIONS
========================================= */

$sStmt = mysqli_prepare($conn, "
    SELECT
        u.id AS student_id,
        u.name,
        u.profile_image,

        s.id AS submission_id,
        s.file_path,
        s.status,
        s.submitted_at,
        s.grade,
        s.graded_at

    FROM class_students cs

    INNER JOIN users u
        ON u.id = cs.student_id

    LEFT JOIN submissions s
        ON s.student_id = u.id
        AND s.assessment_id = ?

    WHERE cs.class_id = ?
      AND (cs.archived = 0 OR cs.archived IS NULL)
      AND u.role = 'student'

    ORDER BY
        (s.status IS NULL) ASC,
        u.name ASC
");

if (!$sStmt) {
    http_response_code(500);
    exit("Unable to retrieve student submissions.");
}

mysqli_stmt_bind_param(
    $sStmt,
    "ii",
    $assessment_id,
    $class_id
);

mysqli_stmt_execute($sStmt);

$result = mysqli_stmt_get_result($sStmt);

$students = [];

while ($row = mysqli_fetch_assoc($result)) {
    $students[] = $row;
}

mysqli_stmt_close($sStmt);

/* =========================================
   SUBMISSION STATISTICS
========================================= */

$total = count($students);
$turnedIn = 0;
$graded = 0;

foreach ($students as $student) {

    if (in_array($student['status'], ['submitted', 'done'], true)) {
        $turnedIn++;
    }

    if ($student['grade'] !== null) {
        $graded++;
    }
}

/* =========================================
   GET SUBMISSION ATTACHMENTS
========================================= */

$submissionIds = [];

foreach ($students as $student) {
    if (!empty($student['submission_id'])) {
        $submissionIds[] = (int)$student['submission_id'];
    }
}

$attachmentsBySubmission = [];

if (!empty($submissionIds)) {

    $placeholders = implode(
        ',',
        array_fill(0, count($submissionIds), '?')
    );

    $types = str_repeat('i', count($submissionIds));

    $attStmt = mysqli_prepare(
        $conn,
        "
        SELECT *
        FROM submission_attachments
        WHERE submission_id IN ($placeholders)
        ORDER BY id ASC
        "
    );

    if (!$attStmt) {
        http_response_code(500);
        exit("Unable to retrieve submission attachments.");
    }

    $bindArgs = array_merge(
        [$attStmt, $types],
        $submissionIds
    );

    $bindRefs = [];

    foreach ($bindArgs as $key => $value) {
        $bindRefs[$key] = &$bindArgs[$key];
    }

    call_user_func_array(
        'mysqli_stmt_bind_param',
        $bindRefs
    );

    mysqli_stmt_execute($attStmt);

    $attResult = mysqli_stmt_get_result($attStmt);

    while ($row = mysqli_fetch_assoc($attResult)) {
        $attachmentsBySubmission[$row['submission_id']][] = $row;
    }

    mysqli_stmt_close($attStmt);
}

/* =========================================
   GET ASSESSMENT ATTACHMENTS
========================================= */

$description = $assessment['instructions'] ?? '';

$assessmentAttachments = aa_fetch($conn, $assessment_id);

// Backward compatibility for older assessments.
if (empty($assessmentAttachments)) {

    if (!empty($assessment['image'])) {
        $assessmentAttachments[] = [
            'type' => 'image',
            'file_path' => $assessment['image'],
            'original_name' => $assessment['image'],
            'url' => null
        ];
    }

    if (!empty($assessment['attachment'])) {
        $assessmentAttachments[] = [
            'type' => 'file',
            'file_path' => $assessment['attachment'],
            'original_name' => preg_replace(
                '/^assessment_file_[0-9a-f]{13}(?:\.[0-9]+)?_/',
                '',
                $assessment['attachment']
            ),
            'url' => null
        ];
    }

    if (!empty($assessment['link'])) {
        $assessmentAttachments[] = [
            'type' => 'link',
            'file_path' => null,
            'original_name' => null,
            'url' => $assessment['link']
        ];
    }
}

/* =========================================
   ORGANIZE ASSESSMENT ATTACHMENTS
========================================= */

$attImages = [];
$attFiles = [];
$attLinks = [];

foreach ($assessmentAttachments as $att) {

    if (($att['type'] ?? '') === 'image') {
        $attImages[] = $att;

    } elseif (($att['type'] ?? '') === 'file') {
        $attFiles[] = $att;

    } elseif (($att['type'] ?? '') === 'link') {
        $attLinks[] = $att;
    }
}

$attachmentTotal = count($assessmentAttachments);
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo e($assessment['title']); ?> - Submissions</title>

    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link rel="stylesheet" href="teacher_class.css?v=5">

    <link rel="stylesheet" href="teacher_submissions.css?v=3">

    <style>

        /* =========================================
           ASSESSMENT ATTACHMENTS
        ========================================= */

        .attach-section-title {
            font-size: 13px;
            font-weight: 700;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin: 20px 0 10px;
        }

        .tab-count {
            display: inline-block;
            min-width: 20px;
            margin-left: 6px;
            padding: 1px 7px;
            font-size: 11.5px;
            font-weight: 700;
            color: #3f87b5;
            background: #eaf3fa;
            border-radius: 20px;
            text-align: center;
        }

        .instr-images {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
        }

        .instr-images a {
            display: block;
        }

        .instr-images img {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #e8eef4;
            display: block;
            transition: box-shadow 0.2s, transform 0.2s;
        }

        .instr-images a:hover img {
            box-shadow: 0 4px 12px rgba(0,0,0,.12);
            transform: translateY(-1px);
        }

        .instr-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .instr-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 14px;
            background: #f8fafc;
            border: 1px solid #e0e8f0;
            border-radius: 9px;
            color: #222;
            text-decoration: none;
            font-size: 14px;
            min-width: 0;
            transition: border-color 0.2s, background 0.2s;
        }

        .instr-item i {
            color: #3f87b5;
            font-size: 18px;
            width: 22px;
            text-align: center;
            flex-shrink: 0;
        }

        .instr-item span {
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .instr-item:hover {
            border-color: #3f87b5;
            background: #f0f6fb;
            color: #222;
        }

        .instr-item .open-icon {
            color: #bbb;
            font-size: 13px;
            width: auto;
        }

        /* =========================================
           MOBILE
        ========================================= */

        @media (max-width: 768px) {

            .instr-images {
                grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
            }

            .instr-images img {
                height: 110px;
            }

            .instr-item {
                padding: 10px 12px;
                font-size: 13.5px;
            }
        }

    </style>

</head>

<body>

<!-- =========================================
     SIDEBAR
========================================= -->

<div class="sidebar" id="sidebar">

    <div class="sidebar-header">

        <button class="toggle-btn" onclick="toggleSidebar()">
            <i class="fa-solid fa-bars"></i>
        </button>

        <button class="sidebar-close-btn" onclick="mobileSidebar()" aria-label="Close menu">
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

<!-- MOBILE SIDEBAR OVERLAY -->

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
    onclick="mobileSidebar()">
</div>

<!-- =========================================
     MAIN CONTENT
========================================= -->

<div class="main-content" id="mainContent">

    <!-- TOPBAR -->

    <div class="topbar">

        <div class="navbar-left">

            <button class="mobile-menu" onclick="mobileSidebar()">
                <i class="fa-solid fa-bars"></i>
            </button>

            <img
                src="img/classroom logo.png"
                class="navbar-logo"
                alt="PLMUN Classroom Logo">

        </div>

        <div class="navbar-right">

            <?php
                $profileFile = $user['profile_image'] ?? '';
                $hasProfileImage = hasValidProfileImage($profileFile);
            ?>

            <a href="profile.php" class="profile-icon">

                <?php if ($hasProfileImage): ?>

                    <img
                        src="uploads/<?php echo e(basename($profileFile)); ?>"
                        alt="Profile"
                        onerror="this.style.display='none'; this.parentElement.insertAdjacentHTML('beforeend','<i class=&quot;fa-solid fa-user&quot;></i>');">

                <?php else: ?>

                    <i class="fa-solid fa-user"></i>

                <?php endif; ?>

            </a>

        </div>

    </div>

    <!-- =====================================
         PAGE CONTENT
    ====================================== -->

    <div class="container py-4">

        <!-- BACK BUTTON -->

        <a
            href="teacher_assessment.php?id=<?php echo $class_id; ?>"
            class="back-link">

            <i class="fa-solid fa-arrow-left"></i>
            Back to Assessments

        </a>

        <!-- FLASH MESSAGE -->

        <?php if ($flash): ?>

            <div class="assess-flash mb-3">

                <i class="fa-solid fa-circle-check me-2"></i>

                <?php echo e($flash); ?>

            </div>

        <?php endif; ?>

        <!-- =================================
             SUBMISSION TABS
        ================================== -->

        <div class="submissions-tabs">

            <button
                type="button"
                class="submissions-tab"
                id="tabBtn-instructions"
                onclick="showSubTab('instructions')">

                Instructions

                <?php if ($attachmentTotal > 0): ?>

                    <span class="tab-count">
                        <i class="fa-solid fa-paperclip"></i>
                        <?php echo $attachmentTotal; ?>
                    </span>

                <?php endif; ?>

            </button>

            <button
                type="button"
                class="submissions-tab active"
                id="tabBtn-studentwork"
                onclick="showSubTab('studentwork')">

                Student work

            </button>

        </div>

        <!-- =================================
             INSTRUCTIONS PANEL
        ================================== -->

        <div
            class="submissions-panel"
            id="panel-instructions"
            style="display:none;">

            <div class="instructions-card">

                <h2 class="instructions-title">
                    <?php echo e($assessment['title']); ?>
                </h2>

                <div class="instructions-meta">

                    <span class="meta-item">
                        <i class="fa-solid fa-tag"></i>
                        <?php echo e($assessment['type']); ?>
                    </span>

                    <span class="meta-item">
                        <i class="fa-solid fa-star"></i>
                        <?php echo e($assessment['points']); ?> pts
                    </span>

                    <span class="meta-item">
                        <i class="fa-regular fa-calendar"></i>
                        Due
                        <?php
                        echo !empty($assessment['due_date'])
                            ? date("F d, Y", strtotime($assessment['due_date']))
                            : 'No due date';
                        ?>
                    </span>

                </div>

                <!-- DESCRIPTION -->

                <?php if (!empty($description)): ?>

                    <div class="instructions-desc"><?php echo nl2br(e($description)); ?></div>

                <?php else: ?>

                    <div class="instructions-desc instructions-empty">No description provided.</div>

                <?php endif; ?>

                <!-- ATTACHMENTS -->

                <?php if ($attachmentTotal > 0): ?>

                <div class="instr-attachments">

                    <!-- IMAGES -->

                    <?php if (!empty($attImages)): ?>

                        <div class="attach-section-title">
                            Images (<?php echo count($attImages); ?>)
                        </div>

                        <div class="instr-images">

                            <?php foreach ($attImages as $img): ?>

                                <?php
                                $imagePath = basename($img['file_path'] ?? '');
                                ?>

                                <a
                                    href="uploads/<?php echo rawurlencode($imagePath); ?>"
                                    target="_blank"
                                    rel="noopener">

                                    <img
                                        src="uploads/<?php echo rawurlencode($imagePath); ?>"
                                        alt="<?php echo e($img['original_name'] ?: 'Attached image'); ?>"
                                        loading="lazy">

                                </a>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                    <!-- FILES -->

                    <?php if (!empty($attFiles)): ?>

                        <div class="attach-section-title">
                            Files (<?php echo count($attFiles); ?>)
                        </div>

                        <div class="instr-list">

                            <?php foreach ($attFiles as $f): ?>

                                <?php
                                $fname = $f['original_name']
                                    ?: basename($f['file_path'] ?? '');

                                $filePath = basename($f['file_path'] ?? '');
                                ?>

                                <a
                                    href="uploads/<?php echo rawurlencode($filePath); ?>"
                                    target="_blank"
                                    rel="noopener"
                                    class="instr-item">

                                    <i class="fa-solid <?php echo attachment_icon($fname); ?>"></i>

                                    <span><?php echo e($fname); ?></span>

                                    <i class="fa-solid fa-arrow-up-right-from-square open-icon"></i>

                                </a>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                    <!-- LINKS -->

                    <?php if (!empty($attLinks)): ?>

                        <div class="attach-section-title">
                            Links (<?php echo count($attLinks); ?>)
                        </div>

                        <div class="instr-list">

                            <?php foreach ($attLinks as $l): ?>

                                <?php
                                $linkUrl = trim((string)($l['url'] ?? ''));

                                $safeUrl = filter_var(
                                    $linkUrl,
                                    FILTER_VALIDATE_URL
                                ) && preg_match(
                                    '#^https?://#i',
                                    $linkUrl
                                ) ? $linkUrl : '';
                                ?>

                                <?php if ($safeUrl !== ''): ?>

                                    <a
                                        href="<?php echo e($safeUrl); ?>"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="instr-item">

                                        <i class="fa-solid fa-link"></i>

                                        <span><?php echo e($linkUrl); ?></span>

                                        <i class="fa-solid fa-arrow-up-right-from-square open-icon"></i>

                                    </a>

                                <?php else: ?>

                                    <div class="instr-item">

                                        <i class="fa-solid fa-link"></i>

                                        <span><?php echo e($linkUrl); ?></span>

                                    </div>

                                <?php endif; ?>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                </div>

                <?php endif; ?>

            </div>

        </div>

        <!-- =================================
             STUDENT WORK PANEL
        ================================== -->

        <div
            class="submissions-panel"
            id="panel-studentwork">

            <!-- HEADER -->

            <div class="submissions-header">

                <div>

                    <h2>
                        Assessment: <?php echo e($assessment['title']); ?>
                    </h2>

                </div>

                <div class="submissions-summary">

                    <div class="summary-pill">

                        <strong>
                            <?php echo $turnedIn; ?>/<?php echo $total; ?>
                        </strong>

                        <span>Turned In</span>

                    </div>

                    <div class="summary-pill">

                        <strong>
                            <?php echo $graded; ?>/<?php echo $total; ?>
                        </strong>

                        <span>Graded</span>

                    </div>

                </div>

            </div>

            <!-- STUDENT SUBMISSIONS TABLE -->

            <div class="submissions-table-wrap">

                <table class="submissions-table">

                    <thead>

                        <tr>

                            <th>Student Name</th>

                            <th>Submitted On</th>

                            <th>Status</th>

                            <th>Score</th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php if ($total === 0): ?>

                        <tr>

                            <td colspan="4">

                                <div class="empty-state">

                                    <i class="fa-regular fa-folder-open"></i>

                                    <p>No students in this class yet.</p>

                                </div>

                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($students as $s): ?>

                            <?php

                            $status = $s['status'] ?? null;

                            if ($status === 'done') {

                                $badge = [
                                    'label' => 'Marked done',
                                    'class' => 'badge-done'
                                ];

                            } elseif ($status === 'submitted') {

                                $badge = [
                                    'label' => 'Submitted',
                                    'class' => 'badge-submitted'
                                ];

                            } else {

                                $badge = [
                                    'label' => 'Missing',
                                    'class' => 'badge-missing'
                                ];

                            }

                            $subId = $s['submission_id'] ?? null;

                            $attachmentCount = $subId
                                ? count($attachmentsBySubmission[$subId] ?? [])
                                : 0;

                            $submittedAtDisplay = !empty($s['submitted_at'])
                                ? date("M d, h:i A", strtotime($s['submitted_at']))
                                : '';

                            $studentHasImage = hasValidProfileImage(
                                $s['profile_image'] ?? ''
                            );

                            $gradeValue = $s['grade'] !== null
                                ? e($s['grade'])
                                : '';

                            ?>

                            <tr>

                                <!-- STUDENT NAME -->

                                <td>

                                    <?php if ($subId): ?>

                                        <a
                                            href="view_submission.php?id=<?php echo (int)$subId; ?>"
                                            class="student-name-cell student-name-clickable">

                                            <?php if ($studentHasImage): ?>

                                                <img
                                                    src="uploads/<?php echo e(basename($s['profile_image'])); ?>"
                                                    class="submission-avatar"
                                                    alt="Student"
                                                    onerror="this.outerHTML='<div class=&quot;submission-avatar submission-avatar-def&quot;><i class=&quot;fa-solid fa-user&quot;></i></div>';">

                                            <?php else: ?>

                                                <div class="submission-avatar submission-avatar-def">

                                                    <i class="fa-solid fa-user"></i>

                                                </div>

                                            <?php endif; ?>

                                            <span><?php echo e($s['name']); ?></span>

                                            <?php if ($attachmentCount > 0): ?>

                                                <span class="attachment-count-badge">

                                                    <i class="fa-solid fa-paperclip"></i>

                                                    <?php echo $attachmentCount; ?>

                                                </span>

                                            <?php endif; ?>

                                        </a>

                                    <?php else: ?>

                                        <div class="student-name-cell">

                                            <?php if ($studentHasImage): ?>

                                                <img
                                                    src="uploads/<?php echo e(basename($s['profile_image'])); ?>"
                                                    class="submission-avatar"
                                                    alt="Student"
                                                    onerror="this.outerHTML='<div class=&quot;submission-avatar submission-avatar-def&quot;><i class=&quot;fa-solid fa-user&quot;></i></div>';">

                                            <?php else: ?>

                                                <div class="submission-avatar submission-avatar-def">

                                                    <i class="fa-solid fa-user"></i>

                                                </div>

                                            <?php endif; ?>

                                            <span><?php echo e($s['name']); ?></span>

                                        </div>

                                    <?php endif; ?>

                                </td>

                                <!-- SUBMITTED DATE -->

                                <td>

                                    <?php if (!empty($submittedAtDisplay)): ?>

                                        <?php echo e($submittedAtDisplay); ?>

                                    <?php else: ?>

                                        <span class="cell-muted">&mdash;</span>

                                    <?php endif; ?>

                                </td>

                                <!-- STATUS -->

                                <td>

                                    <span class="status-badge <?php echo e($badge['class']); ?>">

                                        <?php echo e($badge['label']); ?>

                                    </span>

                                </td>

                                <!-- GRADE -->

                                <td>

                                    <form
                                        action="save_grade.php"
                                        method="post"
                                        class="grade-form">

                                        <input
                                            type="hidden"
                                            name="assessment_id"
                                            value="<?php echo $assessment_id; ?>">

                                        <input
                                            type="hidden"
                                            name="student_id"
                                            value="<?php echo (int)$s['student_id']; ?>">

                                        <div class="grade-field">

                                            <input
                                                type="number"
                                                name="grade"
                                                class="grade-input"
                                                placeholder="--"
                                                min="0"
                                                max="<?php echo e($assessment['points']); ?>"
                                                step="0.5"
                                                value="<?php echo $gradeValue; ?>">

                                            <span class="grade-total">
                                                / <?php echo e($assessment['points']); ?>
                                            </span>

                                        </div>

                                        <button
                                            type="submit"
                                            class="btn-save"
                                            aria-label="Save grade">

                                            <i class="fa-solid fa-floppy-disk"></i>
                                            <span>Save</span>

                                        </button>

                                    </form>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>

<!-- =========================================
     JAVASCRIPT
========================================= -->

<script>

function toggleSidebar() {

    document.getElementById("sidebar")
        .classList.toggle("collapsed");

    document.getElementById("mainContent")
        .classList.toggle("expanded");

}

function mobileSidebar() {

    document.getElementById("sidebar")
        .classList.toggle("show");

    document.getElementById("sidebarOverlay")
        .classList.toggle("show");

    document.body.classList.toggle("no-scroll");

}

/* Close mobile sidebar with Escape key */

document.addEventListener("keydown", function(e) {

    if (
        e.key === "Escape" &&
        document.getElementById("sidebar").classList.contains("show")
    ) {
        mobileSidebar();
    }

});

/* =========================================
   SWITCH SUBMISSION TABS
========================================= */

function showSubTab(tab) {

    document.getElementById("panel-instructions").style.display =
        (tab === "instructions") ? "block" : "none";

    document.getElementById("panel-studentwork").style.display =
        (tab === "studentwork") ? "block" : "none";

    document.getElementById("tabBtn-instructions")
        .classList.toggle("active", tab === "instructions");

    document.getElementById("tabBtn-studentwork")
        .classList.toggle("active", tab === "studentwork");

}

</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>