
<?php
session_start();
include 'database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$student_id = (int) $_SESSION['user_id'];

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
   GET STUDENT INFORMATION FIRST
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

$profileImageFile = '';
$hasProfileImage = false;

$profileImageFile = trim(
    (string)($user['profile_image'] ?? '')
);

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

    // Get filename only
    $safeName = basename($profileImageFile);

    // Physical image location
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

// No image = display user icon
if (!$hasProfileImage) {
    $profileImageFile = '';
}

/* =========================================
   FLASH MESSAGE
========================================= */

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);


/* ASSESSMENTS ACROSS EVERY CLASS THE STUDENT BELONGS TO
   + MY SUBMISSION STATUS FOR EACH */
$aStmt = mysqli_prepare($conn, "
    SELECT
        a.id, a.title, a.instructions, a.due_date, a.points, a.type,
        a.class_id, c.class_name,
        s.file_path, s.status, s.submitted_at
    FROM assessments a
    INNER JOIN classes c
        ON c.id = a.class_id
    INNER JOIN class_students cs
        ON cs.class_id = a.class_id AND cs.student_id = ?
    LEFT JOIN submissions s
        ON s.assessment_id = a.id AND s.student_id = ?
    ORDER BY a.created_at DESC
");
mysqli_stmt_bind_param($aStmt, "ii", $student_id, $student_id);
mysqli_stmt_execute($aStmt);
$result = mysqli_stmt_get_result($aStmt);

$assignments = [];
while ($r = mysqli_fetch_assoc($result)) {
    $assignments[] = $r;
}

/* distinct classes present in the list, for the class filter dropdown */
$classesInList = [];
foreach ($assignments as $a) {
    $classesInList[$a['class_id']] = $a['class_name'];
}
asort($classesInList);

/* ---------- helpers ---------- */

// visual identity per assessment type
function assess_type_style($type) {
    $key = strtolower(trim($type ?? ''));
    $map = [
        'quiz'       => ['icon' => 'fa-solid fa-circle-question', 'color' => '#7c5cff'],
        'exam'       => ['icon' => 'fa-solid fa-file-pen',        'color' => '#d64545'],
        'project'    => ['icon' => 'fa-solid fa-diagram-project', 'color' => '#e08a2c'],
        'activity'   => ['icon' => 'fa-solid fa-pen',             'color' => '#3f87b5'],
        'assignment' => ['icon' => 'fa-solid fa-clipboard-list',  'color' => '#2f9e64'],
    ];
    return $map[$key] ?? ['icon' => 'fa-solid fa-clipboard-list', 'color' => '#3f87b5'];
}

// due-date chip: label + urgency class, aware of submission status
function assess_due_chip($due_date, $status) {
    if ($status === 'done')      return ['label' => 'Marked done', 'class' => 'chip-done'];
    if ($status === 'submitted') return ['label' => 'Submitted',   'class' => 'chip-submitted'];
    if (empty($due_date))        return ['label' => 'No due date', 'class' => 'chip-neutral'];

    $daysLeft = (int) ceil((strtotime($due_date) - strtotime(date('Y-m-d'))) / 86400);

    if ($daysLeft < 0)  return ['label' => 'Overdue',                       'class' => 'chip-overdue'];
    if ($daysLeft === 0) return ['label' => 'Due today',                    'class' => 'chip-urgent'];
    if ($daysLeft === 1) return ['label' => 'Due tomorrow',                 'class' => 'chip-urgent'];
    if ($daysLeft <= 3)  return ['label' => "Due in {$daysLeft} days",      'class' => 'chip-warn'];
    return ['label' => 'Due ' . date('M d', strtotime($due_date)), 'class' => 'chip-ok'];
}

$total     = count($assignments);
$completed = 0;
foreach ($assignments as $a) {
    if ($a['status'] === 'done' || $a['status'] === 'submitted') $completed++;
}
$pct = $total > 0 ? round(($completed / $total) * 100) : 0;
// SVG ring math (radius 42)
$circumference = 2 * M_PI * 42;
$ringOffset    = $circumference - ($pct / 100) * $circumference;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Assignments</title>
    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="student_class.css">
    <link rel="stylesheet" href="student_assessment.css?v=2">
    <style>
        /* extras for the "all classes" view only */
        .assess-class-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 700;
            color: var(--a-primary-deep);
            background: #eaf2f8;
            padding: 4px 10px;
            border-radius: 999px;
            margin-bottom: 8px;
            max-width: 100%;
        }
        .assess-class-select {
            border: 1px solid var(--a-line);
            background: #fff;
            color: var(--a-ink);
            font-size: 13px;
            font-weight: 600;
            padding: 7px 14px;
            border-radius: 999px;
            cursor: pointer;
        }
        .assess-filters-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            width: 100%;
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
    </div>
    <ul>
        <li><a href="student_dashboard.php"><i class="fa-solid fa-house"></i><span>Home</span></a></li>
        <li><a href="student_class_page.php"><i class="fa-solid fa-users"></i><span>Classes</span></a></li>
        <li><a href="student_archived_classes.php"><i class="fa-solid fa-box-archive"></i><span>Archived</span></a></li>
        <li><a href="student_assignments.php" class="active"><i class="fa-solid fa-clipboard-list"></i><span>Assignment</span></a></li>
        <li><a href="student_messages.php"><i class="fa-regular fa-message"></i><span>Messages</span></a></li>
        <li><a href="student_performance.php"><i class="fa-solid fa-chart-pie"></i><span>Performance</span></a></li>
        <li><a href="student_resources.php"><i class="fa-solid fa-book-open"></i><span>Resources</span></a></li>
    </ul>
    <div class="logout-btn">
        <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a>
    </div>
</div>

<!-- SIDEBAR OVERLAY (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileSidebar()"></div>

<!-- MAIN CONTENT -->
<div class="main-content" id="mainContent">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="navbar-left">
            <button class="mobile-menu" onclick="mobileSidebar()" aria-label="Open menu">
                <i class="fa-solid fa-bars"></i>
            </button>
            <img src="img/classroom logo.png" class="navbar-logo" alt="Logo">
        </div>
        <div class="navbar-right">
        
            
            <!-- PROFILE -->

            <a href="profile.php" class="profile-icon" aria-label="My profile">

                <?php if ($hasProfileImage): ?>

                    <img
                        src="uploads/<?= e(rawurlencode($profileImageFile)) ?>"
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

    <div class="container py-4">

        <?php if ($flash): ?>
            <div class="assess-flash mb-3"><i class="fa-solid fa-circle-check me-2"></i><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>

        <!-- OVERVIEW -->
        <div class="assess-overview mb-4">

            <div class="assess-ring" aria-label="<?php echo $pct; ?>% complete">
                <svg viewBox="0 0 100 100">
                    <circle class="ring-track" cx="50" cy="50" r="42"></circle>
                    <circle class="ring-fill" cx="50" cy="50" r="42"
                        style="stroke-dasharray: <?php echo $circumference; ?>; stroke-dashoffset: <?php echo $ringOffset; ?>;">
                    </circle>
                </svg>
                <div class="assess-ring-label">
                    <strong><?php echo $pct; ?>%</strong>
                    <span>done</span>
                </div>
            </div>

            <div class="assess-overview-text">
                <h3>All Assignments</h3>
                <p><?php echo $completed; ?> of <?php echo $total; ?> handled across <?php echo count($classesInList); ?> class<?php echo count($classesInList) === 1 ? '' : 'es'; ?></p>
            </div>

            <div class="assess-filters-row">
                <div class="assess-filters" role="tablist">
                    <button class="filter-chip active" data-filter="all">All<span class="count"><?php echo $total; ?></span></button>
                    <button class="filter-chip" data-filter="todo">To do<span class="count"><?php echo $total - $completed; ?></span></button>
                    <button class="filter-chip" data-filter="submitted">Submitted</button>
                    <button class="filter-chip" data-filter="done">Done</button>
                </div>

                <?php if (count($classesInList) > 0): ?>
                <select class="assess-class-select" id="classFilter">
                    <option value="all">All classes</option>
                    <?php foreach ($classesInList as $cid => $cname): ?>
                        <option value="<?php echo $cid; ?>"><?php echo htmlspecialchars($cname); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>
        </div>

        <!-- LIST -->
        <?php if ($total === 0): ?>

            <div class="assess-empty">
                <i class="fa-regular fa-folder-open"></i>
                <h5>Nothing posted yet</h5>
                <p>New assignments from any of your classes will show up here.</p>
            </div>

        <?php else: foreach ($assignments as $row): ?>

            <?php
                $isDone      = ($row['status'] === 'done');
                $isSubmitted = ($row['status'] === 'submitted');
                $filterState = $isDone ? 'done' : ($isSubmitted ? 'submitted' : 'todo');
                $style       = assess_type_style($row['type']);
                $chip        = assess_due_chip($row['due_date'], $row['status']);
            ?>

            <div class="assess-card" data-status="<?php echo $filterState; ?>" data-class="<?php echo $row['class_id']; ?>">
                <div class="assess-stripe" style="background:<?php echo $style['color']; ?>"></div>

                <div class="assess-card-body">

                    <div class="assess-card-top">
                        <div class="assess-type-icon" style="background:<?php echo $style['color']; ?>1a; color:<?php echo $style['color']; ?>">
                            <i class="<?php echo $style['icon']; ?>"></i>
                        </div>
                        <div class="assess-card-heading">
                            <span class="assess-class-badge">
                                <i class="fa-solid fa-users"></i>
                                <?php echo htmlspecialchars($row['class_name']); ?>
                            </span>
                            <a href="student_assessment_view.php?id=<?php echo $row['id']; ?>" class="stream-row">
                                <h4><?php echo htmlspecialchars($row['title']); ?></h4>
                            </a>
                            <div class="assess-meta">
                                <span class="meta-type"><?php echo htmlspecialchars($row['type']); ?></span>
                                <span class="meta-dot">&middot;</span>
                                <span class="meta-points"><?php echo htmlspecialchars($row['points']); ?> pts</span>
                            </div>
                        </div>
                        <span class="due-chip <?php echo $chip['class']; ?>"><?php echo $chip['label']; ?></span>
                    </div>

                </div>
            </div>

        <?php endforeach; endif; ?>

    </div>
</div>

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


/* combined client-side filtering: status chip + class dropdown, no reload */
(function () {
    const chips = document.querySelectorAll('.filter-chip');
    const cards = document.querySelectorAll('.assess-card');
    const classFilter = document.getElementById('classFilter');

    let currentStatus = 'all';
    let currentClass  = 'all';

    function applyFilters() {
        cards.forEach(card => {
            const statusMatch = (currentStatus === 'all' || card.dataset.status === currentStatus);
            const classMatch  = (currentClass === 'all' || card.dataset.class === currentClass);
            card.style.display = (statusMatch && classMatch) ? '' : 'none';
        });
    }

    chips.forEach(chip => {
        chip.addEventListener('click', () => {
            chips.forEach(c => c.classList.remove('active'));
            chip.classList.add('active');
            currentStatus = chip.dataset.filter;
            applyFilters();
        });
    });

    if (classFilter) {
        classFilter.addEventListener('change', () => {
            currentClass = classFilter.value;
            applyFilters();
        });
    }
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>