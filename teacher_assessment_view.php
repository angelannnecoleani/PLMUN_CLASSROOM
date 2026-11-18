<?php
session_start();
include 'database.php';

if(!isset($_GET['id'])){
    die("Assessment not found");
}

$assessment_id = intval($_GET['id']);

/* ASSESSMENT + CLASS INFO */
$aStmt = mysqli_prepare($conn, "
    SELECT a.*, c.class_name, c.id AS class_id
    FROM assessments a
    INNER JOIN classes c ON c.id = a.class_id
    WHERE a.id = ?
");
mysqli_stmt_bind_param($aStmt, "i", $assessment_id);
mysqli_stmt_execute($aStmt);
$assessment = mysqli_fetch_assoc(mysqli_stmt_get_result($aStmt));

if(!$assessment){
    die("Assessment not found");
}

$class_id = $assessment['class_id'];

/* FLASH MESSAGE FROM save_grade.php */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* EVERY STUDENT IN THE CLASS + THEIR SUBMISSION (IF ANY) FOR THIS ASSESSMENT */
$sStmt = mysqli_prepare($conn, "
    SELECT
        u.id AS student_id, u.name, u.profile_image,
        s.id AS submission_id, s.file_path, s.status, s.submitted_at,
        s.grade, s.graded_at
    FROM class_students cs
    INNER JOIN users u ON u.id = cs.student_id
    LEFT JOIN submissions s
        ON s.student_id = u.id AND s.assessment_id = ?
    WHERE cs.class_id = ?
    ORDER BY
        (s.status IS NULL) ASC,
        u.name ASC
");
mysqli_stmt_bind_param($sStmt, "ii", $assessment_id, $class_id);
mysqli_stmt_execute($sStmt);
$result = mysqli_stmt_get_result($sStmt);

$students = [];
while($row = mysqli_fetch_assoc($result)){
    $students[] = $row;
}

$total       = count($students);
$turnedIn    = 0;
$graded      = 0;
foreach($students as $s){
    if(in_array($s['status'], ['submitted','done'])) $turnedIn++;
    if($s['grade'] !== null) $graded++;
}

$description   = $assessment['instructions'] ?? '';
$attachedLink  = $assessment['link']         ?? '';
$attachedFile  = $assessment['attachment']   ?? '';
$attachedImage = $assessment['image']        ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($assessment['title']); ?> - Submissions</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="teacher_class.css">
    <link rel="stylesheet" href="teacher_submissions.css">
</head>
<body>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <button class="toggle-btn" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
    </div>
    <ul>
        <li><a href="teacher_dashboard.php"><i class="fa-solid fa-house"></i><span>Home</span></a></li>
        <li><a href="teacher_classpage.php"><i class="fa-solid fa-users"></i><span>Classes</span></a></li>
        <li><a href="#"><i class="fa-solid fa-clipboard-list"></i><span>To Review</span></a></li>
        <li><a href="#"><i class="fa-regular fa-message"></i><span>Messages</span></a></li>
        <li><a href="#"><i class="fa-solid fa-chart-pie"></i><span>Performance</span></a></li>
        <li><a href="#"><i class="fa-solid fa-book-open"></i><span>Resources</span></a></li>
    </ul>
    <div class="logout-btn">
        <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a>
    </div>
</div>

<div class="main-content" id="mainContent">

    <div class="topbar">
        <div class="navbar-left">
            <button class="mobile-menu" onclick="mobileSidebar()"><i class="fa-solid fa-bars"></i></button>
            <img src="img/classroom logo.png" class="navbar-logo">
        </div>
        <div class="navbar-right">
            <a href="#" class="text-dark"><i class="fa-solid fa-video fa-2x"></i></a>
            <div class="profile-icon"></div>
        </div>
    </div>

    <div class="container py-4">

        <a href="teacher_assessment.php?id=<?php echo $class_id; ?>" class="back-link">
            <i class="fa-solid fa-arrow-left"></i> Back to Assessments
        </a>

        <?php if ($flash): ?>
            <div class="assess-flash mb-3"><i class="fa-solid fa-circle-check me-2"></i><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>

        <!-- TABS -->
        <div class="submissions-tabs">
            <button type="button" class="submissions-tab" id="tabBtn-instructions" onclick="showSubTab('instructions')">Instructions</button>
            <button type="button" class="submissions-tab active" id="tabBtn-studentwork" onclick="showSubTab('studentwork')">Student work</button>
        </div>

        <!-- INSTRUCTIONS PANEL -->
        <div class="submissions-panel" id="panel-instructions" style="display:none;">
            <div class="instructions-card">
                <h2 class="instructions-title"><?php echo htmlspecialchars($assessment['title']); ?></h2>
                <p class="submissions-sub">
                    <?php echo htmlspecialchars($assessment['type']); ?> &middot;
                    <?php echo (int) $assessment['points']; ?> pts &middot;
                    Due <?php echo date("F d, Y", strtotime($assessment['due_date'])); ?>
                </p>

                <?php if(!empty($description)): ?>
                    <p class="instructions-desc"><?php echo nl2br(htmlspecialchars($description)); ?></p>
                <?php else: ?>
                    <p class="instructions-desc instructions-empty">No description provided.</p>
                <?php endif; ?>

                <?php if(!empty($attachedImage)): ?>
                    <img src="uploads/<?php echo htmlspecialchars($attachedImage); ?>" class="instructions-image" alt="Attached image">
                <?php endif; ?>

                <?php if(!empty($attachedLink)): ?>
                    <a href="<?php echo htmlspecialchars($attachedLink); ?>" target="_blank" class="instructions-link">
                        <i class="fa-solid fa-link"></i> <?php echo htmlspecialchars($attachedLink); ?>
                    </a>
                <?php endif; ?>

                <?php if(!empty($attachedFile)): ?>
                    <a href="uploads/<?php echo htmlspecialchars($attachedFile); ?>" target="_blank" class="instructions-file">
                        <i class="fa-solid fa-paperclip"></i> <?php echo htmlspecialchars(basename($attachedFile)); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<script>
function toggleSidebar(){
    document.getElementById("sidebar").classList.toggle("collapsed");
    document.getElementById("mainContent").classList.toggle("expanded");
}
function mobileSidebar(){
    document.getElementById("sidebar").classList.toggle("show");
}
function showSubTab(tab){
    document.getElementById("panel-instructions").style.display = (tab === "instructions") ? "block" : "none";
    document.getElementById("panel-studentwork").style.display  = (tab === "studentwork")  ? "block" : "none";

    document.getElementById("tabBtn-instructions").classList.toggle("active", tab === "instructions");
    document.getElementById("tabBtn-studentwork").classList.toggle("active", tab === "studentwork");
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>