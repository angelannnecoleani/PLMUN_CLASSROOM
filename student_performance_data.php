<?php
session_start();
include 'database.php';
header('Content-Type: application/json');

$student_id = $_SESSION['user_id'];
$class_id   = isset($_GET['class_id'])   ? intval($_GET['class_id'])   : 0;

if(!$class_id){
    echo json_encode(['success'=>false]); exit;
}

// Verify enrollment
$check = mysqli_prepare($conn, "SELECT 1 FROM class_students WHERE class_id=? AND student_id=?");
mysqli_stmt_bind_param($check, "ii", $class_id, $student_id);
mysqli_stmt_execute($check);
mysqli_stmt_store_result($check);
if(mysqli_stmt_num_rows($check) === 0){ echo json_encode(['success'=>false]); exit; }

// Assessments
$aStmt = mysqli_prepare($conn, "SELECT id, title, points, type, due_date FROM assessments WHERE class_id=? ORDER BY id ASC");
mysqli_stmt_bind_param($aStmt, "i", $class_id);
mysqli_stmt_execute($aStmt);
$assessments = mysqli_stmt_get_result($aStmt)->fetch_all(MYSQLI_ASSOC);

// My grades
$gStmt = mysqli_prepare($conn, "
    SELECT s.assessment_id, s.grade, s.status
    FROM submissions s
    WHERE s.student_id=? AND s.assessment_id IN (SELECT id FROM assessments WHERE class_id=?)
");
mysqli_stmt_bind_param($gStmt, "ii", $student_id, $class_id);
mysqli_stmt_execute($gStmt);
$myGrades = [];
foreach(mysqli_stmt_get_result($gStmt)->fetch_all(MYSQLI_ASSOC) as $g)
    $myGrades[$g['assessment_id']] = $g;

// Class averages
$avgStmt = mysqli_prepare($conn, "
    SELECT s.assessment_id, AVG(s.grade) AS avg_grade
    FROM submissions s INNER JOIN assessments a ON s.assessment_id=a.id
    WHERE a.class_id=? AND s.grade IS NOT NULL GROUP BY s.assessment_id
");
mysqli_stmt_bind_param($avgStmt, "i", $class_id);
mysqli_stmt_execute($avgStmt);
$classAvg = [];
foreach(mysqli_stmt_get_result($avgStmt)->fetch_all(MYSQLI_ASSOC) as $r)
    $classAvg[$r['assessment_id']] = round($r['avg_grade'], 1);

// Compute stats
$myTotal=0; $myMax=0; $graded=0; $submitted=0;
$myLineData=[]; $avgLineData=[];
foreach($assessments as $a){
    $g = $myGrades[$a['id']] ?? null;
    if($g && $g['grade'] !== null && $a['points'] > 0){
        $pct = round(($g['grade']/$a['points'])*100,1);
        $myLineData[] = $pct;
        $myTotal += $g['grade']; $myMax += $a['points']; $graded++;
    } else { $myLineData[] = null; }
    $avgLineData[] = isset($classAvg[$a['id']]) && $a['points']>0
        ? round(($classAvg[$a['id']]/$a['points'])*100,1) : null;
    if($g && in_array($g['status'],['submitted','done','graded'])) $submitted++;
}
$overallPct = $myMax>0 ? round(($myTotal/$myMax)*100,1) : null;

if($overallPct===null)     { $status='No grades yet'; $statusClass='ungraded'; }
elseif($overallPct>=90)    { $status='⭐ Excellent';  $statusClass='excellent'; }
elseif($overallPct>=75)    { $status='✅ Good';        $statusClass='good'; }
elseif($overallPct>=60)    { $status='📈 Average';    $statusClass='average'; }
else                       { $status='⚠️ Needs Help'; $statusClass='needs-help'; }

$notSubmitted = count($assessments) - $submitted;
$donutData = [$graded, max(0,$submitted-$graded), max(0,$notSubmitted)];

// Build table HTML
ob_start();
foreach($assessments as $a):
    $g   = $myGrades[$a['id']] ?? null;
    $myG = ($g && $g['grade']!==null) ? $g['grade'] : null;
    $myP = ($myG!==null && $a['points']>0) ? round(($myG/$a['points'])*100,1) : null;
    $avgP= isset($classAvg[$a['id']]) && $a['points']>0 ? round(($classAvg[$a['id']]/$a['points'])*100,1) : null;
    $gradeCls = $myP===null?'none':($myP>=75?'high':($myP>=60?'mid':'low'));
    $barColor = $myP===null?'#eef2f7':($myP>=75?'#28a745':($myP>=60?'#f0a500':'#dc3545'));
    $diff     = ($myP!==null&&$avgP!==null) ? round($myP-$avgP,1) : null;
    $vsClass  = $diff===null?'same':($diff>0?'above':($diff<0?'below':'same'));
    $vsText   = $diff===null?'—':($diff>0?'+'.$diff.'%':$diff.'%');
    $rowStatus='no-submit'; $rowStatusText='Not submitted';
    if($g){
        if($myG!==null)                                    { $rowStatus='graded';    $rowStatusText='Graded'; }
        elseif(in_array($g['status'],['submitted','done'])){ $rowStatus='submitted'; $rowStatusText='Submitted'; }
        elseif($g['status']==='missing')                   { $rowStatus='missing';   $rowStatusText='Missing'; }
    }
    echo '<tr>';
    echo '<td><strong>'.htmlspecialchars($a['title']).'</strong><div style="font-size:11px;color:#aaa;">Due '.date('M d, Y',strtotime($a['due_date'])).'</div></td>';
    echo '<td><span class="type-badge">'.htmlspecialchars($a['type']).'</span></td>';
    echo '<td><span class="grade-cell '.$gradeCls.'">'.($myG!==null?$myG.'/'.$a['points']:'—').'</span>'.($myP!==null?'<div style="font-size:11px;color:#aaa;">'.$myP.'%</div>':'').'</td>';
    echo '<td style="color:#888;font-size:13px;">'.($avgP!==null?$avgP.'%':'—').'</td>';
    echo '<td><span class="vs-avg '.$vsClass.'">'.$vsText.'</span></td>';
    echo '<td><div class="compare-bar-wrap"><div class="compare-bar-fill" style="width:0;background:'.$barColor.'" data-width="'.($myP??0).'"></div></div></td>';
    echo '<td><span class="row-status '.$rowStatus.'">'.$rowStatusText.'</span></td>';
    echo '</tr>';
endforeach;
$tableHtml = ob_get_clean();

echo json_encode([
    'success'     => true,
    'overallPct'  => $overallPct,
    'status'      => $status,
    'statusClass' => $statusClass,
    'graded'      => $graded,
    'submitted'   => $submitted,
    'myLineData'  => $myLineData,
    'avgLineData' => $avgLineData,
    'donutData'   => $donutData,
    'tableHtml'   => $tableHtml,
]);
?>