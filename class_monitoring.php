<?php
session_start();
include 'database.php';

/* =====================================
   DIRECTOR ACCESS CHECK
===================================== */
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'director') {
    header("Location: login.php");
    exit();
}

$directorName = $_SESSION['name'] ?? 'Director';

/* =====================================
   HELPER FUNCTION
===================================== */
function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/* =====================================
   SEARCH AND FILTER
===================================== */
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? 'all';

$allowedStatuses = ['all', 'assigned', 'unassigned', 'full'];

if (!in_array($status, $allowedStatuses, true)) {
    $status = 'all';
}

$searchParam = "%{$search}%";

/* =====================================
   FETCH CLASSES
===================================== */
$sql = "
    SELECT
        c.id,
        c.class_name,
        c.program,
        c.section,
        c.academic_year,
        c.schedule,
        c.room,
        c.class_code,
        c.teacher_id,
        c.max_students,
        c.created_at,
        u.name AS instructor_name,
        COUNT(cs.student_id) AS enrolled_students

    FROM classes c

    LEFT JOIN users u
        ON c.teacher_id = u.id

    LEFT JOIN class_students cs
        ON c.id = cs.class_id
        AND (cs.archived = 0 OR cs.archived IS NULL)

    WHERE (
        c.class_name LIKE ?
        OR c.program LIKE ?
        OR c.section LIKE ?
        OR c.academic_year LIKE ?
        OR c.schedule LIKE ?
        OR c.room LIKE ?
        OR u.name LIKE ?
    )

    GROUP BY c.id
";

/* Apply status filters */
if ($status === 'unassigned') {
    $sql .= " HAVING (c.teacher_id IS NULL OR c.teacher_id = 0)";
} elseif ($status === 'assigned') {
    $sql .= " HAVING (c.teacher_id IS NOT NULL AND c.teacher_id != 0)";
} elseif ($status === 'full') {
    $sql .= " HAVING COUNT(cs.student_id) >= COALESCE(c.max_students, 50)";
}

$sql .= " ORDER BY c.created_at DESC";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Database error: " . e($conn->error));
}

$stmt->bind_param(
    "sssssss",
    $searchParam,
    $searchParam,
    $searchParam,
    $searchParam,
    $searchParam,
    $searchParam,
    $searchParam
);

$stmt->execute();

$result = $stmt->get_result();

$classes = [];

while ($row = $result->fetch_assoc()) {
    $classes[] = $row;
}

$stmt->close();

/* =====================================
   SUMMARY COUNTS
   Based on the current search/filter
===================================== */
$totalClasses = count($classes);
$assignedClasses = 0;
$unassignedClasses = 0;
$fullClasses = 0;
$totalStudents = 0;

foreach ($classes as $class) {
    $enrolled = (int)$class['enrolled_students'];
    $capacity = (int)($class['max_students'] ?? 50);

    $totalStudents += $enrolled;

    if (empty($class['teacher_id'])) {
        $unassignedClasses++;
    } else {
        $assignedClasses++;
    }

    if ($enrolled >= $capacity) {
        $fullClasses++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Class Monitoring | PLMUN Classroom</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <link rel="stylesheet" href="class_monitoring.css">
    
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
            <a href="director_dashboard.php">
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
            <a href="class_monitoring.php" class="active">
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

<div class="sidebar-overlay" id="sidebarOverlay" onclick="mobileSidebar()"></div>

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


    <!-- PAGE HEADER -->
    <div class="page-header">

        <div>
            <h3>Class Monitoring</h3>

            <p>
                Monitor all classes, instructor assignments, and student enrollment.
            </p>
        </div>

        <a href="class_management.php" class="btn btn-primary btn-sm">
            <i class="fa-solid fa-plus me-1"></i>
            Manage Classes
        </a>

    </div>


    <!-- SUMMARY CARDS -->
    <div class="row g-3">

        <div class="col-6 col-lg-3">
            <div class="summary-card">
                <div class="summary-top">
                    <div>
                        <p>Total Classes</p>
                        <h3><?= $totalClasses ?></h3>
                    </div>

                    <div class="summary-icon icon-blue">
                        <i class="fa-solid fa-chalkboard"></i>
                    </div>
                </div>
            </div>
        </div>


        <div class="col-6 col-lg-3">
            <div class="summary-card">
                <div class="summary-top">
                    <div>
                        <p>Assigned Classes</p>
                        <h3><?= $assignedClasses ?></h3>
                    </div>

                    <div class="summary-icon icon-green">
                        <i class="fa-solid fa-user-check"></i>
                    </div>
                </div>
            </div>
        </div>


        <div class="col-6 col-lg-3">
            <div class="summary-card">
                <div class="summary-top">
                    <div>
                        <p>Unassigned Classes</p>
                        <h3><?= $unassignedClasses ?></h3>
                    </div>

                    <div class="summary-icon icon-orange">
                        <i class="fa-solid fa-user-clock"></i>
                    </div>
                </div>
            </div>
        </div>


        <div class="col-6 col-lg-3">
            <div class="summary-card">
                <div class="summary-top">
                    <div>
                        <p>Enrolled Students</p>
                        <h3><?= $totalStudents ?></h3>
                    </div>

                    <div class="summary-icon icon-red">
                        <i class="fa-solid fa-users"></i>
                    </div>
                </div>
            </div>
        </div>

    </div>


    <!-- CLASS MONITORING TABLE -->
    <div class="content-panel">

        <div class="panel-heading">
            <h5>
                <i class="fa-solid fa-list-check me-2 text-primary"></i>
                Class Overview
            </h5>
        </div>


        <!-- SEARCH AND FILTER -->
        <form method="GET" action="class_monitoring.php" class="filter-form">

            <div class="search-wrapper">
                <i class="fa-solid fa-magnifying-glass"></i>

                <input
                    type="text"
                    name="search"
                    class="form-control"
                    placeholder="Search class, program, section, or instructor..."
                    value="<?= e($search) ?>"
                >
            </div>


            <select name="status" class="form-select" style="max-width: 190px;">

                <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>
                    All Classes
                </option>

                <option value="assigned" <?= $status === 'assigned' ? 'selected' : '' ?>>
                    Assigned
                </option>

                <option value="unassigned" <?= $status === 'unassigned' ? 'selected' : '' ?>>
                    Unassigned
                </option>

                <option value="full" <?= $status === 'full' ? 'selected' : '' ?>>
                    Full Classes
                </option>

            </select>


            <button type="submit" class="btn-filter">
                <i class="fa-solid fa-filter me-1"></i>
                Filter
            </button>


            <?php if ($search !== '' || $status !== 'all'): ?>
                <a href="class_monitoring.php" class="btn btn-outline-secondary btn-sm d-flex align-items-center">
                    Reset
                </a>
            <?php endif; ?>

        </form>


        <!-- TABLE -->
        <?php if (count($classes) > 0): ?>

            <div class="table-responsive">

                <table class="table">

                    <thead>
                        <tr>
                            <th>CLASS INFORMATION</th>
                            <th>SECTION</th>
                            <th>ACADEMIC YEAR</th>
                            <th>INSTRUCTOR</th>
                            <th>ENROLLMENT</th>
                            <th>STATUS</th>
                            <th>SCHEDULE</th>
                            <th>ROOM</th>
                        </tr>
                    </thead>


                    <tbody>

                        <?php foreach ($classes as $class): ?>

                            <?php
                                $enrolled = (int)$class['enrolled_students'];
                                $capacity = (int)($class['max_students'] ?? 50);

                                if ($capacity <= 0) {
                                    $capacity = 50;
                                }

                                $percentage = min(100, ($enrolled / $capacity) * 100);

                                $isUnassigned = empty($class['teacher_id']);
                                $isFull = $enrolled >= $capacity;
                            ?>

                            <tr>

                                <!-- CLASS INFORMATION -->
                                <td>
                                    <div class="class-name">
                                        <?= e($class['class_name']) ?>
                                    </div>

                                    <div class="class-program">
                                        <?= e($class['program'] ?: 'No program specified') ?>
                                    </div>

                                    <small class="text-muted">
                                        Code: <?= e($class['class_code']) ?>
                                    </small>
                                </td>


                                <!-- SECTION -->
                                <td>
                                    <?= e($class['section'] ?: '—') ?>
                                </td>


                                <!-- ACADEMIC YEAR -->
                                <td>
                                    <?= e($class['academic_year'] ?: '—') ?>
                                </td>


                                <!-- INSTRUCTOR -->
                                <td>

                                    <?php if (!$isUnassigned && !empty($class['instructor_name'])): ?>

                                        <span>
                                            <i class="fa-solid fa-user-tie text-success me-1"></i>
                                            <?= e($class['instructor_name']) ?>
                                        </span>

                                    <?php else: ?>

                                        <span class="text-warning">
                                            <i class="fa-solid fa-circle-exclamation me-1"></i>
                                            No instructor assigned
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- ENROLLMENT -->
                                <td style="min-width: 145px;">

                                    <div class="capacity-text">
                                        <strong><?= $enrolled ?></strong>
                                        / <?= $capacity ?> students
                                    </div>

                                    <div class="progress">
                                        <div
                                            class="progress-bar <?= $isFull ? 'bg-danger' : 'bg-primary' ?>"
                                            role="progressbar"
                                            style="width: <?= $percentage ?>%;"
                                            aria-valuenow="<?= $percentage ?>"
                                            aria-valuemin="0"
                                            aria-valuemax="100">
                                        </div>
                                    </div>

                                </td>


                                <!-- STATUS -->
                                <td>

                                    <?php if ($isUnassigned): ?>

                                        <span class="status-badge status-unassigned">
                                            Unassigned
                                        </span>

                                    <?php elseif ($isFull): ?>

                                        <span class="status-badge status-full">
                                            Full
                                        </span>

                                    <?php else: ?>

                                        <span class="status-badge status-assigned">
                                            Assigned
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- SCHEDULE -->
                                <td>
                                    <?= e($class['schedule'] ?: '—') ?>
                                </td>


                                <!-- ROOM -->
                                <td>
                                    <?= e($class['room'] ?: '—') ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <div class="empty-state">

                <i class="fa-solid fa-folder-open"></i>

                <h5>No Classes Found</h5>

                <p>
                    There are no classes matching your search or selected filter.
                </p>

            </div>

        <?php endif; ?>

    </div>

</main>


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

</body>
</html>