<?php
session_start();
include 'database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$teacher_id = $_SESSION['user_id'];
$class_id   = isset($_POST['class_id']) ? intval($_POST['class_id']) : 0;
$meet_link  = isset($_POST['meet_link']) ? trim($_POST['meet_link']) : '';

if ($class_id <= 0 || $meet_link === '') {
    echo json_encode(['success' => false, 'error' => 'Missing data']);
    exit();
}

// tanggapin lang kung valid Google Meet link ang format
if (!preg_match('#^https://meet\.google\.com/[a-z0-9\-]+$#i', $meet_link)) {
    echo json_encode(['success' => false, 'error' => 'Invalid Google Meet link']);
    exit();
}

// siguraduhin sa teacher na naka-login ang may-ari ng class na 'to
$stmt = mysqli_prepare($conn, "SELECT id FROM classes WHERE id = ? AND teacher_id = ?");
mysqli_stmt_bind_param($stmt, "ii", $class_id, $teacher_id);
mysqli_stmt_execute($stmt);
mysqli_stmt_store_result($stmt);

if (mysqli_stmt_num_rows($stmt) === 0) {
    echo json_encode(['success' => false, 'error' => 'Class not found or access denied']);
    exit();
}
mysqli_stmt_close($stmt);

$update = mysqli_prepare($conn, "UPDATE classes SET meet_link = ? WHERE id = ?");
mysqli_stmt_bind_param($update, "si", $meet_link, $class_id);

if (mysqli_stmt_execute($update)) {
    echo json_encode(['success' => true, 'meet_link' => $meet_link]);
} else {
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
mysqli_stmt_close($update);