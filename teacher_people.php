
<?php
session_start();
require_once 'database.php';
require_once 'auth_helpers.php';

/* =========================================
   1. LOGIN AND ROLE VALIDATION
========================================= */

if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

if (!in_array($role, ['teacher', 'program_chair'], true)) {
    header("Location: login.php");
    exit();
}

if (!isset($_GET['id'])) {
    die("Class not found.");
}

$id = (int) $_GET['id'];

if ($id <= 0) {
    die("Invalid class ID.");
}

/* =========================================
   2. VERIFY CLASS ACCESS
========================================= */

if ($role === 'teacher') {

    $accessStmt = mysqli_prepare(
        $conn,
        "SELECT id
         FROM classes
         WHERE id = ?
         AND (
             teacher_id = ?
             OR EXISTS (
                 SELECT 1
                 FROM class_instructors ci
                 WHERE ci.class_id = classes.id
                 AND ci.teacher_id = ?
             )
         )
         LIMIT 1"
    );

    if (!$accessStmt) {
        die("Database error: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param(
        $accessStmt,
        "iii",
        $id,
        $user_id,
        $user_id
    );

    mysqli_stmt_execute($accessStmt);
    $accessResult = mysqli_stmt_get_result($accessStmt);
    $hasAccess = mysqli_fetch_assoc($accessResult);
    mysqli_stmt_close($accessStmt);

    if (!$hasAccess) {
        die("Access denied. You are not assigned to this class.");
    }

} elseif ($role === 'program_chair') {

    if (!getClassAccess($conn, $id, $user_id, $role)) {
        die("Access denied. You do not have permission to view this class.");
    }
}

/* =========================================
   3. GET CLASS INFORMATION
========================================= */

$classStmt = mysqli_prepare(
    $conn,
    "SELECT *
     FROM classes
     WHERE id = ?
     LIMIT 1"
);

mysqli_stmt_bind_param($classStmt, "i", $id);
mysqli_stmt_execute($classStmt);

$class = mysqli_fetch_assoc(
    mysqli_stmt_get_result($classStmt)
);

mysqli_stmt_close($classStmt);

if (!$class) {
    die("Class not found.");
}

/* =========================================
   4. GET LOGGED-IN USER
========================================= */

$userStmt = mysqli_prepare(
    $conn,
    "SELECT *
     FROM users
     WHERE id = ?
     LIMIT 1"
);

mysqli_stmt_bind_param($userStmt, "i", $user_id);
mysqli_stmt_execute($userStmt);

$user = mysqli_fetch_assoc(
    mysqli_stmt_get_result($userStmt)
) ?: [];

mysqli_stmt_close($userStmt);

/* =========================================
   5. GET ALL ASSIGNED INSTRUCTORS
========================================= */

/*
   Includes:
   - Primary teacher from classes.teacher_id
   - Additional teachers from class_instructors

   DISTINCT prevents displaying the same teacher twice
   if they appear in both places.
*/

$teacherStmt = mysqli_prepare(
    $conn,
    "SELECT DISTINCT
        u.id,
        u.name,
        u.profile_image,
        CASE
            WHEN u.id = c.teacher_id THEN 'Primary Instructor'
            ELSE 'Co-instructor'
        END AS instructor_role
     FROM classes c
     INNER JOIN users u
        ON (
            u.id = c.teacher_id
            OR EXISTS (
                SELECT 1
                FROM class_instructors ci
                WHERE ci.class_id = c.id
                AND ci.teacher_id = u.id
            )
        )
     WHERE c.id = ?
     ORDER BY
        CASE
            WHEN u.id = c.teacher_id THEN 0
            ELSE 1
        END,
        u.name ASC"
);

if (!$teacherStmt) {
    die("Database error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($teacherStmt, "i", $id);
mysqli_stmt_execute($teacherStmt);

$teacherResult = mysqli_stmt_get_result($teacherStmt);

$teachers = [];

while ($teacher = mysqli_fetch_assoc($teacherResult)) {
    $teachers[] = $teacher;
}

mysqli_stmt_close($teacherStmt);

$teacherCount = count($teachers);

/* =========================================
   6. GET STUDENTS
========================================= */

$studentsStmt = mysqli_prepare(
    $conn,
    "SELECT u.*
     FROM class_students cs
     INNER JOIN users u
        ON cs.student_id = u.id
     WHERE cs.class_id = ?
     AND (cs.archived = 0 OR cs.archived IS NULL)
     ORDER BY u.name ASC"
);

if (!$studentsStmt) {
    die("Database error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($studentsStmt, "i", $id);
mysqli_stmt_execute($studentsStmt);

$studentsQuery = mysqli_stmt_get_result($studentsStmt);

$students = [];

while ($student = mysqli_fetch_assoc($studentsQuery)) {
    $students[] = $student;
}

mysqli_stmt_close($studentsStmt);

$studentCount = count($students);

/* =========================================
   7. PROFILE IMAGE HELPER
========================================= */

function getProfileImage($filename) {

    $filename = trim((string) $filename);

    if (
        $filename === '' ||
        in_array(
            strtolower($filename),
            ['default.png', 'default.jpg', 'default.jpeg', 'null']
        )
    ) {
        return false;
    }

    $safeFilename = basename($filename);
    $filePath = __DIR__ . '/uploads/' . $safeFilename;

    if (is_file($filePath)) {
        return 'uploads/' . $safeFilename;
    }

    return false;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>People</title>

    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">

    <link rel="stylesheet" href="teacher_class.css">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

</head>

<body>

    <!-- SIDEBAR OVERLAY -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- SIDEBAR -->
    <div class="sidebar" id="sidebar">

        <div class="sidebar-header">

            <button class="toggle-btn" onclick="toggleSidebar()">
                <i class="fa-solid fa-bars"></i>
            </button>

            <button class="sidebar-close-btn"
                    onclick="closeMobileSidebar()"
                    aria-label="Close menu">

                <i class="fa-solid fa-xmark"></i>

            </button>

        </div>

        <ul>

            <li>
                <a href="teacher_dashboard.php">
                    <i class="fa-solid fa-house"></i>
                    <span>Home</span>
                </a>
            </li>

            <li>
                <a href="teacher_classpage.php">
                    <i class="fa-solid fa-users"></i>
                    <span>Classes</span>
                </a>
            </li>

            <li>
                <a href="teacher_to_review.php">
                    <i class="fa-solid fa-clipboard-list"></i>
                    <span>To Review</span>
                </a>
            </li>

            <li>
                <a href="teacher_messages.php">
                    <i class="fa-regular fa-message"></i>
                    <span>Messages</span>
                </a>
            </li>

            <li>
                <a href="teacher_performance.php">
                    <i class="fa-solid fa-chart-pie"></i>
                    <span>Performance</span>
                </a>
            </li>


        </ul>

        <div class="logout-btn">

            <a href="logout.php">
                <i class="fa-solid fa-right-from-bracket"></i>
                <span>Logout</span>
            </a>

        </div>

    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content" id="mainContent">

        <!-- TOPBAR -->
        <div class="topbar">

            <div class="navbar-left">

                <button class="mobile-menu" onclick="mobileSidebar()">
                    <i class="fa-solid fa-bars"></i>
                </button>

                <img src="img/classroom logo.png" class="navbar-logo">

            </div>

            <div class="navbar-right">

                <?php
                $profileImage = getProfileImage($user['profile_image'] ?? '');
                ?>

                <a href="profile.php" class="profile-icon">

                    <?php if ($profileImage): ?>

                        <img
                            src="<?php echo htmlspecialchars($profileImage); ?>"
                            alt="Profile"
                            onerror="this.style.display='none'; this.parentElement.insertAdjacentHTML('beforeend','<i class=&quot;fa-solid fa-user&quot;></i>');"
                        >

                    <?php else: ?>

                        <i class="fa-solid fa-user"></i>

                    <?php endif; ?>

                </a>

            </div>

        </div>

        <!-- CLASS NAVIGATION -->
        <div class="class-nav">

            <a href="teacher_class.php?id=<?php echo $id; ?>">
                Stream
            </a>

            <a href="teacher_assessment.php?id=<?php echo $id; ?>">
                Assessments
            </a>

            <a href="teacher_material.php?id=<?php echo $id; ?>">
                Materials
            </a>

            <a href="teacher_people.php?id=<?php echo $id; ?>" class="nav-active">
                People
            </a>

            <a href="teacher_grades.php?id=<?php echo $id; ?>">
                Grades
            </a>

        </div>

        <!-- PEOPLE CONTENT -->
        <div class="container py-4">

            <h2 class="mb-4">
                People
            </h2>

            <!-- =================================
                 TEACHERS AND CO-INSTRUCTORS
            ================================== -->

            <div class="people-section">

                <h4 class="section-title">
                    Teachers (<?php echo $teacherCount; ?>)
                </h4>

                <?php if ($teacherCount > 0): ?>

                    <?php foreach ($teachers as $teacher): ?>

                        <?php
                        $teacherPic = getProfileImage(
                            $teacher['profile_image'] ?? ''
                        );
                        ?>

                        <div class="people-card">

                            <?php if ($teacherPic): ?>

                                <img
                                    src="<?php echo htmlspecialchars($teacherPic); ?>"
                                    class="people-avatar"
                                    alt="<?php echo htmlspecialchars($teacher['name']); ?>"
                                    onerror="this.style.display='none';"
                                >

                            <?php else: ?>

                                <div class="people-avatar people-avatar-default">
                                    <i class="fa-solid fa-user"></i>
                                </div>

                            <?php endif; ?>

                            <div>

                                <h5>
                                    <?php echo htmlspecialchars($teacher['name']); ?>
                                </h5>

                                <small class="text-muted">
                                    <?php echo htmlspecialchars($teacher['instructor_role']); ?>
                                </small>

                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="people-empty">

                        <i class="fa-solid fa-user-slash"></i>

                        <p class="mt-2">
                            No instructors have been assigned to this class yet.
                        </p>

                    </div>

                <?php endif; ?>

            </div>

            <!-- =================================
                 STUDENTS
            ================================== -->

            <div class="people-section mt-5">

                <h4 class="section-title">
                    Students (<?php echo $studentCount; ?>)
                </h4>

                <?php if ($studentCount > 0): ?>

                    <?php foreach ($students as $student): ?>

                        <?php
                        $studentPic = getProfileImage(
                            $student['profile_image'] ?? ''
                        );
                        ?>

                        <div class="people-card">

                            <?php if ($studentPic): ?>

                                <img
                                    src="<?php echo htmlspecialchars($studentPic); ?>"
                                    class="people-avatar"
                                    alt="<?php echo htmlspecialchars($student['name']); ?>"
                                    onerror="this.style.display='none';"
                                >

                            <?php else: ?>

                                <div class="people-avatar people-avatar-default">
                                    <i class="fa-solid fa-user"></i>
                                </div>

                            <?php endif; ?>

                            <div>

                                <h5>
                                    <?php echo htmlspecialchars($student['name']); ?>
                                </h5>

                                <small class="text-muted">
                                    Student
                                </small>

                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="people-empty">

                        <i class="fa-solid fa-user-slash"></i>

                        <p class="mt-2">
                            No students have joined yet.
                        </p>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>

    function toggleSidebar() {

        document.getElementById("sidebar").classList.toggle("collapsed");

        document.getElementById("mainContent").classList.toggle("expanded");

    }

    function openMobileSidebar() {

        document.getElementById("sidebar").classList.add("show");

        document.getElementById("sidebarOverlay").classList.add("show");

        document.body.classList.add("no-scroll");

    }

    function closeMobileSidebar() {

        document.getElementById("sidebar").classList.remove("show");

        document.getElementById("sidebarOverlay").classList.remove("show");

        document.body.classList.remove("no-scroll");

    }

    function mobileSidebar() {

        const sidebar = document.getElementById("sidebar");

        sidebar.classList.contains("show")
            ? closeMobileSidebar()
            : openMobileSidebar();

    }

    document.getElementById("sidebarOverlay").addEventListener(
        "click",
        closeMobileSidebar
    );

    document.addEventListener("keydown", function(e) {

        if (e.key === "Escape") {
            closeMobileSidebar();
        }

    });

    document.querySelectorAll(
        ".sidebar ul li a, .sidebar .logout-btn a"
    ).forEach(function(link) {

        link.addEventListener("click", function() {

            if (
                document.getElementById("sidebar").classList.contains("show")
            ) {
                closeMobileSidebar();
            }

        });

    });

    </script>

</body>
</html>
