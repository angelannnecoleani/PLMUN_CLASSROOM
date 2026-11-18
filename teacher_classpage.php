
<?php
session_start();
include 'database.php';

/* =========================
   LOGIN AND ROLE CHECK
========================= */

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'teacher') {
    header("Location: login.php");
    exit();
}

$teacher_id = (int) $_SESSION['user_id'];

/* =========================
   HELPER FUNCTION
========================= */

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/* =========================
   GET TEACHER INFORMATION
========================= */

$userStmt = mysqli_prepare(
    $conn,
    "SELECT id, name, profile_image
     FROM users
     WHERE id = ? AND role = 'teacher'
     LIMIT 1"
);

if (!$userStmt) {
    die("Database error: " . e(mysqli_error($conn)));
}

mysqli_stmt_bind_param($userStmt, "i", $teacher_id);
mysqli_stmt_execute($userStmt);

$userResult = mysqli_stmt_get_result($userStmt);
$user = mysqli_fetch_assoc($userResult);

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

/* =========================
   SEARCH
========================= */

$search = trim($_GET['search'] ?? '');

/* =========================
   CLASS CARD COLORS
========================= */

$cardColors = [
    "#4C8C92",
    "#A65D57",
    "#6B5B95",
    "#8E9B5C",
    "#C97B63",
    "#3F87B5",
    "#B58457",
    "#5C7F8E"
];

function getClassColor($classId, $colors) {
    $index = abs(crc32((string)$classId)) % count($colors);
    return $colors[$index];
}

/* =========================
   LOAD ASSIGNED CLASSES

   Only classes assigned to
   the logged-in teacher.
========================= */

$sql = "
    SELECT classes.*
    FROM classes
    WHERE classes.teacher_id = ?
";

if ($search !== '') {
    $sql .= "
        AND (
            classes.class_name LIKE ?
            OR classes.program LIKE ?
            OR classes.section LIKE ?
            OR classes.schedule LIKE ?
            OR classes.room LIKE ?
        )
    ";
}

$sql .= " ORDER BY classes.created_at DESC";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die("Database error: " . e(mysqli_error($conn)));
}

if ($search !== '') {
    $searchTerm = "%" . $search . "%";

    mysqli_stmt_bind_param(
        $stmt,
        "isssss",
        $teacher_id,
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm
    );
} else {
    mysqli_stmt_bind_param($stmt, "i", $teacher_id);
}

mysqli_stmt_execute($stmt);
$query = mysqli_stmt_get_result($stmt);

if (!$query) {
    die("Unable to load classes.");
}

/* =========================
   PROFILE IMAGE
========================= */

$profileFile = $user['profile_image'] ?? '';

$profilePath = '';

if (
    !empty($profileFile) &&
    !in_array(
        strtolower($profileFile),
        ['default.png', 'default.jpg', 'default.jpeg', 'null']
    )
) {
    $safeFilename = basename($profileFile);

    if (file_exists(__DIR__ . '/uploads/' . $safeFilename)) {
        $profilePath = 'uploads/' . $safeFilename;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Classes | PLMUN Classroom</title>

    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">

    <link rel="stylesheet" href="teacher_classpage.css">

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

<!-- =========================
     SIDEBAR
========================= -->

<div class="sidebar" id="sidebar">

    <div class="sidebar-header">

        <button class="toggle-btn" onclick="toggleSidebar()">
            <i class="fa-solid fa-bars"></i>
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
            <a href="teacher_classpage.php" class="active">
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

<!-- MOBILE SIDEBAR OVERLAY -->

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
    onclick="closeMobileSidebar()"
></div>


<!-- =========================
     MAIN CONTENT
========================= -->

<div class="main-content" id="mainContent">

    <!-- TOPBAR -->

    <div class="topbar">

        <div class="navbar-left">

            <button class="mobile-menu" onclick="mobileSidebar()">
                <i class="fa-solid fa-bars"></i>
            </button>

            <img
                src="img/classroom logo.png"
                alt="PLMUN Classroom"
                class="navbar-logo"
            >

        </div>


        <div class="navbar-right">

            <!-- CREATE CLASS -->

            <a href="create_class.php" title="Create Class">
                <i class="fa-solid fa-plus fa-lg"></i>
            </a>


            <!-- NOTIFICATIONS -->

            <div class="dropdown">

                <a
                    href="#"
                    class="position-relative text-dark"
                    role="button"
                    data-bs-toggle="dropdown"
                    aria-expanded="false"
                >
                    <i class="fa-regular fa-bell fa-lg"></i>
                </a>

                <div class="dropdown-menu dropdown-menu-end notification-box p-0">

                    <h6 class="fw-bold p-3 mb-0">
                        Notifications
                    </h6>

                    <div class="notification-item px-3">

                        <div class="avatar"></div>

                        <div>
                            <h6 class="mb-0">
                                No new notifications
                            </h6>
                        </div>

                    </div>

                </div>

            </div>


            <!-- PROFILE -->

            <a href="profile.php" class="profile-icon">

                <?php if (!empty($profilePath)): ?>

                    <img
                        src="<?php echo e($profilePath); ?>"
                        alt="Profile"
                        onerror="this.style.display='none'; this.parentElement.insertAdjacentHTML('beforeend','<i class=&quot;fa-solid fa-user&quot;></i>');"
                    >

                <?php else: ?>

                    <i class="fa-solid fa-user"></i>

                <?php endif; ?>

            </a>

        </div>

    </div>


    <!-- =========================
         SEARCH SECTION
    ========================= -->

    <div class="search-section">

        <div class="search-box">

            <form method="GET" action="teacher_classpage.php">

                <div class="input-group">

                    <input
                        type="text"
                        name="search"
                        class="form-control"
                        placeholder="Search classes, programs, sections..."
                        value="<?php echo e($search); ?>"
                    >

                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </button>

                    <?php if ($search !== ''): ?>

                        <a
                            href="teacher_classpage.php"
                            class="btn btn-outline-secondary"
                            title="Clear search"
                        >
                            <i class="fa-solid fa-xmark"></i>
                        </a>

                    <?php endif; ?>

                </div>

            </form>

        </div>

    </div>


    <!-- =========================
         CLASSES CONTENT
    ========================= -->

    <div class="container-fluid p-4">

        <div class="row mt-4">

            <?php if (mysqli_num_rows($query) > 0): ?>

                <?php while ($class = mysqli_fetch_assoc($query)): ?>

                    <div class="col-lg-6 col-md-6 mb-4">

                        <a
                            href="teacher_class.php?id=<?php echo (int)$class['id']; ?>"
                            class="class-link"
                        >

                            <div class="class-card">

                                <!-- CLASS BANNER -->

                                <div
                                    class="class-banner"
                                    style="background: <?php echo e(getClassColor($class['id'], $cardColors)); ?>;"
                                ></div>


                                <!-- CLASS INFORMATION -->

                                <div class="class-content">

                                    <h3>
                                        <?php echo e($class['class_name']); ?>
                                    </h3>

                                    <p class="teacher-name">
                                        <?php echo e($user['name']); ?>
                                    </p>

                                    <?php if (!empty($class['program'])): ?>

                                        <p>
                                            <i class="fa-solid fa-graduation-cap"></i>
                                            <?php echo e($class['program']); ?>
                                        </p>

                                    <?php endif; ?>

                                    <p>
                                        <i class="fa-solid fa-users"></i>
                                        <?php echo e($class['section']); ?>
                                    </p>

                                    <p>
                                        <i class="fa-regular fa-clock"></i>
                                        <?php echo e($class['schedule']); ?>
                                    </p>

                                    <p>
                                        <i class="fa-solid fa-location-dot"></i>
                                        <?php echo e($class['room']); ?>
                                    </p>

                                </div>


                                <!-- CLASS FOOTER -->

                                <div class="class-footer">
                                    <i class="fa-solid fa-ellipsis-vertical"></i>
                                </div>

                            </div>

                        </a>

                    </div>

                <?php endwhile; ?>

            <?php else: ?>

                <div class="col-12">

                    <div class="alert alert-info text-center p-4">

                        <?php if ($search !== ''): ?>

                            <i class="fa-solid fa-magnifying-glass fa-2x mb-3"></i>

                            <h5>No matching classes found</h5>

                            <p class="mb-0">
                                Try another search term.
                            </p>

                        <?php else: ?>

                            <i class="fa-solid fa-chalkboard-user fa-2x mb-3"></i>

                            <h5>No assigned classes yet</h5>

                            <p class="mb-0">
                                Classes assigned to you by the Director will appear here.
                            </p>

                        <?php endif; ?>

                    </div>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>


<!-- =========================
     JAVASCRIPT
========================= -->

<script>

function toggleSidebar() {

    if (window.innerWidth > 768) {

        document.getElementById('sidebar')
            .classList.toggle('collapsed');

        document.getElementById('mainContent')
            .classList.toggle('expanded');

    }

}

function mobileSidebar() {

    document.getElementById('sidebar')
        .classList.toggle('show');

    document.getElementById('sidebarOverlay')
        .classList.toggle('show');

}

function closeMobileSidebar() {

    document.getElementById('sidebar')
        .classList.remove('show');

    document.getElementById('sidebarOverlay')
        .classList.remove('show');

}

window.addEventListener('resize', function () {

    if (window.innerWidth > 768) {
        closeMobileSidebar();
    }

});

</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
