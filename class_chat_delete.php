<?php
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

$uid = (int)$_SESSION['user_id'];
$mid = (int)($_POST['message_id'] ?? 0);
if ($mid <= 0) out(['success' => false, 'error' => 'Invalid message'], 400);

/* sender column is sender_id */
$stmt = mysqli_prepare($conn,
    "SELECT message, attachment FROM class_chat_messages
     WHERE id = ? AND sender_id = ? AND is_deleted = 0");
mysqli_stmt_bind_param($stmt, 'ii', $mid, $uid);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$row) out(['success' => false, 'error' => 'Message not found, or it is not yours'], 404);

// Soft delete: keep the row (so other open chats can be told), wipe the text
$stmt = mysqli_prepare($conn,
    "UPDATE class_chat_messages SET is_deleted = 1, message = '', changed_at = NOW()
     WHERE id = ? AND sender_id = ?");
mysqli_stmt_bind_param($stmt, 'ii', $mid, $uid);
$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

if (!$ok) out(['success' => false, 'error' => 'Could not delete the message'], 500);

// Remove the uploaded file too
$files = [];
// new format: stored as "chat/<random>.<ext>" (relative to /uploads)
if (!empty($row['attachment']) && preg_match('#^chat/[A-Za-z0-9._-]+$#', $row['attachment'])) {
    $files[] = $row['attachment'];
}
// old format: "[image:xxx]" / "[file:xxx]" stored directly in /uploads
if (preg_match('/^\[(?:image|file):(.+)\]$/', trim((string)$row['message']), $m)) {
    $files[] = basename($m[1]);
}

foreach ($files as $f) {
    $path = __DIR__ . '/uploads/' . $f;
    if (is_file($path)) @unlink($path);
}

out(['success' => true]);