
<?php
session_start();
include 'database.php';
require_once __DIR__ . '/activity_log_helper.php';

/* =========================================================
   ADMIN ACCESS CONTROL
========================================================= */
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.html");
    exit();
}

$admin_id = (int) $_SESSION['user_id'];

$allowedRoles = ['student', 'teacher', 'program_chair', 'director', 'admin'];

$roleLabels = [
    'student' => 'Student',
    'teacher' => 'Teacher',
    'program_chair' => 'Program Chair',
    'director' => 'Director',
    'admin' => 'Admin'
];

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function redirectUsers($message = '') {
    if ($message !== '') {
        $_SESSION['user_message'] = $message;
    }
    header("Location: admin_users.php");
    exit();
}

function validRole($role, $allowedRoles) {
    return in_array($role, $allowedRoles, true);
}

function getCount($conn, $sql) {
    $result = mysqli_query($conn, $sql);
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        return (int)($row['total'] ?? 0);
    }
    return 0;
}

/* =========================================================
   GET ADMIN
========================================================= */
$stmt = mysqli_prepare(
    $conn,
    "SELECT * FROM users WHERE id = ? LIMIT 1"
);

mysqli_stmt_bind_param($stmt, "i", $admin_id);
mysqli_stmt_execute($stmt);

$adminResult = mysqli_stmt_get_result($stmt);

if (!$adminResult || mysqli_num_rows($adminResult) !== 1) {
    session_destroy();
    header("Location: login.html");
    exit();
}

$admin = mysqli_fetch_assoc($adminResult);
mysqli_stmt_close($stmt);

/* =========================================================
   CSRF TOKEN
========================================================= */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

/* =========================================================
   LOAD PROGRAMS
   Requires:
   programs(id, college_name, college_code, program_name)
========================================================= */
$programsByCollege = [];
$programDetails = [];

$programResult = mysqli_query(
    $conn,
    "SELECT id, college_name, college_code, program_name
     FROM programs
     ORDER BY college_name ASC, program_name ASC"
);

if (!$programResult) {
    die("Unable to load programs. Please make sure the programs table has been created.");
}

while ($program = mysqli_fetch_assoc($programResult)) {

    $collegeKey = $program['college_name'];

    if (!isset($programsByCollege[$collegeKey])) {
        $programsByCollege[$collegeKey] = [
            'college_code' => $program['college_code'],
            'programs' => []
        ];
    }

    $programsByCollege[$collegeKey]['programs'][] = [
        'id' => (int)$program['id'],
        'name' => $program['program_name']
    ];

    $programDetails[(int)$program['id']] = [
        'college' => $program['college_name'],
        'name' => $program['program_name']
    ];
}

/* =========================================================
   ROLE PERMISSIONS
========================================================= */
$permissions = [
    'student' => [
        'view_courses',
        'submit_assignments',
        'view_grades',
        'join_classes'
    ],

    'teacher' => [
        'manage_courses',
        'grade_assignments',
        'create_assessments',
        'view_analytics'
    ],

    'program_chair' => [
        'view_program_classes',
        'monitor_teachers',
        'manage_program_classes',
        'view_program_analytics',
        'post_announcements',
        'create_assessments',
        'upload_materials',
        'join_meetings'
    ],

    'director' => [
        'view_all_classes',
        'monitor_all_programs',
        'manage_all_classes',
        'view_all_analytics',
        'manage_program_chairs',
        'post_announcements',
        'create_assessments',
        'upload_materials',
        'join_meetings'
    ],

    'admin' => [
        'user_management',
        'system_settings',
        'all_modules',
        'backup_security'
    ]
];

$permissionLabels = [
    'student' => [
        'view_courses' => 'View Courses',
        'submit_assignments' => 'Submit Assignments',
        'view_grades' => 'View Grades',
        'join_classes' => 'Join Classes'
    ],

    'teacher' => [
        'manage_courses' => 'Manage Courses',
        'grade_assignments' => 'Grade Assignments',
        'create_assessments' => 'Create Assessments',
        'view_analytics' => 'View Analytics'
    ],

    'program_chair' => [
        'view_program_classes' => 'View Program Classes',
        'monitor_teachers' => 'Monitor Teachers',
        'manage_program_classes' => 'Manage Program Classes',
        'view_program_analytics' => 'View Program Analytics',
        'post_announcements' => 'Post Announcements',
        'create_assessments' => 'Create Assessments',
        'upload_materials' => 'Upload Materials',
        'join_meetings' => 'Join Meetings'
    ],

    'director' => [
        'view_all_classes' => 'View All Classes',
        'monitor_all_programs' => 'Monitor All Programs',
        'manage_all_classes' => 'Manage All Classes',
        'view_all_analytics' => 'View All Analytics',
        'manage_program_chairs' => 'Manage Program Chairs',
        'post_announcements' => 'Post Announcements',
        'create_assessments' => 'Create Assessments',
        'upload_materials' => 'Upload Materials',
        'join_meetings' => 'Join Meetings'
    ],

    'admin' => [
        'user_management' => 'User Management',
        'system_settings' => 'System Settings',
        'all_modules' => 'All Modules',
        'backup_security' => 'Backup & Security'
    ]
];

/* =========================================================
   PROCESS POST ACTIONS
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        die("Invalid request. Please refresh the page and try again.");
    }

    $action = $_POST['action'] ?? '';

    /* =====================================================
       ADD USER
    ===================================================== */
    if ($action === 'add_user') {

        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'student';
        $program_id = (int)($_POST['program_id'] ?? 0);

        if ($name === '' || $email === '' || $password === '') {
            redirectUsers("Please complete all required fields.");
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            redirectUsers("Invalid email address.");
        }

        if (!validRole($role, $allowedRoles)) {
            redirectUsers("Invalid role.");
        }

        if (strlen($password) < 6) {
            redirectUsers("Password must be at least 6 characters.");
        }

        if (
            $role === 'program_chair' &&
            !isset($programDetails[$program_id])
        ) {
            redirectUsers("Please select a valid program for the Program Chair.");
        }

        $check = mysqli_prepare(
            $conn,
            "SELECT id FROM users WHERE email = ? LIMIT 1"
        );

        mysqli_stmt_bind_param($check, "s", $email);
        mysqli_stmt_execute($check);

        if (mysqli_num_rows(mysqli_stmt_get_result($check)) > 0) {
            mysqli_stmt_close($check);
            redirectUsers("Email is already registered.");
        }

        mysqli_stmt_close($check);

        mysqli_begin_transaction($conn);

        try {

            $hashedPassword = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $insertUser = mysqli_prepare(
                $conn,
                "INSERT INTO users
                    (name, email, password, role, profile_image)
                 VALUES (?, ?, ?, ?, 'default.png')"
            );

            mysqli_stmt_bind_param(
                $insertUser,
                "ssss",
                $name,
                $email,
                $hashedPassword,
                $role
            );

            if (!mysqli_stmt_execute($insertUser)) {
                throw new Exception("Unable to add user.");
            }

            $newUserId = (int)mysqli_insert_id($conn);
            mysqli_stmt_close($insertUser);

            if ($role === 'program_chair') {

                $assign = mysqli_prepare(
                    $conn,
                    "INSERT INTO program_chair_assignments
                        (user_id, program_id)
                     VALUES (?, ?)"
                );

                mysqli_stmt_bind_param(
                    $assign,
                    "ii",
                    $newUserId,
                    $program_id
                );

                if (!mysqli_stmt_execute($assign)) {
                    throw new Exception(
                        "Unable to save the Program Chair program assignment."
                    );
                }

                mysqli_stmt_close($assign);
            }

            mysqli_commit($conn);

            /* ACTIVITY LOG */
            $programDescription = '';

            if (
                $role === 'program_chair' &&
                isset($programDetails[$program_id])
            ) {
                $programName = $programDetails[$program_id]['name'];
                $collegeName = $programDetails[$program_id]['college'];

                $programDescription =
                    ' | Assigned Program: ' . $programName .
                    ' | College: ' . $collegeName;
            }

            addActivityLog(
                $conn,
                'Add User',
                'Created account for ' . $name .
                ' | Email: ' . $email .
                ' | Role: ' . ($roleLabels[$role] ?? $role) .
                $programDescription
            );

            redirectUsers("User added successfully.");

        } catch (Throwable $ex) {

            mysqli_rollback($conn);
            error_log("Add user failed: " . $ex->getMessage());

            redirectUsers($ex->getMessage());
        }
    }

    /* =====================================================
       EDIT USER
    ===================================================== */
    if ($action === 'edit_user') {

        $user_id = (int)($_POST['user_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'student';
        $password = $_POST['password'] ?? '';
        $program_id = (int)($_POST['program_id'] ?? 0);

        if ($user_id <= 0) {
            redirectUsers("Invalid user.");
        }

        if ($name === '' || $email === '') {
            redirectUsers("Name and email are required.");
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            redirectUsers("Invalid email address.");
        }

        if (!validRole($role, $allowedRoles)) {
            redirectUsers("Invalid role.");
        }

        if ($user_id === $admin_id && $role !== 'admin') {
            redirectUsers("You cannot remove your own admin role.");
        }

        if (
            $role === 'program_chair' &&
            !isset($programDetails[$program_id])
        ) {
            redirectUsers("Please select a valid program for the Program Chair.");
        }

        $check = mysqli_prepare(
            $conn,
            "SELECT id FROM users
             WHERE email = ? AND id != ?
             LIMIT 1"
        );

        mysqli_stmt_bind_param(
            $check,
            "si",
            $email,
            $user_id
        );

        mysqli_stmt_execute($check);

        if (mysqli_num_rows(mysqli_stmt_get_result($check)) > 0) {
            mysqli_stmt_close($check);
            redirectUsers("Email is already used by another user.");
        }

        mysqli_stmt_close($check);

        if ($password !== '' && strlen($password) < 6) {
            redirectUsers("Password must be at least 6 characters.");
        }

        mysqli_begin_transaction($conn);

        try {

            if ($password !== '') {

                $hashedPassword = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $update = mysqli_prepare(
                    $conn,
                    "UPDATE users
                     SET name = ?, email = ?, password = ?, role = ?
                     WHERE id = ?"
                );

                mysqli_stmt_bind_param(
                    $update,
                    "ssssi",
                    $name,
                    $email,
                    $hashedPassword,
                    $role,
                    $user_id
                );

            } else {

                $update = mysqli_prepare(
                    $conn,
                    "UPDATE users
                     SET name = ?, email = ?, role = ?
                     WHERE id = ?"
                );

                mysqli_stmt_bind_param(
                    $update,
                    "sssi",
                    $name,
                    $email,
                    $role,
                    $user_id
                );
            }

            if (!mysqli_stmt_execute($update)) {
                throw new Exception("Unable to update user.");
            }

            mysqli_stmt_close($update);

            /* Replace old assignment */
            $deleteAssignment = mysqli_prepare(
                $conn,
                "DELETE FROM program_chair_assignments
                 WHERE user_id = ?"
            );

            mysqli_stmt_bind_param(
                $deleteAssignment,
                "i",
                $user_id
            );

            if (!mysqli_stmt_execute($deleteAssignment)) {
                throw new Exception("Unable to update program assignment.");
            }

            mysqli_stmt_close($deleteAssignment);

            /* Assign new program if Program Chair */
            if ($role === 'program_chair') {

                $assign = mysqli_prepare(
                    $conn,
                    "INSERT INTO program_chair_assignments
                        (user_id, program_id)
                     VALUES (?, ?)"
                );

                mysqli_stmt_bind_param(
                    $assign,
                    "ii",
                    $user_id,
                    $program_id
                );

                if (!mysqli_stmt_execute($assign)) {
                    throw new Exception(
                        "Unable to save the Program Chair program assignment."
                    );
                }

                mysqli_stmt_close($assign);
            }

            mysqli_commit($conn);

            /* ACTIVITY LOG */
            $programDescription = '';

            if (
                $role === 'program_chair' &&
                isset($programDetails[$program_id])
            ) {
                $programName = $programDetails[$program_id]['name'];
                $collegeName = $programDetails[$program_id]['college'];

                $programDescription =
                    ' | Assigned Program: ' . $programName .
                    ' | College: ' . $collegeName;
            }

            addActivityLog(
                $conn,
                'Edit User',
                'Updated account: ' . $name .
                ' | Email: ' . $email .
                ' | Role: ' . ($roleLabels[$role] ?? $role) .
                $programDescription .
                ($password !== '' ? ' | Password was also changed.' : '')
            );

            redirectUsers("User updated successfully.");

        } catch (Throwable $ex) {

            mysqli_rollback($conn);
            error_log("Edit user failed: " . $ex->getMessage());

            redirectUsers($ex->getMessage());
        }
    }

    /* =====================================================
       CHANGE ROLE
       Program Chair assignment must be selected through Edit User
    ===================================================== */
    if ($action === 'change_role') {

        $user_id = (int)($_POST['user_id'] ?? 0);
        $role = $_POST['role'] ?? '';

        if ($user_id <= 0) {
            redirectUsers("Invalid user.");
        }

        if (!validRole($role, $allowedRoles)) {
            redirectUsers("Invalid role.");
        }

        if ($user_id === $admin_id && $role !== 'admin') {
            redirectUsers("You cannot remove your own admin role.");
        }

        if ($role === 'program_chair') {
            redirectUsers(
                "To assign the Program Chair role, use Edit User and select the program."
            );
        }

        /* GET CURRENT USER DETAILS */
        $getUser = mysqli_prepare(
            $conn,
            "SELECT name, role
             FROM users
             WHERE id = ?
             LIMIT 1"
        );

        mysqli_stmt_bind_param($getUser, "i", $user_id);
        mysqli_stmt_execute($getUser);

        $targetUser = mysqli_fetch_assoc(
            mysqli_stmt_get_result($getUser)
        );

        mysqli_stmt_close($getUser);

        if (!$targetUser) {
            redirectUsers("User not found.");
        }

        $oldRole = $targetUser['role'];
        $targetName = $targetUser['name'];

        mysqli_begin_transaction($conn);

        try {

            $stmt = mysqli_prepare(
                $conn,
                "UPDATE users SET role = ? WHERE id = ?"
            );

            mysqli_stmt_bind_param(
                $stmt,
                "si",
                $role,
                $user_id
            );

            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Unable to update role.");
            }

            mysqli_stmt_close($stmt);

            $deleteAssignment = mysqli_prepare(
                $conn,
                "DELETE FROM program_chair_assignments
                 WHERE user_id = ?"
            );

            mysqli_stmt_bind_param(
                $deleteAssignment,
                "i",
                $user_id
            );

            if (!mysqli_stmt_execute($deleteAssignment)) {
                throw new Exception("Unable to remove old program assignment.");
            }

            mysqli_stmt_close($deleteAssignment);

            mysqli_commit($conn);

            /* ACTIVITY LOG */
            addActivityLog(
                $conn,
                'Change User Role',
                'Changed role of ' . $targetName .
                ' from ' . ($roleLabels[$oldRole] ?? $oldRole) .
                ' to ' . ($roleLabels[$role] ?? $role)
            );

            redirectUsers("Role updated successfully.");

        } catch (Throwable $ex) {

            mysqli_rollback($conn);
            error_log("Change role failed: " . $ex->getMessage());

            redirectUsers($ex->getMessage());
        }
    }

    /* =====================================================
       DELETE USER
    ===================================================== */
    if ($action === 'delete_user') {

        $user_id = (int)($_POST['user_id'] ?? 0);

        if ($user_id <= 0) {
            redirectUsers("Invalid user.");
        }

        if ($user_id === $admin_id) {
            redirectUsers("You cannot delete your own account.");
        }

        /* GET USER DETAILS BEFORE DELETION */
        $getUser = mysqli_prepare(
            $conn,
            "SELECT name, email, role
             FROM users
             WHERE id = ?
             LIMIT 1"
        );

        mysqli_stmt_bind_param($getUser, "i", $user_id);
        mysqli_stmt_execute($getUser);

        $targetUser = mysqli_fetch_assoc(
            mysqli_stmt_get_result($getUser)
        );

        mysqli_stmt_close($getUser);

        if (!$targetUser) {
            redirectUsers("User not found.");
        }

        $stmt = mysqli_prepare(
            $conn,
            "DELETE FROM users WHERE id = ?"
        );

        mysqli_stmt_bind_param($stmt, "i", $user_id);

        if (mysqli_stmt_execute($stmt)) {

            if (mysqli_stmt_affected_rows($stmt) > 0) {

                mysqli_stmt_close($stmt);

                /* ACTIVITY LOG */
                addActivityLog(
                    $conn,
                    'Delete User',
                    'Deleted account: ' . $targetUser['name'] .
                    ' | Email: ' . $targetUser['email'] .
                    ' | Role: ' .
                    ($roleLabels[$targetUser['role']] ?? $targetUser['role'])
                );

                redirectUsers("User deleted successfully.");
            }

            mysqli_stmt_close($stmt);
            redirectUsers("User not found.");
        }

        mysqli_stmt_close($stmt);

        redirectUsers(
            "Unable to delete user. This account may be linked to existing records."
        );
    }

    /* =====================================================
       UPDATE ROLE PERMISSIONS
    ===================================================== */
    if ($action === 'update_permissions') {

        mysqli_begin_transaction($conn);

        try {

            foreach ($permissions as $roleKey => $rolePermissions) {

                foreach ($rolePermissions as $permission) {

                    $field = $roleKey . '_' . $permission;
                    $allowed = isset($_POST[$field]) ? 1 : 0;

                    $stmt = mysqli_prepare(
                        $conn,
                        "INSERT INTO role_permissions
                            (role, permission, allowed)
                         VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE
                            allowed = VALUES(allowed)"
                    );

                    if (!$stmt) {
                        throw new Exception(
                            "Unable to prepare permission update. Check your role_permissions table."
                        );
                    }

                    mysqli_stmt_bind_param(
                        $stmt,
                        "ssi",
                        $roleKey,
                        $permission,
                        $allowed
                    );

                    if (!mysqli_stmt_execute($stmt)) {
                        throw new Exception(
                            "Unable to save permissions. Check your database table."
                        );
                    }

                    mysqli_stmt_close($stmt);
                }
            }

            mysqli_commit($conn);

            /* ACTIVITY LOG */
            addActivityLog(
                $conn,
                'Update Role Permissions',
                'Updated role permissions for Student, Teacher, Program Chair, Director, and Admin.'
            );

            redirectUsers("Permissions updated successfully.");

        } catch (Throwable $ex) {

            mysqli_rollback($conn);
            error_log("Permission update failed: " . $ex->getMessage());

            redirectUsers($ex->getMessage());
        }
    }
}

/* =========================================================
   MESSAGE AND STATISTICS
========================================================= */
$message = $_SESSION['user_message'] ?? '';
unset($_SESSION['user_message']);

$totalUsers = getCount(
    $conn,
    "SELECT COUNT(*) AS total FROM users"
);

$totalStudents = getCount(
    $conn,
    "SELECT COUNT(*) AS total FROM users WHERE role = 'student'"
);

$totalTeachers = getCount(
    $conn,
    "SELECT COUNT(*) AS total FROM users WHERE role = 'teacher'"
);

$totalProgramChairs = getCount(
    $conn,
    "SELECT COUNT(*) AS total FROM users WHERE role = 'program_chair'"
);

$totalDirectors = getCount(
    $conn,
    "SELECT COUNT(*) AS total FROM users WHERE role = 'director'"
);

$totalAdmins = getCount(
    $conn,
    "SELECT COUNT(*) AS total FROM users WHERE role = 'admin'"
);

$activeToday = getCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM users
     WHERE last_login >= CURDATE()"
);

/* =========================================================
   SEARCH USERS + PROGRAM ASSIGNMENT
========================================================= */
$search = trim($_GET['search'] ?? '');

$sql = "SELECT
            u.*,
            p.id AS assigned_program_id,
            p.program_name,
            p.college_name,
            p.college_code
        FROM users u
        LEFT JOIN program_chair_assignments pca
            ON pca.user_id = u.id
        LEFT JOIN programs p
            ON p.id = pca.program_id";

if ($search !== '') {

    $searchValue = '%' . $search . '%';

    $sql .= " WHERE
                u.name LIKE ?
                OR u.email LIKE ?
                OR u.role LIKE ?
                OR p.program_name LIKE ?
                OR p.college_name LIKE ?";

    $sql .= " ORDER BY u.id DESC";

    $stmt = mysqli_prepare($conn, $sql);

    mysqli_stmt_bind_param(
        $stmt,
        "sssss",
        $searchValue,
        $searchValue,
        $searchValue,
        $searchValue,
        $searchValue
    );

    mysqli_stmt_execute($stmt);
    $usersResult = mysqli_stmt_get_result($stmt);

} else {

    $sql .= " ORDER BY u.id DESC";
    $usersResult = mysqli_query($conn, $sql);
}

/* =========================================================
   LOAD PERMISSION STATES
========================================================= */
$permissionState = [];

$permissionResult = mysqli_query(
    $conn,
    "SELECT role, permission, allowed FROM role_permissions"
);

if ($permissionResult) {

    while ($row = mysqli_fetch_assoc($permissionResult)) {

        $permissionState[$row['role']][$row['permission']] =
            (int)$row['allowed'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>User Management | PLMUN Classroom</title>

    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">
    <link rel="stylesheet" href="admin_dashboard.css">
    <link rel="stylesheet" href="admin_users.css">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>

<body>

<!-- =========================================
     SIDEBAR
========================================= -->

<div class="admin-sidebar" id="adminSidebar">

    <!-- SIDEBAR HEADER -->
    <div class="admin-sidebar-header">
        <h1>ADMIN</h1>
    </div>

    <!-- SIDEBAR MENU -->
    <ul class="admin-menu">

        <li>
            <a href="admin_dashboard.php">
                <i class="fa-solid fa-house"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <li>
            <a href="admin_users.php" class="active">
                <i class="fa-solid fa-user-group"></i>
                <span>User Management</span>
            </a>
        </li>

        <li>
            <a href="admin_settings.php">
                <i class="fa-solid fa-gear"></i>
                <span>System Settings</span>
            </a>
        </li>

        <li>
            <a href="admin_monitor.php">
                <i class="fa-solid fa-desktop"></i>
                <span>System Monitor</span>
            </a>
        </li>

        <li>
            <a href="admin_reports.php">
                <i class="fa-solid fa-chart-line"></i>
                <span>Reports &amp; Analytics</span>
            </a>
        </li>

        <li>
            <a href="admin_backup_security.php">
                <i class="fa-solid fa-shield-halved"></i>
                <span>Backup &amp; Security</span>
            </a>
        </li>

        <li>
            <a href="security_overview.php">
                <i class="fa-solid fa-shield-halved"></i>
                <span>Security Overview</span>
            </a>
        </li>

        <li>
            <a href="audit_logs.php">
                <i class="fa-solid fa-clock-rotate-left"></i>
                <span>Audit Logs</span>
            </a>
        </li>

    </ul>

    <!-- LOGOUT -->
    <div class="admin-logout">
        <a href="logout.php">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Log out</span>
        </a>
    </div>

</div>

<!-- MOBILE SIDEBAR OVERLAY -->
<div class="admin-overlay"
     id="adminOverlay"
     onclick="closeAdminSidebar()">
</div>

<div class="admin-main">

    <header class="admin-topbar">

        <div class="admin-top-left">

            <button
                class="admin-mobile-btn"
                onclick="toggleAdminSidebar()"
                type="button"
            >
                <i class="fa-solid fa-bars"></i>
            </button>

            <img
                src="img/classroom logo.png"
                alt="PLMUN Classroom"
                class="admin-logo"
            >

        </div>

        <div class="admin-top-right">

            <div class="admin-user-name">
                <span><?= e($admin['name']); ?></span>
            </div>

            <a href="profile.php" class="admin-profile">

                <?php
                $profileImage = trim($admin['profile_image'] ?? '');
                $profilePath = 'uploads/' . $profileImage;

                if (
                    $profileImage !== '' &&
                    $profileImage !== 'default.png' &&
                    file_exists($profilePath)
                ):
                ?>

                    <img src="<?= e($profilePath); ?>" alt="Admin Profile">

                <?php else: ?>

                    <i class="fa-solid fa-user"></i>

                <?php endif; ?>

            </a>

        </div>

    </header>

    <main class="admin-content">

        <div class="users-title-row">

            <h2>User Management</h2>

            <button
                class="add-user-btn"
                data-bs-toggle="modal"
                data-bs-target="#addUserModal"
                type="button"
            >
                <i class="fa-solid fa-plus"></i>
                Add User
            </button>

        </div>

        <?php if ($message !== ''): ?>

            <div class="user-alert">
                <i class="fa-solid fa-circle-check"></i>
                <?= e($message); ?>
            </div>

        <?php endif; ?>

        <!-- USER STATISTICS -->
        <div class="user-statistics">

            <div class="user-stat-box">
                <span>Total Users</span>
                <strong><?= number_format($totalUsers); ?></strong>
            </div>

            <div class="user-stat-box">
                <span>Students</span>
                <strong><?= number_format($totalStudents); ?></strong>
            </div>

            <div class="user-stat-box">
                <span>Teachers</span>
                <strong><?= number_format($totalTeachers); ?></strong>
            </div>

            <div class="user-stat-box">
                <span>Program Chairs</span>
                <strong><?= number_format($totalProgramChairs); ?></strong>
            </div>

            <div class="user-stat-box">
                <span>Directors</span>
                <strong><?= number_format($totalDirectors); ?></strong>
            </div>

            <div class="user-stat-box">
                <span>Admins</span>
                <strong><?= number_format($totalAdmins); ?></strong>
            </div>

            <div class="user-stat-box">
                <span>Active Today</span>
                <strong><?= number_format($activeToday); ?></strong>
            </div>

        </div>

        <!-- ROLE PERMISSIONS -->
        

        <!-- ALL USERS -->
        <section class="all-users-section">

            <div class="users-list-header">

                <h3>All Users</h3>

                <form method="GET" class="user-search">

                    <input
                        type="text"
                        name="search"
                        placeholder="Search users..."
                        value="<?= e($search); ?>"
                    >

                    <button type="submit">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </button>

                </form>

            </div>

            <div class="users-table-wrapper">

                <table class="users-table">

                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Password</th>
                            <th>Role</th>
                            <th>Assigned Program</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if ($usersResult && mysqli_num_rows($usersResult) > 0): ?>

                        <?php while ($user = mysqli_fetch_assoc($usersResult)): ?>

                            <tr>

                                <td>

                                    <div class="table-user">

                                        <div class="table-avatar">

                                            <?php
                                            $image = trim($user['profile_image'] ?? '');
                                            $imagePath = 'uploads/' . $image;

                                            if (
                                                $image !== '' &&
                                                $image !== 'default.png' &&
                                                file_exists($imagePath)
                                            ):
                                            ?>

                                                <img src="<?= e($imagePath); ?>" alt="Profile">

                                            <?php else: ?>

                                                <i class="fa-solid fa-user"></i>

                                            <?php endif; ?>

                                        </div>

                                        <span><?= e($user['name']); ?></span>

                                    </div>

                                </td>

                                <td><?= e($user['email']); ?></td>

                                <td>
                                    <span class="hidden-password">••••••••</span>
                                </td>

                                <td>

                                    <form method="POST" class="role-form">

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e($csrf_token); ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="change_role"
                                        >

                                        <input
                                            type="hidden"
                                            name="user_id"
                                            value="<?= (int)$user['id']; ?>"
                                        >

                                        <select
                                            name="role"
                                            onchange="this.form.submit()"
                                            <?= (int)$user['id'] === $admin_id ? 'disabled' : ''; ?>
                                        >

                                            <?php foreach ($roleLabels as $roleValue => $roleLabel): ?>

                                                <option
                                                    value="<?= e($roleValue); ?>"
                                                    <?= $user['role'] === $roleValue ? 'selected' : ''; ?>
                                                >
                                                    <?= e($roleLabel); ?>
                                                </option>

                                            <?php endforeach; ?>

                                        </select>

                                    </form>

                                </td>

                                <td>

                                    <?php if (
                                        $user['role'] === 'program_chair' &&
                                        !empty($user['program_name'])
                                    ): ?>

                                        <strong><?= e($user['program_name']); ?></strong>

                                        <small class="d-block text-muted">
                                            <?= e($user['college_code']); ?>
                                            ·
                                            <?= e($user['college_name']); ?>
                                        </small>

                                    <?php elseif ($user['role'] === 'program_chair'): ?>

                                        <span class="text-danger">Not assigned</span>

                                    <?php else: ?>

                                        <span class="text-muted">—</span>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <div class="user-actions">

                                        <button
                                            type="button"
                                            class="edit-btn"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editUserModal"
                                            data-id="<?= (int)$user['id']; ?>"
                                            data-name="<?= e($user['name']); ?>"
                                            data-email="<?= e($user['email']); ?>"
                                            data-role="<?= e($user['role']); ?>"
                                            data-program-id="<?= (int)($user['assigned_program_id'] ?? 0); ?>"
                                            title="Edit User"
                                        >
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>

                                        <?php if ((int)$user['id'] !== $admin_id): ?>

                                            <form
                                                method="POST"
                                                onsubmit="return confirm('Are you sure you want to delete this user?');"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= e($csrf_token); ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="delete_user"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="user_id"
                                                    value="<?= (int)$user['id']; ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="delete-btn"
                                                    title="Delete User"
                                                >
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>

                                            </form>

                                        <?php endif; ?>

                                    </div>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="6" class="no-users">
                                No users found.
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    </main>

</div>

<!-- =====================================================
     ADD USER MODAL
===================================================== -->
<div class="modal fade" id="addUserModal" tabindex="-1">

    <div class="modal-dialog">

        <div class="modal-content user-modal">

            <div class="modal-header">

                <h5 class="modal-title">Add User</h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>

            <form method="POST">

                <div class="modal-body">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrf_token); ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="add_user"
                    >

                    <div class="form-group">

                        <label>Full Name</label>

                        <input
                            type="text"
                            name="name"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label>Email</label>

                        <input
                            type="email"
                            name="email"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label>Password</label>

                        <input
                            type="password"
                            name="password"
                            minlength="6"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label>Role</label>

                        <select name="role" id="addUserRole" required>

                            <?php foreach ($roleLabels as $roleValue => $roleLabel): ?>

                                <option value="<?= e($roleValue); ?>">
                                    <?= e($roleLabel); ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div
                        class="program-assignment-fields"
                        id="addProgramFields"
                        hidden
                    >

                        <div class="form-group">

                            <label>College</label>

                            <select id="addCollegeSelect">

                                <option value="">Select College</option>

                                <?php foreach ($programsByCollege as $collegeName => $collegeData): ?>

                                    <option value="<?= e($collegeName); ?>">
                                        <?= e($collegeData['college_code'] . ' - ' . $collegeName); ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="form-group">

                            <label>Program</label>

                            <select
                                name="program_id"
                                id="addProgramSelect"
                                disabled
                            >
                                <option value="">Select Program</option>
                            </select>

                        </div>

                    </div>

                </div>

                <div class="modal-footer">

                    <button
                        type="button"
                        class="cancel-btn"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button type="submit" class="add-user-submit">
                        Add User
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- =====================================================
     EDIT USER MODAL
===================================================== -->
<div class="modal fade" id="editUserModal" tabindex="-1">

    <div class="modal-dialog">

        <div class="modal-content user-modal">

            <div class="modal-header">

                <h5 class="modal-title">Edit User</h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>

            <form method="POST">

                <div class="modal-body">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrf_token); ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="edit_user"
                    >

                    <input
                        type="hidden"
                        name="user_id"
                        id="editUserId"
                    >

                    <div class="form-group">

                        <label>Full Name</label>

                        <input
                            type="text"
                            name="name"
                            id="editUserName"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label>Email</label>

                        <input
                            type="email"
                            name="email"
                            id="editUserEmail"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label>
                            New Password
                            <small>(leave blank to keep current password)</small>
                        </label>

                        <input
                            type="password"
                            name="password"
                            minlength="6"
                        >

                    </div>

                    <div class="form-group">

                        <label>Role</label>

                        <select name="role" id="editUserRole" required>

                            <?php foreach ($roleLabels as $roleValue => $roleLabel): ?>

                                <option value="<?= e($roleValue); ?>">
                                    <?= e($roleLabel); ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div
                        class="program-assignment-fields"
                        id="editProgramFields"
                        hidden
                    >

                        <div class="form-group">

                            <label>College</label>

                            <select id="editCollegeSelect">

                                <option value="">Select College</option>

                                <?php foreach ($programsByCollege as $collegeName => $collegeData): ?>

                                    <option value="<?= e($collegeName); ?>">
                                        <?= e($collegeData['college_code'] . ' - ' . $collegeName); ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="form-group">

                            <label>Program</label>

                            <select
                                name="program_id"
                                id="editProgramSelect"
                                disabled
                            >
                                <option value="">Select Program</option>
                            </select>

                        </div>

                    </div>

                </div>

                <div class="modal-footer">

                    <button
                        type="button"
                        class="cancel-btn"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button type="submit" class="add-user-submit">
                        Save Changes
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const programData = <?= json_encode(
    $programsByCollege,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
); ?>;

/* =====================================================
   SIDEBAR
===================================================== */
function toggleAdminSidebar() {

    document.getElementById('adminSidebar').classList.toggle('show');

    document.getElementById('adminOverlay').classList.toggle('show');
}

function closeAdminSidebar() {

    document.getElementById('adminSidebar').classList.remove('show');

    document.getElementById('adminOverlay').classList.remove('show');
}

/* =====================================================
   PROGRAM SELECTOR
===================================================== */
function setupProgramSelector(
    roleSelectId,
    fieldsId,
    collegeSelectId,
    programSelectId
) {

    const roleSelect = document.getElementById(roleSelectId);
    const fields = document.getElementById(fieldsId);
    const collegeSelect = document.getElementById(collegeSelectId);
    const programSelect = document.getElementById(programSelectId);

    function populatePrograms(collegeName, selectedProgramId = '') {

        programSelect.innerHTML =
            '<option value="">Select Program</option>';

        if (!collegeName || !programData[collegeName]) {

            programSelect.disabled = true;
            return;
        }

        programData[collegeName].programs.forEach(program => {

            const option = document.createElement('option');

            option.value = program.id;
            option.textContent = program.name;

            if (String(program.id) === String(selectedProgramId)) {
                option.selected = true;
            }

            programSelect.appendChild(option);

        });

        programSelect.disabled = false;
    }

    function updateVisibility() {

        const isProgramChair =
            roleSelect.value === 'program_chair';

        fields.hidden = !isProgramChair;

        collegeSelect.required = isProgramChair;
        programSelect.required = isProgramChair;

        if (!isProgramChair) {

            collegeSelect.value = '';

            programSelect.innerHTML =
                '<option value="">Select Program</option>';

            programSelect.disabled = true;
        }
    }

    roleSelect.addEventListener('change', updateVisibility);

    collegeSelect.addEventListener('change', function() {

        populatePrograms(this.value);

    });

    return {

        populatePrograms,

        updateVisibility,

        setProgram(programId) {

            if (!programId) {

                collegeSelect.value = '';
                populatePrograms('');

                return;
            }

            let foundCollege = '';

            Object.keys(programData).some(collegeName => {

                const found = programData[collegeName].programs.some(
                    program => String(program.id) === String(programId)
                );

                if (found) {

                    foundCollege = collegeName;
                    return true;
                }

                return false;

            });

            collegeSelect.value = foundCollege;

            populatePrograms(foundCollege, programId);
        }
    };
}

/* =====================================================
   INITIALIZE PROGRAM SELECTORS
===================================================== */
const addProgramUI = setupProgramSelector(
    'addUserRole',
    'addProgramFields',
    'addCollegeSelect',
    'addProgramSelect'
);

const editProgramUI = setupProgramSelector(
    'editUserRole',
    'editProgramFields',
    'editCollegeSelect',
    'editProgramSelect'
);

/* =====================================================
   ADD USER MODAL
===================================================== */
document.getElementById('addUserModal').addEventListener(
    'show.bs.modal',
    function() {

        document.getElementById('addUserRole').value = 'student';

        addProgramUI.updateVisibility();

    }
);

/* =====================================================
   EDIT USER MODAL
===================================================== */
document.getElementById('editUserModal').addEventListener(
    'show.bs.modal',
    function(event) {

        const button = event.relatedTarget;

        document.getElementById('editUserId').value =
            button.getAttribute('data-id');

        document.getElementById('editUserName').value =
            button.getAttribute('data-name');

        document.getElementById('editUserEmail').value =
            button.getAttribute('data-email');

        document.getElementById('editUserRole').value =
            button.getAttribute('data-role');

        editProgramUI.updateVisibility();

        const assignedProgramId =
            button.getAttribute('data-program-id');

        if (button.getAttribute('data-role') === 'program_chair') {

            editProgramUI.setProgram(assignedProgramId);

        } else {

            document.getElementById('editCollegeSelect').value = '';

            document.getElementById('editProgramSelect').innerHTML =
                '<option value="">Select Program</option>';

            document.getElementById('editProgramSelect').disabled = true;

        }

    }
);

/* =====================================================
   RESPONSIVE SIDEBAR
===================================================== */
window.addEventListener('resize', function() {

    if (window.innerWidth > 768) {
        closeAdminSidebar();
    }

});
</script>

</body>
</html>
