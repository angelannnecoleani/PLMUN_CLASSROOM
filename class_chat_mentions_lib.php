<?php
/* Shared helpers for @mentions in the class group chat. */

/* Everyone in a class (teacher + enrolled students) */
function chat_class_members($conn, $class_id) {
    $class_id = (int)$class_id;
    $stmt = mysqli_prepare($conn, "
        SELECT u.id, u.name, 'teacher' AS role
        FROM classes c INNER JOIN users u ON u.id = c.teacher_id
        WHERE c.id = ?
        UNION
        SELECT u.id, u.name, 'student' AS role
        FROM class_students cs INNER JOIN users u ON u.id = cs.student_id
        WHERE cs.class_id = ?
        ORDER BY name ASC
    ");
    mysqli_stmt_bind_param($stmt, 'ii', $class_id, $class_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $members = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $members[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'role' => $r['role']];
    }
    mysqli_stmt_close($stmt);
    return $members;
}

/* Finds "@Full Name" in the text, saves who was mentioned, returns [['id'=>..,'name'=>..], ...].
   Call after sending AND after editing a message (it replaces the old mentions). */
function chat_sync_mentions($conn, $message_id, $class_id, $sender_id, $text) {
    $message_id = (int)$message_id;
    $class_id   = (int)$class_id;

    mysqli_query($conn, "DELETE FROM class_chat_mentions WHERE message_id = $message_id");

    $text = (string)$text;
    if (strpos($text, '@') === false) return [];

    $members = chat_class_members($conn, $class_id);
    // longest names first, so "@Ann Lee" is not also counted as a mention of "Ann"
    usort($members, function ($a, $b) { return mb_strlen($b['name']) - mb_strlen($a['name']); });

    $found = [];
    $ins = mysqli_prepare($conn,
        "INSERT IGNORE INTO class_chat_mentions (message_id, user_id, class_id) VALUES (?, ?, ?)");

    foreach ($members as $m) {
        if ($m['id'] === (int)$sender_id) continue;          // no self-mentions
        $name = trim($m['name']);
        if ($name === '') continue;

        $re = '/(?<![\p{L}\p{N}_])@' . preg_quote($name, '/') . '(?![\p{L}\p{N}_])/iu';
        if (preg_match($re, $text)) {
            $uid = $m['id'];
            mysqli_stmt_bind_param($ins, 'iii', $message_id, $uid, $class_id);
            mysqli_stmt_execute($ins);
            $found[] = ['id' => $uid, 'name' => $name];
            $text = preg_replace($re, ' ', $text);            // consume it
        }
    }

    // NEW: "@all" or "@everyone" notifies every member of the class (except the sender)
    if (preg_match('/(?<![\p{L}\p{N}_])@(?:all|everyone)(?![\p{L}\p{N}_])/iu', $text)) {
        foreach ($members as $m) {
            if ($m['id'] === (int)$sender_id) continue;
            $uid = $m['id'];
            mysqli_stmt_bind_param($ins, 'iii', $message_id, $uid, $class_id);
            mysqli_stmt_execute($ins);                        // INSERT IGNORE: no duplicates
        }
    }

    mysqli_stmt_close($ins);
    return $found;   // named mentions only; @all is highlighted by the browser
}

/* [message_id => [['id'=>..,'name'=>..], ...]] for a list of message ids */
function chat_mentions_for($conn, array $ids) {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return [];
    $list = implode(',', $ids);                               // ints only, safe
    $res = mysqli_query($conn, "
        SELECT mm.message_id, u.id AS user_id, u.name
        FROM class_chat_mentions mm
        INNER JOIN users u ON u.id = mm.user_id
        WHERE mm.message_id IN ($list)
    ");
    $map = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $map[(int)$r['message_id']][] = ['id' => (int)$r['user_id'], 'name' => (string)$r['name']];
    }
    return $map;
}