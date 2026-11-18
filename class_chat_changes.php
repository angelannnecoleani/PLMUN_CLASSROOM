<?php
/* Returns messages that were edited or deleted since a given time,
   so every open chat window stays in sync. */
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

$uid      = (int)$_SESSION['user_id'];
$class_id = (int)($_GET['class_id'] ?? 0);
$since    = (string)($_GET['since'] ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) {
    $since = '1970-01-01 00:00:00';
}
if ($class_id <= 0) out(['success' => false, 'error' => 'Invalid class'], 400);

// Must be the class teacher or an enrolled student
$stmt = mysqli_prepare($conn,
    "SELECT 1 FROM classes WHERE id = ? AND teacher_id = ?
     UNION
     SELECT 1 FROM class_students WHERE class_id = ? AND student_id = ?");
mysqli_stmt_bind_param($stmt, 'iiii', $class_id, $uid, $class_id, $uid);
mysqli_stmt_execute($stmt);
$allowed = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$allowed) out(['success' => false, 'error' => 'Not allowed'], 403);

// Use the database clock for everything so there is no clock skew
$serverTime = mysqli_fetch_row(mysqli_query($conn, "SELECT NOW()"))[0];

$stmt = mysqli_prepare($conn,
    "SELECT id, message, is_deleted FROM class_chat_messages
     WHERE class_id = ? AND changed_at >= ?");
mysqli_stmt_bind_param($stmt, 'is', $class_id, $since);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$edited = [];
$deleted = [];
while ($r = mysqli_fetch_assoc($res)) {
    if ((int)$r['is_deleted'] === 1) {
        $deleted[] = (int)$r['id'];
    } else {
        $edited[] = ['id' => (int)$r['id'], 'message' => $r['message']];
    }
}
mysqli_stmt_close($stmt);

// NEW: mentions for edited messages
$mentionMap = chat_mentions_for($conn, array_column($edited, 'id'));
foreach ($edited as &$e) { $e['mentions'] = $mentionMap[$e['id']] ?? []; }
unset($e);

out(['success' => true, 'edited' => $edited, 'deleted' => $deleted, 'server_time' => $serverTime]);