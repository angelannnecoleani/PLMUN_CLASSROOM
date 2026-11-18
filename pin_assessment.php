<?php
session_start();
include 'database.php';

if(empty($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}
$teacher_id = intval($_SESSION['user_id']);

if(!isset($_GET['id'])){
    die("Assessment not found");
}
$assessment_id = intval($_GET['id']);

// Fetch the assessment + its class, verifying this teacher owns that class
$query = mysqli_query($conn, "
    SELECT assessments.id, assessments.pinned, assessments.class_id
    FROM assessments
    INNER JOIN classes ON assessments.class_id = classes.id
    WHERE assessments.id = '$assessment_id' AND classes.teacher_id = '$teacher_id'
");
$assessment = mysqli_fetch_assoc($query);
if(!$assessment) die("Assessment not found or you do not have access to it.");

$class_id = intval($assessment['class_id']);
$newPinned = $assessment['pinned'] ? 0 : 1;

mysqli_query($conn, "UPDATE assessments SET pinned = '$newPinned' WHERE id = '$assessment_id'");

header("Location: teacher_class.php?id=" . $class_id);
exit;