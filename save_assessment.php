
<?php
session_start();

require_once 'database.php';
require_once 'auth_helpers.php';
require_once 'notification_helper.php';
require_once 'assessment_attachments.php';

$role = $_SESSION['role'] ?? '';

/* =========================================
   LOGIN AND ROLE VALIDATION
========================================= */

if (
    empty($_SESSION['user_id']) ||
    !in_array($role, ['teacher', 'program_chair'], true)
) {
    header("Location: login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];

/* =========================================
   POST REQUEST VALIDATION
========================================= */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header(
        "Location: " .
        ($role === 'program_chair'
            ? 'program_chair_dashboard.php'
            : 'teacher_classpage.php')
    );
    exit();
}

/* =========================================
   GET FORM DATA
========================================= */

$class_id = (int) ($_POST['class_id'] ?? 0);

$type = trim($_POST['type'] ?? 'Quiz');
$title = trim($_POST['title'] ?? '');
$instructions = trim($_POST['instructions'] ?? '');

$due_date = aa_combine_datetime(
    $_POST['due_date'] ?? '',
    $_POST['due_time'] ?? ''
);

/* =========================================
   VALIDATE REQUIRED FIELDS
========================================= */

if ($class_id <= 0 || $title === '') {
    exit("Missing class or assessment title.");
}

/* =========================================
   VERIFY CLASS ACCESS
========================================= */

$class = false;

if ($role === 'teacher') {

    // Primary instructor OR additional assigned instructor.

    $accessStmt = mysqli_prepare(
        $conn,
        "SELECT c.id, c.class_name
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
        exit("Database error: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param(
        $accessStmt,
        "iii",
        $class_id,
        $user_id,
        $user_id
    );

    mysqli_stmt_execute($accessStmt);

    $accessResult = mysqli_stmt_get_result($accessStmt);
    $class = mysqli_fetch_assoc($accessResult);

    mysqli_stmt_close($accessStmt);

} elseif ($role === 'program_chair') {

    $class = getClassAccess(
        $conn,
        $class_id,
        $user_id,
        $role
    );
}

if (!$class) {
    http_response_code(403);
    exit("Access denied. You do not have permission to create an assessment in this class.");
}

/* =========================================
   VALIDATE POINTS
========================================= */

$points_raw = $_POST['points'] ?? '100';

if ($points_raw === 'custom') {
    $points = (int) ($_POST['custom_points'] ?? 0);
} else {
    $points = (int) $points_raw;
}

if ($points <= 0) {
    exit("Please enter a valid assessment point value.");
}

/* =========================================
   UPLOAD DIRECTORY
========================================= */

$created_by = $user_id;

$upload_dir = __DIR__ . '/uploads/';

if (!is_dir($upload_dir)) {
    if (!mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
        exit("Unable to create uploads directory.");
    }
}

/* =========================================
   INSERT ASSESSMENT
   SAVE ACTUAL LOGGED-IN CREATOR
========================================= */

$link_placeholder = '';
$image_placeholder = null;
$attachment_placeholder = null;

$stmt = mysqli_prepare(
    $conn,
    "INSERT INTO assessments
    (
        class_id,
        created_by,
        type,
        title,
        instructions,
        due_date,
        points,
        link,
        image,
        attachment
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);

if (!$stmt) {
    exit("Database error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param(
    $stmt,
    "iissssisss",
    $class_id,
    $created_by,
    $type,
    $title,
    $instructions,
    $due_date,
    $points,
    $link_placeholder,
    $image_placeholder,
    $attachment_placeholder
);

if (!mysqli_stmt_execute($stmt)) {
    $error = mysqli_stmt_error($stmt);
    mysqli_stmt_close($stmt);
    exit("Could not save assessment: " . $error);
}

$assessment_id = mysqli_insert_id($conn);

mysqli_stmt_close($stmt);

/* =========================================
   SAVE OPTIONAL COLUMNS
========================================= */

$start_date = aa_combine_datetime(
    $_POST['start_date'] ?? '',
    $_POST['start_time'] ?? ''
);

aa_update_optional($conn, $assessment_id, [
    'topic'      => trim($_POST['topic'] ?? ''),
    'start_date' => $start_date,
]);

/* =========================================
   SAVE ASSESSMENT ATTACHMENTS
========================================= */

aa_save_new(
    $conn,
    $assessment_id,
    $upload_dir
);

/* =========================================
   SYNC LEGACY ATTACHMENT COLUMNS
========================================= */

aa_sync_legacy(
    $conn,
    $assessment_id
);

/* =========================================
   NOTIFY CLASS STUDENTS
========================================= */

$class_name = $class['class_name'] ?? get_class_name($conn, $class_id);

notify_class_students(
    $conn,
    $class_id,
    'assignment',
    'New ' . strtolower($type) . ' in ' . $class_name . ': ' . $title,
    'student_class.php?id=' . $class_id
);

/* =========================================
   REDIRECT
========================================= */

header("Location: teacher_assessment.php?id=" . $class_id);
exit();
?>
