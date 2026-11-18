<?php

session_start();
include 'database.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "Please log in again."]);
    exit();
}

$student_id = $_SESSION['user_id'];

if (!isset($_POST['class_code']) || trim($_POST['class_code']) === '') {
    echo json_encode(["success" => false, "message" => "Please enter a class code."]);
    exit();
}

$class_code = trim($_POST['class_code']);

/* FIND CLASS */

$stmt = mysqli_prepare($conn, "SELECT * FROM classes WHERE class_code = ?");
mysqli_stmt_bind_param($stmt, "s", $class_code);
mysqli_stmt_execute($stmt);
$classQuery = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($classQuery) == 0) {
    echo json_encode(["success" => false, "message" => "Invalid class code. Please check and try again."]);
    exit();
}

$class = mysqli_fetch_assoc($classQuery);
$class_id = $class['id'];

/* CHECK IF ALREADY JOINED */

$stmt2 = mysqli_prepare($conn, "SELECT * FROM class_students WHERE class_id = ? AND student_id = ?");
mysqli_stmt_bind_param($stmt2, "ii", $class_id, $student_id);
mysqli_stmt_execute($stmt2);
$check = mysqli_stmt_get_result($stmt2);

if (mysqli_num_rows($check) > 0) {
    echo json_encode(["success" => false, "message" => "You have already joined this class."]);
    exit();
}

/* INSERT */

$stmt3 = mysqli_prepare($conn, "INSERT INTO class_students (class_id, student_id) VALUES (?, ?)");
mysqli_stmt_bind_param($stmt3, "ii", $class_id, $course_id, $student_id);
$insertSuccess = mysqli_stmt_execute($stmt3);

if ($insertSuccess) {
    echo json_encode(["success" => true, "class_id" => $class_id]);
}
elseif ($insertSuccess) {
    echo json_encode(["success" => true, "course_id" => $course_id]);
}
 else {
    echo json_encode(["success" => false, "message" => "Something went wrong. Please try again."]);
}