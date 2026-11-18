<?php
session_start();
include 'database.php';
include 'class_chat_mentions_lib.php';   // NEW
header('Content-Type: application/json');

function fail($code, $msg) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

if (!isset($_SESSION['user_id'])) fail(401, 'Not logged in');

$class_id = isset($_POST['class_id']) ? intval($_POST['class_id']) : 0;
$message  = isset($_POST['message']) ? trim($_POST['message']) : '';
$uid      = $_SESSION['user_id'];

if ($class_id <= 0) fail(400, 'Missing class_id');
if (mb_strlen($message) > 2000) fail(400, 'Message too long');

// Confirm membership (teacher or enrolled student)
$check = mysqli_prepare($conn, "
    SELECT 1 FROM classes WHERE id = ? AND teacher_id = ?
    UNION
    SELECT 1 FROM class_students WHERE class_id = ? AND student_id = ?
");
mysqli_stmt_bind_param($check, "iiii", $class_id, $uid, $class_id, $uid);
mysqli_stmt_execute($check);
mysqli_stmt_store_result($check);
if (mysqli_stmt_num_rows($check) === 0) fail(403, 'Not a member of this class');
mysqli_stmt_close($check);

/* ---- optional attachment ---- */
$attachment = null;
$attName    = null;
$attType    = null;

if (!empty($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
    $f = $_FILES['attachment'];

    if ($f['error'] !== UPLOAD_ERR_OK) fail(400, 'Upload failed (error ' . $f['error'] . ')');
    if ($f['size'] > 10 * 1024 * 1024) fail(400, 'File too large (max 10MB)');

    $ext       = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $fileExts  = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'csv', 'txt', 'zip', 'rar'];

    if (in_array($ext, $imageExts, true)) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (strpos($mime, 'image/') !== 0) fail(400, 'Invalid image');
        $attType = 'image';
    } elseif (in_array($ext, $fileExts, true)) {
        $attType = 'file';
    } else {
        fail(400, 'File type not allowed');
    }

    $dir = __DIR__ . '/uploads/chat/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $newName = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $newName)) fail(500, 'Could not save file');

    $attachment = 'chat/' . $newName;
    $attName    = mb_substr(basename($f['name']), 0, 200);
}

if ($message === '' && $attachment === null) fail(400, 'Empty message');

$stmt = mysqli_prepare($conn, "
    INSERT INTO class_chat_messages
        (class_id, sender_id, message, attachment, attachment_name, attachment_type)
    VALUES (?, ?, ?, ?, ?, ?)
");
mysqli_stmt_bind_param($stmt, "iissss", $class_id, $uid, $message, $attachment, $attName, $attType);

if (mysqli_stmt_execute($stmt)) {
    $newId = mysqli_insert_id($conn);
    // NEW: record who was @mentioned
    chat_sync_mentions($conn, $newId, $class_id, $uid, $message);
    echo json_encode(['success' => true, 'id' => $newId]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save message']);
}