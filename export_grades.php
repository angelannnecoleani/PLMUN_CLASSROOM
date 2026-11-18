<?php
session_start();
include 'database.php';

if(!isset($_GET['id'])) die("Class not found");
$class_id   = intval($_GET['id']);
$teacher_id = $_SESSION['user_id'];

// Verify ownership
$check = mysqli_prepare($conn, "SELECT class_name, section FROM classes WHERE id = ? AND teacher_id = ?");
mysqli_stmt_bind_param($check, "ii", $class_id, $teacher_id);
mysqli_stmt_execute($check);
$class = mysqli_fetch_assoc(mysqli_stmt_get_result($check));
if(!$class) die("Not authorised");

// Students
$sStmt = mysqli_prepare($conn, "
    SELECT u.id, u.name FROM class_students cs
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
    SELECT s.student_id, s.assessment_id, s.grade FROM submissions s
    INNER JOIN assessments a ON s.assessment_id = a.id
    WHERE a.class_id = ?
");
mysqli_stmt_bind_param($gStmt, "i", $class_id);
mysqli_stmt_execute($gStmt);
$gradeRows = mysqli_stmt_get_result($gStmt)->fetch_all(MYSQLI_ASSOC);
$grades = [];
foreach($gradeRows as $g) $grades[$g['student_id']][$g['assessment_id']] = $g['grade'];

// Output CSV
$filename = 'grades_' . preg_replace('/[^a-z0-9]/i', '_', $class['class_name']) . '_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');

// Header row
$header = ['Student'];
foreach($assessments as $a) $header[] = $a['title'] . ' (/' . $a['points'] . ')';
fputcsv($out, $header);

// Student rows
foreach($students as $s){
    $row = [$s['name']];
    foreach($assessments as $a){
        $row[] = $grades[$s['id']][$a['id']] ?? '';
    }
    fputcsv($out, $row);
}

fclose($out);
exit;
?>