
<?php
session_start();
require_once 'database.php';

/* =========================================
   PROFILE IMAGE VALIDATION
========================================= */

function hasValidProfileImage($file) {
    if (empty($file)) {
        return false;
    }

    $filename = basename($file);

    if (in_array(
        strtolower($filename),
        ['default.png', 'default.jpg', 'default.jpeg', 'null'],
        true
    )) {
        return false;
    }

    return is_file(__DIR__ . '/uploads/' . $filename);
}

/* =========================================
   TEACHER LOGIN VALIDATION
========================================= */

if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'teacher'
) {
    header("Location: login.php");
    exit();
}

$teacher_id = (int)$_SESSION['user_id'];

if (!isset($_GET['id']) || (int)$_GET['id'] <= 0) {
    http_response_code(404);
    exit("Class not found.");
}

$class_id = (int)$_GET['id'];

/* =========================================
   GET TEACHER INFORMATION
========================================= */

$userStmt = mysqli_prepare(
    $conn,
    "SELECT id, name, profile_image
     FROM users
     WHERE id = ?
     LIMIT 1"
);

if (!$userStmt) {
    error_log(mysqli_error($conn));
    exit("Unable to load user information.");
}

mysqli_stmt_bind_param($userStmt, "i", $teacher_id);
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
   VERIFY PRIMARY OR CO-INSTRUCTOR ACCESS
========================================= */

$classStmt = mysqli_prepare(
    $conn,
    "
    SELECT c.*
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
    "
);

if (!$classStmt) {
    error_log(mysqli_error($conn));
    exit("Unable to verify class access.");
}

mysqli_stmt_bind_param(
    $classStmt,
    "iii",
    $class_id,
    $teacher_id,
    $teacher_id
);

mysqli_stmt_execute($classStmt);

$classResult = mysqli_stmt_get_result($classStmt);
$class = mysqli_fetch_assoc($classResult);

mysqli_stmt_close($classStmt);

if (!$class) {
    http_response_code(403);
    exit("You are not authorized to view this class performance.");
}

/* =========================================
   FETCH ACTIVE STUDENTS
========================================= */

$sStmt = mysqli_prepare(
    $conn,
    "
    SELECT
        u.id,
        u.name,
        u.profile_image
    FROM class_students cs
    INNER JOIN users u
        ON cs.student_id = u.id
    WHERE cs.class_id = ?
      AND (cs.archived = 0 OR cs.archived IS NULL)
      AND u.role = 'student'
    ORDER BY u.name ASC
    "
);

if (!$sStmt) {
    error_log(mysqli_error($conn));
    exit("Unable to load students.");
}

mysqli_stmt_bind_param($sStmt, "i", $class_id);
mysqli_stmt_execute($sStmt);

$students = mysqli_stmt_get_result($sStmt)
    ->fetch_all(MYSQLI_ASSOC);

mysqli_stmt_close($sStmt);

/* =========================================
   FETCH ASSESSMENTS
========================================= */

$aStmt = mysqli_prepare(
    $conn,
    "
    SELECT id, title, points, type
    FROM assessments
    WHERE class_id = ?
    ORDER BY id ASC
    "
);

if (!$aStmt) {
    error_log(mysqli_error($conn));
    exit("Unable to load assessments.");
}

mysqli_stmt_bind_param($aStmt, "i", $class_id);
mysqli_stmt_execute($aStmt);

$assessments = mysqli_stmt_get_result($aStmt)
    ->fetch_all(MYSQLI_ASSOC);

mysqli_stmt_close($aStmt);

/* =========================================
   FETCH GRADES
========================================= */

$gStmt = mysqli_prepare(
    $conn,
    "
    SELECT
        s.student_id,
        s.assessment_id,
        s.grade
    FROM submissions s
    INNER JOIN assessments a
        ON s.assessment_id = a.id
    WHERE a.class_id = ?
    "
);

if (!$gStmt) {
    error_log(mysqli_error($conn));
    exit("Unable to load grades.");
}

mysqli_stmt_bind_param($gStmt, "i", $class_id);
mysqli_stmt_execute($gStmt);

$gradeRows = mysqli_stmt_get_result($gStmt)
    ->fetch_all(MYSQLI_ASSOC);

mysqli_stmt_close($gStmt);

$grades = [];

foreach ($gradeRows as $g) {
    $grades[$g['student_id']][$g['assessment_id']] = $g['grade'];
}

/* =========================================
   COMPUTE PER-STUDENT STATISTICS
========================================= */

$studentPerf = [];

foreach ($students as $s) {

    $total = 0;
    $maxTotal = 0;
    $count = 0;

    foreach ($assessments as $a) {

        $g = $grades[$s['id']][$a['id']] ?? null;

        if ($g !== null) {
            $total += (float)$g;
            $maxTotal += (float)$a['points'];
            $count++;
        }
    }

    $pct = ($maxTotal > 0)
        ? round(($total / $maxTotal) * 100, 1)
        : null;

    $studentPerf[$s['id']] = [
        'pct' => $pct,
        'graded' => $count,
        'total_assessed' => count($assessments),
    ];
}

/* =========================================
   SUMMARY COUNTS
========================================= */

$cExcellent = 0;
$cGood = 0;
$cAverage = 0;
$cNeedsHelp = 0;
$cUngraded = 0;

foreach ($studentPerf as $sp) {

    if ($sp['pct'] === null) {
        $cUngraded++;
        continue;
    }

    if ($sp['pct'] >= 90) {
        $cExcellent++;
    } elseif ($sp['pct'] >= 75) {
        $cGood++;
    } elseif ($sp['pct'] >= 60) {
        $cAverage++;
    } else {
        $cNeedsHelp++;
    }
}

/* =========================================
   CHART.JS DATA
========================================= */

$chartLabels = array_map(
    fn($a) => $a['title'],
    $assessments
);

$chartColors = [
    '#3f87b5',
    '#28a745',
    '#f0a500',
    '#dc3545',
    '#7b5fc4',
    '#17a2b8',
    '#e83e8c',
    '#fd7e14',
    '#6c757d',
    '#20c997',
    '#0d6efd',
    '#198754',
    '#ffc107',
    '#d63384',
    '#0dcaf0',
];

$chartDatasets = [];

foreach ($students as $i => $s) {

    $pts = [];

    foreach ($assessments as $a) {

        $g = $grades[$s['id']][$a['id']] ?? null;

        $pts[] = (
            $g !== null &&
            (float)$a['points'] > 0
        )
            ? round(((float)$g / (float)$a['points']) * 100, 1)
            : null;
    }

    $color = $chartColors[$i % count($chartColors)];

    $chartDatasets[] = [
        'label' => $s['name'],
        'data' => $pts,
        'borderColor' => $color,
        'backgroundColor' => $color . '20',
        'tension' => 0.4,
        'pointRadius' => 5,
        'pointHoverRadius' => 8,
        'spanGaps' => true,
        'borderWidth' => 2,
    ];
}

/* =========================================
   BAR CHART: CLASS AVERAGE PER ASSESSMENT
========================================= */

$barData = [];

foreach ($assessments as $a) {

    $total = 0;
    $count = 0;

    foreach ($students as $s) {

        $g = $grades[$s['id']][$a['id']] ?? null;

        if ($g !== null && (float)$a['points'] > 0) {

            $total += (
                (float)$g / (float)$a['points']
            ) * 100;

            $count++;
        }
    }

    $barData[] = $count > 0
        ? round($total / $count, 1)
        : 0;
}

/* =========================================
   DONUT CHART DATA
========================================= */

$donutData = [
    $cExcellent,
    $cGood,
    $cAverage,
    $cNeedsHelp,
    $cUngraded
];

$donutColors = [
    '#28a745',
    '#3f87b5',
    '#f0a500',
    '#dc3545',
    '#adb5bd'
];

$donutLabels = [
    'Excellent',
    'Good',
    'Average',
    'Needs Help',
    'Ungraded'
];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance – <?php echo htmlspecialchars($class['class_name']); ?></title>
    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">
    <link rel="stylesheet" href="teacher_class.css?v=5">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <!-- Page styles: keep AFTER teacher_class.css -->
    <link rel="stylesheet" href="teacher_class_performance.css">
</head>
<body class="class-perf-page">

<!-- MOBILE SIDEBAR OVERLAY -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileSidebar()"></div>

<!-- SIDEBAR -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <button type="button" class="toggle-btn" id="desktopToggle" onclick="toggleSidebar()"
                aria-label="Collapse or expand sidebar">
            <i class="fa-solid fa-bars"></i>
        </button>
        <button type="button" class="sidebar-close-btn" onclick="closeMobileSidebar()"
                aria-label="Close sidebar">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>
    <ul>
        <li><a href="teacher_dashboard.php"><i class="fa-solid fa-house"></i><span>Home</span></a></li>
        <li><a href="teacher_classpage.php"><i class="fa-solid fa-users"></i><span>Classes</span></a></li>
        <li><a href="teacher_to_review.php"><i class="fa-solid fa-clipboard-list"></i><span>To Review</span></a></li>
        <li><a href="teacher_messages.php"><i class="fa-regular fa-message"></i><span>Messages</span></a></li>
        <li><a href="teacher_performance.php" class="active" aria-current="page"><i class="fa-solid fa-chart-pie"></i><span>Performance</span></a></li>
    </ul>
    <div class="logout-btn">
        <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a>
    </div>
</div>

<div class="main-content" id="mainContent">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="navbar-left">
            <button type="button" class="mobile-menu" id="mobileMenuButton" onclick="mobileSidebar()"
                    aria-label="Open navigation menu" aria-expanded="false">
                <i class="fa-solid fa-bars"></i>
            </button>
            <img src="img/classroom logo.png" class="navbar-logo" alt="Logo">
        </div>
        <div class="navbar-right">
            <?php
                $profileFile = $user['profile_image'] ?? '';
                $hasProfileImage = hasValidProfileImage($profileFile);
            ?>
            <a href="profile.php" class="profile-icon" aria-label="Profile">
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

    <div class="container py-4">

        <!-- Page header -->
        <div class="perf-page-header">
            <a href="teacher_performance.php" class="back-btn" aria-label="Back to performance list">
                <i class="fa-solid fa-arrow-left"></i>
            </a>

            <div class="perf-heading">
                <h1 class="perf-page-title">
                    <span>
                        <?php echo htmlspecialchars($class['class_name']); ?>
                        <?php if(!empty($class['section'])): ?>
                            &mdash; <?php echo htmlspecialchars($class['section']); ?>
                        <?php endif; ?>
                    </span>
                    <span class="live-dot">LIVE</span>
                </h1>
                <div class="perf-page-sub">Student Performance Overview</div>
            </div>

            <div class="perf-actions">
                <button type="button" class="refresh-btn" id="refreshBtn" onclick="refreshData()">
                    <i class="fa-solid fa-rotate-right" id="refreshIcon"></i> Refresh
                </button>
                <a href="teacher_grades.php?id=<?php echo $class_id; ?>" class="refresh-btn">
                    <i class="fa-solid fa-table-list"></i> Grades
                </a>
            </div>
        </div>

        <?php if(empty($students)): ?>
        <div class="perf-empty">
            <i class="fa-solid fa-user-group"></i>
            <p>No students enrolled yet.</p>
        </div>

        <?php elseif(empty($assessments)): ?>
        <div class="perf-empty">
            <i class="fa-solid fa-clipboard-list"></i>
            <p>No assessments created yet.</p>
        </div>

        <?php else: ?>

        <!-- Summary stats -->
        <div class="summary-grid" id="summaryGrid">
            <div class="summary-card excellent">
                <div class="summary-num green" id="cnt-excellent"><?php echo $cExcellent; ?></div>
                <div class="summary-lbl">Excellent</div>
                <small class="summary-range">≥90%</small>
            </div>
            <div class="summary-card good">
                <div class="summary-num blue" id="cnt-good"><?php echo $cGood; ?></div>
                <div class="summary-lbl">Good</div>
                <small class="summary-range">75–89%</small>
            </div>
            <div class="summary-card average">
                <div class="summary-num yellow" id="cnt-average"><?php echo $cAverage; ?></div>
                <div class="summary-lbl">Average</div>
                <small class="summary-range">60–74%</small>
            </div>
            <div class="summary-card needs-help">
                <div class="summary-num red" id="cnt-needs"><?php echo $cNeedsHelp; ?></div>
                <div class="summary-lbl">Needs Help</div>
                <small class="summary-range">&lt;60%</small>
            </div>
            <div class="summary-card ungraded">
                <div class="summary-num grey" id="cnt-ungraded"><?php echo $cUngraded; ?></div>
                <div class="summary-lbl">Ungraded</div>
                <small class="summary-range">No grades</small>
            </div>
        </div>

        <div class="row g-4">

            <!-- Line chart -->
            <div class="col-12 col-lg-8">
                <div class="chart-card">
                    <div class="chart-card-title">Individual Performance per Assessment</div>
                    <div class="chart-card-sub">Score percentage (%) per assessment for each student</div>
                    <div class="chart-box">
                        <canvas id="lineChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Donut chart -->
            <div class="col-12 col-lg-4">
                <div class="chart-card">
                    <div class="chart-card-title">Performance Distribution</div>
                    <div class="chart-card-sub">Overall class breakdown</div>
                    <div class="chart-box">
                        <canvas id="donutChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Bar chart -->
            <div class="col-12">
                <div class="chart-card">
                    <div class="chart-card-title">Class Average per Assessment</div>
                    <div class="chart-card-sub">Average score (%) across all students per assessment</div>
                    <div class="chart-box chart-box-bar">
                        <canvas id="barChart"></canvas>
                    </div>
                </div>
            </div>

        </div>

        <!-- Student performance list -->
        <div class="chart-card mt-4">
            <div class="rank-header">
                <div>
                    <div class="chart-card-title mb-0">Student Rankings</div>
                    <div class="chart-card-sub">Sorted by overall performance</div>
                </div>
                <a href="export_grades.php?id=<?php echo $class_id; ?>" class="refresh-btn">
                    <i class="fa-solid fa-file-export"></i> Export CSV
                </a>
            </div>

            <div id="studentList">
            <?php
            // Sort students by pct descending
            $sortedStudents = $students;
            usort($sortedStudents, function($a, $b) use ($studentPerf){
                $pA = $studentPerf[$a['id']]['pct'] ?? -1;
                $pB = $studentPerf[$b['id']]['pct'] ?? -1;
                return $pB <=> $pA;
            });

            foreach($sortedStudents as $rank => $s):
                $sp  = $studentPerf[$s['id']];
                $pct = $sp['pct'];

                if($pct === null){
                    $barClass = ''; $badgeClass = 'ungraded'; $badgeText = 'No grades yet'; $bw = 0;
                } elseif($pct >= 90){
                    $barClass = 'high'; $badgeClass = 'excellent'; $badgeText = '⭐ Excellent'; $bw = $pct;
                } elseif($pct >= 75){
                    $barClass = 'high'; $badgeClass = 'good'; $badgeText = '✅ Good'; $bw = $pct;
                } elseif($pct >= 60){
                    $barClass = 'mid'; $badgeClass = 'average'; $badgeText = '📈 Average'; $bw = $pct;
                } else {
                    $barClass = 'low'; $badgeClass = 'needs-help'; $badgeText = '⚠️ Needs Help'; $bw = $pct;
                }
            ?>
            <div class="student-perf-row">

                <div class="s-avatar">
                    <?php if(hasValidProfileImage($s['profile_image'] ?? '')): ?>
                        <img src="uploads/<?php echo htmlspecialchars(basename($s['profile_image'])); ?>"
                             alt=""
                             onerror="this.remove(); this.parentElement.innerHTML='<i class=&quot;fa-solid fa-user&quot;></i>';">
                    <?php else: ?>
                        <i class="fa-solid fa-user"></i>
                    <?php endif; ?>
                </div>

                <div class="s-info">
                    <div class="s-name">#<?php echo $rank+1; ?> <?php echo htmlspecialchars($s['name']); ?></div>
                    <div class="s-sub"><?php echo $sp['graded']; ?>/<?php echo $sp['total_assessed']; ?> assessed</div>
                </div>

                <div class="bar-wrap">
                    <div class="bar-fill <?php echo $barClass; ?>"
                         style="width:0"
                         data-width="<?php echo $bw; ?>">
                    </div>
                </div>

                <div class="score-pct"><?php echo $pct !== null ? $pct.'%' : '—'; ?></div>

                <span class="status-badge <?php echo $badgeClass; ?>"><?php echo $badgeText; ?></span>

            </div>
            <?php endforeach; ?>
            </div>
        </div>

        <?php endif; ?>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const CLASS_ID = <?php echo $class_id; ?>;

/* ── Sidebar ── */
function toggleSidebar(){
    if(window.innerWidth <= 768) return;
    document.getElementById('sidebar').classList.toggle('collapsed');
    document.getElementById('mainContent').classList.toggle('expanded');
}
function mobileSidebar(){
    document.getElementById('sidebar').classList.add('show');
    document.getElementById('sidebarOverlay').classList.add('show');
    document.body.classList.add('no-scroll');
    document.getElementById('mobileMenuButton').setAttribute('aria-expanded', 'true');
}
function closeMobileSidebar(){
    document.getElementById('sidebar').classList.remove('show');
    document.getElementById('sidebarOverlay').classList.remove('show');
    document.body.classList.remove('no-scroll');
    document.getElementById('mobileMenuButton').setAttribute('aria-expanded', 'false');
}
document.addEventListener('keydown', function(e){
    if(e.key === 'Escape') closeMobileSidebar();
});
document.querySelectorAll('.sidebar ul li a, .sidebar .logout-btn a').forEach(function(link){
    link.addEventListener('click', closeMobileSidebar);
});
window.addEventListener('resize', function(){
    if(window.innerWidth > 768){
        closeMobileSidebar();
    } else {
        document.getElementById('sidebar').classList.remove('collapsed');
        document.getElementById('mainContent').classList.remove('expanded');
    }
});

<?php if(!empty($students) && !empty($assessments)): ?>
/* ── Chart data from PHP ── */
const lineLabels   = <?php echo json_encode($chartLabels); ?>;
const lineDatasets = <?php echo json_encode($chartDatasets); ?>;
const barLabels    = <?php echo json_encode($chartLabels); ?>;
const barData      = <?php echo json_encode($barData); ?>;
const donutData    = <?php echo json_encode($donutData); ?>;
const donutColors  = <?php echo json_encode($donutColors); ?>;
const donutLabels  = <?php echo json_encode($donutLabels); ?>;

const isPhone = window.innerWidth <= 576;

// Smaller points / fonts on phones
lineDatasets.forEach(ds => {
    ds.pointRadius = isPhone ? 3 : 5;
    ds.pointHoverRadius = isPhone ? 6 : 8;
});

const tickFont   = { size: isPhone ? 10 : 11 };
const legendFont = { size: isPhone ? 10 : 11 };

// Long assessment titles: shorten on the axis only (full title stays in the tooltip)
function shortLabel(label){
    const max = isPhone ? 10 : 18;
    label = String(label);
    return label.length > max ? label.slice(0, max - 1) + '…' : label;
}

const xTicks = {
    font: tickFont,
    autoSkip: true,
    maxRotation: isPhone ? 60 : 30,
    callback: function(value){ return shortLabel(this.getLabelForValue(value)); }
};

const tooltipTitle = items => items.length ? lineLabels[items[0].dataIndex] : '';

/* ── Line chart ── */
const lineChart = new Chart(document.getElementById('lineChart').getContext('2d'), {
    type: 'line',
    data: { labels: lineLabels, datasets: lineDatasets },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 800 },
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: {
                position: 'bottom',
                labels: { boxWidth: 10, font: legendFont, padding: isPhone ? 8 : 14 }
            },
            tooltip: {
                callbacks: {
                    title: tooltipTitle,
                    label: ctx => ` ${ctx.dataset.label}: ${ctx.parsed.y !== null ? ctx.parsed.y + '%' : 'N/A'}`
                }
            }
        },
        scales: {
            y: {
                min: 0, max: 100,
                ticks: { callback: v => v + '%', font: tickFont },
                grid: { color: '#f0f0f0' }
            },
            x: { ticks: xTicks, grid: { display: false } }
        }
    }
});

/* ── Bar chart ── */
const barChart = new Chart(document.getElementById('barChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: barLabels,
        datasets: [{
            label: 'Class Average (%)',
            data: barData,
            backgroundColor: barData.map(v =>
                v >= 75 ? '#28a74588' : v >= 60 ? '#f0a50088' : '#dc354588'
            ),
            borderColor: barData.map(v =>
                v >= 75 ? '#28a745' : v >= 60 ? '#f0a500' : '#dc3545'
            ),
            borderWidth: 2,
            borderRadius: 6,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 800 },
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    title: items => items.length ? barLabels[items[0].dataIndex] : '',
                    label: ctx => ` Average: ${ctx.parsed.y}%`
                }
            }
        },
        scales: {
            y: {
                min: 0, max: 100,
                ticks: { callback: v => v + '%', font: tickFont },
                grid: { color: '#f0f0f0' }
            },
            x: { ticks: xTicks, grid: { display: false } }
        }
    }
});

/* ── Donut chart ── */
const donutChart = new Chart(document.getElementById('donutChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: donutLabels,
        datasets: [{
            data: donutData,
            backgroundColor: donutColors,
            borderWidth: 2,
            borderColor: '#fff',
            hoverOffset: 8,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { animateRotate: true, duration: 900 },
        plugins: {
            legend: {
                position: 'bottom',
                labels: { boxWidth: 10, font: legendFont, padding: 10 }
            },
            tooltip: {
                callbacks: { label: ctx => ` ${ctx.label}: ${ctx.parsed} students` }
            }
        },
        cutout: '62%',
    }
});

/* ── Animate progress bars on load ── */
document.addEventListener('DOMContentLoaded', animateBars);

function animateBars(){
    document.querySelectorAll('.bar-fill').forEach(function(bar){
        setTimeout(() => bar.style.width = bar.dataset.width + '%', 100);
    });
}

/* ── Live refresh every 30 seconds ── */
setInterval(refreshData, 30000);

async function refreshData(){
    const btn = document.getElementById('refreshBtn');
    btn.classList.add('spinning');

    try {
        const r = await fetch(`performance_data.php?class_id=${CLASS_ID}&t=${Date.now()}`);
        const d = await r.json();

        if(!d.success){ btn.classList.remove('spinning'); return; }

        // Summary counts
        document.getElementById('cnt-excellent').textContent = d.counts.excellent;
        document.getElementById('cnt-good').textContent      = d.counts.good;
        document.getElementById('cnt-average').textContent   = d.counts.average;
        document.getElementById('cnt-needs').textContent     = d.counts.needs_help;
        document.getElementById('cnt-ungraded').textContent  = d.counts.ungraded;

        // Line chart
        lineChart.data.datasets.forEach((ds, i) => {
            if(d.lineDatasets[i]) ds.data = d.lineDatasets[i].data;
        });
        lineChart.update('active');

        // Bar chart
        barChart.data.datasets[0].data = d.barData;
        barChart.data.datasets[0].backgroundColor = d.barData.map(v =>
            v >= 75 ? '#28a74588' : v >= 60 ? '#f0a50088' : '#dc354588'
        );
        barChart.update('active');

        // Donut
        donutChart.data.datasets[0].data = d.donutData;
        donutChart.update('active');

        // Student list
        document.getElementById('studentList').innerHTML = d.studentListHtml;
        animateBars();

    } catch(e){ console.error('Refresh error:', e); }

    btn.classList.remove('spinning');
}
<?php endif; ?>
</script>
</body>
</html>