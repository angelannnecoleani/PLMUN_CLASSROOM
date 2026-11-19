<?php
session_start();

require_once 'database.php';
require_once 'auth_helpers.php';

/* =========================================
   1. PROFILE IMAGE VALIDATION
========================================= */

function hasValidProfileImage($file) {

    if (empty($file)) {
        return false;
    }

    if (in_array(strtolower($file), [
        'default.png',
        'default.jpg',
        'default.jpeg',
        'null'
    ], true)) {
        return false;
    }

    return is_file(
        __DIR__ . '/uploads/' . basename($file)
    );
}

/* =========================================
   2. LOGIN AND ROLE VALIDATION
========================================= */

if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

if (!in_array($role, ['teacher', 'program_chair'], true)) {
    header("Location: login.php");
    exit();
}

/* =========================================
   3. VALIDATE CLASS ID
========================================= */

if (!isset($_GET['id'])) {
    die("Class not found.");
}

$class_id = (int) $_GET['id'];

if ($class_id <= 0) {
    die("Invalid class ID.");
}

/* =========================================
   4. VERIFY CLASS ACCESS
========================================= */

if ($role === 'teacher') {

    // Primary teacher or assigned co-instructor
    $classStmt = mysqli_prepare(
        $conn,
        "SELECT *
         FROM classes
         WHERE id = ?
         AND (
             teacher_id = ?
             OR EXISTS (
                 SELECT 1
                 FROM class_instructors ci
                 WHERE ci.class_id = classes.id
                 AND ci.teacher_id = ?
             )
         )
         LIMIT 1"
    );

    if (!$classStmt) {
        die("Database error: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param(
        $classStmt,
        "iii",
        $class_id,
        $user_id,
        $user_id
    );

} else {

    // Program chair: use existing access helper
    if (!getClassAccess($conn, $class_id, $user_id, $role)) {
        die("Access denied. You do not have permission to view this class.");
    }

    $classStmt = mysqli_prepare(
        $conn,
        "SELECT *
         FROM classes
         WHERE id = ?
         LIMIT 1"
    );

    if (!$classStmt) {
        die("Database error: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param(
        $classStmt,
        "i",
        $class_id
    );
}

mysqli_stmt_execute($classStmt);

$class = mysqli_fetch_assoc(
    mysqli_stmt_get_result($classStmt)
);

mysqli_stmt_close($classStmt);

if (!$class) {
    die("Class not found or access denied.");
}

/* =========================================
   5. GET LOGGED-IN USER
========================================= */

$userStmt = mysqli_prepare(
    $conn,
    "SELECT *
     FROM users
     WHERE id = ?
     LIMIT 1"
);

mysqli_stmt_bind_param($userStmt, "i", $user_id);
mysqli_stmt_execute($userStmt);

$user = mysqli_fetch_assoc(
    mysqli_stmt_get_result($userStmt)
) ?: [];

mysqli_stmt_close($userStmt);

/* =========================================
   6. SORT ORDER
========================================= */

$sort = (
    isset($_GET['sort']) &&
    $_GET['sort'] === 'first'
) ? 'first' : 'last';

// Use a whitelist for the SQL ORDER BY expression.
if ($sort === 'first') {
    $order_sql = "ORDER BY SUBSTRING_INDEX(u.name, ' ', 1) ASC, u.name ASC";
} else {
    $order_sql = "ORDER BY SUBSTRING_INDEX(u.name, ' ', -1) ASC, u.name ASC";
}

/* =========================================
   7. FETCH STUDENTS
========================================= */

$studentsSql = "
    SELECT
        u.id,
        u.name,
        u.profile_image
    FROM class_students cs
    INNER JOIN users u
        ON cs.student_id = u.id
    WHERE cs.class_id = ?
    AND (cs.archived = 0 OR cs.archived IS NULL)
    $order_sql
";

$studentsStmt = mysqli_prepare($conn, $studentsSql);

if (!$studentsStmt) {
    die("Unable to load students: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($studentsStmt, "i", $class_id);
mysqli_stmt_execute($studentsStmt);

$studentsResult = mysqli_stmt_get_result($studentsStmt);

$students = [];

while ($s = mysqli_fetch_assoc($studentsResult)) {
    $students[] = $s;
}

mysqli_stmt_close($studentsStmt);

/* =========================================
   8. FETCH ASSESSMENTS
========================================= */

$assessStmt = mysqli_prepare(
    $conn,
    "SELECT id, title, points, type
     FROM assessments
     WHERE class_id = ?
     ORDER BY id ASC"
);

mysqli_stmt_bind_param($assessStmt, "i", $class_id);
mysqli_stmt_execute($assessStmt);

$assessResult = mysqli_stmt_get_result($assessStmt);

$assessments = [];

while ($a = mysqli_fetch_assoc($assessResult)) {
    $assessments[] = $a;
}

mysqli_stmt_close($assessStmt);

/* =========================================
   9. FETCH GRADES
========================================= */

$gradesStmt = mysqli_prepare(
    $conn,
    "SELECT
        s.student_id,
        s.assessment_id,
        s.grade
     FROM submissions s
     INNER JOIN assessments a
        ON s.assessment_id = a.id
     WHERE a.class_id = ?"
);

mysqli_stmt_bind_param($gradesStmt, "i", $class_id);
mysqli_stmt_execute($gradesStmt);

$gradesResult = mysqli_stmt_get_result($gradesStmt);

$grades = [];

while ($g = mysqli_fetch_assoc($gradesResult)) {

    $grades[$g['student_id']][$g['assessment_id']] = $g['grade'];

}

mysqli_stmt_close($gradesStmt);

/* =========================================
   10. COMPUTE CLASS AVERAGES
========================================= */

$averages = [];

foreach ($assessments as $a) {

    $total = 0;
    $count = 0;

    foreach ($students as $s) {

        $grade = $grades[$s['id']][$a['id']] ?? null;

        if ($grade !== null && $grade !== '') {

            $total += (float) $grade;
            $count++;

        }
    }

    $averages[$a['id']] = $count > 0
        ? round($total / $count, 1)
        : null;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grades – <?php echo htmlspecialchars($class['class_name']); ?></title>
    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">
    <link rel="stylesheet" href="teacher_class.css?v=5">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        /* ── Grades table ── */
        .grades-wrap {
            overflow-x: auto;
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e0e8f0;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }

        .grades-table {
            border-collapse: separate;
            border-spacing: 0;
            min-width: 600px;
            width: 100%;
        }

        /* Sticky student name column */
        .grades-table th.col-student,
        .grades-table td.col-student {
            position: sticky;
            left: 0;
            z-index: 2;
            background: #fff;
            min-width: 220px;
            border-right: 2px solid #e0e8f0;
        }

        .grades-table th.col-student {
            background: #f8fafc;
            z-index: 3;
        }

        /* Header row */
        .grades-table thead th {
            background: #f8fafc;
            padding: 0;
            border-bottom: 2px solid #e0e8f0;
            border-right: 1px solid #f0f0f0;
            vertical-align: top;
            text-align: center;
        }

        .grades-table thead th:last-child { border-right: none; }

        .col-header-inner {
            padding: 14px 16px 10px;
        }

        .col-assessment-title {
            font-weight: 700;
            font-size: 14px;
            color: #1a2d3e;
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 130px;
        }

        .col-points {
            font-size: 11px;
            color: #888;
            margin-top: 2px;
            display: block;
        }

        .col-type-badge {
            display: inline-block;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 10px;
            margin-top: 4px;
            background: #e8f0f8;
            color: #3f87b5;
        }

        /* Sort row */
        .grades-sort-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .sort-select {
            height: 42px;
            padding: 0 36px 0 14px;
            border: 1px solid #dde4ec;
            border-radius: 8px;
            background: #fff;
            font-size: 14px;
            font-weight: 500;
            color: #333;
            appearance: none;
            cursor: pointer;
            outline: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23666' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
        }

        /* Body rows */
        .grades-table tbody tr {
            transition: background 0.15s;
        }

        .grades-table tbody tr:hover td {
            background: #f5f8fb;
        }

        .grades-table tbody tr:hover td.col-student {
            background: #f5f8fb;
        }

        .grades-table tbody td {
            padding: 10px 12px;
            border-bottom: 1px solid #f0f0f0;
            border-right: 1px solid #f0f0f0;
            text-align: center;
            vertical-align: middle;
        }

        .grades-table tbody td:last-child { border-right: none; }
        .grades-table tbody tr:last-child td { border-bottom: none; }

        /* Student cell */
        .student-cell {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 16px;
        }

        .student-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
            background: #d4dbe5;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            color: #666;
            overflow: hidden;
        }

        .student-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            display: block;
        }

        .student-name {
            font-size: 14px;
            font-weight: 600;
            color: #1a2d3e;
            text-align: left;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 160px;
        }

        /* Average row */
        .avg-row td {
            background: #f0f6fb !important;
            font-weight: 700;
            font-size: 13px;
            color: #3f87b5;
            border-bottom: 2px solid #dde4ec !important;
        }

        .avg-row td.col-student {
            background: #f0f6fb !important;
        }

        /* Grade input */
        .grade-input {
            width: 72px;
            height: 36px;
            border: 1px solid transparent;
            border-radius: 6px;
            text-align: center;
            font-size: 14px;
            font-weight: 600;
            color: #1a2d3e;
            background: transparent;
            outline: none;
            transition: border-color 0.2s, background 0.2s;
            cursor: pointer;
        }

        .grade-input:hover {
            border-color: #cde0ef;
            background: #f5f8fb;
        }

        .grade-input:focus {
            border-color: #3f87b5;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(63,135,181,.12);
            cursor: text;
        }

        /* Grade colour coding */
        .grade-input.grade-high  { color: #198754; }
        .grade-input.grade-mid   { color: #f0a500; }
        .grade-input.grade-low   { color: #dc3545; }
        .grade-input.grade-empty { color: #bbb; }

        /* Save indicator */
        .grade-saved-dot {
            display: inline-block;
            width: 6px; height: 6px;
            border-radius: 50%;
            background: #198754;
            margin-left: 4px;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .grade-saved-dot.show { opacity: 1; }

        /* Empty state */
        .grades-empty {
            text-align: center;
            padding: 60px 20px;
            color: #bbb;
        }

        .grades-empty i { font-size: 48px; display: block; margin-bottom: 14px; }

        /* Export button */
        .export-btn {
            height: 42px;
            padding: 0 20px;
            border: 1px solid #dde4ec;
            border-radius: 8px;
            background: #fff;
            font-size: 14px;
            font-weight: 600;
            color: #444;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: background 0.2s;
        }
        .export-btn:hover { background: #f5f8fb; color: #333; }

        /* Flash message */
        .flash-msg {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #1a2d3e;
            color: #fff;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            z-index: 9999;
            opacity: 0;
            transform: translateY(10px);
            transition: opacity 0.3s, transform 0.3s;
            pointer-events: none;
        }
        .flash-msg.show {
            opacity: 1;
            transform: translateY(0);
        }

        /* Assessment title link */
        .assessment-title-link {
            text-decoration: none;
            color: #222;
        }
        .assessment-title-link:hover { color: #3f87b5; }

        @media (max-width: 768px) {
            .grades-sort-row {
                flex-wrap: wrap;
                gap: 10px;
            }

            .grades-sort-row #sortForm {
                width: 100%;
            }

            .sort-select {
                flex: 1;
                min-width: 0;
            }

            .export-btn span { display: none; }
            .export-btn { padding: 0 14px; }

            .grades-table th.col-student,
            .grades-table td.col-student {
                min-width: 170px;
            }

            .student-cell {
                padding: 10px 12px;
                gap: 10px;
            }

            .student-name {
                max-width: 110px;
            }

            .col-header-inner {
                padding: 12px 12px 8px;
            }

            .col-assessment-title {
                max-width: 100px;
            }

            .grade-input {
                width: 58px;
                height: 40px;
            }

            .flash-msg {
                left: 16px;
                right: 16px;
                bottom: 16px;
                text-align: center;
            }
        }

        @media (max-width: 480px) {
            .grades-table th.col-student,
            .grades-table td.col-student {
                min-width: 140px;
            }

            .student-cell {
                padding: 10px;
                gap: 8px;
            }

            .student-avatar {
                width: 30px;
                height: 30px;
                font-size: 12px;
            }

            .student-name {
                max-width: 80px;
                font-size: 13px;
            }

            .col-header-inner {
                padding: 10px 8px 8px;
            }

            .col-assessment-title {
                font-size: 13px;
                max-width: 78px;
            }

            .grade-input {
                width: 50px;
                font-size: 13px;
            }

            .grades-empty {
                padding: 40px 16px;
            }
        }
    </style>
</head>
<body>

<!-- Dims the page and closes the mobile menu when tapped -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- SIDEBAR -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <button class="toggle-btn" onclick="toggleSidebar()">
            <i class="fa-solid fa-bars"></i>
        </button>
        <button class="sidebar-close-btn" onclick="closeMobileSidebar()" aria-label="Close menu">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>
    <ul>
        <li><a href="teacher_dashboard.php"><i class="fa-solid fa-house"></i><span>Home</span></a></li>
        <li><a href="teacher_classpage.php"><i class="fa-solid fa-users"></i><span>Classes</span></a></li>
        <li><a href="teacher_to_review.php"><i class="fa-solid fa-clipboard-list"></i><span>To Review</span></a></li>
        <li><a href="teacher_messages.php"><i class="fa-regular fa-message"></i><span>Messages</span></a></li>
        <li><a href="teacher_performance.php"><i class="fa-solid fa-chart-pie"></i><span>Performance</span></a></li>

    </ul>
    <div class="logout-btn">
        <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="main-content" id="mainContent">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="navbar-left">
            <button class="mobile-menu" onclick="mobileSidebar()">
                <i class="fa-solid fa-bars"></i>
            </button>
            <img src="img/classroom logo.png" class="navbar-logo" alt="Logo">
        </div>
        <div class="navbar-right">

            <?php
                $profileFile = $user['profile_image'] ?? '';
                $hasProfileImage = hasValidProfileImage($profileFile);
            ?>
            <a href="profile.php" class="profile-icon">
                <?php if($hasProfileImage): ?>
                    <img src="uploads/<?php echo htmlspecialchars(basename($profileFile)); ?>"
                         alt="Profile"
                         onerror="this.style.display='none'; this.parentElement.insertAdjacentHTML('beforeend','<i class=&quot;fa-solid fa-user&quot;></i>');">
                <?php else: ?>
                    <i class="fa-solid fa-user"></i>
                <?php endif; ?>
            </a>
        </div>
    </div>

    <!-- CLASS NAV -->
    <div class="class-nav">
        <a href="teacher_class.php?id=<?php echo $class_id; ?>">Stream</a>
        <a href="teacher_assessment.php?id=<?php echo $class_id; ?>">Assessments</a>
        <a href="teacher_material.php?id=<?php echo $class_id; ?>">Materials</a>
        <a href="teacher_people.php?id=<?php echo $class_id; ?>">People</a>
        <a href="teacher_grades.php?id=<?php echo $class_id; ?>" class="nav-active">Grades</a>
    </div>

    <div class="container py-4">

        <?php if(empty($assessments)): ?>
        <div class="grades-empty">
            <i class="fa-solid fa-table-list"></i>
            <p>No assessments yet. Create one in the <a href="teacher_assessment.php?id=<?php echo $class_id; ?>">Assessments</a> tab.</p>
        </div>

        <?php elseif(empty($students)): ?>
        <div class="grades-empty">
            <i class="fa-solid fa-user-group"></i>
            <p>No students enrolled yet.</p>
        </div>

        <?php else: ?>

        <!-- SORT + EXPORT -->
        <div class="grades-sort-row mb-3">
            <form method="GET" id="sortForm" style="display:flex; align-items:center; gap:8px;">
                <input type="hidden" name="id" value="<?php echo $class_id; ?>">
                <select name="sort" class="sort-select" onchange="document.getElementById('sortForm').submit()">
                    <option value="last"  <?php echo $sort==='last'  ? 'selected' : ''; ?>>Sort by last name</option>
                    <option value="first" <?php echo $sort==='first' ? 'selected' : ''; ?>>Sort by first name</option>
                </select>
            </form>
            <a href="export_grades.php?id=<?php echo $class_id; ?>" class="export-btn">
                <i class="fa-solid fa-file-export"></i>
                <span>Export CSV</span>
            </a>
        </div>

        <!-- GRADES TABLE -->
        <div class="grades-wrap">
        <table class="grades-table">
            <thead>
                <tr>
                    <!-- Sticky student column header -->
                    <th class="col-student">
                        <div class="col-header-inner" style="text-align:left; padding:14px 16px;">
                            <span style="font-weight:700; font-size:14px; color:#1a2d3e;">Student</span>
                        </div>
                    </th>

                    <?php foreach($assessments as $a): ?>
                    <th>
                        <div class="col-header-inner">
                            <a href="teacher_submissions.php?id=<?php echo $a['id']; ?>"
                               class="col-assessment-title assessment-title-link"
                               title="<?php echo htmlspecialchars($a['title']); ?>">
                                <?php echo htmlspecialchars($a['title']); ?>
                            </a>
                            <span class="col-points">out of <?php echo (int)$a['points']; ?></span>
                            <span class="col-type-badge"><?php echo htmlspecialchars($a['type']); ?></span>
                        </div>
                    </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>

                <!-- Class Average row -->
                <tr class="avg-row">
                    <td class="col-student">
                        <div class="student-cell">
                            <div class="student-avatar">
                                <i class="fa-solid fa-chart-bar" style="font-size:14px; color:#3f87b5;"></i>
                            </div>
                            <span class="student-name">Class Average</span>
                        </div>
                    </td>
                    <?php foreach($assessments as $a): ?>
                    <td>
                        <?php if($averages[$a['id']] !== null): ?>
                            <?php
                            $pct = $a['points'] > 0 ? ($averages[$a['id']] / $a['points']) * 100 : 0;
                            $cls = $pct >= 75 ? 'grade-high' : ($pct >= 50 ? 'grade-mid' : 'grade-low');
                            ?>
                            <span class="<?php echo $cls; ?>">
                                <?php echo $averages[$a['id']]; ?>
                            </span>
                        <?php else: ?>
                            <span style="color:#ccc;">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                </tr>

                <!-- Student rows -->
                <?php foreach($students as $s): ?>
                <tr>
                    <td class="col-student">
                        <div class="student-cell">
                            <div class="student-avatar">
                                <?php if(hasValidProfileImage($s['profile_image'] ?? '')): ?>
                                    <img src="uploads/<?php echo htmlspecialchars(basename($s['profile_image'])); ?>"
                                         alt=""
                                         onerror="this.remove(); this.parentElement.innerHTML='<i class=&quot;fa-solid fa-user&quot;></i>';">
                                <?php else: ?>
                                    <i class="fa-solid fa-user"></i>
                                <?php endif; ?>
                            </div>
                            <span class="student-name" title="<?php echo htmlspecialchars($s['name']); ?>">
                                <?php echo htmlspecialchars($s['name']); ?>
                            </span>
                        </div>
                    </td>

                    <?php foreach($assessments as $a): ?>
                    <?php
                    $grade_val = $grades[$s['id']][$a['id']] ?? null;
                    $pct       = ($grade_val !== null && $a['points'] > 0)
                                 ? ($grade_val / $a['points']) * 100 : -1;
                    $colorCls  = $grade_val === null ? 'grade-empty'
                               : ($pct >= 75 ? 'grade-high'
                               : ($pct >= 50 ? 'grade-mid' : 'grade-low'));
                    ?>
                    <td>
                        <div style="display:flex; align-items:center; justify-content:center; gap:4px;">
                            <input
                                type="number"
                                class="grade-input <?php echo $colorCls; ?>"
                                value="<?php echo $grade_val !== null ? htmlspecialchars($grade_val) : ''; ?>"
                                placeholder="—"
                                min="0"
                                max="<?php echo (int)$a['points']; ?>"
                                step="0.5"
                                data-assessment="<?php echo $a['id']; ?>"
                                data-student="<?php echo $s['id']; ?>"
                                data-max="<?php echo (int)$a['points']; ?>"
                                title="<?php echo htmlspecialchars($s['name']); ?> — <?php echo htmlspecialchars($a['title']); ?>">
                            <span class="grade-saved-dot" id="dot-<?php echo $a['id']; ?>-<?php echo $s['id']; ?>"></span>
                        </div>
                    </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>

            </tbody>
        </table>
        </div>

        <?php endif; ?>

    </div><!-- end container -->
</div><!-- end main-content -->

<!-- Flash toast -->
<div class="flash-msg" id="flashMsg"></div>

<script>
function toggleSidebar(){
    document.getElementById('sidebar').classList.toggle('collapsed');
    document.getElementById('mainContent').classList.toggle('expanded');
}
function openMobileSidebar(){
    document.getElementById('sidebar').classList.add('show');
    document.getElementById('sidebarOverlay').classList.add('show');
    document.body.classList.add('no-scroll');
}
function closeMobileSidebar(){
    document.getElementById('sidebar').classList.remove('show');
    document.getElementById('sidebarOverlay').classList.remove('show');
    document.body.classList.remove('no-scroll');
}
function mobileSidebar(){
    const sidebar = document.getElementById('sidebar');
    sidebar.classList.contains('show') ? closeMobileSidebar() : openMobileSidebar();
}

// Tapping the dimmed background closes the menu
document.getElementById('sidebarOverlay').addEventListener('click', closeMobileSidebar);

// Pressing Escape closes the menu
document.addEventListener('keydown', function(e){
    if(e.key === 'Escape') closeMobileSidebar();
});

// Picking a nav link closes the menu so it doesn't stay open on the new page
document.querySelectorAll('.sidebar ul li a, .sidebar .logout-btn a').forEach(function(link){
    link.addEventListener('click', function(){
        if(document.getElementById('sidebar').classList.contains('show')) closeMobileSidebar();
    });
});

// ── Flash toast ──
function showFlash(msg, isError){
    const el = document.getElementById('flashMsg');
    el.textContent = msg;
    el.style.background = isError ? '#dc3545' : '#1a2d3e';
    el.classList.add('show');
    setTimeout(() => el.classList.remove('show'), 2500);
}

// ── Auto-save grades on blur ──
document.querySelectorAll('.grade-input').forEach(function(input){

    // Colour coding on load
    applyColor(input);

    input.addEventListener('focus', function(){
        this.select();
    });

    input.addEventListener('input', function(){
        applyColor(this);
    });

    input.addEventListener('blur', function(){
        saveGrade(this);
    });

    input.addEventListener('keydown', function(e){
        if(e.key === 'Enter'){
            e.preventDefault();
            this.blur();
            // Move to next input in same column
            const inputs = Array.from(document.querySelectorAll('.grade-input'));
            const idx = inputs.indexOf(this);
            if(inputs[idx + 1]) inputs[idx + 1].focus();
        }
        if(e.key === 'Escape'){
            this.blur();
        }
    });
});

function applyColor(input){
    input.classList.remove('grade-high','grade-mid','grade-low','grade-empty');
    const val = input.value.trim();
    if(val === ''){ input.classList.add('grade-empty'); return; }
    const max = parseFloat(input.dataset.max);
    const pct = max > 0 ? (parseFloat(val) / max) * 100 : 0;
    if(pct >= 75)      input.classList.add('grade-high');
    else if(pct >= 50) input.classList.add('grade-mid');
    else               input.classList.add('grade-low');
}

async function saveGrade(input){
    const assessment_id = input.dataset.assessment;
    const student_id    = input.dataset.student;
    const grade         = input.value.trim();
    const dot           = document.getElementById('dot-' + assessment_id + '-' + student_id);

    try {
        const r = await fetch('save_grade.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `assessment_id=${assessment_id}&student_id=${student_id}&grade=${encodeURIComponent(grade)}`
        });
        const text = await r.text();

        // Show green dot briefly
        if(dot){ dot.classList.add('show'); setTimeout(() => dot.classList.remove('show'), 1500); }

        showFlash('Grade saved');
    } catch(e){
        showFlash('Failed to save grade', true);
    }
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>