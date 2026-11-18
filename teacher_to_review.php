<?php
session_start();
include 'database.php';

$teacher_id = $_SESSION['user_id'];

$userQuery = mysqli_query($conn, "SELECT * FROM users WHERE id='$teacher_id'");
$user = mysqli_fetch_assoc($userQuery);

/* ------------------------------------------------------------
   FILTERS (from the URL)
   tab       = review | reviewed   (default: review)
   class_id  = 0 (All Classes) or a specific class id
------------------------------------------------------------ */
$tab = (isset($_GET['tab']) && $_GET['tab'] === 'reviewed') ? 'reviewed' : 'review';

$selected_class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;

/* ------------------------------------------------------------
   Classes taught by this teacher (for the filter dropdown)
   NOTE: adjust "class_name" below if your classes table
   uses a different column name for the class title.
------------------------------------------------------------ */
$classStmt = mysqli_prepare($conn, "SELECT id, class_name FROM classes WHERE teacher_id = ? ORDER BY class_name ASC");
mysqli_stmt_bind_param($classStmt, "i", $teacher_id);
mysqli_stmt_execute($classStmt);
$classListResult = mysqli_stmt_get_result($classStmt);
$teacherClasses = [];
while ($row = mysqli_fetch_assoc($classListResult)) {
    $teacherClasses[] = $row;
}

/* ------------------------------------------------------------
   Build the assessment query
   - always scoped to this teacher's classes
   - optionally scoped to a single class
   - "review"   -> at least 1 turned-in submission that is NOT graded yet
   - "reviewed" -> has turned-in submissions AND all of them are graded
------------------------------------------------------------ */
$params = [$teacher_id];
$paramTypes = "i";

$classFilterSql = "";
if ($selected_class_id > 0) {
    $classFilterSql = " AND a.class_id = ? ";
    $params[] = $selected_class_id;
    $paramTypes .= "i";
}

if ($tab === 'reviewed') {
    // Only assessments that have submissions AND every one of them is graded.
    $havingSql = "HAVING turned_in_count > 0 AND turned_in_count = graded_count";
} else {
    // "To Review" tab shows every assessment from every class,
    // regardless of submission/grading status.
    $havingSql = "";
}

$sql = "
    SELECT
        a.*,
        c.class_name,
        (SELECT COUNT(*) FROM class_students cs
            WHERE cs.class_id = a.class_id) AS assigned_count,
        (SELECT COUNT(*) FROM submissions s
            WHERE s.assessment_id = a.id
            AND s.status IN ('submitted','done')) AS turned_in_count,
        (SELECT COUNT(*) FROM submissions s
            WHERE s.assessment_id = a.id
            AND s.grade IS NOT NULL) AS graded_count
    FROM assessments a
    INNER JOIN classes c ON c.id = a.class_id
    WHERE c.teacher_id = ?
    $classFilterSql
    $havingSql
    ORDER BY c.class_name ASC, a.due_date IS NULL, a.due_date ASC, a.id DESC
";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $paramTypes, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

/* Group the flat result set by class name for the accordion */
$grouped = [];
while ($row = mysqli_fetch_assoc($result)) {
    $className = $row['class_name'];
    if (!isset($grouped[$className])) {
        $grouped[$className] = [];
    }
    $grouped[$className][] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>To Review</title>

    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="teacher_class.css">
    <link rel="stylesheet" href="teacher_class2.css">

    <link rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>

<body>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileSidebar()"></div>
        <!-- SIDEBAR -->
    <div class="sidebar" id="sidebar">

        <div class="sidebar-header">
            <button class="toggle-btn" onclick="toggleSidebar()" aria-label="Toggle sidebar">
                <i class="fa-solid fa-bars"></i>
            </button>

            <button class="sidebar-close-btn" onclick="closeMobileSidebar()" aria-label="Close sidebar">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <ul>

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
                <a href="teacher_to_review.php" class="active-link">
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

        </ul>

        <div class="logout-btn">

            <a href="logout.php">

                <i class="fa-solid fa-right-from-bracket"></i>

                <span>Logout</span>

            </a>

        </div>

    </div>

    <div class="main-content" id="mainContent">

        <!-- TOPBAR -->
        <div class="topbar">

            <div class="navbar-left">

                <button class="mobile-menu"
                        onclick="mobileSidebar()">

                    <i class="fa-solid fa-bars"></i>

                </button>

                <img src="img/classroom logo.png"
                    class="navbar-logo">

            </div>

            <div class="navbar-right">
                
                <?php
                $profileFile = $user['profile_image'] ?? '';
                $hasProfileImage = !empty($profileFile)
                    && !in_array(strtolower($profileFile), ['default.png', 'default.jpg', 'default.jpeg', 'null'])
                    && file_exists(__DIR__ . '/uploads/' . basename($profileFile));
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

        <div class="class-nav">

            <a href="teacher_to_review.php?tab=review<?php echo $selected_class_id ? '&class_id='.$selected_class_id : ''; ?>"
               class="<?php echo $tab === 'review' ? 'nav-active' : ''; ?>">
                To Review
            </a>

            <a href="teacher_to_review.php?tab=reviewed<?php echo $selected_class_id ? '&class_id='.$selected_class_id : ''; ?>"
               class="<?php echo $tab === 'reviewed' ? 'nav-active' : ''; ?>">
                Reviewed
            </a>

        </div>

        <div class="container py-4">
            <!-- CONTENT -->

            <div class="assessment-page">

                <div class="assessment-actions">

                    <div class="topic-filter-wrap">
                        <select
                            class="topic-filter-select"
                            id="classFilter"
                            onchange="filterByClass(this.value)">

                            <option value="0" <?php echo $selected_class_id === 0 ? 'selected' : ''; ?>>
                                All Classes
                            </option>

                            <?php foreach ($teacherClasses as $c): ?>
                            <option value="<?php echo $c['id']; ?>"
                                <?php echo $selected_class_id === (int)$c['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['class_name']); ?>
                            </option>
                            <?php endforeach; ?>

                        </select>
                        <i class="fa-solid fa-chevron-down topic-filter-icon"></i>
                    </div>

                </div>

                <?php if (empty($grouped)): ?>

                    <div class="empty-state">
                        <i class="fa-regular fa-circle-check"></i>
                        <p>
                            <?php echo $tab === 'review'
                                ? "No assessments found yet."
                                : "No fully graded assessments yet."; ?>
                        </p>
                    </div>

                <?php else: ?>

                    <?php foreach ($grouped as $className => $assessments): ?>

                    <div class="topic-block mb-3">

                        <div class="topic-header-bar">
                            <div class="topic-header-left">
                                <button class="topic-collapse-btn" onclick="toggleTopic(this)">
                                    <i class="fa-solid fa-chevron-down"></i>
                                </button>
                                <span class="topic-name">
                                    <?php echo htmlspecialchars($className); ?>
                                </span>
                            </div>
                        </div>

                        

                        <div class="topic-body">

                            <?php foreach ($assessments as $assessment): ?>
                            <div class="assessment-item">

                                <div class="assessment-top-row">

                                    <div class="assessment-title-wrap">
                                        <i class="fa-regular fa-clipboard assessment-icon"></i>
                                        <a href="teacher_submissions.php?id=<?php echo $assessment['id']; ?>" class="assessment-title assessment-title-link">
                                            <?php echo htmlspecialchars($assessment['title']); ?>
                                        </a>
                                    </div>

                                    <div class="assessment-due-wrap">
                                        <span class="due-label">Due</span>
                                        <span class="due-date">
                                            <?php echo $assessment['due_date']
                                                ? date("F d, Y", strtotime($assessment['due_date']))
                                                : 'No due date'; ?>
                                        </span>
                                    </div>

                                </div>

                                <div class="assessment-stats-row">

                                    <div class="posted-label"><?php echo htmlspecialchars($assessment['type']); ?></div>
                                    <div class="stat-cell">
                                        <strong><?php echo (int) $assessment['turned_in_count']; ?></strong>
                                        <span>Turned In</span>
                                    </div>
                                    <div class="stat-cell">
                                        <strong><?php echo (int) $assessment['assigned_count']; ?></strong>
                                        <span>Assigned</span>
                                    </div>
                                    <div class="stat-cell">
                                        <strong><?php echo (int) $assessment['graded_count']; ?></strong>
                                        <span>Graded</span>
                                    </div>

                                </div>

                            </div>
                            <?php endforeach; ?>

                        </div>

                    </div>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>
        </div>

    </div>

    <script>
/* =========================================================
   SIDEBAR RESPONSIVENESS
========================================================= */

const sidebar = document.getElementById("sidebar");
const mainContent = document.getElementById("mainContent");
const sidebarOverlay = document.getElementById("sidebarOverlay");

function toggleSidebar() {
    // Desktop sidebar collapse
    if (window.innerWidth > 768) {
        sidebar.classList.toggle("collapsed");
        mainContent.classList.toggle("expanded");
    }
}

function mobileSidebar() {
    // Open mobile sidebar
    if (window.innerWidth <= 768) {
        sidebar.classList.add("show");
        sidebarOverlay.classList.add("show");
        document.body.style.overflow = "hidden";
    }
}

function closeMobileSidebar() {
    sidebar.classList.remove("show");
    sidebarOverlay.classList.remove("show");
    document.body.style.overflow = "";
}

// Close sidebar when clicking a navigation link on mobile
document.querySelectorAll(".sidebar ul a, .logout-btn a").forEach(link => {
    link.addEventListener("click", function() {
        if (window.innerWidth <= 768) {
            closeMobileSidebar();
        }
    });
});

// Close sidebar when pressing Escape
document.addEventListener("keydown", function(event) {
    if (event.key === "Escape") {
        closeMobileSidebar();
    }
});

// Reset mobile sidebar when switching back to desktop
window.addEventListener("resize", function() {
    if (window.innerWidth > 768) {
        closeMobileSidebar();
    }
});

/* =========================================================
   COLLAPSE CLASS / TOPIC
========================================================= */

function toggleTopic(btn) {
    const body = btn.closest(".topic-block").querySelector(".topic-body");

    body.classList.toggle("collapsed");

    const icon = btn.querySelector("i");

    icon.classList.toggle("fa-chevron-down");
    icon.classList.toggle("fa-chevron-up");
}

/* =========================================================
   CLASS FILTER
========================================================= */

function filterByClass(classId) {
    const tab = "<?php echo $tab; ?>";

    let url = "teacher_to_review.php?tab=" + tab;

    if (classId && classId !== "0") {
        url += "&class_id=" + encodeURIComponent(classId);
    }

    window.location.href = url;
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>