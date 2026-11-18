<?php
session_start();
include 'database.php';

header('Content-Type: application/json');

if(!isset($_SESSION['user_id'])){
    echo json_encode(['success' => false, 'message' => 'You must be logged in.']);
    exit();
}

$student_id = $_SESSION['user_id'];
$class_id   = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;

if($class_id <= 0){
    echo json_encode(['success' => false, 'message' => 'Invalid class.']);
    exit();
}

/* Confirm the student is actually enrolled in this class */
$check = mysqli_prepare(
    $conn,
    "SELECT 1 FROM class_students WHERE student_id = ? AND class_id = ?"
);
mysqli_stmt_bind_param($check, "ii", $student_id, $class_id);
mysqli_stmt_execute($check);
mysqli_stmt_store_result($check);

if(mysqli_stmt_num_rows($check) === 0){
    echo json_encode(['success' => false, 'message' => 'You are not enrolled in this class.']);
    mysqli_stmt_close($check);
    exit();
}
mysqli_stmt_close($check);

/* Bring it back to the main dashboard */
$stmt = mysqli_prepare(
    $conn,
    "UPDATE class_students SET archived = 0 WHERE student_id = ? AND class_id = ?"
);
mysqli_stmt_bind_param($stmt, "ii", $student_id, $class_id);

if(mysqli_stmt_execute($stmt)){
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Could not unarchive this class.']);
}

mysqli_stmt_close($stmt);
mysqli_close($conn);