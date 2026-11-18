
<?php
session_start();
include 'database.php';

/* ================= LOGIN + ROLE CHECK ================= */
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'program_chair') {
    header("Location: login.php");
    exit();
}

$chair_id = (int) $_SESSION['user_id'];

function e($v) {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

/* ================= FETCH CHAIR ================= */
$stmt = mysqli_prepare(
    $conn,
    "SELECT * FROM users WHERE id = ? AND role = 'program_chair'"
);

mysqli_stmt_bind_param($stmt, "i", $chair_id);
mysqli_stmt_execute($stmt);

$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

mysqli_stmt_close($stmt);

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

/* ================= FETCH ASSIGNED PROGRAMS ================= */
/*
   Program Chair assignments are stored in:
   program_chair_assignments
   programs

   NOT users.program.
*/

$programs = [];

$stmt = mysqli_prepare(
    $conn,
    "SELECT
        p.id,
        p.program_name,
        p.college_name,
        p.college_code
     FROM program_chair_assignments pca
     INNER JOIN programs p
        ON p.id = pca.program_id
     WHERE pca.user_id = ?
     ORDER BY p.college_name, p.program_name"
);

mysqli_stmt_bind_param($stmt, "i", $chair_id);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

while ($row = mysqli_fetch_assoc($result)) {
    $programs[] = $row;
}

mysqli_stmt_close($stmt);

/* Prepare program names for display */
$programNames = [];

foreach ($programs as $p) {
    $programNames[] = $p['program_name'];
}

$programDisplay = !empty($programNames)
    ? implode(', ', $programNames)
    : '';

$hasAssignedProgram = !empty($programs);

/* ================= SEARCH ================= */
$search = trim($_GET['search'] ?? '');

/* ================= CLASS CARD COLORS ================= */
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

function getClassColor($id, $colors) {
    return $colors[abs(crc32((string)$id)) % count($colors)];
}

/* ================= FETCH CLASSES ================= */

$classes = [];

if ($hasAssignedProgram) {

    /*
       Match classes.program against programs.program_name.

       This uses EXISTS so classes are not duplicated
       if a Program Chair has multiple assignments.
    */

    $sql = "
        SELECT DISTINCT
            c.*,
            COALESCE(u.name, 'No instructor assigned') AS teacher_name
        FROM classes c
        LEFT JOIN users u
            ON c.teacher_id = u.id
        WHERE EXISTS (
            SELECT 1
            FROM program_chair_assignments pca
            INNER JOIN programs p
                ON p.id = pca.program_id
            WHERE pca.user_id = ?
              AND TRIM(c.program) = TRIM(p.program_name)
        )
    ";

    if ($search !== '') {
        $sql .= "
            AND (
                c.class_name LIKE ?
                OR c.section LIKE ?
                OR c.schedule LIKE ?
                OR c.room LIKE ?
                OR u.name LIKE ?
            )
        ";
    }

    $sql .= " ORDER BY c.created_at DESC";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        die("Database error: " . e(mysqli_error($conn)));
    }

    if ($search !== '') {
        $like = "%{$search}%";

        mysqli_stmt_bind_param(
            $stmt,
            "isssss",
            $chair_id,
            $like,
            $like,
            $like,
            $like,
            $like
        );
    } else {
        mysqli_stmt_bind_param($stmt, "i", $chair_id);
    }

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $classes[] = $row;
    }

    mysqli_stmt_close($stmt);
}

/* ================= STATISTICS ================= */

$totalClasses = 0;
$instructorCount = 0;
$studentCount = 0;
$assessCount = 0;

if ($hasAssignedProgram) {

    /* Total classes and instructors */
    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            COUNT(*) AS total,
            COUNT(DISTINCT c.teacher_id) AS instructors
         FROM classes c
         WHERE EXISTS (
            SELECT 1
            FROM program_chair_assignments pca
            INNER JOIN programs p
                ON p.id = pca.program_id
            WHERE pca.user_id = ?
              AND TRIM(c.program) = TRIM(p.program_name)
         )"
    );

    mysqli_stmt_bind_param($stmt, "i", $chair_id);
    mysqli_stmt_execute($stmt);

    $r = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    $totalClasses = (int)($r['total'] ?? 0);
    $instructorCount = (int)($r['instructors'] ?? 0);

    mysqli_stmt_close($stmt);

    /* Total unique students */
    $stmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(DISTINCT cs.student_id) AS cnt
         FROM class_students cs
         INNER JOIN classes c
            ON cs.class_id = c.id
         WHERE cs.archived = 0
           AND EXISTS (
                SELECT 1
                FROM program_chair_assignments pca
                INNER JOIN programs p
                    ON p.id = pca.program_id
                WHERE pca.user_id = ?
                  AND TRIM(c.program) = TRIM(p.program_name)
           )"
    );

    mysqli_stmt_bind_param($stmt, "i", $chair_id);
    mysqli_stmt_execute($stmt);

    $r = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    $studentCount = (int)($r['cnt'] ?? 0);

    mysqli_stmt_close($stmt);

    /* Total assessments */
    $stmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS cnt
         FROM assessments a
         INNER JOIN classes c
            ON a.class_id = c.id
         WHERE EXISTS (
            SELECT 1
            FROM program_chair_assignments pca
            INNER JOIN programs p
                ON p.id = pca.program_id
            WHERE pca.user_id = ?
              AND TRIM(c.program) = TRIM(p.program_name)
         )"
    );

    mysqli_stmt_bind_param($stmt, "i", $chair_id);
    mysqli_stmt_execute($stmt);

    $r = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    $assessCount = (int)($r['cnt'] ?? 0);

    mysqli_stmt_close($stmt);
}

/* ================= PROFILE IMAGE ================= */

$profileFile = basename($user['profile_image'] ?? '');

$hasProfileImage = !empty($profileFile)
    && !in_array(
        strtolower($profileFile),
        ['default.png', 'default.jpg', 'default.jpeg', 'null']
    )
    && file_exists(__DIR__ . '/uploads/' . $profileFile);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Program Chair Dashboard</title>

    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">

    <link rel="stylesheet" href="teacher_dashboard.css">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
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
        <li>
            <a href="program_chair_dashboard.php" class="active">
                <i class="fa-solid fa-house"></i>
                <span>Home</span>
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

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileSidebar()"></div>

<!-- MAIN CONTENT -->
<div class="main-content" id="mainContent">

    <!-- TOPBAR -->
    <div class="topbar">

        <div class="navbar-left">

            <button class="mobile-menu" onclick="mobileSidebar()">
                <i class="fa-solid fa-bars"></i>
            </button>

            <img src="img/classroom logo.png" alt="Logo" class="navbar-logo">

        </div>

        <div class="navbar-right">

            <a href="profile.php" class="profile-icon">

                <?php if ($hasProfileImage): ?>

                    <img
                        src="uploads/<?php echo e($profileFile); ?>"
                        alt="Profile"
                        onerror="this.style.display='none'; this.parentElement.insertAdjacentHTML('beforeend','<i class=&quot;fa-solid fa-user&quot;></i>');"
                    >

                <?php else: ?>

                    <i class="fa-solid fa-user"></i>

                <?php endif; ?>

            </a>

        </div>

    </div>

    <!-- SEARCH -->
    <div class="search-section">

        <div class="search-box">

            <form method="GET" action="program_chair_dashboard.php">

                <div class="input-group">

                    <input
                        type="text"
                        name="search"
                        class="form-control"
                        placeholder="Search classes, sections, instructors..."
                        value="<?php echo e($search); ?>"
                    >

                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </button>

                    <?php if ($search !== ''): ?>

                        <a href="program_chair_dashboard.php" class="btn btn-outline-secondary">
                            <i class="fa-solid fa-xmark"></i>
                        </a>

                    <?php endif; ?>

                </div>

            </form>

        </div>

    </div>

    <div class="container-fluid p-4">

        <!-- WELCOME -->
        <div class="mb-3">

            <h4 class="fw-bold">
                Welcome, <?php echo e($user['name']); ?>!
            </h4>

            <p class="text-muted">

                <?php if ($hasAssignedProgram): ?>

                    Program Chair &middot;
                    <strong><?php echo e($programDisplay); ?></strong>

                <?php else: ?>

                    No program is assigned to your account yet.

                <?php endif; ?>

            </p>

        </div>

        <!-- STATISTICS -->
        <div class="row mb-3">

            <?php

            $stats = [
                ['Program Classes', $totalClasses, 'fa-chalkboard', 'primary'],
                ['Instructors', $instructorCount, 'fa-chalkboard-user', 'info'],
                ['Total Students', $studentCount, 'fa-user-graduate', 'success'],
                ['Assessments', $assessCount, 'fa-clipboard-check', 'warning']
            ];

            foreach ($stats as $s):

            ?>

                <div class="col-lg-3 col-md-6 col-sm-6 mb-3">

                    <div class="card border-0 shadow-sm p-3">

                        <div class="d-flex align-items-center gap-3">

                            <div class="rounded-circle p-3 bg-<?php echo e($s[3]); ?> bg-opacity-10 text-<?php echo e($s[3]); ?>">

                                <i class="fa-solid <?php echo e($s[2]); ?> fa-lg"></i>

                            </div>

                            <div>

                                <h4 class="fw-bold mb-0">
                                    <?php echo (int)$s[1]; ?>
                                </h4>

                                <small class="text-muted">
                                    <?php echo e($s[0]); ?>
                                </small>

                            </div>

                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

        <!-- CLASS HEADER -->
        <div class="d-flex justify-content-between align-items-center mt-4 mb-3">

            <div>

                <h5 class="fw-bold mb-1">
                    Classes in My Program
                </h5>

                <small class="text-muted">

                    All classes under
                    <?php echo e($programDisplay ?: 'your assigned program'); ?>.

                </small>

            </div>

            <span class="badge bg-primary">
                <?php echo count($classes); ?> Classes
            </span>

        </div>

        <!-- CLASS CARDS -->
        <div class="row mt-2">

            <?php if (!empty($classes)): ?>

                <?php foreach ($classes as $class): ?>

                    <div class="col-lg-6 col-md-6 mb-4">

                        <div class="class-card">

                            <a
                                href="program_chair_class.php?id=<?php echo (int)$class['id']; ?>"
                                class="class-link"
                            >

                                <div
                                    class="class-banner"
                                    style="background:<?php echo e(getClassColor($class['id'], $cardColors)); ?>;"
                                ></div>

                                <div class="class-content">

                                    <h3>
                                        <?php echo e($class['class_name']); ?>
                                    </h3>

                                    <p class="teacher-name">

                                        <i class="fa-solid fa-chalkboard-user me-1"></i>

                                        <?php echo e($class['teacher_name']); ?>

                                    </p>

                                    <?php if (!empty($class['section'])): ?>

                                        <p>
                                            <i class="fa-solid fa-users me-1"></i>
                                            <?php echo e($class['section']); ?>
                                        </p>

                                    <?php endif; ?>

                                    <?php if (!empty($class['schedule'])): ?>

                                        <p>
                                            <i class="fa-regular fa-clock me-1"></i>
                                            <?php echo e($class['schedule']); ?>
                                        </p>

                                    <?php endif; ?>

                                    <?php if (!empty($class['room'])): ?>

                                        <p>
                                            <i class="fa-solid fa-location-dot me-1"></i>
                                            <?php echo e($class['room']); ?>
                                        </p>

                                    <?php endif; ?>

                                    <p class="small text-muted mb-0">
                                        Class Code: <?php echo e($class['class_code']); ?>
                                    </p>

                                </div>

                            </a>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <div class="col-12">

                    <div class="alert alert-info d-flex align-items-center gap-3">

                        <i class="fa-solid fa-circle-info fa-lg"></i>

                        <div>

                            <?php if (!$hasAssignedProgram): ?>

                                <strong>No program assigned.</strong>

                                <div>
                                    Ask the Director or Administrator to assign a program to your account.
                                </div>

                            <?php elseif ($search !== ''): ?>

                                <strong>No matching classes found.</strong>

                                <div>
                                    Try another class name, section, or instructor.
                                </div>

                            <?php else: ?>

                                <strong>No classes found under your assigned program.</strong>

                                <div>
                                    Check whether the program assigned to you matches the program
                                    saved in the existing classes.
                                </div>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

function toggleSidebar() {

    if (window.innerWidth > 768) {

        document.getElementById('sidebar').classList.toggle('collapsed');

        document.getElementById('mainContent').classList.toggle('expanded');

    }

}

function mobileSidebar() {

    document.getElementById('sidebar').classList.toggle('show');

    document.getElementById('sidebarOverlay').classList.toggle('show');

}

function closeMobileSidebar() {

    document.getElementById('sidebar').classList.remove('show');

    document.getElementById('sidebarOverlay').classList.remove('show');

}

window.addEventListener('resize', function () {

    if (window.innerWidth > 768) {

        closeMobileSidebar();

    }

});

</script>

</body>
</html>
