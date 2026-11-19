
<?php
session_start();
require_once 'database.php';
require_once 'auth_helpers.php';

/* ============================================================
   HELPER FUNCTIONS
============================================================ */

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function hasValidProfileImage($file) {
    if (empty($file)) {
        return false;
    }

    $file = basename($file);

    if (in_array(strtolower($file), [
        'default.png',
        'default.jpg',
        'default.jpeg',
        'default.svg',
        'null'
    ], true)) {
        return false;
    }

    return file_exists(__DIR__ . '/uploads/' . $file);
}

function safeLink($url) {
    $url = trim((string)$url);

    if (
        filter_var($url, FILTER_VALIDATE_URL) &&
        in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)
    ) {
        return $url;
    }

    return '#';
}

/* ============================================================
   LOGIN AND ROLE VALIDATION
============================================================ */

$role = $_SESSION['role'] ?? '';

if (
    empty($_SESSION['user_id']) ||
    !in_array($role, ['teacher', 'program_chair'], true)
) {
    header("Location: login.php");
    exit;
}

$teacher_id = (int)$_SESSION['user_id'];
$isChair = ($role === 'program_chair');

/* ============================================================
   GET SUBMISSION ID
============================================================ */

$submission_id = (int)($_GET['id'] ?? 0);

if ($submission_id <= 0) {
    http_response_code(400);
    exit("Invalid submission.");
}

/* ============================================================
   GET SUBMISSION, STUDENT, ASSESSMENT AND CLASS
============================================================ */

$sql = "
    SELECT
        s.id AS submission_id,
        s.student_id,
        s.assessment_id,
        s.file_path,
        s.status,
        s.submitted_at,
        s.grade,
        s.graded_at,

        u.name AS student_name,
        u.profile_image,

        a.title AS assessment_title,
        a.instructions,
        a.points,
        a.due_date,
        a.type,

        c.id AS class_id,
        c.class_name,
        c.section,
        c.teacher_id

    FROM submissions s

    INNER JOIN users u
        ON u.id = s.student_id

    INNER JOIN assessments a
        ON a.id = s.assessment_id

    INNER JOIN classes c
        ON c.id = a.class_id

    WHERE s.id = ?
    LIMIT 1
";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    error_log("Submission query error: " . mysqli_error($conn));
    http_response_code(500);
    exit("Unable to load submission.");
}

mysqli_stmt_bind_param($stmt, "i", $submission_id);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$submission = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);

if (!$submission) {
    http_response_code(404);
    exit("Submission not found.");
}

$class_id = (int)$submission['class_id'];

/* ============================================================
   CLASS ACCESS VALIDATION

   TEACHER:
   - Primary instructor
   - Assigned co-instructor

   PROGRAM CHAIR:
   - Uses existing program-chair access helper
============================================================ */

$hasAccess = false;

if ($role === 'teacher') {

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
        error_log("Teacher access query error: " . mysqli_error($conn));
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
    $hasAccess = (mysqli_num_rows($accessResult) > 0);

    mysqli_stmt_close($accessStmt);

} elseif ($role === 'program_chair') {

    $hasAccess = getClassAccess(
        $conn,
        $class_id,
        $teacher_id,
        $role
    );
}

if (!$hasAccess) {
    http_response_code(403);
    exit("You are not authorized to view this submission.");
}

/* ============================================================
   GET LOGGED-IN USER
============================================================ */

$userStmt = mysqli_prepare(
    $conn,
    "SELECT id, name, profile_image FROM users WHERE id = ? LIMIT 1"
);

if (!$userStmt) {
    http_response_code(500);
    exit("Unable to load user information.");
}

mysqli_stmt_bind_param($userStmt, "i", $teacher_id);
mysqli_stmt_execute($userStmt);

$user = mysqli_fetch_assoc(mysqli_stmt_get_result($userStmt));

mysqli_stmt_close($userStmt);

/* ============================================================
   GET SUBMISSION ATTACHMENTS
============================================================ */

$attachments = [];

$attStmt = mysqli_prepare($conn, "
    SELECT
        id,
        submission_id,
        type,
        file_path,
        original_name,
        link_url
    FROM submission_attachments
    WHERE submission_id = ?
    ORDER BY id ASC
");

if (!$attStmt) {
    error_log("Attachment query error: " . mysqli_error($conn));
    http_response_code(500);
    exit("Unable to load submission attachments.");
}

mysqli_stmt_bind_param($attStmt, "i", $submission_id);
mysqli_stmt_execute($attStmt);

$attResult = mysqli_stmt_get_result($attStmt);

while ($row = mysqli_fetch_assoc($attResult)) {
    $attachments[] = $row;
}

mysqli_stmt_close($attStmt);

/* ============================================================
   PROFILE IMAGE
============================================================ */

$profileFile = $user['profile_image'] ?? '';
$hasProfileImage = hasValidProfileImage($profileFile);

$studentProfileFile = $submission['profile_image'] ?? '';
$hasStudentProfileImage = hasValidProfileImage($studentProfileFile);

/* ============================================================
   SUBMISSION STATUS
============================================================ */

$status = strtolower(trim($submission['status'] ?? ''));

if ($status === 'done') {
    $badgeLabel = 'Marked done';
    $badgeClass = 'badge-done';

} elseif ($status === 'submitted') {
    $badgeLabel = 'Submitted';
    $badgeClass = 'badge-submitted';

} else {
    $badgeLabel = 'Missing';
    $badgeClass = 'badge-missing';
}

/* ============================================================
   SUBMISSION DATE
============================================================ */

$submittedAt = '';

if (!empty($submission['submitted_at'])) {
    $timestamp = strtotime($submission['submitted_at']);

    if ($timestamp !== false) {
        $submittedAt = date("F d, Y • h:i A", $timestamp);
    }
}

$studentName = $submission['student_name'] ?? 'Student';
$studentName = (string)$studentName;

$points = max(0, (float)$submission['points']);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo e($studentName); ?> - Submission</title>

    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <link rel="stylesheet" href="teacher_class.css">
    <link rel="stylesheet" href="teacher_submission_view.css">

</head>

<body>

<!-- ============================================================
     SIDEBAR
============================================================ -->

<div class="sidebar" id="sidebar">

    <div class="sidebar-header">

        <button class="toggle-btn" onclick="toggleSidebar()">
            <i class="fa-solid fa-bars"></i>
        </button>

        <button
            class="sidebar-close-btn"
            onclick="mobileSidebar()"
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

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
    onclick="mobileSidebar()"
></div>

<!-- ============================================================
     MAIN CONTENT
============================================================ -->

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
                alt="PLMUN Classroom Logo"
            >

        </div>

        <div class="navbar-right">

            <a href="profile.php" class="profile-icon">

                <?php if ($hasProfileImage): ?>

                    <img
                        src="uploads/<?php echo e(basename($profileFile)); ?>"
                        alt="Profile"
                        onerror="this.style.display='none'; this.parentElement.insertAdjacentHTML('beforeend','<i class=&quot;fa-solid fa-user&quot;></i>');"
                    >

                <?php else: ?>

                    <i class="fa-solid fa-user"></i>

                <?php endif; ?>

            </a>

        </div>

    </div>

    <!-- PAGE CONTENT -->

    <div class="container py-4">

        <!-- BACK TO STUDENT WORK -->

        <a
            href="teacher_submissions.php?id=<?php echo (int)$submission['assessment_id']; ?>"
            class="back-link"
        >

            <i class="fa-solid fa-arrow-left"></i>
            Back to Student Work

        </a>

        <!-- ====================================================
             SUBMISSION HEADER
        ==================================================== -->

        <div class="submission-page-header">

            <div class="student-information">

                <?php if ($hasStudentProfileImage): ?>

                    <img
                        src="uploads/<?php echo e(basename($studentProfileFile)); ?>"
                        class="student-large-avatar"
                        alt="Student"
                    >

                <?php else: ?>

                    <div class="student-large-avatar avatar-default">
                        <i class="fa-solid fa-user"></i>
                    </div>

                <?php endif; ?>

                <div>

                    <h1><?php echo e($studentName); ?></h1>

                    <p>
                        <?php echo e($submission['class_name']); ?>

                        <?php if (!empty($submission['section'])): ?>
                            · <?php echo e($submission['section']); ?>
                        <?php endif; ?>
                    </p>

                </div>

            </div>

            <span class="status-badge <?php echo e($badgeClass); ?>">
                <?php echo e($badgeLabel); ?>
            </span>

        </div>

        <!-- ====================================================
             ASSESSMENT INFORMATION
        ==================================================== -->

        <div class="assessment-info-card">

            <div>

                <span class="info-label">Assessment</span>

                <strong>
                    <?php echo e($submission['assessment_title']); ?>
                </strong>

            </div>

            <div>

                <span class="info-label">Type</span>

                <strong>
                    <?php echo e($submission['type']); ?>
                </strong>

            </div>

            <div>

                <span class="info-label">Points</span>

                <strong>
                    <?php echo e($submission['points']); ?>
                </strong>

            </div>

            <div>

                <span class="info-label">Submitted</span>

                <strong>

                    <?php if ($submittedAt): ?>

                        <?php echo e($submittedAt); ?>

                    <?php else: ?>

                        Not submitted

                    <?php endif; ?>

                </strong>

            </div>

        </div>

        <!-- ====================================================
             STUDENT SUBMISSION
        ==================================================== -->

        <div class="submission-card">

            <div class="submission-card-header">

                <div>

                    <h2>Student Submission</h2>

                    <p>
                        Files and links submitted by
                        <?php echo e($studentName); ?>
                    </p>

                </div>

                <?php if ($submission['grade'] !== null): ?>

                    <div class="current-grade">

                        <span>Score</span>

                        <strong>
                            <?php echo e($submission['grade']); ?>
                            /
                            <?php echo e($submission['points']); ?>
                        </strong>

                    </div>

                <?php endif; ?>

            </div>

            <!-- ATTACHMENTS -->

            <div class="submission-attachments">

                <?php if (count($attachments) > 0): ?>

                    <?php foreach ($attachments as $attachment): ?>

                        <?php if (($attachment['type'] ?? '') === 'link'): ?>

                            <?php
                                $externalLink = safeLink($attachment['link_url'] ?? '');
                            ?>

                            <a
                                href="<?php echo e($externalLink); ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="submission-attachment"
                            >

                                <div class="attachment-icon link-icon">
                                    <i class="fa-solid fa-link"></i>
                                </div>

                                <div class="attachment-information">

                                    <strong>
                                        <?php echo e($attachment['link_url']); ?>
                                    </strong>

                                    <span>External link</span>

                                </div>

                                <i class="fa-solid fa-arrow-up-right-from-square attachment-open"></i>

                            </a>

                        <?php else: ?>

                            <?php
                                $attachmentFile = basename(
                                    (string)($attachment['file_path'] ?? '')
                                );

                                $attachmentName = !empty($attachment['original_name'])
                                    ? $attachment['original_name']
                                    : $attachmentFile;

                                $attachmentExists = (
                                    $attachmentFile !== '' &&
                                    file_exists(__DIR__ . '/uploads/' . $attachmentFile)
                                );
                            ?>

                            <?php if ($attachmentExists): ?>

                                <a
                                    href="uploads/<?php echo rawurlencode($attachmentFile); ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="submission-attachment"
                                >

                                    <div class="attachment-icon">
                                        <i class="fa-solid fa-file-lines"></i>
                                    </div>

                                    <div class="attachment-information">

                                        <strong>
                                            <?php echo e($attachmentName); ?>
                                        </strong>

                                        <span>Submitted file</span>

                                    </div>

                                    <i class="fa-solid fa-arrow-up-right-from-square attachment-open"></i>

                                </a>

                            <?php else: ?>

                                <div class="submission-attachment">

                                    <div class="attachment-icon">
                                        <i class="fa-solid fa-file-circle-exclamation"></i>
                                    </div>

                                    <div class="attachment-information">

                                        <strong>
                                            <?php echo e($attachmentName); ?>
                                        </strong>

                                        <span>File not found on server</span>

                                    </div>

                                </div>

                            <?php endif; ?>

                        <?php endif; ?>

                    <?php endforeach; ?>

                <?php elseif (!empty($submission['file_path'])): ?>

                    <!-- LEGACY SINGLE-FILE SUBMISSION -->

                    <?php
                        $legacyFile = basename(
                            (string)$submission['file_path']
                        );

                        $legacyExists = (
                            $legacyFile !== '' &&
                            file_exists(__DIR__ . '/uploads/' . $legacyFile)
                        );
                    ?>

                    <?php if ($legacyExists): ?>

                        <a
                            href="uploads/<?php echo rawurlencode($legacyFile); ?>"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="submission-attachment"
                        >

                            <div class="attachment-icon">
                                <i class="fa-solid fa-file-lines"></i>
                            </div>

                            <div class="attachment-information">

                                <strong>
                                    <?php echo e($legacyFile); ?>
                                </strong>

                                <span>Submitted file</span>

                            </div>

                            <i class="fa-solid fa-arrow-up-right-from-square attachment-open"></i>

                        </a>

                    <?php else: ?>

                        <div class="no-submission">

                            <i class="fa-solid fa-file-circle-exclamation"></i>

                            <h3>File not found</h3>

                            <p>
                                The submitted file could not be found
                                in the uploads folder.
                            </p>

                        </div>

                    <?php endif; ?>

                <?php else: ?>

                    <div class="no-submission">

                        <i class="fa-regular fa-folder-open"></i>

                        <h3>No files or links</h3>

                        <p>
                            This submission does not contain
                            any uploaded files or links.
                        </p>

                    </div>

                <?php endif; ?>

            </div>

        </div>

        <!-- ====================================================
             GRADING
        ==================================================== -->

        <div class="grading-card">

            <div>

                <h2>Grade Submission</h2>

                <p>
                    Enter the student's score for this assessment.
                </p>

            </div>

            <form
                action="save_grade.php"
                method="post"
                class="grading-form"
            >

                <input
                    type="hidden"
                    name="assessment_id"
                    value="<?php echo (int)$submission['assessment_id']; ?>"
                >

                <input
                    type="hidden"
                    name="student_id"
                    value="<?php echo (int)$submission['student_id']; ?>"
                >

                <div class="grade-input-wrapper">

                    <input
                        type="number"
                        name="grade"
                        class="grade-input-large"
                        min="0"
                        max="<?php echo e($points); ?>"
                        step="0.5"
                        value="<?php
                            echo $submission['grade'] !== null
                                ? e($submission['grade'])
                                : '';
                        ?>"
                        placeholder="0"
                    >

                    <span>
                        / <?php echo e($submission['points']); ?>
                    </span>

                </div>

                <button
                    type="submit"
                    class="save-grade-btn"
                >

                    <i class="fa-solid fa-check"></i>
                    Save Grade

                </button>

            </form>

        </div>

    </div>

</div>

<!-- ============================================================
     SIDEBAR JAVASCRIPT
============================================================ -->

<script>

function toggleSidebar() {

    document
        .getElementById("sidebar")
        .classList
        .toggle("collapsed");

    document
        .getElementById("mainContent")
        .classList
        .toggle("expanded");

}

function mobileSidebar() {

    document
        .getElementById("sidebar")
        .classList
        .toggle("show");

    document
        .getElementById("sidebarOverlay")
        .classList
        .toggle("show");

    document.body.classList.toggle("no-scroll");

}

document.addEventListener("keydown", function(e) {

    if (
        e.key === "Escape" &&
        document.getElementById("sidebar").classList.contains("show")
    ) {
        mobileSidebar();
    }

});

</script>

</body>
</html>
