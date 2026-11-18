<?php

session_start();
include 'database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.html");
    exit();
}

$id = intval($_GET['id'] ?? 0);

if ($id <= 0) {
    header("Location: admin_courses.php");
    exit();
}


/* Get course */

$result = mysqli_query(
    $conn,
    "SELECT *
     FROM classes
     WHERE id='$id'
     LIMIT 1"
);

if (!$result || mysqli_num_rows($result) === 0) {
    header("Location: admin_courses.php");
    exit();
}

$course = mysqli_fetch_assoc($result);


/* Get teachers */

$teachers = mysqli_query(
    $conn,
    "SELECT id, name
     FROM users
     WHERE role='teacher'
     ORDER BY name ASC"
);


$error = '';


/* Update */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $class_name =
        trim($_POST['class_name'] ?? '');

    $section =
        trim($_POST['section'] ?? '');

    $academic_year =
        trim($_POST['academic_year'] ?? '');

    $class_code =
        trim($_POST['class_code'] ?? '');

    $teacher_id =
        intval($_POST['teacher_id'] ?? 0);


    if (
        $class_name === '' ||
        $section === '' ||
        $academic_year === '' ||
        $class_code === '' ||
        $teacher_id <= 0
    ) {

        $error = "Please complete all fields.";

    } else {

        $class_name =
            mysqli_real_escape_string(
                $conn,
                $class_name
            );

        $section =
            mysqli_real_escape_string(
                $conn,
                $section
            );

        $academic_year =
            mysqli_real_escape_string(
                $conn,
                $academic_year
            );

        $class_code =
            mysqli_real_escape_string(
                $conn,
                $class_code
            );


        $update = mysqli_query(
            $conn,
            "UPDATE classes
             SET
                class_name='$class_name',
                section='$section',
                academic_year='$academic_year',
                class_code='$class_code',
                teacher_id='$teacher_id'
             WHERE id='$id'"
        );


        if ($update) {

            header(
                "Location: admin_courses.php"
            );

            exit();

        } else {

            $error = "Failed to update course.";

        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Edit Course | PLMUN Classroom</title>

<link
    rel="stylesheet"
    href="admin_dashboard.css"
>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
>

<style>

.form-page {
    padding: 35px;
}

.form-card {
    max-width: 700px;
    background: #fff;
    border: 1px solid #ddd;
    padding: 30px;
}

.form-card h2 {
    margin-bottom: 25px;
}

.form-group {
    margin-bottom: 18px;
}

.form-group label {
    display: block;
    margin-bottom: 7px;
    font-weight: 600;
}

.form-group input,
.form-group select {
    width: 100%;
    padding: 10px;
    border: 1px solid #ccc;
}

.form-buttons {
    display: flex;
    gap: 10px;
    margin-top: 25px;
}

.btn-save {
    border: none;
    background: #3f87b5;
    color: #fff;
    padding: 10px 22px;
    border-radius: 5px;
}

.btn-cancel {
    background: #eee;
    color: #333;
    padding: 10px 22px;
    border-radius: 5px;
    text-decoration: none;
}

.error {
    background: #ffe5e5;
    color: #c00;
    padding: 12px;
    margin-bottom: 20px;
}

</style>

</head>


<body>


<div class="admin-sidebar">

    <div class="admin-sidebar-header">
        <h1>Admin</h1>
    </div>

    <ul class="admin-menu">

        <li>
            <a href="admin_dashboard.php">
                <i class="fa-solid fa-house"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <li>
            <a href="admin_users.php">
                <i class="fa-solid fa-user"></i>
                <span>User Management</span>
            </a>
        </li>

        <li>
            <a href="admin_courses.php" class="active">
                <i class="fa-solid fa-table-cells-large"></i>
                <span>Course Management</span>
            </a>
        </li>

    </ul>

    <div class="admin-logout">

        <a href="logout.php">

            <i class="fa-solid fa-right-from-bracket"></i>

            <span>Log out</span>

        </a>

    </div>

</div>


<div class="admin-main">

    <header class="admin-topbar">

        <div class="admin-top-left">

            <img
                src="img/classroom logo.png"
                class="admin-logo"
                alt="PLMUN Classroom"
            >

        </div>

    </header>


    <main class="form-page">

        <div class="form-card">

            <h2>Edit Course</h2>


            <?php if ($error): ?>

                <div class="error">

                    <?php
                    echo htmlspecialchars($error);
                    ?>

                </div>

            <?php endif; ?>


            <form method="POST">


                <div class="form-group">

                    <label>Course Name</label>

                    <input
                        type="text"
                        name="class_name"
                        value="<?php
                        echo htmlspecialchars(
                            $course['class_name']
                        );
                        ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>Section</label>

                    <input
                        type="text"
                        name="section"
                        value="<?php
                        echo htmlspecialchars(
                            $course['section']
                        );
                        ?>"
                        required
                    >

                </div>


                <div class="form-group">
                    <label for="academic_year">Academic Year</label>

                    <input
                        type="text"
                        id="academic_year"
                        name="academic_year"
                        value="<?php echo htmlspecialchars($course['academic_year'] ?? ''); ?>"
                        placeholder="e.g. 2025 - 2026"
                        required
                    >
                </div>


                <div class="form-group">

                    <label>Class Code</label>

                    <input
                        type="text"
                        name="class_code"
                        value="<?php
                        echo htmlspecialchars(
                            $course['class_code']
                        );
                        ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>Teacher</label>

                    <select
                        name="teacher_id"
                        required
                    >

                        <?php while (
                            $teacher =
                            mysqli_fetch_assoc($teachers)
                        ): ?>

                            <option
                                value="<?php
                                echo $teacher['id'];
                                ?>"
                                <?php
                                echo (
                                    $teacher['id']
                                    ==
                                    $course['teacher_id']
                                )
                                    ? 'selected'
                                    : '';
                                ?>
                            >

                                <?php
                                echo htmlspecialchars(
                                    $teacher['name']
                                );
                                ?>

                            </option>

                        <?php endwhile; ?>

                    </select>

                </div>


                <div class="form-buttons">

                    <button
                        type="submit"
                        class="btn-save"
                    >

                        <i class="fa-solid fa-check"></i>

                        Save Changes

                    </button>


                    <a
                        href="admin_courses.php"
                        class="btn-cancel"
                    >

                        Cancel

                    </a>

                </div>


            </form>

        </div>

    </main>

</div>

</body>

</html>