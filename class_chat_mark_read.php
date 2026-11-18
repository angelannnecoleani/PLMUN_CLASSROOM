<?php
/* Marks a class chat as read up to a given message id for the logged-in user. */
session_start();
include 'database.php';
header('Content-Type: application/json');

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if (!isset($_SESSION['user_id'])) out(['success' => false, 'error' => 'Not logged in'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['success' => false, 'error' => 'POST only'], 405);

$uid      = (int)$_SESSION['user_id'];
$class_id = (int)($_POST['class_id'] ?? 0);
$last_id  = (int)($_POST['last_id'] ?? 0);

if ($class_id <= 0 || $last_id <= 0) out(['success' => false, 'error' => 'Invalid request'], 400);

// Must be the class teacher or an enrolled student
$stmt = mysqli_prepare($conn, "
    SELECT 1 FROM classes WHERE id = ? AND teacher_id = ?
    UNION
    SELECT 1 FROM class_students WHERE class_id = ? AND student_id = ?
");
mysqli_stmt_bind_param($stmt, 'iiii', $class_id, $uid, $class_id, $uid);
mysqli_stmt_execute($stmt);
$allowed = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$allowed) out(['success' => false, 'error' => 'Not allowed'], 403);

// Only ever move the marker forward
$stmt = mysqli_prepare($conn, "
    INSERT INTO class_chat_reads (user_id, class_id, last_read_id)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id))
");
mysqli_stmt_bind_param($stmt, 'iii', $uid, $class_id, $last_id);
$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

out(['success' => (bool)$ok]);