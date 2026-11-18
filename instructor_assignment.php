
<?php
session_start();
include 'database.php';

/* =========================================
   DIRECTOR ACCESS CHECK
========================================= */
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (($_SESSION['role'] ?? '') !== 'director') {
    header("Location: login.php");
    exit();
}

$directorName = $director['name'] ?? 'Director';

/* =========================================
   HELPER FUNCTION
========================================= */
function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/* =========================================
   CSRF TOKEN
========================================= */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* =========================================
   FLASH MESSAGE
========================================= */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* =========================================
   HANDLE INSTRUCTOR ASSIGNMENT
========================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $_SESSION['flash'] = [
            'type' => 'danger',
            'message' => 'Invalid request. Please try again.'
        ];

        header("Location: instructor_assignment.php");
        exit();
    }

    $action = $_POST['action'] ?? '';
    $class_id = filter_var($_POST['class_id'] ?? null, FILTER_VALIDATE_INT);

    if (!$class_id || $class_id <= 0) {
        $_SESSION['flash'] = [
            'type' => 'danger',
            'message' => 'Invalid class selected.'
        ];

        header("Location: instructor_assignment.php");
        exit();
    }

    /* CHECK IF CLASS EXISTS */
    $classCheck = mysqli_prepare(
        $conn,
        "SELECT id FROM classes WHERE id = ?"
    );

    if (!$classCheck) {
        die("Database error: " . e(mysqli_error($conn)));
    }

    mysqli_stmt_bind_param($classCheck, "i", $class_id);
    mysqli_stmt_execute($classCheck);

    $classResult = mysqli_stmt_get_result($classCheck);
    $classExists = mysqli_fetch_assoc($classResult);

    mysqli_stmt_close($classCheck);

    if (!$classExists) {
        $_SESSION['flash'] = [
            'type' => 'danger',
            'message' => 'The selected class does not exist.'
        ];

        header("Location: instructor_assignment.php");
        exit();
    }

    /* =====================================
       ASSIGN OR CHANGE INSTRUCTOR
    ===================================== */
    if ($action === 'assign') {

        $teacher_id = filter_var(
            $_POST['teacher_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        if (!$teacher_id || $teacher_id <= 0) {
            $_SESSION['flash'] = [
                'type' => 'danger',
                'message' => 'Please select a valid instructor.'
            ];

            header("Location: instructor_assignment.php");
            exit();
        }

        /* VERIFY THAT SELECTED USER IS A TEACHER */
        $teacherCheck = mysqli_prepare(
            $conn,
            "SELECT id FROM users WHERE id = ? AND role = 'teacher'"
        );

        if (!$teacherCheck) {
            die("Database error: " . e(mysqli_error($conn)));
        }

        mysqli_stmt_bind_param($teacherCheck, "i", $teacher_id);
        mysqli_stmt_execute($teacherCheck);

        $teacherResult = mysqli_stmt_get_result($teacherCheck);
        $teacherExists = mysqli_fetch_assoc($teacherResult);

        mysqli_stmt_close($teacherCheck);

        if (!$teacherExists) {
            $_SESSION['flash'] = [
                'type' => 'danger',
                'message' => 'The selected account is not a valid instructor.'
            ];

            header("Location: instructor_assignment.php");
            exit();
        }

        /* UPDATE CLASS INSTRUCTOR */
        $updateStmt = mysqli_prepare(
            $conn,
            "UPDATE classes SET teacher_id = ? WHERE id = ?"
        );

        if (!$updateStmt) {
            die("Database error: " . e(mysqli_error($conn)));
        }

        mysqli_stmt_bind_param(
            $updateStmt,
            "ii",
            $teacher_id,
            $class_id
        );

        if (mysqli_stmt_execute($updateStmt)) {
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => 'Instructor successfully assigned to the class.'
            ];
        } else {
            $_SESSION['flash'] = [
                'type' => 'danger',
                'message' => 'Unable to assign instructor. Please try again.'
            ];
        }

        mysqli_stmt_close($updateStmt);

    }

    /* =====================================
       REMOVE INSTRUCTOR
    ===================================== */
    elseif ($action === 'unassign') {

        $updateStmt = mysqli_prepare(
            $conn,
            "UPDATE classes SET teacher_id = NULL WHERE id = ?"
        );

        if (!$updateStmt) {
            die("Database error: " . e(mysqli_error($conn)));
        }

        mysqli_stmt_bind_param($updateStmt, "i", $class_id);

        if (mysqli_stmt_execute($updateStmt)) {
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => 'Instructor assignment successfully removed.'
            ];
        } else {
            $_SESSION['flash'] = [
                'type' => 'danger',
                'message' => 'Unable to remove instructor assignment.'
            ];
        }

        mysqli_stmt_close($updateStmt);

    } else {

        $_SESSION['flash'] = [
            'type' => 'danger',
            'message' => 'Invalid action.'
        ];
    }

    header("Location: instructor_assignment.php");
    exit();
}

/* =========================================
   SEARCH
========================================= */
$search = trim($_GET['search'] ?? '');

/* =========================================
   GET INSTRUCTORS
========================================= */
$teachers = [];

$teacherQuery = mysqli_query(
    $conn,
    "SELECT id, name, email
     FROM users
     WHERE role = 'teacher'
     ORDER BY name ASC"
);

if (!$teacherQuery) {
    die("Database error: " . e(mysqli_error($conn)));
}

while ($teacher = mysqli_fetch_assoc($teacherQuery)) {
    $teachers[] = $teacher;
}

/* =========================================
   GET CLASS STATISTICS
========================================= */
$statsQuery = mysqli_query(
    $conn,
    "SELECT
        COUNT(*) AS total_classes,
        SUM(CASE WHEN teacher_id IS NULL THEN 1 ELSE 0 END) AS unassigned_classes,
        SUM(CASE WHEN teacher_id IS NOT NULL THEN 1 ELSE 0 END) AS assigned_classes
     FROM classes"
);

if (!$statsQuery) {
    die("Database error: " . e(mysqli_error($conn)));
}

$stats = mysqli_fetch_assoc($statsQuery);

$totalClasses = (int)($stats['total_classes'] ?? 0);
$unassignedClasses = (int)($stats['unassigned_classes'] ?? 0);
$assignedClasses = (int)($stats['assigned_classes'] ?? 0);

/* =========================================
   GET CLASSES
========================================= */
$sql = "
    SELECT
        classes.*,
        users.name AS teacher_name,
        users.email AS teacher_email
    FROM classes
    LEFT JOIN users
        ON classes.teacher_id = users.id
        AND users.role = 'teacher'
";

if ($search !== '') {

    $sql .= "
        WHERE classes.class_name LIKE ?
        OR classes.program LIKE ?
        OR classes.section LIKE ?
        OR users.name LIKE ?
    ";
}

$sql .= "
    ORDER BY
        CASE WHEN classes.teacher_id IS NULL THEN 0 ELSE 1 END ASC,
        classes.class_name ASC
";

$classStmt = mysqli_prepare($conn, $sql);

if (!$classStmt) {
    die("Database error: " . e(mysqli_error($conn)));
}

if ($search !== '') {

    $searchTerm = "%{$search}%";

    mysqli_stmt_bind_param(
        $classStmt,
        "ssss",
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm
    );
}

mysqli_stmt_execute($classStmt);

$classResult = mysqli_stmt_get_result($classStmt);

$classes = [];

while ($class = mysqli_fetch_assoc($classResult)) {
    $classes[] = $class;
}

mysqli_stmt_close($classStmt);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Instructor Assignment | PLMUN Classroom</title>

    <link rel="stylesheet" href="instructor_assignment.css">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    
          
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
            <a href="instructor_assignment.php" class="active"">
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

        <div class="title-section">
            <h2>Instructor Assignment</h2>
            <p>Assign instructors to classes and manage teaching responsibilities.</p>
        </div>

    </div>

    <!-- FLASH MESSAGE -->
    <?php if ($flash): ?>

        <div class="alert alert-<?php echo e($flash['type']); ?> alert-dismissible fade show">

            <i class="fa-solid fa-circle-info me-2"></i>

            <?php echo e($flash['message']); ?>

            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>

        </div>

    <?php endif; ?>

    <!-- =========================================
         STATISTICS
    ========================================= -->
    <div class="stats-grid">

        <div class="stat-card">

            <div class="stat-icon blue">
                <i class="fa-solid fa-chalkboard"></i>
            </div>

            <div>
                <h3><?php echo $totalClasses; ?></h3>
                <p>Total Classes</p>
            </div>

        </div>

        <div class="stat-card">

            <div class="stat-icon green">
                <i class="fa-solid fa-user-check"></i>
            </div>

            <div>
                <h3><?php echo $assignedClasses; ?></h3>
                <p>Assigned Classes</p>
            </div>

        </div>

        <div class="stat-card">

            <div class="stat-icon orange">
                <i class="fa-solid fa-user-clock"></i>
            </div>

            <div>
                <h3><?php echo $unassignedClasses; ?></h3>
                <p>Unassigned Classes</p>
            </div>

        </div>

    </div>

    <!-- =========================================
         CLASS ASSIGNMENT TABLE
    ========================================= -->
    <div class="content-card">

        <div class="content-card-header">

            <div>
                <h5>Class Instructor List</h5>
                <p>Assign or update the instructor responsible for each class.</p>
            </div>

            <form method="GET" action="instructor_assignment.php">

                <div class="search-box">

                    <i class="fa-solid fa-magnifying-glass"></i>

                    <input
                        type="text"
                        name="search"
                        placeholder="Search class, program, instructor..."
                        value="<?php echo e($search); ?>"
                    >

                </div>

            </form>

        </div>

        <div class="table-responsive">

            <table class="assignment-table">

                <thead>
                    <tr>
                        <th>Class Information</th>
                        <th>Program</th>
                        <th>Section</th>
                        <th>Instructor</th>
                        <th>Status</th>
                        <th>Assign Instructor</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (count($classes) > 0): ?>

                        <?php foreach ($classes as $class): ?>

                            <?php
                                $isAssigned = !empty($class['teacher_id']);
                            ?>

                            <tr>

                                <!-- CLASS INFORMATION -->
                                <td>

                                    <div class="class-name">
                                        <?php echo e($class['class_name']); ?>
                                    </div>

                                    <div class="class-sub">
                                        Code: <?php echo e($class['class_code']); ?>
                                    </div>

                                </td>

                                <!-- PROGRAM -->
                                <td>
                                    <div class="program-text">
                                        <?php echo e($class['program'] ?: 'Not specified'); ?>
                                    </div>
                                </td>

                                <!-- SECTION -->
                                <td>
                                    <?php echo e($class['section'] ?: 'N/A'); ?>
                                </td>

                                <!-- CURRENT INSTRUCTOR -->
                                <td>

                                    <?php if ($isAssigned && !empty($class['teacher_name'])): ?>

                                        <div class="instructor-info">

                                            <div class="instructor-avatar">
                                                <i class="fa-solid fa-user"></i>
                                            </div>

                                            <div>
                                                <strong>
                                                    <?php echo e($class['teacher_name']); ?>
                                                </strong>

                                                <small>
                                                    <?php echo e($class['teacher_email']); ?>
                                                </small>
                                            </div>

                                        </div>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            No instructor assigned
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <!-- STATUS -->
                                <td>

                                    <?php if ($isAssigned && !empty($class['teacher_name'])): ?>

                                        <span class="status-badge status-assigned">
                                            <i class="fa-solid fa-circle-check"></i>
                                            Assigned
                                        </span>

                                    <?php else: ?>

                                        <span class="status-badge status-unassigned">
                                            <i class="fa-solid fa-clock"></i>
                                            Unassigned
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <!-- ASSIGNMENT FORM -->
                                <td>

                                    <?php if (count($teachers) > 0): ?>

                                        <form
                                            method="POST"
                                            action="instructor_assignment.php"
                                            class="assign-form"
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?php echo e($_SESSION['csrf_token']); ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="class_id"
                                                value="<?php echo (int)$class['id']; ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="assign"
                                            >

                                            <select name="teacher_id" required>

                                                <option value="">
                                                    Select instructor
                                                </option>

                                                <?php foreach ($teachers as $teacher): ?>

                                                    <option
                                                        value="<?php echo (int)$teacher['id']; ?>"
                                                        <?php
                                                        echo (
                                                            (int)($class['teacher_id'] ?? 0)
                                                            === (int)$teacher['id']
                                                        ) ? 'selected' : '';
                                                        ?>
                                                    >
                                                        <?php echo e($teacher['name']); ?>
                                                    </option>

                                                <?php endforeach; ?>

                                            </select>

                                            <button type="submit" class="btn-assign">

                                                <i class="fa-solid fa-check me-1"></i>

                                                <?php echo $isAssigned ? 'Update' : 'Assign'; ?>

                                            </button>

                                        </form>

                                        <?php if ($isAssigned): ?>

                                            <form
                                                method="POST"
                                                action="instructor_assignment.php"
                                                onsubmit="return confirm('Remove this instructor from the class?');"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?php echo e($_SESSION['csrf_token']); ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="class_id"
                                                    value="<?php echo (int)$class['id']; ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="unassign"
                                                >

                                                <button type="submit" class="btn-remove">
                                                    <i class="fa-solid fa-user-minus me-1"></i>
                                                    Remove Instructor
                                                </button>

                                            </form>

                                        <?php endif; ?>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            No teacher accounts available.
                                        </span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="6">

                                <div class="empty-state">

                                    <i class="fa-regular fa-folder-open"></i>

                                    <h5>No classes found</h5>

                                    <p>
                                        <?php if ($search !== ''): ?>
                                            No classes matched your search.
                                        <?php else: ?>
                                            Create a class first in Class Management.
                                        <?php endif; ?>
                                    </p>

                                </div>

                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('collapsed');
    document.getElementById('mainContent').classList.toggle('expanded');
}

function mobileSidebar() {
    document.getElementById('sidebar').classList.toggle('show');
    document.getElementById('sidebarOverlay').classList.toggle('show');
}
</script>

</body>
</html>
