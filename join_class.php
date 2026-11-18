
<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

function jsonResponse($success, $message, $classId = null)
{
    $data = [
        'success' => $success,
        'message' => $message
    ];

    if ($classId !== null) {
        $data['class_id'] = (int)$classId;
    }

    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jsonResponse(false, 'Invalid request method.');
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    jsonResponse(false, 'Please log in again.');
}

$student_id = (int)$_SESSION['user_id'];

if ($student_id <= 0) {
    http_response_code(401);
    jsonResponse(false, 'Invalid user session.');
}

if (isset($_SESSION['role']) &&
    $_SESSION['role'] !== 'student') {
    http_response_code(403);
    jsonResponse(false, 'Only students can join classes.');
}

$class_code = trim((string)($_POST['class_code'] ?? ''));

if ($class_code === '') {
    jsonResponse(false, 'Please enter a class code.');
}

if (strlen($class_code) > 20) {
    jsonResponse(false, 'Invalid class code format.');
}

try {
    require_once 'database.php';

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    /* ========================================
       VERIFY STUDENT
    ======================================== */

    $studentStmt = mysqli_prepare(
        $conn,
        "SELECT id FROM users WHERE id = ? LIMIT 1"
    );

    mysqli_stmt_bind_param($studentStmt, 'i', $student_id);
    mysqli_stmt_execute($studentStmt);

    $studentResult = mysqli_stmt_get_result($studentStmt);
    $student = mysqli_fetch_assoc($studentResult);

    mysqli_stmt_close($studentStmt);

    if (!$student) {
        http_response_code(401);
        jsonResponse(false, 'Student account not found.');
    }

    /* ========================================
       FIND CLASS
    ======================================== */

    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, class_name, class_code
         FROM classes
         WHERE class_code = ?
         LIMIT 1"
    );

    mysqli_stmt_bind_param($stmt, 's', $class_code);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $class = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    if (!$class) {
        jsonResponse(
            false,
            'Invalid class code. Please check and try again.'
        );
    }

    $class_id = (int)$class['id'];

    /* ========================================
       CHECK EXISTING ENROLLMENT
    ======================================== */

    $checkStmt = mysqli_prepare(
        $conn,
        "SELECT archived
         FROM class_students
         WHERE class_id = ?
         AND student_id = ?
         LIMIT 1"
    );

    mysqli_stmt_bind_param(
        $checkStmt,
        'ii',
        $class_id,
        $student_id
    );

    mysqli_stmt_execute($checkStmt);

    $checkResult = mysqli_stmt_get_result($checkStmt);
    $existing = mysqli_fetch_assoc($checkResult);

    mysqli_stmt_close($checkStmt);

    if ($existing) {

        if ((int)$existing['archived'] === 1) {

            $restoreStmt = mysqli_prepare(
                $conn,
                "UPDATE class_students
                 SET archived = 0
                 WHERE class_id = ?
                 AND student_id = ?"
            );

            mysqli_stmt_bind_param(
                $restoreStmt,
                'ii',
                $class_id,
                $student_id
            );

            mysqli_stmt_execute($restoreStmt);
            mysqli_stmt_close($restoreStmt);

            jsonResponse(
                true,
                'Class restored successfully.',
                $class_id
            );
        }

        jsonResponse(
            false,
            'You have already joined this class.'
        );
    }

    /* ========================================
       INSERT STUDENT ENROLLMENT
    ======================================== */

    $insertStmt = mysqli_prepare(
        $conn,
        "INSERT INTO class_students
         (class_id, student_id, archived)
         VALUES (?, ?, 0)"
    );

    mysqli_stmt_bind_param(
        $insertStmt,
        'ii',
        $class_id,
        $student_id
    );

    mysqli_stmt_execute($insertStmt);
    mysqli_stmt_close($insertStmt);

    jsonResponse(
        true,
        'Successfully joined the class.',
        $class_id
    );

} catch (Throwable $e) {

    error_log('Join Class Error: ' . $e->getMessage());

    http_response_code(500);

    jsonResponse(
        false,
        'A database error occurred. Check the PHP error log.'
    );
}
