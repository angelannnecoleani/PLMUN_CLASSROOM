<?php
session_start();
include 'database.php';

/* =========================================
   TEACHER LOGIN VALIDATION
========================================= */

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$teacher_id = (int) $_SESSION['user_id'];

/* =========================================
   GET TEACHER INFORMATION
========================================= */

$userQuery = mysqli_prepare(
    $conn,
    "SELECT * FROM users WHERE id = ?"
);

mysqli_stmt_bind_param($userQuery, "i", $teacher_id);
mysqli_stmt_execute($userQuery);

$userResult = mysqli_stmt_get_result($userQuery);
$user = mysqli_fetch_assoc($userResult);

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

/* =========================================
   GET ALL CLASSES OWNED BY TEACHER
========================================= */

$classesQuery = mysqli_prepare(
    $conn,
    "
    SELECT
        classes.*,
        COUNT(DISTINCT cs.student_id) AS student_count,
        COUNT(DISTINCT a.id) AS assessment_count
    FROM classes
    LEFT JOIN class_students cs
        ON cs.class_id = classes.id
    LEFT JOIN assessments a
        ON a.class_id = classes.id
    WHERE classes.teacher_id = ?
    GROUP BY classes.id
    ORDER BY classes.class_name ASC
    "
);

mysqli_stmt_bind_param($classesQuery, "i", $teacher_id);
mysqli_stmt_execute($classesQuery);

$classesResult = mysqli_stmt_get_result($classesQuery);

/* =========================================
   PROFILE IMAGE VALIDATION
========================================= */

$profileFile = $user['profile_image'] ?? '';

$hasProfileImage = !empty($profileFile)
    && !in_array(
        strtolower($profileFile),
        ['default.png', 'default.jpg', 'default.jpeg', 'null'],
        true
    )
    && file_exists(
        __DIR__ . '/uploads/' . basename($profileFile)
    );

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Student Performances</title>

    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <!-- Shared Teacher Stylesheet -->
    <link rel="stylesheet" href="teacher_class.css">

    <!-- Page-specific Stylesheet (keep AFTER teacher_class.css) -->
    <link rel="stylesheet" href="teacher_performance.css">
</head>

<body class="performance-page">

<!-- MOBILE SIDEBAR OVERLAY -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileSidebar()"></div>


<!-- SIDEBAR -->
<div class="sidebar" id="sidebar">

    <div class="sidebar-header">

        <!-- DESKTOP COLLAPSE BUTTON -->
        <button type="button" class="toggle-btn" id="desktopToggle"
                onclick="toggleSidebar()"
                aria-label="Collapse or expand sidebar"
                aria-expanded="true">
            <i class="fa-solid fa-bars"></i>
        </button>

        <!-- MOBILE CLOSE BUTTON -->
        <button type="button" class="sidebar-close-btn"
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
            <a href="teacher_performance.php" class="active" aria-current="page">
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


<!-- MAIN CONTENT -->
<div class="main-content" id="mainContent">

    <!-- TOPBAR -->
    <div class="topbar">

        <div class="navbar-left">

            <!-- MOBILE HAMBURGER BUTTON -->
            <button type="button" class="mobile-menu" id="mobileMenuButton"
                    onclick="mobileSidebar()"
                    aria-label="Open navigation menu"
                    aria-expanded="false">
                <i class="fa-solid fa-bars"></i>
            </button>

            <img src="img/classroom logo.png" class="navbar-logo" alt="PLMUN Classroom Logo">

        </div>

        <!-- PROFILE -->
        <div class="navbar-right">
            <a href="profile.php" class="profile-icon" aria-label="Profile">
                <?php if ($hasProfileImage): ?>
                    <img src="uploads/<?php echo htmlspecialchars(basename($profileFile)); ?>"
                         alt="Profile"
                         onerror="this.style.display='none'; this.parentElement.insertAdjacentHTML('beforeend','<i class=&quot;fa-solid fa-user&quot;></i>');">
                <?php else: ?>
                    <i class="fa-solid fa-user"></i>
                <?php endif; ?>
            </a>
        </div>

    </div>


    <!-- PAGE CONTENT -->
    <div class="container py-4">

        <h1 class="page-title">Student's Performances</h1>

        <?php if (mysqli_num_rows($classesResult) === 0): ?>

            <!-- EMPTY STATE -->
            <div class="perf-empty">
                <i class="fa-solid fa-chart-pie"></i>
                <p>No classes yet.</p>
            </div>

        <?php else: ?>

            <!-- CLASS PERFORMANCE CARDS -->
            <div class="class-perf-list">

            <?php while ($cls = mysqli_fetch_assoc($classesResult)): ?>

                <a href="teacher_class_performance.php?id=<?php echo (int) $cls['id']; ?>"
                   class="class-perf-card">

                    <div class="card-left">
                        <h5><?php echo htmlspecialchars($cls['class_name']); ?></h5>

                        <small>
                            <?php if (!empty($cls['section'])): ?>
                                <?php echo htmlspecialchars($cls['section']); ?> &nbsp;•&nbsp;
                            <?php endif; ?>
                            <?php echo (int) $cls['student_count']; ?> students
                            &nbsp;•&nbsp;
                            <?php echo (int) $cls['assessment_count']; ?> assessments
                        </small>
                    </div>

                    <div class="card-right">
                        <span class="meta-pill">
                            <i class="fa-solid fa-users me-1"></i>
                            <?php echo (int) $cls['student_count']; ?> enrolled
                        </span>

                        <div class="view-arrow" aria-hidden="true">
                            <i class="fa-solid fa-arrow-right"></i>
                        </div>
                    </div>

                </a>

            <?php endwhile; ?>

            </div>

        <?php endif; ?>

    </div>

</div>


<!-- JAVASCRIPT -->
<script>

/* Desktop sidebar collapse / expand */
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    const toggleButton = document.getElementById('desktopToggle');

    if (window.innerWidth <= 768) return;

    sidebar.classList.toggle('collapsed');
    mainContent.classList.toggle('expanded');

    toggleButton.setAttribute(
        'aria-expanded',
        (!sidebar.classList.contains('collapsed')).toString()
    );
}

/* Mobile sidebar open */
function mobileSidebar() {
    document.getElementById('sidebar').classList.add('show');
    document.getElementById('sidebarOverlay').classList.add('show');
    document.body.classList.add('no-scroll');
    document.getElementById('mobileMenuButton').setAttribute('aria-expanded', 'true');
}

/* Mobile sidebar close */
function closeMobileSidebar() {
    document.getElementById('sidebar').classList.remove('show');
    document.getElementById('sidebarOverlay').classList.remove('show');
    document.body.classList.remove('no-scroll');
    document.getElementById('mobileMenuButton').setAttribute('aria-expanded', 'false');
}

/* Close sidebar with Escape */
document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeMobileSidebar();
});

/* Close the mobile sidebar when a menu link is tapped */
document.querySelectorAll('.sidebar ul li a, .sidebar .logout-btn a').forEach(function (link) {
    link.addEventListener('click', closeMobileSidebar);
});

/* Reset sidebar state when resizing between desktop and mobile */
window.addEventListener('resize', function () {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');

    if (window.innerWidth > 768) {
        closeMobileSidebar();
    } else {
        // Do not carry the desktop collapsed state into mobile
        sidebar.classList.remove('collapsed');
        mainContent.classList.remove('expanded');
    }
});

</script>

<!-- BOOTSTRAP JAVASCRIPT -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>