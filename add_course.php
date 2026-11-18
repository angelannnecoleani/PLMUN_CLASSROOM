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
   VARIABLES
========================================================= */

$error = '';

$class_name = '';
$section = '';
$academic_year = '';
$teacher_id = '';


/* =========================================================
   GET TEACHERS
========================================================= */

$teacherQuery = mysqli_query(
    $conn,
    "SELECT id, name
     FROM users
     WHERE role='teacher'
     ORDER BY name ASC"
);


/* =========================================================
   RANDOM CLASS CODE
========================================================= */

function generateClassCode($conn)
{

    $characters =
        'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';


    do {

        $code = 'PLM';


        for (
            $i = 0;
            $i < 5;
            $i++
        ) {

            $code .=
                $characters[
                    random_int(
                        0,
                        strlen($characters) - 1
                    )
                ];

        }


        $safeCode =
            mysqli_real_escape_string(
                $conn,
                $code
            );


        $check =
            mysqli_query(
                $conn,
                "SELECT id
                 FROM classes
                 WHERE class_code='$safeCode'
                 LIMIT 1"
            );


    } while (
        $check &&
        mysqli_num_rows($check) > 0
    );


    return $code;
}


/* =========================================================
   CREATE COURSE
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {


    $class_name =
        trim(
            $_POST['class_name'] ?? ''
        );


    $section =
        trim(
            $_POST['section'] ?? ''
        );


    $academic_year =
        trim(
            $_POST['academic_year'] ?? ''
        );


    $teacher_id =
        intval(
            $_POST['teacher_id'] ?? 0
        );


    /* =====================================================
       VALIDATION
    ===================================================== */

    if (
        $class_name === ''
    ) {

        $error =
            "Please enter the course name.";

    }

    elseif (
        $section === ''
    ) {

        $error =
            "Please enter the section.";

    }

    elseif (
        $academic_year === ''
    ) {

        $error =
            "Please enter the academic year.";

    }

    elseif (
        $teacher_id <= 0
    ) {

        $error =
            "Please select a teacher.";

    }

    else {


        /* ================================================
           CHECK TEACHER
        ================================================= */

        $teacherCheck =
            mysqli_query(
                $conn,
                "SELECT id, name
                 FROM users
                 WHERE id='$teacher_id'
                 AND role='teacher'
                 LIMIT 1"
            );


        if (
            !$teacherCheck
        ) {

            $error =
                "Database error while checking teacher: "
                . mysqli_error($conn);

        }

        elseif (
            mysqli_num_rows(
                $teacherCheck
            ) == 0
        ) {

            $error =
                "Selected teacher is invalid.";

        }

        else {


            /* ============================================
               TEACHER DATA
            ============================================= */

            $teacherData =
                mysqli_fetch_assoc(
                    $teacherCheck
                );


            /* ============================================
               GENERATE CLASS CODE
            ============================================= */

            $class_code =
                generateClassCode(
                    $conn
                );


            /* ============================================
               ESCAPE DATA
            ============================================= */

            $safeClassName =
                mysqli_real_escape_string(
                    $conn,
                    $class_name
                );


            $safeSection =
                mysqli_real_escape_string(
                    $conn,
                    $section
                );


            $safeAcademicYear =
                mysqli_real_escape_string(
                    $conn,
                    $academic_year
                );


            $safeClassCode =
                mysqli_real_escape_string(
                    $conn,
                    $class_code
                );


            /* ============================================
               INSERT COURSE
            ============================================= */

            $insertQuery =
                mysqli_query(
                    $conn,
                    "INSERT INTO classes
                    (
                        class_name,
                        section,
                        academic_year,
                        class_code,
                        teacher_id
                    )
                    VALUES
                    (
                        '$safeClassName',
                        '$safeSection',
                        '$safeAcademicYear',
                        '$safeClassCode',
                        '$teacher_id'
                    )"
                );


            /* ============================================
               SUCCESS
            ============================================= */

            if (
                $insertQuery
            ) {


                /* ========================================
                   WRITE ACTIVITY LOG
                ======================================== */

                addActivityLog(
                    $conn,
                    $admin_id,
                    $admin['name'],
                    'admin',
                    'Course Created',
                    "Created course '$class_name' "
                    . "for section '$section'. "
                    . "Academic Year: $academic_year. "
                    . "Class Code: $class_code. "
                    . "Teacher: "
                    . $teacherData['name']
                );


                /* ========================================
                   REDIRECT
                ======================================== */

                header(
                    "Location: admin_courses.php"
                );

                exit();


            } else {

                $error =
                    "Failed to create course: "
                    . mysqli_error($conn);
            }

        }

    }

}

?>