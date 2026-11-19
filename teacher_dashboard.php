<?php
session_start();
include 'database.php';

/* =========================================
   LOGIN AND ROLE CHECK
========================================= */
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: login.php");
    exit();
}

$teacher_id = (int)$_SESSION['user_id'];

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
   FETCH TEACHER INFORMATION
========================================= */
$userStmt = mysqli_prepare(
    $conn,
    "SELECT * FROM users WHERE id = ? AND role = 'teacher'"
);

if (!$userStmt) {
    die("Database error: " . e(mysqli_error($conn)));
}

mysqli_stmt_bind_param($userStmt, "i", $teacher_id);
mysqli_stmt_execute($userStmt);

$userResult = mysqli_stmt_get_result($userStmt);
$user = mysqli_fetch_assoc($userResult);

mysqli_stmt_close($userStmt);

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

/* =========================================
   SEARCH
========================================= */
$search = trim($_GET['search'] ?? '');

/* =========================================
   CLASS CARD COLORS
========================================= */
$cardColors = [
    "#4C8C92",
    "#A65D57",
    "#6B5B95",
    "#8E9B5C",
    "#C97B63",
    "#3F87B5",
    "#B58457",
    "#5C7F8E"
];

function getClassColor($classId, $colors) {
    $index = crc32((string)$classId) % count($colors);
    return $colors[abs($index)];
}

/* =========================================
   FETCH ASSIGNED CLASSES

   Supports:
   1. Legacy classes.teacher_id
   2. Multiple instructors in class_instructors

   Displays ALL instructors assigned to
   each class.
========================================= */
$sql = "
    SELECT DISTINCT
        classes.*,

        COALESCE(
            (
                SELECT GROUP_CONCAT(
                    DISTINCT assigned_users.name
                    ORDER BY assigned_users.name
                    SEPARATOR ', '
                )
                FROM users assigned_users
                WHERE assigned_users.role = 'teacher'
                AND (
                    assigned_users.id = classes.teacher_id

                    OR EXISTS (
                        SELECT 1
                        FROM class_instructors ci2
                        WHERE ci2.class_id = classes.id
                        AND ci2.teacher_id = assigned_users.id
                    )
                )
            ),
            'No instructor assigned'
        ) AS teacher_names

    FROM classes

    WHERE (
        classes.teacher_id = ?

        OR EXISTS (
            SELECT 1
            FROM class_instructors ci
            WHERE ci.class_id = classes.id
            AND ci.teacher_id = ?
        )
    )
";

/* =========================================
   SEARCH FILTER
========================================= */
if ($search !== '') {

    $sql .= "
        AND (
            classes.class_name LIKE ?
            OR classes.program LIKE ?
            OR classes.section LIKE ?
            OR classes.schedule LIKE ?
            OR classes.room LIKE ?
        )
    ";
}

$sql .= " ORDER BY classes.created_at DESC";

/* =========================================
   EXECUTE CLASS QUERY
========================================= */
$classStmt = mysqli_prepare($conn, $sql);

if (!$classStmt) {
    die("Database error: " . e(mysqli_error($conn)));
}

if ($search !== '') {

    $searchTerm = "%{$search}%";

    mysqli_stmt_bind_param(
        $classStmt,
        "iisssss",
        $teacher_id,
        $teacher_id,
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm
    );

} else {

    mysqli_stmt_bind_param(
        $classStmt,
        "ii",
        $teacher_id,
        $teacher_id
    );
}

mysqli_stmt_execute($classStmt);

$classResult = mysqli_stmt_get_result($classStmt);

$classes = [];

while ($class = mysqli_fetch_assoc($classResult)) {
    $classes[] = $class;
}

mysqli_stmt_close($classStmt);

/* =========================================
   CLASS STATISTICS
========================================= */
$totalClasses = count($classes);

/* =========================================
   TOTAL STUDENTS IN ASSIGNED CLASSES
========================================= */
$studentStmt = mysqli_prepare(
    $conn,
    "SELECT COUNT(DISTINCT cs.student_id) AS cnt
     FROM class_students cs
     INNER JOIN classes c
        ON cs.class_id = c.id
     WHERE (
        c.teacher_id = ?
        OR EXISTS (
            SELECT 1
            FROM class_instructors ci
            WHERE ci.class_id = c.id
              AND ci.teacher_id = ?
        )
     )
     AND cs.archived = 0"
);

if (!$studentStmt) {
    die("Database error: " . e(mysqli_error($conn)));
}

mysqli_stmt_bind_param(
    $studentStmt,
    "ii",
    $teacher_id,
    $teacher_id
);

mysqli_stmt_execute($studentStmt);

$studentResult = mysqli_stmt_get_result($studentStmt);
$studentData = mysqli_fetch_assoc($studentResult);

$studentCount = (int)($studentData['cnt'] ?? 0);

mysqli_stmt_close($studentStmt);

/* =========================================
   TOTAL ASSESSMENTS IN ASSIGNED CLASSES
========================================= */
$assessStmt = mysqli_prepare(
    $conn,
    "SELECT COUNT(*) AS cnt
     FROM assessments a
     INNER JOIN classes c
        ON a.class_id = c.id
     WHERE (
        c.teacher_id = ?
        OR EXISTS (
            SELECT 1
            FROM class_instructors ci
            WHERE ci.class_id = c.id
              AND ci.teacher_id = ?
        )
     )"
);

if (!$assessStmt) {
    die("Database error: " . e(mysqli_error($conn)));
}

mysqli_stmt_bind_param(
    $assessStmt,
    "ii",
    $teacher_id,
    $teacher_id
);

mysqli_stmt_execute($assessStmt);

$assessResult = mysqli_stmt_get_result($assessStmt);
$assessData = mysqli_fetch_assoc($assessResult);

$assessCount = (int)($assessData['cnt'] ?? 0);

mysqli_stmt_close($assessStmt);

/* =========================================
   PROFILE IMAGE
========================================= */
$profileFile = $user['profile_image'] ?? '';

$hasProfileImage = !empty($profileFile)
    && !in_array(
        strtolower($profileFile),
        ['default.png', 'default.jpg', 'default.jpeg', 'null']
    )
    && file_exists(
        __DIR__ . '/uploads/' . basename($profileFile)
    );

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Teacher Dashboard</title>

    <link
        rel="shortcut icon"
        href="img/plmun logo.png"
        type="image/x-icon"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <link rel="stylesheet" href="teacher_dashboard.css">
    <link rel="stylesheet" href="notifications.css">

</head>

<body>

<!-- =========================================
     SIDEBAR
========================================= -->

<div class="sidebar" id="sidebar">

    <div class="sidebar-header">

        <button
            class="toggle-btn"
            onclick="toggleSidebar()"
            aria-label="Toggle sidebar"
        >
            <i class="fa-solid fa-bars"></i>
        </button>

        <button
            class="sidebar-close-btn"
            onclick="closeMobileSidebar()"
            aria-label="Close menu"
        >
            <i class="fa-solid fa-xmark"></i>
        </button>

    </div>

    <ul>

        <li>
            <a href="teacher_dashboard.php" class="active">
                <i class="fa-solid fa-house"></i>
                <span>Home</span>
            </a>
        </li>

        <li>
            <a href="teacher_classpage.php">
                <i class="fa-solid fa-users"></i>
                <span>Classes</span>
            </a>
        </li>

        <li>
            <a href="teacher_to_review.php">
                <i class="fa-solid fa-clipboard-list"></i>
                <span>To Review</span>
            </a>
        </li>

        <li>
            <a href="teacher_messages.php">
                <i class="fa-regular fa-message"></i>
                <span>Messages</span>
            </a>
        </li>

        <li>
            <a href="teacher_performance.php">
                <i class="fa-solid fa-chart-pie"></i>
                <span>Performance</span>
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

<!-- MOBILE SIDEBAR OVERLAY -->

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
    onclick="closeMobileSidebar()"
></div>

<!-- =========================================
     MAIN CONTENT
========================================= -->

<div class="main-content" id="mainContent">

    <!-- =====================================
         TOPBAR
    ====================================== -->

    <div class="topbar">

        <div class="navbar-left">

            <button
                class="mobile-menu"
                onclick="mobileSidebar()"
                aria-label="Open navigation"
            >
                <i class="fa-solid fa-bars"></i>
            </button>

            <img
                src="img/classroom logo.png"
                alt="PLMUN Classroom"
                class="navbar-logo"
            >

        </div>

        <div class="navbar-right">

            <!-- CREATE CLASS -->
            <a
                href="create_class.php"
                title="Create Class"
                aria-label="Create Class"
            >
                <i class="fa-solid fa-plus fa-lg"></i>
            </a>

            <!-- NOTIFICATIONS -->
            <div class="dropdown">

                <a
                    href="#"
                    class="position-relative text-dark"
                    role="button"
                    data-bs-toggle="dropdown"
                    data-bs-auto-close="outside"
                    aria-expanded="false"
                    aria-label="Notifications"
                >

                    <i class="fa-regular fa-bell fa-lg"></i>

                    <span
                        class="notif-badge d-none"
                        id="notifBadge"
                    >
                        0
                    </span>

                </a>

                <div class="dropdown-menu dropdown-menu-end notification-box p-0">

                    <div class="d-flex justify-content-between align-items-center p-3 border-bottom">

                        <h6 class="fw-bold mb-0">
                            Notifications
                        </h6>

                        <button
                            type="button"
                            class="btn btn-link btn-sm p-0 text-decoration-none d-none"
                            id="markAllReadBtn"
                        >
                            Mark all as read
                        </button>

                    </div>

                    <div id="notifList">

                        <div class="notif-empty">
                            No new notifications
                        </div>

                    </div>

                </div>

            </div>

            <!-- PROFILE -->
            <a
                href="profile.php"
                class="profile-icon"
                aria-label="My Profile"
            >

                <?php if ($hasProfileImage): ?>

                    <img
                        src="uploads/<?php echo e(basename($profileFile)); ?>"
                        alt="Profile"
                        onerror="this.style.display='none'; this.parentElement.insertAdjacentHTML('beforeend','<i class=&quot;fa-solid fa-user&quot;></i>');"
                    >

                <?php else: ?>

                    <i class="fa-solid fa-user"></i>

                <?php endif; ?>

            </a>

        </div>

    </div>

    <!-- =====================================
         SEARCH
    ====================================== -->

    <div class="search-section">

        <div class="search-box">

            <form
                method="GET"
                action="teacher_dashboard.php"
            >

                <div class="input-group">

                    <input
                        type="text"
                        name="search"
                        class="form-control"
                        placeholder="Search classes..."
                        value="<?php echo e($search); ?>"
                    >

                    <button
                        type="submit"
                        class="btn btn-primary"
                        aria-label="Search"
                    >
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </button>

                    <?php if ($search !== ''): ?>

                        <a
                            href="teacher_dashboard.php"
                            class="btn btn-outline-secondary"
                            aria-label="Clear search"
                        >
                            <i class="fa-solid fa-xmark"></i>
                        </a>

                    <?php endif; ?>

                </div>

            </form>

        </div>

    </div>

    <!-- =====================================
         DASHBOARD
    ====================================== -->

    <div class="container-fluid dashboard-container">

        <!-- WELCOME -->

        <div class="welcome-section">

            <h4 class="fw-bold">
                Welcome, <?php echo e($user['name']); ?>!
            </h4>

            <p class="text-muted mb-0">
                Here are the classes assigned to you.
            </p>

        </div>

        <!-- =================================
             CLASS CARDS
        ================================== -->

        <div class="row class-grid">

            <?php if (count($classes) > 0): ?>

                <?php foreach ($classes as $class): ?>

                    <div class="col-lg-6 col-md-6 col-sm-12 mb-4">

                        <div class="class-card">

                            <a
                                href="teacher_class.php?id=<?php echo (int)$class['id']; ?>"
                                class="class-link"
                            >

                                <!-- CLASS BANNER -->

                                <div
                                    class="class-banner"
                                    style="background:<?php echo e(getClassColor($class['id'], $cardColors)); ?>;"
                                ></div>

                                <!-- CLASS DETAILS -->

                                <div class="class-content">

                                    <h3 title="<?php echo e($class['class_name']); ?>">
                                        <?php echo e($class['class_name']); ?>
                                    </h3>

                                    <!-- ALL ASSIGNED INSTRUCTORS -->

                                    <p class="teacher-name">

                                        <i class="fa-solid fa-chalkboard-user me-1"></i>

                                        <?php echo e($class['teacher_names']); ?>

                                    </p>

                                    <!-- PROGRAM -->

                                    <?php if (!empty($class['program'])): ?>

                                        <p>
                                            <i class="fa-solid fa-graduation-cap me-1"></i>
                                            <?php echo e($class['program']); ?>
                                        </p>

                                    <?php endif; ?>

                                    <!-- SECTION -->

                                    <?php if (!empty($class['section'])): ?>

                                        <p>
                                            <i class="fa-solid fa-users me-1"></i>
                                            <?php echo e($class['section']); ?>
                                        </p>

                                    <?php endif; ?>

                                    <!-- SCHEDULE -->

                                    <?php if (!empty($class['schedule'])): ?>

                                        <p>
                                            <i class="fa-regular fa-clock me-1"></i>
                                            <?php echo e($class['schedule']); ?>
                                        </p>

                                    <?php endif; ?>

                                    <!-- ROOM -->

                                    <?php if (!empty($class['room'])): ?>

                                        <p>
                                            <i class="fa-solid fa-location-dot me-1"></i>
                                            <?php echo e($class['room']); ?>
                                        </p>

                                    <?php endif; ?>

                                    <!-- CLASS CODE -->

                                    <p class="small text-muted mb-0 class-code">

                                        Class Code:
                                        <?php echo e($class['class_code']); ?>

                                    </p>

                                </div>

                            </a>

                            <!-- CLASS OPTIONS -->

                            <div class="class-footer dropdown">

                                <button
                                    type="button"
                                    class="btn-ellipsis"
                                    data-bs-toggle="dropdown"
                                    aria-expanded="false"
                                    aria-label="Class options"
                                >
                                    <i class="fa-solid fa-ellipsis-vertical"></i>
                                </button>

                                <ul class="dropdown-menu dropdown-menu-end">

                                    <li>

                                        <a
                                            class="dropdown-item text-danger"
                                            href="#"
                                            onclick="event.preventDefault(); askDelete(
                                                <?php echo (int)$class['id']; ?>,
                                                <?php echo e(json_encode($class['class_name'])); ?>
                                            );"
                                        >

                                            <i class="fa-solid fa-trash"></i>

                                            Delete Class

                                        </a>

                                    </li>

                                </ul>

                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <!-- EMPTY STATE -->

                <div class="col-12">

                    <div class="alert alert-info empty-state">

                        <i class="fa-solid fa-circle-info fa-lg"></i>

                        <div>

                            <?php if ($search !== ''): ?>

                                <strong>
                                    No matching classes found.
                                </strong>

                                <div>
                                    Try another class name, program, or section.
                                </div>

                            <?php else: ?>

                                <strong>
                                    No classes assigned yet.
                                </strong>

                                <div>
                                    Your assigned classes will appear here once the Director assigns you as an instructor.
                                </div>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>

<!-- =========================================
     DELETE CONFIRMATION MODAL
========================================= -->

<div
    class="modal fade"
    id="confirmActionModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content confirm-modal">

            <div class="modal-header border-0 pb-0">

                <h5
                    class="modal-title fw-bold"
                    id="confirmActionTitle"
                >
                    Delete Class
                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>

            <div
                class="modal-body pt-2"
                id="confirmActionBody"
            >
                Are you sure you want to delete this class?
            </div>

            <div class="modal-footer border-0">

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >
                    Cancel
                </button>

                <button
                    type="button"
                    class="btn btn-danger"
                    id="confirmActionBtn"
                >
                    Delete
                </button>

            </div>

        </div>

    </div>

</div>

<!-- TOAST -->

<div
    class="toast-msg"
    id="toastMsg"
    role="status"
    aria-live="polite"
></div>

<!-- =========================================
     JAVASCRIPT
========================================= -->

<script src="teacher_notifications.js"></script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

/* =========================================
   RESPONSIVE SIDEBAR
========================================= */

const sidebar = document.getElementById('sidebar');

const mainContent = document.getElementById('mainContent');

const sidebarOverlay = document.getElementById('sidebarOverlay');

function toggleSidebar() {

    if (window.innerWidth > 768) {

        sidebar.classList.toggle('collapsed');

        mainContent.classList.toggle('expanded');

    }

}

function mobileSidebar() {

    sidebar.classList.add('show');

    sidebarOverlay.classList.add('show');

    document.body.style.overflow = 'hidden';

}

function closeMobileSidebar() {

    sidebar.classList.remove('show');

    sidebarOverlay.classList.remove('show');

    document.body.style.overflow = '';

}

window.addEventListener('resize', function() {

    if (window.innerWidth > 768) {
        closeMobileSidebar();
    }

});

document.querySelectorAll('.sidebar ul a, .logout-btn a').forEach(link => {

    link.addEventListener('click', function() {

        if (window.innerWidth <= 768) {
            closeMobileSidebar();
        }

    });

});

document.addEventListener('keydown', function(event) {

    if (event.key === 'Escape') {
        closeMobileSidebar();
    }

});

/* =========================================
   TOAST MESSAGE
========================================= */

let toastTimer;

function showToast(msg, isError = false) {

    const toast = document.getElementById('toastMsg');

    toast.textContent = msg;

    toast.className = 'toast-msg show' + (isError ? ' error' : '');

    clearTimeout(toastTimer);

    toastTimer = setTimeout(() => {

        toast.className = 'toast-msg';

    }, 3000);

}

/* =========================================
   DELETE CLASS MODAL
========================================= */

let pendingClassId = null;

const confirmModalEl = document.getElementById('confirmActionModal');

const confirmModal = new bootstrap.Modal(confirmModalEl);

const confirmTitle = document.getElementById('confirmActionTitle');

const confirmBody = document.getElementById('confirmActionBody');

const confirmBtn = document.getElementById('confirmActionBtn');

function askDelete(id, name) {

    pendingClassId = id;

    confirmTitle.textContent = 'Delete Class';

    confirmBody.textContent =
        'Are you sure you want to delete "' + name +
        '"? This may permanently remove the class and its associated records.';

    confirmBtn.className = 'btn btn-danger';

    confirmBtn.textContent = 'Delete';

    confirmModal.show();

}

confirmBtn.addEventListener('click', function() {

    if (!pendingClassId) {
        return;
    }

    confirmBtn.disabled = true;

    confirmBtn.innerHTML =
        '<span class="spinner-border spinner-border-sm me-2"></span>Please wait...';

    fetch('delete_class.php', {

        method: 'POST',

        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },

        body: 'id=' + encodeURIComponent(pendingClassId)

    })

    .then(response => {

        if (!response.ok) {
            throw new Error('Server error');
        }

        return response.json();

    })

    .then(data => {

        confirmBtn.disabled = false;

        confirmBtn.textContent = 'Delete';

        confirmModal.hide();

        if (data.success) {

            showToast('Class deleted successfully.');

            setTimeout(() => {
                location.reload();
            }, 800);

        } else {

            showToast(
                data.message || 'Unable to delete class.',
                true
            );

        }

    })

    .catch(() => {

        confirmBtn.disabled = false;

        confirmBtn.textContent = 'Delete';

        confirmModal.hide();

        showToast(
            'Network error. Please try again.',
            true
        );

    });

});

confirmModalEl.addEventListener('hidden.bs.modal', function() {

    confirmBtn.disabled = false;

    confirmBtn.textContent = 'Delete';

    pendingClassId = null;

});

</script>

</body>
</html>