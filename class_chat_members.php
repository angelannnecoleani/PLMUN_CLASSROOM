<?php
/* People who can be @mentioned in a class chat (teacher + enrolled students). */
session_start();
include 'database.php';
include 'class_chat_mentions_lib.php';
header('Content-Type: application/json');

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if (!isset($_SESSION['user_id'])) out(['success' => false, 'error' => 'Not logged in'], 401);

$uid      = (int)$_SESSION['user_id'];
$class_id = (int)($_GET['class_id'] ?? 0);
if ($class_id <= 0) out(['success' => false, 'error' => 'Invalid class'], 400);

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

out(['success' => true, 'members' => chat_class_members($conn, $class_id)]);