
<?php
session_start();
require_once 'database.php';

/* ==========================================
   LOGIN AND ROLE VALIDATION
========================================== */

if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'teacher'
) {
    header("Location: login.php");
    exit;
}

$teacher_id = (int) $_SESSION['user_id'];

/* ==========================================
   VALIDATE REQUEST
========================================== */

if (!isset($_GET['id']) || (int) $_GET['id'] <= 0) {
    die("Invalid assessment request.");
}

$assessment_id = (int) $_GET['id'];

/* ==========================================
   VERIFY CLASS ACCESS
   Primary teacher OR co-instructor
========================================== */

$stmt = mysqli_prepare($conn, "
    SELECT
        a.id,
        a.pinned,
        a.class_id
    FROM assessments a
    INNER JOIN classes c ON a.class_id = c.id
    WHERE a.id = ?
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
    die("Database error.");
}

mysqli_stmt_bind_param(
    $stmt,
    "iii",
    $assessment_id,
    $teacher_id,
    $teacher_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$assessment = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);

if (!$assessment) {
    die("Assessment not found or you do not have access to it.");
}

$class_id = (int) $assessment['class_id'];

/* ==========================================
   TOGGLE PIN STATUS
========================================== */

$new_status = ((int) $assessment['pinned'] === 1) ? 0 : 1;

$update = mysqli_prepare(
    $conn,
    "UPDATE assessments SET pinned = ? WHERE id = ?"
);

if (!$update) {
    die("Unable to update assessment.");
}

mysqli_stmt_bind_param(
    $update,
    "ii",
    $new_status,
    $assessment_id
);

if (!mysqli_stmt_execute($update)) {
    mysqli_stmt_close($update);
    die("Failed to update assessment.");
}

mysqli_stmt_close($update);

/* ==========================================
   REDIRECT
========================================== */

header("Location: teacher_class.php?id=" . $class_id);
exit;
?>