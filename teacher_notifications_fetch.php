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
        'student_id'  => 0,
        'class_name'  => $r['class_name'],
        'sender_name' => $r['sender_name'],
        'title'       => $title,
        'info'        => $info,
        'created_at'  => $r['created_at'],
        'is_read'     => $isRead,
        'url'         => $url,
    ];
}

/* ── Private comments from students (one item per thread) ── */
$pcSql = "
SELECT
    a.id AS assessment_id,
    a.class_id,
    c.class_name,
    pc.student_id,
    su.name AS sender_name,
    pc.message,
    pc.created_at,
    (
        SELECT COUNT(*)
        FROM assessment_private_comments unread
        WHERE unread.assessment_id = pc.assessment_id
          AND unread.student_id = pc.student_id
          AND unread.sender_id = pc.student_id
          AND unread.read_at IS NULL
    ) AS pc_unread
FROM (
    SELECT assessment_id, student_id, MAX(id) AS latest_id
    FROM assessment_private_comments
    WHERE sender_id = student_id
    GROUP BY assessment_id, student_id
) latest
INNER JOIN assessment_private_comments pc ON pc.id = latest.latest_id
INNER JOIN assessments a ON a.id = pc.assessment_id
INNER JOIN classes c ON c.id = a.class_id
INNER JOIN users su ON su.id = pc.student_id
INNER JOIN class_students cst
    ON cst.class_id = c.id
   AND cst.student_id = pc.student_id
   AND cst.archived = 0
WHERE (
        c.teacher_id = $tid
     OR EXISTS (
            SELECT 1 FROM class_instructors ci
            WHERE ci.class_id = c.id
              AND ci.teacher_id = $tid
        )
)
  AND pc.created_at >= (NOW() - INTERVAL 30 DAY)
ORDER BY pc.created_at DESC
LIMIT 30
";

$pcRes = mysqli_query($conn, $pcSql);
if ($pcRes) {
    while ($p = mysqli_fetch_assoc($pcRes)) {
        $msg    = trim((string)$p['message']);
        $title  = $msg !== '' ? mb_strimwidth($msg, 0, 70, '…') : 'Sent a private comment';
        $isRead = ((int)$p['pc_unread'] === 0);

        if (!$isRead) $unread++;

        $items[] = [
            'type'        => 'private',
            'id'          => (int)$p['assessment_id'],
            'student_id'  => (int)$p['student_id'],
            'class_name'  => $p['class_name'],
            'sender_name' => $p['sender_name'],
            'title'       => $title,
            'info'        => null,
            'created_at'  => $p['created_at'],
            'is_read'     => $isRead,
            'url'         => 'assessment_private_comments.php?assessment_id=' . (int)$p['assessment_id']
                           . '&student_id=' . (int)$p['student_id'],
        ];
    }
}

/* Merge: newest first, keep the latest 30 */
usort($items, function ($a, $b) {
    return strcmp((string)$b['created_at'], (string)$a['created_at']);
});
$items = array_slice($items, 0, 30);

/* Total unread private comment messages (for the sidebar badge) */
$privateUnread = 0;

$pcCountSql = "
SELECT COUNT(*) AS total
FROM assessment_private_comments pc
INNER JOIN assessments a ON a.id = pc.assessment_id
INNER JOIN classes cl ON cl.id = a.class_id
INNER JOIN class_students cst
    ON cst.class_id = cl.id
   AND cst.student_id = pc.student_id
   AND cst.archived = 0
WHERE pc.sender_id = pc.student_id
  AND pc.read_at IS NULL
  AND (
        cl.teacher_id = $tid
     OR EXISTS (
            SELECT 1 FROM class_instructors ci
            WHERE ci.class_id = cl.id
              AND ci.teacher_id = $tid
        )
  )
";

$pcCountRes = mysqli_query($conn, $pcCountSql);
if ($pcCountRes) {
    $pcRow = mysqli_fetch_assoc($pcCountRes);
    $privateUnread = (int)($pcRow['total'] ?? 0);
}

echo json_encode([
    'success'        => true,
    'items'          => array_values($items),
    'unread'         => $unread,
    'private_unread' => $privateUnread
]);