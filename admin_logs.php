
<?php
session_start();
include 'database.php';

/* =========================================
   ADMIN ACCESS CONTROL
========================================= */

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit();
}

$admin_id = (int) $_SESSION['user_id'];

/* =========================================
   HELPER FUNCTIONS
========================================= */

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function bindDynamicParams($stmt, $types, &$params) {
    if (empty($params)) {
        return true;
    }

    $bindValues = [];
    $bindValues[] = $types;

    foreach ($params as $key => &$value) {
        $bindValues[] = &$value;
    }

    return call_user_func_array(
        [$stmt, 'bind_param'],
        $bindValues
    );
}

function getRoleLabel($role) {
    $role = strtolower(trim((string)$role));
    $role = str_replace(['-', ' '], '_', $role);

    $roles = [
        'admin' => 'Admin',
        'administrator' => 'Admin',
        'teacher' => 'Teacher',
        'student' => 'Student',
        'director' => 'Director',
        'program_chair' => 'Program Chair'
    ];

    return $roles[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

function getActivityIcon($action) {
    $action = strtolower(trim((string)$action));

    if (strpos($action, 'login') !== false) {
        return 'fa-right-to-bracket';
    }

    if (strpos($action, 'logout') !== false) {
        return 'fa-right-from-bracket';
    }

    if (strpos($action, 'register') !== false) {
        return 'fa-user-plus';
    }

    if (strpos($action, 'announcement') !== false) {
        return 'fa-bullhorn';
    }

    if (strpos($action, 'assessment') !== false) {
        return 'fa-file-pen';
    }

    if (strpos($action, 'material') !== false) {
        return 'fa-folder-open';
    }

    if (strpos($action, 'meet') !== false) {
        return 'fa-video';
    }

    if (strpos($action, 'class') !== false || strpos($action, 'course') !== false) {
        return 'fa-chalkboard';
    }

    if (strpos($action, 'delete') !== false) {
        return 'fa-trash';
    }

    if (strpos($action, 'update') !== false || strpos($action, 'edit') !== false) {
        return 'fa-pen-to-square';
    }

    if (strpos($action, 'create') !== false || strpos($action, 'add') !== false) {
        return 'fa-plus';
    }

    if (strpos($action, 'user') !== false) {
        return 'fa-user';
    }

    return 'fa-clock';
}

/* =========================================
   LOAD ADMIN INFORMATION
========================================= */

$adminQuery = mysqli_prepare(
    $conn,
    "SELECT * FROM users WHERE id = ? LIMIT 1"
);

mysqli_stmt_bind_param($adminQuery, "i", $admin_id);
mysqli_stmt_execute($adminQuery);

$adminResult = mysqli_stmt_get_result($adminQuery);
$admin = mysqli_fetch_assoc($adminResult);

if (!$admin) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$adminName = $admin['name'] ?? 'Administrator';

$profileImage = $admin['profile_image'] ?? '';

$showProfileImage = (
    !empty($profileImage) &&
    $profileImage !== 'default.png' &&
    file_exists('uploads/' . $profileImage)
);

/* =========================================
   CSRF TOKEN
========================================= */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/* =========================================
   CLEAR ACTIVITY LOGS
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_logs'])) {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, $submittedToken)) {
        $_SESSION['logs_message'] = 'Invalid security token. Please try again.';
        $_SESSION['logs_message_type'] = 'danger';
    } else {

        $clearQuery = mysqli_query($conn, "DELETE FROM activity_logs");

        if ($clearQuery) {
            $_SESSION['logs_message'] = 'All activity logs have been cleared successfully.';
            $_SESSION['logs_message_type'] = 'success';
        } else {
            $_SESSION['logs_message'] = 'Unable to clear activity logs. Please check your database.';
            $_SESSION['logs_message_type'] = 'danger';
        }
    }

    header("Location: admin_logs.php");
    exit();
}

/* =========================================
   FLASH MESSAGE
========================================= */

$message = $_SESSION['logs_message'] ?? '';
$messageType = $_SESSION['logs_message_type'] ?? 'success';

unset($_SESSION['logs_message'], $_SESSION['logs_message_type']);

/* =========================================
   SEARCH AND FILTER
========================================= */

$search = trim($_GET['search'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');

$allowedRoles = [
    'admin',
    'director',
    'program_chair',
    'teacher',
    'student'
];

if (!in_array($roleFilter, $allowedRoles, true)) {
    $roleFilter = '';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

$whereConditions = [];
$params = [];
$types = '';

if ($search !== '') {
    $whereConditions[] = "(
        user_name LIKE ?
        OR action LIKE ?
        OR description LIKE ?
        OR ip_address LIKE ?
    )";

    $searchValue = '%' . $search . '%';

    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;

    $types .= 'ssss';
}

if ($roleFilter !== '') {
    $whereConditions[] = "
        REPLACE(
            REPLACE(LOWER(TRIM(user_role)), ' ', '_'),
            '-', '_'
        ) = ?
    ";

    $params[] = $roleFilter;
    $types .= 's';
}

$whereSQL = '';

if (!empty($whereConditions)) {
    $whereSQL = ' WHERE ' . implode(' AND ', $whereConditions);
}

/* =========================================
   TOTAL LOG COUNT
========================================= */

$totalLogs = 0;

$countSQL = "
    SELECT COUNT(*) AS total
    FROM activity_logs
    $whereSQL
";

$countStmt = mysqli_prepare($conn, $countSQL);

if ($countStmt) {
    if (!empty($params)) {
        bindDynamicParams($countStmt, $types, $params);
    }

    mysqli_stmt_execute($countStmt);

    $countResult = mysqli_stmt_get_result($countStmt);
    $countRow = mysqli_fetch_assoc($countResult);

    $totalLogs = (int)($countRow['total'] ?? 0);

    mysqli_stmt_close($countStmt);
}

$totalPages = max(1, (int)ceil($totalLogs / $limit));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

/* =========================================
   FETCH ACTIVITY LOGS
========================================= */

$activities = [];

$logsSQL = "
    SELECT
        id,
        user_id,
        user_name,
        user_role,
        action,
        description,
        ip_address,
        created_at
    FROM activity_logs
    $whereSQL
    ORDER BY created_at DESC, id DESC
    LIMIT ? OFFSET ?
";

$logsStmt = mysqli_prepare($conn, $logsSQL);

if ($logsStmt) {

    $logParams = $params;
    $logTypes = $types . 'ii';

    $logParams[] = $limit;
    $logParams[] = $offset;

    bindDynamicParams($logsStmt, $logTypes, $logParams);

    mysqli_stmt_execute($logsStmt);

    $logsResult = mysqli_stmt_get_result($logsStmt);

    while ($row = mysqli_fetch_assoc($logsResult)) {
        $activities[] = $row;
    }

    mysqli_stmt_close($logsStmt);
}

/* =========================================
   ACTIVITY STATISTICS
========================================= */

function getLogCount($conn, $sql) {
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        return 0;
    }

    $row = mysqli_fetch_assoc($result);

    return (int)($row['total'] ?? 0);
}

$totalToday = getLogCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM activity_logs
     WHERE DATE(created_at) = CURDATE()"
);

$totalLogins = getLogCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM activity_logs
     WHERE LOWER(action) LIKE '%login%'"
);

$totalAdminActions = getLogCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM activity_logs
     WHERE LOWER(TRIM(user_role)) IN ('admin', 'administrator')"
);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Logs & Activity | PLMUN Classroom</title>

    <link rel="icon" href="img/plmun logo.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link rel="stylesheet" href="admin_dashboard.css">

    <style>
        /* =========================================
           LOGS PAGE
        ========================================= */

        .logs-stat-card {
            min-height: 115px;
        }

        .logs-stat-card .admin-stat-label {
            font-size: 17px;
        }

        .logs-stat-card .admin-stat-info strong {
            font-size: 35px;
        }

        .logs-container {
            background: #fff;
            border: 1px solid #ddd;
            margin-top: 10px;
        }

        .logs-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            padding: 18px 20px;
            border-bottom: 1px solid #eee;
            flex-wrap: wrap;
        }

        .logs-toolbar h3 {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
            color: #222;
        }

        .logs-toolbar p {
            font-size: 13px;
            color: #888;
            margin: 4px 0 0;
        }

        .logs-filters {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            padding: 18px 20px;
            background: #fff;
            border-bottom: 1px solid #eee;
        }

        .logs-search {
            position: relative;
            flex: 1;
            min-width: 200px;
        }

        .logs-search i {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
        }

        .logs-search input {
            width: 100%;
            height: 42px;
            padding: 8px 12px 8px 38px;
            border: 1px solid #ddd;
            outline: none;
            font-size: 14px;
        }

        .logs-search input:focus,
        .logs-filters select:focus {
            border-color: #3f87b5;
            box-shadow: 0 0 0 2px rgba(63,135,181,.10);
        }

        .logs-filters select {
            height: 42px;
            min-width: 160px;
            padding: 7px 12px;
            border: 1px solid #ddd;
            background: #fff;
            outline: none;
            font-size: 14px;
        }

        .logs-btn {
            height: 42px;
            padding: 0 16px;
            border: none;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            cursor: pointer;
        }

        .logs-btn-primary {
            background: #3f87b5;
            color: #fff;
        }

        .logs-btn-primary:hover {
            background: #32739e;
            color: #fff;
        }

        .logs-btn-danger {
            background: #dc5555;
            color: #fff;
        }

        .logs-btn-danger:hover {
            background: #c83e3e;
        }

        .logs-btn-light {
            background: #eee;
            color: #444;
        }

        .logs-table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .logs-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        .logs-table thead {
            background: #f5f6f8;
        }

        .logs-table th {
            padding: 15px 17px;
            font-size: 13px;
            font-weight: 700;
            color: #555;
            text-align: left;
            border-bottom: 1px solid #e5e5e5;
            white-space: nowrap;
        }

        .logs-table td {
            padding: 15px 17px;
            font-size: 13px;
            color: #444;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }

        .logs-table tbody tr:hover {
            background: #fafbfc;
        }

        .logs-user {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .logs-user strong {
            font-size: 13px;
            color: #333;
        }

        .logs-user small {
            font-size: 11px;
            color: #999;
        }

        .logs-action {
            display: inline-block;
            padding: 5px 9px;
            background: #eef4f8;
            color: #3f87b5;
            font-size: 11px;
            font-weight: 700;
            border-radius: 3px;
            white-space: nowrap;
        }

        .logs-role {
            display: inline-block;
            padding: 5px 9px;
            background: #f0f0f0;
            color: #555;
            font-size: 11px;
            font-weight: 600;
            border-radius: 3px;
            white-space: nowrap;
        }

        .logs-description {
            max-width: 280px;
            overflow-wrap: anywhere;
        }

        .logs-ip {
            color: #777;
            white-space: nowrap;
        }

        .logs-date {
            white-space: nowrap;
            color: #777;
            font-size: 12px;
        }

        .logs-empty {
            text-align: center;
            padding: 55px 20px !important;
            color: #999 !important;
        }

        .logs-empty i {
            font-size: 35px;
            display: block;
            margin-bottom: 12px;
            color: #ccc;
        }

        .logs-empty p {
            margin: 0;
            font-size: 14px;
        }

        .logs-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            padding: 16px 20px;
            flex-wrap: wrap;
        }

        .logs-footer-info {
            color: #888;
            font-size: 13px;
        }

        .logs-pagination {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .logs-pagination a,
        .logs-pagination span {
            min-width: 34px;
            height: 34px;
            padding: 0 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            border: 1px solid #ddd;
            color: #555;
            font-size: 13px;
            background: #fff;
        }

        .logs-pagination a:hover {
            background: #eef4f8;
            color: #3f87b5;
        }

        .logs-pagination .current {
            background: #3f87b5;
            border-color: #3f87b5;
            color: #fff;
        }

        .logs-alert {
            margin: 0 0 20px;
            border-radius: 0;
            font-size: 14px;
        }

        .logs-clear-form {
            margin: 0;
        }

        @media (max-width: 768px) {
            .logs-toolbar {
                align-items: flex-start;
            }

            .logs-filters {
                flex-direction: column;
                align-items: stretch;
            }

            .logs-search {
                width: 100%;
                min-width: 0;
            }

            .logs-filters select,
            .logs-filters .logs-btn {
                width: 100%;
            }

            .logs-footer {
                align-items: flex-start;
                flex-direction: column;
            }

            .logs-pagination {
                flex-wrap: wrap;
            }
        }

        @media (max-width: 480px) {
            .logs-toolbar {
                padding: 15px;
            }

            .logs-toolbar h3 {
                font-size: 18px;
            }

            .logs-stat-card .admin-stat-info strong {
                font-size: 30px;
            }
        }
    </style>
</head>

<body>

<!-- =========================================
     SIDEBAR
========================================= -->

<div class="admin-overlay" id="adminOverlay" onclick="closeAdminSidebar()"></div>

<aside class="admin-sidebar" id="adminSidebar">

    <div class="admin-sidebar-header">
        <h1>ADMIN</h1>
    </div>

    <ul class="admin-menu">

        <li>
            <a href="admin_dashboard.php">
                <i class="fa-solid fa-house"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <li>
            <a href="admin_users.php">
                <i class="fa-solid fa-user-group"></i>
                <span>User Management</span>
            </a>
        </li>

        <li>
            <a href="admin_settings.php">
                <i class="fas fa-gear"></i>
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
                <i class="fas fa-chart-bar"></i>
                <span>Reports & Analytics</span>
            </a>
        </li>

        <li>
            <a href="admin_logs.php" class="active">
                <i class="fas fa-clock-rotate-left"></i>
                <span>Logs & Activity</span>
            </a>
        </li>

        <li>
            <a href="admin_security.php">
                <i class="fas fa-shield-halved"></i>
                <span>Security</span>
            </a>
        </li>

    </ul>

    <div class="admin-logout">
        <a href="logout.php">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>

</aside>

<!-- =========================================
     MAIN CONTENT
========================================= -->

<main class="admin-main">

    <!-- TOPBAR -->

    <header class="admin-topbar">

        <div class="admin-top-left">

            <button class="admin-mobile-btn" onclick="toggleAdminSidebar()">
                <i class="fas fa-bars"></i>
            </button>

            <img src="img/classroom logo.png" alt="PLMUN Classroom" class="admin-logo">

        </div>

        <div class="admin-top-right">

            <span class="admin-user-name">
                <?php echo e($adminName); ?>
            </span>

            <a href="profile.php" class="admin-profile">

                <?php if ($showProfileImage): ?>

                    <img src="<?php echo e('uploads/' . $profileImage); ?>" alt="Admin Profile">

                <?php else: ?>

                    <i class="fas fa-user"></i>

                <?php endif; ?>

            </a>

        </div>

    </header>

    <!-- PAGE CONTENT -->

    <section class="admin-content">

        <div class="admin-page-header">
            <h2>Logs & Activity</h2>
            <p>Monitor system activities and user actions.</p>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?php echo e($messageType); ?> logs-alert">
                <?php echo e($message); ?>
            </div>
        <?php endif; ?>

        <!-- STATISTICS -->

        <div class="admin-stats">

            <div class="admin-stat-card purple logs-stat-card">

                <div class="admin-stat-info">
                    <span class="admin-stat-label">Total Activities Today</span>
                    <strong><?php echo number_format($totalToday); ?></strong>
                </div>

                <i class="fas fa-calendar-day admin-stat-icon"></i>

            </div>

            <div class="admin-stat-card green logs-stat-card">

                <div class="admin-stat-info">
                    <span class="admin-stat-label">Total Login Activities</span>
                    <strong><?php echo number_format($totalLogins); ?></strong>
                </div>

                <i class="fas fa-right-to-bracket admin-stat-icon"></i>

            </div>

            <div class="admin-stat-card orange logs-stat-card">

                <div class="admin-stat-info">
                    <span class="admin-stat-label">Admin Activities</span>
                    <strong><?php echo number_format($totalAdminActions); ?></strong>
                </div>

                <i class="fas fa-user-shield admin-stat-icon"></i>

            </div>

        </div>

        <!-- ACTIVITY LOGS -->

        <div class="logs-container">

            <div class="logs-toolbar">

                <div>
                    <h3>System Activity History</h3>
                    <p>View and manage recorded user activities.</p>
                </div>

                <form method="POST" class="logs-clear-form"
                      onsubmit="return confirm('Are you sure you want to permanently delete all activity logs? This action cannot be undone.');">

                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

                    <button type="submit" name="clear_logs" value="1"
                            class="logs-btn logs-btn-danger">

                        <i class="fas fa-trash"></i>
                        Clear All Logs

                    </button>

                </form>

            </div>

            <!-- SEARCH AND FILTER -->

            <form method="GET" action="admin_logs.php" class="logs-filters">

                <div class="logs-search">
                    <i class="fas fa-search"></i>

                    <input
                        type="text"
                        name="search"
                        placeholder="Search user, action, description or IP..."
                        value="<?php echo e($search); ?>"
                    >
                </div>

                <select name="role">

                    <option value="">All Roles</option>

                    <option value="admin" <?php echo $roleFilter === 'admin' ? 'selected' : ''; ?>>
                        Admin
                    </option>

                    <option value="director" <?php echo $roleFilter === 'director' ? 'selected' : ''; ?>>
                        Director
                    </option>

                    <option value="program_chair" <?php echo $roleFilter === 'program_chair' ? 'selected' : ''; ?>>
                        Program Chair
                    </option>

                    <option value="teacher" <?php echo $roleFilter === 'teacher' ? 'selected' : ''; ?>>
                        Teacher
                    </option>

                    <option value="student" <?php echo $roleFilter === 'student' ? 'selected' : ''; ?>>
                        Student
                    </option>

                </select>

                <button type="submit" class="logs-btn logs-btn-primary">
                    <i class="fas fa-filter"></i>
                    Apply Filter
                </button>

                <a href="admin_logs.php" class="logs-btn logs-btn-light">
                    <i class="fas fa-rotate-left"></i>
                    Reset
                </a>

            </form>

            <!-- TABLE -->

            <div class="logs-table-wrapper">

                <table class="logs-table">

                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Action</th>
                            <th>Description</th>
                            <th>IP Address</th>
                            <th>Date & Time</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (!empty($activities)): ?>

                            <?php foreach ($activities as $activity): ?>

                                <tr>

                                    <td>
                                        <div class="logs-user">

                                            <strong>
                                                <?php echo e($activity['user_name'] ?: 'Unknown User'); ?>
                                            </strong>

                                            <small>
                                                User ID: <?php echo (int)$activity['user_id']; ?>
                                            </small>

                                        </div>
                                    </td>

                                    <td>
                                        <span class="logs-role">
                                            <?php echo e(getRoleLabel($activity['user_role'])); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="logs-action">
                                            <i class="fas <?php echo e(getActivityIcon($activity['action'])); ?>"></i>
                                            <?php echo e($activity['action']); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <div class="logs-description">
                                            <?php echo e($activity['description'] ?: 'No description'); ?>
                                        </div>
                                    </td>

                                    <td>
                                        <span class="logs-ip">
                                            <?php echo e($activity['ip_address'] ?: 'N/A'); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="logs-date">
                                            <?php
                                            if (!empty($activity['created_at'])) {
                                                echo e(date('M d, Y h:i A', strtotime($activity['created_at'])));
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </span>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="6" class="logs-empty">

                                    <i class="fas fa-clock-rotate-left"></i>

                                    <p>
                                        <?php echo ($search !== '' || $roleFilter !== '')
                                            ? 'No activity logs match your search or filter.'
                                            : 'No activity logs available yet.'; ?>
                                    </p>

                                </td>
                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <!-- PAGINATION -->

            <div class="logs-footer">

                <div class="logs-footer-info">

                    <?php if ($totalLogs > 0): ?>

                        Showing
                        <?php echo number_format($offset + 1); ?>
                        to
                        <?php echo number_format(min($offset + $limit, $totalLogs)); ?>
                        of
                        <?php echo number_format($totalLogs); ?>
                        activities

                    <?php else: ?>

                        No records found

                    <?php endif; ?>

                </div>

                <?php if ($totalPages > 1): ?>

                    <div class="logs-pagination">

                        <?php
                        $queryParams = [];

                        if ($search !== '') {
                            $queryParams['search'] = $search;
                        }

                        if ($roleFilter !== '') {
                            $queryParams['role'] = $roleFilter;
                        }
                        ?>

                        <?php if ($page > 1): ?>

                            <?php
                            $queryParams['page'] = $page - 1;
                            $previousUrl = 'admin_logs.php?' . http_build_query($queryParams);
                            ?>

                            <a href="<?php echo e($previousUrl); ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>

                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        ?>

                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>

                            <?php
                            $queryParams['page'] = $i;
                            $pageUrl = 'admin_logs.php?' . http_build_query($queryParams);
                            ?>

                            <?php if ($i === $page): ?>

                                <span class="current">
                                    <?php echo $i; ?>
                                </span>

                            <?php else: ?>

                                <a href="<?php echo e($pageUrl); ?>">
                                    <?php echo $i; ?>
                                </a>

                            <?php endif; ?>

                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>

                            <?php
                            $queryParams['page'] = $page + 1;
                            $nextUrl = 'admin_logs.php?' . http_build_query($queryParams);
                            ?>

                            <a href="<?php echo e($nextUrl); ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </section>

</main>

<script>
/* =========================================
   MOBILE SIDEBAR
========================================= */

function toggleAdminSidebar() {
    document.getElementById('adminSidebar').classList.toggle('show');
    document.getElementById('adminOverlay').classList.toggle('show');
}

function closeAdminSidebar() {
    document.getElementById('adminSidebar').classList.remove('show');
    document.getElementById('adminOverlay').classList.remove('show');
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
