<?php

session_start();
include 'database.php';


/* =========================================================
   ADMIN ACCESS
========================================================= */

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.html");
    exit();
}


/* =========================================================
   GET FORM DATA
========================================================= */

$class_name = trim($_POST['class_name'] ?? '');
$section = trim($_POST['section'] ?? '');
$academic_year = trim($_POST['academic_year'] ?? '');
$class_code = trim($_POST['class_code'] ?? '');

$max_students = intval(
    $_POST['max_students'] ?? 50
);


/* =========================================================
   VALIDATION
========================================================= */

if (
    empty($class_name) ||
    empty($section) ||
    empty($academic_year) ||
    empty($class_code)
) {

    die("Please complete all required fields.");

}


/*
 * Maximum students cannot exceed 50.
 */

if ($max_students < 1) {

    $max_students = 1;

}

if ($max_students > 50) {

    $max_students = 50;

}


/* =========================================================
   CHECK CLASS CODE
========================================================= */

$check = mysqli_prepare(
    $conn,
    "SELECT id FROM classes WHERE class_code = ?"
);

mysqli_stmt_bind_param(
    $check,
    "s",
    $class_code
);

mysqli_stmt_execute($check);

$result = mysqli_stmt_get_result($check);

if (mysqli_num_rows($result) > 0) {

    echo "<script>
        alert('Class code already exists.');
        window.location='admin_courses.php';
    </script>";

    exit();

}


/* =========================================================
   INSERT COURSE
========================================================= */

$stmt = mysqli_prepare(
    $conn,
    "INSERT INTO classes
    (
        class_name,
        section,
        academic_year,
        class_code,
        max_students
    )
    VALUES (?, ?, ?, ?, ?)"
);

mysqli_stmt_bind_param(
    $stmt,
    "ssssi",
    $class_name,
    $section,
    $academic_year,
    $class_code,
    $max_students
);


if (mysqli_stmt_execute($stmt)) {

    echo "<script>

        alert('Course successfully added.');

        window.location='admin_courses.php';

    </script>";

} else {

    echo "Error: " . mysqli_error($conn);

}

?>