<?php
session_start();
include 'database.php';

/* =========================================
   DIRECTOR ACCESS ONLY
========================================= */
if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'director'
) {
    header("Location: login.php");
    exit();
}

/* =========================================
   HELPER
========================================= */
function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

if (!isset($_GET['id']) || !ctype_digit((string)$_GET['id'])) {
    http_response_code(400);
    die("Invalid class ID.");
}

$class_id = (int)$_GET['id'];
$success = '';
$error = '';

/* =========================================
   CSRF TOKEN
========================================= */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* =========================================
   FETCH PROGRAMS FROM DATABASE
========================================= */
$programsByCollege = [];
$validPrograms = [];

$programQuery = $conn->query(
    "SELECT id, college_name, college_code, program_name
     FROM programs
     ORDER BY college_name ASC, program_name ASC"
);

if (!$programQuery) {
    die("Unable to load academic programs. Please check the programs table: " . e($conn->error));
}

while ($programRow = $programQuery->fetch_assoc()) {
    $programsByCollege[$programRow['college_name']][] = $programRow;
    $validPrograms[] = $programRow['program_name'];
}

/* =========================================
   FETCH CLASS
========================================= */
$stmt = $conn->prepare(
    "SELECT *
     FROM classes
     WHERE id = ?
     LIMIT 1"
);

if (!$stmt) {
    die("Unable to prepare class query: " . e($conn->error));
}

$stmt->bind_param("i", $class_id);
$stmt->execute();
$class = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$class) {
    http_response_code(404);
    die("Class not found.");
}

/* =========================================
   FETCH INSTRUCTORS
========================================= */
$teachers = [];

$teacherQuery = $conn->query(
    "SELECT id, name, email
     FROM users
     WHERE role = 'teacher'
     ORDER BY name ASC"
);

if ($teacherQuery) {
    while ($teacher = $teacherQuery->fetch_assoc()) {
        $teachers[] = $teacher;
    }
}

/* =========================================
   UPDATE CLASS
========================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $postedToken)) {
        $error = "Your session verification failed. Please refresh the page and try again.";
    } else {
        $class_name = trim($_POST['class_name'] ?? '');
        $program = trim($_POST['program'] ?? '');
        $section = trim($_POST['section'] ?? '');
        $academic_year = trim($_POST['academic_year'] ?? '');
        $schedule = trim($_POST['schedule'] ?? '');
        $room = trim($_POST['room'] ?? '');
        $teacher_input = trim($_POST['teacher_id'] ?? '');
        $max_students = filter_var(
            $_POST['max_students'] ?? null,
            FILTER_VALIDATE_INT
        );

        $teacher_id = null;

        if ($teacher_input !== '') {
            if (!ctype_digit($teacher_input) || (int)$teacher_input < 1) {
                $error = "The selected instructor is invalid.";
            } else {
                $teacher_id = (int)$teacher_input;
            }
        }

        /* Required field validation */
        if (
            $error === '' &&
            (
                $class_name === '' ||
                $program === '' ||
                $section === '' ||
                $academic_year === '' ||
                $schedule === '' ||
                $room === '' ||
                $max_students === false ||
                $max_students < 1
            )
        ) {
            $error = "Please complete all required fields and enter a valid student capacity.";
        }

        /* Validate program against the programs table */
        if ($error === '') {
            $programCheck = $conn->prepare(
                "SELECT id
                 FROM programs
                 WHERE program_name = ?
                 LIMIT 1"
            );

            if (!$programCheck) {
                $error = "Unable to validate the selected program.";
            } else {
                $programCheck->bind_param("s", $program);
                $programCheck->execute();
                $programExists = $programCheck->get_result()->num_rows > 0;
                $programCheck->close();

                if (!$programExists) {
                    $error = "Please select a valid academic program from the list.";
                }
            }
        }

        /* Validate instructor */
        if ($error === '' && $teacher_id !== null) {
            $checkTeacher = $conn->prepare(
                "SELECT id
                 FROM users
                 WHERE id = ? AND role = 'teacher'
                 LIMIT 1"
            );

            if (!$checkTeacher) {
                $error = "Unable to validate the selected instructor.";
            } else {
                $checkTeacher->bind_param("i", $teacher_id);
                $checkTeacher->execute();
                $teacherExists = $checkTeacher->get_result()->num_rows > 0;
                $checkTeacher->close();

                if (!$teacherExists) {
                    $error = "The selected instructor is invalid.";
                }
            }
        }

        /* Prevent capacity from being lower than active enrollment */
        if ($error === '') {
            $countStmt = $conn->prepare(
                "SELECT COUNT(*) AS total
                 FROM class_students
                 WHERE class_id = ? AND archived = 0"
            );

            if (!$countStmt) {
                $error = "Unable to check the current class enrollment.";
            } else {
                $countStmt->bind_param("i", $class_id);
                $countStmt->execute();
                $enrollment = $countStmt->get_result()->fetch_assoc();
                $countStmt->close();

                $current_students = (int)($enrollment['total'] ?? 0);

                if ($max_students < $current_students) {
                    $error = "Maximum students cannot be lower than the current enrollment of {$current_students}.";
                }
            }
        }

        /* Update class */
        if ($error === '') {
            $update = $conn->prepare(
                "UPDATE classes
                 SET class_name = ?,
                     program = ?,
                     section = ?,
                     academic_year = ?,
                     schedule = ?,
                     room = ?,
                     teacher_id = ?,
                     max_students = ?
                 WHERE id = ?"
            );

            if (!$update) {
                $error = "Unable to prepare the class update: " . $conn->error;
            } else {
                $update->bind_param(
                    "ssssssiii",
                    $class_name,
                    $program,
                    $section,
                    $academic_year,
                    $schedule,
                    $room,
                    $teacher_id,
                    $max_students,
                    $class_id
                );

                if ($update->execute()) {
                    $success = "Class information updated successfully.";
                } else {
                    $error = "Unable to update class information: " . $update->error;
                }

                $update->close();
            }
        }
    }

    /* Keep the user's submitted values visible if validation fails. */
    if ($error !== '') {
        $class['class_name'] = $_POST['class_name'] ?? $class['class_name'];
        $class['program'] = $_POST['program'] ?? $class['program'];
        $class['section'] = $_POST['section'] ?? $class['section'];
        $class['academic_year'] = $_POST['academic_year'] ?? $class['academic_year'];
        $class['schedule'] = $_POST['schedule'] ?? $class['schedule'];
        $class['room'] = $_POST['room'] ?? $class['room'];
        $class['teacher_id'] = $_POST['teacher_id'] ?? $class['teacher_id'];
        $class['max_students'] = $_POST['max_students'] ?? $class['max_students'];
    } else {
        /* Refresh the saved class data after a successful update. */
        $refresh = $conn->prepare(
            "SELECT *
             FROM classes
             WHERE id = ?
             LIMIT 1"
        );

        $refresh->bind_param("i", $class_id);
        $refresh->execute();
        $class = $refresh->get_result()->fetch_assoc();
        $refresh->close();
    }
}

/* Check whether the class's existing program is in the programs table. */
$currentProgramExists = in_array((string)($class['program'] ?? ''), $validPrograms, true);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Class | PLMUN Classroom</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #f5f7fb;
            font-family: Arial, sans-serif;
            color: #333;
        }

        .page-container {
            max-width: 950px;
            margin: 40px auto;
            padding: 0 18px;
        }

        .form-card {
            background: #fff;
            border-radius: 14px;
            padding: 30px;
            box-shadow: 0 3px 15px rgba(0,0,0,.06);
        }

        .page-title {
            font-size: 25px;
            font-weight: 700;
            color: #263746;
        }

        .form-label {
            font-weight: 600;
            color: #394b59;
        }

        .form-control, .form-select {
            min-height: 45px;
            border-radius: 8px;
            border-color: #dce1e6;
        }

        .form-control:focus, .form-select:focus {
            border-color: #347fa8;
            box-shadow: 0 0 0 3px rgba(52,127,168,.12);
        }

        .btn-save {
            background: #347fa8;
            color: #fff;
            border: none;
            padding: 11px 22px;
            border-radius: 8px;
        }

        .btn-save:hover {
            background: #286789;
            color: #fff;
        }

        .btn-back {
            border-radius: 8px;
            padding: 10px 18px;
        }

        .info-note {
            background: #f0f6fa;
            border: 1px solid #dcebf4;
            color: #42677e;
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 13px;
        }

        .class-code-field {
            background: #f7f8fa;
            font-weight: 700;
            letter-spacing: 1px;
            color: #347fa8;
        }

        @media (max-width: 576px) {
            .page-container { margin: 22px auto; }
            .form-card { padding: 20px; }
            .page-title { font-size: 21px; }
        }
    </style>
</head>

<body>

<div class="page-container">

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h2 class="page-title mb-1">
                <i class="fa-solid fa-pen-to-square me-2"></i>
                Edit Class Information
            </h2>
            <p class="text-muted mb-0">Update the class details and instructor assignment.</p>
        </div>

        <a href="class_management.php" class="btn btn-outline-secondary btn-back">
            <i class="fa-solid fa-arrow-left me-2"></i>Back to Classes
        </a>
    </div>

    <div class="form-card">

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check me-2"></i>
                <?= e($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fa-solid fa-circle-exclamation me-2"></i>
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <?php if (!$currentProgramExists): ?>
            <div class="alert alert-warning">
                <i class="fa-solid fa-triangle-exclamation me-2"></i>
                This class currently has a program name that does not match any entry in the
                <strong>programs</strong> table. Select the correct program below before saving.
            </div>
        <?php endif; ?>

        <div class="info-note mb-4">
            <i class="fa-solid fa-circle-info me-2"></i>
            Program options are loaded from the database. Use the exact academic program assigned
            to this class so it can appear under the correct Program Chair dashboard.
        </div>

        <form method="POST" action="edit_class.php?id=<?= $class_id ?>">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

            <div class="row g-3">

                <div class="col-md-12">
                    <label class="form-label">Subject / Class Name *</label>
                    <input
                        type="text"
                        name="class_name"
                        class="form-control"
                        value="<?= e($class['class_name']) ?>"
                        maxlength="150"
                        required
                    >
                </div>

                <div class="col-md-12">
                    <label class="form-label">Academic Program *</label>
                    <select name="program" class="form-select" required>
                        <option value="">Select Academic Program</option>

                        <?php foreach ($programsByCollege as $college => $collegePrograms): ?>
                            <optgroup label="<?= e($college) ?>">
                                <?php foreach ($collegePrograms as $programRow): ?>
                                    <option
                                        value="<?= e($programRow['program_name']) ?>"
                                        <?= ((string)$class['program'] === (string)$programRow['program_name']) ? 'selected' : '' ?>
                                    >
                                        <?= e($programRow['program_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Section *</label>
                    <input
                        type="text"
                        name="section"
                        class="form-control"
                        value="<?= e($class['section']) ?>"
                        required
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label">Academic Year *</label>
                    <input
                        type="text"
                        name="academic_year"
                        class="form-control"
                        value="<?= e($class['academic_year']) ?>"
                        placeholder="Example: 2026-2027"
                        required
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label">Schedule *</label>
                    <input
                        type="text"
                        name="schedule"
                        class="form-control"
                        value="<?= e($class['schedule']) ?>"
                        placeholder="Example: Monday and Wednesday, 8:00 AM"
                        required
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label">Room *</label>
                    <input
                        type="text"
                        name="room"
                        class="form-control"
                        value="<?= e($class['room']) ?>"
                        placeholder="Example: Room 301"
                        required
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label">Assign Instructor</label>
                    <select name="teacher_id" class="form-select">
                        <option value="">No instructor assigned</option>

                        <?php foreach ($teachers as $teacher): ?>
                            <option
                                value="<?= (int)$teacher['id'] ?>"
                                <?= ((string)($class['teacher_id'] ?? '') === (string)$teacher['id']) ? 'selected' : '' ?>
                            >
                                <?= e($teacher['name']) ?> (<?= e($teacher['email']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Select an instructor or leave unassigned.</small>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Maximum Students *</label>
                    <input
                        type="number"
                        name="max_students"
                        class="form-control"
                        min="1"
                        value="<?= e($class['max_students']) ?>"
                        required
                    >
                    <small class="text-muted">Cannot be lower than the current active enrollment.</small>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Class Code</label>
                    <input
                        type="text"
                        class="form-control class-code-field"
                        value="<?= e($class['class_code']) ?>"
                        readonly
                    >
                    <small class="text-muted">The class code is retained and cannot be changed here.</small>
                </div>

            </div>

            <hr class="my-4">

            <div class="d-flex justify-content-end gap-2 flex-wrap">
                <a href="class_management.php" class="btn btn-light border">
                    Cancel
                </a>

                <button type="submit" class="btn btn-save">
                    <i class="fa-solid fa-floppy-disk me-2"></i>
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
