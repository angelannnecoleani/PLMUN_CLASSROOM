<?php
session_start();
include 'database.php';

/* =========================================
   DIRECTOR ACCESS
========================================= */
if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'director'
) {
    header("Location: login.php");
    exit();
}

$directorName = $director['name'] ?? 'Director';

/* =========================================
   HELPER
========================================= */
function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$error = '';
$success = '';

/* =========================================
   FETCH PROGRAMS FROM DATABASE
   Uses the existing programs table:
   id, college_name, college_code, program_name
========================================= */
$programsByCollege = [];
$programNames = [];

$programQuery = mysqli_query(
    $conn,
    "SELECT id, college_name, college_code, program_name
     FROM programs
     ORDER BY college_name ASC, program_name ASC"
);

if ($programQuery) {
    while ($row = mysqli_fetch_assoc($programQuery)) {
        $college = $row['college_name'];
        $programsByCollege[$college][] = $row;
        $programNames[] = $row['program_name'];
    }
} else {
    $error = "Unable to load academic programs. Please check the programs table.";
}

/* =========================================
   FETCH INSTRUCTORS
========================================= */
$teachers = [];

$teacherQuery = mysqli_query(
    $conn,
    "SELECT id, name, email
     FROM users
     WHERE role = 'teacher'
     ORDER BY name ASC"
);

if ($teacherQuery) {
    while ($teacher = mysqli_fetch_assoc($teacherQuery)) {
        $teachers[] = $teacher;
    }
}

/* =========================================
   FORM VALUES
========================================= */
$class_name = '';
$program = '';
$section = '';
$academic_year = '';
$schedule = '';
$room = '';
$teacher_id = '';
$max_students = 40;

/* =========================================
   CREATE CLASS
========================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $class_name = trim($_POST['class_name'] ?? '');
    $program = trim($_POST['program'] ?? '');
    $section = trim($_POST['section'] ?? '');
    $academic_year = trim($_POST['academic_year'] ?? '');
    $schedule = trim($_POST['schedule'] ?? '');
    $room = trim($_POST['room'] ?? '');
    $teacher_id_input = trim($_POST['teacher_id'] ?? '');
    $max_students = (int)($_POST['max_students'] ?? 40);

    if (
        $class_name === '' ||
        $program === '' ||
        $section === '' ||
        $academic_year === '' ||
        $schedule === '' ||
        $room === ''
    ) {
        $error = "Please complete all required fields.";
    } elseif ($max_students < 1) {
        $error = "Maximum students must be at least 1.";
    } else {
        /* Confirm the selected program exists in the programs table. */
        $programCheck = mysqli_prepare(
            $conn,
            "SELECT program_name
             FROM programs
             WHERE program_name = ?
             LIMIT 1"
        );

        if (!$programCheck) {
            $error = "Unable to validate the selected program: " . mysqli_error($conn);
        } else {
            mysqli_stmt_bind_param($programCheck, "s", $program);
            mysqli_stmt_execute($programCheck);
            $programResult = mysqli_stmt_get_result($programCheck);
            $validProgram = mysqli_fetch_assoc($programResult);
            mysqli_stmt_close($programCheck);

            if (!$validProgram) {
                $error = "Please select a valid academic program.";
            }
        }
    }

    /* Validate instructor, if selected. */
    $teacher_id = null;

    if ($error === '' && $teacher_id_input !== '') {
        $teacher_id = (int)$teacher_id_input;

        $checkTeacher = mysqli_prepare(
            $conn,
            "SELECT id
             FROM users
             WHERE id = ? AND role = 'teacher'
             LIMIT 1"
        );

        if (!$checkTeacher) {
            $error = "Unable to validate the selected instructor.";
        } else {
            mysqli_stmt_bind_param($checkTeacher, "i", $teacher_id);
            mysqli_stmt_execute($checkTeacher);
            $teacherResult = mysqli_stmt_get_result($checkTeacher);
            $assignedTeacher = mysqli_fetch_assoc($teacherResult);
            mysqli_stmt_close($checkTeacher);

            if (!$assignedTeacher) {
                $error = "The selected instructor is invalid.";
            }
        }
    }

    /* Insert class only when all validations pass. */
    if ($error === '') {
        try {
            do {
                $class_code = strtoupper(bin2hex(random_bytes(4)));

                $checkCode = mysqli_prepare(
                    $conn,
                    "SELECT id FROM classes WHERE class_code = ? LIMIT 1"
                );

                if (!$checkCode) {
                    throw new Exception("Unable to check class code: " . mysqli_error($conn));
                }

                mysqli_stmt_bind_param($checkCode, "s", $class_code);
                mysqli_stmt_execute($checkCode);
                $codeResult = mysqli_stmt_get_result($checkCode);
                $codeExists = mysqli_num_rows($codeResult) > 0;
                mysqli_stmt_close($checkCode);
            } while ($codeExists);

            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO classes
                    (class_name, program, section, academic_year, schedule,
                     room, class_code, teacher_id, max_students)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            if (!$stmt) {
                throw new Exception("SQL Prepare Error: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param(
                $stmt,
                "sssssssii",
                $class_name,
                $program,
                $section,
                $academic_year,
                $schedule,
                $room,
                $class_code,
                $teacher_id,
                $max_students
            );

            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Unable to create class: " . mysqli_stmt_error($stmt));
            }

            mysqli_stmt_close($stmt);

            $_SESSION['class_success'] =
                "Class created successfully! Class code: " . $class_code;

            header("Location: class_management.php");
            exit();

        } catch (Throwable $ex) {
            $error = $ex->getMessage();
        }
    }
}

/* =========================================
   SUCCESS MESSAGE
========================================= */
if (isset($_SESSION['class_success'])) {
    $success = $_SESSION['class_success'];
    unset($_SESSION['class_success']);
}

/* =========================================
   FETCH ALL CLASSES
   Director can monitor classes across programs.
========================================= */
$classes = [];

$classQuery = mysqli_query(
    $conn,
    "SELECT
        c.*,
        u.name AS teacher_name,
        p.college_name
     FROM classes c
     LEFT JOIN users u
        ON c.teacher_id = u.id
     LEFT JOIN programs p
        ON TRIM(c.program) = TRIM(p.program_name)
     ORDER BY c.created_at DESC, c.id DESC"
);

if ($classQuery) {
    while ($class = mysqli_fetch_assoc($classQuery)) {
        $classes[] = $class;
    }
} elseif ($error === '') {
    $error = "Unable to load classes: " . mysqli_error($conn);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Management | PLMUN Classroom</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="class_management.css">
    
</head>

<body>

<!-- SIDEBAR -->

<div class="sidebar" id="sidebar">

    <div class="sidebar-header">

        <button class="toggle-btn" onclick="toggleSidebar()">
            <i class="fa-solid fa-bars"></i>
        </button>


    </div>

    <ul>

        <li>
            <a href="director_dashboard.php">
                <i class="fa-solid fa-house"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <li>
            <a href="class_management.php" class="active">
                <i class="fa-solid fa-book"></i>
                <span>Class Management</span>
            </a>
        </li>

        <li>
            <a href="instructor_assignment.php">
                <i class="fa-solid fa-user-check"></i>
                <span>Instructor Assignment</span>
            </a>
        </li>

        <li>
            <a href="class_monitoring.php">
                <i class="fa-solid fa-chart-line"></i>
                <span>Class Monitoring</span>
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

<div class="sidebar-overlay" id="sidebarOverlay" onclick="mobileSidebar()"></div>

<div class="main-content" id="mainContent">
    <div class="topbar">

        <div class="topbar-logo d-flex align-items-center gap-3">

            <button class="mobile-menu" onclick="mobileSidebar()">
                <i class="fa-solid fa-bars"></i>
            </button>

            <img src="img/plmun logo.png" alt="logo">

            <h5>PLMUN Director</h5>

        </div>

        <div class="director-info">

            <div class="director-avatar">
                <i class="fa-solid fa-user-tie"></i>
            </div>

            <span><?php echo e($directorName); ?></span>

        </div>

    </div>

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h2>
                <i class="fa-solid fa-chalkboard-user me-2"></i>
                Class Management
            </h2>
            <p>Create classes, assign instructors, and monitor classes across all academic programs.</p>
        </div>

        <a href="director_dashboard.php" class="btn btn-outline-secondary">
            <i class="fa-solid fa-arrow-left me-2"></i>
            Back to Dashboard
        </a>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fa-solid fa-circle-check me-2"></i>
            <?= e($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fa-solid fa-circle-exclamation me-2"></i>
            <?= e($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (empty($programsByCollege)): ?>
        <div class="alert alert-warning">
            No academic programs were found in the <strong>programs</strong> table.
            Add the programs first before creating a class.
        </div>
    <?php endif; ?>

    <!-- CREATE CLASS -->
    <div class="content-card">
        <h5>
            <i class="fa-solid fa-plus-circle me-2 text-primary"></i>
            Create New Class
        </h5>

        <form method="POST" action="">
            <div class="row g-3">

                <div class="col-md-6">
                    <label class="form-label">Class / Subject Name *</label>
                    <input
                        type="text"
                        name="class_name"
                        class="form-control"
                        placeholder="Enter subject name"
                        value="<?= e($class_name) ?>"
                        maxlength="150"
                        required
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label">Academic Program *</label>
                    <select name="program" class="form-select" required>
                        <option value="">Select Academic Program</option>

                        <?php foreach ($programsByCollege as $college => $collegePrograms): ?>
                            <optgroup label="<?= e($college) ?>">
                                <?php foreach ($collegePrograms as $programRow): ?>
                                    <option
                                        value="<?= e($programRow['program_name']) ?>"
                                        <?= $program === $programRow['program_name'] ? 'selected' : '' ?>
                                    >
                                        <?= e($programRow['program_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">
                        Program names are loaded directly from the Programs database.
                    </small>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Section *</label>
                    <input
                        type="text"
                        name="section"
                        class="form-control"
                        placeholder="Example: BSIT 3A"
                        value="<?= e($section) ?>"
                        required
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label">Academic Year *</label>
                    <input
                        type="text"
                        name="academic_year"
                        class="form-control"
                        placeholder="Example: 2026-2027"
                        value="<?= e($academic_year) ?>"
                        required
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label">Schedule *</label>
                    <input
                        type="text"
                        name="schedule"
                        class="form-control"
                        placeholder="Example: Monday / 8:00 AM - 10:00 AM"
                        value="<?= e($schedule) ?>"
                        required
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label">Room *</label>
                    <input
                        type="text"
                        name="room"
                        class="form-control"
                        placeholder="Example: Room 301"
                        value="<?= e($room) ?>"
                        required
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label">Assign Instructor</label>
                    <select name="teacher_id" class="form-select">
                        <option value="">No Instructor Assigned Yet</option>
                        <?php foreach ($teachers as $teacher): ?>
                            <option
                                value="<?= (int)$teacher['id'] ?>"
                                <?= (string)$teacher_id === (string)$teacher['id'] ? 'selected' : '' ?>
                            >
                                <?= e($teacher['name']) ?> (<?= e($teacher['email']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">You can assign an instructor later.</small>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Maximum Students *</label>
                    <input
                        type="number"
                        name="max_students"
                        class="form-control"
                        min="1"
                        value="<?= e($max_students) ?>"
                        required
                    >
                </div>

            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary" <?= empty($programsByCollege) ? 'disabled' : '' ?>>
                    <i class="fa-solid fa-plus me-2"></i>
                    Create Class
                </button>
            </div>
        </form>
    </div>

    <!-- MANAGE CLASSES -->
    <div class="content-card">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h5 class="mb-1">
                    <i class="fa-solid fa-list-check me-2 text-primary"></i>
                    Manage Classes
                </h5>
                <small class="text-muted">
                    All classes are displayed regardless of their assigned academic program.
                </small>
            </div>

            <span class="badge bg-primary">
                <?= count($classes) ?> Classes
            </span>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-md-6">
                <input
                    type="search"
                    id="classSearch"
                    class="form-control"
                    placeholder="Search class, program, section, or instructor..."
                >
            </div>
            <div class="col-md-4">
                <select id="programFilter" class="form-select">
                    <option value="">All Programs</option>
                    <?php foreach ($programsByCollege as $college => $collegePrograms): ?>
                        <optgroup label="<?= e($college) ?>">
                            <?php foreach ($collegePrograms as $programRow): ?>
                                <option value="<?= e(mb_strtolower($programRow['program_name'])) ?>">
                                    <?= e($programRow['program_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="button" id="clearFilters" class="btn btn-outline-secondary w-100">
                    <i class="fa-solid fa-rotate-left me-1"></i> Clear
                </button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle" id="classesTable">
                <thead>
                    <tr>
                        <th>Subject / Class</th>
                        <th>College</th>
                        <th>Program</th>
                        <th>Section</th>
                        <th>Academic Year</th>
                        <th>Schedule</th>
                        <th>Room</th>
                        <th>Instructor</th>
                        <th>Class Code</th>
                        <th>Capacity</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($classes)): ?>
                        <?php foreach ($classes as $class): ?>
                            <tr class="class-row">
                                <td><strong><?= e($class['class_name']) ?></strong></td>
                                <td><?= e($class['college_name'] ?: 'Program not matched') ?></td>
                                <td>
                                    <span class="program-badge"><?= e($class['program'] ?: 'Not assigned') ?></span>
                                </td>
                                <td><?= e($class['section']) ?></td>
                                <td><?= e($class['academic_year']) ?></td>
                                <td><?= e($class['schedule']) ?></td>
                                <td><?= e($class['room']) ?></td>
                                <td>
                                    <?php if (!empty($class['teacher_name'])): ?>
                                        <span class="text-success">
                                            <i class="fa-solid fa-user-check me-1"></i>
                                            <?= e($class['teacher_name']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="unassigned">
                                            <i class="fa-solid fa-user-clock me-1"></i>
                                            Not Assigned
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="class-code"><?= e($class['class_code']) ?></span></td>
                                <td><?= (int)$class['max_students'] ?> Students</td>
                                <td>
                                    <a
                                        href="edit_class.php?id=<?= (int)$class['id'] ?>"
                                        class="btn btn-sm btn-primary"
                                        title="Edit Class"
                                    >
                                        <i class="fa-solid fa-pen-to-square me-1"></i> Edit
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="11" class="text-center empty-state">
                                <i class="fa-solid fa-folder-open fa-2x mb-3"></i>
                                <p class="mb-0">No classes have been created yet.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <tr id="noSearchResults" style="display:none;">
                        <td colspan="11" class="text-center text-muted py-4">
                            No classes match your search or selected program.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

/* SIDEBAR ELEMENTS */

const sidebar = document.getElementById('sidebar');
const mainContent = document.getElementById('mainContent');
const overlay = document.getElementById('sidebarOverlay');

/* DESKTOP SIDEBAR */

function toggleSidebar() {

    if (window.innerWidth <= 768) {
        mobileSidebar();
        return;
    }

    sidebar.classList.toggle('collapsed');
    mainContent.classList.toggle('expanded');

}

/* MOBILE SIDEBAR */

function mobileSidebar() {

    sidebar.classList.toggle('show');

    const isOpen = sidebar.classList.contains('show');

    overlay.classList.toggle('show', isOpen);

}

/* RESET MOBILE SIDEBAR ON RESIZE */

window.addEventListener('resize', function() {

    if (window.innerWidth > 768) {

        sidebar.classList.remove('show');
        overlay.classList.remove('show');

    }

});


document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('classSearch');
    const programFilter = document.getElementById('programFilter');
    const clearButton = document.getElementById('clearFilters');
    const rows = Array.from(document.querySelectorAll('.class-row'));
    const noResults = document.getElementById('noSearchResults');

    function filterClasses() {
        const search = searchInput.value.trim().toLowerCase();
        const selectedProgram = programFilter.value.toLowerCase();
        let visibleCount = 0;

        rows.forEach(function (row) {
            const rowText = row.innerText.toLowerCase();
            const programCell = row.cells[2]?.innerText.toLowerCase() || '';

            const matchesSearch = rowText.includes(search);
            const matchesProgram = !selectedProgram || programCell.includes(selectedProgram);
            const show = matchesSearch && matchesProgram;

            row.style.display = show ? '' : 'none';

            if (show) visibleCount++;
        });

        noResults.style.display = (rows.length > 0 && visibleCount === 0) ? '' : 'none';
    }

    searchInput.addEventListener('input', filterClasses);
    programFilter.addEventListener('change', filterClasses);

    clearButton.addEventListener('click', function () {
        searchInput.value = '';
        programFilter.value = '';
        filterClasses();
    });
});
</script>

</body>
</html>
