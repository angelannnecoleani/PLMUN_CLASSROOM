
<?php
session_start();
include 'database.php';

/* =========================================
   LOGIN CHECK
========================================= */

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Class not found.");
}

$class_id = (int) $_GET['id'];
$student_id = (int) $_SESSION['user_id'];

/* =========================================
   HELPER
========================================= */

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/* =========================================
   CHECK STUDENT ENROLLMENT
========================================= */

$check = mysqli_prepare(
    $conn,
    "SELECT 1
     FROM class_students
     WHERE class_id = ?
       AND student_id = ?
       AND archived = 0
     LIMIT 1"
);

if (!$check) {
    die("Database error: " . e(mysqli_error($conn)));
}

mysqli_stmt_bind_param($check, "ii", $class_id, $student_id);
mysqli_stmt_execute($check);
mysqli_stmt_store_result($check);

$isEnrolled = mysqli_stmt_num_rows($check) > 0;

mysqli_stmt_close($check);

if (!$isEnrolled) {
    http_response_code(403);
    die("Access denied. You are not enrolled in this class.");
}

/* =========================================
   GET CLASS INFORMATION
========================================= */

$classStmt = mysqli_prepare(
    $conn,
    "SELECT
        classes.*,
        COALESCE(users.name, 'No instructor assigned yet') AS teacher_name
     FROM classes
     LEFT JOIN users ON classes.teacher_id = users.id
     WHERE classes.id = ?
     LIMIT 1"
);

if (!$classStmt) {
    die("Database error: " . e(mysqli_error($conn)));
}

mysqli_stmt_bind_param($classStmt, "i", $class_id);
mysqli_stmt_execute($classStmt);

$classResult = mysqli_stmt_get_result($classStmt);
$class = mysqli_fetch_assoc($classResult);

mysqli_stmt_close($classStmt);

if (!$class) {
    die("Class not found.");
}

/* =========================================
   GET STUDENT INFORMATION
========================================= */

$userStmt = mysqli_prepare(
    $conn,
    "SELECT id, name, profile_image
     FROM users
     WHERE id = ?
     LIMIT 1"
);

if (!$userStmt) {
    die("Database error: " . e(mysqli_error($conn)));
}

mysqli_stmt_bind_param($userStmt, "i", $student_id);
mysqli_stmt_execute($userStmt);

$userResult = mysqli_stmt_get_result($userStmt);
$user = mysqli_fetch_assoc($userResult) ?: [];

mysqli_stmt_close($userStmt);

/* =========================================
   PROFILE IMAGE VALIDATION
========================================= */

$profileImageFile = trim((string)($user['profile_image'] ?? ''));
$hasProfileImage = false;

$defaultImages = [
    '',
    'default.png',
    'default.jpg',
    'default.jpeg',
    'default.svg',
    'null'
];

if (!in_array(strtolower($profileImageFile), $defaultImages, true)) {

    $safeName = basename($profileImageFile);
    $imagePath = __DIR__ . '/uploads/' . $safeName;

    if (
        $safeName !== '' &&
        is_file($imagePath) &&
        is_readable($imagePath)
    ) {
        $profileImageFile = $safeName;
        $hasProfileImage = true;
    }
}

if (!$hasProfileImage) {
    $profileImageFile = '';
}

/* =========================================
   FLASH MESSAGE
========================================= */

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* =========================================
   GET ASSESSMENTS AND SUBMISSION STATUS
========================================= */

$aStmt = mysqli_prepare(
    $conn,
    "SELECT
        a.id,
        a.title,
        a.instructions,
        a.due_date,
        a.points,
        a.type,
        s.file_path,
        s.status,
        s.submitted_at
     FROM assessments a
     LEFT JOIN submissions s
        ON s.assessment_id = a.id
        AND s.student_id = ?
     WHERE a.class_id = ?
     ORDER BY a.created_at DESC"
);

if (!$aStmt) {
    die("Database error: " . e(mysqli_error($conn)));
}

mysqli_stmt_bind_param($aStmt, "ii", $student_id, $class_id);
mysqli_stmt_execute($aStmt);

$result = mysqli_stmt_get_result($aStmt);

$assignments = [];

while ($r = mysqli_fetch_assoc($result)) {
    $assignments[] = $r;
}

mysqli_stmt_close($aStmt);

/* =========================================
   ASSESSMENT TYPE STYLE
========================================= */

function assess_type_style($type) {

    $key = strtolower(trim($type ?? ''));

    $map = [
        'quiz' => [
            'icon' => 'fa-solid fa-circle-question',
            'color' => '#7c5cff'
        ],
        'exam' => [
            'icon' => 'fa-solid fa-file-pen',
            'color' => '#d64545'
        ],
        'project' => [
            'icon' => 'fa-solid fa-diagram-project',
            'color' => '#e08a2c'
        ],
        'activity' => [
            'icon' => 'fa-solid fa-pen',
            'color' => '#3f87b5'
        ],
        'assignment' => [
            'icon' => 'fa-solid fa-clipboard-list',
            'color' => '#2f9e64'
        ]
    ];

    return $map[$key] ?? [
        'icon' => 'fa-solid fa-clipboard-list',
        'color' => '#3f87b5'
    ];
}

/* =========================================
   DUE DATE STATUS
========================================= */

function assess_due_chip($due_date, $status, $submitted_at = null) {

    $isLate = false;

    if (!empty($submitted_at) && !empty($due_date)) {
        $isLate = strtotime($submitted_at) > strtotime($due_date);
    }

    if ($status === 'done') {
        return $isLate
            ? ['label' => 'Done (Late)', 'class' => 'chip-late']
            : ['label' => 'Marked done', 'class' => 'chip-done'];
    }

    if ($status === 'submitted') {
        return $isLate
            ? ['label' => 'Submitted (Late)', 'class' => 'chip-late']
            : ['label' => 'Submitted', 'class' => 'chip-submitted'];
    }

    if (empty($due_date)) {
        return [
            'label' => 'No due date',
            'class' => 'chip-neutral'
        ];
    }

    $dueTimestamp = strtotime($due_date);

    if ($dueTimestamp === false) {
        return [
            'label' => 'No due date',
            'class' => 'chip-neutral'
        ];
    }

    $daysLeft = (int) ceil(
        ($dueTimestamp - strtotime(date('Y-m-d'))) / 86400
    );

    if ($daysLeft < 0) {
        return ['label' => 'Overdue', 'class' => 'chip-overdue'];
    }

    if ($daysLeft === 0) {
        return ['label' => 'Due today', 'class' => 'chip-urgent'];
    }

    if ($daysLeft === 1) {
        return ['label' => 'Due tomorrow', 'class' => 'chip-urgent'];
    }

    if ($daysLeft <= 3) {
        return [
            'label' => "Due in {$daysLeft} days",
            'class' => 'chip-warn'
        ];
    }

    return [
        'label' => 'Due ' . date('M d', $dueTimestamp),
        'class' => 'chip-ok'
    ];
}

/* =========================================
   PROGRESS CALCULATION
========================================= */

$total = count($assignments);
$completed = 0;
$submittedCount = 0;

foreach ($assignments as $a) {

    $status = $a['status'] ?? '';

    if ($status === 'done' || $status === 'submitted') {
        $completed++;
    }

    if ($status === 'submitted') {
        $submittedCount++;
    }
}

$todoCount = $total - $completed;

$pct = $total > 0
    ? round(($completed / $total) * 100)
    : 0;

/* SVG RING */

$circumference = 2 * M_PI * 42;
$ringOffset = $circumference - ($pct / 100) * $circumference;

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo e($class['class_name']); ?> - Assignments</title>

    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="student_class.css">
    <link rel="stylesheet" href="student_assessment.css">

</head>

<body>

<!-- =========================================
     MOBILE SIDEBAR OVERLAY
========================================= -->

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
    onclick="closeMobileSidebar()">
</div>

<!-- =========================================
     SIDEBAR
========================================= -->

<div class="sidebar" id="sidebar">

    <div class="sidebar-header">

        <button
            type="button"
            class="toggle-btn"
            onclick="toggleSidebar()"
            aria-label="Toggle sidebar">

            <i class="fa-solid fa-bars"></i>

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
            <a href="student_class_page.php">
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
                <span>Assignment</span>
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

<!-- =========================================
     MAIN CONTENT
========================================= -->

<div class="main-content" id="mainContent">

    <!-- TOPBAR -->

    <div class="topbar">

        <div class="navbar-left">

            <button
                type="button"
                class="mobile-menu"
                onclick="mobileSidebar()"
                aria-label="Open navigation menu">

                <i class="fa-solid fa-bars"></i>

            </button>

            <img
                src="img/classroom logo.png"
                class="navbar-logo"
                alt="PLMUN Classroom Logo">

        </div>

        <div class="navbar-right">

            <a href="profile.php" class="profile-icon" title="My Profile">

                <?php if ($hasProfileImage): ?>

                    <img
                        src="uploads/<?php echo e(rawurlencode($profileImageFile)); ?>"
                        class="profile-image"
                        alt="Profile Picture"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">

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

    <!-- =========================================
         CLASS NAVIGATION
    ========================================= -->

    <nav class="class-nav" aria-label="Class navigation">

        <a href="student_class.php?id=<?php echo $class_id; ?>">
            Stream
        </a>

        <a
            href="student_assessment.php?id=<?php echo $class_id; ?>"
            class="nav-active">
            Assignments
        </a>

        <a href="student_material.php?id=<?php echo $class_id; ?>">
            Materials
        </a>

        <a href="student_people.php?id=<?php echo $class_id; ?>">
            People
        </a>

    </nav>

    <!-- =========================================
         PAGE CONTENT
    ========================================= -->

    <main class="assessment-container">

        <!-- FLASH MESSAGE -->

        <?php if ($flash): ?>

            <div class="assess-flash mb-3">
                <i class="fa-solid fa-circle-check me-2"></i>
                <?php echo e($flash); ?>
            </div>

        <?php endif; ?>

        <!-- =========================================
             OVERVIEW
        ========================================= -->

        <section class="assess-overview mb-4">

            <!-- PROGRESS RING -->

            <div
                class="assess-ring"
                aria-label="<?php echo $pct; ?>% complete">

                <svg viewBox="0 0 100 100" aria-hidden="true">

                    <circle
                        class="ring-track"
                        cx="50"
                        cy="50"
                        r="42">
                    </circle>

                    <circle
                        class="ring-fill"
                        cx="50"
                        cy="50"
                        r="42"
                        style="
                            stroke-dasharray: <?php echo $circumference; ?>;
                            stroke-dashoffset: <?php echo $ringOffset; ?>;
                        ">
                    </circle>

                </svg>

                <div class="assess-ring-label">
                    <strong><?php echo $pct; ?>%</strong>
                    <span>done</span>
                </div>

            </div>

            <!-- OVERVIEW TEXT -->

            <div class="assess-overview-text">

                <h3>Assignments</h3>

                <p>
                    <?php echo $completed; ?> of
                    <?php echo $total; ?> handled in
                    <?php echo e($class['class_name']); ?>
                </p>

                <?php if (empty($class['teacher_id'])): ?>

                    <small class="text-muted">
                        No instructor assigned yet
                    </small>

                <?php endif; ?>

            </div>

            <!-- FILTER BUTTONS -->

            <div class="assess-filters" role="group" aria-label="Filter assignments">

                <button
                    type="button"
                    class="filter-chip active"
                    data-filter="all">

                    All
                    <span class="count"><?php echo $total; ?></span>

                </button>

                <button
                    type="button"
                    class="filter-chip"
                    data-filter="todo">

                    To do
                    <span class="count"><?php echo $todoCount; ?></span>

                </button>

                <button
                    type="button"
                    class="filter-chip"
                    data-filter="submitted">

                    Submitted
                    <span class="count"><?php echo $submittedCount; ?></span>

                </button>

                <button
                    type="button"
                    class="filter-chip"
                    data-filter="done">

                    Done

                </button>

            </div>

        </section>

        <!-- =========================================
             ASSIGNMENT LIST
        ========================================= -->

        <?php if ($total === 0): ?>

            <div class="assess-empty">

                <i class="fa-regular fa-folder-open"></i>

                <h5>Nothing posted yet</h5>

                <p>
                    New assignments from your teacher will show up here.
                </p>

            </div>

        <?php else: ?>

            <div class="assess-list" id="assessList">

                <?php foreach ($assignments as $row): ?>

                    <?php

                    $status = $row['status'] ?? '';

                    $isDone = ($status === 'done');
                    $isSubmitted = ($status === 'submitted');

                    $filterState = $isDone
                        ? 'done'
                        : ($isSubmitted ? 'submitted' : 'todo');

                    $style = assess_type_style($row['type'] ?? '');

                    $chip = assess_due_chip(
                        $row['due_date'] ?? null,
                        $status,
                        $row['submitted_at'] ?? null
                    );

                    ?>

                    <article
                        class="assess-card"
                        data-status="<?php echo e($filterState); ?>">

                        <div
                            class="assess-stripe"
                            style="background: <?php echo e($style['color']); ?>">
                        </div>

                        <div class="assess-card-body">

                            <div class="assess-card-top">

                                <!-- TYPE ICON -->

                                <div
                                    class="assess-type-icon"
                                    style="
                                        background: <?php echo e($style['color']); ?>1a;
                                        color: <?php echo e($style['color']); ?>;
                                    ">

                                    <i class="<?php echo e($style['icon']); ?>"></i>

                                </div>

                                <!-- ASSIGNMENT DETAILS -->

                                <div class="assess-card-heading">

                                    <a
                                        href="student_assessment_view.php?id=<?php echo (int)$row['id']; ?>"
                                        class="assessment-title-link">

                                        <h4><?php echo e($row['title']); ?></h4>

                                    </a>

                                    <div class="assess-meta">

                                        <span class="meta-type">
                                            <?php echo e($row['type']); ?>
                                        </span>

                                        <span class="meta-dot">&middot;</span>

                                        <span class="meta-points">
                                            <?php echo e($row['points']); ?> pts
                                        </span>

                                    </div>

                                </div>

                                <!-- DUE DATE STATUS -->

                                <span class="due-chip <?php echo e($chip['class']); ?>">
                                    <?php echo e($chip['label']); ?>
                                </span>

                            </div>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

            <div class="assess-no-results" id="noResults" style="display:none;">
                <i class="fa-solid fa-filter-circle-xmark"></i>
                <p>No assignments found for this filter.</p>
            </div>

        <?php endif; ?>

    </main>

</div>

<!-- =========================================
     JAVASCRIPT
========================================= -->

<script>

/* =========================================
   SIDEBAR ELEMENTS
========================================= */

const sidebar = document.getElementById('sidebar');
const mainContent = document.getElementById('mainContent');
const sidebarOverlay = document.getElementById('sidebarOverlay');

/* =========================================
   DESKTOP SIDEBAR TOGGLE
========================================= */

function toggleSidebar() {

    if (window.innerWidth <= 768) {
        closeMobileSidebar();
        return;
    }

    sidebar.classList.toggle('collapsed');
    mainContent.classList.toggle('expanded');
}

/* =========================================
   OPEN MOBILE SIDEBAR
========================================= */

function mobileSidebar() {

    sidebar.classList.add('show');
    sidebarOverlay.classList.add('active');

    document.body.style.overflow = 'hidden';
}

/* =========================================
   CLOSE MOBILE SIDEBAR
========================================= */

function closeMobileSidebar() {

    sidebar.classList.remove('show');
    sidebarOverlay.classList.remove('active');

    document.body.style.overflow = '';
}

/* =========================================
   CLOSE SIDEBAR WHEN NAVIGATION LINK
   IS CLICKED
========================================= */

sidebar.querySelectorAll('a').forEach(function(link) {

    link.addEventListener('click', function() {

        if (window.innerWidth <= 768) {
            closeMobileSidebar();
        }

    });

});

/* =========================================
   ESCAPE KEY
========================================= */

document.addEventListener('keydown', function(event) {

    if (event.key === 'Escape') {
        closeMobileSidebar();
    }

});

/* =========================================
   WINDOW RESIZE
========================================= */

window.addEventListener('resize', function() {

    if (window.innerWidth > 768) {
        closeMobileSidebar();
    }

});

/* =========================================
   SHOW SELECTED FILENAME
   (For pages using .file-pill)
========================================= */

function assessFileName(input) {

    const filePill = input.closest('.file-pill');

    if (!filePill) return;

    const label = filePill.querySelector('.file-pill-text');

    if (!label) return;

    label.textContent = input.files[0]
        ? input.files[0].name
        : 'Choose file';
}

/* =========================================
   ASSIGNMENT FILTER
========================================= */

(function() {

    const chips = document.querySelectorAll('.filter-chip');
    const cards = document.querySelectorAll('.assess-card');
    const noResults = document.getElementById('noResults');

    chips.forEach(chip => {

        chip.addEventListener('click', function() {

            chips.forEach(c => c.classList.remove('active'));
            chip.classList.add('active');

            const filter = chip.dataset.filter;
            let visibleCount = 0;

            cards.forEach(card => {

                const matches =
                    filter === 'all' ||
                    card.dataset.status === filter;

                card.style.display = matches ? '' : 'none';

                if (matches) {
                    visibleCount++;
                }

            });

            if (noResults) {
                noResults.style.display =
                    visibleCount === 0 ? 'block' : 'none';
            }

        });

    });

})();

</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
