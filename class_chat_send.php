
<?php
session_start();

require_once 'database.php';
require_once 'auth_helpers.php';
require_once 'class_chat_mentions_lib.php';

header('Content-Type: application/json; charset=utf-8');

function fail($code, $msg) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

/* ==========================================
   LOGIN VALIDATION
========================================== */

if (empty($_SESSION['user_id'])) {
    fail(401, 'Not logged in');
}

$uid = (int) $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

if (!in_array($role, ['teacher', 'program_chair', 'student'], true)) {
    fail(403, 'You are not allowed to use this chat');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Invalid request method');
}

/* ==========================================
   GET MESSAGE DATA
========================================== */

$class_id = (int) ($_POST['class_id'] ?? 0);
$message = trim($_POST['message'] ?? '');

if ($class_id <= 0) {
    fail(400, 'Missing class_id');
}

if (mb_strlen($message) > 2000) {
    fail(400, 'Message too long');
}

/* ==========================================
   VERIFY CLASS MEMBERSHIP
   TEACHER: PRIMARY OR CO-INSTRUCTOR
   CHAIR: EXISTING ACCESS HELPER
   STUDENT: ENROLLED STUDENT
========================================== */

$hasAccess = false;

if ($role === 'teacher') {

    $check = mysqli_prepare(
        $conn,
        "SELECT c.id
         FROM classes c
         WHERE c.id = ?
           AND (
                c.teacher_id = ?
                OR EXISTS (
                    SELECT 1
                    FROM class_instructors ci
                    WHERE ci.class_id = c.id
                      AND ci.teacher_id = ?
                )
           )
         LIMIT 1"
    );

    if (!$check) {
        fail(500, 'Database error while checking instructor access');
    }

    mysqli_stmt_bind_param($check, "iii", $class_id, $uid, $uid);
    mysqli_stmt_execute($check);

    $result = mysqli_stmt_get_result($check);
    $hasAccess = mysqli_num_rows($result) > 0;

    mysqli_stmt_close($check);

} elseif ($role === 'program_chair') {

    $hasAccess = (bool) getClassAccess(
        $conn,
        $class_id,
        $uid,
        $role
    );

} elseif ($role === 'student') {

    $check = mysqli_prepare(
        $conn,
        "SELECT 1
         FROM class_students
         WHERE class_id = ?
           AND student_id = ?
           AND (archived = 0 OR archived IS NULL)
         LIMIT 1"
    );

    if (!$check) {
        fail(500, 'Database error while checking student access');
    }

    mysqli_stmt_bind_param($check, "ii", $class_id, $uid);
    mysqli_stmt_execute($check);

    $result = mysqli_stmt_get_result($check);
    $hasAccess = mysqli_num_rows($result) > 0;

    mysqli_stmt_close($check);
}

if (!$hasAccess) {
    fail(403, 'Not a member of this class');
}

/* ==========================================
   OPTIONAL ATTACHMENT
========================================== */

$attachment = null;
$attName = null;
$attType = null;
$savedFilePath = null;

if (
    isset($_FILES['attachment']) &&
    $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE
) {
    $f = $_FILES['attachment'];

    if ($f['error'] !== UPLOAD_ERR_OK) {
        fail(400, 'Upload failed (error ' . $f['error'] . ')');
    }

    if ($f['size'] > 10 * 1024 * 1024) {
        fail(400, 'File too large (max 10MB)');
    }

    if (!is_uploaded_file($f['tmp_name'])) {
        fail(400, 'Invalid uploaded file');
    }

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));

    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    $fileExts = [
        'pdf', 'doc', 'docx', 'ppt', 'pptx',
        'xls', 'xlsx', 'csv', 'txt', 'zip', 'rar'
    ];

    if (in_array($ext, $imageExts, true)) {

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($f['tmp_name']);

        $allowedImageMimes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp'
        ];

        if (!in_array($mime, $allowedImageMimes, true)) {
            fail(400, 'Invalid image file');
        }

        $attType = 'image';

    } elseif (in_array($ext, $fileExts, true)) {

        $attType = 'file';

    } else {

        fail(400, 'File type not allowed');
    }

    $dir = __DIR__ . '/uploads/chat/';

    if (
        !is_dir($dir) &&
        !mkdir($dir, 0755, true) &&
        !is_dir($dir)
    ) {
        fail(500, 'Unable to create upload directory');
    }

    $newName = bin2hex(random_bytes(12)) . '.' . $ext;
    $savedFilePath = $dir . $newName;

    if (!move_uploaded_file($f['tmp_name'], $savedFilePath)) {
        fail(500, 'Could not save file');
    }

    $attachment = 'chat/' . $newName;
    $attName = mb_substr(basename($f['name']), 0, 200);
}

/* ==========================================
   VALIDATE MESSAGE
========================================== */

if ($message === '' && $attachment === null) {
    if ($savedFilePath && is_file($savedFilePath)) {
        unlink($savedFilePath);
    }

    fail(400, 'Empty message');
}

/* ==========================================
   SAVE MESSAGE
========================================== */

$stmt = mysqli_prepare(
    $conn,
    "INSERT INTO class_chat_messages
        (
            class_id,
            sender_id,
            message,
            attachment,
            attachment_name,
            attachment_type
        )
     VALUES (?, ?, ?, ?, ?, ?)"
);

if (!$stmt) {
    if ($savedFilePath && is_file($savedFilePath)) {
        unlink($savedFilePath);
    }

    fail(500, 'Database error while preparing message');
}

mysqli_stmt_bind_param(
    $stmt,
    "iissss",
    $class_id,
    $uid,
    $message,
    $attachment,
    $attName,
    $attType
);

if (!mysqli_stmt_execute($stmt)) {
    $error = mysqli_stmt_error($stmt);
    mysqli_stmt_close($stmt);

    if ($savedFilePath && is_file($savedFilePath)) {
        unlink($savedFilePath);
    }

    fail(500, 'Failed to save message: ' . $error);
}

$newId = mysqli_insert_id($conn);
mysqli_stmt_close($stmt);

/* ==========================================
   SAVE @MENTIONS
========================================== */

chat_sync_mentions(
    $conn,
    $newId,
    $class_id,
    $uid,
    $message
);

echo json_encode([
    'success' => true,
    'id' => $newId
]);
