
<?php
session_start();

require_once 'database.php';
require_once 'auth_helpers.php';

/* =========================================
   AJAX DETECTION
========================================= */
$isAjax = (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    isset($_POST['ajax'])
);

/* =========================================
   ERROR RESPONSE
========================================= */
function grade_fail($isAjax, $message, $code = 400) {
    http_response_code($code);

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $message
        ]);
        exit;
    }

    exit($message);
}

/* =========================================
   LOGIN AND ROLE VALIDATION
========================================= */
$role = $_SESSION['role'] ?? '';

if (
    empty($_SESSION['user_id']) ||
    !in_array($role, ['teacher', 'program_chair'], true)
) {
    grade_fail($isAjax, 'Not authorized.', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    grade_fail($isAjax, 'Invalid request method.', 405);
}

$user_id = (int) $_SESSION['user_id'];

$assessment_id = (int) ($_POST['assessment_id'] ?? 0);
$student_id = (int) ($_POST['student_id'] ?? 0);
$grade = trim((string) ($_POST['grade'] ?? ''));

if ($assessment_id <= 0 || $student_id <= 0) {
    grade_fail($isAjax, 'Invalid assessment or student.');
}

/* =========================================
   GET ASSESSMENT INFORMATION
========================================= */
$aStmt = mysqli_prepare(
    $conn,
    "SELECT class_id, points
     FROM assessments
     WHERE id = ?
     LIMIT 1"
);

if (!$aStmt) {
    grade_fail($isAjax, 'Unable to retrieve assessment.', 500);
}

mysqli_stmt_bind_param($aStmt, 'i', $assessment_id);
mysqli_stmt_execute($aStmt);

$assessmentResult = mysqli_stmt_get_result($aStmt);
$assessment = mysqli_fetch_assoc($assessmentResult);

mysqli_stmt_close($aStmt);

if (!$assessment) {
    grade_fail($isAjax, 'Assessment not found.', 404);
}

$class_id = (int) $assessment['class_id'];
$max_points = (float) $assessment['points'];

if ($max_points < 0) {
    grade_fail($isAjax, 'Invalid assessment maximum points.', 500);
}

/* =========================================
   CLASS ACCESS VALIDATION
========================================= */

if ($role === 'teacher') {

    // Teacher must be the primary instructor
    // or an assigned co-instructor.
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
        grade_fail($isAjax, 'Unable to verify class access.', 500);
    }

    mysqli_stmt_bind_param(
        $accessStmt,
        'iii',
        $class_id,
        $user_id,
        $user_id
    );

    mysqli_stmt_execute($accessStmt);

    $accessResult = mysqli_stmt_get_result($accessStmt);
    $hasAccess = mysqli_fetch_assoc($accessResult);

    mysqli_stmt_close($accessStmt);

    if (!$hasAccess) {
        grade_fail(
            $isAjax,
            'Access denied. You are not assigned to this class.',
            403
        );
    }

} elseif ($role === 'program_chair') {

    // Program chair access is checked using the existing helper.
    if (!getClassAccess($conn, $class_id, $user_id, $role)) {
        grade_fail(
            $isAjax,
            'Access denied. This class is not under your program.',
            403
        );
    }
}

/* =========================================
   VERIFY STUDENT ENROLLMENT
========================================= */
$eStmt = mysqli_prepare(
    $conn,
    "SELECT 1
     FROM class_students
     WHERE class_id = ?
       AND student_id = ?
       AND (archived = 0 OR archived IS NULL)
     LIMIT 1"
);

if (!$eStmt) {
    grade_fail($isAjax, 'Unable to verify student enrollment.', 500);
}

mysqli_stmt_bind_param($eStmt, 'ii', $class_id, $student_id);
mysqli_stmt_execute($eStmt);

$enrollmentResult = mysqli_stmt_get_result($eStmt);
$enrolled = mysqli_fetch_assoc($enrollmentResult);

mysqli_stmt_close($eStmt);

if (!$enrolled) {
    grade_fail(
        $isAjax,
        'This student is not actively enrolled in this class.',
        400
    );
}

/* =========================================
   CLEAR GRADE
========================================= */
if ($grade === '') {

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE submissions
         SET grade = NULL,
             graded_at = NULL
         WHERE assessment_id = ?
           AND student_id = ?"
    );

    if (!$stmt) {
        grade_fail($isAjax, 'Unable to clear grade.', 500);
    }

    mysqli_stmt_bind_param($stmt, 'ii', $assessment_id, $student_id);

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        grade_fail($isAjax, 'Failed to clear grade.', 500);
    }

    mysqli_stmt_close($stmt);

} else {

    /* =====================================
       VALIDATE GRADE
    ===================================== */
    if (!is_numeric($grade)) {
        grade_fail($isAjax, 'Grade must be a valid number.');
    }

    $grade = (float) $grade;

    if (!is_finite($grade)) {
        grade_fail($isAjax, 'Invalid grade value.');
    }

    if ($grade < 0 || $grade > $max_points) {
        grade_fail(
            $isAjax,
            'Grade must be between 0 and ' . $max_points . '.'
        );
    }

    /* =====================================
       INSERT OR UPDATE GRADE
    ===================================== */
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO submissions
            (assessment_id, student_id, grade, graded_at, status)
         VALUES (?, ?, ?, NOW(), 'missing')
         ON DUPLICATE KEY UPDATE
            grade = VALUES(grade),
            graded_at = NOW()"
    );

    if (!$stmt) {
        grade_fail($isAjax, 'Unable to prepare grade saving.', 500);
    }

    mysqli_stmt_bind_param(
        $stmt,
        'iid',
        $assessment_id,
        $student_id,
        $grade
    );

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        grade_fail($isAjax, 'Failed to save grade.', 500);
    }

    mysqli_stmt_close($stmt);
}

/* =========================================
   AJAX SUCCESS RESPONSE
========================================= */
if ($isAjax) {
    header('Content-Type: application/json');

    echo json_encode([
        'success' => true,
        'message' => 'Grade saved successfully.'
    ]);

    exit;
}

/* =========================================
   NON-AJAX REDIRECT
========================================= */
$_SESSION['flash'] = 'Grade saved successfully.';

$redirect = null;

$ref = $_SERVER['HTTP_REFERER'] ?? '';

if ($ref !== '') {

    $refHost = parse_url($ref, PHP_URL_HOST);
    $myHost = $_SERVER['HTTP_HOST'] ?? '';

    $myHostName = preg_replace('/:\d+$/', '', $myHost);

    if (
        $refHost !== null &&
        strcasecmp($refHost, $myHostName) === 0
    ) {
        $redirect = $ref;
    }
}

if ($redirect === null) {
    $redirect = 'teacher_grades.php?id=' . $class_id;
}

header('Location: ' . $redirect);
exit;
