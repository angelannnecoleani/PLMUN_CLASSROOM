<?php
session_start();
include 'database.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    echo json_encode(['success' => false, 'items' => [], 'unread' => 0]);
    exit();
}

$tid   = (int)$_SESSION['user_id'];
$today = date('Y-m-d');
$now   = date('Y-m-d H:i:s');

$reviewDays  = 30;   // how far back to look for ended assessments
$reviewSince = date('Y-m-d', strtotime("-{$reviewDays} days"));

$sql = "
SELECT x.*, c.class_name,
       (nr.id IS NOT NULL) AS is_read
FROM (
    /* @mentions in the group chat (includes @all / @everyone) */
    SELECT 'mention' AS post_type, msg.id AS post_id, msg.class_id,
           msg.created_at, msg.message AS title, su.name AS sender_name,
           NULL AS atype, NULL AS due_date,
           0 AS turned_in, 0 AS assigned, 0 AS graded
    FROM class_chat_mentions mm
    INNER JOIN class_chat_messages msg ON msg.id = mm.message_id
    INNER JOIN users su ON su.id = msg.sender_id
    INNER JOIN classes cl ON cl.id = msg.class_id AND cl.teacher_id = $tid
    WHERE mm.user_id = $tid
      AND msg.sender_id <> $tid
      AND msg.is_deleted = 0
      AND msg.created_at >= (NOW() - INTERVAL 30 DAY)

    UNION ALL

    /* Deadline passed: submissions waiting to be checked */
    SELECT 'review', s.id, s.class_id,
           CAST(s.due_date AS DATETIME), s.title, NULL,
           s.type, s.due_date,
           (SELECT COUNT(*) FROM submissions sb
             WHERE sb.assessment_id = s.id
               AND sb.status IN ('submitted','done')),
           (SELECT COUNT(*) FROM class_students cs
             WHERE cs.class_id = s.class_id),
           (SELECT COUNT(*) FROM submissions sb
             WHERE sb.assessment_id = s.id
               AND sb.grade IS NOT NULL)
    FROM assessments s
    INNER JOIN classes cl ON cl.id = s.class_id AND cl.teacher_id = $tid
    WHERE s.due_date IS NOT NULL
      AND DATE(s.due_date) >= '$reviewSince'
      AND (
            DATE(s.due_date) < '$today'
         OR (TIME(s.due_date) <> '00:00:00' AND s.due_date <= '$now')
      )
) x
INNER JOIN classes c ON c.id = x.class_id
LEFT JOIN notification_reads nr
    ON nr.student_id = $tid
   AND nr.post_type = x.post_type
   AND nr.post_id = x.post_id
WHERE x.post_type <> 'review' OR x.turned_in > x.graded
ORDER BY x.created_at DESC
LIMIT 30
";

$res = mysqli_query($conn, $sql);
if (!$res) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    exit();
}

$items  = [];
$unread = 0;

while ($r = mysqli_fetch_assoc($res)) {
    $type  = $r['post_type'];
    $title = trim((string)$r['title']);
    $info  = null;

    if ($type === 'review') {
        $kind  = trim((string)$r['atype']);
        $title = ($kind !== '' ? ucfirst(strtolower($kind)) . ': ' : '') . $title;

        $turned   = (int)$r['turned_in'];
        $assigned = (int)$r['assigned'];
        $toGrade  = max(0, $turned - (int)$r['graded']);

        $info = $turned . ' of ' . $assigned . ' student' . ($assigned === 1 ? '' : 's')
              . ' submitted · ' . $toGrade . ' still to grade';

        $url = 'teacher_submissions.php?id=' . (int)$r['post_id'];
    } else { // mention
        $title = $title !== '' ? mb_strimwidth($title, 0, 70, '…') : 'Sent an attachment';
        $url   = 'teacher_messages.php?class_id=' . (int)$r['class_id'];
    }

    $isRead = (int)$r['is_read'] === 1;
    if (!$isRead) $unread++;

    $items[] = [
        'type'        => $type,
        'id'          => (int)$r['post_id'],
        'class_name'  => $r['class_name'],
        'sender_name' => $r['sender_name'],
        'title'       => $title,
        'info'        => $info,
        'created_at'  => $r['created_at'],
        'is_read'     => $isRead,
        'url'         => $url,
    ];
}

echo json_encode(['success' => true, 'items' => $items, 'unread' => $unread]);