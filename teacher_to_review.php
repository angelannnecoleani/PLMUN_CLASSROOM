
<?php
session_start();
require_once 'database.php';

/* ==========================================
   LOGIN VALIDATION
========================================== */

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if (
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'teacher'
) {
    header("Location: login.php");
    exit;
}

$teacher_id = (int)$_SESSION['user_id'];

function e($value) {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

/* ==========================================
   FETCH TEACHER INFORMATION
========================================== */

$userStmt = mysqli_prepare(
    $conn,
    "SELECT id, name, profile_image
     FROM users
     WHERE id = ?
     LIMIT 1"
);

mysqli_stmt_bind_param(
    $userStmt,
    "i",
    $teacher_id
);

mysqli_stmt_execute($userStmt);

$userResult = mysqli_stmt_get_result($userStmt);
$user = mysqli_fetch_assoc($userResult);

mysqli_stmt_close($userStmt);

if (!$user) {
    header("Location: login.php");
    exit;
}

/* ==========================================
   FILTERS
========================================== */

$tab = (
    isset($_GET['tab']) &&
    $_GET['tab'] === 'reviewed'
) ? 'reviewed' : 'review';

$selected_class_id = isset($_GET['class_id'])
    ? max(0, (int)$_GET['class_id'])
    : 0;

/* ==========================================
   TEACHER CLASSES
========================================== */

$teacherClasses = [];

$classStmt = mysqli_prepare(
    $conn,
    "SELECT id, class_name
     FROM classes
     WHERE teacher_id = ?
     ORDER BY class_name ASC"
);

mysqli_stmt_bind_param(
    $classStmt,
    "i",
    $teacher_id
);

mysqli_stmt_execute($classStmt);

$classResult = mysqli_stmt_get_result($classStmt);

while ($row = mysqli_fetch_assoc($classResult)) {
    $teacherClasses[] = $row;
}

mysqli_stmt_close($classStmt);

/* ==========================================
   VALIDATE SELECTED CLASS
========================================== */

$validClassIds = array_map(
    'intval',
    array_column($teacherClasses, 'id')
);

if (
    $selected_class_id > 0 &&
    !in_array($selected_class_id, $validClassIds, true)
) {
    $selected_class_id = 0;
}

/* ==========================================
   FETCH ASSESSMENTS AND STATISTICS
========================================== */

$params = [$teacher_id];
$types = "i";

$classFilter = "";

if ($selected_class_id > 0) {
    $classFilter = " AND a.class_id = ?";
    $params[] = $selected_class_id;
    $types .= "i";
}

/*
  Turned In:
    Submitted/done submissions.

  Graded:
    Submitted/done submissions with grade.

  Assigned:
    Students enrolled in the class.

  This keeps the original submission statuses
  used by your classroom system.
*/

$sql = "
    SELECT
        a.id,
        a.class_id,
        a.title,
        a.type,
        a.due_date,
        c.class_name,

        (
            SELECT COUNT(*)
            FROM class_students cs
            WHERE cs.class_id = a.class_id
            AND cs.archived = 0
        ) AS assigned_count,

        (
            SELECT COUNT(*)
            FROM submissions s
            WHERE s.assessment_id = a.id
            AND s.status IN ('submitted', 'done')
        ) AS turned_in_count,

        (
            SELECT COUNT(*)
            FROM submissions s
            WHERE s.assessment_id = a.id
            AND s.status IN ('submitted', 'done')
            AND s.grade IS NOT NULL
        ) AS graded_count

    FROM assessments a

    INNER JOIN classes c
        ON c.id = a.class_id

    WHERE c.teacher_id = ?

    $classFilter

    ORDER BY
        c.class_name ASC,
        a.due_date IS NULL,
        a.due_date ASC,
        a.id DESC
";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    error_log(mysqli_error($conn));
    http_response_code(500);
    exit("Unable to load assessments.");
}

mysqli_stmt_bind_param(
    $stmt,
    $types,
    ...$params
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

/* ==========================================
   ORGANIZE ASSESSMENTS BY CLASS
========================================== */

$grouped = [];

while ($row = mysqli_fetch_assoc($result)) {

    $turnedIn = (int)$row['turned_in_count'];
    $graded = (int)$row['graded_count'];

    $fullyReviewed = (
        $turnedIn > 0 &&
        $graded >= $turnedIn
    );

    if ($tab === 'reviewed' && !$fullyReviewed) {
        continue;
    }

    if ($tab === 'review' && $fullyReviewed) {
        continue;
    }

    // Group by class ID to avoid merging
    // classes with identical names.
    $classId = (int)$row['class_id'];

    if (!isset($grouped[$classId])) {
        $grouped[$classId] = [
            'class_name' => $row['class_name'],
            'assessments' => []
        ];
    }

    $grouped[$classId]['assessments'][] = $row;
}

mysqli_stmt_close($stmt);

/* ==========================================
   PROFILE IMAGE
========================================== */

$profileFile = trim(
    (string)($user['profile_image'] ?? '')
);

$profileName = basename($profileFile);

$defaultImages = [
    '',
    'null',
    'default.png',
    'default.jpg',
    'default.jpeg'
];

$hasProfileImage = (
    !in_array(
        strtolower($profileName),
        $defaultImages,
        true
    ) &&
    is_file(__DIR__ . '/uploads/' . $profileName)
);

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Teacher - To Review</title>

    <link rel="shortcut icon"
          href="img/plmun logo.png"
          type="image/x-icon">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link rel="stylesheet"
          href="teacher_class.css?v=7">

    <link rel="stylesheet"
          href="teacher_class2.css?v=3">

</head>

<body>

<!-- ==========================================
     SIDEBAR OVERLAY
========================================== -->

<div class="sidebar-overlay"
     id="sidebarOverlay"
     onclick="closeMobileSidebar()">
</div>

<!-- ==========================================
     SIDEBAR
========================================== -->

<div class="sidebar" id="sidebar">

    <div class="sidebar-header">

        <button
            type="button"
            class="toggle-btn"
            onclick="toggleSidebar()"
            aria-label="Toggle sidebar">

            <i class="fa-solid fa-bars"></i>

        </button>

        <button
            type="button"
            class="sidebar-close-btn"
            onclick="closeMobileSidebar()"
            aria-label="Close sidebar">

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
            <a href="teacher_to_review.php"
               class="active-link">

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

    </ul>

    <div class="logout-btn">

        <a href="logout.php">

            <i class="fa-solid fa-right-from-bracket"></i>

            <span>Logout</span>

        </a>

    </div>

</div>

<!-- ==========================================
     MAIN CONTENT
========================================== -->

<div class="main-content" id="mainContent">

    <!-- TOPBAR -->

    <div class="topbar">

        <div class="navbar-left">

            <button
                type="button"
                class="mobile-menu"
                onclick="mobileSidebar()"
                aria-label="Open menu">

                <i class="fa-solid fa-bars"></i>

            </button>

            <img
                src="img/classroom logo.png"
                class="navbar-logo"
                alt="PLMun Classroom">

        </div>

        <div class="navbar-right">

            <a href="profile.php"
               class="profile-icon"
               aria-label="My profile">

                <?php if ($hasProfileImage): ?>

                    <img
                        src="uploads/<?= e($profileName) ?>"
                        alt="Profile Picture"
                        onerror="
                            this.style.display='none';
                            this.nextElementSibling.style.display='flex';
                        ">

                    <i
                        class="fa-solid fa-user"
                        style="display:none;">
                    </i>

                <?php else: ?>

                    <i class="fa-solid fa-user"></i>

                <?php endif; ?>

            </a>

        </div>

    </div>

    <!-- ======================================
         REVIEW NAVIGATION
    ======================================= -->

    <div class="class-nav">

        <a
            href="teacher_to_review.php?tab=review<?= $selected_class_id ? '&class_id='.$selected_class_id : '' ?>"
            class="<?= $tab === 'review' ? 'nav-active' : '' ?>">

            To Review

        </a>

        <a
            href="teacher_to_review.php?tab=reviewed<?= $selected_class_id ? '&class_id='.$selected_class_id : '' ?>"
            class="<?= $tab === 'reviewed' ? 'nav-active' : '' ?>">

            Reviewed

        </a>

    </div>

    <!-- ======================================
         REVIEW CONTENT
    ======================================= -->

    <div class="container py-4">

        <div class="assessment-page">

            <!-- CLASS FILTER -->

            <div class="assessment-actions">

                <div class="topic-filter-wrap">

                    <select
                        class="topic-filter-select"
                        id="classFilter"
                        onchange="filterByClass(this.value)">

                        <option
                            value="0"
                            <?= $selected_class_id === 0 ? 'selected' : '' ?>>

                            All Classes

                        </option>

                        <?php foreach ($teacherClasses as $c): ?>

                            <option
                                value="<?= (int)$c['id'] ?>"
                                <?= $selected_class_id === (int)$c['id'] ? 'selected' : '' ?>>

                                <?= e($c['class_name']) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                    <i class="fa-solid fa-chevron-down topic-filter-icon"></i>

                </div>

            </div>

            <!-- ==================================
                 ASSESSMENT LIST
            =================================== -->

            <?php if (empty($grouped)): ?>

                <div class="empty-state">

                    <i class="fa-regular fa-circle-check"></i>

                    <p>
                        <?php if ($tab === 'review'): ?>

                            No assessments awaiting review.

                        <?php else: ?>

                            No fully reviewed assessments yet.

                        <?php endif; ?>
                    </p>

                </div>

            <?php else: ?>

                <?php foreach ($grouped as $classGroup): ?>

                    <div class="topic-block mb-3">

                        <!-- CLASS TITLE -->

                        <div class="topic-header-bar">

                            <div class="topic-header-left">

                                <button
                                    type="button"
                                    class="topic-collapse-btn"
                                    onclick="toggleTopic(this)"
                                    aria-label="Toggle class assessments"
                                    aria-expanded="true">

                                    <i class="fa-solid fa-chevron-down"></i>

                                </button>

                                <span class="topic-name">

                                    <?= e($classGroup['class_name']) ?>

                                </span>

                            </div>

                        </div>

                        <!-- ASSESSMENTS -->

                        <div class="topic-body">

                            <?php foreach ($classGroup['assessments'] as $assessment): ?>

                                <div class="assessment-item">

                                    <!-- ASSESSMENT TITLE -->

                                    <div class="assessment-top-row">

                                        <div class="assessment-title-wrap">

                                            <i class="fa-regular fa-clipboard assessment-icon"></i>

                                            <a
                                                href="teacher_submissions.php?id=<?= (int)$assessment['id'] ?>"
                                                class="assessment-title assessment-title-link">

                                                <?= e($assessment['title']) ?>

                                            </a>

                                        </div>

                                        <!-- DUE DATE -->

                                        <div class="assessment-due-wrap">

                                            <span class="due-label">
                                                Due
                                            </span>

                                            <span class="due-date">

                                                <?php if (!empty($assessment['due_date'])): ?>

                                                    <?= e(date(
                                                        "F d, Y",
                                                        strtotime($assessment['due_date'])
                                                    )) ?>

                                                <?php else: ?>

                                                    No due date

                                                <?php endif; ?>

                                            </span>

                                        </div>

                                    </div>

                                    <!-- ASSESSMENT STATISTICS -->

                                    <div class="assessment-stats-row">

                                        <div class="posted-label">

                                            <?= e($assessment['type']) ?>

                                        </div>

                                        <div class="stat-cell">

                                            <strong>
                                                <?= (int)$assessment['turned_in_count'] ?>
                                            </strong>

                                            <span>
                                                Turned In
                                            </span>

                                        </div>

                                        <div class="stat-cell">

                                            <strong>
                                                <?= (int)$assessment['assigned_count'] ?>
                                            </strong>

                                            <span>
                                                Assigned
                                            </span>

                                        </div>

                                        <div class="stat-cell">

                                            <strong>
                                                <?= (int)$assessment['graded_count'] ?>
                                            </strong>

                                            <span>
                                                Graded
                                            </span>

                                        </div>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </div>

</div>

<!-- ==========================================
     JAVASCRIPT
========================================== -->

<script>

const sidebar = document.getElementById('sidebar');
const mainContent = document.getElementById('mainContent');
const sidebarOverlay = document.getElementById('sidebarOverlay');

/* ==========================================
   SIDEBAR RESPONSIVENESS
========================================== */

function toggleSidebar() {

    if (window.innerWidth <= 768) {
        mobileSidebar();
        return;
    }

    sidebar.classList.toggle('collapsed');

    mainContent.classList.toggle('expanded');
}

function mobileSidebar() {

    if (window.innerWidth > 768) {
        return;
    }

    sidebar.classList.add('show');

    sidebarOverlay.classList.add('show');

    document.body.style.overflow = 'hidden';
}

function closeMobileSidebar() {

    sidebar.classList.remove('show');

    sidebarOverlay.classList.remove('show');

    document.body.style.overflow = '';
}

document.querySelectorAll(
    '.sidebar ul a, .logout-btn a'
).forEach(function(link) {

    link.addEventListener('click', function() {

        if (window.innerWidth <= 768) {
            closeMobileSidebar();
        }

    });

});

document.addEventListener('keydown', function(event) {

    if (event.key === 'Escape') {
        closeMobileSidebar();
    }

});

window.addEventListener('resize', function() {

    if (window.innerWidth > 768) {
        closeMobileSidebar();
    }

});

/* ==========================================
   COLLAPSE CLASS
========================================== */

function toggleTopic(btn) {

    const topicBlock = btn.closest('.topic-block');

    if (!topicBlock) return;

    const topicBody = topicBlock.querySelector('.topic-body');

    if (!topicBody) return;

    topicBody.classList.toggle('collapsed');

    const isExpanded = !topicBody.classList.contains('collapsed');

    btn.setAttribute(
        'aria-expanded',
        String(isExpanded)
    );

    const icon = btn.querySelector('i');

    if (icon) {

        icon.classList.toggle(
            'fa-chevron-down',
            isExpanded
        );

        icon.classList.toggle(
            'fa-chevron-up',
            !isExpanded
        );

    }
}

/* ==========================================
   FILTER CLASSES
========================================== */

function filterByClass(classId) {

    const tab = <?= json_encode($tab) ?>;

    const url = new URL(
        'teacher_to_review.php',
        window.location.href
    );

    url.searchParams.set('tab', tab);

    if (classId && classId !== '0') {

        url.searchParams.set(
            'class_id',
            classId
        );

    }

    window.location.href = url.toString();
}

</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
