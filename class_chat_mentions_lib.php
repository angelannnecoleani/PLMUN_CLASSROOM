<?php
/*
 * Shared helpers for @mentions in the class group chat.
 * Supports primary instructors, co-instructors, and students.
 */

/* =========================================
   GET ALL CLASS MEMBERS
========================================= */

function chat_class_members($conn, $class_id) {

    $class_id = (int)$class_id;

    $sql = "
        /* Primary instructor */
        SELECT
            u.id,
            u.name,
            'teacher' AS role
        FROM classes c
        INNER JOIN users u ON u.id = c.teacher_id
        WHERE c.id = ?

        UNION

        /* Co-instructors */
        SELECT
            u.id,
            u.name,
            'co_instructor' AS role
        FROM class_instructors ci
        INNER JOIN users u ON u.id = ci.teacher_id
        WHERE ci.class_id = ?
          AND ci.teacher_id NOT IN (
              SELECT teacher_id
              FROM classes
              WHERE id = ?
                AND teacher_id IS NOT NULL
          )

        UNION

        /* Active enrolled students */
        SELECT
            u.id,
            u.name,
            'student' AS role
        FROM class_students cs
        INNER JOIN users u ON u.id = cs.student_id
        WHERE cs.class_id = ?
          AND (cs.archived = 0 OR cs.archived IS NULL)

        ORDER BY name ASC
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        error_log('Chat mention member query failed: ' . mysqli_error($conn));
        return [];
    }

    mysqli_stmt_bind_param(
        $stmt,
        'iiii',
        $class_id,
        $class_id,
        $class_id,
        $class_id
    );

    mysqli_stmt_execute($stmt);

    $res = mysqli_stmt_get_result($stmt);

    $members = [];

    while ($r = mysqli_fetch_assoc($res)) {

        $members[] = [
            'id'   => (int)$r['id'],
            'name' => (string)$r['name'],
            'role' => (string)$r['role']
        ];
    }

    mysqli_stmt_close($stmt);

    return $members;
}

/* =========================================
   SAVE AND SYNCHRONIZE MENTIONS
========================================= */

function chat_sync_mentions(
    $conn,
    $message_id,
    $class_id,
    $sender_id,
    $text
) {

    $message_id = (int)$message_id;
    $class_id   = (int)$class_id;
    $sender_id  = (int)$sender_id;

    // Remove previous mentions when editing a message.
    $delete = mysqli_prepare(
        $conn,
        "DELETE FROM class_chat_mentions WHERE message_id = ?"
    );

    if (!$delete) {
        error_log('Chat mention delete preparation failed: ' . mysqli_error($conn));
        return [];
    }

    mysqli_stmt_bind_param($delete, 'i', $message_id);
    mysqli_stmt_execute($delete);
    mysqli_stmt_close($delete);

    $text = (string)$text;

    if (strpos($text, '@') === false) {
        return [];
    }

    // Includes primary instructor, co-instructors, and active students.
    $members = chat_class_members($conn, $class_id);

    // Match longer names first.
    usort($members, function ($a, $b) {
        return mb_strlen($b['name']) - mb_strlen($a['name']);
    });

    $found = [];

    $ins = mysqli_prepare(
        $conn,
        "INSERT IGNORE INTO class_chat_mentions
         (message_id, user_id, class_id)
         VALUES (?, ?, ?)"
    );

    if (!$ins) {
        error_log('Chat mention insert preparation failed: ' . mysqli_error($conn));
        return [];
    }

    foreach ($members as $m) {

        // Do not mention yourself.
        if ((int)$m['id'] === $sender_id) {
            continue;
        }

        $name = trim($m['name']);

        if ($name === '') {
            continue;
        }

        $re = '/(?<![\p{L}\p{N}_])@' .
              preg_quote($name, '/') .
              '(?![\p{L}\p{N}_])/iu';

        if (preg_match($re, $text)) {

            $mentioned_id = (int)$m['id'];

            mysqli_stmt_bind_param(
                $ins,
                'iii',
                $message_id,
                $mentioned_id,
                $class_id
            );

            mysqli_stmt_execute($ins);

            $found[] = [
                'id'   => $mentioned_id,
                'name' => $name
            ];

            // Prevent shorter overlapping names from matching again.
            $text = preg_replace($re, ' ', $text);
        }
    }

    /* =====================================
       @ALL AND @EVERYONE
    ===================================== */

    if (
        preg_match(
            '/(?<![\p{L}\p{N}_])@(?:all|everyone)(?![\p{L}\p{N}_])/iu',
            $text
        )
    ) {

        foreach ($members as $m) {

            if ((int)$m['id'] === $sender_id) {
                continue;
            }

            $mentioned_id = (int)$m['id'];

            mysqli_stmt_bind_param(
                $ins,
                'iii',
                $message_id,
                $mentioned_id,
                $class_id
            );

            mysqli_stmt_execute($ins);
        }
    }

    mysqli_stmt_close($ins);

    return $found;
}

/* =========================================
   GET MENTIONS FOR MULTIPLE MESSAGES
========================================= */

function chat_mentions_for($conn, array $ids) {

    $ids = array_values(
        array_unique(
            array_filter(
                array_map('intval', $ids),
                function ($id) {
                    return $id > 0;
                }
            )
        )
    );

    if (!$ids) {
        return [];
    }

    // IDs are converted to integers before being used in this query.
    $list = implode(',', $ids);

    $sql = "
        SELECT
            mm.message_id,
            u.id AS user_id,
            u.name
        FROM class_chat_mentions mm
        INNER JOIN users u ON u.id = mm.user_id
        WHERE mm.message_id IN ($list)
    ";

    $res = mysqli_query($conn, $sql);

    if (!$res) {
        error_log('Chat mentions fetch failed: ' . mysqli_error($conn));
        return [];
    }

    $map = [];

    while ($r = mysqli_fetch_assoc($res)) {

        $map[(int)$r['message_id']][] = [
            'id'   => (int)$r['user_id'],
            'name' => (string)$r['name']
        ];
    }

    return $map;
}