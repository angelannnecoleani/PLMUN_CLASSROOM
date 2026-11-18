<?php
session_start();
include 'database.php';
include 'class_chat_mentions_lib.php';   // NEW
header('Content-Type: application/json');

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if (!isset($_SESSION['user_id'])) out(['success' => false, 'error' => 'Not logged in'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['success' => false, 'error' => 'POST only'], 405);

$uid  = (int)$_SESSION['user_id'];
$mid  = (int)($_POST['message_id'] ?? 0);
$text = trim((string)($_POST['message'] ?? ''));

if ($mid <= 0)               out(['success' => false, 'error' => 'Invalid message'], 400);
if ($text === '')            out(['success' => false, 'error' => 'Message cannot be empty'], 400);
if (mb_strlen($text) > 5000) out(['success' => false, 'error' => 'Message is too long'], 400);

$stmt = mysqli_prepare($conn,
    "SELECT class_id, message FROM class_chat_messages WHERE id = ? AND sender_id = ? AND is_deleted = 0");
mysqli_stmt_bind_param($stmt, 'ii', $mid, $uid);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$row) out(['success' => false, 'error' => 'Message not found, or it is not yours'], 404);

// old-format attachment messages ("[image:xxx]" / "[file:xxx]") can't be edited
if (preg_match('/^\[(image|file):.+\]$/', trim((string)$row['message']))) {
    out(['success' => false, 'error' => 'Attachments cannot be edited'], 400);
}

$stmt = mysqli_prepare($conn,
    "UPDATE class_chat_messages SET message = ?, edited_at = NOW(), changed_at = NOW()
     WHERE id = ? AND sender_id = ?");
mysqli_stmt_bind_param($stmt, 'sii', $text, $mid, $uid);
$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

if (!$ok) out(['success' => false, 'error' => 'Could not save the edit'], 500);
// NEW: the edit may add or remove @mentions
$mentions = chat_sync_mentions($conn, $mid, (int)$row['class_id'], $uid, $text);
out(['success' => true, 'message' => $text, 'mentions' => $mentions]);