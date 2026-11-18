<?php
session_start();
include 'database.php';
include 'class_chat_mentions_lib.php';   // NEW
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

if (!isset($_GET['class_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing class_id']);
    exit;
}

$class_id = intval($_GET['class_id']);

// Optional: only return messages newer than a given id, for efficient polling
$after_id = isset($_GET['after_id']) ? intval($_GET['after_id']) : 0;

// Confirm the requester actually belongs to this class (teacher or enrolled student)
$check = mysqli_prepare($conn, "
    SELECT 1 FROM classes WHERE id = ? AND teacher_id = ?
    UNION
    SELECT 1 FROM class_students WHERE class_id = ? AND student_id = ?
");
$uid = $_SESSION['user_id'];
mysqli_stmt_bind_param($check, "iiii", $class_id, $uid, $class_id, $uid);
mysqli_stmt_execute($check);
mysqli_stmt_store_result($check);

if (mysqli_stmt_num_rows($check) === 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Not a member of this class']);
    exit;
}
mysqli_stmt_close($check);

// CHANGED: deleted messages are returned as placeholders (content blanked), plus edited_at
$stmt = mysqli_prepare($conn, "
    SELECT class_chat_messages.id, class_chat_messages.message,
           class_chat_messages.attachment, class_chat_messages.attachment_name,
           class_chat_messages.attachment_type, class_chat_messages.created_at,
           class_chat_messages.edited_at, class_chat_messages.is_deleted,
           users.id AS sender_id, users.name, users.profile_image
    FROM class_chat_messages
    INNER JOIN users ON class_chat_messages.sender_id = users.id
    WHERE class_chat_messages.class_id = ? AND class_chat_messages.id > ?
    ORDER BY class_chat_messages.id ASC
");
mysqli_stmt_bind_param($stmt, "ii", $class_id, $after_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$messages = [];
while ($row = mysqli_fetch_assoc($result)) {
    $deleted = ((int)$row['is_deleted'] === 1);
    $messages[] = [
        'id'              => $row['id'],
        'message'         => $deleted ? '' : $row['message'],
        'attachment'      => $deleted ? null : $row['attachment'],
        'attachment_name' => $deleted ? null : $row['attachment_name'],
        'attachment_type' => $deleted ? null : $row['attachment_type'],
        'created_at'      => $row['created_at'],
        'edited_at'       => $deleted ? null : $row['edited_at'],
        'is_deleted'      => $deleted,
        'sender_id'       => $row['sender_id'],
        'name'            => $row['name'],
        'profile_image'   => $row['profile_image'],
        'is_me'           => ($row['sender_id'] == $_SESSION['user_id']),
    ];
}

// NEW: attach who was @mentioned in each message
$mentionMap = chat_mentions_for($conn, array_column($messages, 'id'));
foreach ($messages as &$mm) {
    $mm['mentions'] = $mentionMap[(int)$mm['id']] ?? [];
    if (!empty($mm['is_deleted'])) $mm['mentions'] = [];
}
unset($mm);

echo json_encode(['messages' => $messages]);