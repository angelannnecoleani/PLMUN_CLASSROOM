<?php
session_start();
require_once 'database.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.html");
    exit();
}

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$adminName = $_SESSION['name'] ?? 'Administrator';
$search = trim($_GET['search'] ?? '');
$role = trim($_GET['role'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

function validDate(string $date): bool {
    if ($date === '') return true;
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

$tableCheck = $conn->query("SHOW TABLES LIKE 'activity_logs'");
$tableExists = $tableCheck && $tableCheck->num_rows > 0;

$logs = [];
$totalRows = 0;
$errorMessage = '';

if (!$tableExists) {
    $errorMessage = "The activity_logs table does not exist. Import plmun_security_tables.sql first.";
} elseif (!validDate($from) || !validDate($to) || ($from && $to && $from > $to)) {
    $errorMessage = "Please provide a valid date range.";
} else {
    $where = [];
    $types = '';
    $params = [];

    if ($search !== '') {
        $where[] = "(user_name LIKE ? OR action LIKE ? OR description LIKE ? OR ip_address LIKE ?)";
        $like = "%{$search}%";
        $types .= 'ssss';
        array_push($params, $like, $like, $like, $like);
    }
    if ($role !== '') {
        $where[] = "user_role = ?";
        $types .= 's';
        $params[] = $role;
    }
    if ($from !== '') {
        $where[] = "created_at >= ?";
        $types .= 's';
        $params[] = $from . ' 00:00:00';
    }
    if ($to !== '') {
        $where[] = "created_at <= ?";
        $types .= 's';
        $params[] = $to . ' 23:59:59';
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countSql = "SELECT COUNT(*) AS total FROM activity_logs {$whereSql}";
    $countStmt = $conn->prepare($countSql);
    if ($countStmt) {
        if ($types !== '') $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $totalRows = (int)(($countResult->fetch_assoc()['total'] ?? 0));
        $countStmt->close();
    }

    $sql = "SELECT id, user_id, user_name, user_role, action, description, ip_address, created_at
            FROM activity_logs {$whereSql}
            ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $queryTypes = $types . 'ii';
        $queryParams = $params;
        $queryParams[] = $perPage;
        $queryParams[] = $offset;
        $stmt->bind_param($queryTypes, ...$queryParams);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) $logs[] = $row;
        }
        $stmt->close();
    } else {
        $errorMessage = "Unable to retrieve audit records. Check that the activity_logs table has the expected columns.";
    }
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));

function pageUrl(int $page): string {
    $params = $_GET;
    $params['page'] = $page;
    return 'audit_logs.php?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Audit Logs | PLMUN Classroom</title>
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
.admin-main{margin-left:274px;min-height:100vh}.admin-topbar{height:72px;background:#fff;border-bottom:1px solid #e8ebf2;display:flex;align-items:center;justify-content:space-between;padding:0 30px}
.admin-topbar a{color:#29324b;text-decoration:none}.content{padding:30px}.page-title{font-size:24px;font-weight:700;margin:0}.sub-title{color:var(--muted);font-size:13px;margin-top:6px}
.panel{background:#fff;border:1px solid #e9edf4;border-radius:14px;padding:20px;box-shadow:0 3px 12px #25304b08}
.table{font-size:12px;vertical-align:middle}.table th{color:#778096;font-weight:600;white-space:nowrap}.table td{max-width:300px;overflow-wrap:anywhere}
.btn-primary{background:var(--blue);border-color:var(--blue)}.btn-primary:hover{background:#32749f;border-color:#32749f}
.form-label{font-size:12px;font-weight:600}.form-control,.form-select{font-size:13px}.menu-toggle{display:none;border:0;background:none;font-size:20px;color:var(--navy)}
.badge-role{background:#edf4fb;color:#356f9b;font-weight:600}.pagination .page-link{font-size:12px;color:#356f9b}.pagination .active .page-link{background:var(--blue);border-color:var(--blue)}
.overlay{display:none;position:fixed;inset:0;background:#11182780;z-index:1040}.overlay.show{display:block}
@media(max-width:900px){.admin-sidebar{transform:translateX(-100%);transition:.25s}.admin-sidebar.show{transform:translateX(0)}.admin-main{margin-left:0}.menu-toggle{display:inline-block}.content{padding:20px}.admin-topbar{padding:0 18px}}
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
            <a href="security_overview.php">
                <i class="fa-solid fa-shield-halved"></i>
                <span>Security Overview</span>
            </a>
        </li>

        <li>
            <a href="audit_logs.php" class="active">
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
    <div class="d-flex align-items-center gap-3"><button class="menu-toggle" onclick="toggleSidebar()" aria-label="Open menu"><i class="fa-solid fa-bars"></i></button><div><strong>Audit Logs</strong><div class="text-secondary small">View recorded activities across the system</div></div></div>
    <div class="d-flex align-items-center gap-3"><span class="small text-secondary d-none d-sm-inline"><?= e($adminName) ?></span><a href="profile.php" title="Profile"><i class="fa-solid fa-circle-user fs-4"></i></a></div>
</header>
<section class="content">
    <div class="mb-4"><h1 class="page-title">Audit Logs</h1><p class="sub-title">Search and review recorded user and administrative activities.</p></div>
    <?php if ($errorMessage): ?><div class="alert alert-warning small"><?= e($errorMessage) ?></div><?php endif; ?>
    <div class="panel mb-3">
        <form method="GET" action="audit_logs.php" class="row g-3 align-items-end">
            <div class="col-lg-4"><label class="form-label">Search activity</label><input class="form-control" type="search" name="search" value="<?= e($search) ?>" placeholder="User, action, description, IP address"></div>
            <div class="col-md-4 col-lg-2"><label class="form-label">User role</label><select class="form-select" name="role"><option value="">All roles</option><?php foreach (['admin','director','program_chair','teacher','student'] as $r): ?><option value="<?= e($r) ?>" <?= $role === $r ? 'selected' : '' ?>><?= e(ucwords(str_replace('_',' ',$r))) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4 col-lg-2"><label class="form-label">From</label><input class="form-control" type="date" name="from" value="<?= e($from) ?>"></div>
            <div class="col-md-4 col-lg-2"><label class="form-label">To</label><input class="form-control" type="date" name="to" value="<?= e($to) ?>"></div>
            <div class="col-lg-2 d-flex gap-2"><button class="btn btn-primary btn-sm flex-grow-1" type="submit"><i class="fa-solid fa-filter me-1"></i>Filter</button><a class="btn btn-outline-secondary btn-sm" href="audit_logs.php">Reset</a></div>
        </form>
    </div>
    <div class="panel">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div><strong>Activity Records</strong><div class="text-secondary small"><?= number_format($totalRows) ?> matching record(s)</div></div>
            <span class="text-secondary small">Newest activities first</span>
        </div>
        <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th>ID</th><th>User</th><th>Role</th><th>Action</th><th>Description</th><th>IP Address</th><th>Date &amp; Time</th></tr></thead>
            <tbody>
            <?php if (!$logs): ?><tr><td colspan="7" class="text-center text-secondary py-4">No audit records found.</td></tr>
            <?php else: foreach ($logs as $log): ?>
                <tr>
                    <td><?= (int)$log['id'] ?></td>
                    <td><?= e($log['user_name']) ?><div class="text-secondary">User ID: <?= e($log['user_id']) ?></div></td>
                    <td><span class="badge badge-role"><?= e(ucwords(str_replace('_',' ',$log['user_role']))) ?></span></td>
                    <td><?= e($log['action']) ?></td>
                    <td><?= e($log['description']) ?></td>
                    <td><?= e($log['ip_address']) ?></td>
                    <td class="text-nowrap"><?= e($log['created_at']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Audit log pagination"><ul class="pagination pagination-sm justify-content-end mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(pageUrl(max(1,$page-1))) ?>">Previous</a></li>
            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            for ($p = $startPage; $p <= $endPage; $p++):
            ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= e(pageUrl($p)) ?>"><?= $p ?></a></li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(pageUrl(min($totalPages,$page+1))) ?>">Next</a></li>
        </ul></nav>
        <?php endif; ?>
    </div>
    <p class="text-secondary small mt-3">Audit records should be append-only for normal users. Restrict deletion and modification of these records to trusted database maintenance procedures.</p>
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
