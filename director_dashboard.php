
<?php
session_start();
include 'database.php';

/* =====================================
   DIRECTOR ACCESS PROTECTION
===================================== */

if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (($_SESSION['role'] ?? '') !== 'director') {
    header("Location: login.php");
    exit();
}

$director_id = (int)$_SESSION['user_id'];

/* =====================================
   HELPER
===================================== */

function e($value) {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

/* =====================================
   CHECK CLASS_INSTRUCTORS TABLE
===================================== */

$hasCoInstructorTable = false;

$tableCheck = mysqli_query(
    $conn,
    "SHOW TABLES LIKE 'class_instructors'"
);

if ($tableCheck && mysqli_num_rows($tableCheck) > 0) {
    $hasCoInstructorTable = true;
}

/* =====================================
   DIRECTOR INFORMATION
===================================== */

$stmt = mysqli_prepare(
    $conn,
    "SELECT name FROM users WHERE id = ? LIMIT 1"
);

if (!$stmt) {
    die("Unable to load director information: " . e(mysqli_error($conn)));
}

mysqli_stmt_bind_param($stmt, "i", $director_id);
mysqli_stmt_execute($stmt);

$directorResult = mysqli_stmt_get_result($stmt);
$director = mysqli_fetch_assoc($directorResult);

mysqli_stmt_close($stmt);

$directorName = $director['name'] ?? 'Director';

/* =====================================
   DASHBOARD STATISTICS
===================================== */

// Total classes
$totalClassesQuery = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total FROM classes"
);

if (!$totalClassesQuery) {
    die("Unable to count classes: " . e(mysqli_error($conn)));
}

$totalClasses = (int)mysqli_fetch_assoc($totalClassesQuery)['total'];

/* =====================================
   ASSIGNED CLASSES
   PRIMARY OR CO-INSTRUCTOR
===================================== */

if ($hasCoInstructorTable) {

    $assignedSQL = "
        SELECT COUNT(DISTINCT c.id) AS total
        FROM classes c
        WHERE
            (c.teacher_id IS NOT NULL AND c.teacher_id != 0)
            OR EXISTS (
                SELECT 1
                FROM class_instructors ci
                WHERE ci.class_id = c.id
            )
    ";

} else {

    $assignedSQL = "
        SELECT COUNT(*) AS total
        FROM classes
        WHERE teacher_id IS NOT NULL
          AND teacher_id != 0
    ";

}

$assignedQuery = mysqli_query($conn, $assignedSQL);

if (!$assignedQuery) {
    die("Unable to count assigned classes: " . e(mysqli_error($conn)));
}

$assignedClasses = (int)mysqli_fetch_assoc($assignedQuery)['total'];

/* =====================================
   UNASSIGNED CLASSES
===================================== */

if ($hasCoInstructorTable) {

    $unassignedSQL = "
        SELECT COUNT(*) AS total
        FROM classes c
        WHERE
            (c.teacher_id IS NULL OR c.teacher_id = 0)
            AND NOT EXISTS (
                SELECT 1
                FROM class_instructors ci
                WHERE ci.class_id = c.id
            )
    ";

} else {

    $unassignedSQL = "
        SELECT COUNT(*) AS total
        FROM classes
        WHERE teacher_id IS NULL
           OR teacher_id = 0
    ";

}

$unassignedQuery = mysqli_query($conn, $unassignedSQL);

if (!$unassignedQuery) {
    die("Unable to count unassigned classes: " . e(mysqli_error($conn)));
}

$unassignedClasses = (int)mysqli_fetch_assoc($unassignedQuery)['total'];

/* =====================================
   TOTAL INSTRUCTORS
===================================== */

$instructorsQuery = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total
     FROM users
     WHERE role = 'teacher'"
);

if (!$instructorsQuery) {
    die("Unable to count instructors: " . e(mysqli_error($conn)));
}

$totalInstructors = (int)mysqli_fetch_assoc($instructorsQuery)['total'];

/* =====================================
   RECENTLY CREATED CLASSES

   DISPLAY:
   - PRIMARY INSTRUCTOR
   - CO-INSTRUCTORS
===================================== */

if ($hasCoInstructorTable) {

    $recentClassesSQL = "
        SELECT
            c.id,
            c.class_name,
            c.section,
            c.academic_year,
            c.schedule,
            c.room,
            c.class_code,
            c.teacher_id,
            c.created_at,

            primary_teacher.name AS teacher_name,

            GROUP_CONCAT(
                DISTINCT
                CASE
                    WHEN ci.teacher_id IS NOT NULL
                         AND ci.teacher_id != c.teacher_id
                    THEN co_teacher.name
                    ELSE NULL
                END
                ORDER BY co_teacher.name
                SEPARATOR '|||'
            ) AS co_instructor_names

        FROM classes c

        LEFT JOIN users primary_teacher
            ON c.teacher_id = primary_teacher.id

        LEFT JOIN class_instructors ci
            ON ci.class_id = c.id

        LEFT JOIN users co_teacher
            ON co_teacher.id = ci.teacher_id

        GROUP BY
            c.id,
            c.class_name,
            c.section,
            c.academic_year,
            c.schedule,
            c.room,
            c.class_code,
            c.teacher_id,
            c.created_at,
            primary_teacher.name

        ORDER BY c.created_at DESC

        LIMIT 8
    ";

} else {

    $recentClassesSQL = "
        SELECT
            c.id,
            c.class_name,
            c.section,
            c.academic_year,
            c.schedule,
            c.room,
            c.class_code,
            c.teacher_id,
            c.created_at,
            primary_teacher.name AS teacher_name,
            NULL AS co_instructor_names

        FROM classes c

        LEFT JOIN users primary_teacher
            ON c.teacher_id = primary_teacher.id

        ORDER BY c.created_at DESC

        LIMIT 8
    ";

}

$recentClassesQuery = mysqli_query($conn, $recentClassesSQL);

if (!$recentClassesQuery) {
    die("Unable to load recent classes: " . e(mysqli_error($conn)));
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Director Dashboard | PLMUN Classroom</title>

    <link rel="shortcut icon" href="img/plmun logo.png">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <link rel="stylesheet" href="director_dasboard.css">

    <style>
        /* INSTRUCTOR DISPLAY */

        .instructor-list {
            display: flex;
            flex-direction: column;
            gap: 7px;
            min-width: 160px;
        }

        .instructor-item {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 13px;
            color: #344054;
        }

        .instructor-item i {
            color: #3f87b5;
            font-size: 12px;
            width: 15px;
            text-align: center;
        }

        .instructor-role {
            display: inline-block;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 10px;
            background: #e8f2fb;
            color: #3276a5;
            margin-left: 3px;
        }

        .no-instructor {
            color: #999;
            font-size: 13px;
        }

        .status-badge {
            white-space: nowrap;
        }

        .table td {
            vertical-align: middle;
        }
    </style>

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
            <a href="director_dashboard.php" class="active">
                <i class="fa-solid fa-house"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <li>
            <a href="class_management.php">
                <i class="fa-solid fa-book"></i>
                <span>Class Management</span>
            </a>
        </li>

        <li>
            <a href="instructor_assignment.php">
                <i class="fa-solid fa-user-check"></i>
                <span>Instructor Assignment</span>
            </a>
        </li>

        <li>
            <a href="class_monitoring.php">
                <i class="fa-solid fa-chart-line"></i>
                <span>Class Monitoring</span>
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

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
    onclick="mobileSidebar()"
></div>

<!-- MAIN CONTENT -->

<div class="main-content" id="mainContent">

    <!-- TOPBAR -->

    <div class="topbar">

        <div class="topbar-logo d-flex align-items-center gap-3">

            <button class="mobile-menu" onclick="mobileSidebar()">
                <i class="fa-solid fa-bars"></i>
            </button>

            <img src="img/plmun logo.png" alt="logo">

            <h5>PLMUN Director</h5>

        </div>

        <div class="director-info">

            <div class="director-avatar">
                <i class="fa-solid fa-user-tie"></i>
            </div>

            <span><?php echo e($directorName); ?></span>

        </div>

    </div>

    <!-- DASHBOARD CONTENT -->

    <div class="dashboard-container">

        <!-- WELCOME -->

        <div class="welcome-section">

            <h3>
                Welcome, <?php echo e($directorName); ?>!
            </h3>

            <p>
                Manage classes, assign instructors, and monitor classroom activities.
            </p>

        </div>

        <!-- STATISTICS -->

        <div class="row g-3 mb-4">

            <!-- TOTAL CLASSES -->

            <div class="col-xl-3 col-md-6">

                <div class="stat-card">

                    <div class="stat-top">

                        <div>
                            <h3><?php echo number_format($totalClasses); ?></h3>
                            <p>Total Classes</p>
                        </div>

                        <div class="stat-icon icon-blue">
                            <i class="fa-solid fa-book-open"></i>
                        </div>

                    </div>

                </div>

            </div>

            <!-- ASSIGNED -->

            <div class="col-xl-3 col-md-6">

                <div class="stat-card">

                    <div class="stat-top">

                        <div>
                            <h3><?php echo number_format($assignedClasses); ?></h3>
                            <p>Assigned Classes</p>
                        </div>

                        <div class="stat-icon icon-green">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>

                    </div>

                </div>

            </div>

            <!-- UNASSIGNED -->

            <div class="col-xl-3 col-md-6">

                <div class="stat-card">

                    <div class="stat-top">

                        <div>
                            <h3><?php echo number_format($unassignedClasses); ?></h3>
                            <p>Unassigned Classes</p>
                        </div>

                        <div class="stat-icon icon-orange">
                            <i class="fa-solid fa-user-clock"></i>
                        </div>

                    </div>

                </div>

            </div>

            <!-- INSTRUCTORS -->

            <div class="col-xl-3 col-md-6">

                <div class="stat-card">

                    <div class="stat-top">

                        <div>
                            <h3><?php echo number_format($totalInstructors); ?></h3>
                            <p>Total Instructors</p>
                        </div>

                        <div class="stat-icon icon-purple">
                            <i class="fa-solid fa-chalkboard-user"></i>
                        </div>

                    </div>

                </div>

            </div>

        </div>

        <!-- QUICK ACTIONS -->

        <h5 class="section-heading">Quick Actions</h5>

        <div class="row g-3 mb-4">

            <div class="col-lg-4 col-md-6">

                <a href="class_management.php" class="quick-action">

                    <i class="fa-solid fa-plus-circle"></i>

                    <div>
                        <h6>Create Class</h6>
                        <small>Create and manage subjects and sections</small>
                    </div>

                </a>

            </div>

            <div class="col-lg-4 col-md-6">

                <a href="instructor_assignment.php" class="quick-action">

                    <i class="fa-solid fa-user-plus"></i>

                    <div>
                        <h6>Assign Instructor</h6>
                        <small>Assign primary and co-instructors to classes</small>
                    </div>

                </a>

            </div>

            <div class="col-lg-4 col-md-6">

                <a href="class_monitoring.php" class="quick-action">

                    <i class="fa-solid fa-chart-column"></i>

                    <div>
                        <h6>Monitor Classes</h6>
                        <small>View class assignments and enrollment</small>
                    </div>

                </a>

            </div>

        </div>

        <!-- RECENT CLASSES -->

        <div class="table-container">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">

                <h5 class="section-heading mb-0">
                    Recently Created Classes
                </h5>

                <a
                    href="class_management.php"
                    class="btn btn-sm btn-outline-primary"
                >
                    View All Classes
                    <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>

            </div>

            <div class="table-responsive">

                <table class="table table-hover">

                    <thead>

                        <tr>
                            <th>Class / Subject</th>
                            <th>Section</th>
                            <th>Academic Year</th>
                            <th>Instructor(s)</th>
                            <th>Class Code</th>
                            <th>Status</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php if (mysqli_num_rows($recentClassesQuery) > 0): ?>

                            <?php while ($class = mysqli_fetch_assoc($recentClassesQuery)): ?>

                                <?php
                                $primaryInstructor = trim(
                                    (string)($class['teacher_name'] ?? '')
                                );

                                $coInstructorNames = [];

                                if (!empty($class['co_instructor_names'])) {
                                    $coInstructorNames = explode(
                                        '|||',
                                        $class['co_instructor_names']
                                    );
                                }

                                $coInstructorNames = array_values(
                                    array_unique(
                                        array_filter(
                                            array_map('trim', $coInstructorNames)
                                        )
                                    )
                                );

                                $hasPrimaryInstructor =
                                    !empty($class['teacher_id']) &&
                                    $primaryInstructor !== '';

                                $hasAnyInstructor =
                                    $hasPrimaryInstructor ||
                                    count($coInstructorNames) > 0;
                                ?>

                                <tr>

                                    <!-- CLASS NAME -->

                                    <td>
                                        <strong>
                                            <?php echo e($class['class_name']); ?>
                                        </strong>
                                    </td>

                                    <!-- SECTION -->

                                    <td>
                                        <?php echo e($class['section']); ?>
                                    </td>

                                    <!-- ACADEMIC YEAR -->

                                    <td>
                                        <?php
                                        echo e(
                                            $class['academic_year']
                                            ?: 'Not specified'
                                        );
                                        ?>
                                    </td>

                                    <!-- INSTRUCTORS -->

                                    <td>

                                        <?php if ($hasAnyInstructor): ?>

                                            <div class="instructor-list">

                                                <?php if ($hasPrimaryInstructor): ?>

                                                    <div class="instructor-item">

                                                        <i class="fa-solid fa-user-tie"></i>

                                                        <span>
                                                            <?php echo e($primaryInstructor); ?>

                                                            <span class="instructor-role">
                                                                Primary
                                                            </span>
                                                        </span>

                                                    </div>

                                                <?php endif; ?>

                                                <?php foreach ($coInstructorNames as $coName): ?>

                                                    <div class="instructor-item">

                                                        <i class="fa-solid fa-user"></i>

                                                        <span>
                                                            <?php echo e($coName); ?>

                                                            <span class="instructor-role">
                                                                Co-Instructor
                                                            </span>
                                                        </span>

                                                    </div>

                                                <?php endforeach; ?>

                                            </div>

                                        <?php else: ?>

                                            <span class="no-instructor">
                                                No instructor assigned
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <!-- CLASS CODE -->

                                    <td>

                                        <span class="class-code">
                                            <?php echo e($class['class_code']); ?>
                                        </span>

                                    </td>

                                    <!-- STATUS -->

                                    <td>

                                        <?php if ($hasAnyInstructor): ?>

                                            <span class="status-badge status-assigned">
                                                Assigned
                                            </span>

                                        <?php else: ?>

                                            <span class="status-badge status-unassigned">
                                                Unassigned
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endwhile; ?>

                        <?php else: ?>

                            <tr>

                                <td colspan="6" class="text-center text-muted py-4">

                                    <i class="fa-solid fa-folder-open fa-2x mb-2"></i>

                                    <p class="mb-0">
                                        No classes have been created yet.
                                    </p>

                                </td>

                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>

<script>

/* SIDEBAR ELEMENTS */

const sidebar = document.getElementById('sidebar');
const mainContent = document.getElementById('mainContent');
const overlay = document.getElementById('sidebarOverlay');

/* DESKTOP SIDEBAR */

function toggleSidebar() {

    if (window.innerWidth <= 768) {
        mobileSidebar();
        return;
    }

    sidebar.classList.toggle('collapsed');
    mainContent.classList.toggle('expanded');

}

/* MOBILE SIDEBAR */

function mobileSidebar() {

    sidebar.classList.toggle('show');

    const isOpen = sidebar.classList.contains('show');

    overlay.classList.toggle('show', isOpen);

}

/* RESET MOBILE SIDEBAR ON RESIZE */

window.addEventListener('resize', function() {

    if (window.innerWidth > 768) {

        sidebar.classList.remove('show');
        overlay.classList.remove('show');

    }

});

</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
