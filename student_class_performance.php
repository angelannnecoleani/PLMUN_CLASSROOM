<?php
session_start();
include 'database.php';

$student_id = $_SESSION['user_id'];
if(!isset($_GET['id'])) die("Class not found");
$class_id = intval($_GET['id']);

$userQuery = mysqli_query($conn, "SELECT * FROM users WHERE id='$student_id'");
$user      = mysqli_fetch_assoc($userQuery);

// Verify enrollment
$enrollCheck = mysqli_prepare($conn, "
    SELECT classes.*, users.name AS teacher_name
    FROM class_students cs
    INNER JOIN classes ON cs.class_id = classes.id
    INNER JOIN users   ON classes.teacher_id = users.id
    WHERE cs.class_id = ? AND cs.student_id = ?
");
mysqli_stmt_bind_param($enrollCheck, "ii", $class_id, $student_id);
mysqli_stmt_execute($enrollCheck);
$class = mysqli_fetch_assoc(mysqli_stmt_get_result($enrollCheck));
if(!$class) die("Class not found or not enrolled");

// Assessments
$aStmt = mysqli_prepare($conn, "
    SELECT id, title, points, type, due_date
    FROM assessments WHERE class_id = ? ORDER BY id ASC
");
mysqli_stmt_bind_param($aStmt, "i", $class_id);
mysqli_stmt_execute($aStmt);
$assessments = mysqli_stmt_get_result($aStmt)->fetch_all(MYSQLI_ASSOC);

// This student's grades
$gStmt = mysqli_prepare($conn, "
    SELECT s.assessment_id, s.grade, s.status, s.submitted_at
    FROM submissions s
    WHERE s.student_id = ? AND s.assessment_id IN (
        SELECT id FROM assessments WHERE class_id = ?
    )
");
mysqli_stmt_bind_param($gStmt, "ii", $student_id, $class_id);
mysqli_stmt_execute($gStmt);
$gradeRows = mysqli_stmt_get_result($gStmt)->fetch_all(MYSQLI_ASSOC);
$myGrades  = [];
foreach($gradeRows as $g) $myGrades[$g['assessment_id']] = $g;

// Class averages
$avgStmt = mysqli_prepare($conn, "
    SELECT s.assessment_id, AVG(s.grade) AS avg_grade
    FROM submissions s
    INNER JOIN assessments a ON s.assessment_id = a.id
    WHERE a.class_id = ? AND s.grade IS NOT NULL
    GROUP BY s.assessment_id
");
mysqli_stmt_bind_param($avgStmt, "i", $class_id);
mysqli_stmt_execute($avgStmt);
$avgRows = mysqli_stmt_get_result($avgStmt)->fetch_all(MYSQLI_ASSOC);
$classAvg = [];
foreach($avgRows as $r) $classAvg[$r['assessment_id']] = round($r['avg_grade'], 1);

// Overall stats
$myTotal = 0; $myMax = 0; $graded = 0; $submitted = 0;
$myLineData  = [];
$avgLineData = [];
$chartLabels = [];

foreach($assessments as $a){
    $chartLabels[] = $a['title'];
    $g = $myGrades[$a['id']] ?? null;

    // My score %
    if($g && $g['grade'] !== null && $a['points'] > 0){
        $pct = round(($g['grade'] / $a['points']) * 100, 1);
        $myLineData[] = $pct;
        $myTotal += $g['grade'];
        $myMax   += $a['points'];
        $graded++;
    } else {
        $myLineData[] = null;
    }

    // Class avg %
    if(isset($classAvg[$a['id']]) && $a['points'] > 0){
        $avgLineData[] = round(($classAvg[$a['id']] / $a['points']) * 100, 1);
    } else {
        $avgLineData[] = null;
    }

    if($g && in_array($g['status'], ['submitted','done'])) $submitted++;
}

$overallPct = $myMax > 0 ? round(($myTotal / $myMax) * 100, 1) : null;

// Status
if($overallPct === null)     { $status = 'No grades yet'; $statusClass = 'ungraded'; }
elseif($overallPct >= 90)    { $status = '⭐ Excellent';   $statusClass = 'excellent'; }
elseif($overallPct >= 75)    { $status = '✅ Good';         $statusClass = 'good'; }
elseif($overallPct >= 60)    { $status = '📈 Average';     $statusClass = 'average'; }
else                         { $status = '⚠️ Needs Help';  $statusClass = 'needs-help'; }

// Circumference for big ring
$bigCirc   = 2 * M_PI * 54;
$bigOffset = $overallPct !== null ? $bigCirc - ($overallPct / 100) * $bigCirc : $bigCirc;
if($overallPct === null)    $ringColor = '#adb5bd';
elseif($overallPct >= 90)  $ringColor = '#28a745';
elseif($overallPct >= 75)  $ringColor = '#3f87b5';
elseif($overallPct >= 60)  $ringColor = '#f0a500';
else                       $ringColor = '#dc3545';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Performance – <?php echo htmlspecialchars($class['class_name']); ?></title>
    <link rel="stylesheet" href="student_dashboard.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        /* Page header */
        .perf-header {
            display: flex; align-items: center; gap: 14px;
            margin-bottom: 24px; flex-wrap: wrap;
        }
        .back-btn {
            width: 38px; height: 38px; border-radius: 50%;
            border: 1px solid #dde4ec; background: #fff;
            display: flex; align-items: center; justify-content: center;
            color: #555; font-size: 15px; text-decoration: none;
            transition: background 0.2s; flex-shrink: 0;
        }
        .back-btn:hover { background: #f0f4f8; color: #3f87b5; }
        .perf-page-title { font-size: 22px; font-weight: 800; color: #1a2d3e; margin: 0; }
        .perf-page-sub   { font-size: 13px; color: #888; margin: 2px 0 0; }

        /* Live dot */
        .live-dot {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 11px; font-weight: 600; color: #28a745;
            background: #d4edda; padding: 3px 10px; border-radius: 20px; margin-left: 8px;
        }
        .live-dot::before {
            content: ''; width: 7px; height: 7px; border-radius: 50%;
            background: #28a745; animation: pulse 1.4s infinite;
        }
        @keyframes pulse {
            0%,100% { opacity:1; transform:scale(1); }
            50%      { opacity:.4; transform:scale(1.3); }
        }

        /* Overall card */
        .overall-card {
            background: #fff; border: 1px solid #e0e8f0;
            border-radius: 16px; padding: 28px 32px;
            display: flex; align-items: center; gap: 32px;
            margin-bottom: 24px; flex-wrap: wrap;
        }

        /* Big progress ring */
        .big-ring-wrap { position: relative; width: 130px; height: 130px; flex-shrink: 0; }
        .big-ring-wrap svg { transform: rotate(-90deg); }
        .ring-bg   { fill: none; stroke: #eef2f7; stroke-width: 8; }
        .ring-fill { fill: none; stroke-width: 8; stroke-linecap: round; transition: stroke-dashoffset 1.2s ease; }
        .ring-label {
            position: absolute; inset: 0;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
        }
        .ring-pct-big  { font-size: 24px; font-weight: 900; color: #1a2d3e; line-height: 1; }
        .ring-pct-sub  { font-size: 11px; color: #aaa; margin-top: 2px; }

        .overall-stats { flex: 1; min-width: 200px; }
        .overall-stats h4 { font-size: 18px; font-weight: 700; color: #1a2d3e; margin-bottom: 6px; }

        .status-badge {
            display: inline-block; font-size: 13px; font-weight: 700;
            padding: 6px 18px; border-radius: 20px; margin-bottom: 14px;
        }
        .status-badge.excellent  { background: #d4edda; color: #155724; }
        .status-badge.good       { background: #cce5ff; color: #004085; }
        .status-badge.average    { background: #fff3cd; color: #856404; }
        .status-badge.needs-help { background: #f8d7da; color: #721c24; }
        .status-badge.ungraded   { background: #f0f0f0; color: #888; }

        .stat-row { display: flex; gap: 24px; flex-wrap: wrap; }
        .stat-item { text-align: center; }
        .stat-item .num { font-size: 22px; font-weight: 800; color: #1a2d3e; }
        .stat-item .lbl { font-size: 11px; color: #888; margin-top: 2px; text-transform: uppercase; }

        /* Chart card */
        .chart-card {
            background: #fff; border: 1px solid #e0e8f0;
            border-radius: 12px; padding: 20px 24px; margin-bottom: 20px;
        }
        .chart-title { font-size: 14px; font-weight: 700; color: #1a2d3e; margin-bottom: 4px; }
        .chart-sub   { font-size: 12px; color: #888; margin-bottom: 16px; }

        /* Assessment table */
        .assess-table { width: 100%; border-collapse: separate; border-spacing: 0; }
        .assess-table th {
            padding: 10px 14px; font-size: 12px; font-weight: 700;
            color: #888; text-transform: uppercase; letter-spacing: .4px;
            border-bottom: 2px solid #f0f0f0; text-align: left; background: #f8fafc;
        }
        .assess-table td {
            padding: 13px 14px; font-size: 14px;
            border-bottom: 1px solid #f5f5f5; vertical-align: middle;
        }
        .assess-table tr:last-child td { border-bottom: none; }
        .assess-table tr:hover td { background: #f9fbfe; }

        .type-badge {
            font-size: 10px; font-weight: 700; padding: 3px 10px;
            border-radius: 20px; background: #e8f0f8; color: #3f87b5;
        }

        .grade-cell { font-weight: 700; font-size: 15px; }
        .grade-cell.high   { color: #28a745; }
        .grade-cell.mid    { color: #f0a500; }
        .grade-cell.low    { color: #dc3545; }
        .grade-cell.none   { color: #bbb; }

        .compare-bar-wrap { width: 100px; background: #eef2f7; border-radius: 10px; height: 8px; overflow: hidden; display: inline-block; vertical-align: middle; }
        .compare-bar-fill { height: 100%; border-radius: 10px; transition: width 1s ease; }

        .vs-avg { font-size: 12px; font-weight: 600; }
        .vs-avg.above { color: #28a745; }
        .vs-avg.below { color: #dc3545; }
        .vs-avg.same  { color: #888; }

        /* Mini status */
        .row-status {
            font-size: 11px; font-weight: 600;
            padding: 3px 10px; border-radius: 10px; white-space: nowrap;
        }
        .row-status.submitted  { background: #cce5ff; color: #004085; }
        .row-status.graded     { background: #d4edda; color: #155724; }
        .row-status.missing    { background: #f8d7da; color: #721c24; }
        .row-status.no-submit  { background: #f0f0f0; color: #888; }

        /* Refresh */
        .refresh-btn {
            border: 1px solid #dde4ec; background: #fff; border-radius: 8px;
            padding: 6px 16px; font-size: 13px; font-weight: 600; color: #555;
            cursor: pointer; transition: background 0.2s;
            display: flex; align-items: center; gap: 6px;
        }
        .refresh-btn:hover { background: #f0f4f8; }
        .refresh-btn.spinning i { animation: spin 0.6s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Empty */
        .perf-empty { text-align: center; padding: 50px 20px; color: #bbb; font-size: 14px; }
        .perf-empty i { font-size: 44px; display: block; margin-bottom: 12px; }

        @media (max-width: 576px) {
            .overall-card { flex-direction: column; align-items: center; text-align: center; }
            .stat-row { justify-content: center; }
            .compare-bar-wrap { display: none; }
        }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <button class="toggle-btn" onclick="toggleSidebar()">
            <i class="fa-solid fa-bars"></i>
        </button>
    </div>
    <ul>
        <li><a href="student_dashboard.php"><i class="fa-solid fa-house"></i><span>Home</span></a></li>
        <li><a href="student_classpage.php"><i class="fa-solid fa-users"></i><span>Classes</span></a></li>
        <li><a href="#"><i class="fa-solid fa-clipboard-list"></i><span>To Do</span></a></li>
        <li><a href="student_messages.php"><i class="fa-regular fa-message"></i><span>Messages</span></a></li>
        <li><a href="student_performance.php" style="color:#3f87b5; border-left:3px solid #3f87b5;"><i class="fa-solid fa-chart-pie"></i><span>Performance</span></a></li>
        <li><a href="#"><i class="fa-solid fa-book-open"></i><span>Resources</span></a></li>
    </ul>
    <div class="logout-btn">
        <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a>
    </div>
</div>

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
            <?php if(!empty($user['profile_image'])): ?>
                <img src="uploads/<?php echo htmlspecialchars($user['profile_image']); ?>" class="profile-icon" alt="">
            <?php else: ?>
                <div class="profile-icon"><i class="fa-solid fa-user"></i></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="container py-4">

        <!-- Page header -->
        <div class="perf-header">
            <a href="student_performance.php" class="back-btn">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div>
                <div class="perf-page-title">
                    <?php echo htmlspecialchars($class['class_name']); ?>
                    <?php if(!empty($class['section'])): ?>
                        &mdash; <?php echo htmlspecialchars($class['section']); ?>
                    <?php endif; ?>
                    <span class="live-dot">LIVE</span>
                </div>
                <div class="perf-page-sub">
                    <i class="fa-solid fa-chalkboard-teacher me-1"></i>
                    <?php echo htmlspecialchars($class['teacher_name']); ?>
                </div>
            </div>
            <button class="refresh-btn ms-auto" id="refreshBtn" onclick="refreshData()">
                <i class="fa-solid fa-rotate-right" id="refreshIcon"></i> Refresh
            </button>
        </div>

        <?php if(empty($assessments)): ?>
        <div class="perf-empty">
            <i class="fa-solid fa-clipboard-list"></i>
            <p>No assessments posted yet.</p>
        </div>

        <?php else: ?>

        <!-- Overall card -->
        <div class="overall-card" id="overallCard">

            <!-- Big ring -->
            <div class="big-ring-wrap">
                <svg viewBox="0 0 120 120" width="130" height="130">
                    <circle class="ring-bg"   cx="60" cy="60" r="54"/>
                    <circle class="ring-fill"
                            cx="60" cy="60" r="54"
                            stroke="<?php echo $ringColor; ?>"
                            stroke-dasharray="<?php echo round($bigCirc, 2); ?>"
                            stroke-dashoffset="<?php echo round($bigOffset, 2); ?>"/>
                </svg>
                <div class="ring-label">
                    <div class="ring-pct-big" id="overallPct">
                        <?php echo $overallPct !== null ? $overallPct.'%' : '—'; ?>
                    </div>
                    <div class="ring-pct-sub">overall</div>
                </div>
            </div>

            <!-- Stats -->
            <div class="overall-stats">
                <h4>
                    <?php echo htmlspecialchars($user['name']); ?>
                </h4>
                <span class="status-badge <?php echo $statusClass; ?>" id="statusBadge">
                    <?php echo $status; ?>
                </span>
                <div class="stat-row">
                    <div class="stat-item">
                        <div class="num" id="statGraded"><?php echo $graded; ?></div>
                        <div class="lbl">Graded</div>
                    </div>
                    <div class="stat-item">
                        <div class="num" id="statSubmitted"><?php echo $submitted; ?></div>
                        <div class="lbl">Submitted</div>
                    </div>
                    <div class="stat-item">
                        <div class="num"><?php echo count($assessments); ?></div>
                        <div class="lbl">Total</div>
                    </div>
                    <div class="stat-item">
                        <div class="num"><?php echo count($assessments) - $submitted; ?></div>
                        <div class="lbl">Pending</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">

            <!-- Line chart: my score vs class avg -->
            <div class="col-lg-8">
                <div class="chart-card">
                    <div class="chart-title">My Score vs Class Average</div>
                    <div class="chart-sub">Score percentage (%) per assessment</div>
                    <canvas id="lineChart" height="180"></canvas>
                </div>
            </div>

            <!-- Donut: submission status -->
            <div class="col-lg-4">
                <div class="chart-card">
                    <div class="chart-title">Submission Status</div>
                    <div class="chart-sub">Breakdown of your work</div>
                    <canvas id="donutChart" height="180"></canvas>
                </div>
            </div>

            <!-- Bar chart: score per assessment -->
            <div class="col-12">
                <div class="chart-card">
                    <div class="chart-title">My Score per Assessment</div>
                    <div class="chart-sub">Your individual score (%) per assessment</div>
                    <canvas id="barChart" height="90"></canvas>
                </div>
            </div>

        </div>

        <!-- Assessment breakdown table -->
        <div class="chart-card mt-2">
            <div class="chart-title mb-1">Assessment Breakdown</div>
            <div class="chart-sub">Your grade, class average, and status per assessment</div>
            <div style="overflow-x:auto;">
            <table class="assess-table" id="assessTable">
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
                <?php foreach($assessments as $a):
                    $g    = $myGrades[$a['id']] ?? null;
                    $myG  = ($g && $g['grade'] !== null) ? $g['grade'] : null;
                    $myP  = ($myG !== null && $a['points'] > 0) ? round(($myG / $a['points']) * 100, 1) : null;
                    $avgP = isset($classAvg[$a['id']]) && $a['points'] > 0
                            ? round(($classAvg[$a['id']] / $a['points']) * 100, 1) : null;

                    $gradeCls = $myP === null ? 'none' : ($myP >= 75 ? 'high' : ($myP >= 60 ? 'mid' : 'low'));
                    $barColor = $myP === null ? '#eef2f7' : ($myP >= 75 ? '#28a745' : ($myP >= 60 ? '#f0a500' : '#dc3545'));

                    $diff = ($myP !== null && $avgP !== null) ? round($myP - $avgP, 1) : null;
                    $vsClass = $diff === null ? 'same' : ($diff > 0 ? 'above' : ($diff < 0 ? 'below' : 'same'));
                    $vsText  = $diff === null ? '—' : ($diff > 0 ? '+' . $diff . '%' : $diff . '%');

                    $rowStatus = 'no-submit'; $rowStatusText = 'Not submitted';
                    if($g){
                        if($myG !== null)                              { $rowStatus='graded';    $rowStatusText='Graded'; }
                        elseif(in_array($g['status'],['submitted','done'])) { $rowStatus='submitted'; $rowStatusText='Submitted'; }
                        elseif($g['status'] === 'missing')             { $rowStatus='missing';   $rowStatusText='Missing'; }
                    }
                ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($a['title']); ?></strong>
                        <div style="font-size:11px; color:#aaa;">Due <?php echo date('M d, Y', strtotime($a['due_date'])); ?></div>
                    </td>
                    <td><span class="type-badge"><?php echo htmlspecialchars($a['type']); ?></span></td>
                    <td>
                        <span class="grade-cell <?php echo $gradeCls; ?>">
                            <?php echo $myG !== null ? $myG . '/' . $a['points'] : '—'; ?>
                        </span>
                        <?php if($myP !== null): ?>
                            <div style="font-size:11px; color:#aaa;"><?php echo $myP; ?>%</div>
                        <?php endif; ?>
                    </td>
                    <td style="color:#888; font-size:13px;">
                        <?php echo $avgP !== null ? $avgP . '%' : '—'; ?>
                    </td>
                    <td><span class="vs-avg <?php echo $vsClass; ?>"><?php echo $vsText; ?></span></td>
                    <td>
                        <div class="compare-bar-wrap">
                            <div class="compare-bar-fill"
                                 style="width:0; background:<?php echo $barColor; ?>"
                                 data-width="<?php echo $myP ?? 0; ?>">
                            </div>
                        </div>
                    </td>
                    <td><span class="row-status <?php echo $rowStatus; ?>"><?php echo $rowStatusText; ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CLASS_ID    = <?php echo $class_id; ?>;
const STUDENT_ID  = <?php echo $student_id; ?>;

const lineLabels  = <?php echo json_encode($chartLabels); ?>;
const myLineData  = <?php echo json_encode($myLineData); ?>;
const avgLineData = <?php echo json_encode($avgLineData); ?>;
const barData     = <?php echo json_encode($myLineData); ?>;

<?php
$notSubmitted = count($assessments) - $submitted;
$pendingCount = count($assessments) - $graded - $notSubmitted;
?>
const donutData   = [<?php echo $graded; ?>, <?php echo $submitted - $graded; ?>, <?php echo max(0, $notSubmitted); ?>];
const donutLabels = ['Graded', 'Submitted', 'Not Submitted'];
const donutColors = ['#28a745', '#3f87b5', '#dee2e6'];

// ── Line chart: me vs class avg ──
const lineCtx = document.getElementById('lineChart').getContext('2d');
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
        animation: { duration: 800 },
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
            tooltip: { callbacks: { label: ctx => ` ${ctx.dataset.label}: ${ctx.parsed.y !== null ? ctx.parsed.y + '%' : 'N/A'}` } }
        },
        scales: {
            y: {
                min: 0, max: 100,
                ticks: { callback: v => v + '%', font: { size: 11 } },
                grid: { color: '#f0f0f0' }
            },
            x: { ticks: { font: { size: 11 } }, grid: { display: false } }
        }
    }
});

// ── Bar chart ──
const barCtx = document.getElementById('barChart').getContext('2d');
const barChart = new Chart(barCtx, {
    type: 'bar',
    data: {
        labels: lineLabels,
        datasets: [{
            label: 'My Score (%)',
            data: barData,
            backgroundColor: barData.map(v =>
                v === null ? '#dee2e6' : v >= 75 ? '#28a74588' : v >= 60 ? '#f0a50088' : '#dc354588'
            ),
            borderColor: barData.map(v =>
                v === null ? '#dee2e6' : v >= 75 ? '#28a745' : v >= 60 ? '#f0a500' : '#dc3545'
            ),
            borderWidth: 2,
            borderRadius: 6,
        }]
    },
    options: {
        responsive: true,
        animation: { duration: 800 },
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => ` My score: ${ctx.parsed.y !== null ? ctx.parsed.y + '%' : 'N/A'}` } }
        },
        scales: {
            y: {
                min: 0, max: 100,
                ticks: { callback: v => v + '%', font: { size: 11 } },
                grid: { color: '#f0f0f0' }
            },
            x: { ticks: { font: { size: 11 } }, grid: { display: false } }
        }
    }
});

// ── Donut chart ──
const donutCtx = document.getElementById('donutChart').getContext('2d');
const donutChart = new Chart(donutCtx, {
    type: 'doughnut',
    data: {
        labels: donutLabels,
        datasets: [{
            data: donutData,
            backgroundColor: donutColors,
            borderWidth: 2, borderColor: '#fff', hoverOffset: 8,
        }]
    },
    options: {
        responsive: true,
        animation: { animateRotate: true, duration: 900 },
        plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 }, padding: 10 } },
            tooltip: { callbacks: { label: ctx => ` ${ctx.label}: ${ctx.parsed}` } }
        },
        cutout: '62%',
    }
});

// ── Animate progress bars ──
function animateBars(){
    document.querySelectorAll('.compare-bar-fill').forEach(bar => {
        setTimeout(() => bar.style.width = bar.dataset.width + '%', 100);
    });
}
document.addEventListener('DOMContentLoaded', animateBars);

// ── Live refresh every 30s ──
setInterval(refreshData, 30000);

async function refreshData(){
    const btn  = document.getElementById('refreshBtn');
    const icon = document.getElementById('refreshIcon');
    btn.classList.add('spinning');
    try {
        const r = await fetch(`student_performance_data.php?class_id=${CLASS_ID}&student_id=${STUDENT_ID}&t=${Date.now()}`);
        const d = await r.json();
        if(!d.success){ btn.classList.remove('spinning'); return; }

        // Update overall pct text
        document.getElementById('overallPct').textContent  = d.overallPct !== null ? d.overallPct + '%' : '—';
        document.getElementById('statusBadge').textContent = d.status;
        document.getElementById('statusBadge').className   = 'status-badge ' + d.statusClass;
        document.getElementById('statGraded').textContent    = d.graded;
        document.getElementById('statSubmitted').textContent = d.submitted;

        // Update line chart
        lineChart.data.datasets[0].data = d.myLineData;
        lineChart.data.datasets[1].data = d.avgLineData;
        lineChart.update('active');

        // Update bar
        barChart.data.datasets[0].data = d.myLineData;
        barChart.update('active');

        // Update donut
        donutChart.data.datasets[0].data = d.donutData;
        donutChart.update('active');

        // Update table
        document.getElementById('assessTableBody').innerHTML = d.tableHtml;
        animateBars();

    } catch(e){ console.error('Refresh error:', e); }
    btn.classList.remove('spinning');
}

function toggleSidebar(){
    document.getElementById('sidebar').classList.toggle('collapsed');
    document.getElementById('mainContent').classList.toggle('expanded');
}
function mobileSidebar(){
    document.getElementById('sidebar').classList.toggle('show');
}
</script>
</body>
</html>