
<?php
session_start();

require_once 'database.php';
require_once 'auth_helpers.php';
require_once 'assessment_attachments.php';

/* =========================================
   1. LOGIN AND ROLE VALIDATION
========================================= */

if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

if (!in_array($role, ['teacher', 'program_chair'], true)) {
    die("Access denied.");
}

/* =========================================
   2. REQUEST VALIDATION
========================================= */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: teacher_dashboard.php");
    exit();
}

$assessment_id = (int) ($_POST['id'] ?? 0);

if ($assessment_id <= 0) {
    die("Assessment not found.");
}

/* =========================================
   3. LOAD ASSESSMENT AND CLASS
========================================= */

$s = mysqli_prepare(
    $conn,
    "SELECT class_id
     FROM assessments
     WHERE id = ?
     LIMIT 1"
);

if (!$s) {
    die("Database error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($s, "i", $assessment_id);
mysqli_stmt_execute($s);

$existing = mysqli_fetch_assoc(mysqli_stmt_get_result($s));

mysqli_stmt_close($s);

if (!$existing) {
    die("Assessment not found.");
}

$class_id = (int) $existing['class_id'];

/* =========================================
   4. VERIFY CLASS ACCESS
========================================= */

if ($role === 'teacher') {

    // Primary teacher or assigned co-instructor
    $accessStmt = mysqli_prepare(
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

    if (!$accessStmt) {
        die("Database error: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param(
        $accessStmt,
        "iii",
        $class_id,
        $user_id,
        $user_id
    );

    mysqli_stmt_execute($accessStmt);

    $hasAccess = mysqli_fetch_assoc(
        mysqli_stmt_get_result($accessStmt)
    );

    mysqli_stmt_close($accessStmt);

    if (!$hasAccess) {
        die("Access denied. You are not assigned to this class.");
    }

} elseif ($role === 'program_chair') {

    // Use the existing project permission helper
    if (!getClassAccess($conn, $class_id, $user_id, $role)) {
        die("Access denied. You do not have permission to manage this class.");
    }
}

/* =========================================
   5. VALIDATE FORM DATA
========================================= */

$type = trim($_POST['type'] ?? 'Quiz');
$title = trim($_POST['title'] ?? '');
$instructions = trim($_POST['instructions'] ?? '');

$points = isset($_POST['points'])
    ? (int) $_POST['points']
    : 100;

$allowedTypes = [
    'Quiz',
    'Assignment',
    'Activity',
    'Exam'
];

if (!in_array($type, $allowedTypes, true)) {
    die("Invalid assessment type.");
}

if ($title === '') {
    die("Title is required.");
}

if ($points < 0) {
    die("Points cannot be negative.");
}

$due_date = aa_combine_datetime(
    $_POST['due_date'] ?? '',
    $_POST['due_time'] ?? ''
);

$start_date = aa_combine_datetime(
    $_POST['start_date'] ?? '',
    $_POST['start_time'] ?? ''
);

$upload_dir = 'uploads/';

/* =========================================
   6. UPDATE ASSESSMENT AND ATTACHMENTS
========================================= */

mysqli_begin_transaction($conn);

try {

    // Migrate old single image/file/link attachments
    // before changing the assessment.
    aa_ensure_migrated($conn, $assessment_id);

    // Update the main assessment fields
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE assessments
         SET type = ?,
             title = ?,
             instructions = ?,
             due_date = ?,
             points = ?
         WHERE id = ?"
    );

    if (!$stmt) {
        throw new Exception(mysqli_error($conn));
    }

    mysqli_stmt_bind_param(
        $stmt,
        "ssssii",
        $type,
        $title,
        $instructions,
        $due_date,
        $points,
        $assessment_id
    );

    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception(mysqli_stmt_error($stmt));
    }

    mysqli_stmt_close($stmt);

    // Update optional start_date column if available
    aa_update_optional($conn, $assessment_id, [
        'start_date' => $start_date
    ]);

    // Remove attachments selected for deletion
    aa_remove(
        $conn,
        $assessment_id,
        $_POST['remove_attachments'] ?? [],
        $upload_dir
    );

    // Save newly uploaded attachments
    aa_save_new(
        $conn,
        $assessment_id,
        $upload_dir
    );

    // Synchronize legacy attachment columns
    aa_sync_legacy($conn, $assessment_id);

    mysqli_commit($conn);

} catch (Throwable $e) {

    mysqli_rollback($conn);

    die(
        "Unable to update assessment. Please check your form and attachments. "
        . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
    );
}

/* =========================================
   7. REDIRECT
========================================= */

header("Location: teacher_assessment.php?id=" . $class_id);
exit();
?>
