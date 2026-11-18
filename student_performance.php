
<?php
session_start();
include 'database.php';

/* =========================================
   SESSION VALIDATION
========================================= */

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$student_id = (int) $_SESSION['user_id'];

/* =========================================
   HELPER FUNCTION
========================================= */

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/* =========================================
   GET STUDENT INFORMATION
========================================= */

$userStmt = mysqli_prepare(
    $conn,
    "SELECT id, name, profile_image FROM users WHERE id = ? LIMIT 1"
);

mysqli_stmt_bind_param($userStmt, "i", $student_id);
mysqli_stmt_execute($userStmt);

$userResult = mysqli_stmt_get_result($userStmt);
$user = mysqli_fetch_assoc($userResult);

mysqli_stmt_close($userStmt);

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

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
   GET ENROLLED CLASSES
========================================= */

$classesStmt = mysqli_prepare($conn, "
    SELECT
        classes.*,
        users.name AS teacher_name,
        COUNT(DISTINCT a.id) AS assessment_count
    FROM class_students cs
    INNER JOIN classes
        ON cs.class_id = classes.id
    INNER JOIN users
        ON classes.teacher_id = users.id
    LEFT JOIN assessments a
        ON a.class_id = classes.id
    WHERE cs.student_id = ?
    GROUP BY classes.id
    ORDER BY classes.class_name ASC
");

mysqli_stmt_bind_param($classesStmt, "i", $student_id);
mysqli_stmt_execute($classesStmt);

$classesQuery = mysqli_stmt_get_result($classesStmt);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Performance</title>

    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">

    <link rel="stylesheet" href="student_dashboard.css">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        /* =========================================
           GENERAL PAGE
        ========================================= */

        * {
            box-sizing: border-box;
        }

        body {
            overflow-x: hidden;
            background: #f5f8fb;
        }

        .main-content {
            min-width: 0;
        }

        .performance-container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 28px 30px;
        }

        .page-title {
            font-size: 26px;
            font-weight: 800;
            color: #1a2d3e;
            margin-bottom: 24px;
        }

        /* =========================================
           TOPBAR
        ========================================= */

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            min-width: 0;
        }

        .navbar-left,
        .navbar-right {
            display: flex;
            align-items: center;
        }

        .navbar-left {
            gap: 12px;
            min-width: 0;
        }

        .navbar-logo {
            max-width: 190px;
            height: auto;
            object-fit: contain;
        }

        .mobile-menu {
            display: none;
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 8px;
            background: transparent;
            color: #1a2d3e;
            font-size: 20px;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
        }

        .mobile-menu:hover {
            background: #edf3f8;
        }

        .profile-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            border-radius: 50%;
            overflow: hidden;
            background: #e9f1f7;
            text-decoration: none;
            flex-shrink: 0;
        }

        .profile-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-fallback {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #3f87b5;
            font-size: 19px;
        }

        /* =========================================
           CLASS PERFORMANCE CARD
        ========================================= */

        .class-perf-card {
            width: 100%;
            min-width: 0;
            background: #fff;
            border: 1px solid #e0e8f0;
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 12px;

            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;

            text-decoration: none;
            color: inherit;

            transition: box-shadow 0.2s, transform 0.15s;
        }

        .class-perf-card:hover {
            box-shadow: 0 6px 20px rgba(0, 0, 0, .1);
            transform: translateY(-1px);
            color: inherit;
        }

        .card-left {
            flex: 1;
            min-width: 0;
        }

        .card-left h5 {
            font-weight: 700;
            font-size: 16px;
            color: #1a2d3e;
            margin: 0 0 6px;
            overflow-wrap: anywhere;
        }

        .card-left small {
            display: block;
            font-size: 13px;
            color: #888;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }

        .card-right {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 14px;
            flex-shrink: 0;
        }

        /* =========================================
           MINI PROGRESS RING
        ========================================= */

        .mini-ring-wrap {
            position: relative;
            width: 52px;
            height: 52px;
            flex-shrink: 0;
        }

        .mini-ring-wrap svg {
            display: block;
            transform: rotate(-90deg);
        }

        .ring-bg {
            fill: none;
            stroke: #eef2f7;
            stroke-width: 5;
        }

        .ring-fill {
            fill: none;
            stroke-width: 5;
            stroke-linecap: round;
            transition: stroke-dashoffset 1s ease;
        }

        .ring-pct {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 800;
            color: #1a2d3e;
        }

        /* =========================================
           VIEW ARROW
        ========================================= */

        .view-arrow {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #3f87b5;
            color: #fff;

            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 14px;
            transition: background 0.2s;
            flex-shrink: 0;
        }

        .class-perf-card:hover .view-arrow {
            background: #2f76a7;
        }

        /* =========================================
           EMPTY STATE
        ========================================= */

        .perf-empty {
            background: #fff;
            border: 1px solid #e0e8f0;
            border-radius: 12px;
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .perf-empty i {
            font-size: 48px;
            display: block;
            margin-bottom: 14px;
            color: #b7c5d1;
        }

        .perf-empty p {
            margin: 0;
            font-size: 15px;
        }

        /* =========================================
           SIDEBAR OVERLAY
        ========================================= */

        .sidebar-overlay {
            display: none;
        }

        /* =========================================
           TABLET RESPONSIVENESS
        ========================================= */

        @media (max-width: 992px) {

            .performance-container {
                padding: 25px 22px;
            }

            .page-title {
                font-size: 24px;
            }

            .class-perf-card {
                padding: 18px 20px;
            }
        }

        /* =========================================
           MOBILE RESPONSIVENESS
        ========================================= */

        @media (max-width: 768px) {

            /* Main content */

            .main-content {
                width: 100% !important;
                margin-left: 0 !important;
                min-width: 0;
            }

            .performance-container {
                width: 100%;
                padding: 22px 16px;
            }

            /* Mobile topbar */

            .topbar {
                width: 100%;
                min-height: 65px;
                padding: 10px 16px;
                gap: 10px;
            }

            .mobile-menu {
                display: flex !important;
            }

            .navbar-logo {
                max-width: 155px;
                max-height: 40px;
            }

            .profile-icon {
                width: 38px;
                height: 38px;
            }

            /* Sidebar drawer */

            .sidebar {
                position: fixed !important;
                top: 0;
                left: 0;
                bottom: 0;
                width: 260px !important;
                max-width: 85vw;
                height: 100vh;
                height: 100dvh;
                z-index: 1100;

                transform: translateX(-100%);
                transition: transform 0.3s ease;
                overflow-y: auto;
            }

            .sidebar.show {
                transform: translateX(0);
            }

            /* Center sidebar menu button */

            .sidebar .sidebar-header {
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 65px;
                padding: 10px 15px;
            }

            .sidebar .toggle-btn {
                display: flex !important;
                align-items: center;
                justify-content: center;
                width: 40px;
                height: 40px;
                border: none;
                background: transparent;
                color: #333;
                font-size: 20px;
                cursor: pointer;
                border-radius: 8px;
            }

            .sidebar .toggle-btn:hover {
                background: rgba(0, 0, 0, 0.08);
            }

            /* Overlay */

            .sidebar-overlay {
                display: block;
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.4);
                z-index: 1090;
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.3s ease, visibility 0.3s ease;
            }

            .sidebar-overlay.active {
                opacity: 1;
                visibility: visible;
            }

            /* Page title */

            .page-title {
                font-size: 23px;
                margin-bottom: 20px;
            }

            /* Class cards */

            .class-perf-card {
                padding: 17px;
                border-radius: 11px;
                gap: 14px;
            }

            .card-left h5 {
                font-size: 15px;
            }

            .card-left small {
                font-size: 12px;
            }

            .card-right {
                gap: 12px;
            }
        }

        /* =========================================
           SMALL MOBILE
        ========================================= */

        @media (max-width: 576px) {

            .performance-container {
                padding: 20px 13px;
            }

            .topbar {
                padding: 9px 13px;
            }

            .navbar-logo {
                max-width: 130px;
                max-height: 36px;
            }

            .page-title {
                font-size: 21px;
                margin-bottom: 17px;
            }

            .class-perf-card {
                flex-direction: column;
                align-items: stretch;
                padding: 16px;
                gap: 13px;
            }

            .card-left {
                width: 100%;
            }

            .card-left h5 {
                font-size: 15px;
                line-height: 1.4;
            }

            .card-left small {
                font-size: 12px;
                line-height: 1.7;
            }

            .card-right {
                width: 100%;
                justify-content: space-between;
                padding-top: 5px;
                border-top: 1px solid #f0f2f5;
            }

            .mini-ring-wrap {
                width: 48px;
                height: 48px;
            }

            .mini-ring-wrap svg {
                width: 48px;
                height: 48px;
            }

            .ring-pct {
                font-size: 10px;
            }

            .view-arrow {
                width: 34px;
                height: 34px;
                font-size: 13px;
            }

            .perf-empty {
                padding: 45px 15px;
            }

            .perf-empty i {
                font-size: 40px;
            }

            .perf-empty p {
                font-size: 13px;
            }
        }

        /* =========================================
           EXTRA SMALL DEVICES
        ========================================= */

        @media (max-width: 360px) {

            .performance-container {
                padding: 17px 10px;
            }

            .navbar-logo {
                max-width: 115px;
            }

            .page-title {
                font-size: 19px;
            }

            .class-perf-card {
                padding: 14px;
            }

            .card-left h5 {
                font-size: 14px;
            }

            .card-left small {
                font-size: 11px;
            }
        }
    </style>
</head>

<body>

<!-- =========================================
     SIDEBAR OVERLAY
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
            <a href="student_classpage.php">
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
            <a
                href="student_performance.php"
                class="active"
                style="color:#3f87b5; border-left:3px solid #3f87b5;">

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
                        alt="Profile Picture"
                        class="profile-image"
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

    <!-- PAGE CONTENT -->

    <main class="performance-container">

        <h1 class="page-title">My Performance</h1>

        <?php if (mysqli_num_rows($classesQuery) === 0): ?>

            <div class="perf-empty">
                <i class="fa-solid fa-chart-pie"></i>
                <p>You are not enrolled in any class yet.</p>
            </div>

        <?php else: ?>

            <?php while ($cls = mysqli_fetch_assoc($classesQuery)): ?>

                <?php

                /* =========================================
                   GET STUDENT GRADES FOR THIS CLASS
                ========================================= */

                $gStmt = mysqli_prepare($conn, "
                    SELECT s.grade, a.points
                    FROM submissions s
                    INNER JOIN assessments a
                        ON s.assessment_id = a.id
                    WHERE a.class_id = ?
                      AND s.student_id = ?
                      AND s.grade IS NOT NULL
                ");

                $class_id = (int)$cls['id'];

                mysqli_stmt_bind_param(
                    $gStmt,
                    "ii",
                    $class_id,
                    $student_id
                );

                mysqli_stmt_execute($gStmt);

                $gResult = mysqli_stmt_get_result($gStmt);
                $gRows = mysqli_fetch_all($gResult, MYSQLI_ASSOC);

                mysqli_stmt_close($gStmt);

                /* =========================================
                   COMPUTE OVERALL PERCENTAGE
                ========================================= */

                $total = 0;
                $maxTotal = 0;

                foreach ($gRows as $g) {
                    $total += (float)$g['grade'];
                    $maxTotal += (float)$g['points'];
                }

                $pct = $maxTotal > 0
                    ? round(($total / $maxTotal) * 100, 1)
                    : null;

                /* =========================================
                   PROGRESS RING COLOR
                ========================================= */

                if ($pct === null) {
                    $ringColor = '#adb5bd';
                } elseif ($pct >= 90) {
                    $ringColor = '#28a745';
                } elseif ($pct >= 75) {
                    $ringColor = '#3f87b5';
                } elseif ($pct >= 60) {
                    $ringColor = '#f0a500';
                } else {
                    $ringColor = '#dc3545';
                }

                $circumference = 2 * M_PI * 22;

                $offset = $pct !== null
                    ? $circumference - ($pct / 100) * $circumference
                    : $circumference;

                ?>

                <!-- CLASS PERFORMANCE CARD -->

                <a
                    href="student_class_performance.php?id=<?php echo $class_id; ?>"
                    class="class-perf-card">

                    <div class="card-left">

                        <h5>
                            <?php echo e($cls['class_name']); ?>
                        </h5>

                        <small>

                            <?php if (!empty($cls['section'])): ?>

                                <?php echo e($cls['section']); ?>
                                &nbsp;•&nbsp;

                            <?php endif; ?>

                            <?php echo e($cls['teacher_name']); ?>

                            &nbsp;•&nbsp;

                            <?php echo (int)$cls['assessment_count']; ?>
                            assessments

                        </small>

                    </div>

                    <div class="card-right">

                        <!-- PROGRESS RING -->

                        <div class="mini-ring-wrap">

                            <svg viewBox="0 0 52 52" width="52" height="52">

                                <circle
                                    class="ring-bg"
                                    cx="26"
                                    cy="26"
                                    r="22">
                                </circle>

                                <circle
                                    class="ring-fill"
                                    cx="26"
                                    cy="26"
                                    r="22"
                                    stroke="<?php echo e($ringColor); ?>"
                                    stroke-dasharray="<?php echo round($circumference, 2); ?>"
                                    stroke-dashoffset="<?php echo round($offset, 2); ?>">
                                </circle>

                            </svg>

                            <div class="ring-pct">
                                <?php echo $pct !== null ? e($pct) . '%' : '—'; ?>
                            </div>

                        </div>

                        <!-- VIEW BUTTON -->

                        <div class="view-arrow">
                            <i class="fa-solid fa-arrow-right"></i>
                        </div>

                    </div>

                </a>

            <?php endwhile; ?>

        <?php endif; ?>

    </main>

</div>

<!-- =========================================
     JAVASCRIPT
========================================= -->

<script>
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
   MOBILE SIDEBAR OPEN
========================================= */

function mobileSidebar() {

    sidebar.classList.add('show');
    sidebarOverlay.classList.add('active');

    document.body.style.overflow = 'hidden';
}

/* =========================================
   MOBILE SIDEBAR CLOSE
========================================= */

function closeMobileSidebar() {

    sidebar.classList.remove('show');
    sidebarOverlay.classList.remove('active');

    document.body.style.overflow = '';
}

/* =========================================
   CLOSE SIDEBAR WHEN LINK IS CLICKED
========================================= */

sidebar.querySelectorAll('a').forEach(function(link) {

    link.addEventListener('click', function() {

        if (window.innerWidth <= 768) {
            closeMobileSidebar();
        }

    });

});

/* =========================================
   CLOSE SIDEBAR WITH ESCAPE KEY
========================================= */

document.addEventListener('keydown', function(event) {

    if (event.key === 'Escape') {
        closeMobileSidebar();
    }

});

/* =========================================
   HANDLE WINDOW RESIZE
========================================= */

window.addEventListener('resize', function() {

    if (window.innerWidth > 768) {
        closeMobileSidebar();
    }

});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>

<?php
mysqli_stmt_close($classesStmt);
?>
