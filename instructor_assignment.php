
<?php
session_start();
require_once 'database.php';

/* =========================================
   DIRECTOR ACCESS CHECK
========================================= */
if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'director'
) {
    header("Location: login.php");
    exit();
}

/* =========================================
   HELPER FUNCTION
========================================= */
function e($value) {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

/* =========================================
   GET DIRECTOR INFORMATION
========================================= */
$directorName = 'Director';

$directorStmt = mysqli_prepare(
    $conn,
    "SELECT name FROM users WHERE id = ? LIMIT 1"
);

if ($directorStmt) {
    mysqli_stmt_bind_param(
        $directorStmt,
        "i",
        $_SESSION['user_id']
    );

    mysqli_stmt_execute($directorStmt);

    $directorResult = mysqli_stmt_get_result($directorStmt);
    $director = mysqli_fetch_assoc($directorResult);

    if ($director && !empty($director['name'])) {
        $directorName = $director['name'];
    }

    mysqli_stmt_close($directorStmt);
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
   REDIRECT WITH MESSAGE
========================================= */
function redirectWithMessage($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];

    header("Location: instructor_assignment.php");
    exit();
}

/* =========================================
   HANDLE POST REQUESTS
========================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* VERIFY CSRF */
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        redirectWithMessage(
            'danger',
            'Invalid request. Please refresh the page and try again.'
        );
    }

    $action = $_POST['action'] ?? '';

    $class_id = filter_var(
        $_POST['class_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (!$class_id || $class_id <= 0) {
        redirectWithMessage(
            'danger',
            'Invalid class selected.'
        );
    }

    /* =====================================
       VERIFY CLASS
    ===================================== */
    $classCheck = mysqli_prepare(
        $conn,
        "SELECT id FROM classes WHERE id = ? LIMIT 1"
    );

    if (!$classCheck) {
        redirectWithMessage(
            'danger',
            'Unable to verify the selected class.'
        );
    }

    mysqli_stmt_bind_param(
        $classCheck,
        "i",
        $class_id
    );

    mysqli_stmt_execute($classCheck);

    $classResult = mysqli_stmt_get_result($classCheck);
    $classExists = mysqli_fetch_assoc($classResult);

    mysqli_stmt_close($classCheck);

    if (!$classExists) {
        redirectWithMessage(
            'danger',
            'The selected class does not exist.'
        );
    }

    /* =====================================
       ASSIGN MULTIPLE INSTRUCTORS
    ===================================== */
    if ($action === 'assign') {

        $teacherIds = $_POST['teacher_ids'] ?? [];

        if (!is_array($teacherIds)) {
            redirectWithMessage(
                'danger',
                'Invalid instructor selection.'
            );
        }

        /* NORMALIZE SELECTED IDS */
        $teacherIds = array_values(
            array_unique(
                array_filter(
                    array_map('intval', $teacherIds),
                    function ($id) {
                        return $id > 0;
                    }
                )
            )
        );

        if (count($teacherIds) === 0) {
            redirectWithMessage(
                'warning',
                'Please check at least one instructor before saving.'
            );
        }

        /* =================================
           VERIFY SELECTED TEACHERS
        ================================= */
        $teacherResult = mysqli_query(
            $conn,
            "SELECT id FROM users WHERE role = 'teacher'"
        );

        if (!$teacherResult) {
            redirectWithMessage(
                'danger',
                'Unable to verify instructor accounts.'
            );
        }

        $validTeacherIds = [];

        while ($teacher = mysqli_fetch_assoc($teacherResult)) {
            $validTeacherIds[] = (int)$teacher['id'];
        }

        foreach ($teacherIds as $teacherId) {

            if (!in_array($teacherId, $validTeacherIds, true)) {
                redirectWithMessage(
                    'danger',
                    'One or more selected accounts are not valid instructors.'
                );
            }
        }

        /* =================================
           SAVE USING TRANSACTION
        ================================= */
        mysqli_begin_transaction($conn);

        try {

            /* REMOVE OLD ASSIGNMENTS */
            $deleteStmt = mysqli_prepare(
                $conn,
                "DELETE FROM class_instructors WHERE class_id = ?"
            );

            if (!$deleteStmt) {
                throw new Exception(
                    "Unable to prepare assignment update."
                );
            }

            mysqli_stmt_bind_param(
                $deleteStmt,
                "i",
                $class_id
            );

            if (!mysqli_stmt_execute($deleteStmt)) {
                throw new Exception(
                    "Unable to remove previous assignments."
                );
            }

            mysqli_stmt_close($deleteStmt);

            /* INSERT SELECTED INSTRUCTORS */
            $insertStmt = mysqli_prepare(
                $conn,
                "INSERT INTO class_instructors
                    (class_id, teacher_id)
                 VALUES (?, ?)"
            );

            if (!$insertStmt) {
                throw new Exception(
                    "Unable to prepare instructor assignment."
                );
            }

            foreach ($teacherIds as $teacherId) {

                mysqli_stmt_bind_param(
                    $insertStmt,
                    "ii",
                    $class_id,
                    $teacherId
                );

                if (!mysqli_stmt_execute($insertStmt)) {
                    throw new Exception(
                        "Unable to save an instructor assignment."
                    );
                }
            }

            mysqli_stmt_close($insertStmt);

            /* =================================
               KEEP LEGACY PRIMARY TEACHER
            ================================= */
            $primaryTeacherId = $teacherIds[0];

            $updateClass = mysqli_prepare(
                $conn,
                "UPDATE classes
                 SET teacher_id = ?
                 WHERE id = ?"
            );

            if (!$updateClass) {
                throw new Exception(
                    "Unable to update the primary instructor."
                );
            }

            mysqli_stmt_bind_param(
                $updateClass,
                "ii",
                $primaryTeacherId,
                $class_id
            );

            if (!mysqli_stmt_execute($updateClass)) {
                throw new Exception(
                    "Unable to update the class instructor."
                );
            }

            mysqli_stmt_close($updateClass);

            mysqli_commit($conn);

            redirectWithMessage(
                'success',
                count($teacherIds) .
                ' instructor(s) successfully assigned to the class.'
            );

        } catch (Throwable $exception) {

            mysqli_rollback($conn);

            redirectWithMessage(
                'danger',
                'Unable to save instructor assignments. ' .
                $exception->getMessage()
            );
        }
    }

    /* =====================================
       REMOVE ALL INSTRUCTORS
    ===================================== */
    elseif ($action === 'unassign') {

        mysqli_begin_transaction($conn);

        try {

            $deleteStmt = mysqli_prepare(
                $conn,
                "DELETE FROM class_instructors WHERE class_id = ?"
            );

            if (!$deleteStmt) {
                throw new Exception(
                    "Unable to prepare instructor removal."
                );
            }

            mysqli_stmt_bind_param(
                $deleteStmt,
                "i",
                $class_id
            );

            if (!mysqli_stmt_execute($deleteStmt)) {
                throw new Exception(
                    "Unable to remove instructor assignments."
                );
            }

            mysqli_stmt_close($deleteStmt);

            /* CLEAR PRIMARY TEACHER */
            $updateStmt = mysqli_prepare(
                $conn,
                "UPDATE classes
                 SET teacher_id = NULL
                 WHERE id = ?"
            );

            if (!$updateStmt) {
                throw new Exception(
                    "Unable to prepare class update."
                );
            }

            mysqli_stmt_bind_param(
                $updateStmt,
                "i",
                $class_id
            );

            if (!mysqli_stmt_execute($updateStmt)) {
                throw new Exception(
                    "Unable to clear the primary instructor."
                );
            }

            mysqli_stmt_close($updateStmt);

            mysqli_commit($conn);

            redirectWithMessage(
                'success',
                'All instructor assignments have been removed from the class.'
            );

        } catch (Throwable $exception) {

            mysqli_rollback($conn);

            redirectWithMessage(
                'danger',
                'Unable to remove instructor assignments. ' .
                $exception->getMessage()
            );
        }

    } else {

        redirectWithMessage(
            'danger',
            'Invalid action.'
        );
    }
}

/* =========================================
   SEARCH
========================================= */
$search = trim($_GET['search'] ?? '');

/* =========================================
   GET ALL TEACHERS
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

        SUM(
            CASE
                WHEN NOT EXISTS (
                    SELECT 1
                    FROM class_instructors ci
                    WHERE ci.class_id = classes.id
                )
                THEN 1 ELSE 0
            END
        ) AS unassigned_classes,

        SUM(
            CASE
                WHEN EXISTS (
                    SELECT 1
                    FROM class_instructors ci
                    WHERE ci.class_id = classes.id
                )
                THEN 1 ELSE 0
            END
        ) AS assigned_classes

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
    SELECT c.*
    FROM classes c
";

if ($search !== '') {

    $sql .= "
        WHERE
            c.class_name LIKE ?
            OR c.program LIKE ?
            OR c.section LIKE ?
            OR EXISTS (
                SELECT 1
                FROM class_instructors ci
                INNER JOIN users u
                    ON u.id = ci.teacher_id
                WHERE ci.class_id = c.id
                AND (
                    u.name LIKE ?
                    OR u.email LIKE ?
                )
            )
    ";
}

$sql .= "
    ORDER BY
        CASE
            WHEN EXISTS (
                SELECT 1
                FROM class_instructors ci
                WHERE ci.class_id = c.id
            )
            THEN 1 ELSE 0
        END ASC,
        c.class_name ASC
";

$classStmt = mysqli_prepare($conn, $sql);

if (!$classStmt) {
    die("Database error: " . e(mysqli_error($conn)));
}

if ($search !== '') {

    $searchTerm = "%{$search}%";

    mysqli_stmt_bind_param(
        $classStmt,
        "sssss",
        $searchTerm,
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

    $class['instructors'] = [];
    $class['instructor_ids'] = [];

    $classes[(int)$class['id']] = $class;
}

mysqli_stmt_close($classStmt);

/* =========================================
   GET ALL ASSIGNED INSTRUCTORS
========================================= */
if (!empty($classes)) {

    $classIds = array_keys($classes);

    $placeholders = implode(
        ',',
        array_fill(0, count($classIds), '?')
    );

    $types = str_repeat('i', count($classIds));

    $instructorSql = "
        SELECT
            ci.class_id,
            ci.teacher_id,
            u.name,
            u.email
        FROM class_instructors ci
        INNER JOIN users u
            ON u.id = ci.teacher_id
        WHERE ci.class_id IN ($placeholders)
        AND u.role = 'teacher'
        ORDER BY u.name ASC
    ";

    $instructorStmt = mysqli_prepare(
        $conn,
        $instructorSql
    );

    if (!$instructorStmt) {
        die("Database error: " . e(mysqli_error($conn)));
    }

    mysqli_stmt_bind_param(
        $instructorStmt,
        $types,
        ...$classIds
    );

    mysqli_stmt_execute($instructorStmt);

    $instructorResult = mysqli_stmt_get_result($instructorStmt);

    while ($instructor = mysqli_fetch_assoc($instructorResult)) {

        $classId = (int)$instructor['class_id'];

        if (isset($classes[$classId])) {

            $classes[$classId]['instructors'][] = [
                'id' => (int)$instructor['teacher_id'],
                'name' => $instructor['name'],
                'email' => $instructor['email']
            ];

            $classes[$classId]['instructor_ids'][] =
                (int)$instructor['teacher_id'];
        }
    }

    mysqli_stmt_close($instructorStmt);
}

$classes = array_values($classes);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Instructor Assignment | PLMUN Classroom</title>

    <link rel="stylesheet" href="instructor_assignment.css">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <link rel="preconnect" href="https://fonts.googleapis.com">

    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <style>
        /* MULTIPLE INSTRUCTOR CHECKBOXES */

        .instructor-checkbox-list {
            width: 100%;
            max-height: 190px;
            overflow-y: auto;
            border: 1px solid #e0e6ed;
            border-radius: 9px;
            padding: 7px;
            background: #fff;
        }

        .instructor-checkbox-item {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 9px;
            border-radius: 7px;
            cursor: pointer;
            transition: .2s;
            margin-bottom: 3px;
        }

        .instructor-checkbox-item:hover {
            background: #eef6fb;
        }

        .instructor-checkbox-item input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: #3f87b5;
            cursor: pointer;
            flex-shrink: 0;
        }

        .checkbox-instructor-avatar {
            width: 32px;
            height: 32px;
            min-width: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: #eaf3fa;
            color: #3f87b5;
            font-size: 11px;
        }

        .checkbox-instructor-details {
            min-width: 0;
            display: flex;
            flex-direction: column;
        }

        .checkbox-instructor-details strong {
            font-size: 11px;
            color: #334155;
        }

        .checkbox-instructor-details small {
            font-size: 9px;
            color: #929cab;
            overflow-wrap: anywhere;
        }

        .selection-help {
            display: block;
            color: #929cab;
            font-size: 10px;
            line-height: 1.5;
            margin-top: 5px;
        }

        .assign-form {
            min-width: 210px;
        }
    </style>

</head>

<body>

<!-- =========================================
     SIDEBAR
========================================= -->

<div class="sidebar" id="sidebar">

    <div class="sidebar-header">

        <button
            class="toggle-btn"
            type="button"
            onclick="toggleSidebar()"
            aria-label="Toggle sidebar"
        >
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
            <a href="instructor_assignment.php" class="active">
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

<!-- =========================================
     MAIN CONTENT
========================================= -->

<div class="main-content" id="mainContent">

    <!-- TOPBAR -->

    <div class="topbar">

        <div class="topbar-logo d-flex align-items-center gap-3">

            <button
                class="mobile-menu"
                type="button"
                onclick="mobileSidebar()"
                aria-label="Open menu"
            >
                <i class="fa-solid fa-bars"></i>
            </button>

            <img
                src="img/plmun logo.png"
                alt="PLMUN Logo"
            >

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

            <p>
                Assign multiple instructors to classes and manage teaching responsibilities.
            </p>

        </div>

    </div>

    <!-- FLASH MESSAGE -->

    <?php if ($flash): ?>

        <div class="alert alert-<?php echo e($flash['type']); ?> alert-dismissible fade show">

            <i class="fa-solid fa-circle-info me-2"></i>

            <?php echo e($flash['message']); ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>

    <!-- STATISTICS -->

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

    <!-- CLASS ASSIGNMENT TABLE -->

    <div class="content-card">

        <div class="content-card-header">

            <div>

                <h5>Class Instructor List</h5>

                <p>
                    Select one or more instructors for each class.
                </p>

            </div>

            <form
                method="GET"
                action="instructor_assignment.php"
            >

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
                        <th>Assigned Instructors</th>
                        <th>Status</th>
                        <th>Assign Instructors</th>
                    </tr>

                </thead>

                <tbody>

                    <?php if (count($classes) > 0): ?>

                        <?php foreach ($classes as $class): ?>

                            <?php
                                $assignedInstructors = $class['instructors'];
                                $isAssigned = count($assignedInstructors) > 0;
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

                                <!-- ASSIGNED INSTRUCTORS -->

                                <td>

                                    <?php if ($isAssigned): ?>

                                        <div class="instructor-list">

                                            <?php foreach ($assignedInstructors as $instructor): ?>

                                                <div class="instructor-info">

                                                    <div class="instructor-avatar">
                                                        <i class="fa-solid fa-user"></i>
                                                    </div>

                                                    <div>

                                                        <strong>
                                                            <?php echo e($instructor['name']); ?>
                                                        </strong>

                                                        <small>
                                                            <?php echo e($instructor['email']); ?>
                                                        </small>

                                                    </div>

                                                </div>

                                            <?php endforeach; ?>

                                        </div>

                                    <?php else: ?>

                                        <span class="no-instructor">
                                            <i class="fa-solid fa-user-slash"></i>
                                            No instructor assigned
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <!-- STATUS -->

                                <td>

                                    <?php if ($isAssigned): ?>

                                        <span class="status-badge status-assigned">

                                            <i class="fa-solid fa-circle-check"></i>

                                            Assigned
                                            (<?php echo count($assignedInstructors); ?>)

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

                                            <!-- MULTIPLE INSTRUCTOR CHECKBOXES -->

                                            
                                            <div class="instructor-search-box">
                                                <i class="fa-solid fa-magnifying-glass"></i>
                                                <input
                                                    type="text"
                                                    class="instructor-search-input"
                                                    placeholder="Search instructor name or email..."
                                                    oninput="searchInstructors(this)"
                                                    autocomplete="off"
                                                >
                                            </div>

                                            <div class="instructor-checkbox-list">

                                                <?php foreach ($teachers as $teacher): ?>

                                                    <label
                                                        class="instructor-checkbox-item"
                                                        data-search="<?php echo e(strtolower($teacher['name'] . ' ' . $teacher['email'])); ?>"
                                                    >

                                                        <input
                                                            type="checkbox"
                                                            name="teacher_ids[]"
                                                            value="<?php echo (int)$teacher['id']; ?>"
                                                            <?php
                                                            echo in_array(
                                                                (int)$teacher['id'],
                                                                $class['instructor_ids'],
                                                                true
                                                            ) ? 'checked' : '';
                                                            ?>
                                                        >

                                                        <span class="checkbox-instructor-avatar">
                                                            <i class="fa-solid fa-user"></i>
                                                        </span>

                                                        <span class="checkbox-instructor-details">
                                                            <strong><?php echo e($teacher['name']); ?></strong>
                                                            <small><?php echo e($teacher['email']); ?></small>
                                                        </span>

                                                    </label>

                                                <?php endforeach; ?>

                                                <div class="instructor-no-results" style="display:none;">
                                                    <i class="fa-solid fa-user-slash"></i>
                                                    No instructor found.
                                                </div>

                                            </div>

                                            <small class="selection-help">
                                                Check all instructors you want to assign to this class.
                                            </small>


                                            <small class="selection-help">
                                                Check all instructors you want to assign to this class.
                                            </small>

                                            <button
                                                type="submit"
                                                class="btn-assign"
                                            >

                                                <i class="fa-solid fa-check me-1"></i>

                                                Save Instructors

                                            </button>

                                        </form>

                                        <!-- REMOVE ALL INSTRUCTORS -->

                                        <?php if ($isAssigned): ?>

                                            <form
                                                method="POST"
                                                action="instructor_assignment.php"
                                                onsubmit="return confirm('Are you sure you want to remove ALL instructors from this class?');"
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

                                                <button
                                                    type="submit"
                                                    class="btn-remove"
                                                >

                                                    <i class="fa-solid fa-user-minus me-1"></i>

                                                    Remove All Instructors

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

<!-- JAVASCRIPT -->

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


function searchInstructors(input) {

    const searchValue = input.value.toLowerCase().trim();

    // Get the checkbox list belonging to this search field
    const form = input.closest('form');

    if (!form) return;

    const instructorItems = form.querySelectorAll(
        '.instructor-checkbox-item'
    );

    const noResults = form.querySelector(
        '.instructor-no-results'
    );

    let visibleCount = 0;

    instructorItems.forEach(function(item) {

        const instructorInfo = (
            item.getAttribute('data-search') || ''
        ).toLowerCase();

        const matches = instructorInfo.includes(searchValue);

        item.style.display = matches ? 'flex' : 'none';

        if (matches) {
            visibleCount++;
        }

    });

    if (noResults) {
        noResults.style.display =
            visibleCount === 0 ? 'block' : 'none';
    }
}

</script>

</body>
</html>
