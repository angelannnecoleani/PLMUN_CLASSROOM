<?php

session_start();
include 'database.php';
include 'activity_log.php';


/* =========================================================
   ADMIN ACCESS CONTROL
========================================================= */

if (!isset($_SESSION['user_id'])) {

    header("Location: login.html");

    exit();
}


if (
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {

    header("Location: login.html");

    exit();
}


/* =========================================================
   ADMIN INFORMATION
========================================================= */

$admin_id =
    intval($_SESSION['user_id']);


$adminQuery = mysqli_query(
    $conn,
    "SELECT *
     FROM users
     WHERE id='$admin_id'
     LIMIT 1"
);


if (
    !$adminQuery ||
    mysqli_num_rows($adminQuery) == 0
) {

    session_destroy();

    header("Location: login.html");

    exit();
}


$admin =
    mysqli_fetch_assoc(
        $adminQuery
    );


/* =========================================================
   GET COURSE ID
========================================================= */

$id =
    intval(
        $_GET['id'] ?? 0
    );


if ($id <= 0) {

    header(
        "Location: admin_courses.php"
    );

    exit();
}


/* =========================================================
   GET COURSE INFORMATION BEFORE DELETING
========================================================= */

$courseQuery = mysqli_query(
    $conn,
    "SELECT
        c.*,
        u.name AS teacher_name
     FROM classes c
     LEFT JOIN users u
        ON c.teacher_id = u.id
     WHERE c.id='$id'
     LIMIT 1"
);


/* =========================================================
   COURSE MUST EXIST
========================================================= */

if (
    !$courseQuery ||
    mysqli_num_rows($courseQuery) == 0
) {

    header(
        "Location: admin_courses.php"
    );

    exit();
}


$course =
    mysqli_fetch_assoc(
        $courseQuery
    );


/* =========================================================
   COURSE DATA FOR LOG
========================================================= */

$courseName =
    $course['class_name'] ?? 'Unknown Course';

$section =
    $course['section'] ?? '-';

$academicYear =
    $course['academic_year'] ?? '-';

$classCode =
    $course['class_code'] ?? '-';

$teacherName =
    $course['teacher_name'] ?? 'No Teacher';


/* =========================================================
   DELETE ENROLLED STUDENTS FIRST
========================================================= */

$deleteStudents =
    mysqli_query(
        $conn,
        "DELETE FROM class_students
         WHERE class_id='$id'"
    );


/* =========================================================
   DELETE COURSE
========================================================= */

$deleteCourse =
    mysqli_query(
        $conn,
        "DELETE FROM classes
         WHERE id='$id'"
    );


/* =========================================================
   IF DELETED SUCCESSFULLY
========================================================= */

if (
    $deleteCourse
) {


    /* ================================================
       ACTIVITY LOG
    ================================================= */

    addActivityLog(
        $conn,
        $admin_id,
        $admin['name'],
        'admin',
        'Course Deleted',
        "Deleted course '$courseName' "
        . "for section '$section'. "
        . "Academic Year: $academicYear. "
        . "Class Code: $classCode. "
        . "Teacher: $teacherName"
    );


}


/* =========================================================
   RETURN TO COURSE MANAGEMENT
========================================================= */

header(
    "Location: admin_courses.php"
);

exit();

?>