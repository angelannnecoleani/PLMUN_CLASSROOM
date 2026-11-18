<?php
session_start();
include 'database.php';

// Require login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (!isset($_GET['id'])) {
    die("Assessment not found");
}

$assessment_id = intval($_GET['id']);
$teacher_id    = $_SESSION['user_id'];

/* Verify this assessment belongs to a class taught by the logged-in teacher.
   This also gives us a trustworthy class_id for the redirect. */
$check = mysqli_prepare($conn, "
    SELECT a.id, a.class_id
    FROM assessments a
    INNER JOIN classes c ON c.id = a.class_id
    WHERE a.id = ? AND c.teacher_id = ?
");
mysqli_stmt_bind_param($check, "ii", $assessment_id, $teacher_id);
mysqli_stmt_execute($check);
$assessment = mysqli_fetch_assoc(mysqli_stmt_get_result($check));
mysqli_stmt_close($check);

if (!$assessment) {
    die("Access denied");
}

$class_id = $assessment['class_id'];

/* Delete dependent submissions first (skip this if your FK is ON DELETE CASCADE) */
$delSubs = mysqli_prepare($conn, "DELETE FROM submissions WHERE assessment_id = ?");
mysqli_stmt_bind_param($delSubs, "i", $assessment_id);
mysqli_stmt_execute($delSubs);
mysqli_stmt_close($delSubs);

/* Delete the assessment itself */
$stmt = mysqli_prepare($conn, "DELETE FROM assessments WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $assessment_id);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

header("Location: teacher_assessment.php?id=" . intval($class_id));
exit();
?>