
<?php
session_start();
include 'database.php';

/* =========================================
   LOGIN VALIDATION
========================================= */

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
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

/* =========================================
   PROFILE IMAGE VALIDATION
========================================= */

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

    // Prevent directory traversal.
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
     LIMIT 1"
);

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
   GET CLASS AND TEACHER INFORMATION
========================================= */

$classStmt = mysqli_prepare(
    $conn,
    "SELECT
        classes.*,
        users.name AS teacher_name,
        users.profile_image AS teacher_profile_image
     FROM classes
     INNER JOIN users
        ON classes.teacher_id = users.id
     WHERE classes.id = ?
     LIMIT 1"
);

mysqli_stmt_bind_param($classStmt, "i", $class_id);
mysqli_stmt_execute($classStmt);

$classResult = mysqli_stmt_get_result($classStmt);
$class = mysqli_fetch_assoc($classResult);

mysqli_stmt_close($classStmt);

if (!$class) {
    die("Class not found");
}

// Validate teacher image.
$teacherImageFile = getProfileImage(
    $class['teacher_profile_image'] ?? ''
);

/* =========================================
   GET STUDENTS IN THE CLASS
========================================= */

$studentsStmt = mysqli_prepare(
    $conn,
    "SELECT
        users.id,
        users.name,
        users.profile_image
     FROM class_students
     INNER JOIN users
        ON class_students.student_id = users.id
     WHERE class_students.class_id = ?
     ORDER BY users.name ASC"
);

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

    <div class="people-section">

        <h4 class="section-title">Teacher</h4>

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
                <small class="text-muted">Instructor</small>
            </div>

        </div>

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