
<?php
session_start();
include 'database.php';

/* =====================================
   DIRECTOR ACCESS CHECK
===================================== */

if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'director'
) {
    header("Location: login.php");
    exit();
}

$directorName = $_SESSION['name'] ?? 'Director';

/* =====================================
   HELPER FUNCTION
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
   SEARCH AND FILTER
===================================== */

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? 'all';

$allowedStatuses = [
    'all',
    'assigned',
    'unassigned',
    'full'
];

if (!in_array($status, $allowedStatuses, true)) {
    $status = 'all';
}

$searchParam = "%{$search}%";

/* =====================================
   FETCH CLASSES

   Uses separate subqueries for:
   - Active student enrollment
   - Co-instructor names

   This prevents duplicate enrollment counts
   when a class has multiple instructors.
===================================== */

if ($hasCoInstructorTable) {

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

            primary_teacher.name AS instructor_name,

            (
                SELECT GROUP_CONCAT(
                    DISTINCT co_teacher.name
                    ORDER BY co_teacher.name
                    SEPARATOR '|||'
                )
                FROM class_instructors ci
                INNER JOIN users co_teacher
                    ON co_teacher.id = ci.teacher_id
                WHERE ci.class_id = c.id
                  AND ci.teacher_id != COALESCE(c.teacher_id, 0)
            ) AS co_instructor_names,

            (
                SELECT COUNT(*)
                FROM class_students cs
                WHERE cs.class_id = c.id
                  AND (cs.archived = 0 OR cs.archived IS NULL)
            ) AS enrolled_students,

            (
                CASE
                    WHEN (
                        c.teacher_id IS NOT NULL
                        AND c.teacher_id != 0
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM class_instructors ci2
                        WHERE ci2.class_id = c.id
                    )
                    THEN 1
                    ELSE 0
                END
            ) AS has_instructor

        FROM classes c

        LEFT JOIN users primary_teacher
            ON c.teacher_id = primary_teacher.id

        WHERE (
            c.class_name LIKE ?
            OR c.program LIKE ?
            OR c.section LIKE ?
            OR c.academic_year LIKE ?
            OR c.schedule LIKE ?
            OR c.room LIKE ?
            OR primary_teacher.name LIKE ?

            OR EXISTS (
                SELECT 1
                FROM class_instructors search_ci
                INNER JOIN users search_teacher
                    ON search_teacher.id = search_ci.teacher_id
                WHERE search_ci.class_id = c.id
                  AND search_teacher.name LIKE ?
            )
        )
    ";

} else {

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

            primary_teacher.name AS instructor_name,

            NULL AS co_instructor_names,

            (
                SELECT COUNT(*)
                FROM class_students cs
                WHERE cs.class_id = c.id
                  AND (cs.archived = 0 OR cs.archived IS NULL)
            ) AS enrolled_students,

            CASE
                WHEN c.teacher_id IS NOT NULL
                     AND c.teacher_id != 0
                THEN 1
                ELSE 0
            END AS has_instructor

        FROM classes c

        LEFT JOIN users primary_teacher
            ON c.teacher_id = primary_teacher.id

        WHERE (
            c.class_name LIKE ?
            OR c.program LIKE ?
            OR c.section LIKE ?
            OR c.academic_year LIKE ?
            OR c.schedule LIKE ?
            OR c.room LIKE ?
            OR primary_teacher.name LIKE ?
        )
    ";
}

/* =====================================
   APPLY STATUS FILTERS
===================================== */

if ($status === 'unassigned') {

    if ($hasCoInstructorTable) {

        $sql .= "
            AND (
                c.teacher_id IS NULL
                OR c.teacher_id = 0
            )
            AND NOT EXISTS (
                SELECT 1
                FROM class_instructors ci
                WHERE ci.class_id = c.id
            )
        ";

    } else {

        $sql .= "
            AND (
                c.teacher_id IS NULL
                OR c.teacher_id = 0
            )
        ";

    }

} elseif ($status === 'assigned') {

    if ($hasCoInstructorTable) {

        $sql .= "
            AND (
                (
                    c.teacher_id IS NOT NULL
                    AND c.teacher_id != 0
                )
                OR EXISTS (
                    SELECT 1
                    FROM class_instructors ci
                    WHERE ci.class_id = c.id
                )
            )
        ";

    } else {

        $sql .= "
            AND c.teacher_id IS NOT NULL
            AND c.teacher_id != 0
        ";

    }

} elseif ($status === 'full') {

    $sql .= "
        AND (
            SELECT COUNT(*)
            FROM class_students cs
            WHERE cs.class_id = c.id
              AND (cs.archived = 0 OR cs.archived IS NULL)
        ) >= COALESCE(NULLIF(c.max_students, 0), 50)
    ";

}

$sql .= " ORDER BY c.created_at DESC";

/* =====================================
   PREPARE AND EXECUTE QUERY
===================================== */

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die("Database error: " . e(mysqli_error($conn)));
}

if ($hasCoInstructorTable) {

    mysqli_stmt_bind_param(
        $stmt,
        "ssssssss",
        $searchParam,
        $searchParam,
        $searchParam,
        $searchParam,
        $searchParam,
        $searchParam,
        $searchParam,
        $searchParam
    );

} else {

    mysqli_stmt_bind_param(
        $stmt,
        "sssssss",
        $searchParam,
        $searchParam,
        $searchParam,
        $searchParam,
        $searchParam,
        $searchParam,
        $searchParam
    );

}

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$classes = [];

while ($row = mysqli_fetch_assoc($result)) {

    $row['enrolled_students'] =
        (int)($row['enrolled_students'] ?? 0);

    $row['max_students'] =
        (int)($row['max_students'] ?? 50);

    if ($row['max_students'] <= 0) {
        $row['max_students'] = 50;
    }

    $row['has_instructor'] =
        (int)($row['has_instructor'] ?? 0);

    $classes[] = $row;
}

mysqli_stmt_close($stmt);

/* =====================================
   SUMMARY COUNTS
   BASED ON CURRENT SEARCH AND FILTER
===================================== */

$totalClasses = count($classes);
$assignedClasses = 0;
$unassignedClasses = 0;
$fullClasses = 0;
$totalStudents = 0;

foreach ($classes as $class) {

    $enrolled = (int)$class['enrolled_students'];
    $capacity = (int)$class['max_students'];

    $totalStudents += $enrolled;

    if ($class['has_instructor']) {
        $assignedClasses++;
    } else {
        $unassignedClasses++;
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

    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <link rel="stylesheet" href="class_monitoring.css">

    <style>

        /* =====================================
           MULTIPLE INSTRUCTOR DISPLAY
        ===================================== */

        .instructor-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 180px;
        }

        .instructor-item {
            display: flex;
            align-items: flex-start;
            gap: 7px;
            color: #344054;
            font-size: 13px;
            line-height: 1.4;
        }

        .instructor-item i {
            width: 15px;
            min-width: 15px;
            margin-top: 3px;
            text-align: center;
            color: #3f87b5;
        }

        .instructor-name {
            overflow-wrap: anywhere;
        }

        .instructor-role {
            display: inline-block;
            margin-top: 3px;
            padding: 2px 7px;
            border-radius: 10px;
            background: #e8f2fb;
            color: #3276a5;
            font-size: 10px;
            font-weight: 600;
        }

        .primary-role {
            background: #e4f5eb;
            color: #218653;
        }

        .no-instructor {
            color: #dc8b20;
            font-size: 12px;
            white-space: nowrap;
        }

        .table td {
            vertical-align: middle;
        }

        .progress {
            min-width: 100px;
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

    <!-- PAGE HEADER -->

    <div class="page-header">

        <div>

            <h3>Class Monitoring</h3>

            <p>
                Monitor all classes, instructor assignments, and student enrollment.
            </p>

        </div>

        <a
            href="class_management.php"
            class="btn btn-primary btn-sm"
        >
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
                        <h3><?php echo number_format($totalClasses); ?></h3>
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
                        <h3><?php echo number_format($assignedClasses); ?></h3>
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
                        <h3><?php echo number_format($unassignedClasses); ?></h3>
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
                        <h3><?php echo number_format($totalStudents); ?></h3>
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

        <form
            method="GET"
            action="class_monitoring.php"
            class="filter-form"
        >

            <div class="search-wrapper">

                <i class="fa-solid fa-magnifying-glass"></i>

                <input
                    type="text"
                    name="search"
                    class="form-control"
                    placeholder="Search class, program, section, or instructor..."
                    value="<?php echo e($search); ?>"
                >

            </div>

            <select
                name="status"
                class="form-select"
                style="max-width: 190px;"
            >

                <option
                    value="all"
                    <?php echo $status === 'all' ? 'selected' : ''; ?>
                >
                    All Classes
                </option>

                <option
                    value="assigned"
                    <?php echo $status === 'assigned' ? 'selected' : ''; ?>
                >
                    Assigned
                </option>

                <option
                    value="unassigned"
                    <?php echo $status === 'unassigned' ? 'selected' : ''; ?>
                >
                    Unassigned
                </option>

                <option
                    value="full"
                    <?php echo $status === 'full' ? 'selected' : ''; ?>
                >
                    Full Classes
                </option>

            </select>

            <button type="submit" class="btn-filter">

                <i class="fa-solid fa-filter me-1"></i>
                Filter

            </button>

            <?php if ($search !== '' || $status !== 'all'): ?>

                <a
                    href="class_monitoring.php"
                    class="btn btn-outline-secondary btn-sm d-flex align-items-center"
                >
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
                            <th>INSTRUCTOR(S)</th>
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
                            $capacity = (int)$class['max_students'];

                            if ($capacity <= 0) {
                                $capacity = 50;
                            }

                            $percentage = min(
                                100,
                                ($enrolled / $capacity) * 100
                            );

                            $isUnassigned = !$class['has_instructor'];
                            $isFull = ($enrolled >= $capacity);

                            $primaryInstructor = trim(
                                (string)($class['instructor_name'] ?? '')
                            );

                            $hasPrimaryInstructor =
                                !empty($class['teacher_id']) &&
                                $primaryInstructor !== '';

                            $coInstructorNames = [];

                            if (!empty($class['co_instructor_names'])) {

                                $coInstructorNames = explode(
                                    '|||',
                                    $class['co_instructor_names']
                                );

                                $coInstructorNames = array_values(
                                    array_unique(
                                        array_filter(
                                            array_map('trim', $coInstructorNames)
                                        )
                                    )
                                );

                            }
                            ?>

                            <tr>

                                <!-- CLASS INFORMATION -->

                                <td>

                                    <div class="class-name">
                                        <?php echo e($class['class_name']); ?>
                                    </div>

                                    <div class="class-program">
                                        <?php
                                        echo e(
                                            $class['program']
                                            ?: 'No program specified'
                                        );
                                        ?>
                                    </div>

                                    <small class="text-muted">
                                        Code:
                                        <?php echo e($class['class_code']); ?>
                                    </small>

                                </td>

                                <!-- SECTION -->

                                <td>
                                    <?php echo e($class['section'] ?: '—'); ?>
                                </td>

                                <!-- ACADEMIC YEAR -->

                                <td>
                                    <?php echo e($class['academic_year'] ?: '—'); ?>
                                </td>

                                <!-- INSTRUCTORS -->

                                <td>

                                    <?php if (!$isUnassigned): ?>

                                        <div class="instructor-list">

                                            <?php if ($hasPrimaryInstructor): ?>

                                                <div class="instructor-item">

                                                    <i class="fa-solid fa-user-tie"></i>

                                                    <div class="instructor-name">

                                                        <strong>
                                                            <?php echo e($primaryInstructor); ?>
                                                        </strong>

                                                        <br>

                                                        <span class="instructor-role primary-role">
                                                            Primary Instructor
                                                        </span>

                                                    </div>

                                                </div>

                                            <?php endif; ?>

                                            <?php foreach ($coInstructorNames as $coName): ?>

                                                <div class="instructor-item">

                                                    <i class="fa-solid fa-user"></i>

                                                    <div class="instructor-name">

                                                        <?php echo e($coName); ?>

                                                        <br>

                                                        <span class="instructor-role">
                                                            Co-Instructor
                                                        </span>

                                                    </div>

                                                </div>

                                            <?php endforeach; ?>

                                            <?php if (!$hasPrimaryInstructor && count($coInstructorNames) === 0): ?>

                                                <span class="text-muted">
                                                    Instructor assigned
                                                </span>

                                            <?php endif; ?>

                                        </div>

                                    <?php else: ?>

                                        <span class="no-instructor">

                                            <i class="fa-solid fa-circle-exclamation me-1"></i>

                                            No instructor assigned

                                        </span>

                                    <?php endif; ?>

                                </td>

                                <!-- ENROLLMENT -->

                                <td style="min-width: 145px;">

                                    <div class="capacity-text">

                                        <strong>
                                            <?php echo number_format($enrolled); ?>
                                        </strong>

                                        /
                                        <?php echo number_format($capacity); ?>
                                        students

                                    </div>

                                    <div class="progress">

                                        <div
                                            class="progress-bar <?php echo $isFull ? 'bg-danger' : 'bg-primary'; ?>"
                                            role="progressbar"
                                            style="width: <?php echo $percentage; ?>%;"
                                            aria-valuenow="<?php echo $percentage; ?>"
                                            aria-valuemin="0"
                                            aria-valuemax="100"
                                        ></div>

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
                                    <?php echo e($class['schedule'] ?: '—'); ?>
                                </td>

                                <!-- ROOM -->

                                <td>
                                    <?php echo e($class['room'] ?: '—'); ?>
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
