
<?php
session_start();
include 'database.php';

/* =========================================================
   ADMIN ACCESS CONTROL
========================================================= */

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.html");
    exit();
}

$admin_id = (int) $_SESSION['user_id'];

$adminQuery = mysqli_query(
    $conn,
    "SELECT * FROM users WHERE id = $admin_id LIMIT 1"
);

if (!$adminQuery || mysqli_num_rows($adminQuery) === 0) {
    session_destroy();
    header("Location: login.html");
    exit();
}

$admin = mysqli_fetch_assoc($adminQuery);

/* =========================================================
   HELPER FUNCTIONS
========================================================= */

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function getRows($conn, $sql) {
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        error_log("Reports SQL error: " . mysqli_error($conn));
        return [];
    }

    $rows = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }

    return $rows;
}

function getNumber($conn, $sql) {
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        error_log("Reports SQL error: " . mysqli_error($conn));
        return 0;
    }

    $row = mysqli_fetch_assoc($result);

    return (float)($row['total'] ?? 0);
}

/* =========================================================
   REPORT FILTERS
========================================================= */

$selectedProgram = trim($_GET['program'] ?? '');
$selectedYear = trim($_GET['academic_year'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}

if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    $dateTo = '';
}

$programSQL = mysqli_real_escape_string($conn, $selectedProgram);
$yearSQL = mysqli_real_escape_string($conn, $selectedYear);
$fromSQL = mysqli_real_escape_string($conn, $dateFrom);
$toSQL = mysqli_real_escape_string($conn, $dateTo);

/* =========================================================
   BUILD FILTER CONDITIONS
========================================================= */

// User filters
$userWhere = " WHERE 1=1 ";

if ($selectedProgram !== '') {
    $userWhere .= " AND program = '$programSQL' ";
}

if ($dateFrom !== '') {
    $userWhere .= " AND DATE(created_at) >= '$fromSQL' ";
}

if ($dateTo !== '') {
    $userWhere .= " AND DATE(created_at) <= '$toSQL' ";
}

// Class filters
$classWhere = " WHERE 1=1 ";

if ($selectedProgram !== '') {
    $classWhere .= " AND c.program = '$programSQL' ";
}

if ($selectedYear !== '') {
    $classWhere .= " AND c.academic_year = '$yearSQL' ";
}

if ($dateFrom !== '') {
    $classWhere .= " AND DATE(c.created_at) >= '$fromSQL' ";
}

if ($dateTo !== '') {
    $classWhere .= " AND DATE(c.created_at) <= '$toSQL' ";
}

// Enrollment filters
$enrollmentWhere = " WHERE cs.archived = 0 ";

if ($selectedProgram !== '') {
    $enrollmentWhere .= " AND c.program = '$programSQL' ";
}

if ($selectedYear !== '') {
    $enrollmentWhere .= " AND c.academic_year = '$yearSQL' ";
}

if ($dateFrom !== '') {
    $enrollmentWhere .= " AND DATE(cs.joined_at) >= '$fromSQL' ";
}

if ($dateTo !== '') {
    $enrollmentWhere .= " AND DATE(cs.joined_at) <= '$toSQL' ";
}

// Assessment filters
$assessmentWhere = " WHERE 1=1 ";

if ($selectedProgram !== '') {
    $assessmentWhere .= " AND c.program = '$programSQL' ";
}

if ($selectedYear !== '') {
    $assessmentWhere .= " AND c.academic_year = '$yearSQL' ";
}

if ($dateFrom !== '') {
    $assessmentWhere .= " AND DATE(a.created_at) >= '$fromSQL' ";
}

if ($dateTo !== '') {
    $assessmentWhere .= " AND DATE(a.created_at) <= '$toSQL' ";
}

/* =========================================================
   FILTER OPTIONS
========================================================= */

$programs = getRows(
    $conn,
    "SELECT DISTINCT program_name
     FROM programs
     ORDER BY program_name ASC"
);

$academicYears = getRows(
    $conn,
    "SELECT DISTINCT academic_year
     FROM classes
     WHERE academic_year IS NOT NULL
       AND academic_year != ''
     ORDER BY academic_year DESC"
);

/* =========================================================
   SUMMARY: TOTAL STUDENTS
========================================================= */

$totalStudents = getNumber(
    $conn,
    "SELECT COUNT(*) AS total
     FROM users
     $userWhere
     AND role = 'student'"
);

/* =========================================================
   SUMMARY: TOTAL TEACHERS
========================================================= */

$totalTeachers = getNumber(
    $conn,
    "SELECT COUNT(*) AS total
     FROM users
     $userWhere
     AND role = 'teacher'"
);

/* =========================================================
   SUMMARY: TOTAL CLASSES
========================================================= */

$totalClasses = getNumber(
    $conn,
    "SELECT COUNT(*) AS total
     FROM classes c
     $classWhere"
);

/* =========================================================
   SUMMARY: ACTIVE ENROLLMENTS
========================================================= */

$activeEnrollments = getNumber(
    $conn,
    "SELECT COUNT(DISTINCT cs.id) AS total
     FROM class_students cs
     INNER JOIN classes c ON c.id = cs.class_id
     $enrollmentWhere"
);

/* =========================================================
   SUMMARY: ASSESSMENT COMPLETION RATE

   Expected submissions:
   Number of assessments multiplied by active students
   enrolled in each assessment's class.

   Completed submissions:
   Status = submitted or done.
========================================================= */

$expectedSubmissions = getNumber(
    $conn,
    "SELECT COALESCE(SUM(enrolled.student_count), 0) AS total
     FROM assessments a
     INNER JOIN classes c ON c.id = a.class_id
     INNER JOIN (
         SELECT class_id,
                COUNT(DISTINCT student_id) AS student_count
         FROM class_students
         WHERE archived = 0
         GROUP BY class_id
     ) enrolled ON enrolled.class_id = c.id
     $assessmentWhere"
);

$completedSubmissions = getNumber(
    $conn,
    "SELECT COUNT(DISTINCT CONCAT(s.assessment_id, '-', s.student_id)) AS total
     FROM submissions s
     INNER JOIN assessments a ON a.id = s.assessment_id
     INNER JOIN classes c ON c.id = a.class_id
     $assessmentWhere
     AND s.status IN ('submitted', 'done')"
);

$completionRate = $expectedSubmissions > 0
    ? ($completedSubmissions / $expectedSubmissions) * 100
    : 0;

$completionRate = min(100, $completionRate);

/* =========================================================
   SUMMARY: AVERAGE STUDENT PERFORMANCE

   Grade is converted into a percentage using assessment
   points as the maximum score.
========================================================= */

$averagePerformance = getNumber(
    $conn,
    "SELECT COALESCE(
         AVG((s.grade / NULLIF(a.points, 0)) * 100),
         0
     ) AS total
     FROM submissions s
     INNER JOIN assessments a ON a.id = s.assessment_id
     INNER JOIN classes c ON c.id = a.class_id
     $assessmentWhere
     AND s.grade IS NOT NULL
     AND a.points > 0"
);

/* =========================================================
   CHART 1: STUDENT ENROLLMENT TRENDS
========================================================= */

$enrollmentTrend = getRows(
    $conn,
    "SELECT
         DATE_FORMAT(cs.joined_at, '%Y-%m') AS month_key,
         DATE_FORMAT(cs.joined_at, '%b %Y') AS month_label,
         COUNT(DISTINCT cs.id) AS total
     FROM class_students cs
     INNER JOIN classes c ON c.id = cs.class_id
     $enrollmentWhere
     GROUP BY DATE_FORMAT(cs.joined_at, '%Y-%m')
     ORDER BY month_key ASC"
);

/* =========================================================
   CHART 2: STUDENT DISTRIBUTION BY PROGRAM
========================================================= */

$studentDistribution = getRows(
    $conn,
    "SELECT
         COALESCE(NULLIF(program, ''), 'Unassigned') AS program_name,
         COUNT(*) AS total
     FROM users
     $userWhere
     AND role = 'student'
     GROUP BY program_name
     ORDER BY total DESC"
);

/* =========================================================
   CHART 3: CLASSES PER PROGRAM
========================================================= */

$classesPerProgram = getRows(
    $conn,
    "SELECT
         COALESCE(NULLIF(c.program, ''), 'Unassigned') AS program_name,
         COUNT(*) AS total
     FROM classes c
     $classWhere
     GROUP BY program_name
     ORDER BY total DESC"
);

/* =========================================================
   CHART 4: ASSESSMENT SUBMISSION STATISTICS
========================================================= */

$submissionStatistics = getRows(
    $conn,
    "SELECT
         s.status,
         COUNT(*) AS total
     FROM submissions s
     INNER JOIN assessments a ON a.id = s.assessment_id
     INNER JOIN classes c ON c.id = a.class_id
     $assessmentWhere
     GROUP BY s.status
     ORDER BY s.status ASC"
);

/* =========================================================
   CHART 5: OVERALL STUDENT PERFORMANCE
========================================================= */

$performanceRows = getRows(
    $conn,
    "SELECT
         student_average.student_id,
         student_average.average_grade
     FROM (
         SELECT
             s.student_id,
             AVG((s.grade / NULLIF(a.points, 0)) * 100) AS average_grade
         FROM submissions s
         INNER JOIN assessments a ON a.id = s.assessment_id
         INNER JOIN classes c ON c.id = a.class_id
         $assessmentWhere
         AND s.grade IS NOT NULL
         AND a.points > 0
         GROUP BY s.student_id
     ) AS student_average"
);

$performanceDistribution = [
    'Excellent (90-100%)' => 0,
    'Very Good (85-89%)' => 0,
    'Good (80-84%)' => 0,
    'Satisfactory (75-79%)' => 0,
    'Below 75%' => 0
];

foreach ($performanceRows as $row) {
    $grade = (float)$row['average_grade'];

    if ($grade >= 90) {
        $performanceDistribution['Excellent (90-100%)']++;
    } elseif ($grade >= 85) {
        $performanceDistribution['Very Good (85-89%)']++;
    } elseif ($grade >= 80) {
        $performanceDistribution['Good (80-84%)']++;
    } elseif ($grade >= 75) {
        $performanceDistribution['Satisfactory (75-79%)']++;
    } else {
        $performanceDistribution['Below 75%']++;
    }
}

/* =========================================================
   CSV EXPORT
========================================================= */

if (isset($_GET['export']) && $_GET['export'] === 'csv') {

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="PLMUN_Report_Analytics.csv"');

    $output = fopen('php://output', 'w');

    fputcsv($output, ['PLMUN CLASSROOM - REPORTS AND ANALYTICS']);
    fputcsv($output, ['Generated:', date('F d, Y h:i A')]);
    fputcsv($output, ['Program:', $selectedProgram ?: 'All Programs']);
    fputcsv($output, ['Academic Year:', $selectedYear ?: 'All Academic Years']);
    fputcsv($output, ['Date From:', $dateFrom ?: 'All Dates']);
    fputcsv($output, ['Date To:', $dateTo ?: 'All Dates']);
    fputcsv($output, []);

    fputcsv($output, ['SUMMARY REPORT']);
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Total Students', $totalStudents]);
    fputcsv($output, ['Total Teachers', $totalTeachers]);
    fputcsv($output, ['Total Classes', $totalClasses]);
    fputcsv($output, ['Active Enrollments', $activeEnrollments]);
    fputcsv($output, ['Assessment Completion Rate', round($completionRate, 2) . '%']);
    fputcsv($output, ['Average Student Performance', round($averagePerformance, 2) . '%']);
    fputcsv($output, []);

    fputcsv($output, ['STUDENT ENROLLMENT TRENDS']);
    fputcsv($output, ['Month', 'Enrollments']);

    foreach ($enrollmentTrend as $row) {
        fputcsv($output, [$row['month_label'], $row['total']]);
    }

    fputcsv($output, []);
    fputcsv($output, ['STUDENT DISTRIBUTION BY PROGRAM']);
    fputcsv($output, ['Program', 'Students']);

    foreach ($studentDistribution as $row) {
        fputcsv($output, [$row['program_name'], $row['total']]);
    }

    fputcsv($output, []);
    fputcsv($output, ['CLASSES PER PROGRAM']);
    fputcsv($output, ['Program', 'Classes']);

    foreach ($classesPerProgram as $row) {
        fputcsv($output, [$row['program_name'], $row['total']]);
    }

    fputcsv($output, []);
    fputcsv($output, ['ASSESSMENT SUBMISSION STATISTICS']);
    fputcsv($output, ['Status', 'Submissions']);

    foreach ($submissionStatistics as $row) {
        fputcsv($output, [$row['status'], $row['total']]);
    }

    fputcsv($output, []);
    fputcsv($output, ['OVERALL STUDENT PERFORMANCE']);
    fputcsv($output, ['Performance Range', 'Students']);

    foreach ($performanceDistribution as $label => $total) {
        fputcsv($output, [$label, $total]);
    }

    fclose($output);
    exit();
}

/* =========================================================
   CHART DATA
========================================================= */

$enrollmentLabels = [];
$enrollmentValues = [];

foreach ($enrollmentTrend as $row) {
    $enrollmentLabels[] = $row['month_label'];
    $enrollmentValues[] = (int)$row['total'];
}

$studentProgramLabels = [];
$studentProgramValues = [];

foreach ($studentDistribution as $row) {
    $studentProgramLabels[] = $row['program_name'];
    $studentProgramValues[] = (int)$row['total'];
}

$classProgramLabels = [];
$classProgramValues = [];

foreach ($classesPerProgram as $row) {
    $classProgramLabels[] = $row['program_name'];
    $classProgramValues[] = (int)$row['total'];
}

$submissionLabels = [];
$submissionValues = [];

foreach ($submissionStatistics as $row) {
    $submissionLabels[] = ucfirst($row['status']);
    $submissionValues[] = (int)$row['total'];
}

$performanceLabels = array_keys($performanceDistribution);
$performanceValues = array_values($performanceDistribution);

$filterQuery = $_GET;
unset($filterQuery['export']);

$exportUrl = 'admin_reports.php?' .
    http_build_query(array_merge($filterQuery, ['export' => 'csv']));

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Report & Analytics | PLMUN Classroom</title>

    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">

    <link rel="stylesheet" href="admin_dashboard.css">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f7f8fa;
            font-family: Arial, sans-serif;
            overflow-x: hidden;
        }

        .reports-content {
            padding: 20px 24px 40px;
        }

        .reports-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .reports-title {
            font-size: 27px;
            font-weight: 700;
            color: #111;
        }

        .reports-subtitle {
            color: #777;
            font-size: 13px;
            margin-top: 6px;
        }

        .report-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .report-btn {
            border: none;
            padding: 11px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .export-btn {
            background: #174d83;
            color: white;
        }

        .print-btn {
            background: #e9edf2;
            color: #222;
        }

        .filter-card,
        .analytics-card {
            background: white;
            border: 1px solid #e6e8eb;
            border-radius: 9px;
            padding: 20px;
            margin-bottom: 22px;
        }

        .filter-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 15px;
            color: #222;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(4, minmax(130px, 1fr)) auto;
            gap: 13px;
            align-items: end;
        }

        .filter-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #555;
            margin-bottom: 7px;
        }

        .filter-group select,
        .filter-group input {
            width: 100%;
            height: 40px;
            border: 1px solid #d9dce1;
            border-radius: 5px;
            padding: 0 10px;
            font-size: 13px;
            background: white;
            color: #333;
        }

        .filter-submit {
            height: 40px;
            border: none;
            border-radius: 5px;
            padding: 0 19px;
            background: #174d83;
            color: white;
            font-weight: 600;
            cursor: pointer;
        }

        .clear-filter {
            display: inline-block;
            margin-top: 13px;
            color: #174d83;
            font-size: 12px;
            text-decoration: none;
        }

        .section-heading {
            font-size: 18px;
            font-weight: 700;
            color: #222;
            margin: 25px 0 14px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 17px;
        }

        .summary-card {
            background: white;
            border: 1px solid #e6e8eb;
            border-radius: 9px;
            padding: 20px;
            min-height: 140px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }

        .summary-label {
            color: #777;
            font-size: 13px;
            margin-bottom: 12px;
        }

        .summary-value {
            font-size: 29px;
            font-weight: 700;
            color: #161616;
        }

        .summary-description {
            color: #888;
            font-size: 11px;
            margin-top: 8px;
        }

        .summary-icon {
            width: 47px;
            height: 47px;
            border-radius: 9px;
            display: flex;
            justify-content: center;
            align-items: center;
            background: #edf3fa;
            color: #174d83;
            font-size: 20px;
            flex-shrink: 0;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }

        .analytics-card {
            min-width: 0;
            margin-bottom: 0;
        }

        .analytics-card.full-width {
            grid-column: 1 / -1;
        }

        .chart-title {
            font-size: 15px;
            font-weight: 700;
            color: #222;
            margin-bottom: 5px;
        }

        .chart-description {
            font-size: 12px;
            color: #888;
            margin-bottom: 20px;
        }

        .chart-container {
            position: relative;
            height: 280px;
            width: 100%;
        }

        .no-chart-data {
            display: none;
            text-align: center;
            color: #999;
            font-size: 13px;
            padding: 35px 10px;
        }

        @media(max-width: 1100px) {
            .filter-form {
                grid-template-columns: repeat(2, 1fr);
            }

            .summary-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media(max-width: 768px) {
            .reports-content {
                padding: 18px 15px 30px;
            }

            .reports-title {
                font-size: 23px;
            }

            .charts-grid {
                grid-template-columns: 1fr;
            }

            .analytics-card.full-width {
                grid-column: auto;
            }

            .chart-container {
                height: 250px;
            }
        }

        @media(max-width: 500px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }

            .filter-form {
                grid-template-columns: 1fr;
            }

            .report-actions {
                width: 100%;
            }

            .report-btn {
                flex: 1;
                justify-content: center;
            }

            .summary-card {
                min-height: 120px;
            }
        }

        @media print {
            body {
                background: white;
            }

            .admin-sidebar,
            .admin-overlay,
            .admin-topbar,
            .report-actions,
            .filter-card,
            .admin-mobile-btn {
                display: none !important;
            }

            .admin-main {
                margin: 0 !important;
                width: 100% !important;
            }

            .reports-content {
                padding: 10px;
            }

            .analytics-card,
            .summary-card {
                break-inside: avoid;
                border: 1px solid #ccc;
            }

            .charts-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>

<body>
<!-- =========================================
     SIDEBAR
========================================= -->

<div class="admin-sidebar" id="adminSidebar">

    <!-- SIDEBAR HEADER -->
    <div class="admin-sidebar-header">
        <h1>ADMIN</h1>
    </div>

    <!-- SIDEBAR MENU -->
    <ul class="admin-menu">

        <li>
            <a href="admin_dashboard.php">
                <i class="fa-solid fa-house"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <li>
            <a href="admin_users.php">
                <i class="fa-solid fa-user-group"></i>
                <span>User Management</span>
            </a>
        </li>

        <li>
            <a href="admin_settings.php">
                <i class="fa-solid fa-gear"></i>
                <span>System Settings</span>
            </a>
        </li>

        <li>
            <a href="admin_monitor.php">
                <i class="fa-solid fa-desktop"></i>
                <span>System Monitor</span>
            </a>
        </li>

        <li>
            <a href="admin_reports.php" class="active">
                <i class="fa-solid fa-chart-line"></i>
                <span>Reports &amp; Analytics</span>
            </a>
        </li>

        <li>
            <a href="admin_backup_security.php">
                <i class="fa-solid fa-shield-halved"></i>
                <span>Backup</span>
            </a>
        </li>

        <li>
            <a href="security_overview.php">
                <i class="fa-solid fa-database"></i>
                <span>Security Overview</span>
            </a>
        </li>

        <li>
            <a href="audit_logs.php">
                <i class="fa-solid fa-clock-rotate-left"></i>
                <span>Audit Logs</span>
            </a>
        </li>

    </ul>

    <!-- LOGOUT -->
    <div class="admin-logout">
        <a href="logout.php">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Log out</span>
        </a>
    </div>

</div>

<!-- MOBILE SIDEBAR OVERLAY -->
<div class="admin-overlay"
     id="adminOverlay"
     onclick="closeAdminSidebar()">
</div>

<!-- =========================================================
     MAIN CONTENT
========================================================= -->

<div class="admin-main" id="adminMain">

    <header class="admin-topbar">

        <div class="admin-top-left">

            <button class="admin-mobile-btn"
                    onclick="toggleAdminSidebar()">
                <i class="fa-solid fa-bars"></i>
            </button>

            <img src="img/classroom logo.png"
                 alt="PLMUN Classroom"
                 class="admin-logo">

        </div>

        <div class="admin-top-right">

            <div class="admin-user-name">
                <span><?= e($admin['name']) ?></span>
            </div>

            <a href="profile.php" class="admin-profile">

                <?php if (
                    !empty($admin['profile_image']) &&
                    $admin['profile_image'] !== 'default.png'
                ): ?>

                    <img src="uploads/<?= e($admin['profile_image']) ?>"
                         alt="Admin">

                <?php else: ?>

                    <i class="fa-solid fa-user"></i>

                <?php endif; ?>

            </a>

        </div>

    </header>

    <main class="reports-content">

        <!-- PAGE HEADER -->

        <div class="reports-header">

            <div>
                <h2 class="reports-title">Reports & Analytics</h2>

                <p class="reports-subtitle">
                    Overview of student enrollment, classes, and academic performance.
                </p>
            </div>

            <div class="report-actions">

                <a href="<?= e($exportUrl) ?>"
                   class="report-btn export-btn">
                    <i class="fa-solid fa-file-csv"></i>
                    Export CSV
                </a>

                <button type="button"
                        class="report-btn print-btn"
                        onclick="window.print()">
                    <i class="fa-solid fa-print"></i>
                    Print Report
                </button>

            </div>

        </div>

        <!-- FILTERS -->

        <section class="filter-card">

            <h3 class="filter-title">
                <i class="fa-solid fa-filter"></i>
                Filter Reports
            </h3>

            <form method="GET" class="filter-form">

                <div class="filter-group">

                    <label for="academic_year">Academic Year</label>

                    <select name="academic_year" id="academic_year">

                        <option value="">All Academic Years</option>

                        <?php foreach ($academicYears as $year): ?>

                            <option value="<?= e($year['academic_year']) ?>"
                                <?= $selectedYear === $year['academic_year'] ? 'selected' : '' ?>>
                                <?= e($year['academic_year']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="filter-group">

                    <label for="program">Program</label>

                    <select name="program" id="program">

                        <option value="">All Programs</option>

                        <?php foreach ($programs as $program): ?>

                            <option value="<?= e($program['program_name']) ?>"
                                <?= $selectedProgram === $program['program_name'] ? 'selected' : '' ?>>
                                <?= e($program['program_name']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="filter-group">

                    <label for="date_from">Date From</label>

                    <input type="date"
                           name="date_from"
                           id="date_from"
                           value="<?= e($dateFrom) ?>">

                </div>

                <div class="filter-group">

                    <label for="date_to">Date To</label>

                    <input type="date"
                           name="date_to"
                           id="date_to"
                           value="<?= e($dateTo) ?>">

                </div>

                <button type="submit" class="filter-submit">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    Apply
                </button>

            </form>

            <a href="admin_reports.php" class="clear-filter">
                <i class="fa-solid fa-rotate-left"></i>
                Clear Filters
            </a>

        </section>

        <!-- SUMMARY CARDS -->

        <h3 class="section-heading">Overview</h3>

        <section class="summary-grid">

            <div class="summary-card">

                <div>
                    <p class="summary-label">Total Students</p>

                    <h2 class="summary-value">
                        <?= number_format($totalStudents) ?>
                    </h2>

                    <p class="summary-description">Registered student accounts</p>
                </div>

                <div class="summary-icon">
                    <i class="fa-solid fa-user-graduate"></i>
                </div>

            </div>

            <div class="summary-card">

                <div>
                    <p class="summary-label">Total Teachers</p>

                    <h2 class="summary-value">
                        <?= number_format($totalTeachers) ?>
                    </h2>

                    <p class="summary-description">Registered teacher accounts</p>
                </div>

                <div class="summary-icon">
                    <i class="fa-solid fa-chalkboard-user"></i>
                </div>

            </div>

            <div class="summary-card">

                <div>
                    <p class="summary-label">Total Classes</p>

                    <h2 class="summary-value">
                        <?= number_format($totalClasses) ?>
                    </h2>

                    <p class="summary-description">Available classes</p>
                </div>

                <div class="summary-icon">
                    <i class="fa-solid fa-book-open"></i>
                </div>

            </div>

            <div class="summary-card">

                <div>
                    <p class="summary-label">Active Enrollments</p>

                    <h2 class="summary-value">
                        <?= number_format($activeEnrollments) ?>
                    </h2>

                    <p class="summary-description">Non-archived class enrollments</p>
                </div>

                <div class="summary-icon">
                    <i class="fa-solid fa-users"></i>
                </div>

            </div>

            <div class="summary-card">

                <div>
                    <p class="summary-label">Assessment Completion Rate</p>

                    <h2 class="summary-value">
                        <?= number_format($completionRate, 1) ?>%
                    </h2>

                    <p class="summary-description">
                        <?= number_format($completedSubmissions) ?>
                        of
                        <?= number_format($expectedSubmissions) ?>
                        expected submissions
                    </p>
                </div>

                <div class="summary-icon">
                    <i class="fa-solid fa-clipboard-check"></i>
                </div>

            </div>

            <div class="summary-card">

                <div>
                    <p class="summary-label">Average Performance</p>

                    <h2 class="summary-value">
                        <?= number_format($averagePerformance, 1) ?>%
                    </h2>

                    <p class="summary-description">Average graded submission percentage</p>
                </div>

                <div class="summary-icon">
                    <i class="fa-solid fa-chart-line"></i>
                </div>

            </div>

        </section>

        <!-- ANALYTICS CHARTS -->

        <h3 class="section-heading">Analytics</h3>

        <section class="charts-grid">

            <div class="analytics-card">

                <h3 class="chart-title">Student Enrollment Trends</h3>

                <p class="chart-description">
                    Number of enrollments recorded each month.
                </p>

                <div class="chart-container">
                    <canvas id="enrollmentChart"></canvas>
                </div>

                <p class="no-chart-data" id="enrollmentEmpty">
                    No enrollment data available.
                </p>

            </div>

            <div class="analytics-card">

                <h3 class="chart-title">Student Distribution by Program</h3>

                <p class="chart-description">
                    Distribution of registered students across programs.
                </p>

                <div class="chart-container">
                    <canvas id="studentProgramChart"></canvas>
                </div>

                <p class="no-chart-data" id="studentProgramEmpty">
                    No student distribution data available.
                </p>

            </div>

            <div class="analytics-card">

                <h3 class="chart-title">Classes per Program</h3>

                <p class="chart-description">
                    Number of classes associated with each program.
                </p>

                <div class="chart-container">
                    <canvas id="classProgramChart"></canvas>
                </div>

                <p class="no-chart-data" id="classProgramEmpty">
                    No class data available.
                </p>

            </div>

            <div class="analytics-card">

                <h3 class="chart-title">Assessment Submission Statistics</h3>

                <p class="chart-description">
                    Recorded submissions grouped by submission status.
                </p>

                <div class="chart-container">
                    <canvas id="submissionChart"></canvas>
                </div>

                <p class="no-chart-data" id="submissionEmpty">
                    No submission data available.
                </p>

            </div>

            <div class="analytics-card full-width">

                <h3 class="chart-title">Overall Student Performance</h3>

                <p class="chart-description">
                    Number of students grouped according to their average graded assessment percentage.
                </p>

                <div class="chart-container">
                    <canvas id="performanceChart"></canvas>
                </div>

                <p class="no-chart-data" id="performanceEmpty">
                    No graded submissions available.
                </p>

            </div>

        </section>

    </main>

</div>

<script>

/* =========================================================
   SIDEBAR
========================================================= */

function toggleAdminSidebar() {

    document.getElementById('adminSidebar').classList.toggle('show');

    document.getElementById('adminOverlay').classList.toggle('show');
}

function closeAdminSidebar() {

    document.getElementById('adminSidebar').classList.remove('show');

    document.getElementById('adminOverlay').classList.remove('show');
}

window.addEventListener('resize', function () {

    if (window.innerWidth > 768) {
        closeAdminSidebar();
    }

});

/* =========================================================
   CHART DATA
========================================================= */

const enrollmentLabels = <?= json_encode($enrollmentLabels) ?>;
const enrollmentValues = <?= json_encode($enrollmentValues) ?>;

const studentProgramLabels = <?= json_encode($studentProgramLabels) ?>;
const studentProgramValues = <?= json_encode($studentProgramValues) ?>;

const classProgramLabels = <?= json_encode($classProgramLabels) ?>;
const classProgramValues = <?= json_encode($classProgramValues) ?>;

const submissionLabels = <?= json_encode($submissionLabels) ?>;
const submissionValues = <?= json_encode($submissionValues) ?>;

const performanceLabels = <?= json_encode($performanceLabels) ?>;
const performanceValues = <?= json_encode($performanceValues) ?>;

/* =========================================================
   CHART DEFAULTS
========================================================= */

Chart.defaults.font.family = 'Arial, sans-serif';
Chart.defaults.color = '#666';

function showEmptyChart(canvasId, emptyId, hasData) {

    if (!hasData) {
        document.getElementById(canvasId).style.display = 'none';
        document.getElementById(emptyId).style.display = 'block';
    }
}

/* =========================================================
   CHART 1: ENROLLMENT TRENDS
========================================================= */

showEmptyChart(
    'enrollmentChart',
    'enrollmentEmpty',
    enrollmentValues.length > 0
);

if (enrollmentValues.length > 0) {

    new Chart(document.getElementById('enrollmentChart'), {

        type: 'line',

        data: {
            labels: enrollmentLabels,

            datasets: [{
                label: 'Enrollments',
                data: enrollmentValues,
                borderColor: '#174d83',
                backgroundColor: 'rgba(23, 77, 131, 0.12)',
                fill: true,
                tension: 0.35,
                borderWidth: 2,
                pointRadius: 4
            }]
        },

        options: {
            responsive: true,
            maintainAspectRatio: false,

            plugins: {
                legend: {
                    display: false
                }
            },

            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            }
        }

    });

}

/* =========================================================
   CHART 2: STUDENT DISTRIBUTION
========================================================= */

showEmptyChart(
    'studentProgramChart',
    'studentProgramEmpty',
    studentProgramValues.length > 0
);

if (studentProgramValues.length > 0) {

    new Chart(document.getElementById('studentProgramChart'), {

        type: 'doughnut',

        data: {
            labels: studentProgramLabels,

            datasets: [{
                data: studentProgramValues,
                backgroundColor: [
                    '#174d83',
                    '#3274ad',
                    '#5b91bf',
                    '#84afd0',
                    '#a7c8df',
                    '#c9deeb',
                    '#e0edf5'
                ],
                borderWidth: 1
            }]
        },

        options: {
            responsive: true,
            maintainAspectRatio: false,

            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }

    });

}

/* =========================================================
   CHART 3: CLASSES PER PROGRAM
========================================================= */

showEmptyChart(
    'classProgramChart',
    'classProgramEmpty',
    classProgramValues.length > 0
);

if (classProgramValues.length > 0) {

    new Chart(document.getElementById('classProgramChart'), {

        type: 'bar',

        data: {
            labels: classProgramLabels,

            datasets: [{
                label: 'Classes',
                data: classProgramValues,
                backgroundColor: '#3274ad',
                borderRadius: 5
            }]
        },

        options: {
            responsive: true,
            maintainAspectRatio: false,

            plugins: {
                legend: {
                    display: false
                }
            },

            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            }
        }

    });

}

/* =========================================================
   CHART 4: SUBMISSION STATISTICS
========================================================= */

showEmptyChart(
    'submissionChart',
    'submissionEmpty',
    submissionValues.length > 0
);

if (submissionValues.length > 0) {

    new Chart(document.getElementById('submissionChart'), {

        type: 'bar',

        data: {
            labels: submissionLabels,

            datasets: [{
                label: 'Submissions',
                data: submissionValues,
                backgroundColor: '#5b91bf',
                borderRadius: 5
            }]
        },

        options: {
            responsive: true,
            maintainAspectRatio: false,

            plugins: {
                legend: {
                    display: false
                }
            },

            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            }
        }

    });

}

/* =========================================================
   CHART 5: PERFORMANCE DISTRIBUTION
========================================================= */

showEmptyChart(
    'performanceChart',
    'performanceEmpty',
    performanceValues.some(value => value > 0)
);

if (performanceValues.some(value => value > 0)) {

    new Chart(document.getElementById('performanceChart'), {

        type: 'bar',

        data: {
            labels: performanceLabels,

            datasets: [{
                label: 'Number of Students',
                data: performanceValues,
                backgroundColor: '#174d83',
                borderRadius: 5
            }]
        },

        options: {
            responsive: true,
            maintainAspectRatio: false,

            plugins: {
                legend: {
                    display: false
                }
            },

            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            }
        }

    });

}

</script>

</body>
</html>
