
<?php
session_start();
require_once 'database.php';

/* =====================================================
   ADMIN ACCESS CONTROL
===================================================== */

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.html");
    exit();
}

$admin_id = (int) $_SESSION['user_id'];

/* =====================================================
   HELPER FUNCTIONS
===================================================== */

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function formatBytes($bytes) {
    $bytes = (int)$bytes;

    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    }

    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }

    return $bytes . ' bytes';
}

function getCount($conn, $sql) {
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        return 0;
    }

    $row = mysqli_fetch_assoc($result);

    return (int)($row['total'] ?? 0);
}

/* =====================================================
   PRIVATE BACKUP DIRECTORY

   Stored outside the project directory.
   PHP handles authenticated downloads.
===================================================== */

$backupDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'plmun_private_backups';

if (!is_dir($backupDirectory)) {
    if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
        error_log("Unable to create backup directory.");
    }
}

if (is_dir($backupDirectory)) {
    @chmod($backupDirectory, 0700);
}

/* =====================================================
   CREATE REQUIRED TABLES
===================================================== */

$createBackupTable = "
CREATE TABLE IF NOT EXISTS admin_backups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    file_size BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
";

$createSecurityTable = "
CREATE TABLE IF NOT EXISTS admin_security_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NULL,
    action VARCHAR(150) NOT NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (admin_id),
    INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
";

mysqli_query($conn, $createBackupTable);
mysqli_query($conn, $createSecurityTable);

/* =====================================================
   CSRF TOKEN
===================================================== */

if (empty($_SESSION['backup_csrf'])) {
    $_SESSION['backup_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['backup_csrf'];

/* =====================================================
   SECURITY LOG FUNCTION
===================================================== */

function recordSecurityLog($conn, $admin_id, $action, $details = '') {

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO admin_security_logs
        (admin_id, action, details, ip_address)
        VALUES (?, ?, ?, ?)"
    );

    if ($stmt) {
        mysqli_stmt_bind_param(
            $stmt,
            "isss",
            $admin_id,
            $action,
            $details,
            $ip
        );

        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

/* =====================================================
   GET ADMIN INFORMATION
===================================================== */

$stmt = mysqli_prepare(
    $conn,
    "SELECT * FROM users WHERE id = ? LIMIT 1"
);

mysqli_stmt_bind_param($stmt, "i", $admin_id);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$admin = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);

if (!$admin) {
    session_destroy();
    header("Location: login.html");
    exit();
}

/* =====================================================
   PROFILE IMAGE
===================================================== */

$profileImage = $admin['profile_image'] ?? '';

$hasProfileImage = (
    !empty($profileImage) &&
    !in_array($profileImage, [
        'default.png',
        'default.jpg',
        'default.jpeg',
        'default.svg'
    ], true) &&
    is_file('uploads/' . basename($profileImage))
);

/* =====================================================
   DOWNLOAD EXISTING BACKUP

   Only registered backup files can be downloaded.
===================================================== */

if (isset($_GET['download'])) {

    $backup_id = filter_var(
        $_GET['download'],
        FILTER_VALIDATE_INT
    );

    if (!$backup_id || !is_dir($backupDirectory)) {
        http_response_code(404);
        exit('Backup not found.');
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT filename FROM admin_backups WHERE id = ? LIMIT 1"
    );

    mysqli_stmt_bind_param($stmt, "i", $backup_id);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $backup = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    if (!$backup) {
        http_response_code(404);
        exit('Backup not found.');
    }

    $filename = basename($backup['filename']);
    $filepath = $backupDirectory . DIRECTORY_SEPARATOR . $filename;

    if (!is_file($filepath) || !is_readable($filepath)) {
        http_response_code(404);
        exit('Backup file is unavailable.');
    }

    recordSecurityLog(
        $conn,
        $admin_id,
        'Backup Downloaded',
        'Downloaded backup: ' . $filename
    );

    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filepath));
    header('X-Content-Type-Options: nosniff');

    readfile($filepath);
    exit();
}

/* =====================================================
   CREATE DATABASE BACKUP
===================================================== */

$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_backup'])) {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($csrfToken, $_POST['csrf_token'])
    ) {
        $errorMessage = "Invalid security token. Please refresh the page.";
    } elseif (!is_dir($backupDirectory) || !is_writable($backupDirectory)) {
        $errorMessage = "Backup directory is unavailable or not writable.";
    } else {

        $databaseResult = mysqli_query($conn, "SELECT DATABASE() AS db_name");
        $databaseRow = $databaseResult ? mysqli_fetch_assoc($databaseResult) : null;
        $databaseName = $databaseRow['db_name'] ?? '';

        if ($databaseName === '') {
            $errorMessage = "Unable to identify the database.";
        } else {

            $filename = 'PLMUN_Backup_' . date('Y-m-d_H-i-s') .
                        '_' . bin2hex(random_bytes(4)) . '.sql';

            $filepath = $backupDirectory . DIRECTORY_SEPARATOR . $filename;

            $file = @fopen($filepath, 'xb');

            if (!$file) {
                $errorMessage = "Unable to create backup file.";
            } else {

                $backupSuccessful = true;

                $header = "-- PLMUN CLASSROOM DATABASE BACKUP\n";
                $header .= "-- Database: " . $databaseName . "\n";
                $header .= "-- Created: " . date('Y-m-d H:i:s') . "\n\n";
                $header .= "SET FOREIGN_KEY_CHECKS=0;\n";
                $header .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";

                fwrite($file, $header);

                /* GET DATABASE TABLES */

                $tablesResult = mysqli_query($conn, "SHOW FULL TABLES");

                if (!$tablesResult) {
                    $backupSuccessful = false;
                } else {

                    while ($tableRow = mysqli_fetch_row($tablesResult)) {

                        $tableName = $tableRow[0];
                        $tableType = $tableRow[1] ?? 'BASE TABLE';

                        // Export regular tables only.
                        if (strtoupper($tableType) !== 'BASE TABLE') {
                            continue;
                        }

                        $safeTable = '`' .
                            str_replace('`', '``', $tableName) .
                            '`';

                        /* TABLE STRUCTURE */

                        $createResult = mysqli_query(
                            $conn,
                            "SHOW CREATE TABLE " . $safeTable
                        );

                        if (!$createResult) {
                            $backupSuccessful = false;
                            break;
                        }

                        $createRow = mysqli_fetch_assoc($createResult);
                        $createSQL = $createRow['Create Table'] ?? '';

                        if ($createSQL === '') {
                            $backupSuccessful = false;
                            break;
                        }

                        fwrite($file, "\n\n-- TABLE: " . $tableName . "\n");
                        fwrite($file, "DROP TABLE IF EXISTS " . $safeTable . ";\n");
                        fwrite($file, $createSQL . ";\n\n");

                        /* TABLE DATA */

                        $dataResult = mysqli_query(
                            $conn,
                            "SELECT * FROM " . $safeTable
                        );

                        if (!$dataResult) {
                            $backupSuccessful = false;
                            break;
                        }

                        while ($dataRow = mysqli_fetch_assoc($dataResult)) {

                            $columns = [];
                            $values = [];

                            foreach ($dataRow as $column => $value) {

                                $columns[] = '`' .
                                    str_replace('`', '``', $column) .
                                    '`';

                                if ($value === null) {
                                    $values[] = 'NULL';
                                } else {
                                    $values[] = "'" .
                                        mysqli_real_escape_string($conn, (string)$value) .
                                        "'";
                                }
                            }

                            $insertSQL =
                                "INSERT INTO " . $safeTable .
                                " (" . implode(', ', $columns) . ") VALUES (" .
                                implode(', ', $values) . ");\n";

                            if (fwrite($file, $insertSQL) === false) {
                                $backupSuccessful = false;
                                break 2;
                            }
                        }

                        mysqli_free_result($dataResult);
                    }

                    mysqli_free_result($tablesResult);
                }

                fwrite($file, "\nSET FOREIGN_KEY_CHECKS=1;\n");
                fclose($file);

                if (!$backupSuccessful || !is_file($filepath) || filesize($filepath) === 0) {

                    @unlink($filepath);

                    $errorMessage = "Database backup failed. Check the database permissions and PHP error log.";

                } else {

                    $fileSize = filesize($filepath);

                    $stmt = mysqli_prepare(
                        $conn,
                        "INSERT INTO admin_backups
                        (admin_id, filename, file_size)
                        VALUES (?, ?, ?)"
                    );

                    if (!$stmt) {

                        @unlink($filepath);
                        $errorMessage = "Unable to save backup history.";

                    } else {

                        mysqli_stmt_bind_param(
                            $stmt,
                            "isi",
                            $admin_id,
                            $filename,
                            $fileSize
                        );

                        $saved = mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);

                        if (!$saved) {

                            @unlink($filepath);
                            $errorMessage = "Unable to register the backup.";

                        } else {

                            recordSecurityLog(
                                $conn,
                                $admin_id,
                                'Database Backup Created',
                                'Created database backup: ' . $filename
                            );

                            $successMessage = "Database backup created successfully!";
                        }
                    }
                }
            }
        }
    }
}

/* =====================================================
   DASHBOARD STATISTICS
===================================================== */

$totalBackups = getCount(
    $conn,
    "SELECT COUNT(*) AS total FROM admin_backups"
);

$totalSecurityLogs = getCount(
    $conn,
    "SELECT COUNT(*) AS total FROM admin_security_logs"
);

$totalUsers = getCount(
    $conn,
    "SELECT COUNT(*) AS total FROM users"
);

$totalClasses = getCount(
    $conn,
    "SELECT COUNT(*) AS total FROM classes"
);

/* =====================================================
   BACKUP HISTORY
===================================================== */

$backups = [];

$backupQuery = mysqli_query(
    $conn,
    "SELECT b.*, u.name AS admin_name
     FROM admin_backups b
     LEFT JOIN users u ON b.admin_id = u.id
     ORDER BY b.created_at DESC
     LIMIT 20"
);

if ($backupQuery) {
    while ($row = mysqli_fetch_assoc($backupQuery)) {
        $backups[] = $row;
    }
}

/* =====================================================
   SECURITY ACTIVITY
===================================================== */

$securityLogs = [];

$securityQuery = mysqli_query(
    $conn,
    "SELECT l.*, u.name AS admin_name
     FROM admin_security_logs l
     LEFT JOIN users u ON l.admin_id = u.id
     ORDER BY l.created_at DESC
     LIMIT 15"
);

if ($securityQuery) {
    while ($row = mysqli_fetch_assoc($securityQuery)) {
        $securityLogs[] = $row;
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Backup & Security | PLMUN Classroom</title>

    <link rel="shortcut icon" href="img/plmun logo.png">

    <link rel="stylesheet" href="admin_dashboard.css">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>

/* =====================================================
   BACKUP & SECURITY CONTENT
===================================================== */

.admin-content {
    padding: 27px 30px 40px;
}

.backup-header {
    background: #e7e7e7;
    border: 1px solid #d9d9d9;
    padding: 16px 18px;
    margin-bottom: 25px;
}

.backup-header h2 {
    font-size: 23px;
    font-weight: 700;
    color: #5e5e5e;
    margin: 0;
}

.backup-header p {
    margin: 5px 0 0;
    color: #777;
    font-size: 14px;
}

.backup-alert {
    border-radius: 4px;
    padding: 13px 16px;
    margin-bottom: 20px;
}

.backup-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 18px;
    margin-bottom: 25px;
}

.backup-stat {
    background: #fff;
    border: 1px solid #e2e2e2;
    padding: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-width: 0;
}

.backup-stat-info {
    min-width: 0;
}

.backup-stat-info span {
    display: block;
    font-size: 13px;
    color: #777;
    margin-bottom: 7px;
}

.backup-stat-info strong {
    font-size: 27px;
    color: #252d4b;
    font-weight: 700;
}

.backup-stat-icon {
    width: 45px;
    height: 45px;
    background: #eef4f8;
    color: #3f87b5;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 19px;
    flex-shrink: 0;
}

.backup-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.2fr) minmax(260px, .8fr);
    gap: 20px;
    margin-bottom: 25px;
}

.backup-panel {
    background: #fff;
    border: 1px solid #dedede;
    min-width: 0;
}

.backup-panel-header {
    padding: 17px 20px;
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
}

.backup-panel-header h3 {
    font-size: 17px;
    font-weight: 700;
    margin: 0;
    color: #252d4b;
}

.backup-panel-body {
    padding: 22px;
}

.backup-description {
    font-size: 14px;
    color: #777;
    line-height: 1.7;
}

.backup-main-icon {
    width: 60px;
    height: 60px;
    background: #edf5fa;
    color: #3f87b5;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 27px;
    margin-bottom: 16px;
    border-radius: 8px;
}

.btn-backup {
    background: #3f87b5;
    color: #fff;
    border: none;
    padding: 11px 19px;
    border-radius: 4px;
    font-size: 14px;
    font-weight: 600;
}

.btn-backup:hover {
    background: #306f98;
    color: #fff;
}

.backup-note {
    background: #f5f8fa;
    border-left: 3px solid #3f87b5;
    padding: 12px 14px;
    font-size: 13px;
    color: #666;
    margin-top: 18px;
}

.security-item {
    display: flex;
    align-items: flex-start;
    gap: 13px;
    padding: 12px 0;
    border-bottom: 1px solid #eee;
}

.security-item:last-child {
    border-bottom: none;
}

.security-item i {
    width: 35px;
    height: 35px;
    border-radius: 50%;
    background: #edf5fa;
    color: #3f87b5;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.security-item strong {
    display: block;
    font-size: 13px;
    color: #333;
}

.security-item small {
    color: #888;
    font-size: 12px;
}

.backup-table-wrapper {
    width: 100%;
    overflow-x: auto;
}

.backup-table {
    width: 100%;
    min-width: 650px;
    border-collapse: collapse;
}

.backup-table th {
    background: #f7f8fa;
    color: #555;
    font-size: 12px;
    text-transform: uppercase;
    padding: 14px 16px;
    text-align: left;
    white-space: nowrap;
}

.backup-table td {
    padding: 14px 16px;
    font-size: 13px;
    color: #555;
    border-top: 1px solid #eee;
    vertical-align: middle;
}

.backup-table tr:hover td {
    background: #fafbfc;
}

.backup-file {
    display: flex;
    align-items: center;
    gap: 10px;
}

.backup-file i {
    font-size: 22px;
    color: #d66b6b;
}

.backup-file strong {
    display: block;
    color: #333;
    font-size: 13px;
    overflow-wrap: anywhere;
}

.backup-file small {
    color: #999;
    font-size: 11px;
}

.download-btn {
    color: #3f87b5;
    border: 1px solid #3f87b5;
    padding: 6px 10px;
    border-radius: 3px;
    text-decoration: none;
    font-size: 12px;
    display: inline-block;
    white-space: nowrap;
}

.download-btn:hover {
    background: #3f87b5;
    color: #fff;
}

.security-log-icon {
    color: #3f87b5;
    margin-right: 7px;
}

.empty-backup {
    text-align: center;
    padding: 35px 15px;
    color: #999;
}

.empty-backup i {
    font-size: 30px;
    margin-bottom: 10px;
}

.status-label {
    display: inline-block;
    background: #e6f5ec;
    color: #27834c;
    padding: 5px 9px;
    font-size: 11px;
    border-radius: 3px;
    font-weight: 600;
}

/* =====================================================
   RESPONSIVE
===================================================== */

@media(max-width: 1100px) {

    .backup-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .backup-grid {
        grid-template-columns: 1fr;
    }

}

@media(max-width: 768px) {

    .admin-content {
        padding: 18px 15px 30px;
    }

    .backup-stats {
        grid-template-columns: 1fr;
        gap: 12px;
    }

    .backup-stat {
        padding: 16px;
    }

    .backup-panel-body {
        padding: 17px;
    }

    .backup-header h2 {
        font-size: 20px;
    }

}

@media(max-width: 480px) {

    .backup-panel-header {
        padding: 14px;
    }

    .backup-panel-header h3 {
        font-size: 15px;
    }

    .backup-panel-body {
        padding: 14px;
    }

    .btn-backup {
        width: 100%;
    }

}

    </style>

</head>

<body>

<!-- =====================================================
     SIDEBAR
===================================================== -->

<div class="admin-sidebar" id="adminSidebar">

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
                <i class="fa-solid fa-wave-square"></i>
                <span>Reports &amp; Analytics</span>
            </a>
        </li>

        <li>
            <a href="admin_logs.php">
                <i class="fa-regular fa-clock"></i>
                <span>Logs &amp; Activity</span>
            </a>
        </li>

        <li>
            <a href="admin_backup_security.php" class="active">
                <i class="fa-solid fa-shield-halved"></i>
                <span>Backup &amp; Security</span>
            </a>
        </li>

    </ul>

    <div class="admin-logout">
        <a href="logout.php">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Log out</span>
        </a>
    </div>

</div>

<div class="admin-overlay"
     id="adminOverlay"
     onclick="closeAdminSidebar()"></div>


<!-- =====================================================
     MAIN CONTENT
===================================================== -->

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

                    <img src="uploads/<?php echo e(basename($profileImage)); ?>"
                         alt="Profile">

                <?php else: ?>

                    <i class="fa-solid fa-user"></i>

                <?php endif; ?>

            </a>

        </div>

    </header>


    <!-- =================================================
         PAGE CONTENT
    ================================================== -->

    <main class="admin-content">

        <div class="backup-header">

            <h2>Backup &amp; Security</h2>

            <p>Manage database backups and monitor administrative security activities.</p>

        </div>


        <!-- ALERTS -->

        <?php if ($successMessage !== ''): ?>

            <div class="alert alert-success backup-alert">
                <i class="fa-solid fa-circle-check me-2"></i>
                <?php echo e($successMessage); ?>
            </div>

        <?php endif; ?>

        <?php if ($errorMessage !== ''): ?>

            <div class="alert alert-danger backup-alert">
                <i class="fa-solid fa-circle-exclamation me-2"></i>
                <?php echo e($errorMessage); ?>
            </div>

        <?php endif; ?>


        <!-- STATISTICS -->

        <div class="backup-stats">

            <div class="backup-stat">

                <div class="backup-stat-info">
                    <span>Total Backups</span>
                    <strong><?php echo number_format($totalBackups); ?></strong>
                </div>

                <div class="backup-stat-icon">
                    <i class="fa-solid fa-database"></i>
                </div>

            </div>


            <div class="backup-stat">

                <div class="backup-stat-info">
                    <span>Security Activities</span>
                    <strong><?php echo number_format($totalSecurityLogs); ?></strong>
                </div>

                <div class="backup-stat-icon">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>

            </div>


            <div class="backup-stat">

                <div class="backup-stat-info">
                    <span>Total Users</span>
                    <strong><?php echo number_format($totalUsers); ?></strong>
                </div>

                <div class="backup-stat-icon">
                    <i class="fa-solid fa-users"></i>
                </div>

            </div>


            <div class="backup-stat">

                <div class="backup-stat-info">
                    <span>Total Classes</span>
                    <strong><?php echo number_format($totalClasses); ?></strong>
                </div>

                <div class="backup-stat-icon">
                    <i class="fa-solid fa-book"></i>
                </div>

            </div>

        </div>


        <!-- BACKUP AND SECURITY PANELS -->

        <div class="backup-grid">


            <!-- DATABASE BACKUP -->

            <section class="backup-panel">

                <div class="backup-panel-header">

                    <h3>
                        <i class="fa-solid fa-database me-2"></i>
                        Database Backup
                    </h3>

                    <span class="status-label">Available</span>

                </div>

                <div class="backup-panel-body">

                    <div class="backup-main-icon">
                        <i class="fa-solid fa-cloud-arrow-down"></i>
                    </div>

                    <h5 class="fw-bold">Create Database Backup</h5>

                    <p class="backup-description">
                        Generate a downloadable SQL backup containing the
                        structure and records of your PLMUN Classroom
                        database tables.
                    </p>

                    <form method="POST"
                          onsubmit="return confirmBackup();">

                        <input type="hidden"
                               name="csrf_token"
                               value="<?php echo e($csrfToken); ?>">

                        <button type="submit"
                                name="create_backup"
                                value="1"
                                class="btn btn-backup">

                            <i class="fa-solid fa-download me-2"></i>
                            Create Backup Now

                        </button>

                    </form>

                    <div class="backup-note">

                        <i class="fa-solid fa-circle-info me-2"></i>

                        Backups are stored outside the project directory
                        and can only be downloaded by an authenticated admin.

                    </div>

                </div>

            </section>


            <!-- SECURITY OVERVIEW -->

            <section class="backup-panel">

                <div class="backup-panel-header">

                    <h3>
                        <i class="fa-solid fa-shield-halved me-2"></i>
                        Security Overview
                    </h3>

                </div>

                <div class="backup-panel-body">

                    <div class="security-item">

                        <i class="fa-solid fa-lock"></i>

                        <div>
                            <strong>Admin Authentication</strong>
                            <small>Administrator role verification enabled.</small>
                        </div>

                    </div>


                    <div class="security-item">

                        <i class="fa-solid fa-key"></i>

                        <div>
                            <strong>CSRF Protection</strong>
                            <small>Backup actions require a valid security token.</small>
                        </div>

                    </div>


                    <div class="security-item">

                        <i class="fa-solid fa-database"></i>

                        <div>
                            <strong>Private Backup Storage</strong>
                            <small>Backup files are stored outside the project directory.</small>
                        </div>

                    </div>


                    <div class="security-item">

                        <i class="fa-solid fa-clipboard-list"></i>

                        <div>
                            <strong>Security Activity Logging</strong>
                            <small>Backup creation and downloads are recorded.</small>
                        </div>

                    </div>

                </div>

            </section>

        </div>


        <!-- BACKUP HISTORY -->

        <section class="backup-panel mb-4">

            <div class="backup-panel-header">

                <h3>
                    <i class="fa-solid fa-clock-rotate-left me-2"></i>
                    Backup History
                </h3>

                <span class="text-muted small">
                    Latest 20 backups
                </span>

            </div>

            <div class="backup-table-wrapper">

                <table class="backup-table">

                    <thead>

                        <tr>
                            <th>Backup File</th>
                            <th>Created By</th>
                            <th>Date Created</th>
                            <th>File Size</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php if (!empty($backups)): ?>

                            <?php foreach ($backups as $backup): ?>

                                <?php
                                $backupExists = (
                                    is_dir($backupDirectory) &&
                                    is_file(
                                        $backupDirectory . DIRECTORY_SEPARATOR .
                                        basename($backup['filename'])
                                    )
                                );
                                ?>

                                <tr>

                                    <td>

                                        <div class="backup-file">

                                            <i class="fa-solid fa-file-code"></i>

                                            <div>
                                                <strong>
                                                    <?php echo e($backup['filename']); ?>
                                                </strong>

                                                <small>SQL Database Backup</small>
                                            </div>

                                        </div>

                                    </td>

                                    <td>
                                        <?php echo e($backup['admin_name'] ?? 'Unknown'); ?>
                                    </td>

                                    <td>
                                        <?php
                                        $date = strtotime($backup['created_at']);

                                        echo $date
                                            ? e(date('M d, Y h:i A', $date))
                                            : 'Unavailable';
                                        ?>
                                    </td>

                                    <td>
                                        <?php echo e(formatBytes($backup['file_size'])); ?>
                                    </td>

                                    <td>

                                        <?php if ($backupExists): ?>

                                            <span class="status-label">Available</span>

                                        <?php else: ?>

                                            <span class="badge bg-danger">Missing</span>

                                        <?php endif; ?>

                                    </td>

                                    <td>

                                        <?php if ($backupExists): ?>

                                            <a href="admin_backup_security.php?download=<?php echo (int)$backup['id']; ?>"
                                               class="download-btn">

                                                <i class="fa-solid fa-download me-1"></i>
                                                Download

                                            </a>

                                        <?php else: ?>

                                            <span class="text-muted small">Unavailable</span>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="6">

                                    <div class="empty-backup">

                                        <i class="fa-solid fa-database"></i>

                                        <p class="mb-0">No database backups found.</p>

                                        <small>Create your first backup above.</small>

                                    </div>

                                </td>
                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>


        <!-- SECURITY LOGS -->

        <section class="backup-panel">

            <div class="backup-panel-header">

                <h3>
                    <i class="fa-solid fa-shield-halved me-2"></i>
                    Security Activity Logs
                </h3>

                <span class="text-muted small">
                    Latest 15 activities
                </span>

            </div>

            <div class="backup-table-wrapper">

                <table class="backup-table">

                    <thead>

                        <tr>
                            <th>Activity</th>
                            <th>Administrator</th>
                            <th>Details</th>
                            <th>IP Address</th>
                            <th>Date &amp; Time</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php if (!empty($securityLogs)): ?>

                            <?php foreach ($securityLogs as $log): ?>

                                <tr>

                                    <td>
                                        <i class="fa-solid fa-shield-halved security-log-icon"></i>
                                        <?php echo e($log['action']); ?>
                                    </td>

                                    <td>
                                        <?php echo e($log['admin_name'] ?? 'Unknown'); ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($log['details'] ?? 'No details available', ENT_QUOTES, 'UTF-8') ?>
                                    </td>

                                    <td>
                                        <?php echo e($log['ip_address']); ?>
                                    </td>

                                    <td>
                                        <?php
                                        $date = strtotime($log['created_at']);

                                        echo $date
                                            ? e(date('M d, Y h:i A', $date))
                                            : 'Unavailable';
                                        ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="5">

                                    <div class="empty-backup">

                                        <i class="fa-solid fa-shield-halved"></i>

                                        <p class="mb-0">No security activities recorded yet.</p>

                                    </div>

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
     JAVASCRIPT
===================================================== -->

<script>

function toggleAdminSidebar() {

    document.getElementById('adminSidebar').classList.toggle('show');

    document.getElementById('adminOverlay').classList.toggle('show');

}

function closeAdminSidebar() {

    document.getElementById('adminSidebar').classList.remove('show');

    document.getElementById('adminOverlay').classList.remove('show');

}

function confirmBackup() {

    return confirm(
        'Are you sure you want to create a new PLMUN Classroom database backup?'
    );

}

window.addEventListener('resize', function() {

    if (window.innerWidth > 768) {
        closeAdminSidebar();
    }

});

</script>

</body>
</html>
