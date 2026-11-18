
<?php
session_start();
include 'database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$student_id = (int) $_SESSION['user_id'];

/* =========================================
   HELPER FUNCTIONS
========================================= */

function e($value) {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function getClassColor($classId, $colors) {
    $index = abs(crc32((string)$classId)) % count($colors);
    return $colors[$index];
}

/* =========================================
   GET USER INFORMATION
========================================= */

$userStmt = mysqli_prepare(
    $conn,
    "SELECT id, name, profile_image FROM users WHERE id = ? LIMIT 1"
);

if (!$userStmt) {
    die("Database error: " . e(mysqli_error($conn)));
}

mysqli_stmt_bind_param($userStmt, "i", $student_id);
mysqli_stmt_execute($userStmt);

$userResult = mysqli_stmt_get_result($userStmt);
$user = mysqli_fetch_assoc($userResult) ?: [];

mysqli_stmt_close($userStmt);

/* =========================================
   PROFILE IMAGE
========================================= */

$profileImageFile = trim((string)($user['profile_image'] ?? ''));
$hasProfileImage = false;

$defaultImages = [
    '', 'null',
    'default.png', 'default.jpg', 'default.jpeg', 'default.svg',
    'user.png', 'user.jpg', 'user.jpeg'
];

if ($profileImageFile !== '') {
    $safeName = basename(str_replace('\\', '/', $profileImageFile));
    $imagePath = __DIR__ . '/uploads/' . $safeName;

    if (
        !in_array(strtolower($safeName), $defaultImages, true) &&
        is_file($imagePath) &&
        is_readable($imagePath)
    ) {
        $profileImageFile = $safeName;
        $hasProfileImage = true;
    }
}

if (!$hasProfileImage) {
    $profileImageFile = '';
}

/* =========================================
   SEARCH ARCHIVED CLASSES
========================================= */

$search = trim($_GET['search'] ?? '');

if ($search !== '') {
    $searchTerm = '%' . $search . '%';

    $query = mysqli_prepare($conn, "
        SELECT
            classes.*,
            users.name AS teacher_name
        FROM class_students
        INNER JOIN classes
            ON class_students.class_id = classes.id
        LEFT JOIN users
            ON classes.teacher_id = users.id
        WHERE
            class_students.student_id = ?
            AND class_students.archived = 1
            AND (
                classes.class_name LIKE ?
                OR classes.section LIKE ?
                OR users.name LIKE ?
            )
        ORDER BY class_students.joined_at DESC
    ");

    if (!$query) {
        die("Database error: " . e(mysqli_error($conn)));
    }

    mysqli_stmt_bind_param(
        $query,
        "isss",
        $student_id,
        $searchTerm,
        $searchTerm,
        $searchTerm
    );

} else {
    $query = mysqli_prepare($conn, "
        SELECT
            classes.*,
            users.name AS teacher_name
        FROM class_students
        INNER JOIN classes
            ON class_students.class_id = classes.id
        LEFT JOIN users
            ON classes.teacher_id = users.id
        WHERE
            class_students.student_id = ?
            AND class_students.archived = 1
        ORDER BY class_students.joined_at DESC
    ");

    if (!$query) {
        die("Database error: " . e(mysqli_error($conn)));
    }

    mysqli_stmt_bind_param($query, "i", $student_id);
}

mysqli_stmt_execute($query);
$archivedClasses = mysqli_stmt_get_result($query);

/* =========================================
   CARD COLORS
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
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Archived Classes</title>

    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">

    <!-- BOOTSTRAP -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- FONT AWESOME -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <!-- SAME DESIGN AS STUDENT DASHBOARD -->
    <link rel="stylesheet" href="student_dashboard.css">

    <!-- NOTIFICATIONS -->
    <link rel="stylesheet" href="notifications.css">

    <style>
        /* =========================================
           GENERAL LAYOUT
        ========================================= */

        html,
        body {
            width: 100%;
            max-width: 100%;
            overflow-x: hidden;
        }

        .main-content {
            min-width: 0;
            transition: margin-left 0.3s ease, width 0.3s ease;
        }

        .main-content,
        .main-content * {
            box-sizing: border-box;
        }

        /* =========================================
           SIDEBAR OVERLAY
        ========================================= */

        .sidebar-overlay {
            display: none;
        }

        /* =========================================
           TOPBAR
        ========================================= */

        .topbar {
            position: relative;
            z-index: 1030;
        }

        .navbar-left,
        .navbar-right {
            display: flex;
            align-items: center;
            min-width: 0;
        }

        .navbar-left {
            gap: 12px;
        }

        .navbar-right {
            gap: 20px;
        }

        .navbar-logo {
            max-width: 180px;
            height: auto;
            object-fit: contain;
        }

        .mobile-menu {
            display: none;
            border: none;
            background: transparent;
            color: #333;
            font-size: 23px;
            cursor: pointer;
            padding: 5px;
            align-items: center;
            justify-content: center;
        }

        .profile-icon {
            flex-shrink: 0;
            overflow: hidden;
        }

        .profile-icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .profile-fallback {
            width: 100%;
            height: 100%;
            align-items: center;
            justify-content: center;
        }

        /* =========================================
           SEARCH SECTION
        ========================================= */

        .search-section {
            width: 100%;
            padding: 20px 30px 0;
        }

        .search-box {
            width: 100%;
            max-width: 650px;
            margin-left: auto;
        }

        .search-box form,
        .search-box .input-group {
            width: 100%;
            min-width: 0;
        }

        .search-box .form-control {
            min-width: 0;
        }

        /* =========================================
           ARCHIVED CLASS GRID
           AUTOMATICALLY EXPANDS
        ========================================= */

        .archived-container {
            width: 100%;
            max-width: 1600px;
            margin: 0 auto;
            padding: 20px 30px 35px;
        }

        .archived-row {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 24px;
            margin: 0;
            width: 100%;
        }

        /*
         * Reset Bootstrap column sizing.
         * This allows CSS Grid to control the card width.
         */
        .archived-row > .class-column {
            width: auto !important;
            max-width: none !important;
            flex: initial !important;
            padding: 0 !important;
            margin: 0 !important;
            min-width: 0;
            display: flex;
        }

        .class-card {
            width: 100%;
            min-width: 0;
            height: 100%;
            display: flex;
            flex-direction: column;
            transition: opacity 0.25s ease, transform 0.25s ease;
        }

        .class-card.removing {
            opacity: 0;
            transform: scale(0.96);
        }

        .class-link {
            display: block;
            flex: 1;
            min-width: 0;
            color: inherit;
            text-decoration: none;
        }

        .class-content {
            min-width: 0;
            overflow-wrap: anywhere;
        }

        .class-content h3 {
            overflow-wrap: break-word;
        }

        .class-footer {
            margin-top: auto;
        }

        /* One card: full available width */
        .archived-row:has(> .class-column:only-child) {
            grid-template-columns: minmax(0, 1fr);
        }

        /* Two cards: two equal columns */
        .archived-row:has(> .class-column:nth-child(2):last-child) {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        /* Empty search result */
        .archived-empty {
            grid-column: 1 / -1;
            width: 100%;
        }

        /* =========================================
           DROPDOWNS AND MODALS
        ========================================= */

        .notification-box,
        .class-options-menu {
            max-width: calc(100vw - 25px);
        }

        .class-options-menu {
            z-index: 1040;
        }

        .modal-dialog {
            max-width: 500px;
        }

        .modal-content {
            max-width: 100%;
        }

        .toast-container {
            position: fixed;
            right: 20px;
            bottom: 20px;
            z-index: 2000;
        }

        body.no-scroll {
            overflow: hidden;
        }

        /* =========================================
           TABLET
        ========================================= */

        @media (max-width: 991.98px) {

            .archived-container {
                padding: 20px;
            }

            .archived-row {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 20px;
            }

            .archived-row:has(> .class-column:only-child) {
                grid-template-columns: minmax(0, 1fr);
            }

            .main-content .container-fluid {
                padding-left: 20px !important;
                padding-right: 20px !important;
            }
        }

        /* =========================================
           MOBILE SIDEBAR - MATCH DASHBOARD
        ========================================= */

        @media (max-width: 768px) {

            .sidebar {
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                bottom: 0 !important;

                width: 250px !important;
                max-width: 85vw !important;

                height: 100vh !important;
                height: 100dvh !important;

                z-index: 1060 !important;

                transform: translateX(-100%);
                transition: transform 0.3s ease;

                overflow-y: auto;
                overflow-x: hidden;
            }

            .sidebar.show {
                transform: translateX(0) !important;
            }


            /* Sidebar header menu button */
            .sidebar .sidebar-header {
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 65px;
                padding: 10px 15px;
            }

            .sidebar .toggle-btn {
                display: flex !important;
                align-items: center;
                justify-content: center;
                width: 40px;
                height: 40px;
                border: none;
                background: transparent;
                color: #333;
                font-size: 20px;
                cursor: pointer;
                border-radius: 8px;
            }

            .sidebar .toggle-btn:hover {
                background: rgba(0, 0, 0, 0.08);
            }

            .sidebar-overlay {
                position: fixed;
                inset: 0;
                z-index: 1050;
                display: none;
                background: rgba(0, 0, 0, 0.4);
            }

            .sidebar-overlay.show {
                display: block;
            }

            .main-content,
            .main-content.expanded {
                width: 100% !important;
                margin-left: 0 !important;
            }

            .topbar {
                width: 100%;
                min-width: 0;
                padding-left: 15px;
                padding-right: 15px;
                box-sizing: border-box;
            }

            .navbar-left,
            .navbar-right {
                min-width: 0;
            }

            .navbar-left {
                gap: 10px;
            }

            .navbar-right {
                gap: 15px;
            }

            .mobile-menu {
                display: flex !important;
                flex-shrink: 0;
            }

            .navbar-logo {
                max-width: 150px;
            }

            .search-section {
                padding: 15px 15px 0;
            }

            .archived-container {
                padding: 15px;
            }

            .archived-row {
                grid-template-columns: minmax(0, 1fr);
                gap: 18px;
            }

            .archived-row:has(> .class-column:nth-child(2):last-child) {
                grid-template-columns: minmax(0, 1fr);
            }

            .archived-row > .class-column {
                width: 100% !important;
            }

            .modal-dialog {
                margin: 12px;
            }
        }

        /* =========================================
           SMALL MOBILE
        ========================================= */

        @media (max-width: 480px) {

            .topbar {
                padding-left: 12px;
                padding-right: 12px;
            }

            .navbar-left {
                gap: 8px;
            }

            .navbar-right {
                gap: 12px;
            }

            .navbar-logo {
                max-width: 125px;
            }

            .search-section {
                padding-left: 12px;
                padding-right: 12px;
            }

            .archived-container {
                padding: 12px;
            }

            .modal-dialog {
                margin: 10px;
            }

            .modal-footer {
                flex-wrap: wrap;
                gap: 8px;
            }

            .modal-footer .btn {
                flex: 1;
            }

            .toast-container {
                right: 12px;
                left: 12px;
                bottom: 12px;
            }

            .toast-container .toast {
                width: 100%;
            }
        }

        /* =========================================
           EXTRA SMALL SCREENS
        ========================================= */

        @media (max-width: 360px) {

            .navbar-logo {
                max-width: 105px;
            }

            .navbar-right {
                gap: 9px;
            }

            .navbar-right i {
                font-size: 16px;
            }

            .search-box .btn {
                padding-left: 10px;
                padding-right: 10px;
            }
        }
    </style>
</head>

<body>

<!-- =========================================
     SIDEBAR OVERLAY
     ONLY ONE OVERLAY ELEMENT
========================================= -->

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
    onclick="closeMobileSidebar()">
</div>

<!-- =========================================
     SIDEBAR
========================================= -->

<div class="sidebar" id="sidebar">

    <div class="sidebar-header">
        <button
            type="button"
            class="toggle-btn"
            onclick="toggleSidebar()"
            aria-label="Toggle sidebar">
            <i class="fa-solid fa-bars"></i>
        </button>
    </div>

    <ul>
        <li>
            <a href="student_dashboard.php">
                <i class="fa-solid fa-house"></i>
                <span>Home</span>
            </a>
        </li>

        <li>
            <a href="student_class_page.php">
                <i class="fa-solid fa-users"></i>
                <span>Classes</span>
            </a>
        </li>

        <li>
            <a href="student_archived_classes.php" class="active">
                <i class="fa-solid fa-box-archive"></i>
                <span>Archived</span>
            </a>
        </li>

        <li>
            <a href="student_assignments.php">
                <i class="fa-solid fa-clipboard-list"></i>
                <span>Assignment</span>
            </a>
        </li>

        <li>
            <a href="student_messages.php">
                <i class="fa-regular fa-message"></i>
                <span>Messages</span>
            </a>
        </li>

        <li>
            <a href="student_performance.php">
                <i class="fa-solid fa-chart-pie"></i>
                <span>Performance</span>
            </a>
        </li>

        <li>
            <a href="student_resources.php">
                <i class="fa-solid fa-book-open"></i>
                <span>Resources</span>
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

<!-- =========================================
     MAIN CONTENT
========================================= -->

<div class="main-content" id="mainContent">

    <!-- TOPBAR -->
    <div class="topbar">

        <div class="navbar-left">

            <button
                type="button"
                class="mobile-menu"
                onclick="mobileSidebar()"
                aria-label="Open navigation"
                aria-expanded="false">
                <i class="fa-solid fa-bars"></i>
            </button>

            <img
                src="img/classroom logo.png"
                class="navbar-logo"
                alt="PLMUN Classroom">

        </div>

        <div class="navbar-right">

            <!-- JOIN CLASS -->
            <a
                href="#"
                class="text-dark"
                data-bs-toggle="modal"
                data-bs-target="#joinClassModal"
                aria-label="Join a class">
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
                    aria-label="Notifications">
                    <i class="fa-regular fa-bell fa-lg"></i>
                </a>

                <div class="dropdown-menu dropdown-menu-end notification-box p-0">

                    <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
                        <h6 class="fw-bold mb-0">Notifications</h6>
                    </div>

                    <div class="notification-item px-3">
                        <div class="avatar flex-shrink-0"></div>

                        <div>
                            <h6 class="mb-0">No new notifications</h6>
                        </div>
                    </div>

                </div>
            </div>

            <!-- PROFILE -->
            <a
                href="profile.php"
                class="profile-icon"
                aria-label="My profile">

                <?php if ($hasProfileImage): ?>

                    <img
                        src="uploads/<?= e(rawurlencode($profileImageFile)) ?>"
                        alt="Profile Picture"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">

                    <i
                        class="fa-solid fa-user profile-fallback"
                        style="display:none;">
                    </i>

                <?php else: ?>

                    <i class="fa-solid fa-user"></i>

                <?php endif; ?>

            </a>

        </div>
    </div>

    <!-- =====================================
         SEARCH SECTION
    ====================================== -->

    <div class="search-section">

        <div class="search-box">

            <form method="GET" action="student_archived_classes.php">

                <div class="input-group">

                    <input
                        type="text"
                        name="search"
                        class="form-control"
                        placeholder="Search archived classes..."
                        value="<?= e($search) ?>">

                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </button>

                    <?php if ($search !== ''): ?>

                        <a
                            href="student_archived_classes.php"
                            class="btn btn-outline-secondary"
                            aria-label="Clear search">
                            <i class="fa-solid fa-xmark"></i>
                        </a>

                    <?php endif; ?>

                </div>
            </form>

        </div>
    </div>

    <!-- =====================================
         ARCHIVED CLASSES
    ====================================== -->

    <div class="archived-container">

        <div class="archived-row">

            <?php if (mysqli_num_rows($archivedClasses) > 0): ?>

                <?php while ($class = mysqli_fetch_assoc($archivedClasses)): ?>

                    <div class="class-column">

                        <div
                            class="class-card"
                            data-class-id="<?= (int)$class['id'] ?>">

                            <a
                                href="student_class.php?id=<?= (int)$class['id'] ?>"
                                class="class-link">

                                <div
                                    class="class-banner"
                                    style="background: <?= e(getClassColor($class['id'], $cardColors)) ?>;">
                                </div>

                                <div class="class-content">

                                    <h3><?= e($class['class_name']) ?></h3>

                                    <p class="teacher-name">
                                        <?= e($class['teacher_name'] ?: 'No instructor assigned yet') ?>
                                    </p>

                                    <p><?= e($class['section']) ?></p>

                                    <p><?= e($class['schedule']) ?></p>

                                    <p><?= e($class['room']) ?></p>

                                </div>

                            </a>

                            <!-- CLASS OPTIONS -->
                            <div class="class-footer">

                                <div class="dropdown">

                                    <button
                                        type="button"
                                        class="class-menu-btn"
                                        data-bs-toggle="dropdown"
                                        aria-expanded="false"
                                        aria-label="Class options">
                                        <i class="fa-solid fa-ellipsis-vertical"></i>
                                    </button>

                                    <ul class="dropdown-menu dropdown-menu-end class-options-menu">

                                        <li>
                                            <button
                                                type="button"
                                                class="dropdown-item unarchive-class-btn">
                                                <i class="fa-solid fa-box-open me-2"></i>
                                                Unarchive
                                            </button>
                                        </li>

                                        <li>
                                            <button
                                                type="button"
                                                class="dropdown-item leave-class-btn"
                                                data-class-name="<?= e($class['class_name']) ?>">
                                                <i class="fa-solid fa-right-from-bracket me-2"></i>
                                                Leave class
                                            </button>
                                        </li>

                                    </ul>
                                </div>
                            </div>

                        </div>
                    </div>

                <?php endwhile; ?>

            <?php else: ?>

                <div class="archived-empty">

                    <div class="alert alert-info mb-0">

                        <?php if ($search !== ''): ?>

                            No archived classes found matching
                            "<strong><?= e($search) ?></strong>".

                        <?php else: ?>

                            You have no archived classes.

                        <?php endif; ?>

                    </div>

                </div>

            <?php endif; ?>

        </div>
    </div>

</div>

<!-- =========================================
     JOIN CLASS MODAL
========================================= -->

<div class="modal fade" id="joinClassModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content join-class-modal">

            <div class="modal-header">
                <h5 class="modal-title">Join Class</h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Close">
                </button>
            </div>

            <div class="modal-body">

                <div class="class-code-box">

                    <h6>Class Code</h6>

                    <p>
                        Ask your teacher for the class code, then enter it here.
                    </p>

                    <input
                        type="text"
                        id="classCodeInput"
                        class="form-control"
                        placeholder="Enter class code"
                        maxlength="10">

                    <div
                        id="joinClassError"
                        class="text-danger small mt-2 d-none">
                    </div>

                </div>
            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-outline-secondary"
                    data-bs-dismiss="modal">
                    Cancel
                </button>

                <button
                    type="button"
                    class="btn btn-primary"
                    id="joinClassBtn">
                    Join class
                </button>

            </div>
        </div>
    </div>
</div>

<!-- =========================================
     LEAVE CLASS MODAL
========================================= -->

<div class="modal fade" id="leaveClassModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content join-class-modal">

            <div class="modal-header">

                <h5 class="modal-title">Leave class?</h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Close">
                </button>

            </div>

            <div class="modal-body">

                <div class="class-code-box">

                    <p class="mb-0">
                        You're about to leave
                        <strong id="leaveClassName">this class</strong>.

                        You'll need a new class code from your teacher to join it again.
                    </p>

                    <div
                        id="leaveClassError"
                        class="text-danger small mt-2 d-none">
                    </div>

                </div>
            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-outline-secondary"
                    data-bs-dismiss="modal">
                    Cancel
                </button>

                <button
                    type="button"
                    class="btn btn-danger"
                    id="confirmLeaveBtn">
                    Leave class
                </button>

            </div>
        </div>
    </div>
</div>

<!-- =========================================
     ACTION TOAST
========================================= -->

<div class="toast-container">

    <div
        id="actionToast"
        class="toast align-items-center text-white border-0"
        role="alert"
        aria-live="assertive"
        aria-atomic="true">

        <div class="d-flex">

            <div class="toast-body" id="actionToastBody">
                Done.
            </div>

            <button
                type="button"
                class="btn-close btn-close-white me-2 m-auto"
                data-bs-dismiss="toast"
                aria-label="Close">
            </button>

        </div>
    </div>
</div>

<!-- =========================================
     JAVASCRIPT
========================================= -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
/* =========================================
   RESPONSIVE SIDEBAR
   SAME BEHAVIOR AS STUDENT DASHBOARD
========================================= */

const sidebarEl = document.getElementById('sidebar');
const mainEl = document.getElementById('mainContent');
const overlayEl = document.getElementById('sidebarOverlay');
const mobileMenuBtn = document.querySelector('.mobile-menu');

function isMobile() {
    return window.innerWidth <= 768;
}

function toggleSidebar() {
    if (isMobile()) {
        mobileSidebar();
    } else {
        sidebarEl.classList.toggle('collapsed');
        mainEl.classList.toggle('expanded');
    }
}

function mobileSidebar() {
    if (!isMobile()) return;

    const isOpen = sidebarEl.classList.toggle('show');

    overlayEl.classList.toggle('show', isOpen);
    document.body.classList.toggle('no-scroll', isOpen);

    mobileMenuBtn?.setAttribute('aria-expanded', String(isOpen));
}

function closeMobileSidebar() {
    sidebarEl.classList.remove('show');
    overlayEl.classList.remove('show');
    document.body.classList.remove('no-scroll');

    mobileMenuBtn?.setAttribute('aria-expanded', 'false');
}

/* Close drawer after clicking a navigation link */
document.querySelectorAll('.sidebar a').forEach(function(link) {
    link.addEventListener('click', function() {
        if (isMobile()) {
            closeMobileSidebar();
        }
    });
});

/* Close drawer with Escape */
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeMobileSidebar();
    }
});

/* Reset mobile drawer when resizing to desktop */
window.addEventListener('resize', function() {
    if (!isMobile()) {
        closeMobileSidebar();
    }
});
</script>

<script>
/* =========================================
   JOIN CLASS
========================================= */

document.getElementById('joinClassBtn').addEventListener('click', function() {

    const code = document.getElementById('classCodeInput').value.trim();
    const errorBox = document.getElementById('joinClassError');
    const btn = this;

    errorBox.classList.add('d-none');
    errorBox.textContent = '';

    if (code === '') {
        errorBox.textContent = 'Please enter a class code.';
        errorBox.classList.remove('d-none');
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Joining...';

    fetch('join_class.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'class_code=' + encodeURIComponent(code)
    })
    .then(async response => {
        const data = await response.json();
        return data;
    })
    .then(data => {

        btn.disabled = false;
        btn.textContent = 'Join class';

        if (data.success) {
            window.location.href = 'student_class.php?id=' + data.class_id;
        } else {
            errorBox.textContent = data.message || 'Unable to join this class.';
            errorBox.classList.remove('d-none');
        }

    })
    .catch(() => {

        btn.disabled = false;
        btn.textContent = 'Join class';

        errorBox.textContent = 'Something went wrong. Please try again.';
        errorBox.classList.remove('d-none');

    });
});
</script>

<script>
/* =========================================
   TOAST HELPER
========================================= */

const actionToastEl = document.getElementById('actionToast');

const actionToast = new bootstrap.Toast(actionToastEl, {
    delay: 3000
});

function showToast(message, type = 'success') {

    document.getElementById('actionToastBody').textContent = message;

    actionToastEl.classList.remove('bg-success', 'bg-danger');

    actionToastEl.classList.add(
        type === 'success' ? 'bg-success' : 'bg-danger'
    );

    actionToast.show();
}

/* =========================================
   REMOVE CLASS CARD
========================================= */

function removeClassCard(classId) {

    const card = document.querySelector(
        '.class-card[data-class-id="' + classId + '"]'
    );

    if (!card) return;

    const column = card.closest('.class-column');

    card.classList.add('removing');

    setTimeout(function() {

        if (column) {
            column.remove();
        }

    }, 250);
}
</script>

<script>
/* =========================================
   UNARCHIVE CLASS
========================================= */

document.querySelectorAll('.unarchive-class-btn').forEach(function(btn) {

    btn.addEventListener('click', function() {

        const card = this.closest('.class-card');

        if (!card) return;

        const classId = card.getAttribute('data-class-id');

        this.disabled = true;

        fetch('unarchive_class.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'class_id=' + encodeURIComponent(classId)
        })
        .then(response => response.json())
        .then(data => {

            this.disabled = false;

            if (data.success) {

                removeClassCard(classId);

                showToast('Class moved back to your dashboard.');

            } else {

                showToast(
                    data.message || 'Could not unarchive class.',
                    'danger'
                );

            }

        })
        .catch(() => {

            this.disabled = false;

            showToast(
                'Something went wrong. Please try again.',
                'danger'
            );

        });
    });
});
</script>

<script>
/* =========================================
   LEAVE CLASS
========================================= */

let pendingLeaveClassId = null;

const leaveClassModalEl = document.getElementById('leaveClassModal');

const leaveClassModal = new bootstrap.Modal(leaveClassModalEl);

/* Open confirmation modal */
document.querySelectorAll('.leave-class-btn').forEach(function(btn) {

    btn.addEventListener('click', function() {

        const card = this.closest('.class-card');

        if (!card) return;

        pendingLeaveClassId = card.getAttribute('data-class-id');

        document.getElementById('leaveClassName').textContent =
            this.getAttribute('data-class-name') || 'this class';

        document.getElementById('leaveClassError').classList.add('d-none');

        leaveClassModal.show();
    });
});

/* Confirm leaving class */
document.getElementById('confirmLeaveBtn').addEventListener('click', function() {

    if (!pendingLeaveClassId) return;

    const btn = this;
    const errorBox = document.getElementById('leaveClassError');

    errorBox.classList.add('d-none');
    errorBox.textContent = '';

    btn.disabled = true;
    btn.textContent = 'Leaving...';

    fetch('leave_class.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'class_id=' + encodeURIComponent(pendingLeaveClassId)
    })
    .then(response => response.json())
    .then(data => {

        btn.disabled = false;
        btn.textContent = 'Leave class';

        if (data.success) {

            const classId = pendingLeaveClassId;

            leaveClassModal.hide();

            removeClassCard(classId);

            showToast('You left the class.');

            pendingLeaveClassId = null;

        } else {

            errorBox.textContent =
                data.message || 'Could not leave class.';

            errorBox.classList.remove('d-none');

        }

    })
    .catch(() => {

        btn.disabled = false;
        btn.textContent = 'Leave class';

        errorBox.textContent =
            'Something went wrong. Please try again.';

        errorBox.classList.remove('d-none');

    });
});

/* Reset pending class when modal closes */
leaveClassModalEl.addEventListener('hidden.bs.modal', function() {
    pendingLeaveClassId = null;

    const btn = document.getElementById('confirmLeaveBtn');
    btn.disabled = false;
    btn.textContent = 'Leave class';
});
</script>

</body>
</html>

<?php
mysqli_stmt_close($query);
?>
