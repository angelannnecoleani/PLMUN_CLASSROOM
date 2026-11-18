<?php
session_start();
include 'database.php';
include 'auth_helpers.php';

/* ============================================================
   TEACHER / PROGRAM CHAIR ACCESS
   ============================================================ */

$role = $_SESSION['role'] ?? '';

if (empty($_SESSION['user_id']) || !in_array($role, ['teacher', 'program_chair'], true)) {
    header("Location: login.php");
    exit;
}

$teacher_id = intval($_SESSION['user_id']);
$isChair    = ($role === 'program_chair');

/* ============================================================
   GET SUBMISSION ID
   ============================================================ */

$submission_id = intval($_GET['id'] ?? 0);

if ($submission_id <= 0) {
    die("Invalid submission.");
}

/* ============================================================
   GET SUBMISSION + STUDENT + ASSESSMENT + CLASS
   ============================================================ */

$stmt = mysqli_prepare($conn, "
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
");

mysqli_stmt_bind_param($stmt, "i", $submission_id);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$submission = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$submission) {
    die("Submission not found.");
}

/* ============================================================
   MAKE SURE THIS USER MAY ACCESS THE CLASS
   teacher: own class / program chair: any class in their program
   ============================================================ */

if (!getClassAccess($conn, (int)$submission['class_id'], $teacher_id, $role)) {
    http_response_code(403);
    die("You are not authorized to view this submission.");
}

/* LOGGED-IN USER (for the profile icon) */
$uStmt = mysqli_prepare($conn, "SELECT * FROM users WHERE id = ?");
mysqli_stmt_bind_param($uStmt, "i", $teacher_id);
mysqli_stmt_execute($uStmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($uStmt));
mysqli_stmt_close($uStmt);

/* ============================================================
   GET ALL ATTACHMENTS
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

mysqli_stmt_bind_param($attStmt, "i", $submission_id);
mysqli_stmt_execute($attStmt);

$attResult = mysqli_stmt_get_result($attStmt);

while ($row = mysqli_fetch_assoc($attResult)) {
    $attachments[] = $row;
}
mysqli_stmt_close($attStmt);

/* Only http(s) links are clickable (students submit these, so never trust them) */
function safe_link($url) {
    return preg_match('#^https?://#i', (string)$url) ? $url : '#';
}

/* ============================================================
   STATUS
   ============================================================ */

$status = $submission['status'];

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
   DATE
   ============================================================ */

$submittedAt = '';

if (!empty($submission['submitted_at'])) {
    $submittedAt = date(
        "F d, Y • h:i A",
        strtotime($submission['submitted_at'])
    );
}

$studentName = $submission['student_name'];

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($studentName); ?> - Submission</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="teacher_class.css">
    <link rel="stylesheet" href="teacher_submission_view.css">

</head>

<body>

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

            <li>
                <a href="teacher_resources.php">
                    <i class="fa-solid fa-book-open"></i>
                    <span>Resources</span>
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

<!-- Dims the page behind the sidebar on mobile; tapping it closes the menu -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="mobileSidebar()"></div>


<div class="main-content" id="mainContent">

    <!-- TOPBAR -->

    <div class="topbar">

        <div class="navbar-left">

            <button
                class="mobile-menu"
                onclick="mobileSidebar()">

                <i class="fa-solid fa-bars"></i>
            </button>

            <img
                src="img/classroom logo.png"
                class="navbar-logo"
                alt="Logo">

        </div>


        <div class="navbar-right">

            <?php
                $profileFile = $user['profile_image'] ?? '';
                $hasProfileImage = !empty($profileFile)
                    && !in_array(strtolower($profileFile), ['default.png', 'default.jpg', 'default.jpeg', 'null'])
                    && file_exists(__DIR__ . '/uploads/' . basename($profileFile));
            ?>
            <a href="profile.php" class="profile-icon">
                <?php if($hasProfileImage): ?>
                    <img src="uploads/<?php echo htmlspecialchars(basename($profileFile)); ?>"
                         alt="Profile"
                        onerror="this.style.display='none'; this.parentElement.insertAdjacentHTML('beforeend','<i class=&quot;fa-solid fa-user&quot;></i>');">
                <?php else: ?>
                    <i class="fa-solid fa-user"></i>
                <?php endif; ?>
            </a>

        </div>

    </div>


    <!-- PAGE -->

    <div class="container py-4">


        <!-- BACK -->

        <a
            href="teacher_submissions.php?id=<?php echo (int)$submission['assessment_id']; ?>"
            class="back-link">

            <i class="fa-solid fa-arrow-left"></i>

            Back to Student Work

        </a>


        <!-- ====================================================
             SUBMISSION HEADER
             ==================================================== -->

        <div class="submission-page-header">


            <div class="student-information">


                <?php if (!empty($submission['profile_image'])): ?>

                    <img
                        src="uploads/<?php echo htmlspecialchars(basename($submission['profile_image'])); ?>"
                        class="student-large-avatar"
                        alt="Student">

                <?php else: ?>

                    <div class="student-large-avatar avatar-default">

                        <i class="fa-solid fa-user"></i>

                    </div>

                <?php endif; ?>


                <div>

                    <h1>
                        <?php echo htmlspecialchars($studentName); ?>
                    </h1>

                    <p>
                        <?php echo htmlspecialchars($submission['class_name']); ?>

                        <?php if (!empty($submission['section'])): ?>

                            · <?php echo htmlspecialchars($submission['section']); ?>

                        <?php endif; ?>

                    </p>

                </div>

            </div>


            <span
                class="status-badge <?php echo $badgeClass; ?>">

                <?php echo $badgeLabel; ?>

            </span>


        </div>


        <!-- ====================================================
             ASSESSMENT INFORMATION
             ==================================================== -->

        <div class="assessment-info-card">

            <div>

                <span class="info-label">
                    Assessment
                </span>

                <strong>
                    <?php echo htmlspecialchars($submission['assessment_title']); ?>
                </strong>

            </div>


            <div>

                <span class="info-label">
                    Type
                </span>

                <strong>
                    <?php echo htmlspecialchars($submission['type']); ?>
                </strong>

            </div>


            <div>

                <span class="info-label">
                    Points
                </span>

                <strong>
                    <?php echo (int)$submission['points']; ?>
                </strong>

            </div>


            <div>

                <span class="info-label">
                    Submitted
                </span>

                <strong>

                    <?php if ($submittedAt): ?>

                        <?php echo htmlspecialchars($submittedAt); ?>

                    <?php else: ?>

                        Not submitted

                    <?php endif; ?>

                </strong>

            </div>

        </div>


        <!-- ====================================================
             SUBMISSION CONTENT
             ==================================================== -->

        <div class="submission-card">


            <div class="submission-card-header">

                <div>

                    <h2>
                        Student Submission
                    </h2>

                    <p>
                        Files and links submitted by
                        <?php echo htmlspecialchars($studentName); ?>
                    </p>

                </div>


                <?php if ($submission['grade'] !== null): ?>

                    <div class="current-grade">

                        <span>
                            Score
                        </span>

                        <strong>

                            <?php echo htmlspecialchars($submission['grade']); ?>

                            /
                            <?php echo (int)$submission['points']; ?>

                        </strong>

                    </div>

                <?php endif; ?>

            </div>


            <!-- =================================================
                 ATTACHMENTS
                 ================================================= -->

            <div class="submission-attachments">


                <?php $attachmentCount = count($attachments); ?>


                <?php if ($attachmentCount > 0): ?>


                    <?php foreach ($attachments as $attachment): ?>


                        <?php if ($attachment['type'] === 'link'): ?>


                            <a
                                href="<?php echo htmlspecialchars(safe_link($attachment['link_url'])); ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="submission-attachment">

                                <div class="attachment-icon link-icon">

                                    <i class="fa-solid fa-link"></i>

                                </div>


                                <div class="attachment-information">

                                    <strong>
                                        <?php echo htmlspecialchars($attachment['link_url']); ?>
                                    </strong>

                                    <span>
                                        External link
                                    </span>

                                </div>


                                <i class="fa-solid fa-arrow-up-right-from-square attachment-open"></i>

                            </a>


                        <?php else: ?>


                            <a
                                href="uploads/<?php echo htmlspecialchars($attachment['file_path']); ?>"
                                target="_blank"
                                class="submission-attachment">

                                <div class="attachment-icon">

                                    <i class="fa-solid fa-file-lines"></i>

                                </div>


                                <div class="attachment-information">

                                    <strong>

                                        <?php

                                        echo htmlspecialchars(
                                            $attachment['original_name']
                                            ?: basename($attachment['file_path'])
                                        );

                                        ?>

                                    </strong>

                                    <span>
                                        Submitted file
                                    </span>

                                </div>


                                <i class="fa-solid fa-arrow-up-right-from-square attachment-open"></i>

                            </a>


                        <?php endif; ?>


                    <?php endforeach; ?>


                <?php elseif (!empty($submission['file_path'])): ?>


                    <!-- OLD SUBMISSION FORMAT -->

                    <a
                        href="uploads/<?php echo htmlspecialchars($submission['file_path']); ?>"
                        target="_blank"
                        class="submission-attachment">

                        <div class="attachment-icon">

                            <i class="fa-solid fa-file-lines"></i>

                        </div>


                        <div class="attachment-information">

                            <strong>
                                <?php echo htmlspecialchars(basename($submission['file_path'])); ?>
                            </strong>

                            <span>
                                Submitted file
                            </span>

                        </div>


                        <i class="fa-solid fa-arrow-up-right-from-square attachment-open"></i>

                    </a>


                <?php else: ?>


                    <div class="no-submission">

                        <i class="fa-regular fa-folder-open"></i>

                        <h3>
                            No files or links
                        </h3>

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

                <h2>
                    Grade Submission
                </h2>

                <p>
                    Enter the student's score for this assessment.
                </p>

            </div>


            <form
                action="save_grade.php"
                method="post"
                class="grading-form">


                <input
                    type="hidden"
                    name="assessment_id"
                    value="<?php echo (int)$submission['assessment_id']; ?>">


                <input
                    type="hidden"
                    name="student_id"
                    value="<?php echo (int)$submission['student_id']; ?>">


                <div class="grade-input-wrapper">

                    <input
                        type="number"
                        name="grade"
                        class="grade-input-large"
                        min="0"
                        max="<?php echo (int)$submission['points']; ?>"
                        step="0.5"
                        value="<?php echo $submission['grade'] !== null ? htmlspecialchars($submission['grade']) : ''; ?>"
                        placeholder="0">

                    <span>
                        /
                        <?php echo (int)$submission['points']; ?>
                    </span>

                </div>


                <button
                    type="submit"
                    class="save-grade-btn">

                    <i class="fa-solid fa-check"></i>

                    Save Grade

                </button>


            </form>

        </div>


    </div>

</div>


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

/* Close the mobile menu with the Escape key */
document.addEventListener("keydown", function (e) {
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