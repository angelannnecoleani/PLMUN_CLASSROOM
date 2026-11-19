<?php
session_start();
require_once 'database.php';

if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'student'
) {
    header("Location: login.php");
    exit();
}

$student_id = (int) $_SESSION['user_id'];

/* =========================================
   HELPER FUNCTION
========================================= */

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/* =========================================
   USER INFORMATION
========================================= */

$userStmt = mysqli_prepare(
    $conn,
    "SELECT * FROM users WHERE id = ?"
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

$profileImageFile = '';
$hasProfileImage = false;

$profileImageFile = trim((string)($user['profile_image'] ?? ''));

$defaultImages = [
    '',
    'null',
    'default.png',
    'default.jpg',
    'default.jpeg',
    'default.svg',
    'user.png',
    'user.jpg',
    'user.jpeg'
];

if ($profileImageFile !== '') {

    $safeName = basename($profileImageFile);

    $imagePath = __DIR__ . '/uploads/' . $safeName;

    if (
        !in_array(strtolower($safeName), $defaultImages, true) &&
        is_file($imagePath)
    ) {
        $profileImageFile = $safeName;
        $hasProfileImage = true;
    }
}

if (!$hasProfileImage) {
    $profileImageFile = '';
}

/* =========================================
   SEARCH
========================================= */

$search = trim($_GET['search'] ?? '');

/* =========================================
   MY CLASSES
   Primary instructor + co-instructors
========================================= */

$myClasses = [];

$myClassesSql = "
    SELECT
        c.*,

        COALESCE(
            teacher.name,
            'No instructor assigned yet'
        ) AS teacher_name,

        COALESCE(
            co.co_instructor_names,
            ''
        ) AS co_instructor_names

    FROM class_students cs

    INNER JOIN classes c
        ON cs.class_id = c.id

    LEFT JOIN users AS teacher
        ON c.teacher_id = teacher.id

    LEFT JOIN (

        SELECT
            ci.class_id,

            GROUP_CONCAT(
                DISTINCT u.name
                ORDER BY u.name ASC
                SEPARATOR ', '
            ) AS co_instructor_names

        FROM class_instructors ci

        INNER JOIN classes c2
            ON ci.class_id = c2.id

        INNER JOIN users u
            ON ci.teacher_id = u.id

        WHERE
            c2.teacher_id IS NULL
            OR ci.teacher_id <> c2.teacher_id

        GROUP BY ci.class_id

    ) AS co
        ON co.class_id = c.id

    WHERE cs.student_id = ?

      AND (
          cs.archived = 0
          OR cs.archived IS NULL
      )
";

if ($search !== '') {

    $myClassesSql .= "
        AND (
            c.class_name LIKE ?
            OR c.program LIKE ?
            OR c.section LIKE ?
            OR teacher.name LIKE ?
            OR co.co_instructor_names LIKE ?
        )
    ";
}

$myClassesSql .= "
    ORDER BY cs.joined_at DESC
";

$myClassesStmt = mysqli_prepare($conn, $myClassesSql);

if (!$myClassesStmt) {
    die("Database error: " . e(mysqli_error($conn)));
}

if ($search !== '') {

    $searchTerm = "%{$search}%";

    mysqli_stmt_bind_param(
        $myClassesStmt,
        "isssss",
        $student_id,
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm
    );

} else {

    mysqli_stmt_bind_param(
        $myClassesStmt,
        "i",
        $student_id
    );
}

mysqli_stmt_execute($myClassesStmt);

$myClassesResult = mysqli_stmt_get_result($myClassesStmt);

while ($row = mysqli_fetch_assoc($myClassesResult)) {
    $myClasses[] = $row;
}

mysqli_stmt_close($myClassesStmt);

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

function getClassColor($classId, $colors) {
    $index = abs(crc32((string)$classId)) % count($colors);
    return $colors[$index];
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Student Dashboard</title>

    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">

    <!-- BOOTSTRAP -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- FONT AWESOME -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <!-- CUSTOM CSS -->
    <link rel="stylesheet" href="student_dashboard.css">

    <!-- NOTIFICATIONS CSS -->
    <link rel="stylesheet" href="notifications.css">

    <style>

        .dashboard-section-title {
            font-size: 21px;
            font-weight: 700;
            margin-bottom: 20px;
            color: #333;
        }

        .program-name {
            font-size: 12px;
            color: #777;
            margin-top: 8px;
            margin-bottom: 8px;
            line-height: 1.5;
        }

        .class-card {
            height: 100%;
            position: relative;
        }

        .class-card .class-link {
            text-decoration: none;
            color: inherit;
        }

        .teacher-name {
            font-size: 14px;
            color: #555;
            margin-bottom: 12px;
        }

        /* CO-INSTRUCTOR DISPLAY */

        .co-instructor-name {
            font-size: 12px;
            color: #64748b;
            margin-top: -8px;
            margin-bottom: 10px;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }

        .co-instructor-name i {
            color: #3f87b5;
        }

        .empty-class-message {
            background: #fff;
            border: 1px dashed #ccc;
            border-radius: 10px;
            padding: 35px 20px;
            text-align: center;
            color: #777;
        }

        .empty-class-message i {
            font-size: 35px;
            margin-bottom: 12px;
            color: #aaa;
        }

        .class-count {
            background: #eaf3f9;
            color: #3f87b5;
            border-radius: 20px;
            padding: 4px 10px;
            font-size: 12px;
            margin-left: 8px;
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
            type="button"
            class="toggle-btn"
            onclick="toggleSidebar()"
            aria-label="Toggle sidebar">

            <i class="fa-solid fa-bars"></i>

        </button>

    </div>

    <ul>

        <li>
            <a href="student_dashboard.php" class="active">
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
            <a href="student_archived_classes.php">
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

<!-- SIDEBAR OVERLAY -->

<div
    class="sidebar-overlay"
    id="sidebarOverlay">
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
                aria-label="Open navigation menu"
                aria-expanded="false"
                aria-controls="sidebar">

                <i class="fa-solid fa-bars"></i>

            </button>

            <img
                src="img/classroom logo.png"
                alt="PLMUN Classroom"
                class="navbar-logo">

        </div>


        <div class="navbar-right">

            <!-- JOIN CLASS -->

            <a
                href="#"
                class="topbar-action"
                data-bs-toggle="modal"
                data-bs-target="#joinClassModal"
                title="Join class"
                aria-label="Join class">

                <i class="fa-solid fa-plus fa-lg"></i>

            </a>


            <!-- NOTIFICATIONS -->

            <div class="dropdown">

                <a
                    href="#"
                    class="position-relative text-dark topbar-action"
                    role="button"
                    data-bs-toggle="dropdown"
                    data-bs-auto-close="outside"
                    aria-expanded="false"
                    aria-label="Notifications">

                    <i class="fa-regular fa-bell fa-lg"></i>

                    <span
                        class="notif-badge d-none"
                        id="notifBadge">0</span>

                </a>


                <div class="dropdown-menu dropdown-menu-end notification-box p-0">

                    <div class="d-flex justify-content-between align-items-center p-3 border-bottom">

                        <h6 class="fw-bold mb-0">Notifications</h6>

                        <button
                            type="button"
                            class="btn btn-link btn-sm p-0 text-decoration-none d-none"
                            id="markAllReadBtn">

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

            <a href="profile.php" class="profile-icon" aria-label="My profile">

                <?php if ($hasProfileImage): ?>

                    <img
                        src="uploads/<?= e($profileImageFile) ?>"
                        alt="Profile Picture"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                    >

                    <i class="fa-solid fa-user profile-fallback"
                    style="display: none;"></i>

                <?php else: ?>

                    <i class="fa-solid fa-user"></i>

                <?php endif; ?>

            </a>

        </div>

    </div>


    <!-- =========================================
         SEARCH SECTION
    ========================================= -->

    <div class="search-section">

        <div class="search-box">

            <form method="GET" action="">

                <div class="input-group">

                    <div class="search-input-wrap">

                        <input
                            type="text"
                            name="search"
                            class="form-control"
                            placeholder="Search your classes"
                            value="<?= e($search) ?>"
                        >

                        <?php if ($search !== ''): ?>

                            <a
                                href="student_dashboard.php"
                                class="search-clear-inside"
                                aria-label="Clear search">

                                <i class="fa-solid fa-xmark"></i>

                            </a>

                        <?php endif; ?>

                    </div>

                    <button type="submit" class="btn btn-primary">

                        <i class="fa-solid fa-magnifying-glass"></i>

                    </button>

                </div>

            </form>

        </div>

    </div>


    <!-- =========================================
         DASHBOARD CONTENT
    ========================================= -->

    <div class="container-fluid dashboard-container">

        <div class="row mt-2">

            <!-- SECTION TITLE -->

            <div class="col-12">

                <div class="section-heading">

                    <h4 class="dashboard-section-title">

                        <i class="fa-solid fa-book-open me-2"></i>

                        My Classes

                        <span class="class-count">
                            <?= count($myClasses) ?>
                        </span>

                    </h4>

                </div>

            </div>


            <!-- =====================================
                 CLASS CARDS
            ====================================== -->

            <?php if (count($myClasses) > 0): ?>

                <?php foreach ($myClasses as $class): ?>

                    <div class="col-xl-4 col-lg-6 col-md-6 col-12 mb-4 class-column">

                        <div
                            class="class-card"
                            data-class-id="<?= (int)$class['id'] ?>">

                            <!-- CLASS LINK -->

                            <a
                                href="student_class.php?id=<?= (int)$class['id'] ?>"
                                class="class-link">

                                <div
                                    class="class-banner"
                                    style="background: <?= e(getClassColor($class['id'], $cardColors)) ?>;">
                                </div>


                                <div class="class-content">

                                    <h3>
                                        <?= e($class['class_name']) ?>
                                    </h3>


                                    <!-- PRIMARY INSTRUCTOR -->

                                    <p class="teacher-name">

                                        <i class="fa-solid fa-chalkboard-user me-1"></i>

                                        <?= e($class['teacher_name']) ?>

                                    </p>


                                    <!-- CO-INSTRUCTORS -->

                                    <?php if (!empty($class['co_instructor_names'])): ?>

                                        <p class="co-instructor-name">

                                            <i class="fa-solid fa-user-group me-1"></i>

                                            Co-Instructor:

                                            <?= e($class['co_instructor_names']) ?>

                                        </p>

                                    <?php endif; ?>


                                    <!-- PROGRAM -->

                                    <p class="program-name">

                                        <i class="fa-solid fa-graduation-cap me-1"></i>

                                        <?= e($class['program'] ?: 'Program not specified') ?>

                                    </p>


                                    <!-- SECTION -->

                                    <p>

                                        <i class="fa-solid fa-users me-1"></i>

                                        <?= e($class['section']) ?>

                                    </p>


                                    <!-- SCHEDULE -->

                                    <p>

                                        <i class="fa-regular fa-clock me-1"></i>

                                        <?= e($class['schedule']) ?>

                                    </p>


                                    <!-- ROOM -->

                                    <p>

                                        <i class="fa-solid fa-location-dot me-1"></i>

                                        <?= e($class['room']) ?>

                                    </p>

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
                                                class="dropdown-item archive-class-btn">

                                                <i class="fa-solid fa-box-archive"></i>

                                                Archive

                                            </button>

                                        </li>


                                        <li>

                                            <button
                                                type="button"
                                                class="dropdown-item leave-class-btn"
                                                data-class-name="<?= e($class['class_name']) ?>">

                                                <i class="fa-solid fa-right-from-bracket"></i>

                                                Leave class

                                            </button>

                                        </li>

                                    </ul>

                                </div>

                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>


            <?php else: ?>

                <!-- EMPTY STATE -->

                <div class="col-12">

                    <div class="empty-class-message">

                        <i class="fa-solid fa-book-open d-block"></i>

                        <h6 class="fw-bold">No classes yet</h6>

                        <p class="mb-0">

                            <?php if ($search !== ''): ?>

                                No classes match your search.
                                Try searching using another keyword.

                            <?php else: ?>

                                You haven't joined any classes yet.
                                Enter a class code using the plus (+) button to join a class.

                            <?php endif; ?>

                        </p>

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
                        Enter the class code provided by your instructor.
                    </p>

                    <input
                        type="text"
                        id="classCodeInput"
                        class="form-control"
                        placeholder="Enter class code"
                        maxlength="20"
                        autocomplete="off"
                    >

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

<script src="notifications.js"></script>


<script>

/* =========================================
   RESPONSIVE SIDEBAR
========================================= */

const sidebarEl = document.getElementById('sidebar');
const mainEl = document.getElementById('mainContent');
const overlayEl = document.getElementById('sidebarOverlay');

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

    const isOpen = sidebarEl.classList.toggle('show');

    overlayEl.classList.toggle('show', isOpen);

    document.body.classList.toggle('no-scroll', isOpen);

    document.querySelector('.mobile-menu')
        ?.setAttribute('aria-expanded', String(isOpen));

}

function closeMobileSidebar() {

    sidebarEl.classList.remove('show');

    overlayEl.classList.remove('show');

    document.body.classList.remove('no-scroll');

    document.querySelector('.mobile-menu')
        ?.setAttribute('aria-expanded', 'false');

}

document.querySelectorAll('.sidebar a').forEach(function(a) {

    a.addEventListener('click', function() {

        if (isMobile()) {
            closeMobileSidebar();
        }

    });

});

overlayEl.addEventListener('click', closeMobileSidebar);

document.addEventListener('keydown', function(event) {

    if (event.key === 'Escape') {
        closeMobileSidebar();
    }

});

window.addEventListener('resize', function() {

    if (!isMobile()) {
        closeMobileSidebar();
    }

});


/* =========================================
   JOIN CLASS MODAL
========================================= */

const joinClassModal = document.getElementById('joinClassModal');

const classCodeInput = document.getElementById('classCodeInput');

const joinClassError = document.getElementById('joinClassError');

joinClassModal.addEventListener('show.bs.modal', function() {

    joinClassError.classList.add('d-none');

    joinClassError.textContent = '';

    classCodeInput.value = '';

});

document.getElementById('joinClassBtn').addEventListener('click', function() {

    const code = classCodeInput.value.trim();

    const errorBox = joinClassError;

    const btn = this;

    errorBox.classList.add('d-none');

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

    .then(res => res.json())

    .then(data => {

        btn.disabled = false;

        btn.textContent = 'Join class';

        if (data.success) {

            window.location.href = 'student_class.php?id=' + data.class_id;

        } else {

            errorBox.textContent = data.message || 'Unable to join class.';

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

    const col = card.closest('.class-column');

    card.classList.add('removing');

    setTimeout(() => {

        if (col) {
            col.remove();
        }

        updateClassCount();

    }, 250);

}

function updateClassCount() {

    const remainingCards = document.querySelectorAll('.class-card').length;

    const countElement = document.querySelector('.class-count');

    if (countElement) {
        countElement.textContent = remainingCards;
    }

    if (remainingCards === 0) {

        const row = document.querySelector('.dashboard-container .row');

        if (row && !document.querySelector('.empty-class-message')) {

            const empty = document.createElement('div');

            empty.className = 'col-12';

            empty.innerHTML = `
                <div class="empty-class-message">
                    <i class="fa-solid fa-book-open d-block"></i>
                    <h6 class="fw-bold">No classes yet</h6>
                    <p class="mb-0">
                        You haven't joined any classes yet.
                        Enter a class code using the plus (+) button to join a class.
                    </p>
                </div>
            `;

            row.appendChild(empty);

        }

    }

}


/* =========================================
   ARCHIVE CLASS
========================================= */

document.querySelectorAll('.archive-class-btn').forEach(function(btn) {

    btn.addEventListener('click', function() {

        const classId = this.closest('.class-card')
            .getAttribute('data-class-id');

        const menu = this.closest('.dropdown-menu');

        const dropdownToggle = menu?.previousElementSibling;

        const dropdown = dropdownToggle
            ? bootstrap.Dropdown.getInstance(dropdownToggle)
            : null;

        dropdown?.hide();

        fetch('archive_class.php', {

            method: 'POST',

            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },

            body: 'class_id=' + encodeURIComponent(classId)

        })

        .then(res => res.json())

        .then(data => {

            if (data.success) {

                removeClassCard(classId);

                showToast('Class archived.');

            } else {

                showToast(data.message || 'Could not archive class.', 'danger');

            }

        })

        .catch(() => {

            showToast('Something went wrong. Please try again.', 'danger');

        });

    });

});


/* =========================================
   LEAVE CLASS
========================================= */

let pendingLeaveClassId = null;

const leaveClassModal = new bootstrap.Modal(
    document.getElementById('leaveClassModal')
);

document.querySelectorAll('.leave-class-btn').forEach(function(btn) {

    btn.addEventListener('click', function() {

        pendingLeaveClassId = this.closest('.class-card')
            .getAttribute('data-class-id');

        document.getElementById('leaveClassName').textContent =
            this.getAttribute('data-class-name');

        document.getElementById('leaveClassError').classList.add('d-none');

        leaveClassModal.show();

    });

});

document.getElementById('confirmLeaveBtn').addEventListener('click', function() {

    if (!pendingLeaveClassId) return;

    const btn = this;

    const errorBox = document.getElementById('leaveClassError');

    errorBox.classList.add('d-none');

    btn.disabled = true;

    btn.textContent = 'Leaving...';

    fetch('leave_class.php', {

        method: 'POST',

        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },

        body: 'class_id=' + encodeURIComponent(pendingLeaveClassId)

    })

    .then(res => res.json())

    .then(data => {

        btn.disabled = false;

        btn.textContent = 'Leave class';

        if (data.success) {

            leaveClassModal.hide();

            removeClassCard(pendingLeaveClassId);

            showToast('You left the class.');

            pendingLeaveClassId = null;

        } else {

            errorBox.textContent = data.message || 'Could not leave class.';

            errorBox.classList.remove('d-none');

        }

    })

    .catch(() => {

        btn.disabled = false;

        btn.textContent = 'Leave class';

        errorBox.textContent = 'Something went wrong. Please try again.';

        errorBox.classList.remove('d-none');

    });

});


/* =========================================
   RESET BUTTONS WHEN MODALS CLOSE
========================================= */

document.getElementById('joinClassModal')
    .addEventListener('hidden.bs.modal', function() {

        const btn = document.getElementById('joinClassBtn');

        btn.disabled = false;

        btn.textContent = 'Join class';

    });

document.getElementById('leaveClassModal')
    .addEventListener('hidden.bs.modal', function() {

        const btn = document.getElementById('confirmLeaveBtn');

        btn.disabled = false;

        btn.textContent = 'Leave class';

    });

</script>

</body>
</html>
