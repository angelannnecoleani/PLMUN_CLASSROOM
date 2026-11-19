
<?php
session_start();

require_once 'database.php';
require_once 'auth_helpers.php';
require_once 'assessment_attachments.php';

/* =====================================================
   LOGIN AND ROLE VALIDATION
===================================================== */

if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$role = $_SESSION['role'] ?? '';

if (!in_array($role, ['teacher', 'program_chair'], true)) {
    http_response_code(403);
    exit("Access denied.");
}

$user_id = (int) $_SESSION['user_id'];
$isChair = ($role === 'program_chair');

/* =====================================================
   VALIDATE ASSESSMENT ID
===================================================== */

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    exit("Assessment not found.");
}

$assessment_id = (int) $_GET['id'];

/* =====================================================
   FETCH ASSESSMENT
===================================================== */

$stmt = mysqli_prepare(
    $conn,
    "SELECT * FROM assessments WHERE id = ? LIMIT 1"
);

if (!$stmt) {
    exit("Database error.");
}

mysqli_stmt_bind_param($stmt, "i", $assessment_id);
mysqli_stmt_execute($stmt);

$assessment = mysqli_fetch_assoc(
    mysqli_stmt_get_result($stmt)
);

mysqli_stmt_close($stmt);

if (!$assessment) {
    http_response_code(404);
    exit("Assessment not found.");
}

$class_id = (int) $assessment['class_id'];

/* =====================================================
   CHECK CLASS ACCESS

   Teacher:
   - Primary instructor
   - Additional instructor in class_instructors

   Program Chair:
   - Uses existing getClassAccess()
===================================================== */

if ($role === 'teacher') {

    $accessStmt = mysqli_prepare($conn, "
        SELECT c.*
        FROM classes c
        WHERE c.id = ?
        AND (
            c.teacher_id = ?
            OR EXISTS (
                SELECT 1
                FROM class_instructors ci
                WHERE ci.class_id = c.id
                AND ci.teacher_id = ?
            )
        )
        LIMIT 1
    ");

    if (!$accessStmt) {
        exit("Database error while checking instructor access.");
    }

    mysqli_stmt_bind_param(
        $accessStmt,
        "iii",
        $class_id,
        $user_id,
        $user_id
    );

    mysqli_stmt_execute($accessStmt);

    $class = mysqli_fetch_assoc(
        mysqli_stmt_get_result($accessStmt)
    );

    mysqli_stmt_close($accessStmt);

} else {

    $class = getClassAccess(
        $conn,
        $class_id,
        $user_id,
        $role
    );

}

if (!$class) {
    http_response_code(403);
    exit("Access denied. You are not authorized to edit this assessment.");
}

/* =====================================================
   FETCH CURRENT USER
===================================================== */

$uStmt = mysqli_prepare(
    $conn,
    "SELECT * FROM users WHERE id = ? LIMIT 1"
);

mysqli_stmt_bind_param($uStmt, "i", $user_id);
mysqli_stmt_execute($uStmt);

$user = mysqli_fetch_assoc(
    mysqli_stmt_get_result($uStmt)
) ?: [];

mysqli_stmt_close($uStmt);

/* =====================================================
   SPLIT DUE DATE INTO DATE AND TIME
===================================================== */

$due_date_val = "";
$due_time_val = "";

if (!empty($assessment['due_date'])) {
    $due_date_val = date(
        "Y-m-d",
        strtotime($assessment['due_date'])
    );

    $due_time_val = date(
        "H:i",
        strtotime($assessment['due_date'])
    );
}

/* =====================================================
   SPLIT START DATE INTO DATE AND TIME
===================================================== */

$start_date_val = "";
$start_time_val = "";

if (!empty($assessment['start_date'])) {
    $start_date_val = date(
        "Y-m-d",
        strtotime($assessment['start_date'])
    );

    $start_time_val = date(
        "H:i",
        strtotime($assessment['start_date'])
    );
}

/* =====================================================
   LOAD EXISTING ATTACHMENTS
===================================================== */

aa_ensure_migrated($conn, $assessment_id);

$existingForJs = [];

foreach (aa_fetch($conn, $assessment_id) as $a) {

    $existingForJs[] = [
        'id' => (int) $a['id'],
        'type' => $a['type'],
        'name' => $a['original_name'] ?: ($a['file_path'] ?? ''),
        'url' => $a['url'] ?? '',
        'preview' => (
            $a['type'] === 'image' &&
            !empty($a['file_path'])
        )
            ? 'uploads/' . rawurlencode(basename($a['file_path']))
            : ''
    ];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        Edit Assessment - <?php echo htmlspecialchars($class['class_name'] ?? 'Class'); ?>
    </title>

    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">

    <link rel="stylesheet" href="teacher_class.css">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>

        * {
            box-sizing: border-box;
        }

        html,
        body {
            width: 100%;
            max-width: 100%;
            overflow-x: hidden;
        }

        .cancel-btn {
            height: 52px;
            min-width: 130px;
            border-radius: 30px;
            font-size: 16px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .save-btn {
            height: 52px;
            min-width: 130px;
            border-radius: 30px;
            background: #3f87b5;
            border: none;
            color: #fff;
            font-size: 16px;
            font-weight: 600;
            transition: background 0.2s;
        }

        .save-btn:hover {
            background: #2f76a7;
            color: #fff;
        }

        .icon-btn {
            background: none;
            border: none;
            padding: 0;
        }

        .attachment-icons {
            align-items: center;
        }

        .link-add-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
        }

        .link-add-row[hidden] {
            display: none;
        }

        .link-add-row i {
            color: #3f87b5;
            flex-shrink: 0;
        }

        .link-add-row input {
            flex: 1;
            min-width: 0;
            height: 40px;
            padding: 0 12px;
            border: 1px solid #dde4ec;
            border-radius: 6px;
            font-size: 14px;
            outline: none;
        }

        .link-add-row input:focus {
            border-color: #3f87b5;
            box-shadow: 0 0 0 3px rgba(63,135,181,.15);
        }

        .link-add-btn {
            height: 40px;
            padding: 0 16px;
            border: none;
            border-radius: 6px;
            background: #3f87b5;
            color: #fff;
            font-weight: 600;
            font-size: 14px;
            flex-shrink: 0;
            transition: background 0.2s;
        }

        .link-add-btn:hover {
            background: #2f76a7;
        }

        .attach-chip {
            max-width: 100%;
        }

        .back-link {
            font-size: 20px;
            color: #444;
            text-decoration: none;
            width: 40px;
            height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.2s;
        }

        .back-link:hover {
            background: #eef2f6;
            color: #3f87b5;
        }

        @media (max-width: 768px) {

            .container.py-4 {
                padding: 14px 12px 40px !important;
            }

            .assessment-page h2 {
                font-size: 22px;
            }

            .topic-block.p-4 {
                padding: 16px !important;
            }

            .instruction-box {
                padding: 12px;
            }

            .instruction-box textarea {
                font-size: 16px;
                min-height: 90px;
            }

            .attachment-icons {
                gap: 6px;
            }

            .icon-btn {
                min-width: 40px;
                min-height: 40px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            .assessment-page .form-control,
            .assessment-page .form-select {
                font-size: 16px;
            }

            .assessment-page .d-flex.gap-2 > input {
                min-width: 0;
                flex: 1 1 0;
            }

            .form-actions {
                gap: 10px !important;
            }

            .cancel-btn,
            .save-btn {
                flex: 1 1 0;
                min-width: 0;
                height: 46px;
                font-size: 15px;
            }
        }

        @media (max-width: 480px) {

            .attach-list {
                gap: 6px;
            }

            .link-add-row {
                flex-wrap: wrap;
            }

            .link-add-row i {
                order: 1;
            }

            .link-add-row input {
                flex: 1 1 100%;
                order: 2;
            }

            .link-add-btn {
                order: 3;
                width: 100%;
            }
        }

    </style>

</head>

<body>

    <!-- SIDEBAR -->

    <div class="sidebar" id="sidebar">

        <div class="sidebar-header">

            <button class="toggle-btn" onclick="toggleSidebar()">
                <i class="fa-solid fa-bars"></i>
            </button>

            <button class="sidebar-close-btn"
                    onclick="closeMobileSidebar()"
                    aria-label="Close menu">

                <i class="fa-solid fa-xmark"></i>

            </button>

        </div>

        <ul>

            <?php if ($isChair): ?>

                <li>
                    <a href="program_chair_dashboard.php">
                        <i class="fa-solid fa-house"></i>
                        <span>Home</span>
                    </a>
                </li>

            <?php else: ?>

                <li>
                    <a href="teacher_dashboard.php">
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

                <li>
                    <a href="teacher_resources.php">
                        <i class="fa-solid fa-book-open"></i>
                        <span>Resources</span>
                    </a>
                </li>

            <?php endif; ?>

        </ul>

        <div class="logout-btn">

            <a href="logout.php">
                <i class="fa-solid fa-right-from-bracket"></i>
                <span>Logout</span>
            </a>

        </div>

    </div>

    <!-- SIDEBAR OVERLAY -->

    <div class="sidebar-overlay"
         id="sidebarOverlay"
         onclick="closeMobileSidebar()">
    </div>

    <!-- MAIN CONTENT -->

    <div class="main-content" id="mainContent">

        <!-- TOPBAR -->

        <div class="topbar">

            <div class="navbar-left">

                <button class="mobile-menu" onclick="mobileSidebar()">
                    <i class="fa-solid fa-bars"></i>
                </button>

                <img src="img/classroom logo.png"
                     class="navbar-logo"
                     alt="Logo">

            </div>

            <div class="navbar-right">

                <?php

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

                <a href="profile.php" class="profile-icon">

                    <?php if ($hasProfileImage): ?>

                        <img src="uploads/<?php echo htmlspecialchars(basename($profileFile)); ?>"
                             alt="Profile"
                             onerror="this.style.display='none'; this.parentElement.insertAdjacentHTML('beforeend','<i class=&quot;fa-solid fa-user&quot;></i>');">

                    <?php else: ?>

                        <i class="fa-solid fa-user"></i>

                    <?php endif; ?>

                </a>

            </div>

        </div>

        <!-- CLASS NAVIGATION -->

        <div class="class-nav">

            <?php if ($isChair): ?>

                <a href="program_chair_class.php?id=<?php echo $class_id; ?>&tab=stream">
                    Stream
                </a>

                <a href="teacher_assessment.php?id=<?php echo $class_id; ?>"
                   class="nav-active">
                    Assessments
                </a>

                <a href="teacher_material.php?id=<?php echo $class_id; ?>">
                    Materials
                </a>

                <a href="program_chair_class.php?id=<?php echo $class_id; ?>&tab=people">
                    People
                </a>

            <?php else: ?>

                <a href="teacher_class.php?id=<?php echo $class_id; ?>">
                    Stream
                </a>

                <a href="teacher_assessment.php?id=<?php echo $class_id; ?>"
                   class="nav-active">
                    Assessments
                </a>

                <a href="teacher_material.php?id=<?php echo $class_id; ?>">
                    Materials
                </a>

                <a href="teacher_people.php?id=<?php echo $class_id; ?>">
                    People
                </a>

                <a href="teacher_grades.php?id=<?php echo $class_id; ?>">
                    Grades
                </a>

            <?php endif; ?>

        </div>

        <!-- EDIT ASSESSMENT CONTENT -->

        <div class="container py-4">

            <div class="assessment-page">

                <div class="d-flex align-items-center mb-3">

                    <a href="teacher_assessment.php?id=<?php echo $class_id; ?>"
                       class="back-link me-2"
                       title="Back">

                        <i class="fa-solid fa-arrow-left"></i>

                    </a>

                    <h2 class="fw-bold mb-0">
                        Edit Assessment
                    </h2>

                </div>

                <div class="topic-block p-4">

                    <form action="update_assessment.php"
                          method="POST"
                          enctype="multipart/form-data"
                          id="assessmentForm">

                        <input type="hidden"
                               name="id"
                               value="<?php echo (int)$assessment['id']; ?>">

                        <input type="hidden"
                               name="class_id"
                               value="<?php echo $class_id; ?>">

                        <!-- TITLE -->

                        <input
                            type="text"
                            name="title"
                            class="form-control assessment-input"
                            placeholder="Title*"
                            value="<?php echo htmlspecialchars($assessment['title']); ?>"
                            required>

                        <!-- INSTRUCTIONS AND ATTACHMENTS -->

                        <div class="instruction-box mt-4">

                            <textarea
                                name="instructions"
                                placeholder="Instructions (optional)"><?php echo htmlspecialchars($assessment['instructions'] ?? ''); ?></textarea>

                            <div id="attachList" class="attach-list"></div>

                            <div id="linkRow" class="link-add-row" hidden>

                                <i class="fa-solid fa-link"></i>

                                <input type="url"
                                       id="linkInput"
                                       placeholder="Paste a link, then press Enter or Add">

                                <button type="button"
                                        id="linkAddBtn"
                                        class="link-add-btn">
                                    Add
                                </button>

                            </div>

                            <div id="attachMsg" class="attach-msg"></div>

                            <div class="instruction-footer">

                                <div class="attachment-icons">

                                    <button type="button"
                                            class="icon-btn"
                                            id="pickImageBtn"
                                            title="Attach images">

                                        <i class="fa-regular fa-image"></i>

                                    </button>

                                    <button type="button"
                                            class="icon-btn"
                                            id="pickFileBtn"
                                            title="Attach files">

                                        <i class="fa-solid fa-paperclip"></i>

                                    </button>

                                    <button type="button"
                                            class="icon-btn"
                                            id="pickLinkBtn"
                                            title="Attach link">

                                        <i class="fa-solid fa-link"></i>

                                    </button>

                                    <span id="attachCounter" class="attach-counter">
                                        0/10
                                    </span>

                                </div>

                            </div>

                            <!-- FILE PICKERS -->

                            <input type="file"
                                   id="imagePicker"
                                   accept="image/*"
                                   multiple
                                   hidden>

                            <input type="file"
                                   id="filePicker"
                                   accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt"
                                   multiple
                                   hidden>

                            <!-- SUBMITTED FILE HOLDERS -->

                            <input type="file"
                                   name="images[]"
                                   id="imagesHolder"
                                   multiple
                                   hidden>

                            <input type="file"
                                   name="attachments[]"
                                   id="filesHolder"
                                   multiple
                                   hidden>

                            <div id="linkHolders"></div>
                            <div id="removeHolders"></div>

                        </div>

                        <!-- TYPE, POINTS, ASSIGN TO -->

                        <div class="row g-3 mt-2">

                            <div class="col-md-4">

                                <label class="form-label fw-bold">
                                    Type
                                </label>

                                <select class="form-select assessment-select"
                                        name="type">

                                    <?php

                                    $types = [
                                        "Quiz",
                                        "Assignment",
                                        "Activity",
                                        "Exam"
                                    ];

                                    foreach ($types as $t) {

                                        $selected = (
                                            $assessment['type'] === $t
                                        ) ? "selected" : "";

                                        echo "<option value=\"" .
                                            htmlspecialchars($t) .
                                            "\" $selected>" .
                                            htmlspecialchars($t) .
                                            "</option>";
                                    }

                                    ?>

                                </select>

                            </div>

                            <div class="col-md-4">

                                <label class="form-label fw-bold">
                                    Points
                                </label>

                                <input
                                    type="number"
                                    class="form-control assessment-select"
                                    name="points"
                                    min="0"
                                    value="<?php echo htmlspecialchars($assessment['points'] ?? 100); ?>">

                            </div>

                            <div class="col-md-4">

                                <label class="form-label fw-bold">
                                    Assign to
                                </label>

                                <select class="form-select assessment-select">

                                    <option>All Students</option>

                                </select>

                            </div>

                        </div>

                        <!-- DATES -->

                        <div class="row g-3 mt-2">

                            <div class="col-md-6">

                                <label class="fw-bold">
                                    Start Date
                                </label>

                                <div class="d-flex gap-2 mt-1">

                                    <input type="date"
                                           class="form-control"
                                           name="start_date"
                                           value="<?php echo htmlspecialchars($start_date_val); ?>">

                                    <input type="time"
                                           class="form-control"
                                           name="start_time"
                                           value="<?php echo htmlspecialchars($start_time_val); ?>">

                                </div>

                            </div>

                            <div class="col-md-6">

                                <label class="fw-bold">
                                    End Date
                                </label>

                                <div class="d-flex gap-2 mt-1">

                                    <input type="date"
                                           class="form-control"
                                           name="due_date"
                                           value="<?php echo htmlspecialchars($due_date_val); ?>"
                                           required>

                                    <input type="time"
                                           class="form-control"
                                           name="due_time"
                                           value="<?php echo htmlspecialchars($due_time_val); ?>">

                                </div>

                            </div>

                        </div>

                        <!-- FORM ACTIONS -->

                        <div class="d-flex justify-content-end gap-2 mt-4 form-actions">

                            <a href="teacher_assessment.php?id=<?php echo $class_id; ?>"
                               class="btn btn-outline-dark cancel-btn">

                                Cancel

                            </a>

                            <button type="submit" class="btn save-btn">

                                Save Changes

                            </button>

                        </div>

                    </form>

                </div>

            </div>

        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>

    /* =====================================================
       SIDEBAR
    ===================================================== */

    function toggleSidebar() {

        if (window.innerWidth > 768) {

            document.getElementById("sidebar").classList.toggle("collapsed");

            document.getElementById("mainContent").classList.toggle("expanded");

        }

    }

    function mobileSidebar() {

        document.getElementById("sidebar").classList.add("show");

        document.getElementById("sidebarOverlay").classList.add("show");

        document.body.classList.add("no-scroll");

    }

    function closeMobileSidebar() {

        document.getElementById("sidebar").classList.remove("show");

        document.getElementById("sidebarOverlay").classList.remove("show");

        document.body.classList.remove("no-scroll");

    }

    document.addEventListener("keydown", function(e) {

        if (e.key === "Escape") {
            closeMobileSidebar();
        }

    });

    window.addEventListener("resize", function() {

        if (window.innerWidth > 768) {
            closeMobileSidebar();
        }

    });

    /* =====================================================
       MULTIPLE ATTACHMENTS
    ===================================================== */

    (function() {

        var MAX = 10;
        var MAX_MB = 10;

        var FILE_EXT = [
            'pdf',
            'doc',
            'docx',
            'ppt',
            'pptx',
            'xls',
            'xlsx',
            'txt'
        ];

        var EXISTING = <?php
            echo json_encode(
                $existingForJs,
                JSON_HEX_TAG |
                JSON_HEX_AMP |
                JSON_HEX_APOS |
                JSON_HEX_QUOT
            );
        ?>;

        var items = EXISTING.map(function(e) {

            return {
                type: e.type,
                existingId: e.id,
                name: e.name,
                url: e.url,
                preview: e.preview
            };

        });

        var removed = [];

        function $(id) {
            return document.getElementById(id);
        }

        function showMsg(message) {

            var msg = $('attachMsg');

            if (msg) {
                msg.textContent = message || '';
            }

        }

        /* SYNC ATTACHMENTS */

        function sync() {

            var linkHolders = $('linkHolders');
            var removeHolders = $('removeHolders');

            linkHolders.innerHTML = '';
            removeHolders.innerHTML = '';

            var canSetFiles = (
                typeof DataTransfer !== 'undefined'
            );

            var dtI = canSetFiles ? new DataTransfer() : null;
            var dtF = canSetFiles ? new DataTransfer() : null;

            items.forEach(function(it) {

                if (it.existingId) {
                    return;
                }

                if (it.type === 'image') {

                    if (dtI) {
                        dtI.items.add(it.file);
                    }

                } else if (it.type === 'file') {

                    if (dtF) {
                        dtF.items.add(it.file);
                    }

                } else {

                    var h = document.createElement('input');

                    h.type = 'hidden';
                    h.name = 'links[]';
                    h.value = it.url;

                    linkHolders.appendChild(h);

                }

            });

            removed.forEach(function(id) {

                var r = document.createElement('input');

                r.type = 'hidden';
                r.name = 'remove_attachments[]';
                r.value = id;

                removeHolders.appendChild(r);

            });

            if (canSetFiles) {

                $('imagesHolder').files = dtI.files;
                $('filesHolder').files = dtF.files;

            }

            var counter = $('attachCounter');

            counter.textContent = items.length + '/' + MAX;

            counter.classList.toggle(
                'full',
                items.length >= MAX
            );

        }

        /* RENDER ATTACHMENTS */

        function render() {

            var list = $('attachList');

            list.innerHTML = '';

            items.forEach(function(it) {

                var chip = document.createElement('div');

                chip.className = 'attach-chip';

                if (it.type === 'image' && it.preview) {

                    var img = document.createElement('img');

                    img.className = 'attach-thumb';
                    img.src = it.preview;
                    img.alt = '';

                    chip.appendChild(img);

                } else {

                    var ic = document.createElement('i');

                    ic.className =
                        'type-icon fa-solid ' +
                        (
                            it.type === 'link'
                                ? 'fa-link'
                                : (
                                    it.type === 'image'
                                        ? 'fa-image'
                                        : 'fa-file'
                                )
                        );

                    chip.appendChild(ic);

                }

                var name = document.createElement('span');

                name.className = 'attach-chip-name';

                name.textContent =
                    it.type === 'link'
                        ? it.url
                        : (it.file ? it.file.name : it.name);

                chip.appendChild(name);

                var x = document.createElement('button');

                x.type = 'button';
                x.className = 'attach-remove';
                x.setAttribute('aria-label', 'Remove');
                x.innerHTML = '&times;';

                x.addEventListener('click', function() {

                    if (it.existingId) {
                        removed.push(it.existingId);
                    }

                    if (it.file && it.preview) {
                        URL.revokeObjectURL(it.preview);
                    }

                    items.splice(items.indexOf(it), 1);

                    showMsg('');

                    render();

                });

                chip.appendChild(x);

                list.appendChild(chip);

            });

            sync();

        }

        /* ADD FILES */

        function addFiles(fileList, type) {

            showMsg('');

            Array.prototype.forEach.call(fileList, function(f) {

                if (items.length >= MAX) {

                    showMsg(
                        'You can attach up to ' + MAX + ' items only.'
                    );

                    return;

                }

                if (f.size > MAX_MB * 1024 * 1024) {

                    showMsg(
                        '"' + f.name + '" is larger than ' +
                        MAX_MB + ' MB.'
                    );

                    return;

                }

                if (
                    type === 'image' &&
                    f.type.indexOf('image/') !== 0
                ) {

                    showMsg(
                        '"' + f.name + '" is not an image.'
                    );

                    return;

                }

                if (type === 'file') {

                    var ext = f.name.split('.').pop().toLowerCase();

                    if (FILE_EXT.indexOf(ext) === -1) {

                        showMsg(
                            '"' + f.name +
                            '" is not a supported file type.'
                        );

                        return;

                    }

                }

                var duplicate = items.some(function(it) {

                    return it.file &&
                        it.type === type &&
                        it.file.name === f.name &&
                        it.file.size === f.size;

                });

                if (duplicate) {
                    return;
                }

                items.push({

                    type: type,

                    file: f,

                    preview: type === 'image'
                        ? URL.createObjectURL(f)
                        : null

                });

            });

            render();

        }

        /* ADD LINK */

        function addLink() {

            var linkInput = $('linkInput');

            var v = linkInput.value.trim();

            if (!v) {
                return;
            }

            if (!/^https?:\/\//i.test(v)) {
                v = 'https://' + v;
            }

            try {

                new URL(v);

            } catch (e) {

                showMsg("That doesn't look like a valid link.");

                return;

            }

            if (items.length >= MAX) {

                showMsg(
                    'You can attach up to ' + MAX + ' items only.'
                );

                return;

            }

            if (items.some(function(it) {

                return it.type === 'link' && it.url === v;

            })) {

                showMsg('That link is already added.');

                return;

            }

            items.push({
                type: 'link',
                url: v
            });

            linkInput.value = '';

            showMsg('');

            render();

        }

        /* INITIALIZE */

        document.addEventListener('DOMContentLoaded', function() {

            var linkRow = $('linkRow');
            var linkInput = $('linkInput');

            $('pickImageBtn').addEventListener('click', function() {
                $('imagePicker').click();
            });

            $('pickFileBtn').addEventListener('click', function() {
                $('filePicker').click();
            });

            $('pickLinkBtn').addEventListener('click', function() {

                linkRow.hidden = !linkRow.hidden;

                if (!linkRow.hidden) {
                    linkInput.focus();
                }

            });

            $('imagePicker').addEventListener('change', function() {

                addFiles(this.files, 'image');

                this.value = '';

            });

            $('filePicker').addEventListener('change', function() {

                addFiles(this.files, 'file');

                this.value = '';

            });

            $('linkAddBtn').addEventListener('click', addLink);

            linkInput.addEventListener('keydown', function(e) {

                if (e.key === 'Enter') {

                    e.preventDefault();

                    addLink();

                }

            });

            $('assessmentForm').addEventListener('submit', function() {

                if (linkInput.value.trim() !== '') {
                    addLink();
                }

                sync();

            });

            render();

        });

    })();

    </script>

</body>
</html>