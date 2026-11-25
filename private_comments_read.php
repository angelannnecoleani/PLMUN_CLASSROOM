<?php
session_start();
include 'database.php';
header('Content-Type: application/json');

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'teacher' ||
    $_SERVER['REQUEST_METHOD'] !== 'POST'
) {
    echo json_encode(['success' => false]);
    exit();
}

$tid   = (int)$_SESSION['user_id'];
$items = json_decode($_POST['items'] ?? '[]', true);

if (!is_array($items)) {
    echo json_encode(['success' => false]);
    exit();
}

$stmt = mysqli_prepare($conn, "
    UPDATE assessment_private_comments pc
    INNER JOIN assessments a ON a.id = pc.assessment_id
    INNER JOIN classes c ON c.id = a.class_id
    SET pc.read_at = NOW()
    WHERE pc.assessment_id = ?
      AND pc.student_id = ?
      AND pc.sender_id = pc.student_id
      AND pc.read_at IS NULL
      AND (
            c.teacher_id = ?
         OR EXISTS (
                SELECT 1 FROM class_instructors ci
                WHERE ci.class_id = c.id AND ci.teacher_id = ?
            )
      )
");

if (!$stmt) {
    echo json_encode(['success' => false]);
    exit();
}

foreach ($items as $item) {
    $assessment_id = (int)($item['assessment_id'] ?? 0);
    $student_id    = (int)($item['student_id'] ?? 0);

    if ($assessment_id < 1 || $student_id < 1) {
        continue;
    }

    mysqli_stmt_bind_param($stmt, "iiii", $assessment_id, $student_id, $tid, $tid);
    mysqli_stmt_execute($stmt);
}

mysqli_stmt_close($stmt);

echo json_encode(['success' => true]);  