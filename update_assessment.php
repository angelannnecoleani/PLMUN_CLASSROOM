<?php
session_start();
include 'database.php';
require_once 'assessment_attachments.php';

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    header("Location: teacher_classpage.php");
    exit();
}

$assessment_id = intval($_POST['id'] ?? 0);
if($assessment_id <= 0) die("Assessment not found");

// Load the assessment (class_id comes from the database, not the form)
$s = mysqli_prepare($conn, "SELECT class_id FROM assessments WHERE id = ?");
mysqli_stmt_bind_param($s, "i", $assessment_id);
mysqli_stmt_execute($s);
$existing = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
mysqli_stmt_close($s);

if(!$existing) die("Assessment not found");
$class_id = intval($existing['class_id']);

$type         = trim($_POST['type'] ?? 'Quiz');
$title        = trim($_POST['title'] ?? '');
$instructions = trim($_POST['instructions'] ?? '');
$points       = intval($_POST['points'] ?? 100);
$due_date     = aa_combine_datetime($_POST['due_date'] ?? '', $_POST['due_time'] ?? '');

if($title === '') die("Title is required.");

$upload_dir = 'uploads/';

/* 0 Old assessments: copy their single image/file/link into the new table first */
aa_ensure_migrated($conn, $assessment_id);

/* 1 Update the main fields */
$stmt = mysqli_prepare(
    $conn,
    "UPDATE assessments
     SET type = ?, title = ?, instructions = ?, due_date = ?, points = ?
     WHERE id = ?"
);
mysqli_stmt_bind_param($stmt, "ssssii", $type, $title, $instructions, $due_date, $points, $assessment_id);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

/* 2 Optional columns (saved only if they exist in your table) */
$start_date = aa_combine_datetime($_POST['start_date'] ?? '', $_POST['start_time'] ?? '');
aa_update_optional($conn, $assessment_id, [
    'start_date' => $start_date,
]);

/* 3 Remove attachments the teacher took off */
aa_remove($conn, $assessment_id, $_POST['remove_attachments'] ?? [], $upload_dir);

/* 4 Add new attachments (limit of 10 counts what is left after removals) */
aa_save_new($conn, $assessment_id, $upload_dir);

/* 5 Keep the old single image/file/link columns in sync */
aa_sync_legacy($conn, $assessment_id);

header("Location: teacher_assessment.php?id=" . $class_id);
exit();
?>