<?php
session_start();

require_once 'database.php';
require_once 'auth_helpers.php';
require_once 'class_chat_mentions_lib.php';

header('Content-Type: application/json');

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

/* =========================================
   LOGIN AND REQUEST VALIDATION
========================================= */

if (empty($_SESSION['user_id'])) {
    out(['success' => false, 'error' => 'Not logged in'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(['success' => false, 'error' => 'POST only'], 405);
}

$uid  = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

if (!in_array($role, ['teacher', 'program_chair', 'student'], true)) {
    out(['success' => false, 'error' => 'Access denied'], 403);
}

$mid  = (int)($_POST['message_id'] ?? 0);
$text = trim((string)($_POST['message'] ?? ''));

if ($mid <= 0) {
    out(['success' => false, 'error' => 'Invalid message'], 400);
}

if ($text === '') {
    out(['success' => false, 'error' => 'Message cannot be empty'], 400);
}

if (mb_strlen($text) > 5000) {
    out(['success' => false, 'error' => 'Message is too long'], 400);
}

/* =========================================
   GET MESSAGE
   Users can only edit their own messages.
========================================= */

$stmt = mysqli_prepare(
    $conn,
    "SELECT class_id, message
     FROM class_chat_messages
     WHERE id = ?
       AND sender_id = ?
       AND is_deleted = 0
     LIMIT 1"
);

if (!$stmt) {
    out(['success' => false, 'error' => 'Database error'], 500);
}

mysqli_stmt_bind_param($stmt, 'ii', $mid, $uid);
mysqli_stmt_execute($stmt);

$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

mysqli_stmt_close($stmt);

if (!$row) {
    out([
        'success' => false,
        'error' => 'Message not found, or it is not yours'
    ], 404);
}

$class_id = (int)$row['class_id'];

/* =========================================
   VERIFY CLASS ACCESS
========================================= */

$allowed = false;

if ($role === 'teacher') {

    // Primary instructor or assigned co-instructor
    $stmt = mysqli_prepare(
        $conn,
        "SELECT 1
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

    if (!$stmt) {
        out(['success' => false, 'error' => 'Database error'], 500);
    }

    mysqli_stmt_bind_param($stmt, 'iii', $class_id, $uid, $uid);
    mysqli_stmt_execute($stmt);

    $allowed = (bool)mysqli_fetch_row(mysqli_stmt_get_result($stmt));

    mysqli_stmt_close($stmt);

} elseif ($role === 'program_chair') {

    $allowed = (bool)getClassAccess(
        $conn,
        $class_id,
        $uid,
        $role
    );

} elseif ($role === 'student') {

    $stmt = mysqli_prepare(
        $conn,
        "SELECT 1
         FROM class_students
         WHERE class_id = ?
           AND student_id = ?
           AND (archived = 0 OR archived IS NULL)
         LIMIT 1"
    );

    if (!$stmt) {
        out(['success' => false, 'error' => 'Database error'], 500);
    }

    mysqli_stmt_bind_param($stmt, 'ii', $class_id, $uid);
    mysqli_stmt_execute($stmt);

    $allowed = (bool)mysqli_fetch_row(mysqli_stmt_get_result($stmt));

    mysqli_stmt_close($stmt);
}

if (!$allowed) {
    out(['success' => false, 'error' => 'Not allowed'], 403);
}

/* =========================================
   ATTACHMENT MESSAGES CANNOT BE EDITED
========================================= */

if (preg_match('/^\[(image|file):.+\]$/', trim((string)$row['message']))) {
    out([
        'success' => false,
        'error' => 'Attachments cannot be edited'
    ], 400);
}

/* =========================================
   UPDATE MESSAGE
========================================= */

$stmt = mysqli_prepare(
    $conn,
    "UPDATE class_chat_messages
     SET message = ?,
         edited_at = NOW(),
         changed_at = NOW()
     WHERE id = ?
       AND sender_id = ?
       AND is_deleted = 0"
);

if (!$stmt) {
    out(['success' => false, 'error' => 'Database error'], 500);
}

mysqli_stmt_bind_param($stmt, 'sii', $text, $mid, $uid);

$ok = mysqli_stmt_execute($stmt);

mysqli_stmt_close($stmt);

if (!$ok) {
    out(['success' => false, 'error' => 'Could not save the edit'], 500);
}

/* =========================================
   SYNCHRONIZE @MENTIONS
========================================= */

$mentions = chat_sync_mentions(
    $conn,
    $mid,
    $class_id,
    $uid,
    $text
);

out([
    'success' => true,
    'message' => $text,
    'mentions' => $mentions
]);
