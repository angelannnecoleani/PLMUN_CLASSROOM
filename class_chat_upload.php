<?php
session_start();
include 'database.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$class_id = isset($_POST['class_id']) ? intval($_POST['class_id']) : 0;
$type     = isset($_POST['type']) ? trim($_POST['type']) : '';

if ($class_id <= 0 || !in_array($type, ['image', 'file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$uid = $_SESSION['user_id'];

// Check membership (teacher or student)
$check = mysqli_prepare($conn, "
    SELECT 1 FROM classes WHERE id = ? AND teacher_id = ?
    UNION
    SELECT 1 FROM class_students WHERE class_id = ? AND student_id = ?
");
mysqli_stmt_bind_param($check, "iiii", $class_id, $uid, $class_id, $uid);
mysqli_stmt_execute($check);
mysqli_stmt_store_result($check);

if (mysqli_stmt_num_rows($check) === 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Not a member of this class']);
    exit;
}
mysqli_stmt_close($check);

// Check file was uploaded
if (empty($_FILES['file']['name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded or upload error']);
    exit;
}

// Allowed extensions
$image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$file_exts  = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'mp4', 'mov', 'avi', 'zip'];
$allowed    = ($type === 'image') ? $image_exts : $file_exts;

$original = basename($_FILES['file']['name']);
$ext      = strtolower(pathinfo($original, PATHINFO_EXTENSION));

if (!in_array($ext, $allowed)) {
    echo json_encode(['error' => 'File type not allowed']);
    exit;
}

// 20MB limit
if ($_FILES['file']['size'] > 20 * 1024 * 1024) {
    echo json_encode(['error' => 'File too large. Maximum is 20MB']);
    exit;
}

// Make sure uploads folder exists
if (!is_dir('uploads')) mkdir('uploads', 0755, true);

// Save with unique name
$filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $original);
$dest     = 'uploads/' . $filename;

if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save file']);
    exit;
}

// Store as tagged message so chat renderer knows it's an attachment
$message = '[' . $type . ':' . $filename . ']';

$stmt = mysqli_prepare($conn, "
    INSERT INTO class_chat_messages (class_id, sender_id, message)
    VALUES (?, ?, ?)
");
mysqli_stmt_bind_param($stmt, "iis", $class_id, $uid, $message);

if (mysqli_stmt_execute($stmt)) {
    echo json_encode(['success' => true, 'id' => mysqli_insert_id($conn)]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save message: ' . mysqli_error($conn)]);
}
?>