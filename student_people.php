<?php
session_start();
require_once 'database.php';

/* =========================================
   LOGIN AND ROLE VALIDATION
========================================= */

if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'student'
) {
    header("Location: login.php");
    exit();
}

if (!isset($_GET['id']) || !ctype_digit((string)$_GET['id'])) {
    die("Class not found");
}

$class_id = (int) $_GET['id'];
$student_id = (int) $_SESSION['user_id'];

/* =========================================
   HELPER FUNCTIONS
========================================= */

function e($value) {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function getProfileImage($filename) {

    $filename = trim((string)($filename ?? ''));

    $defaultImages = [
        '',
        'default.png',
        'default.jpg',
        'default.jpeg',
        'default.svg',
        'null'
    ];

    if (in_array(strtolower($filename), $defaultImages, true)) {
        return null;
    }

    $safeName = basename($filename);

    $imagePath = __DIR__ . '/uploads/' . $safeName;

    if (
        $safeName !== '' &&
        is_file($imagePath) &&
        is_readable($imagePath)
    ) {
        return $safeName;
    }

    return null;
}

/* =========================================
   GET CURRENT STUDENT INFORMATION
========================================= */

$userStmt = mysqli_prepare(
    $conn,
    "SELECT id, name, profile_image
     FROM users
     WHERE id = ?
       AND role = 'student'
     LIMIT 1"
);

if (!$userStmt) {
    die("Unable to load student information.");
}

mysqli_stmt_bind_param($userStmt, "i", $student_id);
mysqli_stmt_execute($userStmt);

$userResult = mysqli_stmt_get_result($userStmt);
$user = mysqli_fetch_assoc($userResult);

mysqli_stmt_close($userStmt);

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$profileImageFile = getProfileImage($user['profile_image'] ?? '');

/* =========================================
   CHECK ACTIVE CLASS ENROLLMENT
========================================= */

$membershipStmt = mysqli_prepare(
    $conn,
    "SELECT id
     FROM class_students
     WHERE class_id = ?
       AND student_id = ?
       AND (archived = 0 OR archived IS NULL)
     LIMIT 1"
);

if (!$membershipStmt) {
    die("Unable to verify class enrollment.");
}

mysqli_stmt_bind_param(
    $membershipStmt,
    "ii",
    $class_id,
    $student_id
);

mysqli_stmt_execute($membershipStmt);

$membershipResult = mysqli_stmt_get_result($membershipStmt);
$isEnrolled = mysqli_num_rows($membershipResult) > 0;

mysqli_stmt_close($membershipStmt);

if (!$isEnrolled) {
    http_response_code(403);
    die("Access denied. You are not enrolled in this class.");
}

/* =========================================
   GET CLASS AND PRIMARY INSTRUCTOR
========================================= */

$classStmt = mysqli_prepare(
    $conn,
    "SELECT
        c.*,
        u.name AS teacher_name,
        u.profile_image AS teacher_profile_image
     FROM classes c
     LEFT JOIN users u
        ON c.teacher_id = u.id
     WHERE c.id = ?
     LIMIT 1"
);

if (!$classStmt) {
    die("Unable to load class information.");
}

mysqli_stmt_bind_param($classStmt, "i", $class_id);
mysqli_stmt_execute($classStmt);

$classResult = mysqli_stmt_get_result($classStmt);
$class = mysqli_fetch_assoc($classResult);

mysqli_stmt_close($classStmt);

if (!$class) {
    die("Class not found.");
}

$teacherImageFile = getProfileImage(
    $class['teacher_profile_image'] ?? ''
);

/* =========================================
   GET CO-INSTRUCTORS
========================================= */

$coInstructors = [];

$coInstructorStmt = mysqli_prepare(
    $conn,
    "SELECT DISTINCT
        u.id,
        u.name,
        u.profile_image
     FROM class_instructors ci
     INNER JOIN users u
        ON ci.teacher_id = u.id
     WHERE ci.class_id = ?
       AND ci.teacher_id != COALESCE(?, 0)
       AND u.role = 'teacher'
     ORDER BY u.name ASC"
);

if (!$coInstructorStmt) {
    die(
        "Unable to load co-instructors. " .
        "Please check that the class_instructors table exists."
    );
}

$primaryTeacherId = (int)($class['teacher_id'] ?? 0);

mysqli_stmt_bind_param(
    $coInstructorStmt,
    "ii",
    $class_id,
    $primaryTeacherId
);

mysqli_stmt_execute($coInstructorStmt);

$coInstructorResult = mysqli_stmt_get_result($coInstructorStmt);

while ($instructor = mysqli_fetch_assoc($coInstructorResult)) {
    $instructor['profile_image_file'] = getProfileImage(
        $instructor['profile_image'] ?? ''
    );

    $coInstructors[] = $instructor;
}

mysqli_stmt_close($coInstructorStmt);

/* =========================================
   GET ACTIVE STUDENTS IN THE CLASS
========================================= */

$studentsStmt = mysqli_prepare(
    $conn,
    "SELECT DISTINCT
        u.id,
        u.name,
        u.profile_image
     FROM class_students cs
     INNER JOIN users u
        ON cs.student_id = u.id
     WHERE cs.class_id = ?
       AND (cs.archived = 0 OR cs.archived IS NULL)
       AND u.role = 'student'
     ORDER BY u.name ASC"
);

if (!$studentsStmt) {
    die("Unable to load students.");
}

mysqli_stmt_bind_param($studentsStmt, "i", $class_id);
mysqli_stmt_execute($studentsStmt);

$studentsQuery = mysqli_stmt_get_result($studentsStmt);

$studentCount = mysqli_num_rows($studentsQuery);
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>People</title>
    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link rel="stylesheet" href="student_class.css?v=4">
    <link rel="stylesheet" href="student_class_responsive.css?v=2">

</head>
<body>

    <!-- SIDEBAR -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <button class="toggle-btn" onclick="toggleSidebar()">
            <i class="fa-solid fa-bars"></i>
        </button>
    </div>
    <ul>
        <li><a href="student_dashboard.php"><i class="fa-solid fa-house"></i><span>Home</span></a></li>
        <li><a href="student_class_page.php"><i class="fa-solid fa-users"></i><span>Classes</span></a></li>
        <li><a href="student_archived_classes.php"><i class="fa-solid fa-box-archive"></i><span>Archived</span></a></li>
        <li><a href="student_assignments.php"><i class="fa-solid fa-clipboard-list"></i><span>Assignment</span></a></li>
        <li><a href="student_messages.php"><i class="fa-regular fa-message"></i><span>Messages</span></a></li>
        <li><a href="student_performance.php"><i class="fa-solid fa-chart-pie"></i><span>Performance</span></a></li>
        <li><a href="student_resources.php"><i class="fa-solid fa-book-open"></i><span>Resources</span></a></li>
    </ul>
    <div class="logout-btn">
        <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a>
    </div>
</div>

<!-- SIDEBAR OVERLAY (mobile only) -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="mobileSidebar()"></div>

<!-- MAIN CONTENT -->
<div class="main-content" id="mainContent">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="navbar-left">
            <button class="mobile-menu" onclick="mobileSidebar()">
                <i class="fa-solid fa-bars"></i>
            </button>
            <img src="img/classroom logo.png" class="navbar-logo" alt="Logo">
        </div>
        <div class="navbar-right">

            <a href="profile.php" class="profile-icon" title="My Profile">

                <?php if ($profileImageFile): ?>

                    <img
                        src="uploads/<?php echo e(rawurlencode($profileImageFile)); ?>"
                        alt="Profile Picture"
                        class="profile-image"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                    >

                    <span class="profile-fallback" style="display:none;">
                        <i class="fa-solid fa-user"></i>
                    </span>

                <?php else: ?>

                    <span class="profile-fallback">
                        <i class="fa-solid fa-user"></i>
                    </span>

                <?php endif; ?>

            </a>

        </div>
    </div>

    <!-- CLASS NAV -->
    <div class="class-nav">
        <a href="student_class.php?id=<?php echo $class_id; ?>" >Stream</a>
        <a href="student_assessment.php?id=<?php echo $class_id; ?>">Assignments</a>
        <a href="student_material.php?id=<?php echo $class_id; ?>">Materials</a>
        <a href="student_people.php?id=<?php echo $class_id; ?>" class="nav-active">People</a>
    </div>

    <div class="container py-4">

    <h2 class="mb-4">
        People
    </h2>


    <!-- TEACHER -->

    
    <!-- PRIMARY INSTRUCTOR -->

    <div class="people-section">

        <h4 class="section-title">Teacher</h4>

        <?php if (!empty($class['teacher_name'])): ?>

            <div class="people-card">

                <?php if ($teacherImageFile): ?>

                    <img
                        src="uploads/<?php echo e(rawurlencode($teacherImageFile)); ?>"
                        class="people-avatar"
                        alt="<?php echo e($class['teacher_name']); ?>"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                    >

                    <div class="people-avatar people-avatar-default" style="display:none;">
                        <i class="fa-solid fa-user"></i>
                    </div>

                <?php else: ?>

                    <div class="people-avatar people-avatar-default">
                        <i class="fa-solid fa-user"></i>
                    </div>

                <?php endif; ?>

                <div>
                    <h5><?php echo e($class['teacher_name']); ?></h5>
                    <small class="text-muted">Primary Instructor</small>
                </div>

            </div>

        <?php else: ?>

            <div class="people-card">
                <div class="people-avatar people-avatar-default">
                    <i class="fa-solid fa-user"></i>
                </div>

                <div>
                    <h5>Unassigned</h5>
                    <small class="text-muted">No primary instructor assigned</small>
                </div>
            </div>

        <?php endif; ?>


        <!-- CO-INSTRUCTORS -->

        <?php if (!empty($coInstructors)): ?>

            <h4 class="section-title mt-4">Co-Instructors</h4>

            <?php foreach ($coInstructors as $instructor): ?>

                <div class="people-card">

                    <?php if ($instructor['profile_image_file']): ?>

                        <img
                            src="uploads/<?php echo e(rawurlencode($instructor['profile_image_file'])); ?>"
                            class="people-avatar"
                            alt="<?php echo e($instructor['name']); ?>"
                            onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                        >

                        <div class="people-avatar people-avatar-default" style="display:none;">
                            <i class="fa-solid fa-user"></i>
                        </div>

                    <?php else: ?>

                        <div class="people-avatar people-avatar-default">
                            <i class="fa-solid fa-user"></i>
                        </div>

                    <?php endif; ?>

                    <div>
                        <h5><?php echo e($instructor['name']); ?></h5>
                        <small class="text-muted">Co-Instructor</small>
                    </div>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>



    
    <!-- STUDENTS -->

    <div class="people-section mt-5">

        <h4 class="section-title">
            Students (<?php echo $studentCount; ?>)
        </h4>

        <?php while ($student = mysqli_fetch_assoc($studentsQuery)): ?>

            <?php
                $studentImageFile = getProfileImage(
                    $student['profile_image'] ?? ''
                );
            ?>

            <div class="people-card">

                <?php if ($studentImageFile): ?>

                    <img
                        src="uploads/<?php echo e(rawurlencode($studentImageFile)); ?>"
                        class="people-avatar"
                        alt="<?php echo e($student['name']); ?>"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                    >

                    <div class="people-avatar people-avatar-default" style="display:none;">
                        <i class="fa-solid fa-user"></i>
                    </div>

                <?php else: ?>

                    <div class="people-avatar people-avatar-default">
                        <i class="fa-solid fa-user"></i>
                    </div>

                <?php endif; ?>

                <div>
                    <h5><?php echo e($student['name']); ?></h5>
                    <small class="text-muted">Student</small>
                </div>

            </div>

        <?php endwhile; ?>

    </div>

    <?php mysqli_stmt_close($studentsStmt); ?>


</div>


<script>
const sidebar = document.getElementById('sidebar');
const mainContent = document.getElementById('mainContent');
const sidebarOverlay = document.getElementById('sidebarOverlay');

function isMobile() {
    return window.innerWidth <= 768;
}

function toggleSidebar() {

    if (isMobile()) {
        mobileSidebar();
        return;
    }

    sidebar.classList.toggle('collapsed');
    mainContent.classList.toggle('expanded');
}

function mobileSidebar() {

    if (!isMobile()) return;

    sidebar.classList.toggle('show');

    const isOpen = sidebar.classList.contains('show');

    sidebarOverlay.classList.toggle('show', isOpen);
    document.body.classList.toggle('no-scroll', isOpen);
}

function closeMobileSidebar() {

    sidebar.classList.remove('show');
    sidebarOverlay.classList.remove('show');
    document.body.classList.remove('no-scroll');
}

// Close sidebar using Escape key.
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeMobileSidebar();
    }
});

// Close the mobile drawer when resizing to desktop.
window.addEventListener('resize', function() {
    if (!isMobile()) {
        closeMobileSidebar();
    }
});

function toggleTopic(id){
    const body = document.getElementById(id);
    const icon = body.previousElementSibling.querySelector('.topic-collapse-btn i');
    body.classList.toggle('collapsed');
    icon.classList.toggle('fa-chevron-down');
    icon.classList.toggle('fa-chevron-right');
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>