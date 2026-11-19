<?php
session_start();

require_once 'database.php';

header('Content-Type: application/json; charset=utf-8');

/* ==========================================
   LOGIN AND ROLE VALIDATION
========================================== */

if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'teacher'
) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'error' => 'Not logged in or unauthorized.'
    ]);
    exit;
}

$teacher_id = (int) $_SESSION['user_id'];

$class_id = isset($_POST['class_id'])
    ? (int) $_POST['class_id']
    : 0;

$meet_link = isset($_POST['meet_link'])
    ? trim($_POST['meet_link'])
    : '';

/* ==========================================
   VALIDATE INPUT
========================================== */

if ($class_id <= 0 || $meet_link === '') {
    echo json_encode([
        'success' => false,
        'error' => 'Missing or invalid data.'
    ]);
    exit;
}

/* ==========================================
   VALIDATE GOOGLE MEET LINK
========================================== */

if (!preg_match(
    '#^https://meet\.google\.com/[a-z0-9-]+$#i',
    $meet_link
)) {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid Google Meet link.'
    ]);
    exit;
}

/* ==========================================
   VERIFY CLASS ACCESS
   Primary teacher OR assigned co-instructor
========================================== */

$stmt = mysqli_prepare($conn, "
    SELECT c.id
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
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => 'Unable to verify class access.'
    ]);
    exit;
}

mysqli_stmt_bind_param(
    $stmt,
    "iii",
    $class_id,
    $teacher_id,
    $teacher_id
);

mysqli_stmt_execute($stmt);
mysqli_stmt_store_result($stmt);

if (mysqli_stmt_num_rows($stmt) === 0) {
    mysqli_stmt_close($stmt);

    http_response_code(403);

    echo json_encode([
        'success' => false,
        'error' => 'Class not found or access denied.'
    ]);
    exit;
}

mysqli_stmt_close($stmt);

/* ==========================================
   UPDATE GOOGLE MEET LINK
========================================== */

$update = mysqli_prepare(
    $conn,
    "UPDATE classes SET meet_link = ? WHERE id = ?"
);

if (!$update) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => 'Unable to prepare database update.'
    ]);
    exit;
}

mysqli_stmt_bind_param(
    $update,
    "si",
    $meet_link,
    $class_id
);

if (mysqli_stmt_execute($update)) {

    echo json_encode([
        'success' => true,
        'meet_link' => $meet_link
    ]);

} else {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => 'Database error while saving Google Meet link.'
    ]);
}

mysqli_stmt_close($update);

exit;
?>
