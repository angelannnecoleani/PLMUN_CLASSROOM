<?php
session_start();
include 'database.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'items' => [], 'unread' => 0]);
    exit();
}

$sid   = (int)$_SESSION['user_id'];
$today = date('Y-m-d');            // same clock the assessment pages use
$now   = date('Y-m-d H:i:s');

$overdueDays  = 30;                // how far back to remind about overdue work
$overdueSince = date('Y-m-d', strtotime("-{$overdueDays} days"));

$sql = "
SELECT x.*, c.class_name,
       (nr.id IS NOT NULL) AS is_read
FROM (
    /* Announcements */
    SELECT 'announcement' AS post_type, a.id AS post_id, a.class_id,
           a.created_at, a.content AS title, NULL AS sender_name,
           NULL AS atype, NULL AS due_date
    FROM announcements a
    INNER JOIN class_students cs
        ON cs.class_id = a.class_id AND cs.student_id = $sid
    WHERE cs.archived = 0
      AND a.created_at >= cs.joined_at
      AND a.created_at >= (NOW() - INTERVAL 30 DAY)

    UNION ALL

    /* Assessments / assignments (newly posted) */
    SELECT 'assessment', s.id, s.class_id, s.created_at, s.title, NULL,
           NULL, NULL
    FROM assessments s
    INNER JOIN class_students cs
        ON cs.class_id = s.class_id AND cs.student_id = $sid
    WHERE cs.archived = 0
      AND s.created_at >= cs.joined_at
      AND s.created_at >= (NOW() - INTERVAL 30 DAY)

    UNION ALL

    /* Materials */
    SELECT 'material', m.id, m.class_id, m.created_at, m.title, NULL,
           NULL, NULL
    FROM materials m
    INNER JOIN class_students cs
        ON cs.class_id = m.class_id AND cs.student_id = $sid
    WHERE cs.archived = 0
      AND m.created_at >= cs.joined_at
      AND m.created_at >= (NOW() - INTERVAL 30 DAY)

    UNION ALL

    /* @mentions in the group chat (includes @all / @everyone) */
    SELECT 'mention', msg.id, msg.class_id, msg.created_at,
           msg.message, su.name, NULL, NULL
    FROM class_chat_mentions mm
    INNER JOIN class_chat_messages msg ON msg.id = mm.message_id
    INNER JOIN users su ON su.id = msg.sender_id
    INNER JOIN class_students cs
        ON cs.class_id = msg.class_id AND cs.student_id = $sid
    WHERE mm.user_id = $sid
      AND msg.sender_id <> $sid
      AND msg.is_deleted = 0
      AND cs.archived = 0
      AND msg.created_at >= (NOW() - INTERVAL 30 DAY)

    UNION ALL

    /* DUE TODAY: not yet submitted / marked done */
    SELECT 'due', s.id, s.class_id, CAST('$now' AS DATETIME), s.title, NULL,
           s.type, s.due_date
    FROM assessments s
    INNER JOIN class_students cs
        ON cs.class_id = s.class_id AND cs.student_id = $sid
    WHERE cs.archived = 0
      AND s.due_date IS NOT NULL
      AND DATE(s.due_date) = '$today'
      AND NOT EXISTS (
          SELECT 1 FROM submissions sub
          WHERE sub.assessment_id = s.id
            AND sub.student_id = $sid
            AND sub.status IN ('submitted', 'done')
      )

    UNION ALL

    /* OVERDUE: past the due date, still not submitted / marked done.
       Sorted by its due date, so it sits where it became overdue. */
    SELECT 'overdue', s.id, s.class_id, CAST(s.due_date AS DATETIME), s.title, NULL,
           s.type, s.due_date
    FROM assessments s
    INNER JOIN class_students cs
        ON cs.class_id = s.class_id AND cs.student_id = $sid
    WHERE cs.archived = 0
      AND s.due_date IS NOT NULL
      AND DATE(s.due_date) < '$today'
      AND DATE(s.due_date) >= '$overdueSince'
      AND NOT EXISTS (
          SELECT 1 FROM submissions sub
          WHERE sub.assessment_id = s.id
            AND sub.student_id = $sid
            AND sub.status IN ('submitted', 'done')
      )
) x
INNER JOIN classes c ON c.id = x.class_id
LEFT JOIN notification_reads nr
    ON nr.student_id = $sid
   AND nr.post_type = x.post_type
   AND nr.post_id = x.post_id
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
    $type     = $r['post_type'];
    $title    = trim((string)$r['title']);
    $dueLabel = null;

    if ($type === 'announcement') {
        $url   = 'student_class.php?id=' . (int)$r['class_id'];
        $title = $title !== '' ? mb_strimwidth($title, 0, 70, '…') : 'New announcement';

    } elseif ($type === 'assessment') {
        $url = 'student_assessment_view.php?id=' . (int)$r['post_id'];

    } elseif ($type === 'material') {
        $url = 'student_material.php?id=' . (int)$r['class_id'];

    } elseif ($type === 'due' || $type === 'overdue') {
        $url = 'student_assessment_view.php?id=' . (int)$r['post_id'];

        $kind  = trim((string)$r['atype']);
        $title = ($kind !== '' ? ucfirst(strtolower($kind)) . ': ' : '') . $title;

        $ts = strtotime((string)$r['due_date']);

        if ($type === 'due') {
            $dueLabel = ($ts !== false && date('H:i:s', $ts) !== '00:00:00')
                ? 'Due today at ' . date('g:i A', $ts)
                : 'Due today';
        } else {
            $days = ($ts !== false)
                ? (int)round((strtotime($today) - strtotime(date('Y-m-d', $ts))) / 86400)
                : 1;
            if ($days < 1) $days = 1;
            $dueLabel = 'Overdue by ' . $days . ' day' . ($days === 1 ? '' : 's')
                      . ' · was due ' . date('M d', $ts);
        }

    } else { // mention
        $url   = 'student_messages.php?class_id=' . (int)$r['class_id'];
        $title = $title !== '' ? mb_strimwidth($title, 0, 70, '…') : 'Sent an attachment';
    }

    $isRead = (int)$r['is_read'] === 1;
    if (!$isRead) $unread++;

    $items[] = [
        'type'        => $type,
        'id'          => (int)$r['post_id'],
        'class_name'  => $r['class_name'],
        'sender_name' => $r['sender_name'],
        'title'       => $title,
        'due_label'   => $dueLabel,
        'created_at'  => $r['created_at'],
        'is_read'     => $isRead,
        'url'         => $url,
    ];
}

echo json_encode(['success' => true, 'items' => $items, 'unread' => $unread]);