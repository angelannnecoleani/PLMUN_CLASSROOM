
<?php
session_start();

require_once 'database.php';
require_once 'auth_helpers.php';

/* =====================================================
   LOGIN AND ROLE VALIDATION
===================================================== */

if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$role = $_SESSION['role'] ?? '';

if (!in_array($role, ['teacher', 'program_chair'], true)) {
    http_response_code(403);
    exit("Access denied.");
}

/* =====================================================
   VALIDATE ASSESSMENT ID
===================================================== */

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    exit("Assessment not found.");
}

$assessment_id = (int) $_GET['id'];
$user_id = (int) $_SESSION['user_id'];

/* =====================================================
   FETCH ASSESSMENT AND VERIFY CLASS ACCESS
===================================================== */

$check = mysqli_prepare($conn, "
    SELECT
        a.id,
        a.class_id,
        c.class_name
    FROM assessments a
    INNER JOIN classes c ON c.id = a.class_id
    WHERE a.id = ?
    LIMIT 1
");

if (!$check) {
    exit("Database error: " . htmlspecialchars(mysqli_error($conn)));
}

mysqli_stmt_bind_param($check, "i", $assessment_id);
mysqli_stmt_execute($check);

$assessment = mysqli_fetch_assoc(
    mysqli_stmt_get_result($check)
);

mysqli_stmt_close($check);

if (!$assessment) {
    http_response_code(404);
    exit("Assessment not found.");
}

$class_id = (int) $assessment['class_id'];

/* =====================================================
   TEACHER ACCESS
   Primary instructor OR assigned instructor
===================================================== */

if ($role === 'teacher') {

    $accessStmt = mysqli_prepare($conn, "
        SELECT c.id
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

    if (!$accessStmt) {
        exit("Database error while checking instructor access.");
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
        http_response_code(403);
        exit("Access denied. You are not assigned to this class.");
    }
}

/* =====================================================
   PROGRAM CHAIR ACCESS
===================================================== */

if ($role === 'program_chair') {

    $classAccess = getClassAccess(
        $conn,
        $class_id,
        $user_id,
        $role
    );

    if (!$classAccess) {
        http_response_code(403);
        exit("Access denied. This class is not under your program.");
    }
}

/* =====================================================
   DELETE ASSESSMENT
===================================================== */

mysqli_begin_transaction($conn);

try {

    /* Delete assessment submissions first */
    $delSubs = mysqli_prepare(
        $conn,
        "DELETE FROM submissions WHERE assessment_id = ?"
    );

    if (!$delSubs) {
        throw new Exception("Unable to prepare submission deletion.");
    }

    mysqli_stmt_bind_param($delSubs, "i", $assessment_id);

    if (!mysqli_stmt_execute($delSubs)) {
        throw new Exception("Unable to delete assessment submissions.");
    }

    mysqli_stmt_close($delSubs);

    /* Delete the assessment */
    $delAssessment = mysqli_prepare(
        $conn,
        "DELETE FROM assessments WHERE id = ? AND class_id = ?"
    );

    if (!$delAssessment) {
        throw new Exception("Unable to prepare assessment deletion.");
    }

    mysqli_stmt_bind_param(
        $delAssessment,
        "ii",
        $assessment_id,
        $class_id
    );

    if (!mysqli_stmt_execute($delAssessment)) {
        throw new Exception("Unable to delete the assessment.");
    }

    if (mysqli_stmt_affected_rows($delAssessment) < 1) {
        throw new Exception("Assessment was not deleted.");
    }

    mysqli_stmt_close($delAssessment);

    mysqli_commit($conn);

} catch (Throwable $e) {

    mysqli_rollback($conn);

    error_log("Delete assessment error: " . $e->getMessage());

    http_response_code(500);
    exit("Unable to delete the assessment. Please try again.");
}

/* =====================================================
   REDIRECT
===================================================== */

header("Location: teacher_assessment.php?id=" . $class_id);
exit();
