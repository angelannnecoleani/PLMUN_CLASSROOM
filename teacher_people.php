<?php
session_start();
include 'database.php';

if(!isset($_GET['id'])){
    die("Class not found");
}

$id = intval($_GET['id']);

$classQuery = mysqli_query(
    $conn,
    "SELECT classes.*, users.name AS teacher_name,
            users.profile_image
     FROM classes
     INNER JOIN users
     ON classes.teacher_id = users.id
     WHERE classes.id='$id'"
);

$class = mysqli_fetch_assoc($classQuery);

if(!$class){
    die("Class not found");
}

$teacher_id = $_SESSION['user_id'];
$userQuery = mysqli_query($conn, "SELECT * FROM users WHERE id='$teacher_id'");
$user = mysqli_fetch_assoc($userQuery);
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

    <!-- Dims the page and closes the mobile menu when tapped -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- SIDEBAR -->
<div class="sidebar" id="sidebar">

    <div class="sidebar-header">
        <button class="toggle-btn" onclick="toggleSidebar()">
            <i class="fa-solid fa-bars"></i>
        </button>
        <button class="sidebar-close-btn" onclick="closeMobileSidebar()" aria-label="Close menu">
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

        <li>
            <a href="teacher_resources.php">
                <i class="fa-solid fa-book-open"></i>
                <span>Resources</span>
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

            <button class="mobile-menu"
                    onclick="mobileSidebar()">

                <i class="fa-solid fa-bars"></i>

            </button>

            <img src="img/classroom logo.png"
                 class="navbar-logo">

        </div>

        <div class="navbar-right">

            <?php
                $profileFile = $user['profile_image'] ?? '';
                $hasProfileImage = !empty($profileFile)
                    && !in_array(strtolower($profileFile), ['default.png', 'default.jpg', 'default.jpeg', 'null'])
                    && file_exists(__DIR__ . '/uploads/' . basename($profileFile));
            ?>
            <a href="profile.php" class="profile-icon">
                <?php if($hasProfileImage): ?>
                    <img src="uploads/<?php echo htmlspecialchars(basename($profileFile)); ?>"
                         alt="Profile"
                        onerror="this.style.display='none'; this.parentElement.insertAdjacentHTML('beforeend','<i class=&quot;fa-solid fa-user&quot;></i>');">
                <?php else: ?>
                    <i class="fa-solid fa-user"></i>
                <?php endif; ?>
            </a>

        </div>

    </div>

    <!-- NAV -->

    <div class="class-nav">

        <a href="teacher_class.php?id=<?php echo $id; ?>">Stream</a>

        <a href="teacher_assessment.php?id=<?php echo $id; ?>">Assessments</a>

        <a href="teacher_material.php?id=<?php echo $id; ?>">Materials</a>

        <a href="teacher_people.php?id=<?php echo $id; ?>" class="nav-active">People</a>

        <a href="teacher_grades.php?id=<?php echo $id; ?>">Grades</a>

    </div>

    <div class="container py-4">

    <h2 class="mb-4">
        People
    </h2>

    <!-- TEACHER -->

    <div class="people-section">

        <h4 class="section-title">
            Teacher
        </h4>

        <div class="people-card">

            <?php
            $teacherPic = "uploads/" . $class['profile_image'];
            $hasTeacherPic = !empty($class['profile_image']) && file_exists($teacherPic);
            ?>

            <?php if($hasTeacherPic): ?>
                <img src="<?php echo htmlspecialchars($teacherPic); ?>"
                     class="people-avatar"
                     alt="<?php echo htmlspecialchars($class['teacher_name']); ?>">
            <?php else: ?>
                <div class="people-avatar people-avatar-default">
                    <i class="fa-solid fa-user"></i>
                </div>
            <?php endif; ?>

            <div>

                <h5>
                    <?php echo htmlspecialchars($class['teacher_name']); ?>
                </h5>

                <small class="text-muted">
                    Instructor
                </small>

            </div>

        </div>

    </div>

    <!-- STUDENTS -->

    <div class="people-section mt-5">

        <?php

        $studentsQuery = mysqli_query(
            $conn,
            "SELECT users.*
             FROM class_students
             INNER JOIN users
             ON class_students.student_id = users.id
             WHERE class_students.class_id='$id'"
        );

        $studentCount = mysqli_num_rows($studentsQuery);

        ?>

        <h4 class="section-title">
            Students (<?php echo $studentCount; ?>)
        </h4>

        <?php while($student = mysqli_fetch_assoc($studentsQuery)){ ?>

        <?php

        $studentPic = "uploads/" . $student['profile_image'];
        $hasStudentPic = !empty($student['profile_image']) && file_exists($studentPic);

        ?>

        <div class="people-card">

            <?php if($hasStudentPic): ?>
                <img src="<?php echo htmlspecialchars($studentPic); ?>"
                     class="people-avatar"
                     alt="<?php echo htmlspecialchars($student['name']); ?>">
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

        <?php } ?>

        <?php if($studentCount === 0): ?>
            <div class="people-empty">
                <i class="fa-solid fa-user-slash"></i>
                <p class="mt-2">No students have joined yet.</p>
            </div>
        <?php endif; ?>


    </div>

</div>


</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar(){
    document.getElementById("sidebar").classList.toggle("collapsed");
    document.getElementById("mainContent").classList.toggle("expanded");
}

function openMobileSidebar(){
    document.getElementById("sidebar").classList.add("show");
    document.getElementById("sidebarOverlay").classList.add("show");
    document.body.classList.add("no-scroll");
}
function closeMobileSidebar(){
    document.getElementById("sidebar").classList.remove("show");
    document.getElementById("sidebarOverlay").classList.remove("show");
    document.body.classList.remove("no-scroll");
}
function mobileSidebar(){
    const sidebar = document.getElementById("sidebar");
    sidebar.classList.contains("show") ? closeMobileSidebar() : openMobileSidebar();
}

// Tapping the dimmed background closes the menu
document.getElementById("sidebarOverlay").addEventListener("click", closeMobileSidebar);

// Pressing Escape closes the menu
document.addEventListener("keydown", function(e){
    if(e.key === "Escape") closeMobileSidebar();
});

// Picking a nav link closes the menu so it doesn't stay open on the new page
document.querySelectorAll(".sidebar ul li a, .sidebar .logout-btn a").forEach(function(link){
    link.addEventListener("click", function(){
        if(document.getElementById("sidebar").classList.contains("show")) closeMobileSidebar();
    });
});
</script>

</body>
</html>