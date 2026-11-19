
<?php
session_start();
require_once 'database.php';

header('Content-Type: application/json');

function sendResponse($success, $message) {
    echo json_encode([
        'success' => $success,
        'message' => $message
    ]);
    exit;
}

/* =========================================
   LOGIN AND ROLE VALIDATION
========================================= */

if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'teacher'
) {
    sendResponse(false, 'Unauthorized access.');
}

$teacher_id = (int) $_SESSION['user_id'];
$class_id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

if ($class_id <= 0) {
    sendResponse(false, 'Invalid class ID.');
}

/* =========================================
   VERIFY PRIMARY INSTRUCTOR OWNERSHIP
========================================= */

$check = mysqli_prepare(
    $conn,
    "SELECT id FROM classes
     WHERE id = ? AND teacher_id = ?
     LIMIT 1"
);

if (!$check) {
    sendResponse(false, 'Database error while checking class ownership.');
}

mysqli_stmt_bind_param($check, "ii", $class_id, $teacher_id);
mysqli_stmt_execute($check);
mysqli_stmt_store_result($check);

$is_owner = mysqli_stmt_num_rows($check) > 0;

mysqli_stmt_close($check);

if (!$is_owner) {
    sendResponse(
        false,
        'You are not authorized to delete this class. Only the primary instructor can delete it.'
    );
}

/* =========================================
   DELETE CLASS AND RELATED DATA
========================================= */

mysqli_begin_transaction($conn);

try {

    // Delete chat messages
    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM class_chat_messages WHERE class_id = ?"
    );

    if (!$stmt) {
        throw new Exception('Unable to prepare chat deletion.');
    }

    mysqli_stmt_bind_param($stmt, "i", $class_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);


    // Delete submissions belonging to assessments in this class
    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM submissions
         WHERE assessment_id IN (
             SELECT id FROM assessments WHERE class_id = ?
         )"
    );

    if (!$stmt) {
        throw new Exception('Unable to prepare submission deletion.');
    }

    mysqli_stmt_bind_param($stmt, "i", $class_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);


    // Delete assessment records
    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM assessments WHERE class_id = ?"
    );

    if (!$stmt) {
        throw new Exception('Unable to prepare assessment deletion.');
    }

    mysqli_stmt_bind_param($stmt, "i", $class_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);


    // Delete announcements
    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM announcements WHERE class_id = ?"
    );

    if (!$stmt) {
        throw new Exception('Unable to prepare announcement deletion.');
    }

    mysqli_stmt_bind_param($stmt, "i", $class_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);


    // Delete materials
    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM materials WHERE class_id = ?"
    );

    if (!$stmt) {
        throw new Exception('Unable to prepare material deletion.');
    }

    mysqli_stmt_bind_param($stmt, "i", $class_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);


    // Delete student enrollments
    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM class_students WHERE class_id = ?"
    );

    if (!$stmt) {
        throw new Exception('Unable to prepare enrollment deletion.');
    }

    mysqli_stmt_bind_param($stmt, "i", $class_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);


    // Delete instructor assignments
    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM class_instructors WHERE class_id = ?"
    );

    if (!$stmt) {
        throw new Exception('Unable to prepare instructor assignment deletion.');
    }

    mysqli_stmt_bind_param($stmt, "i", $class_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);


    // Finally, delete the class itself.
    // Ownership is checked again to prevent deleting another teacher's class.
    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM classes
         WHERE id = ? AND teacher_id = ?"
    );

    if (!$stmt) {
        throw new Exception('Unable to prepare class deletion.');
    }

    mysqli_stmt_bind_param($stmt, "ii", $class_id, $teacher_id);
    mysqli_stmt_execute($stmt);

    if (mysqli_stmt_affected_rows($stmt) !== 1) {
        mysqli_stmt_close($stmt);
        throw new Exception('Class could not be deleted.');
    }

    mysqli_stmt_close($stmt);

    mysqli_commit($conn);

    sendResponse(true, 'Class and its related records were deleted successfully.');

} catch (Throwable $e) {

    mysqli_rollback($conn);

    error_log('Delete class error: ' . $e->getMessage());

    sendResponse(
        false,
        'Unable to delete the class. Please check its related database records.'
    );
}
?>
