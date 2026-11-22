<?php
session_start();
require_once 'database.php';

/* Admin-only access */
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.html");
    exit();
}

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$adminName = $_SESSION['name'] ?? 'Administrator';
$adminId = (int) $_SESSION['user_id'];

function tableExists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("SHOW TABLES LIKE ?");
    if (!$stmt) return false;
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    $stmt->close();
    return $exists;
}

function countRows(mysqli $conn, string $sql): int {
    $result = $conn->query($sql);
    if (!$result) return 0;
    $row = $result->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

$hasActivityLogs = tableExists($conn, 'activity_logs');
$hasLoginAttempts = tableExists($conn, 'security_login_attempts');

$totalLogs = $hasActivityLogs
    ? countRows($conn, "SELECT COUNT(*) AS total FROM activity_logs")
    : 0;

$todayLogs = $hasActivityLogs
    ? countRows($conn, "SELECT COUNT(*) AS total FROM activity_logs WHERE DATE(created_at) = CURDATE()")
    : 0;

$failedLoginsToday = $hasLoginAttempts
    ? countRows($conn, "SELECT COUNT(*) AS total FROM security_login_attempts WHERE success = 0 AND DATE(attempted_at) = CURDATE()")
    : 0;

$successfulLoginsToday = $hasLoginAttempts
    ? countRows($conn, "SELECT COUNT(*) AS total FROM security_login_attempts WHERE success = 1 AND DATE(attempted_at) = CURDATE()")
    : 0;

$recentLogs = [];
if ($hasActivityLogs) {
    $result = $conn->query("SELECT id, user_name, user_role, action, description, ip_address, created_at
                            FROM activity_logs ORDER BY created_at DESC LIMIT 8");
    if ($result) {
        while ($row = $result->fetch_assoc()) $recentLogs[] = $row;
    }
}

$recentFailed = [];
if ($hasLoginAttempts) {
    $stmt = $conn->prepare("SELECT identifier, ip_address, attempted_at FROM security_login_attempts
                            WHERE success = 0 ORDER BY attempted_at DESC LIMIT 6");
    if ($stmt && $stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $recentFailed[] = $row;
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Overview | PLMUN Classroom</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <style>
       /* =========================================
   GLOBAL
========================================= */

*,
*::before,
*::after {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    background: #f7f7f7;
    font-family: 'Segoe UI', Arial, sans-serif;
    color: #222;
    overflow-x: hidden;
}

/* =========================================
   ADMIN SIDEBAR - MATCHES SECURITY PAGES
========================================= */

.admin-sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 274px;
    height: 100vh;
    background: #252d4b;
    color: #fff;
    z-index: 1050;
    display: flex;
    flex-direction: column;
    padding: 22px 16px;
    overflow-y: auto;
    overflow-x: hidden;
    transition: transform 0.3s ease;
}

/* SIDEBAR HEADER */

.admin-sidebar-header {
    min-height: auto;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 10px 18px;
    margin-bottom: 10px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.12);
}

.admin-sidebar-header h1 {
    color: #fff;
    font-size: 30px;
    font-weight: 700;
    letter-spacing: 0;
    margin: 0;
}

/* SECTION LABELS - used for Main Menu and Security */

.side-label {
    display: block;
    padding: 13px 12px 7px;
    color: #aab2ca;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.2px;
}

/* SIDEBAR MENU */

.admin-menu {
    list-style: none;
    padding: 0;
    margin: 0;
    flex: 0 0 auto;
}

.admin-menu li {
    margin: 3px 0;
    padding: 0;
}

.admin-menu li a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 12px;
    color: #dce2f2;
    text-decoration: none;
    font-size: 13px;
    font-weight: 400;
    border-radius: 9px;
    transition: background 0.2s ease, color 0.2s ease;
}

.admin-menu li a i {
    width: 18px;
    min-width: 18px;
    text-align: center;
    font-size: 15px;
}

.admin-menu li a span {
    min-width: 0;
}

.admin-menu li a:hover,
.admin-menu li a.active {
    background: rgba(255, 255, 255, 0.10);
    color: #fff;
}

/* LOGOUT */

.admin-logout {
    padding: 15px 0 0;
    margin-top: auto;
    border-top: 1px solid rgba(255, 255, 255, 0.12);
}

.admin-logout a {
    display: flex;
    align-items: center;
    gap: 12px;
    color: #dce2f2;
    text-decoration: none;
    border: 0;
    border-radius: 9px;
    padding: 11px 12px;
    font-size: 13px;
    font-weight: 400;
    transition: background 0.2s ease, color 0.2s ease;
}

.admin-logout a i {
    width: 18px;
    min-width: 18px;
    text-align: center;
    font-size: 15px;
}

.admin-logout a:hover {
    background: rgba(255, 255, 255, 0.10);
    color: #fff;
}
        .admin-main {
        margin-left:
        274px;min-height:100vh
        }
        .admin-topbar {
            height:72px;
            background:#fff;
            border-bottom:1px solid #e8ebf2;
            display:flex;
            align-items:center;
            justify-content:space-between;
            padding:0 30px
        }
        .admin-topbar .right {
            display:flex;
            align-items:center;
            gap:15px
        }
        .admin-topbar a {
            color:#29324b;
            text-decoration:none
        }
        .content {padding:30px}
        .page-title{
            font-size:24px;
            font-weight:700
            ;margin:0
        }
        .sub-title{
            color:var(--muted);
            font-size:13px;
            margin-top:6px
            }
        .stat-card{background:#fff;border:1px solid #e9edf4;border-radius:14px;padding:20px;height:100%;box-shadow:0 3px 12px #25304b08}
        .stat-icon{width:43px;height:43px;border-radius:12px;display:grid;place-items:center;background:#edf4fb;color:var(--blue);font-size:18px}
        .stat-number{font-size:28px;font-weight:750;margin-top:14px}
        .stat-label{font-size:12px;color:var(--muted)}
        .panel{background:#fff;border:1px solid #e9edf4;border-radius:14px;padding:20px;box-shadow:0 3px 12px #25304b08}
        .panel h2{font-size:16px;font-weight:700;margin:0 0 16px}
        .table{font-size:12px;vertical-align:middle}.table th{color:#778096;font-weight:600;white-space:nowrap}
        .pill{display:inline-block;border-radius:30px;padding:4px 9px;font-size:10px;background:#edf4fb;color:#356f9b}
        .notice{border-radius:10px;padding:13px 15px;font-size:12px}
        .notice.warning{background:#fff7e8;color:#865b13}
        .notice.info{background:#edf5fc;color:#315f83}
        .btn-primary{background:var(--blue);border-color:var(--blue)}
        .btn-primary:hover{background:#32749f;border-color:#32749f}
        .menu-toggle{display:none;border:0;background:none;font-size:20px;color:var(--navy)}
        @media(max-width:900px){.admin-sidebar{transform:translateX(-100%);transition:.25s}
        .admin-sidebar.show{transform:translateX(0)}.admin-main{margin-left:0}.menu-toggle{display:inline-block}.content{padding:20px}.admin-topbar{padding:0 18px}}
        .overlay{display:none;position:fixed;inset:0;background:#11182780;z-index:1040}
        .overlay.show{display:block}
    </style>
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
            <a href="security_overview.php" class="active">
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

<main class="admin-main">
    <header class="admin-topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" onclick="toggleSidebar()" aria-label="Open menu"><i class="fa-solid fa-bars"></i></button>
            <div><strong>Security Overview</strong><div class="text-secondary small">Monitor the security activity of PLMUN Classroom</div></div>
        </div>
        <div class="right"><span class="small text-secondary d-none d-sm-inline"><?= e($adminName) ?></span><a href="profile.php" title="Profile"><i class="fa-solid fa-circle-user fs-4"></i></a></div>
    </header>

    <section class="content">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
            <div><h1 class="page-title">Security Overview</h1><p class="sub-title">Review login activity, audit records, and important security indicators.</p></div>
            <a href="audit_logs.php" class="btn btn-primary btn-sm"><i class="fa-solid fa-clock-rotate-left me-1"></i> View Audit Logs</a>
        </div>

        <?php if (!$hasActivityLogs || !$hasLoginAttempts): ?>
        <div class="notice warning mb-4">
            <strong><i class="fa-solid fa-triangle-exclamation me-1"></i> Setup required:</strong>
            Some security tables are not available yet. Import <code>plmun_security_tables.sql</code> into your project database. Login attempt monitoring will only contain data after you add the login logging snippet to <code>login.php</code>.
        </div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-list-check"></i></div><div class="stat-number"><?= number_format($totalLogs) ?></div><div class="stat-label">Total Audit Records</div></div></div>
            <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-calendar-day"></i></div><div class="stat-number"><?= number_format($todayLogs) ?></div><div class="stat-label">Activities Today</div></div></div>
            <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-right-to-bracket"></i></div><div class="stat-number"><?= number_format($successfulLoginsToday) ?></div><div class="stat-label">Successful Logins Today</div></div></div>
            <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-shield-exclamation"></i></div><div class="stat-number"><?= number_format($failedLoginsToday) ?></div><div class="stat-label">Failed Logins Today</div></div></div>
        </div>

        <div class="row g-3">
            <div class="col-lg-7">
                <div class="panel h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="mb-0">Recent Audit Activity</h2><a class="small text-decoration-none" href="audit_logs.php">See all</a></div>
                    <?php if (!$recentLogs): ?>
                        <p class="text-secondary small mb-0">No audit activity found. Ensure the <code>activity_logs</code> table exists and your application actions are being logged.</p>
                    <?php else: ?>
                    <div class="table-responsive"><table class="table table-hover mb-0">
                        <thead><tr><th>User</th><th>Activity</th><th>Date</th></tr></thead><tbody>
                        <?php foreach ($recentLogs as $log): ?>
                        <tr>
                            <td><strong><?= e($log['user_name']) ?></strong><div class="text-secondary"><?= e($log['user_role']) ?></div></td>
                            <td><?= e($log['action']) ?><div class="text-secondary"><?= e($log['description']) ?></div></td>
                            <td class="text-nowrap"><?= e($log['created_at']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody></table></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="panel h-100">
                    <h2>Recent Failed Login Attempts</h2>
                    <?php if (!$recentFailed): ?>
                        <p class="text-secondary small mb-0">No failed login records found. Failed attempts will appear here after login monitoring is integrated.</p>
                    <?php else: ?>
                    <div class="table-responsive"><table class="table table-hover mb-0">
                        <thead><tr><th>Login Identifier</th><th>IP Address</th><th>Time</th></tr></thead><tbody>
                        <?php foreach ($recentFailed as $attempt): ?>
                        <tr><td><?= e($attempt['identifier']) ?></td><td><?= e($attempt['ip_address']) ?></td><td class="text-nowrap"><?= e($attempt['attempted_at']) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody></table></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="panel mt-3">
            <h2><i class="fa-solid fa-lock me-2 text-primary"></i>Security checklist</h2>
            <div class="row g-3 small">
                <div class="col-md-6"><i class="fa-solid fa-check-circle text-success me-2"></i>Admin-only access control is enforced on this page.</div>
                <div class="col-md-6"><i class="fa-solid fa-check-circle text-success me-2"></i>Database queries use prepared statements where user input is involved.</div>
                <div class="col-md-6"><i class="fa-solid fa-circle-info text-primary me-2"></i>Use HTTPS on the deployed website.</div>
                <div class="col-md-6"><i class="fa-solid fa-circle-info text-primary me-2"></i>Verify password hashing, CSRF checks, upload validation, and session timeout in the relevant PHP handlers.</div>
            </div>
        </div>
        <p class="text-secondary small mt-4 mb-0">Security figures are based on records stored in the database; they do not represent a live intrusion-detection system.</p>
    </section>
</main>
<script>
function toggleSidebar(){
    document.getElementById('adminSidebar').classList.toggle('show');
    document.getElementById('adminOverlay').classList.toggle('show');
}
</script>
</body>
</html>
