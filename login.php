<?php

session_start();
include 'database.php';
include 'activity_log.php';


/* =========================================================
   GET LOGIN DATA
========================================================= */

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';


/* =========================================================
   VALIDATION
========================================================= */

if ($email === '' || $password === '') {

    echo "<script>
        alert('Please enter your email and password.');
        window.location='index.html';
    </script>";

    exit();
}


/* =========================================================
   FIND USER
========================================================= */

$safeEmail = mysqli_real_escape_string(
    $conn,
    $email
);

$sql = "
    SELECT *
    FROM users
    WHERE email='$safeEmail'
    LIMIT 1
";

$result = mysqli_query(
    $conn,
    $sql
);


if (
    $result &&
    mysqli_num_rows($result) == 1
) {

    $user = mysqli_fetch_assoc($result);


    /* =====================================================
       VERIFY PASSWORD
    ===================================================== */

    if (
        password_verify(
            $password,
            $user['password']
        )
    ) {


        /* ================================================
           SESSION
        ================================================= */

        $_SESSION['user_id'] =
            $user['id'];

        $_SESSION['name'] =
            $user['name'];

        $_SESSION['role'] =
            $user['role'];


        /* ================================================
           ACTIVITY LOG
        ================================================= */

        addActivityLog(
            $conn,
            intval($user['id']),
            $user['name'],
            $user['role'],
            'Login',
            'User logged into the PLMUN Classroom system.'
        );


        /* ================================================
           ADMIN
        ================================================= */

        if (
            $user['role'] === 'admin'
        ) {

            header(
                "Location: admin_dashboard.php"
            );

            exit();
        }


        /* ================================================
           TEACHER
        ================================================= */

        elseif (
            $user['role'] === 'teacher'
        ) {

            header(
                "Location: teacher_dashboard.php"
            );

            exit();
        }


        /* ================================================
           STUDENT
        ================================================= */

        elseif (
            $user['role'] === 'student'
        ) {

            header(
                "Location: student_dashboard.php"
            );

            exit();
        }

        elseif (
            $user ['role'] === 'director') 
            {

        header(
            "Location: director_dashboard.php"
            );

            }
        elseif (
            $user ['role'] === 'program_chair') 
            {

        header(
            "Location: program_chair_dashboard.php"
            );

        exit();
            }


        /* ================================================
           UNKNOWN ROLE
        ================================================= */

        else {

            session_destroy();

            echo "<script>
                alert('Invalid user role.');
                window.location='index.html';
            </script>";

            exit();
        }

    } else {

        echo "<script>
            alert('Wrong Password');
            window.location='index.html';
        </script>";

        exit();
    }

} else {

    echo "<script>
        alert('Email Not Found');
        window.location='index.html';
    </script>";

    exit();
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
$agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

$stmt = $conn->prepare("
    INSERT INTO admin_login_logs
    (user_id, email, status, ip_address, user_agent)
    VALUES (?, ?, 'success', ?, ?)
");

$stmt->bind_param(
    "isss",
    $user['id'],
    $user['email'],
    $ip,
    $agent
);

$stmt->execute();
$stmt->close();

$ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
$agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

$stmt = $conn->prepare("
    INSERT INTO admin_login_logs
    (user_id, email, status, ip_address, user_agent)
    VALUES (NULL, ?, 'failed', ?, ?)
");

$stmt->bind_param(
    "sss",
    $email,
    $ip,
    $agent
);

$stmt->execute();
$stmt->close();

?>