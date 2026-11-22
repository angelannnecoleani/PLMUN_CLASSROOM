
<?php
session_start();
require_once 'database.php';

/* =========================================
   ADMIN ACCESS CONTROL
========================================= */

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.html");
    exit();
}

$admin_id = (int) $_SESSION['user_id'];

/* =========================================
   HELPER FUNCTIONS
========================================= */

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function getCount($conn, $sql) {
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        return 0;
    }

    $row = mysqli_fetch_assoc($result);

    return (int)($row['total'] ?? 0);
}

function getRoleLabel($role) {
    $role = strtolower(trim((string)$role));
    $role = str_replace([' ', '-'], '_', $role);

    $labels = [
        'admin' => 'Admin',
        'student' => 'Student',
        'teacher' => 'Teacher',
        'director' => 'Director',
        'program_chair' => 'Program Chair'
    ];

    return $labels[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

/* =========================================
   ACTIVITY ICON
========================================= */

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

    if (strpos($action, 'user') !== false) {
        return 'fa-users';
    }

    if (strpos($action, 'class') !== false) {
        return 'fa-book';
    }

    if (strpos($action, 'announcement') !== false) {
        return 'fa-bullhorn';
    }

    if (strpos($action, 'assessment') !== false) {
        return 'fa-file-circle-check';
    }

    if (strpos($action, 'material') !== false) {
        return 'fa-folder-open';
    }

    if (strpos($action, 'meet') !== false) {
        return 'fa-video';
    }

    if (strpos($action, 'assign') !== false) {
        return 'fa-user-check';
    }

    if (strpos($action, 'delete') !== false) {
        return 'fa-trash';
    }

    if (strpos($action, 'update') !== false || strpos($action, 'edit') !== false) {
        return 'fa-pen-to-square';
    }

    if (strpos($action, 'create') !== false || strpos($action, 'add') !== false) {
        return 'fa-plus-circle';
    }

    return 'fa-clock';
}

/* =========================================
   GET ADMIN INFORMATION
========================================= */

$stmt = mysqli_prepare(
    $conn,
    "SELECT * FROM users WHERE id = ? LIMIT 1"
);

if (!$stmt) {
    die("Admin query preparation failed: " . e(mysqli_error($conn)));
}

mysqli_stmt_bind_param($stmt, "i", $admin_id);
mysqli_stmt_execute($stmt);

$userQuery = mysqli_stmt_get_result($stmt);

if (!$userQuery || mysqli_num_rows($userQuery) === 0) {
    mysqli_stmt_close($stmt);
    session_destroy();
    header("Location: login.html");
    exit();
}

$admin = mysqli_fetch_assoc($userQuery);

mysqli_stmt_close($stmt);

/* =========================================
   DASHBOARD STATISTICS
========================================= */

/* All Users */
$totalUsers = getCount(
    $conn,
    "SELECT COUNT(*) AS total FROM users"
);

/* Students */
$totalStudents = getCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM users
     WHERE LOWER(TRIM(role)) = 'student'"
);

/* Teachers */
$totalTeachers = getCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM users
     WHERE LOWER(TRIM(role)) = 'teacher'"
);

/* Directors */
$totalDirectors = getCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM users
     WHERE LOWER(TRIM(role)) = 'director'"
);

/* Program Chairs */
$totalProgramChairs = getCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM users
     WHERE REPLACE(
         REPLACE(LOWER(TRIM(role)), ' ', '_'),
         '-', '_'
     ) = 'program_chair'"
);

/* Courses / Classes */
$totalClasses = getCount(
    $conn,
    "SELECT COUNT(*) AS total FROM classes"
);

/* =========================================
   RECENT ACTIVITY
   CENTRALIZED ACTIVITY LOGS
========================================= */

$activities = [];

$activityQuery = mysqli_query(
    $conn,
    "SELECT
        id,
        user_id,
        user_name,
        user_role,
        action,
        description,
        created_at
     FROM activity_logs
     ORDER BY created_at DESC, id DESC
     LIMIT 8"
);

if ($activityQuery) {

    while ($row = mysqli_fetch_assoc($activityQuery)) {

        $userName = trim((string)($row['user_name'] ?? 'Unknown User'));

        if ($userName === '') {
            $userName = 'Unknown User';
        }

        $role = getRoleLabel($row['user_role'] ?? '');

        $action = trim((string)($row['action'] ?? 'Activity'));

        $description = trim((string)($row['description'] ?? ''));

        /*
         * Build readable activity text.
         * Example:
         * John Doe (Teacher) - Created a new class.
         */

        $activityText = $userName;

        if ($role !== '') {
            $activityText .= ' (' . $role . ')';
        }

        $activityText .= ' - ' . $action;

        if ($description !== '') {
            $activityText .= ': ' . $description;
        }

        $activities[] = [
            'icon' => getActivityIcon($action),
            'text' => $activityText,
            'date' => $row['created_at'] ?? ''
        ];
    }

} else {

    // Keep the dashboard working if the activity query fails.
    error_log("Activity log query failed: " . mysqli_error($conn));

}

/* =========================================
   PROFILE IMAGE
========================================= */

$profileImage = $admin['profile_image'] ?? '';

$hasProfileImage = (
    !empty($profileImage) &&
    $profileImage !== 'default.png' &&
    file_exists('uploads/' . $profileImage)
);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Dashboard | PLMUN Classroom</title>

    <link rel="shortcut icon"
          href="img/plmun logo.png"
          type="image/x-icon">

    <link rel="stylesheet" href="admin_dashboard.css">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

</head>

<body>


<!-- =========================================
     ADMIN SIDEBAR
========================================= -->

<div class="admin-sidebar" id="adminSidebar">

    <!-- SIDEBAR HEADER -->
    <div class="admin-sidebar-header">
        <h1>ADMIN</h1>
    </div>

    <!-- SIDEBAR MENU -->
    <ul class="admin-menu">

        <li>
            <a href="admin_dashboard.php" class="active">
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


<!-- =========================================
     MAIN CONTENT
========================================= -->

<div class="admin-main" id="adminMain">

    <!-- TOPBAR -->

    <header class="admin-topbar">

        <div class="admin-top-left">

            <button class="admin-mobile-btn"
                    type="button"
                    onclick="toggleAdminSidebar()">

                <i class="fa-solid fa-bars"></i>

            </button>

            <img src="img/classroom logo.png"
                 alt="PLMUN Classroom"
                 class="admin-logo">

        </div>

        <div class="admin-top-right">

            <div class="admin-user-name">
                <span><?php echo e($admin['name'] ?? 'Administrator'); ?></span>
            </div>

            <a href="profile.php" class="admin-profile">

                <?php if ($hasProfileImage): ?>

                    <img src="uploads/<?php echo e($profileImage); ?>"
                         alt="Profile">

                <?php else: ?>

                    <i class="fa-solid fa-user"></i>

                <?php endif; ?>

            </a>

        </div>

    </header>


    <!-- DASHBOARD -->

    <main class="admin-content">

        <!-- PAGE HEADER -->

        <div class="admin-page-header">

            <div>
                <h2>Dashboard</h2>
                <p>System overview and administration</p>
            </div>

        </div>


        <!-- WELCOME -->

        <div class="admin-welcome">

            Welcome <?php echo e($admin['name'] ?? 'Admin'); ?>!

        </div>


        <!-- =====================================
             STATISTICS
        ====================================== -->

        <div class="admin-stats">

            <!-- ALL USERS -->

            <div class="admin-stat-card purple">

                <div class="admin-stat-info">

                    <span class="admin-stat-label">All Users</span>

                    <strong><?php echo number_format($totalUsers); ?></strong>

                </div>

                <div class="admin-stat-icon">
                    <i class="fa-solid fa-users"></i>
                </div>

            </div>


            <!-- STUDENTS -->

            <div class="admin-stat-card green">

                <div class="admin-stat-info">

                    <span class="admin-stat-label">Students</span>

                    <strong><?php echo number_format($totalStudents); ?></strong>

                </div>

                <div class="admin-stat-icon">
                    <i class="fa-solid fa-user-graduate"></i>
                </div>

            </div>


            <!-- TEACHERS -->

            <div class="admin-stat-card orange">

                <div class="admin-stat-info">

                    <span class="admin-stat-label">Teachers</span>

                    <strong><?php echo number_format($totalTeachers); ?></strong>

                </div>

                <div class="admin-stat-icon">
                    <i class="fa-solid fa-chalkboard-user"></i>
                </div>

            </div>


            <!-- DIRECTORS -->

            <div class="admin-stat-card red">

                <div class="admin-stat-info">

                    <span class="admin-stat-label">Directors</span>

                    <strong><?php echo number_format($totalDirectors); ?></strong>

                </div>

                <div class="admin-stat-icon">
                    <i class="fa-solid fa-user-tie"></i>
                </div>

            </div>


            <!-- PROGRAM CHAIRS -->

            <div class="admin-stat-card teal">

                <div class="admin-stat-info">

                    <span class="admin-stat-label">Program Chairs</span>

                    <strong><?php echo number_format($totalProgramChairs); ?></strong>

                </div>

                <div class="admin-stat-icon">
                    <i class="fa-solid fa-user-gear"></i>
                </div>

            </div>


            <!-- COURSES / CLASSES -->

            <div class="admin-stat-card blue">

                <div class="admin-stat-info">

                    <span class="admin-stat-label">Courses / Classes</span>

                    <strong><?php echo number_format($totalClasses); ?></strong>

                </div>

                <div class="admin-stat-icon">
                    <i class="fa-solid fa-book"></i>
                </div>

            </div>

        </div>


        <!-- =====================================
             RECENT ACTIVITY
        ====================================== -->

        <section class="recent-activity">

            <div class="section-header">

                <h3>Recent Activity</h3>

                <a href="audit_logs.php">View All</a>

            </div>


            <div class="activity-list">

                <?php if (!empty($activities)): ?>

                    <?php foreach ($activities as $activity): ?>

                        <div class="activity-item">

                            <div class="activity-icon">

                                <i class="fa-solid <?php echo e($activity['icon']); ?>"></i>

                            </div>

                            <div class="activity-details">

                                <p><?php echo e($activity['text']); ?></p>

                                <?php if (!empty($activity['date'])): ?>

                                    <small>
                                        <?php
                                        $timestamp = strtotime($activity['date']);

                                        if ($timestamp !== false) {
                                            echo e(date('M d, Y h:i A', $timestamp));
                                        } else {
                                            echo 'Date unavailable';
                                        }
                                        ?>
                                    </small>

                                <?php endif; ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="no-activity">

                        <i class="fa-regular fa-clock"></i>

                        <p>No recent activity.</p>

                    </div>

                <?php endif; ?>

            </div>

        </section>

    </main>

</div>


<!-- =========================================
     JAVASCRIPT
========================================= -->

<script>

function toggleAdminSidebar() {

    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.getElementById('adminOverlay');

    sidebar.classList.toggle('show');
    overlay.classList.toggle('show');

}

function closeAdminSidebar() {

    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.getElementById('adminOverlay');

    sidebar.classList.remove('show');
    overlay.classList.remove('show');

}

window.addEventListener('resize', function() {

    if (window.innerWidth > 768) {
        closeAdminSidebar();
    }

});

</script>

</body>
</html>
