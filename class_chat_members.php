
<?php
session_start();

require_once 'database.php';
require_once 'auth_helpers.php';
require_once 'class_chat_mentions_lib.php';

header('Content-Type: application/json; charset=utf-8');

/* =========================================
   JSON RESPONSE
========================================= */
function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

/* =========================================
   LOGIN VALIDATION
========================================= */
if (empty($_SESSION['user_id'])) {
    out([
        'success' => false,
        'error' => 'Not logged in'
    ], 401);
}

$uid      = (int)$_SESSION['user_id'];
$role     = $_SESSION['role'] ?? '';
$class_id = (int)($_GET['class_id'] ?? 0);

if ($class_id <= 0) {
    out([
        'success' => false,
        'error' => 'Invalid class'
    ], 400);
}

/* =========================================
   ROLE VALIDATION
========================================= */
if (!in_array($role, ['teacher', 'program_chair', 'student'], true)) {
    out([
        'success' => false,
        'error' => 'Access denied'
    ], 403);
}

/* =========================================
   CHECK CLASS ACCESS
========================================= */
$allowed = false;

if ($role === 'teacher') {

    // Primary instructor or assigned co-instructor
    $stmt = mysqli_prepare($conn, "
        SELECT 1
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
        out([
            'success' => false,
            'error' => 'Database query failed'
        ], 500);
    }

    mysqli_stmt_bind_param($stmt, 'iii', $class_id, $uid, $uid);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $allowed = ($result && mysqli_num_rows($result) > 0);

    mysqli_stmt_close($stmt);

} elseif ($role === 'program_chair') {

    // Use the existing class-access helper
    $access = getClassAccess($conn, $class_id, $uid, $role);
    $allowed = !empty($access);

} elseif ($role === 'student') {

    // Only currently enrolled, non-archived students
    $stmt = mysqli_prepare($conn, "
        SELECT 1
        FROM class_students
        WHERE class_id = ?
          AND student_id = ?
          AND (archived = 0 OR archived IS NULL)
        LIMIT 1
    ");

    if (!$stmt) {
        out([
            'success' => false,
            'error' => 'Database query failed'
        ], 500);
    }

    mysqli_stmt_bind_param($stmt, 'ii', $class_id, $uid);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $allowed = ($result && mysqli_num_rows($result) > 0);

    mysqli_stmt_close($stmt);
}

/* =========================================
   DENY UNAUTHORIZED ACCESS
========================================= */
if (!$allowed) {
    out([
        'success' => false,
        'error' => 'You are not allowed to access this class chat'
    ], 403);
}

/* =========================================
   GET CLASS MEMBERS FOR @MENTIONS
========================================= */
$members = chat_class_members($conn, $class_id);

out([
    'success' => true,
    'members' => $members
]);