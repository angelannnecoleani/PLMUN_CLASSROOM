<?php
session_start();
include 'database.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    echo json_encode(['success' => false, 'items' => [], 'unread' => 0]);
    exit();
}

$sid = (int)$_SESSION['user_id'];

/* Latest teacher reply in each private conversation of this student. */

$sql = "
    SELECT pc.assessment_id, pc.message, pc.created_at,
           a.title AS assessment_title,
           c.class_name,
           su.name AS sender_name,
           (SELECT COUNT(*)
              FROM assessment_private_comments u
             WHERE u.assessment_id = pc.assessment_id
               AND u.student_id = $sid
               AND u.sender_id <> $sid
               AND u.read_at IS NULL) AS unread_count
    FROM (
        SELECT assessment_id, MAX(id) AS latest_id
        FROM assessment_private_comments
        WHERE student_id = $sid
          AND sender_id <> $sid
        GROUP BY assessment_id
    ) lt
    INNER JOIN assessment_private_comments pc ON pc.id = lt.latest_id
    INNER JOIN assessments a ON a.id = pc.assessment_id
    INNER JOIN classes c ON c.id = a.class_id
    INNER JOIN users su ON su.id = pc.sender_id
    WHERE pc.created_at >= (NOW() - INTERVAL 30 DAY)
    ORDER BY pc.created_at DESC
    LIMIT 20
";

$res = mysqli_query($conn, $sql);

if (!$res) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    exit();
}

$items  = [];
$unread = 0;

while ($r = mysqli_fetch_assoc($res)) {
    $text   = trim(preg_replace('/\s+/', ' ', (string)$r['message']));
    $isRead = ((int)$r['unread_count'] === 0);

    if (!$isRead) {
        $unread++;
    }

    $items[] = [
        'assessment_id'    => (int)$r['assessment_id'],
        'assessment_title' => $r['assessment_title'],
        'class_name'       => $r['class_name'],
        'sender_name'      => $r['sender_name'],
        'title'            => $text !== '' ? mb_strimwidth($text, 0, 70, '…') : 'Sent a private comment',
        'created_at'       => $r['created_at'],
        'is_read'          => $isRead,
        'url'              => 'student_assessment_view.php?id=' . (int)$r['assessment_id'] . '#privateComments',
    ];
}

echo json_encode(['success' => true, 'items' => $items, 'unread' => $unread]);