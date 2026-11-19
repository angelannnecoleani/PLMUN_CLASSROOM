<?php
/*
 * Returns edited and deleted messages since a given time.
 * Supports primary instructors, co-instructors,
 * program chairs, and actively enrolled students.
 */

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
   LOGIN AND VALIDATION
========================================= */

if (empty($_SESSION['user_id'])) {
    out(['success' => false, 'error' => 'Not logged in'], 401);
}

$uid  = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

if (!in_array($role, ['teacher', 'program_chair', 'student'], true)) {
    out(['success' => false, 'error' => 'Access denied'], 403);
}

$class_id = (int)($_GET['class_id'] ?? 0);
$since    = (string)($_GET['since'] ?? '');

if ($class_id <= 0) {
    out(['success' => false, 'error' => 'Invalid class'], 400);
}

if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) {
    $since = '1970-01-01 00:00:00';
}

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
   GET DATABASE SERVER TIME
========================================= */

$timeResult = mysqli_query($conn, "SELECT NOW() AS server_time");

if (!$timeResult) {
    out(['success' => false, 'error' => 'Could not get server time'], 500);
}

$timeRow = mysqli_fetch_assoc($timeResult);
$serverTime = $timeRow['server_time'];

/* =========================================
   FETCH EDITED AND DELETED MESSAGES
========================================= */

$stmt = mysqli_prepare(
    $conn,
    "SELECT id, message, is_deleted
     FROM class_chat_messages
     WHERE class_id = ?
       AND changed_at >= ?
     ORDER BY changed_at ASC"
);

if (!$stmt) {
    out(['success' => false, 'error' => 'Database error'], 500);
}

mysqli_stmt_bind_param($stmt, 'is', $class_id, $since);
mysqli_stmt_execute($stmt);

$res = mysqli_stmt_get_result($stmt);

$edited  = [];
$deleted = [];

while ($r = mysqli_fetch_assoc($res)) {

    if ((int)$r['is_deleted'] === 1) {

        $deleted[] = (int)$r['id'];

    } else {

        $edited[] = [
            'id' => (int)$r['id'],
            'message' => $r['message']
        ];
    }
}

mysqli_stmt_close($stmt);

/* =========================================
   FETCH UPDATED @MENTIONS
========================================= */

$mentionMap = chat_mentions_for(
    $conn,
    array_column($edited, 'id')
);

foreach ($edited as &$e) {
    $e['mentions'] = $mentionMap[$e['id']] ?? [];
}

unset($e);

/* =========================================
   RESPONSE
========================================= */

out([
    'success' => true,
    'edited' => $edited,
    'deleted' => $deleted,
    'server_time' => $serverTime
]);