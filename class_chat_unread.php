<?php
/* Returns how many unread chat messages the logged-in user has, per class + total. */
session_start();
include 'database.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$uid = (int)$_SESSION['user_id'];

/* First time we see a class for this user: treat everything already there as read,
   so old history doesn't show up as hundreds of "missed" messages. */
mysqli_query($conn, "
    INSERT IGNORE INTO class_chat_reads (user_id, class_id, last_read_id)
    SELECT $uid, c.id, COALESCE((SELECT MAX(m.id) FROM class_chat_messages m WHERE m.class_id = c.id), 0)
    FROM classes c
    WHERE c.teacher_id = $uid
      AND NOT EXISTS (SELECT 1 FROM class_chat_reads r WHERE r.user_id = $uid AND r.class_id = c.id)
");
mysqli_query($conn, "
    INSERT IGNORE INTO class_chat_reads (user_id, class_id, last_read_id)
    SELECT $uid, cs.class_id, COALESCE((SELECT MAX(m.id) FROM class_chat_messages m WHERE m.class_id = cs.class_id), 0)
    FROM class_students cs
    WHERE cs.student_id = $uid
      AND NOT EXISTS (SELECT 1 FROM class_chat_reads r WHERE r.user_id = $uid AND r.class_id = cs.class_id)
");

/* Count messages from other people, newer than the last one this user has seen */
$stmt = mysqli_prepare($conn, "
    SELECT m.class_id, COUNT(*) AS unread
    FROM class_chat_messages m
    INNER JOIN class_chat_reads r ON r.class_id = m.class_id AND r.user_id = ?
    WHERE m.id > r.last_read_id
      AND m.sender_id <> ?
      AND m.is_deleted = 0
      AND m.class_id IN (
            SELECT id FROM classes WHERE teacher_id = ?
            UNION
            SELECT class_id FROM class_students WHERE student_id = ?
      )
    GROUP BY m.class_id
");
mysqli_stmt_bind_param($stmt, 'iiii', $uid, $uid, $uid, $uid);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$classes = [];
$total = 0;
while ($row = mysqli_fetch_assoc($res)) {
    $classes[(string)$row['class_id']] = (int)$row['unread'];
    $total += (int)$row['unread'];
}
mysqli_stmt_close($stmt);

/* NEW: unread messages that @mention this user */
$stmt = mysqli_prepare($conn, "
    SELECT m.class_id, COUNT(*) AS n
    FROM class_chat_mentions mm
    INNER JOIN class_chat_messages m ON m.id = mm.message_id
    INNER JOIN class_chat_reads r ON r.class_id = m.class_id AND r.user_id = ?
    WHERE mm.user_id = ? AND m.id > r.last_read_id AND m.is_deleted = 0
    GROUP BY m.class_id
");
mysqli_stmt_bind_param($stmt, 'ii', $uid, $uid);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$mentions = [];
$mentionsTotal = 0;
while ($row = mysqli_fetch_assoc($res)) {
    $mentions[(string)$row['class_id']] = (int)$row['n'];
    $mentionsTotal += (int)$row['n'];
}
mysqli_stmt_close($stmt);

echo json_encode([
    'success' => true,
    'total' => $total,
    'classes' => (object)$classes,
    'mentions' => (object)$mentions,
    'mentions_total' => $mentionsTotal,
]);