<?php
session_start();
include 'database.php';
include 'auth_helpers.php';
include_once 'notification_helper.php';
require_once 'assessment_attachments.php';

$role = $_SESSION['role'] ?? '';

/* Must be logged in as a teacher or program chair, and must be a POST */
if (empty($_SESSION['user_id']) || !in_array($role, ['teacher', 'program_chair'], true)) {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . ($role === 'program_chair' ? 'program_chair_dashboard.php' : 'teacher_classpage.php'));
    exit();
}

$user_id      = (int)$_SESSION['user_id'];
$class_id     = intval($_POST['class_id'] ?? 0);
$type         = trim($_POST['type'] ?? 'Quiz');
$title        = trim($_POST['title'] ?? '');
$instructions = trim($_POST['instructions'] ?? '');
$due_date     = aa_combine_datetime($_POST['due_date'] ?? '', $_POST['due_time'] ?? '');

if ($class_id <= 0 || $title === '') {
    die("Missing class or title.");
}

/* teacher: own class / program chair: any class in their program */
if (!getClassAccess($conn, $class_id, $user_id, $role)) {
    http_response_code(403);
    die("Access denied. You do not have access to this class.");
}

// Points: dropdown value, or the custom box when "Custom" is chosen
$points_raw = $_POST['points'] ?? '100';
$points = ($points_raw === 'custom')
    ? intval($_POST['custom_points'] ?? 0)
    : intval($points_raw);

$upload_dir = 'uploads/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

/* 1 Insert the assessment itself.
      image / attachment / link are filled in afterwards by aa_sync_legacy(). */
$link_placeholder       = '';
$image_placeholder      = null;
$attachment_placeholder = null;

$stmt = mysqli_prepare(
    $conn,
    "INSERT INTO assessments
    (
        class_id,
        type,
        title,
        instructions,
        due_date,
        points,
        link,
        image,
        attachment
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
);

if (!$stmt) {
    die("Database error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param(
    $stmt,
    "issssisss",
    $class_id,
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
    die("Could not save assessment: " . mysqli_stmt_error($stmt));
}
$assessment_id = mysqli_insert_id($conn);
mysqli_stmt_close($stmt);

/* 2 Optional columns (saved only if they exist in your table) */
$start_date = aa_combine_datetime($_POST['start_date'] ?? '', $_POST['start_time'] ?? '');
aa_update_optional($conn, $assessment_id, [
    'topic'      => trim($_POST['topic'] ?? ''),
    'start_date' => $start_date,
]);

/* 3 Attachments: images[], attachments[], links[]  (max 10 total) */
aa_save_new($conn, $assessment_id, $upload_dir);

/* 4 Keep the old single image/file/link columns in sync */
aa_sync_legacy($conn, $assessment_id);

/* 5 NOTIFY STUDENTS */
notify_class_students(
    $conn,
    $class_id,
    'assignment',
    'New ' . strtolower($type) . ' in ' . get_class_name($conn, $class_id) . ': ' . $title,
    'student_class.php?id=' . $class_id
);

/* teacher_assessment.php now serves both teachers and program chairs */
header("Location: teacher_assessment.php?id=" . $class_id);
exit();