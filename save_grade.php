<?php
session_start();
include 'database.php';
include 'auth_helpers.php';

/* Is this an AJAX call (e.g. from the grades page)? */
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || isset($_POST['ajax']);

/* Respond with an error, as JSON for AJAX or plain text otherwise */
function grade_fail($isAjax, $message, $code = 400) {
    http_response_code($code);
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }
    die($message);
}

/* ── LOGIN + ROLE CHECK ── */
$role = $_SESSION['role'] ?? '';

if (empty($_SESSION['user_id']) || !in_array($role, ['teacher', 'program_chair'], true)) {
    grade_fail($isAjax, "Not authorized.", 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    grade_fail($isAjax, "Invalid request", 405);
}

$user_id       = (int)$_SESSION['user_id'];
$assessment_id = intval($_POST['assessment_id'] ?? 0);
$student_id    = intval($_POST['student_id'] ?? 0);
$grade         = trim((string)($_POST['grade'] ?? ''));

if ($assessment_id <= 0 || $student_id <= 0) {
    grade_fail($isAjax, "Invalid request");
}

/* ── LOAD THE ASSESSMENT (class + max points) FROM THE DATABASE ── */
$aStmt = mysqli_prepare($conn, "SELECT class_id, points FROM assessments WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($aStmt, "i", $assessment_id);
mysqli_stmt_execute($aStmt);
$assessment = mysqli_fetch_assoc(mysqli_stmt_get_result($aStmt));
mysqli_stmt_close($aStmt);

if (!$assessment) {
    grade_fail($isAjax, "Assessment not found", 404);
}

$class_id   = (int)$assessment['class_id'];
$max_points = (float)$assessment['points'];

/* teacher: own class / program chair: any class in their program */
if (!getClassAccess($conn, $class_id, $user_id, $role)) {
    grade_fail($isAjax, "Access denied. You cannot grade this assessment.", 403);
}

/* The student must actually be enrolled in this class */
$eStmt = mysqli_prepare($conn, "SELECT 1 FROM class_students WHERE class_id = ? AND student_id = ? LIMIT 1");
mysqli_stmt_bind_param($eStmt, "ii", $class_id, $student_id);
mysqli_stmt_execute($eStmt);
$enrolled = mysqli_fetch_assoc(mysqli_stmt_get_result($eStmt));
mysqli_stmt_close($eStmt);

if (!$enrolled) {
    grade_fail($isAjax, "This student is not enrolled in the class.", 400);
}

/* ── SAVE THE GRADE ── */
if ($grade === '') {

    // Clear grade
    $stmt = mysqli_prepare($conn, "
        UPDATE submissions SET grade = NULL, graded_at = NULL
        WHERE assessment_id = ? AND student_id = ?
    ");
    mysqli_stmt_bind_param($stmt, "ii", $assessment_id, $student_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

} else {

    if (!is_numeric($grade)) {
        grade_fail($isAjax, "Grade must be a number.");
    }

    $grade = floatval($grade);

    if ($grade < 0 || $grade > $max_points) {
        grade_fail($isAjax, "Grade must be between 0 and " . $max_points . ".");
    }

    // Upsert
    $stmt = mysqli_prepare($conn, "
        INSERT INTO submissions (assessment_id, student_id, grade, graded_at, status)
        VALUES (?, ?, ?, NOW(), 'missing')
        ON DUPLICATE KEY UPDATE grade = VALUES(grade), graded_at = NOW()
    ");
    mysqli_stmt_bind_param($stmt, "iid", $assessment_id, $student_id, $grade);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/* AJAX (e.g. grades page): just return OK */
if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

/* Otherwise redirect back — only to a page on this same site */
$_SESSION['flash'] = 'Grade saved.';

$redirect = null;
$ref = $_SERVER['HTTP_REFERER'] ?? '';
if ($ref !== '') {
    $refHost = parse_url($ref, PHP_URL_HOST);
    $myHost  = $_SERVER['HTTP_HOST'] ?? '';
    // strip a port from HTTP_HOST for comparison
    $myHostName = preg_replace('/:\d+$/', '', $myHost);
    if ($refHost !== null && strcasecmp($refHost, $myHostName) === 0) {
        $redirect = $ref;
    }
}

if ($redirect === null) {
    $redirect = ($role === 'program_chair')
        ? "teacher_submissions.php?id=" . $assessment_id
        : "teacher_grades.php?id=" . $class_id;
}

header("Location: " . $redirect);
exit;