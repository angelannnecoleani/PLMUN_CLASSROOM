
<?php
session_start();
require_once 'database.php';
require_once 'assessment_attachments.php';

/* =========================================
   LOGIN AND STUDENT ROLE VALIDATION
========================================= */

if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'student'
) {
    header("Location: login.php");
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Assessment not found");
}

$assessment_id = (int) $_GET['id'];
$student_id    = (int) $_SESSION['user_id'];

if ($assessment_id <= 0) {
    die("Assessment not found");
}

/* =========================================
   CHECK OPTIONAL CREATED_BY COLUMN
   Supports older databases without it.
========================================= */

function studentAssessmentHasCreatedBy($conn) {
    $result = mysqli_query($conn, "SHOW COLUMNS FROM assessments LIKE 'created_by'");

    if (!$result) {
        return false;
    }

    $exists = mysqli_num_rows($result) > 0;
    mysqli_free_result($result);

    return $exists;
}

$hasCreatedBy = studentAssessmentHasCreatedBy($conn);

/* =========================================
   CURRENT STUDENT + PROFILE IMAGE
========================================= */

$uq = mysqli_prepare($conn, "
    SELECT id, name, profile_image
    FROM users
    WHERE id = ?
      AND role = 'student'
    LIMIT 1
");

if (!$uq) {
    die("Unable to load student information.");
}

mysqli_stmt_bind_param($uq, "i", $student_id);
mysqli_stmt_execute($uq);

$userResult = mysqli_stmt_get_result($uq);
$user = mysqli_fetch_assoc($userResult);

mysqli_stmt_close($uq);

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

/* =========================================
   PROFILE IMAGE VALIDATION
========================================= */

$profileImageFile = '';
$hasProfileImage  = false;

$rawProfileImage = trim((string)($user['profile_image'] ?? ''));

$defaultImages = [
    '',
    'default.png',
    'default.jpg',
    'default.jpeg',
    'default.svg',
    'null'
];

if ($rawProfileImage !== '') {
    $safeName = basename($rawProfileImage);

    if (!in_array(strtolower($safeName), $defaultImages, true)) {
        $imagePath = __DIR__ . '/uploads/' . $safeName;

        if (is_file($imagePath) && is_readable($imagePath)) {
            $profileImageFile = $safeName;
            $hasProfileImage  = true;
        }
    }
}

/* =========================================
   MAXIMUM SUBMISSION ATTACHMENTS
========================================= */

$MAX_ATTACHMENTS = 10;

/* =========================================
   GET ASSESSMENT + CLASS + INSTRUCTOR
   If created_by exists, show the instructor
   who actually posted the assessment.
   Otherwise, use the primary instructor.
========================================= */

if ($hasCreatedBy) {
    $authorSelect = "
        COALESCE(
            NULLIF(assessment_author.name, ''),
            NULLIF(primary_teacher.name, ''),
            'Instructor'
        ) AS teacher_name
    ";

    $authorJoin = "
        LEFT JOIN users AS assessment_author
            ON assessments.created_by = assessment_author.id
    ";
} else {
    $authorSelect = "
        COALESCE(
            NULLIF(primary_teacher.name, ''),
            'Instructor'
        ) AS teacher_name
    ";

    $authorJoin = "";
}

$assessmentSQL = "
    SELECT
        assessments.*,
        classes.class_name,
        classes.id AS class_id,
        $authorSelect
    FROM assessments
    INNER JOIN classes
        ON assessments.class_id = classes.id
    LEFT JOIN users AS primary_teacher
        ON classes.teacher_id = primary_teacher.id
    $authorJoin
    WHERE assessments.id = ?
    LIMIT 1
";

$aq = mysqli_prepare($conn, $assessmentSQL);

if (!$aq) {
    die("Unable to load assessment.");
}

mysqli_stmt_bind_param($aq, "i", $assessment_id);
mysqli_stmt_execute($aq);

$assessmentResult = mysqli_stmt_get_result($aq);
$assessment = mysqli_fetch_assoc($assessmentResult);

mysqli_stmt_close($aq);

if (!$assessment) {
    die("Assessment not found");
}

$class_id = (int) $assessment['class_id'];

/* =========================================
   CONFIRM ACTIVE STUDENT ENROLLMENT
========================================= */

$check = mysqli_prepare($conn, "
    SELECT 1
    FROM class_students
    WHERE class_id = ?
      AND student_id = ?
      AND (archived = 0 OR archived IS NULL)
    LIMIT 1
");

if (!$check) {
    die("Unable to verify class enrollment.");
}

mysqli_stmt_bind_param($check, "ii", $class_id, $student_id);
mysqli_stmt_execute($check);
mysqli_stmt_store_result($check);

$isEnrolled = mysqli_stmt_num_rows($check) > 0;

mysqli_stmt_close($check);

if (!$isEnrolled) {
    http_response_code(403);
    die("Access denied. You are not actively enrolled in this class.");
}

/* =========================================
   EXISTING SUBMISSION
========================================= */

$sq = mysqli_prepare($conn, "
    SELECT *
    FROM submissions
    WHERE assessment_id = ?
      AND student_id = ?
    LIMIT 1
");

if (!$sq) {
    die("Unable to load your submission.");
}

mysqli_stmt_bind_param($sq, "ii", $assessment_id, $student_id);
mysqli_stmt_execute($sq);

$submissionResult = mysqli_stmt_get_result($sq);
$submission = mysqli_fetch_assoc($submissionResult);

mysqli_stmt_close($sq);

$hasSubmissionRow = (bool) $submission;

/* =========================================
   EXISTING SUBMISSION ATTACHMENTS
========================================= */

$submissionAttachments = [];

if ($hasSubmissionRow) {
    $saq = mysqli_prepare($conn, "
        SELECT *
        FROM submission_attachments
        WHERE submission_id = ?
        ORDER BY id ASC
    ");

    if ($saq) {
        $submission_id = (int) $submission['id'];

        mysqli_stmt_bind_param($saq, "i", $submission_id);
        mysqli_stmt_execute($saq);

        $submissionAttachments = mysqli_stmt_get_result($saq)
            ->fetch_all(MYSQLI_ASSOC);

        mysqli_stmt_close($saq);
    }
}

/* =========================================
   TEACHER-ADDED ASSESSMENT ATTACHMENTS
========================================= */

$assessmentAttachments = aa_fetch($conn, $assessment_id);

/*
 * Backward compatibility for older assessments
 * that used the original image, attachment, and link columns.
 */

if (empty($assessmentAttachments)) {

    if (!empty($assessment['image'])) {
        $assessmentAttachments[] = [
            'type'          => 'image',
            'file_path'     => $assessment['image'],
            'original_name' => $assessment['image'],
            'url'           => null
        ];
    }

    if (!empty($assessment['attachment'])) {
        $assessmentAttachments[] = [
            'type'          => 'file',
            'file_path'     => $assessment['attachment'],
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
            'type'          => 'link',
            'file_path'     => null,
            'original_name' => null,
            'url'           => $assessment['link']
        ];
    }
}

/* =========================================
   SORT ASSESSMENT ATTACHMENTS
========================================= */

$attImages = [];
$attFiles  = [];
$attLinks  = [];

foreach ($assessmentAttachments as $att) {

    if (($att['type'] ?? '') === 'image') {
        $attImages[] = $att;

    } elseif (($att['type'] ?? '') === 'file') {
        $attFiles[] = $att;

    } elseif (($att['type'] ?? '') === 'link') {
        $attLinks[] = $att;
    }
}

/* =========================================
   ASSESSMENT STATUS
========================================= */

$isOverdue = (
    !empty($assessment['due_date']) &&
    strtotime($assessment['due_date']) < time()
);

/* Check whether the submission was late. */

$isLate = false;

if (
    $submission &&
    !empty($submission['submitted_at']) &&
    !empty($assessment['due_date'])
) {
    $isLate = (
        strtotime($submission['submitted_at']) >
        strtotime($assessment['due_date'])
    );
}

/*
 * Assigned and missing are derived statuses.
 * Submitted and done come from the submissions table.
 */

if (!$submission) {
    $displayStatus = $isOverdue ? 'missing' : 'assigned';
} else {
    $displayStatus = $submission['status'] ?? 'submitted';
}

$statusLabels = [
    'assigned' => [
        'text'  => 'Assigned',
        'class' => 'status-assigned'
    ],
    'submitted' => [
        'text'  => 'Turned in',
        'class' => 'status-done'
    ],
    'done' => [
        'text'  => 'Marked as done',
        'class' => 'status-done'
    ],
    'missing' => [
        'text'  => 'Missing',
        'class' => 'status-missing'
    ]
];

$statusInfo = $statusLabels[$displayStatus]
    ?? [
        'text'  => ucfirst($displayStatus ?: 'Unknown'),
        'class' => 'status-assigned'
    ];

if (
    $isLate &&
    in_array($displayStatus, ['submitted', 'done'], true)
) {
    $statusInfo['text'] = (
        $displayStatus === 'done'
            ? 'Done'
            : 'Turned in'
    ) . ' (Late)';

    $statusInfo['class'] = 'status-late';
}

/* =========================================
   HELPER: CHECK IMAGE EXTENSION
========================================= */

function isImageExt($path) {
    $ext = strtolower(pathinfo((string)$path, PATHINFO_EXTENSION));

    return in_array(
        $ext,
        ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],
        true
    );
}

/* =========================================
   HELPER: ATTACHMENT FILE ICON
========================================= */

if (!function_exists('attachment_icon')) {
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
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($assessment['title']); ?></title>
    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link rel="stylesheet" href="student_class.css">
    <link rel="stylesheet" href="student_assessment_view.css">
    <link rel="stylesheet" href="student_assessment_view_responsive.css?v=1">

    <style>
        /* ── Teacher attachments on the instructions panel ── */
        .assess-attach-title {
            font-size: 13px;
            font-weight: 700;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin: 20px 0 10px;
        }

        .assess-img-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
        }
        .assess-img-grid a { display: block; }
        .assess-img-grid img {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #e8eef4;
            display: block;
            transition: box-shadow 0.2s, transform 0.2s;
        }
        .assess-img-grid a:hover img {
            box-shadow: 0 4px 12px rgba(0,0,0,.12);
            transform: translateY(-1px);
        }

        .assess-attach-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .assess-attach-item {
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
        .assess-attach-item i {
            color: #3f87b5;
            font-size: 18px;
            width: 22px;
            text-align: center;
            flex-shrink: 0;
        }
        .assess-attach-item span {
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .assess-attach-item:hover {
            border-color: #3f87b5;
            background: #f0f6fb;
            color: #222;
        }
        .assess-attach-item .open-icon {
            color: #bbb;
            font-size: 13px;
            width: auto;
        }

        @media (max-width: 768px) {
            .assess-img-grid {
                grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
            }
            .assess-img-grid img { height: 110px; }
            .assess-attach-item { padding: 10px 12px; font-size: 13.5px; }
        }
    </style>
</head>
<body>
<!-- MOBILE SIDEBAR OVERLAY -->
<div
    class="sidebar-overlay"
    id="sidebarOverlay"
    onclick="closeMobileSidebar()">
</div>

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
        <li><a href="student_messages.php"><i class="fa-regular fa-message"></i><span>Messages</span></a></li>
        <li><a href="student_performance.php"><i class="fa-solid fa-chart-pie"></i><span>Performance</span></a></li>
        <li><a href="student_resources.php"><i class="fa-solid fa-book-open"></i><span>Resources</span></a></li>
    </ul>
    <div class="logout-btn">
        <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a>
    </div>
</div>

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
            <a href="profile.php" class="profile-icon">

                <?php if ($hasProfileImage): ?>

                    <img
                        src="uploads/<?php echo rawurlencode($profileImageFile); ?>"
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

    <!-- CLASS NAV -->
    <div class="class-nav">
        <a href="student_class.php?id=<?php echo $class_id; ?>">Stream</a>
        <a href="student_assessment.php?id=<?php echo $class_id; ?>" class="nav-active">Assignments</a>
        <a href="student_material.php?id=<?php echo $class_id; ?>">Materials</a>
        <a href="student_people.php?id=<?php echo $class_id; ?>">People</a>
    </div>

    <div class="container py-4">

        <a href="student_assessment.php?id=<?php echo $class_id; ?>" class="back-link">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to assignments
        </a>

        <!-- ASSESSMENT HEADER -->
        <div class="assess-header">
            <div class="assess-header-icon <?php echo (strtolower($assessment['type']) === 'quiz') ? 'icon-quiz' : 'icon-assignment'; ?>">
                <i class="fa-solid fa-clipboard"></i>
            </div>
            <div class="assess-header-body">
                <h3><?php echo htmlspecialchars($assessment['title']); ?></h3>
                <div class="assess-header-meta">
                    <span><?php echo htmlspecialchars($assessment['teacher_name']); ?></span>
                    <span class="dot"></span>
                    <span><?php echo date("M d, Y", strtotime($assessment['created_at'])); ?></span>
                    <span class="dot"></span>
                    <span><?php echo htmlspecialchars($assessment['points']); ?> points</span>
                    <?php if (!empty($assessment['due_date'])): ?>
                        <span class="dot"></span>
                        <span class="<?php echo ($isOverdue && $displayStatus !== 'submitted' && $displayStatus !== 'done') ? 'text-danger fw-semibold' : ''; ?>">
                            Due <?php echo date("M d, Y h:i A", strtotime($assessment['due_date'])); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="row g-4 mt-1">

            <!-- LEFT: INSTRUCTIONS -->
            <div class="col-lg-8">
                <div class="assess-panel">

                    <?php if (!empty($assessment['instructions'])): ?>
                        <div class="assess-instructions"><?php echo nl2br(htmlspecialchars($assessment['instructions'])); ?></div>
                    <?php else: ?>
                        <p class="text-muted mb-0">No additional instructions.</p>
                    <?php endif; ?>

                    <!-- IMAGES -->
                    <?php if (!empty($attImages)): ?>
                        <div class="assess-attach-title">Images (<?php echo count($attImages); ?>)</div>
                        <div class="assess-img-grid">
                            <?php foreach ($attImages as $img): ?>
                                <a href="uploads/<?php echo rawurlencode(basename($img['file_path'])); ?>" target="_blank" rel="noopener">
                                    <img src="uploads/<?php echo rawurlencode(basename($img['file_path'])); ?>"
                                         alt="<?php echo htmlspecialchars($img['original_name'] ?: 'Attached image'); ?>"
                                         loading="lazy">
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- FILES -->
                    <?php if (!empty($attFiles)): ?>
                        <div class="assess-attach-title">Files (<?php echo count($attFiles); ?>)</div>
                        <div class="assess-attach-list">
                            <?php foreach ($attFiles as $f): ?>
                                <?php $fname = $f['original_name'] ?: basename($f['file_path']); ?>
                                <a href="uploads/<?php echo rawurlencode(basename($f['file_path'])); ?>"
                                   target="_blank" rel="noopener" class="assess-attach-item">
                                    <i class="fa-solid <?php echo attachment_icon($fname); ?>"></i>
                                    <span><?php echo htmlspecialchars($fname); ?></span>
                                    <i class="fa-solid fa-arrow-up-right-from-square open-icon"></i>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- LINKS -->
                    <?php if (!empty($attLinks)): ?>
                        <div class="assess-attach-title">Links (<?php echo count($attLinks); ?>)</div>
                        <div class="assess-attach-list">
                            <?php foreach ($attLinks as $l): ?>
                                <?php
                                    // only allow http(s) links to be clickable
                                    $safeUrl = preg_match('#^https?://#i', $l['url']) ? $l['url'] : '#';
                                ?>
                                <a href="<?php echo htmlspecialchars($safeUrl); ?>"
                                   target="_blank" rel="noopener noreferrer" class="assess-attach-item">
                                    <i class="fa-solid fa-link"></i>
                                    <span><?php echo htmlspecialchars($l['url']); ?></span>
                                    <i class="fa-solid fa-arrow-up-right-from-square open-icon"></i>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

            <!-- RIGHT: YOUR WORK -->
            <div class="col-lg-4">
                <div class="assess-panel your-work-panel">

                    <div class="your-work-header">
                        <span>Your work</span>
                        <span class="status-pill <?php echo $statusInfo['class']; ?>"><?php echo $statusInfo['text']; ?></span>
                    </div>

                    <?php if ($submission && !empty($submission['grade'])): ?>
                        <div class="grade-box">
                            <span class="grade-value"><?php echo htmlspecialchars($submission['grade']); ?></span>
                            <span class="grade-max">/ <?php echo htmlspecialchars($assessment['points']); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($submissionAttachments)): ?>
                        <!-- Already-turned-in files and links (read-only, from submission_attachments) -->
                        <div class="submitted-item">
                            <?php foreach ($submissionAttachments as $att): ?>
                                <?php if ($att['type'] === 'link'): ?>
                                    <a href="<?php echo htmlspecialchars($att['link_url']); ?>" target="_blank" rel="noopener" class="submitted-file">
                                        <i class="fa-solid fa-link me-2"></i>
                                        <?php echo htmlspecialchars($att['link_url']); ?>
                                    </a>
                                <?php else: ?>
                                    <a href="uploads/<?php echo htmlspecialchars($att['file_path']); ?>" target="_blank" class="submitted-file">
                                        <i class="fa-solid <?php echo isImageExt($att['file_path']) ? 'fa-image' : 'fa-file-lines'; ?> me-2"></i>
                                        <?php echo htmlspecialchars($att['original_name'] ?: $att['file_path']); ?>
                                    </a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($submission && !empty($submission['file_path'])): ?>
                        <!-- Backward compatibility: older submissions made before multi-attachment support -->
                        <div class="submitted-item">
                            <a href="uploads/<?php echo htmlspecialchars($submission['file_path']); ?>" target="_blank" class="submitted-file">
                                <i class="fa-solid fa-file-lines me-2"></i>
                                <?php echo htmlspecialchars($submission['file_path']); ?>
                            </a>
                        </div>
                    <?php endif; ?>

                    <div id="yourWorkActions">
                        <?php if ($hasSubmissionRow): ?>
                            <button class="btn btn-outline-secondary w-100 mb-2" id="unsubmitBtn">
                                <?php echo $displayStatus === 'done' ? 'Mark as not done' : 'Unsubmit'; ?>
                            </button>
                        <?php else: ?>

                            <!-- Attach files/links, then Turn in -->
                            <div id="attachmentList" class="attachment-list"></div>

                            <div class="attach-controls">
                                <label class="attach-box" for="submissionFiles">
                                    <i class="fa-solid fa-paperclip"></i>
                                    <span>Add files</span>
                                </label>
                                <input type="file" id="submissionFiles" multiple hidden>

                                <div class="attach-link-row">
                                    <input type="url" id="linkInput" class="form-control form-control-sm" placeholder="Paste a link, then click Add">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="addLinkBtn">Add</button>
                                </div>
                            </div>

                            <div id="attachCountMsg" class="attach-count-msg">
                                0 of <?php echo $MAX_ATTACHMENTS; ?> attachments added
                            </div>

                            <button type="button" class="btn btn-primary w-100 mt-2" id="turnInBtn" disabled>
                                Turn in
                            </button>

                            <button class="btn btn-outline-primary w-100 mt-2" id="markDoneBtn">
                                Mark as done
                            </button>
                        <?php endif; ?>
                    </div>

                    <div id="yourWorkMsg" class="small mt-2"></div>

                </div>
            </div>

        </div>
    </div>
</div>

<script>
const sidebar = document.getElementById('sidebar');
const mainContent = document.getElementById('mainContent');
const sidebarOverlay = document.getElementById('sidebarOverlay');

function isMobile() {
    return window.innerWidth <= 768;
}

function toggleSidebar() {

    if (isMobile()) {
        mobileSidebar();
        return;
    }

    sidebar.classList.toggle('collapsed');
    mainContent.classList.toggle('expanded');
}

function mobileSidebar() {

    if (!isMobile()) return;

    sidebar.classList.toggle('show');

    const isOpen = sidebar.classList.contains('show');

    sidebarOverlay.classList.toggle('show', isOpen);
    document.body.classList.toggle('no-scroll', isOpen);
}

function closeMobileSidebar() {

    sidebar.classList.remove('show');
    sidebarOverlay.classList.remove('show');
    document.body.classList.remove('no-scroll');
}

// Close sidebar when pressing Escape.
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeMobileSidebar();
    }
});

// Reset mobile sidebar when returning to desktop.
window.addEventListener('resize', function() {
    if (!isMobile()) {
        closeMobileSidebar();
    }
});

(function () {
    const ASSESSMENT_ID    = <?php echo json_encode($assessment_id); ?>;
    const MAX_ATTACHMENTS  = <?php echo json_encode($MAX_ATTACHMENTS); ?>;
    const HANDLER_URL      = 'assessment_submit_handler.php';

    const msgBox          = document.getElementById('yourWorkMsg');
    const fileInputEl     = document.getElementById('submissionFiles');
    const linkInput       = document.getElementById('linkInput');
    const addLinkBtn      = document.getElementById('addLinkBtn');
    const attachmentList  = document.getElementById('attachmentList');
    const attachCountMsg  = document.getElementById('attachCountMsg');
    const turnInBtn       = document.getElementById('turnInBtn');
    const markDoneBtn     = document.getElementById('markDoneBtn');
    const unsubmitBtn     = document.getElementById('unsubmitBtn');

    // Only present on the "not yet submitted" state.
    let attachments = []; // { id, kind: 'file'|'link', file?, url?, previewUrl? }
    let nextAttId = 1;

    function showMsg(text, isError) {
        msgBox.textContent = text;
        msgBox.className = 'small mt-2 ' + (isError ? 'text-danger' : 'text-success');
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function renderAttachments() {
        if (!attachmentList) return;

        attachmentList.innerHTML = '';
        attachments.forEach(att => {
            const row = document.createElement('div');
            row.className = 'attachment-item';

            let iconOrThumb;
            if (att.kind === 'file' && att.previewUrl) {
                iconOrThumb = `<img src="${att.previewUrl}" class="attachment-thumb" alt="">`;
            } else if (att.kind === 'link') {
                iconOrThumb = `<i class="fa-solid fa-link attachment-icon"></i>`;
            } else {
                iconOrThumb = `<i class="fa-solid fa-file-lines attachment-icon"></i>`;
            }

            const label = att.kind === 'file' ? att.file.name : att.url;

            row.innerHTML =
                iconOrThumb +
                `<span class="attachment-name" title="${escapeHtml(label)}">${escapeHtml(label)}</span>` +
                `<button type="button" class="attachment-remove" data-id="${att.id}" aria-label="Remove attachment">&times;</button>`;

            attachmentList.appendChild(row);
        });

        attachCountMsg.textContent = `${attachments.length} of ${MAX_ATTACHMENTS} attachments added`;
        turnInBtn.disabled = attachments.length === 0;
    }

    function addFiles(fileList) {
        const files = Array.from(fileList);
        let hitLimit = false;

        for (const file of files) {
            if (attachments.length >= MAX_ATTACHMENTS) { hitLimit = true; break; }
            const att = { id: nextAttId++, kind: 'file', file };
            if (file.type.startsWith('image/')) {
                att.previewUrl = URL.createObjectURL(file);
            }
            attachments.push(att);
        }

        if (hitLimit) showMsg(`You can attach up to ${MAX_ATTACHMENTS} items.`, true);
        renderAttachments();
    }

    function addLink() {
        const url = linkInput.value.trim();
        if (!url) return;

        if (!/^https?:\/\//i.test(url)) {
            showMsg('Links must start with http:// or https://', true);
            return;
        }
        if (attachments.length >= MAX_ATTACHMENTS) {
            showMsg(`You can attach up to ${MAX_ATTACHMENTS} items.`, true);
            return;
        }

        attachments.push({ id: nextAttId++, kind: 'link', url });
        linkInput.value = '';
        renderAttachments();
    }

    function removeAttachment(id) {
        const att = attachments.find(a => a.id === id);
        if (att && att.previewUrl) URL.revokeObjectURL(att.previewUrl);
        attachments = attachments.filter(a => a.id !== id);
        renderAttachments();
    }

    if (fileInputEl) {
        fileInputEl.addEventListener('change', () => {
            addFiles(fileInputEl.files);
            fileInputEl.value = ''; // allow re-adding the same file later
        });
    }

    if (addLinkBtn) {
        addLinkBtn.addEventListener('click', addLink);
        linkInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); addLink(); }
        });
    }

    if (attachmentList) {
        attachmentList.addEventListener('click', (e) => {
            const btn = e.target.closest('.attachment-remove');
            if (!btn) return;
            removeAttachment(Number(btn.dataset.id));
        });
    }

    if (turnInBtn) {
        turnInBtn.addEventListener('click', async () => {
            if (!attachments.length) {
                showMsg('Attach at least one file or link before turning in.', true);
                return;
            }

            const fd = new FormData();
            fd.append('action', 'turn_in');
            fd.append('assessment_id', ASSESSMENT_ID);
            attachments.forEach(att => {
                if (att.kind === 'file') fd.append('files[]', att.file, att.file.name);
                else fd.append('links[]', att.url);
            });

            turnInBtn.disabled = true;
            turnInBtn.textContent = 'Turning in...';
            try {
                const r = await fetch(HANDLER_URL, { method: 'POST', body: fd });
                const d = await r.json();
                if (d.success) { location.reload(); }
                else { showMsg(d.error || 'Could not turn in.', true); turnInBtn.disabled = false; turnInBtn.textContent = 'Turn in'; }
            } catch (err) {
                showMsg('Network error. Try again.', true);
                turnInBtn.disabled = false;
                turnInBtn.textContent = 'Turn in';
            }
        });
        renderAttachments(); // set initial "0 of N" state and disabled button
    }

    if (markDoneBtn) {
        markDoneBtn.addEventListener('click', async () => {
            markDoneBtn.disabled = true;
            markDoneBtn.textContent = 'Marking...';
            try {
                const r = await fetch(HANDLER_URL, {
                    method: 'POST',
                    body: new URLSearchParams({ action: 'mark_done', assessment_id: ASSESSMENT_ID })
                });
                const d = await r.json();
                if (d.success) { location.reload(); }
                else { showMsg(d.error || 'Could not update.', true); markDoneBtn.disabled = false; markDoneBtn.textContent = 'Mark as done'; }
            } catch (err) {
                showMsg('Network error. Try again.', true);
                markDoneBtn.disabled = false;
                markDoneBtn.textContent = 'Mark as done';
            }
        });
    }

    if (unsubmitBtn) {
        unsubmitBtn.addEventListener('click', async () => {
            unsubmitBtn.disabled = true;
            try {
                const r = await fetch(HANDLER_URL, {
                    method: 'POST',
                    body: new URLSearchParams({ action: 'unsubmit', assessment_id: ASSESSMENT_ID })
                });
                const d = await r.json();
                if (d.success) { location.reload(); }
                else { showMsg(d.error || 'Could not update.', true); unsubmitBtn.disabled = false; }
            } catch (err) {
                showMsg('Network error. Try again.', true);
                unsubmitBtn.disabled = false;
            }
        });
    }
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>