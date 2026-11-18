
<?php
session_start();

require_once 'database.php';
require_once __DIR__ . '/activity_log_helper.php';

/* =========================================
   LOGIN VALIDATION
========================================= */

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: login.php");
    exit();
}

/* =========================================
   REQUEST VALIDATION
========================================= */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: create_class.php");
    exit();
}

$teacher_id = (int) $_SESSION['user_id'];

/* =========================================
   GET FORM DATA
========================================= */

$class_name = trim($_POST['class_name'] ?? '');
$section    = trim($_POST['section'] ?? '');
$schedule   = trim($_POST['schedule'] ?? '');
$room       = trim($_POST['room'] ?? '');

/* =========================================
   REQUIRED FIELD VALIDATION
========================================= */

if (
    $class_name === '' ||
    $section === '' ||
    $schedule === '' ||
    $room === ''
) {
    echo "<script>
        alert('Please complete all required fields.');
        window.location.href = 'create_class.php';
    </script>";
    exit();
}

/* =========================================
   GENERATE CLASS CODE
========================================= */

$class_code = strtoupper(substr(md5(uniqid('', true)), 0, 6));

/* =========================================
   INSERT CLASS
========================================= */

$sql = "INSERT INTO classes
(
    teacher_id,
    class_name,
    section,
    schedule,
    room,
    class_code
)
VALUES (?, ?, ?, ?, ?, ?)";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    error_log("Class preparation failed: " . mysqli_error($conn));
    die("Unable to prepare class creation.");
}

mysqli_stmt_bind_param(
    $stmt,
    "isssss",
    $teacher_id,
    $class_name,
    $section,
    $schedule,
    $room,
    $class_code
);

/* =========================================
   SAVE CLASS
========================================= */

if (mysqli_stmt_execute($stmt)) {

    mysqli_stmt_close($stmt);

    /* =====================================
       RECORD ACTIVITY
    ===================================== */

    $description =
        'Created class: ' . $class_name .
        ' | Section: ' . $section .
        ' | Schedule: ' . $schedule .
        ' | Room: ' . $room .
        ' | Class Code: ' . $class_code;

    if (!addActivityLog(
        $conn,
        'Create Class',
        $description
    )) {
        error_log("Activity logging failed for class: " . $class_name);
    }

    header("Location: teacher_dashboard.php");
    exit();

} else {

    $error = mysqli_stmt_error($stmt);

    mysqli_stmt_close($stmt);

    error_log("Class creation failed: " . $error);

    die("Unable to create class. Please try again.");
}
?>
