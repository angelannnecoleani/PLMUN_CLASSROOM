
<?php
session_start();

require_once 'database.php';
require_once 'auth_helpers.php';
require_once 'class_chat_mentions_lib.php';

header('Content-Type: application/json; charset=utf-8');

/* ==========================================
   LOGIN VALIDATION
========================================== */

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$uid = (int) $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

if (!in_array($role, ['teacher', 'program_chair', 'student'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'You are not allowed to access this chat']);
    exit;
}

/* ==========================================
   GET REQUEST DATA
========================================== */

$class_id = isset($_GET['class_id'])
    ? (int) $_GET['class_id']
    : 0;

$after_id = isset($_GET['after_id'])
    ? max(0, (int) $_GET['after_id'])
    : 0;

if ($class_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid class_id']);
    exit;
}

/* ==========================================
   VERIFY CLASS MEMBERSHIP
========================================== */

$hasAccess = false;

if ($role === 'teacher') {

    // Primary instructor OR co-instructor.
    $check = mysqli_prepare(
        $conn,
        "SELECT c.id
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

    if (!$check) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error while checking instructor access']);
        exit;
    }

    mysqli_stmt_bind_param(
        $check,
        "iii",
        $class_id,
        $uid,
        $uid
    );

    mysqli_stmt_execute($check);

    $result = mysqli_stmt_get_result($check);
    $hasAccess = mysqli_num_rows($result) > 0;

    mysqli_stmt_close($check);

} elseif ($role === 'program_chair') {

    $hasAccess = (bool) getClassAccess(
        $conn,
        $class_id,
        $uid,
        $role
    );

} elseif ($role === 'student') {

    $check = mysqli_prepare(
        $conn,
        "SELECT 1
         FROM class_students
         WHERE class_id = ?
           AND student_id = ?
           AND (archived = 0 OR archived IS NULL)
         LIMIT 1"
    );

    if (!$check) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error while checking student access']);
        exit;
    }

    mysqli_stmt_bind_param($check, "ii", $class_id, $uid);
    mysqli_stmt_execute($check);

    $result = mysqli_stmt_get_result($check);
    $hasAccess = mysqli_num_rows($result) > 0;

    mysqli_stmt_close($check);
}

if (!$hasAccess) {
    http_response_code(403);
    echo json_encode(['error' => 'Not a member of this class']);
    exit;
}

/* ==========================================
   FETCH MESSAGES
========================================== */

$stmt = mysqli_prepare(
    $conn,
    "SELECT
        cm.id,
        cm.message,
        cm.attachment,
        cm.attachment_name,
        cm.attachment_type,
        cm.created_at,
        cm.edited_at,
        cm.is_deleted,
        u.id AS sender_id,
        u.name,
        u.profile_image

     FROM class_chat_messages cm

     INNER JOIN users u
        ON cm.sender_id = u.id

     WHERE cm.class_id = ?
       AND cm.id > ?

     ORDER BY cm.id ASC"
);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to prepare message query']);
    exit;
}

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $class_id,
    $after_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$messages = [];

while ($row = mysqli_fetch_assoc($result)) {

    $deleted = ((int) $row['is_deleted'] === 1);

    $messages[] = [
        'id' => (int) $row['id'],

        'message' => $deleted
            ? ''
            : $row['message'],

        'attachment' => $deleted
            ? null
            : $row['attachment'],

        'attachment_name' => $deleted
            ? null
            : $row['attachment_name'],

        'attachment_type' => $deleted
            ? null
            : $row['attachment_type'],

        'created_at' => $row['created_at'],

        'edited_at' => $deleted
            ? null
            : $row['edited_at'],

        'is_deleted' => $deleted,

        'sender_id' => (int) $row['sender_id'],

        'name' => $row['name'],

        'profile_image' => $row['profile_image'],

        'is_me' => ((int) $row['sender_id'] === $uid)
    ];
}

mysqli_stmt_close($stmt);

/* ==========================================
   FETCH @MENTIONS
========================================== */

$messageIds = array_column($messages, 'id');

$mentionMap = [];

if (!empty($messageIds)) {
    $mentionMap = chat_mentions_for($conn, $messageIds);
}

foreach ($messages as &$messageRow) {

    $messageRow['mentions'] =
        $mentionMap[(int) $messageRow['id']] ?? [];

    if (!empty($messageRow['is_deleted'])) {
        $messageRow['mentions'] = [];
    }
}

unset($messageRow);

/* ==========================================
   RETURN JSON
========================================== */

echo json_encode([
    'messages' => $messages
]);
