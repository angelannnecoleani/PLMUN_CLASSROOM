<?php
session_start();
include 'database.php';
header('Content-Type: application/json');

$teacher_id = $_SESSION['user_id'];
$class_id   = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;

if(!$class_id){
    echo json_encode(['success' => false, 'error' => 'Missing class_id']);
    exit;
}

// Verify ownership
$check = mysqli_prepare($conn, "SELECT id FROM classes WHERE id = ? AND teacher_id = ?");
mysqli_stmt_bind_param($check, "ii", $class_id, $teacher_id);
mysqli_stmt_execute($check);
if(mysqli_stmt_num_rows($check) === 0){
    echo json_encode(['success' => false, 'error' => 'Not authorised']);
    exit;
}

// Students
$sStmt = mysqli_prepare($conn, "
    SELECT u.id, u.name, u.profile_image
    FROM class_students cs
    INNER JOIN users u ON cs.student_id = u.id
    WHERE cs.class_id = ? ORDER BY u.name ASC
");
mysqli_stmt_bind_param($sStmt, "i", $class_id);
mysqli_stmt_execute($sStmt);
$students = mysqli_stmt_get_result($sStmt)->fetch_all(MYSQLI_ASSOC);

// Assessments
$aStmt = mysqli_prepare($conn, "SELECT id, title, points FROM assessments WHERE class_id = ? ORDER BY id ASC");
mysqli_stmt_bind_param($aStmt, "i", $class_id);
mysqli_stmt_execute($aStmt);
$assessments = mysqli_stmt_get_result($aStmt)->fetch_all(MYSQLI_ASSOC);

// Grades
$gStmt = mysqli_prepare($conn, "
    SELECT s.student_id, s.assessment_id, s.grade
    FROM submissions s
    INNER JOIN assessments a ON s.assessment_id = a.id
    WHERE a.class_id = ?
");
mysqli_stmt_bind_param($gStmt, "i", $class_id);
mysqli_stmt_execute($gStmt);
$gradeRows = mysqli_stmt_get_result($gStmt)->fetch_all(MYSQLI_ASSOC);
$grades = [];
foreach($gradeRows as $g) $grades[$g['student_id']][$g['assessment_id']] = $g['grade'];

// Per-student average
$studentPerf = [];
foreach($students as $s){
    $total = 0; $maxTotal = 0; $count = 0;
    foreach($assessments as $a){
        $g = $grades[$s['id']][$a['id']] ?? null;
        if($g !== null){ $total += $g; $maxTotal += $a['points']; $count++; }
    }
    $pct = ($maxTotal > 0) ? round(($total / $maxTotal) * 100, 1) : null;
    $studentPerf[$s['id']] = [
        'pct'            => $pct,
        'graded'         => $count,
        'total_assessed' => count($assessments),
    ];
}

// Summary counts
$counts = ['excellent'=>0,'good'=>0,'average'=>0,'needs_help'=>0,'ungraded'=>0];
foreach($studentPerf as $sp){
    if($sp['pct'] === null)       { $counts['ungraded']++;   continue; }
    if($sp['pct'] >= 90)          $counts['excellent']++;
    elseif($sp['pct'] >= 75)      $counts['good']++;
    elseif($sp['pct'] >= 60)      $counts['average']++;
    else                          $counts['needs_help']++;
}

// Line chart datasets
$colors = ['#3f87b5','#28a745','#f0a500','#dc3545','#7b5fc4','#17a2b8','#e83e8c','#fd7e14','#6c757d','#20c997'];
$lineDatasets = [];
foreach($students as $i => $s){
    $pts = [];
    foreach($assessments as $a){
        $g   = $grades[$s['id']][$a['id']] ?? null;
        $pts[] = ($g !== null && $a['points'] > 0) ? round(($g / $a['points']) * 100, 1) : null;
    }
    $color = $colors[$i % count($colors)];
    $lineDatasets[] = ['label' => $s['name'], 'data' => $pts, 'borderColor' => $color];
}

// Bar data (class average per assessment)
$barData = [];
foreach($assessments as $a){
    $total = 0; $count = 0;
    foreach($students as $s){
        $g = $grades[$s['id']][$a['id']] ?? null;
        if($g !== null && $a['points'] > 0){ $total += ($g / $a['points']) * 100; $count++; }
    }
    $barData[] = $count > 0 ? round($total / $count, 1) : 0;
}

// Donut data
$donutData = [$counts['excellent'], $counts['good'], $counts['average'], $counts['needs_help'], $counts['ungraded']];

// Student list HTML
$sortedStudents = $students;
usort($sortedStudents, function($a, $b) use ($studentPerf){
    return ($studentPerf[$b['id']]['pct'] ?? -1) <=> ($studentPerf[$a['id']]['pct'] ?? -1);
});

ob_start();
foreach($sortedStudents as $rank => $s):
    $sp  = $studentPerf[$s['id']];
    $pct = $sp['pct'];
    if($pct === null){
        $barClass=''; $badgeClass='ungraded'; $badgeText='No grades yet'; $bw=0;
    } elseif($pct >= 90){
        $barClass='high'; $badgeClass='excellent'; $badgeText='⭐ Excellent'; $bw=$pct;
    } elseif($pct >= 75){
        $barClass='high'; $badgeClass='good'; $badgeText='✅ Good'; $bw=$pct;
    } elseif($pct >= 60){
        $barClass='mid'; $badgeClass='average'; $badgeText='📈 Average'; $bw=$pct;
    } else {
        $barClass='low'; $badgeClass='needs-help'; $badgeText='⚠️ Needs Help'; $bw=$pct;
    }
    $avatar = !empty($s['profile_image'])
        ? '<img src="uploads/'.htmlspecialchars($s['profile_image']).'" class="s-avatar" alt="">'
        : '<div class="s-avatar"><i class="fa-solid fa-user"></i></div>';
    echo '<div class="student-perf-row">';
    echo $avatar;
    echo '<div class="s-info"><div class="s-name">#'.($rank+1).' '.htmlspecialchars($s['name']).'</div>';
    echo '<div class="s-sub">'.$sp['graded'].'/'.$sp['total_assessed'].' assessed</div></div>';
    echo '<div class="bar-wrap"><div class="bar-fill '.$barClass.'" style="width:0" data-width="'.$bw.'"></div></div>';
    echo '<div class="score-pct">'.($pct !== null ? $pct.'%' : '—').'</div>';
    echo '<span class="status-badge '.$badgeClass.'">'.$badgeText.'</span>';
    echo '</div>';
endforeach;
$studentListHtml = ob_get_clean();

echo json_encode([
    'success'         => true,
    'counts'          => $counts,
    'lineDatasets'    => $lineDatasets,
    'barData'         => $barData,
    'donutData'       => $donutData,
    'studentListHtml' => $studentListHtml,
]);
?>