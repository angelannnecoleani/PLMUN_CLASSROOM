
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
   VALIDATE ANNOUNCEMENT ID
========================================== */

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    die("Invalid announcement request.");
}

/* ==========================================
   FETCH ANNOUNCEMENT AND VERIFY ACCESS
   Primary teacher OR co-instructor
========================================== */

$stmt = mysqli_prepare($conn, "
    SELECT
        a.id,
        a.class_id,
        a.image
    FROM announcements a
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
    $id,
    $teacher_id,
    $teacher_id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$announcement = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);

if (!$announcement) {
    die("Announcement not found or you do not have access to it.");
}

$class_id = (int) $announcement['class_id'];
$image = $announcement['image'] ?? '';

/* ==========================================
   DELETE ANNOUNCEMENT
========================================== */

$delete = mysqli_prepare(
    $conn,
    "DELETE FROM announcements WHERE id = ?"
);

if (!$delete) {
    die("Unable to prepare announcement deletion.");
}

mysqli_stmt_bind_param($delete, "i", $id);

if (!mysqli_stmt_execute($delete)) {
    mysqli_stmt_close($delete);
    die("Failed to delete announcement.");
}

mysqli_stmt_close($delete);

/* ==========================================
   DELETE ASSOCIATED IMAGE
   Only after successful database deletion
========================================== */

if ($image !== '') {

    $image_path = __DIR__ . '/uploads/' . basename($image);

    if (is_file($image_path)) {
        unlink($image_path);
    }
}

/* ==========================================
   REDIRECT BACK TO CLASS
========================================== */

header("Location: teacher_class.php?id=" . $class_id);
exit;
?>
