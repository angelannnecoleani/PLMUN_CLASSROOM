
<?php
session_start();
include 'database.php';

/* =========================================
   SESSION VALIDATION
========================================= */

if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$student_id = (int) $_SESSION['user_id'];

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Class not found.");
}

$class_id = (int) $_GET['id'];

/* =========================================
   HELPER FUNCTION
========================================= */

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/* =========================================
   GET STUDENT INFORMATION
========================================= */

$userStmt = mysqli_prepare($conn, "
    SELECT id, name, profile_image
    FROM users
    WHERE id = ?
    LIMIT 1
");

mysqli_stmt_bind_param($userStmt, "i", $student_id);
mysqli_stmt_execute($userStmt);

$user = mysqli_fetch_assoc(mysqli_stmt_get_result($userStmt));

mysqli_stmt_close($userStmt);

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

/* =========================================
   VERIFY CLASS ENROLLMENT
========================================= */

$enrollCheck = mysqli_prepare($conn, "
    SELECT
        classes.*,
        users.name AS teacher_name
    FROM class_students cs
    INNER JOIN classes
        ON cs.class_id = classes.id
    INNER JOIN users
        ON classes.teacher_id = users.id
    WHERE cs.class_id = ?
      AND cs.student_id = ?
    LIMIT 1
");

mysqli_stmt_bind_param(
    $enrollCheck,
    "ii",
    $class_id,
    $student_id
);

mysqli_stmt_execute($enrollCheck);

$class = mysqli_fetch_assoc(
    mysqli_stmt_get_result($enrollCheck)
);

mysqli_stmt_close($enrollCheck);

if (!$class) {
    http_response_code(404);
    die("Class not found or you are not enrolled in this class.");
}

/* =========================================
   PROFILE IMAGE VALIDATION
========================================= */

$profileImage = trim((string)($user['profile_image'] ?? ''));

$hasProfileImage = false;

$defaultImages = [
    '',
    'default.png',
    'default.jpg',
    'default.jpeg',
    'default.svg',
    'null'
];

if (!in_array(strtolower($profileImage), $defaultImages, true)) {

    $safeImage = basename($profileImage);

    $imagePath = __DIR__ . '/uploads/' . $safeImage;

    if (
        $safeImage !== '' &&
        is_file($imagePath) &&
        is_readable($imagePath)
    ) {
        $profileImage = $safeImage;
        $hasProfileImage = true;
    }
}

if (!$hasProfileImage) {
    $profileImage = '';
}

/* =========================================
   GET CLASS ASSESSMENTS
========================================= */

$aStmt = mysqli_prepare($conn, "
    SELECT
        id,
        title,
        points,
        type,
        due_date
    FROM assessments
    WHERE class_id = ?
    ORDER BY id ASC
");

mysqli_stmt_bind_param($aStmt, "i", $class_id);

mysqli_stmt_execute($aStmt);

$assessments = mysqli_stmt_get_result($aStmt)
    ->fetch_all(MYSQLI_ASSOC);

mysqli_stmt_close($aStmt);

/* =========================================
   GET THIS STUDENT'S GRADES
========================================= */

$gStmt = mysqli_prepare($conn, "
    SELECT
        s.assessment_id,
        s.grade,
        s.status,
        s.submitted_at
    FROM submissions s
    INNER JOIN assessments a
        ON s.assessment_id = a.id
    WHERE s.student_id = ?
      AND a.class_id = ?
");

mysqli_stmt_bind_param(
    $gStmt,
    "ii",
    $student_id,
    $class_id
);

mysqli_stmt_execute($gStmt);

$gradeRows = mysqli_stmt_get_result($gStmt)
    ->fetch_all(MYSQLI_ASSOC);

mysqli_stmt_close($gStmt);

$myGrades = [];

foreach ($gradeRows as $g) {
    $myGrades[$g['assessment_id']] = $g;
}

/* =========================================
   GET CLASS AVERAGES
========================================= */

$avgStmt = mysqli_prepare($conn, "
    SELECT
        s.assessment_id,
        AVG(s.grade) AS avg_grade
    FROM submissions s
    INNER JOIN assessments a
        ON s.assessment_id = a.id
    WHERE a.class_id = ?
      AND s.grade IS NOT NULL
    GROUP BY s.assessment_id
");

mysqli_stmt_bind_param($avgStmt, "i", $class_id);

mysqli_stmt_execute($avgStmt);

$avgRows = mysqli_stmt_get_result($avgStmt)
    ->fetch_all(MYSQLI_ASSOC);

mysqli_stmt_close($avgStmt);

$classAvg = [];

foreach ($avgRows as $r) {
    $classAvg[$r['assessment_id']] = round(
        (float)$r['avg_grade'],
        1
    );
}

/* =========================================
   COMPUTE OVERALL PERFORMANCE
========================================= */

$myTotal = 0;
$myMax = 0;

$graded = 0;
$submitted = 0;

$myLineData = [];
$avgLineData = [];
$chartLabels = [];

foreach ($assessments as $a) {

    $assessmentId = (int)$a['id'];

    $points = (float)$a['points'];

    $chartLabels[] = $a['title'];

    $g = $myGrades[$assessmentId] ?? null;

    /* STUDENT SCORE */

    if (
        $g &&
        $g['grade'] !== null &&
        $points > 0
    ) {

        $grade = (float)$g['grade'];

        $pct = round(
            ($grade / $points) * 100,
            1
        );

        $myLineData[] = $pct;

        $myTotal += $grade;
        $myMax += $points;

        $graded++;

    } else {

        $myLineData[] = null;
    }

    /* CLASS AVERAGE */

    if (
        isset($classAvg[$assessmentId]) &&
        $points > 0
    ) {

        $avgLineData[] = round(
            ($classAvg[$assessmentId] / $points) * 100,
            1
        );

    } else {

        $avgLineData[] = null;
    }

    /* SUBMISSION COUNT */

    if (
        $g &&
        in_array($g['status'], ['submitted', 'done'], true)
    ) {
        $submitted++;
    }
}

/* =========================================
   OVERALL PERCENTAGE
========================================= */

$overallPct = $myMax > 0
    ? round(($myTotal / $myMax) * 100, 1)
    : null;

/* =========================================
   PERFORMANCE STATUS
========================================= */

if ($overallPct === null) {

    $status = 'No grades yet';
    $statusClass = 'ungraded';

} elseif ($overallPct >= 90) {

    $status = 'Excellent';
    $statusClass = 'excellent';

} elseif ($overallPct >= 85) {

    $status = 'Very Good';
    $statusClass = 'good';

} elseif ($overallPct >= 75) {

    $status = 'Good';
    $statusClass = 'good';

} elseif ($overallPct >= 60) {

    $status = 'Needs Improvement';
    $statusClass = 'average';

} else {

    $status = 'Needs Help';
    $statusClass = 'needs-help';
}

/* =========================================
   PROGRESS RING COLOR
========================================= */

$bigCirc = 2 * M_PI * 54;

$bigOffset = $overallPct !== null
    ? $bigCirc - ($overallPct / 100) * $bigCirc
    : $bigCirc;

if ($overallPct === null) {

    $ringColor = '#adb5bd';

} elseif ($overallPct >= 90) {

    $ringColor = '#28a745';

} elseif ($overallPct >= 75) {

    $ringColor = '#3f87b5';

} elseif ($overallPct >= 60) {

    $ringColor = '#f0a500';

} else {

    $ringColor = '#dc3545';
}

/* =========================================
   SUBMISSION STATISTICS
========================================= */

$totalAssessments = count($assessments);

$notSubmitted = max(
    0,
    $totalAssessments - $submitted
);

$submittedButNotGraded = max(
    0,
    $submitted - $graded
);

$pendingCount = max(
    0,
    $totalAssessments - $graded - $notSubmitted
);

$donutData = [
    $graded,
    $submittedButNotGraded,
    $notSubmitted
];

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        My Performance - <?php echo e($class['class_name']); ?>
    </title>

    <link rel="shortcut icon"
          href="img/plmun logo.png"
          type="image/x-icon">

    <link rel="stylesheet" href="student_dashboard.css">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>

        /* =========================================
           PAGE HEADER
        ========================================= */

        .perf-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .back-btn {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            border: 1px solid #dde4ec;
            background: #fff;

            display: flex;
            align-items: center;
            justify-content: center;

            color: #555;
            font-size: 15px;
            text-decoration: none;
            transition: background 0.2s;
            flex-shrink: 0;
        }

        .back-btn:hover {
            background: #f0f4f8;
            color: #3f87b5;
        }

        .perf-page-title {
            font-size: 22px;
            font-weight: 800;
            color: #1a2d3e;
            margin: 0;
        }

        .perf-page-sub {
            font-size: 13px;
            color: #888;
            margin: 2px 0 0;
        }

        /* =========================================
           LIVE DOT
        ========================================= */

        .live-dot {
            display: inline-flex;
            align-items: center;
            gap: 5px;

            font-size: 11px;
            font-weight: 600;
            color: #28a745;

            background: #d4edda;
            padding: 3px 10px;
            border-radius: 20px;
            margin-left: 8px;
        }

        .live-dot::before {
            content: '';
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #28a745;
            animation: pulse 1.4s infinite;
        }

        @keyframes pulse {
            0%, 100% {
                opacity: 1;
                transform: scale(1);
            }

            50% {
                opacity: .4;
                transform: scale(1.3);
            }
        }

        /* =========================================
           OVERALL PERFORMANCE CARD
        ========================================= */

        .overall-card {
            background: #fff;
            border: 1px solid #e0e8f0;
            border-radius: 16px;
            padding: 28px 32px;

            display: flex;
            align-items: center;
            gap: 32px;

            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        /* =========================================
           BIG PROGRESS RING
        ========================================= */

        .big-ring-wrap {
            position: relative;
            width: 130px;
            height: 130px;
            flex-shrink: 0;
        }

        .big-ring-wrap svg {
            transform: rotate(-90deg);
        }

        .ring-bg {
            fill: none;
            stroke: #eef2f7;
            stroke-width: 8;
        }

        .ring-fill {
            fill: none;
            stroke-width: 8;
            stroke-linecap: round;
            transition: stroke-dashoffset 1.2s ease;
        }

        .ring-label {
            position: absolute;
            inset: 0;

            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .ring-pct-big {
            font-size: 24px;
            font-weight: 900;
            color: #1a2d3e;
            line-height: 1;
        }

        .ring-pct-sub {
            font-size: 11px;
            color: #aaa;
            margin-top: 2px;
        }

        /* =========================================
           OVERALL STATS
        ========================================= */

        .overall-stats {
            flex: 1;
            min-width: 200px;
        }

        .overall-stats h4 {
            font-size: 18px;
            font-weight: 700;
            color: #1a2d3e;
            margin-bottom: 6px;
        }

        .status-badge {
            display: inline-block;
            font-size: 13px;
            font-weight: 700;
            padding: 6px 18px;
            border-radius: 20px;
            margin-bottom: 14px;
        }

        .status-badge.excellent {
            background: #d4edda;
            color: #155724;
        }

        .status-badge.good {
            background: #cce5ff;
            color: #004085;
        }

        .status-badge.average {
            background: #fff3cd;
            color: #856404;
        }

        .status-badge.needs-help {
            background: #f8d7da;
            color: #721c24;
        }

        .status-badge.ungraded {
            background: #f0f0f0;
            color: #888;
        }

        .stat-row {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
        }

        .stat-item {
            text-align: center;
        }

        .stat-item .num {
            font-size: 22px;
            font-weight: 800;
            color: #1a2d3e;
        }

        .stat-item .lbl {
            font-size: 11px;
            color: #888;
            margin-top: 2px;
            text-transform: uppercase;
        }

        /* =========================================
           PERFORMANCE INSIGHTS
        ========================================= */

        .insights-card {
            background: #fff;
            border: 1px solid #e0e8f0;
            border-radius: 12px;
            padding: 22px 24px;
            margin-bottom: 24px;
        }

        .insights-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 5px;
        }

        .insights-header i {
            color: #3f87b5;
            font-size: 20px;
        }

        .insights-title {
            font-size: 16px;
            font-weight: 800;
            color: #1a2d3e;
            margin: 0;
        }

        .insights-subtitle {
            font-size: 12px;
            color: #888;
            margin-bottom: 20px;
        }

        .insights-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .insight-item {
            padding: 17px;
            border-radius: 10px;
            background: #f8fafc;
            border: 1px solid #edf1f5;
            min-width: 0;
        }

        .insight-item h6 {
            font-size: 13px;
            font-weight: 800;
            color: #1a2d3e;
            margin-bottom: 9px;
        }

        .insight-item h6 i {
            color: #3f87b5;
            margin-right: 6px;
        }

        .insight-item p {
            font-size: 13px;
            line-height: 1.7;
            color: #667482;
            margin: 0;
            overflow-wrap: anywhere;
        }

        .insight-item.recommendation {
            background: #f0f7fc;
            border-color: #dcebf6;
        }

        /* =========================================
           CHART CARD
        ========================================= */

        .chart-card {
            background: #fff;
            border: 1px solid #e0e8f0;
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 20px;
            min-width: 0;
        }

        .chart-title {
            font-size: 14px;
            font-weight: 700;
            color: #1a2d3e;
            margin-bottom: 4px;
        }

        .chart-sub {
            font-size: 12px;
            color: #888;
            margin-bottom: 16px;
        }

        .chart-card canvas {
            max-width: 100%;
        }

        /* =========================================
           ASSESSMENT TABLE
        ========================================= */

        .assess-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .assess-table th {
            padding: 10px 14px;
            font-size: 12px;
            font-weight: 700;
            color: #888;
            text-transform: uppercase;
            letter-spacing: .4px;
            border-bottom: 2px solid #f0f0f0;
            text-align: left;
            background: #f8fafc;
            white-space: nowrap;
        }

        .assess-table td {
            padding: 13px 14px;
            font-size: 14px;
            border-bottom: 1px solid #f5f5f5;
            vertical-align: middle;
        }

        .assess-table tr:last-child td {
            border-bottom: none;
        }

        .assess-table tr:hover td {
            background: #f9fbfe;
        }

        .type-badge {
            font-size: 10px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 20px;
            background: #e8f0f8;
            color: #3f87b5;
        }

        .grade-cell {
            font-weight: 700;
            font-size: 15px;
        }

        .grade-cell.high {
            color: #28a745;
        }

        .grade-cell.mid {
            color: #f0a500;
        }

        .grade-cell.low {
            color: #dc3545;
        }

        .grade-cell.none {
            color: #bbb;
        }

        .compare-bar-wrap {
            width: 100px;
            background: #eef2f7;
            border-radius: 10px;
            height: 8px;
            overflow: hidden;
            display: inline-block;
            vertical-align: middle;
        }

        .compare-bar-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 1s ease;
        }

        .vs-avg {
            font-size: 12px;
            font-weight: 600;
        }

        .vs-avg.above {
            color: #28a745;
        }

        .vs-avg.below {
            color: #dc3545;
        }

        .vs-avg.same {
            color: #888;
        }

        /* =========================================
           ROW STATUS
        ========================================= */

        .row-status {
            font-size: 11px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 10px;
            white-space: nowrap;
        }

        .row-status.submitted {
            background: #cce5ff;
            color: #004085;
        }

        .row-status.graded {
            background: #d4edda;
            color: #155724;
        }

        .row-status.missing {
            background: #f8d7da;
            color: #721c24;
        }

        .row-status.no-submit {
            background: #f0f0f0;
            color: #888;
        }

        /* =========================================
           REFRESH BUTTON
        ========================================= */

        .refresh-btn {
            border: 1px solid #dde4ec;
            background: #fff;
            border-radius: 8px;
            padding: 6px 16px;
            font-size: 13px;
            font-weight: 600;
            color: #555;
            cursor: pointer;
            transition: background 0.2s;

            display: flex;
            align-items: center;
            gap: 6px;
        }

        .refresh-btn:hover {
            background: #f0f4f8;
        }

        .refresh-btn.spinning i {
            animation: spin 0.6s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* =========================================
           EMPTY STATE
        ========================================= */

        .perf-empty {
            text-align: center;
            padding: 50px 20px;
            color: #bbb;
            font-size: 14px;
            background: #fff;
            border: 1px solid #e0e8f0;
            border-radius: 12px;
        }

        .perf-empty i {
            font-size: 44px;
            display: block;
            margin-bottom: 12px;
        }

        /* =========================================
           RESPONSIVE
        ========================================= */

        @media (max-width: 992px) {

            .overall-card {
                padding: 24px;
                gap: 24px;
            }

            .chart-card {
                padding: 18px;
            }
        }

        @media (max-width: 768px) {

            .main-content {
                width: 100% !important;
                margin-left: 0 !important;
                min-width: 0;
            }

            .insights-grid {
                grid-template-columns: 1fr;
            }

            .perf-header {
                gap: 10px;
            }

            .perf-page-title {
                font-size: 19px;
            }
        }

        @media (max-width: 576px) {

            .overall-card {
                flex-direction: column;
                align-items: center;
                text-align: center;
                padding: 22px 16px;
            }

            .overall-stats {
                width: 100%;
            }

            .stat-row {
                justify-content: center;
                gap: 18px;
            }

            .insights-card {
                padding: 18px;
            }

            .insight-item {
                padding: 15px;
            }

            .chart-card {
                padding: 16px;
            }

            .compare-bar-wrap {
                display: none;
            }

            .refresh-btn {
                margin-left: 0 !important;
            }

            .perf-header {
                align-items: flex-start;
            }
        }

        @media (max-width: 360px) {

            .perf-page-title {
                font-size: 16px;
            }

            .stat-row {
                gap: 12px;
            }

            .stat-item .num {
                font-size: 19px;
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
            <a
                href="student_performance.php"
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

    <!-- =====================================
         TOPBAR
    ====================================== -->

    <div class="topbar">

        <div class="navbar-left">

            <button
                type="button"
                class="mobile-menu"
                onclick="mobileSidebar()"
                aria-label="Open navigation">

                <i class="fa-solid fa-bars"></i>

            </button>

            <img
                src="img/classroom logo.png"
                class="navbar-logo"
                alt="PLMUN Classroom Logo">

        </div>

        <div class="navbar-right">

            <a
                href="profile.php"
                class="profile-icon"
                title="My Profile">

                <?php if ($hasProfileImage): ?>

                    <img
                        src="uploads/<?php echo e(rawurlencode($profileImage)); ?>"
                        class="profile-image"
                        alt="Profile Picture"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">

                    <span
                        class="profile-fallback"
                        style="display:none;">

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

    <!-- =====================================
         PAGE CONTENT
    ====================================== -->

    <div class="container py-4">

        <!-- PAGE HEADER -->

        <div class="perf-header">

            <a
                href="student_performance.php"
                class="back-btn"
                aria-label="Back to performance">

                <i class="fa-solid fa-arrow-left"></i>

            </a>

            <div>

                <div class="perf-page-title">

                    <?php echo e($class['class_name']); ?>

                    <?php if (!empty($class['section'])): ?>

                        &mdash; <?php echo e($class['section']); ?>

                    <?php endif; ?>

                    <span class="live-dot">LIVE</span>

                </div>

                <div class="perf-page-sub">

                    <i class="fa-solid fa-chalkboard-teacher me-1"></i>

                    <?php echo e($class['teacher_name']); ?>

                </div>

            </div>

            <button
                type="button"
                class="refresh-btn ms-auto"
                id="refreshBtn"
                onclick="refreshData()">

                <i
                    class="fa-solid fa-rotate-right"
                    id="refreshIcon">
                </i>

                Refresh

            </button>

        </div>

        <?php if (empty($assessments)): ?>

            <!-- NO ASSESSMENTS -->

            <div class="perf-empty">

                <i class="fa-solid fa-clipboard-list"></i>

                <p>No assessments posted yet.</p>

            </div>

        <?php else: ?>

            <!-- =====================================
                 OVERALL PERFORMANCE CARD
            ====================================== -->

            <div class="overall-card" id="overallCard">

                <!-- BIG RING -->

                <div class="big-ring-wrap">

                    <svg
                        viewBox="0 0 120 120"
                        width="130"
                        height="130">

                        <circle
                            class="ring-bg"
                            cx="60"
                            cy="60"
                            r="54">
                        </circle>

                        <circle
                            class="ring-fill"
                            id="overallRing"
                            cx="60"
                            cy="60"
                            r="54"
                            stroke="<?php echo e($ringColor); ?>"
                            stroke-dasharray="<?php echo round($bigCirc, 2); ?>"
                            stroke-dashoffset="<?php echo round($bigOffset, 2); ?>">
                        </circle>

                    </svg>

                    <div class="ring-label">

                        <div
                            class="ring-pct-big"
                            id="overallPct">

                            <?php
                            echo $overallPct !== null
                                ? e($overallPct) . '%'
                                : '—';
                            ?>

                        </div>

                        <div class="ring-pct-sub">
                            overall
                        </div>

                    </div>

                </div>

                <!-- OVERALL STATS -->

                <div class="overall-stats">

                    <h4>
                        <?php echo e($user['name']); ?>
                    </h4>

                    <span
                        class="status-badge <?php echo e($statusClass); ?>"
                        id="statusBadge">

                        <?php echo e($status); ?>

                    </span>

                    <div class="stat-row">

                        <div class="stat-item">

                            <div class="num" id="statGraded">
                                <?php echo $graded; ?>
                            </div>

                            <div class="lbl">
                                Graded
                            </div>

                        </div>

                        <div class="stat-item">

                            <div class="num" id="statSubmitted">
                                <?php echo $submitted; ?>
                            </div>

                            <div class="lbl">
                                Submitted
                            </div>

                        </div>

                        <div class="stat-item">

                            <div class="num">
                                <?php echo $totalAssessments; ?>
                            </div>

                            <div class="lbl">
                                Total
                            </div>

                        </div>

                        <div class="stat-item">

                            <div class="num" id="statPending">
                                <?php echo $totalAssessments - $submitted; ?>
                            </div>

                            <div class="lbl">
                                Pending
                            </div>

                        </div>

                    </div>

                </div>

            </div>

            <!-- =====================================
                 PERFORMANCE INSIGHTS
            ====================================== -->

            <div class="insights-card">

                <div class="insights-header">

                    <i class="fa-solid fa-lightbulb"></i>

                    <h5 class="insights-title">
                        Performance Insights and Recommendations
                    </h5>

                </div>

                <p class="insights-subtitle">
                    An automatic analysis of your assessment results,
                    academic performance, and learning progress.
                </p>

                <div
                    class="insights-grid"
                    id="performanceInsights">

                    <!-- Generated automatically by JavaScript -->

                </div>

            </div>

            <!-- =====================================
                 CHARTS
            ====================================== -->

            <div class="row g-4">

                <!-- LINE CHART -->

                <div class="col-lg-8">

                    <div class="chart-card">

                        <div class="chart-title">
                            My Score vs Class Average
                        </div>

                        <div class="chart-sub">
                            Score percentage (%) per assessment
                        </div>

                        <canvas
                            id="lineChart"
                            height="180">
                        </canvas>

                    </div>

                </div>

                <!-- DONUT CHART -->

                <div class="col-lg-4">

                    <div class="chart-card">

                        <div class="chart-title">
                            Submission Status
                        </div>

                        <div class="chart-sub">
                            Breakdown of your work
                        </div>

                        <canvas
                            id="donutChart"
                            height="180">
                        </canvas>

                    </div>

                </div>

                <!-- BAR CHART -->

                <div class="col-12">

                    <div class="chart-card">

                        <div class="chart-title">
                            My Score per Assessment
                        </div>

                        <div class="chart-sub">
                            Your individual score (%) per assessment
                        </div>

                        <canvas
                            id="barChart"
                            height="90">
                        </canvas>

                    </div>

                </div>

            </div>

            <!-- =====================================
                 ASSESSMENT BREAKDOWN TABLE
            ====================================== -->

            <div class="chart-card mt-2">

                <div class="chart-title mb-1">
                    Assessment Breakdown
                </div>

                <div class="chart-sub">
                    Your grade, class average, and status per assessment
                </div>

                <div style="overflow-x:auto;">

                    <table
                        class="assess-table"
                        id="assessTable">

                        <thead>

                            <tr>

                                <th>Assessment</th>

                                <th>Type</th>

                                <th>My Score</th>

                                <th>Class Avg</th>

                                <th>vs Average</th>

                                <th>Progress</th>

                                <th>Status</th>

                            </tr>

                        </thead>

                        <tbody id="assessTableBody">

                        <?php foreach ($assessments as $a): ?>

                            <?php

                            $assessmentId = (int)$a['id'];

                            $points = (float)$a['points'];

                            $g = $myGrades[$assessmentId] ?? null;

                            $myG = (
                                $g &&
                                $g['grade'] !== null
                            )
                                ? (float)$g['grade']
                                : null;

                            $myP = (
                                $myG !== null &&
                                $points > 0
                            )
                                ? round(($myG / $points) * 100, 1)
                                : null;

                            $avgP = (
                                isset($classAvg[$assessmentId]) &&
                                $points > 0
                            )
                                ? round(
                                    ($classAvg[$assessmentId] / $points) * 100,
                                    1
                                )
                                : null;

                            /* GRADE COLOR */

                            $gradeCls = $myP === null
                                ? 'none'
                                : (
                                    $myP >= 75
                                        ? 'high'
                                        : (
                                            $myP >= 60
                                                ? 'mid'
                                                : 'low'
                                        )
                                );

                            /* PROGRESS COLOR */

                            $barColor = $myP === null
                                ? '#eef2f7'
                                : (
                                    $myP >= 75
                                        ? '#28a745'
                                        : (
                                            $myP >= 60
                                                ? '#f0a500'
                                                : '#dc3545'
                                        )
                                );

                            /* CLASS AVERAGE DIFFERENCE */

                            $diff = (
                                $myP !== null &&
                                $avgP !== null
                            )
                                ? round($myP - $avgP, 1)
                                : null;

                            $vsClass = $diff === null
                                ? 'same'
                                : (
                                    $diff > 0
                                        ? 'above'
                                        : (
                                            $diff < 0
                                                ? 'below'
                                                : 'same'
                                        )
                                );

                            $vsText = $diff === null
                                ? '—'
                                : (
                                    $diff > 0
                                        ? '+' . $diff . '%'
                                        : $diff . '%'
                                );

                            /* SUBMISSION STATUS */

                            $rowStatus = 'no-submit';
                            $rowStatusText = 'Not submitted';

                            if ($g) {

                                if ($myG !== null) {

                                    $rowStatus = 'graded';
                                    $rowStatusText = 'Graded';

                                } elseif (
                                    in_array(
                                        $g['status'],
                                        ['submitted', 'done'],
                                        true
                                    )
                                ) {

                                    $rowStatus = 'submitted';
                                    $rowStatusText = 'Submitted';

                                } elseif ($g['status'] === 'missing') {

                                    $rowStatus = 'missing';
                                    $rowStatusText = 'Missing';
                                }
                            }

                            ?>

                            <tr>

                                <!-- ASSESSMENT -->

                                <td>

                                    <strong>
                                        <?php echo e($a['title']); ?>
                                    </strong>

                                    <div style="font-size:11px; color:#aaa;">

                                        Due

                                        <?php
                                        echo !empty($a['due_date'])
                                            ? e(date(
                                                'M d, Y',
                                                strtotime($a['due_date'])
                                            ))
                                            : 'Not specified';
                                        ?>

                                    </div>

                                </td>

                                <!-- TYPE -->

                                <td>

                                    <span class="type-badge">
                                        <?php echo e($a['type']); ?>
                                    </span>

                                </td>

                                <!-- STUDENT SCORE -->

                                <td>

                                    <span class="grade-cell <?php echo e($gradeCls); ?>">

                                        <?php
                                        echo $myG !== null
                                            ? e($myG) . '/' . e($a['points'])
                                            : '—';
                                        ?>

                                    </span>

                                    <?php if ($myP !== null): ?>

                                        <div style="font-size:11px; color:#aaa;">

                                            <?php echo e($myP); ?>%

                                        </div>

                                    <?php endif; ?>

                                </td>

                                <!-- CLASS AVERAGE -->

                                <td style="color:#888; font-size:13px;">

                                    <?php
                                    echo $avgP !== null
                                        ? e($avgP) . '%'
                                        : '—';
                                    ?>

                                </td>

                                <!-- COMPARISON -->

                                <td>

                                    <span class="vs-avg <?php echo e($vsClass); ?>">

                                        <?php echo e($vsText); ?>

                                    </span>

                                </td>

                                <!-- PROGRESS BAR -->

                                <td>

                                    <div class="compare-bar-wrap">

                                        <div
                                            class="compare-bar-fill"
                                            style="width:0; background:<?php echo e($barColor); ?>"
                                            data-width="<?php echo e($myP ?? 0); ?>">

                                        </div>

                                    </div>

                                </td>

                                <!-- STATUS -->

                                <td>

                                    <span class="row-status <?php echo e($rowStatus); ?>">

                                        <?php echo e($rowStatusText); ?>

                                    </span>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        <?php endif; ?>

    </div>

</div>

<!-- =========================================
     JAVASCRIPT LIBRARIES
========================================= -->

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

/* =========================================
   PHP DATA TO JAVASCRIPT
========================================= */

const CLASS_ID = <?php echo json_encode($class_id); ?>;

const STUDENT_ID = <?php echo json_encode($student_id); ?>;

const lineLabels = <?php echo json_encode(
    $chartLabels,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
); ?>;

const myLineData = <?php echo json_encode(
    $myLineData,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
); ?>;

const avgLineData = <?php echo json_encode(
    $avgLineData,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
); ?>;

const barData = [...myLineData];

const initialOverallPct = <?php echo json_encode($overallPct); ?>;

const totalAssessments = <?php echo json_encode($totalAssessments); ?>;

const initialGraded = <?php echo json_encode($graded); ?>;

const initialSubmitted = <?php echo json_encode($submitted); ?>;

const donutData = <?php echo json_encode($donutData); ?>;

const donutLabels = [
    'Graded',
    'Submitted',
    'Not Submitted'
];

const donutColors = [
    '#28a745',
    '#3f87b5',
    '#dee2e6'
];

/* =========================================
   HTML ESCAPING
========================================= */

function escapeHTML(value) {

    return String(value ?? '').replace(/[&<>"']/g, function(char) {

        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[char];

    });

}

/* =========================================
   PERFORMANCE INSIGHT GENERATOR
========================================= */

function renderPerformanceInsights(
    overall,
    scores,
    labels,
    averages
) {

    const container = document.getElementById('performanceInsights');

    if (!container) return;

    /* =====================================
       PREPARE GRADED ASSESSMENTS
    ===================================== */

    const gradedResults = scores

        .map((score, index) => ({

            title: labels[index],

            score: score,

            average: averages[index]

        }))

        .filter(item =>

            item.score !== null &&
            item.score !== undefined &&
            Number.isFinite(Number(item.score))

        )

        .map(item => ({

            ...item,

            score: Number(item.score)

        }));

    let overallDescription = '';

    let recommendation = '';

    /* =====================================
       1. OVERALL PERFORMANCE
    ===================================== */

    if (
        overall === null ||
        overall === undefined ||
        !Number.isFinite(Number(overall))
    ) {

        overallDescription =
            'You do not have any graded assessments yet. ' +
            'Your overall academic performance will become available ' +
            'once your instructor evaluates your submitted assessments.';

        recommendation =
            'Complete and submit your assessments to receive grades. ' +
            'Continue reviewing your learning materials while waiting for evaluation.';

    } else {

        overall = Number(overall);

        if (overall >= 90) {

            overallDescription =
                'Excellent performance! Your overall score of ' +
                overall.toFixed(1) +
                '% demonstrates a strong level of achievement in your assessments. ' +
                'Continue maintaining your excellent results.';

            recommendation =
                'Keep up your excellent performance. Continue participating ' +
                'in class activities, reviewing your lessons, and challenging ' +
                'yourself with more advanced learning activities.';

        } else if (overall >= 85) {

            overallDescription =
                'Very good performance! You currently have an overall score of ' +
                overall.toFixed(1) +
                '%. Your assessment results demonstrate good academic progress. ' +
                'Continue strengthening your understanding of the lessons.';

            recommendation =
                'Maintain your study habits and continue reviewing your learning ' +
                'materials. Focus on small improvements that can help you achieve ' +
                'an excellent performance level.';

        } else if (overall >= 75) {

            overallDescription =
                'Good performance! You currently have an overall score of ' +
                overall.toFixed(1) +
                '%. Your results indicate that you are meeting the expected ' +
                'learning outcomes. Continue improving your knowledge and skills.';

            recommendation =
                'Maintain your study habits and review topics where you received ' +
                'lower scores. Consistent preparation can help you achieve higher results.';

        } else if (overall >= 60) {

            overallDescription =
                'Your current overall score is ' +
                overall.toFixed(1) +
                '%. Your results suggest that some learning competencies ' +
                'need additional attention. Improving your study routine ' +
                'may help increase your assessment performance.';

            recommendation =
                'Spend additional time reviewing your lessons and learning materials. ' +
                'Focus on topics you find difficult and consider asking your instructor ' +
                'for clarification or additional guidance.';

        } else {

            overallDescription =
                'Your current overall score is ' +
                overall.toFixed(1) +
                '%. Your assessment results indicate that you may be experiencing ' +
                'difficulty understanding some of the lessons. Additional study ' +
                'and instructor guidance are recommended.';

            recommendation =
                'Prioritize reviewing your lessons and understanding the topics ' +
                'covered in your assessments. Seek assistance from your instructor ' +
                'and work on improving your performance in upcoming assessments.';
        }

    }

    /* =====================================
       2. GRAPH ANALYSIS
    ===================================== */

    let graphDescription = '';

    if (gradedResults.length === 0) {

        graphDescription =
            'There are currently no graded assessment results available ' +
            'to determine your performance trend. Your graph will provide ' +
            'a clearer analysis once your assessments have been graded.';

    } else if (gradedResults.length === 1) {

        graphDescription =
            'Only one assessment has been graded. Your current score is ' +
            gradedResults[0].score.toFixed(1) +
            '%. More graded assessments are needed to identify whether ' +
            'your performance is improving, declining, or remaining consistent.';

    } else {

        const midpoint = Math.ceil(gradedResults.length / 2);

        const firstHalf = gradedResults.slice(0, midpoint);

        const secondHalf = gradedResults.slice(midpoint);

        const firstAverage =
            firstHalf.reduce(
                (sum, item) => sum + item.score,
                0
            ) / firstHalf.length;

        const secondAverage =
            secondHalf.reduce(
                (sum, item) => sum + item.score,
                0
            ) / secondHalf.length;

        const difference = secondAverage - firstAverage;

        if (difference > 3) {

            graphDescription =
                'Your assessment graph shows an improving performance trend. ' +
                'Your average score in the more recent assessments is ' +
                difference.toFixed(1) +
                ' percentage points higher than in your earlier assessments. ' +
                'This suggests progress in your learning performance.';

        } else if (difference < -3) {

            graphDescription =
                'Your assessment graph shows a declining performance trend. ' +
                'Your average score in the more recent assessments is ' +
                Math.abs(difference).toFixed(1) +
                ' percentage points lower than in your earlier assessments. ' +
                'Consider reviewing the lessons covered in your recent assessments.';

        } else {

            graphDescription =
                'Your assessment results show a relatively consistent performance. ' +
                'There is no significant difference between your earlier and ' +
                'more recent assessment averages. Continue maintaining your progress.';
        }

    }

    /* =====================================
       3. STRENGTHS
    ===================================== */

    let strengthsDescription = '';

    let improvementDescription = '';

    if (gradedResults.length === 0) {

        strengthsDescription =
            'Your strengths cannot be identified yet because no assessments have been graded.';

        improvementDescription =
            'There are no graded results available to identify specific assessments that need improvement.';

    } else {

        const highest = [...gradedResults].sort(
            (a, b) => b.score - a.score
        )[0];

        const lowest = [...gradedResults].sort(
            (a, b) => a.score - b.score
        )[0];

        strengthsDescription =
            'Your highest assessment result is "' +
            escapeHTML(highest.title) +
            '" with a score of ' +
            highest.score.toFixed(1) +
            '%. This indicates an assessment where you demonstrated stronger performance.';

        if (gradedResults.length === 1) {

            improvementDescription =
                'Only one assessment has been graded. Additional assessment results ' +
                'are needed to identify specific areas where your performance can improve.';

        } else {

            improvementDescription =
                'Your lowest assessment result is "' +
                escapeHTML(lowest.title) +
                '" with a score of ' +
                lowest.score.toFixed(1) +
                '%. Consider reviewing the lessons and concepts covered in this assessment.';

        }

    }

    /* =====================================
       4. CLASS AVERAGE COMPARISON
    ===================================== */

    const comparisons = gradedResults.filter(item =>

        item.average !== null &&
        item.average !== undefined &&
        Number.isFinite(Number(item.average))

    );

    let comparisonDescription = '';

    if (comparisons.length > 0) {

        const myAverage =

            comparisons.reduce(
                (sum, item) => sum + item.score,
                0
            ) / comparisons.length;

        const classAverage =

            comparisons.reduce(
                (sum, item) => sum + Number(item.average),
                0
            ) / comparisons.length;

        const difference = myAverage - classAverage;

        if (difference > 1) {

            comparisonDescription =
                'Across assessments with available class averages, your average ' +
                'score is ' +
                difference.toFixed(1) +
                ' percentage points higher than the class average.';

        } else if (difference < -1) {

            comparisonDescription =
                'Across assessments with available class averages, your average ' +
                'score is ' +
                Math.abs(difference).toFixed(1) +
                ' percentage points below the class average. Consider reviewing ' +
                'the topics where you received lower scores.';

        } else {

            comparisonDescription =
                'Your average score across assessments with available class averages ' +
                'is approximately equal to the class average.';

        }

    } else {

        comparisonDescription =
            'Class comparison information is not yet available for your graded assessments.';

    }

    /* =====================================
       5. PENDING ASSESSMENTS
    ===================================== */

    const ungradedCount = Math.max(
        0,
        totalAssessments - gradedResults.length
    );

    if (ungradedCount > 0) {

        recommendation +=
            ' You also have ' +
            ungradedCount +
            ' assessment(s) without a recorded grade. Check your assessment list ' +
            'for pending submissions or evaluations.';

    }

    /* =====================================
       6. DISPLAY PERFORMANCE INSIGHTS
    ===================================== */

    container.innerHTML = `

        <div class="insight-item">

            <h6>
                <i class="fa-solid fa-chart-line"></i>
                Overall Performance
            </h6>

            <p>${overallDescription}</p>

        </div>

        <div class="insight-item">

            <h6>
                <i class="fa-solid fa-arrow-trend-up"></i>
                Graph Analysis
            </h6>

            <p>${graphDescription}</p>

        </div>

        <div class="insight-item">

            <h6>
                <i class="fa-solid fa-star"></i>
                Strengths
            </h6>

            <p>${strengthsDescription}</p>

        </div>

        <div class="insight-item">

            <h6>
                <i class="fa-solid fa-magnifying-glass-chart"></i>
                Areas for Improvement
            </h6>

            <p>${improvementDescription}</p>

        </div>

        <div class="insight-item">

            <h6>
                <i class="fa-solid fa-users"></i>
                Class Performance Comparison
            </h6>

            <p>${comparisonDescription}</p>

        </div>

        <div class="insight-item recommendation">

            <h6>
                <i class="fa-solid fa-lightbulb"></i>
                Learning Recommendations
            </h6>

            <p>${recommendation}</p>

        </div>

    `;

}

/* =========================================
   INITIALIZE PERFORMANCE INSIGHTS
========================================= */

renderPerformanceInsights(
    initialOverallPct,
    myLineData,
    lineLabels,
    avgLineData
);

/* =========================================
   CHART CONFIGURATION
========================================= */

/* =====================================
   1. LINE CHART
   MY SCORE VS CLASS AVERAGE
===================================== */

const lineCtx = document
    .getElementById('lineChart')
    .getContext('2d');

const lineChart = new Chart(lineCtx, {

    type: 'line',

    data: {

        labels: lineLabels,

        datasets: [

            {

                label: 'My Score',

                data: myLineData,

                borderColor: '#3f87b5',

                backgroundColor: '#3f87b520',

                tension: 0.4,

                pointRadius: 6,

                pointHoverRadius: 9,

                borderWidth: 2.5,

                spanGaps: true,

                fill: true,

            },

            {

                label: 'Class Average',

                data: avgLineData,

                borderColor: '#adb5bd',

                backgroundColor: 'transparent',

                tension: 0.4,

                pointRadius: 4,

                borderWidth: 1.5,

                borderDash: [6, 4],

                spanGaps: true,

            }

        ]

    },

    options: {

        responsive: true,

        maintainAspectRatio: true,

        animation: {
            duration: 800
        },

        interaction: {
            mode: 'index',
            intersect: false
        },

        plugins: {

            legend: {

                position: 'bottom',

                labels: {
                    boxWidth: 12,
                    font: {
                        size: 11
                    }
                }

            },

            tooltip: {

                callbacks: {

                    label: ctx =>

                        ` ${ctx.dataset.label}: ${
                            ctx.parsed.y !== null
                                ? ctx.parsed.y + '%'
                                : 'N/A'
                        }`

                }

            }

        },

        scales: {

            y: {

                min: 0,

                max: 100,

                ticks: {

                    callback: v => v + '%',

                    font: {
                        size: 11
                    }

                },

                grid: {
                    color: '#f0f0f0'
                }

            },

            x: {

                ticks: {
                    font: {
                        size: 11
                    }
                },

                grid: {
                    display: false
                }

            }

        }

    }

});

/* =====================================
   2. BAR CHART
   SCORE PER ASSESSMENT
===================================== */

const barCtx = document
    .getElementById('barChart')
    .getContext('2d');

const barChart = new Chart(barCtx, {

    type: 'bar',

    data: {

        labels: lineLabels,

        datasets: [

            {

                label: 'My Score (%)',

                data: barData,

                backgroundColor: barData.map(v =>

                    v === null
                        ? '#dee2e6'
                        : (
                            v >= 75
                                ? '#28a74588'
                                : (
                                    v >= 60
                                        ? '#f0a50088'
                                        : '#dc354588'
                                )
                        )

                ),

                borderColor: barData.map(v =>

                    v === null
                        ? '#dee2e6'
                        : (
                            v >= 75
                                ? '#28a745'
                                : (
                                    v >= 60
                                        ? '#f0a500'
                                        : '#dc3545'
                                )
                        )

                ),

                borderWidth: 2,

                borderRadius: 6,

            }

        ]

    },

    options: {

        responsive: true,

        animation: {
            duration: 800
        },

        plugins: {

            legend: {
                display: false
            },

            tooltip: {

                callbacks: {

                    label: ctx =>

                        ` My score: ${
                            ctx.parsed.y !== null
                                ? ctx.parsed.y + '%'
                                : 'N/A'
                        }`

                }

            }

        },

        scales: {

            y: {

                min: 0,

                max: 100,

                ticks: {

                    callback: v => v + '%',

                    font: {
                        size: 11
                    }

                },

                grid: {
                    color: '#f0f0f0'
                }

            },

            x: {

                ticks: {
                    font: {
                        size: 11
                    }
                },

                grid: {
                    display: false
                }

            }

        }

    }

});

/* =====================================
   3. DONUT CHART
   SUBMISSION STATUS
===================================== */

const donutCtx = document
    .getElementById('donutChart')
    .getContext('2d');

const donutChart = new Chart(donutCtx, {

    type: 'doughnut',

    data: {

        labels: donutLabels,

        datasets: [

            {

                data: donutData,

                backgroundColor: donutColors,

                borderWidth: 2,

                borderColor: '#fff',

                hoverOffset: 8,

            }

        ]

    },

    options: {

        responsive: true,

        animation: {

            animateRotate: true,

            duration: 900

        },

        plugins: {

            legend: {

                position: 'bottom',

                labels: {

                    boxWidth: 12,

                    font: {
                        size: 11
                    },

                    padding: 10

                }

            },

            tooltip: {

                callbacks: {

                    label: ctx =>
                        ` ${ctx.label}: ${ctx.parsed}`

                }

            }

        },

        cutout: '62%',

    }

});

/* =========================================
   ANIMATE PROGRESS BARS
========================================= */

function animateBars() {

    document.querySelectorAll('.compare-bar-fill').forEach(bar => {

        const width = Math.min(
            100,
            Math.max(0, Number(bar.dataset.width) || 0)
        );

        setTimeout(() => {

            bar.style.width = width + '%';

        }, 100);

    });

}

document.addEventListener(
    'DOMContentLoaded',
    animateBars
);

/* =========================================
   UPDATE OVERALL RING
========================================= */

function updateOverallRing(percentage) {

    const ring = document.getElementById('overallRing');

    if (!ring) return;

    const circumference = 2 * Math.PI * 54;

    let color = '#adb5bd';

    let offset = circumference;

    if (
        percentage !== null &&
        percentage !== undefined &&
        Number.isFinite(Number(percentage))
    ) {

        percentage = Number(percentage);

        offset = circumference -
            (percentage / 100) * circumference;

        if (percentage >= 90) {

            color = '#28a745';

        } else if (percentage >= 75) {

            color = '#3f87b5';

        } else if (percentage >= 60) {

            color = '#f0a500';

        } else {

            color = '#dc3545';

        }

    }

    ring.style.stroke = color;

    ring.style.strokeDasharray = circumference;

    ring.style.strokeDashoffset = offset;

}

/* =========================================
   AUTOMATIC LIVE REFRESH
========================================= */

let refreshInProgress = false;

async function refreshData() {

    if (refreshInProgress) return;

    refreshInProgress = true;

    const btn = document.getElementById('refreshBtn');

    const icon = document.getElementById('refreshIcon');

    btn.classList.add('spinning');

    try {

        const response = await fetch(

            `student_performance_data.php?class_id=${encodeURIComponent(CLASS_ID)}&student_id=${encodeURIComponent(STUDENT_ID)}&t=${Date.now()}`,

            {
                method: 'GET',
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json'
                }
            }

        );

        if (!response.ok) {
            throw new Error('Unable to retrieve updated performance data.');
        }

        const d = await response.json();

        if (!d.success) {

            console.error(
                'Performance refresh failed:',
                d.message || 'Unknown error'
            );

            return;
        }

        /* =====================================
           UPDATE OVERALL PERFORMANCE
        ===================================== */

        document.getElementById('overallPct').textContent =

            d.overallPct !== null
                ? Number(d.overallPct).toFixed(1) + '%'
                : '—';

        document.getElementById('statusBadge').textContent =
            d.status;

        document.getElementById('statusBadge').className =
            'status-badge ' + d.statusClass;

        document.getElementById('statGraded').textContent =
            d.graded;

        document.getElementById('statSubmitted').textContent =
            d.submitted;

        if (d.pending !== undefined) {

            document.getElementById('statPending').textContent =
                d.pending;

        }

        /* UPDATE RING */

        updateOverallRing(d.overallPct);

        /* =====================================
           UPDATE LINE CHART
        ===================================== */

        lineChart.data.datasets[0].data =
            d.myLineData;

        lineChart.data.datasets[1].data =
            d.avgLineData;

        lineChart.update('active');

        /* =====================================
           UPDATE BAR CHART
        ===================================== */

        barChart.data.datasets[0].data =
            d.myLineData;

        barChart.data.datasets[0].backgroundColor =
            d.myLineData.map(v =>

                v === null
                    ? '#dee2e6'
                    : (
                        v >= 75
                            ? '#28a74588'
                            : (
                                v >= 60
                                    ? '#f0a50088'
                                    : '#dc354588'
                            )
                    )

            );

        barChart.data.datasets[0].borderColor =
            d.myLineData.map(v =>

                v === null
                    ? '#dee2e6'
                    : (
                        v >= 75
                            ? '#28a745'
                            : (
                                v >= 60
                                    ? '#f0a500'
                                    : '#dc3545'
                            )
                    )

            );

        barChart.update('active');

        /* =====================================
           UPDATE DONUT CHART
        ===================================== */

        donutChart.data.datasets[0].data =
            d.donutData;

        donutChart.update('active');

        /* =====================================
           UPDATE PERFORMANCE INSIGHTS
        ===================================== */

        renderPerformanceInsights(

            d.overallPct,

            d.myLineData,

            lineLabels,

            d.avgLineData

        );

        /* =====================================
           UPDATE ASSESSMENT TABLE
        ===================================== */

        if (typeof d.tableHtml === 'string') {

            document.getElementById('assessTableBody')
                .innerHTML = d.tableHtml;

            animateBars();

        }

    } catch (error) {

        console.error(
            'Performance refresh error:',
            error
        );

    } finally {

        btn.classList.remove('spinning');

        refreshInProgress = false;

    }

}

/* =========================================
   AUTOMATIC REFRESH EVERY 30 SECONDS
========================================= */

setInterval(refreshData, 30000);

/* =========================================
   SIDEBAR FUNCTIONS
========================================= */

function toggleSidebar() {

    if (window.innerWidth <= 768) {

        mobileSidebar();

        return;

    }

    document
        .getElementById('sidebar')
        .classList.toggle('collapsed');

    document
        .getElementById('mainContent')
        .classList.toggle('expanded');

}

function mobileSidebar() {

    document
        .getElementById('sidebar')
        .classList.toggle('show');

}

/* =========================================
   CLOSE MOBILE SIDEBAR WHEN LINK IS CLICKED
========================================= */

document.querySelectorAll('.sidebar a').forEach(link => {

    link.addEventListener('click', function() {

        if (window.innerWidth <= 768) {

            document
                .getElementById('sidebar')
                .classList.remove('show');

        }

    });

});

/* =========================================
   ESCAPE KEY
========================================= */

document.addEventListener('keydown', function(event) {

    if (event.key === 'Escape') {

        document
            .getElementById('sidebar')
            .classList.remove('show');

    }

});

/* =========================================
   WINDOW RESIZE
========================================= */

window.addEventListener('resize', function() {

    if (window.innerWidth > 768) {

        document
            .getElementById('sidebar')
            .classList.remove('show');

    }

});

</script>

</body>
</html>
