<?php

session_start();
include 'database.php';

/* =========================================================
   ADMIN ACCESS CONTROL
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
   GET ADMIN INFORMATION
========================================================= */

$admin_id = intval($_SESSION['user_id']);

$adminQuery = mysqli_query(
    $conn,
    "SELECT * FROM users WHERE id='$admin_id'"
);

if (!$adminQuery || mysqli_num_rows($adminQuery) == 0) {
    session_destroy();
    header("Location: login.html");
    exit();
}

$admin = mysqli_fetch_assoc($adminQuery);


/* =========================================================
   SEARCH
========================================================= */

$search = trim($_GET['search'] ?? '');

if ($search !== '') {

    $safeSearch = mysqli_real_escape_string($conn, $search);

    $courseQuery = mysqli_query(
        $conn,
        "SELECT
            c.*,
            u.name AS teacher_name
         FROM classes c
         LEFT JOIN users u
            ON c.teacher_id = u.id
         WHERE
            c.class_name LIKE '%$safeSearch%'
            OR c.section LIKE '%$safeSearch%'
            OR c.academic_year LIKE '%$safeSearch%'
            OR c.class_code LIKE '%$safeSearch%'
            OR u.name LIKE '%$safeSearch%'
         ORDER BY c.id DESC"
    );

} else {

    $courseQuery = mysqli_query(
        $conn,
        "SELECT
            c.*,
            u.name AS teacher_name
         FROM classes c
         LEFT JOIN users u
            ON c.teacher_id = u.id
         ORDER BY c.id DESC"
    );
}


/* =========================================================
   TOTAL COURSES
========================================================= */

$totalCoursesQuery = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total FROM classes"
);

$totalCourses = 0;

if ($totalCoursesQuery) {
    $totalCourses =
        mysqli_fetch_assoc($totalCoursesQuery)['total'] ?? 0;
}


/* =========================================================
   TOTAL STUDENTS ENROLLED
========================================================= */

$totalStudentsQuery = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total FROM class_students"
);

$totalStudents = 0;

if ($totalStudentsQuery) {
    $totalStudents =
        mysqli_fetch_assoc($totalStudentsQuery)['total'] ?? 0;
}


/* =========================================================
   TOTAL TEACHERS
========================================================= */

$totalTeachersQuery = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total
     FROM users
     WHERE role='teacher'"
);

$totalTeachers = 0;

if ($totalTeachersQuery) {
    $totalTeachers =
        mysqli_fetch_assoc($totalTeachersQuery)['total'] ?? 0;
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

    <title>Course Management | PLMUN Classroom</title>

    <link
        rel="shortcut icon"
        href="img/plmun logo.png"
        type="image/x-icon"
    >

    <link
        rel="stylesheet"
        href="admin_dashboard.css"
    >

    <link
        rel="stylesheet"
        href="admin_courses.css"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

</head>


<body>


<!-- =========================================================
     SIDEBAR
========================================================= -->

<div class="admin-sidebar" id="adminSidebar">

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
            <a href="admin_settings.php">

                <i class="fa-solid fa-gear"></i>

                <span>System Settings</span>

            </a>
        </li>


        <li>
            <a
                href="admin_courses.php"
                class="active"
            >

                <i class="fa-solid fa-table-cells-large"></i>

                <span>Course Management</span>

            </a>
        </li>


        <li>
            <a href="admin_monitor.php">

                <i class="fa-solid fa-desktop"></i>

                <span>System Monitor</span>

            </a>
        </li>


        <li>
            <a href="admin_reports.php">

                <i class="fa-solid fa-wave-square"></i>

                <span>Reports &amp; Analytics</span>

            </a>
        </li>


        <li>
            <a href="admin_logs.php">

                <i class="fa-regular fa-clock"></i>

                <span>Logs &amp; Activity</span>

            </a>
        </li>


        <li>
            <a href="admin_security.php">

                <i class="fa-solid fa-shield-halved"></i>

                <span>Backup &amp; Security</span>

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


<!-- MOBILE OVERLAY -->

<div
    class="admin-overlay"
    id="adminOverlay"
    onclick="closeAdminSidebar()"
></div>


<!-- =========================================================
     MAIN
========================================================= -->

<div class="admin-main" id="adminMain">


    <!-- =====================================================
         TOPBAR
    ====================================================== -->

    <header class="admin-topbar">

        <div class="admin-top-left">

            <button
                class="admin-mobile-btn"
                onclick="toggleAdminSidebar()"
            >

                <i class="fa-solid fa-bars"></i>

            </button>


            <img
                src="img/classroom logo.png"
                alt="PLMUN Classroom"
                class="admin-logo"
            >

        </div>


        <div class="admin-top-right">

            <div class="admin-user-name">

                <span>
                    <?php echo htmlspecialchars($admin['name']); ?>
                </span>

            </div>


            <a
                href="profile.php"
                class="admin-profile"
            >

                <?php if (
                    !empty($admin['profile_image']) &&
                    $admin['profile_image'] !== 'default.png'
                ): ?>

                    <img
                        src="uploads/<?php
                        echo htmlspecialchars(
                            $admin['profile_image']
                        );
                        ?>"
                        alt="Admin"
                    >

                <?php else: ?>

                    <i class="fa-solid fa-user"></i>

                <?php endif; ?>

            </a>

        </div>

    </header>


    <!-- =====================================================
         CONTENT
    ====================================================== -->

    <main class="course-content">


        <!-- PAGE TITLE -->

        <div class="course-header">

            <h2>Course Management</h2>

            <a
                href="add_course.php"
                class="add-course-btn"
            >

                <i class="fa-solid fa-plus"></i>

                Add New Course

            </a>

        </div>

        <!-- =================================================
             SEARCH
        ================================================== -->

        <div class="course-search-container">

            <form method="GET">

                <div class="course-search">

                    <input
                        type="text"
                        name="search"
                        id="courseSearch"
                        placeholder="Search courses..."
                        value="<?php
                        echo htmlspecialchars($search);
                        ?>"
                        autocomplete="off"
                    >

                    <i class="fa-solid fa-magnifying-glass"></i>

                </div>

            </form>

        </div>

        <!-- =================================================
             SUMMARY
        ================================================== -->

        <div class="course-summary">

            <div class="summary-card">

                <span>Total Courses</span>

                <strong>
                    <?php echo number_format($totalCourses); ?>
                </strong>

            </div>


            <div class="summary-card">

                <span>Teachers</span>

                <strong>
                    <?php echo number_format($totalTeachers); ?>
                </strong>

            </div>


            <div class="summary-card">

                <span>Enrolled Students</span>

                <strong>
                    <?php echo number_format($totalStudents); ?>
                </strong>

            </div>

        </div>


        <!-- =================================================
             COURSE TABLE
        ================================================== -->

        <div class="course-table-wrapper">

            <table class="course-table">

                <thead>

                    <tr>

                        <th>Course Name</th>

                        <th>Section</th>

                        <th>A.Y</th>

                        <th>Class Code</th>

                        <th>People</th>

                        <th>Actions</th>

                    </tr>

                </thead>


                <tbody>

                <?php if (
                    $courseQuery &&
                    mysqli_num_rows($courseQuery) > 0
                ): ?>


                    <?php while (
                        $course = mysqli_fetch_assoc($courseQuery)
                    ): ?>


                        <?php

                        $class_id =
                            intval($course['id']);

                        $studentCount = 0;

                        $studentQuery = mysqli_query(
                            $conn,
                            "SELECT COUNT(*) AS total
                             FROM class_students
                             WHERE class_id='$class_id'"
                        );

                        if ($studentQuery) {

                            $studentCount =
                                mysqli_fetch_assoc(
                                    $studentQuery
                                )['total'] ?? 0;
                        }

                        ?>


                        <tr>


                            <!-- COURSE -->

                            <td>

                                <div class="course-name">

                                    <?php
                                    echo htmlspecialchars(
                                        $course['class_name']
                                    );
                                    ?>

                                </div>

                                <?php if (
                                    !empty(
                                        $course['teacher_name']
                                    )
                                ): ?>

                                    <small class="teacher-name">

                                        <i class="fa-solid fa-user"></i>

                                        <?php
                                        echo htmlspecialchars(
                                            $course['teacher_name']
                                        );
                                        ?>

                                    </small>

                                <?php endif; ?>

                            </td>


                            <!-- SECTION -->

                            <td>

                                <?php
                                echo htmlspecialchars(
                                    $course['section'] ?? '-'
                                );
                                ?>

                            </td>


                            <!-- ACADEMIC YEAR -->

                            <td>

                                <?php
                                echo htmlspecialchars(
                                    $course['academic_year'] ?? '-'
                                );
                                ?>

                            </td>


                            <!-- CLASS CODE -->

                            <td>

                                <span class="class-code">

                                    <?php
                                    echo htmlspecialchars(
                                        $course['class_code']
                                    );
                                    ?>

                                </span>

                            </td>


                            <!-- PEOPLE -->

                            <td>

                                <span class="people-count">

                                    <?php
                                    echo $studentCount;
                                    ?>

                                </span>

                                <span class="people-total">
                                    /50
                                </span>

                            </td>


                            <!-- ACTIONS -->

                            <td>

                                <div class="course-actions">


                                    <!-- EDIT -->

                                    <a
                                        href="edit_course.php?id=<?php
                                        echo $class_id;
                                        ?>"
                                        class="edit-btn"
                                        title="Edit Course"
                                    >

                                        <i class="fa-regular fa-pen-to-square"></i>

                                    </a>


                                    <!-- DELETE -->

                                    <a
                                        href="delete_course.php?id=<?php
                                        echo $class_id;
                                        ?>"
                                        class="delete-btn"
                                        title="Delete Course"
                                        onclick="return confirm(
                                            'Are you sure you want to delete this course?'
                                        );"
                                    >

                                        <i class="fa-regular fa-trash-can"></i>

                                    </a>

                                </div>

                            </td>

                        </tr>


                    <?php endwhile; ?>


                <?php else: ?>


                    <tr>

                        <td
                            colspan="6"
                            class="no-courses"
                        >

                            <i class="fa-solid fa-book-open"></i>

                            <p>No courses found.</p>

                        </td>

                    </tr>


                <?php endif; ?>

                </tbody>

            </table>

        </div>


    </main>

</div>


<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script>

function toggleAdminSidebar() {

    const sidebar =
        document.getElementById('adminSidebar');

    const overlay =
        document.getElementById('adminOverlay');

    sidebar.classList.toggle('show');

    overlay.classList.toggle('show');

}


function closeAdminSidebar() {

    const sidebar =
        document.getElementById('adminSidebar');

    const overlay =
        document.getElementById('adminOverlay');

    sidebar.classList.remove('show');

    overlay.classList.remove('show');

}


window.addEventListener('resize', function () {

    if (window.innerWidth > 768) {

        closeAdminSidebar();

    }

});


/* =========================================================
   LIVE COURSE SEARCH
========================================================= */

const courseSearch =
    document.getElementById('courseSearch');

let searchTimer;

if (courseSearch) {

    courseSearch.addEventListener('input', function () {

        clearTimeout(searchTimer);

        searchTimer = setTimeout(function () {

            courseSearch
                .closest('form')
                .submit();

        }, 300);

    });

}

</script>


</body>

</html>


