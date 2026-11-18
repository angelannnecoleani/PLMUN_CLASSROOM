<?php
session_start();
include 'database.php';
include 'auth_helpers.php';
require_once 'assessment_attachments.php';

// Returns true only if the profile image is real and the file exists on disk
function hasValidProfileImage($file){
    if(empty($file)) return false;
    if(in_array(strtolower($file), ['default.png', 'default.jpg', 'default.jpeg', 'null'])) return false;
    return file_exists(__DIR__ . '/uploads/' . basename($file));
}

/* ── LOGIN + ROLE CHECK ── */
$role = $_SESSION['role'] ?? '';

if(empty($_SESSION['user_id']) || !in_array($role, ['teacher', 'program_chair'], true)){
    header("Location: login.php");
    exit();
}

$teacher_id = (int)$_SESSION['user_id'];
$isChair    = ($role === 'program_chair');

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
mysqli_stmt_close($aStmt);

if(!$assessment){
    die("Assessment not found");
}

$class_id = (int)$assessment['class_id'];

/* teacher: own class / program chair: any class in their program */
if(!getClassAccess($conn, $class_id, $teacher_id, $role)){
    http_response_code(403);
    die("Access denied. This assessment does not belong to a class you can manage.");
}

/* LOGGED-IN USER (for the profile icon) */
$uStmt = mysqli_prepare($conn, "SELECT * FROM users WHERE id = ?");
mysqli_stmt_bind_param($uStmt, "i", $teacher_id);
mysqli_stmt_execute($uStmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($uStmt));
mysqli_stmt_close($uStmt);

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
mysqli_stmt_close($sStmt);

$total       = count($students);
$turnedIn    = 0;
$graded      = 0;
foreach($students as $s){
    if(in_array($s['status'], ['submitted','done'])) $turnedIn++;
    if($s['grade'] !== null) $graded++;
}

/* EVERY FILE + LINK ATTACHED TO ANY OF THESE SUBMISSIONS, IN ONE QUERY,
   GROUPED BY submission_id */
$submissionIds = [];
foreach ($students as $s) {
    if (!empty($s['submission_id'])) $submissionIds[] = (int) $s['submission_id'];
}

$attachmentsBySubmission = [];
if (!empty($submissionIds)) {
    $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
    $types        = str_repeat('i', count($submissionIds));

    $attStmt = mysqli_prepare($conn,
        "SELECT * FROM submission_attachments WHERE submission_id IN ($placeholders) ORDER BY id ASC");

    // Bind a variable number of parameters via an array of references
    $bindArgs = array_merge([$attStmt, $types], $submissionIds);
    $bindRefs = [];
    foreach ($bindArgs as $key => $value) {
        $bindRefs[$key] = &$bindArgs[$key];
    }
    call_user_func_array('mysqli_stmt_bind_param', $bindRefs);

    mysqli_stmt_execute($attStmt);
    $attResult = mysqli_stmt_get_result($attStmt);
    while ($row = mysqli_fetch_assoc($attResult)) {
        $attachmentsBySubmission[$row['submission_id']][] = $row;
    }
    mysqli_stmt_close($attStmt);
}

/* ATTACHMENTS ADDED TO THE ASSESSMENT (images, files, links) */
$description = $assessment['instructions'] ?? '';

$assessmentAttachments = aa_fetch($conn, $assessment_id);

// Older assessments only have the single image / file / link columns
if(empty($assessmentAttachments)){
    if(!empty($assessment['image'])){
        $assessmentAttachments[] = [
            'type' => 'image', 'file_path' => $assessment['image'],
            'original_name' => $assessment['image'], 'url' => null,
        ];
    }
    if(!empty($assessment['attachment'])){
        $assessmentAttachments[] = [
            'type' => 'file', 'file_path' => $assessment['attachment'],
            'original_name' => preg_replace('/^assessment_file_[0-9a-f]{13}(?:\.[0-9]+)?_/', '', $assessment['attachment']),
            'url' => null,
        ];
    }
    if(!empty($assessment['link'])){
        $assessmentAttachments[] = [
            'type' => 'link', 'file_path' => null,
            'original_name' => null, 'url' => $assessment['link'],
        ];
    }
}

$attImages = [];
$attFiles  = [];
$attLinks  = [];
foreach($assessmentAttachments as $att){
    if($att['type'] === 'image')     $attImages[] = $att;
    elseif($att['type'] === 'file')  $attFiles[]  = $att;
    elseif($att['type'] === 'link')  $attLinks[]  = $att;
}
$attachmentTotal = count($assessmentAttachments);

/* Font Awesome icon for a file name */
function attachment_icon($name){
    $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
    switch($ext){
        case 'pdf':  return 'fa-file-pdf';
        case 'doc':
        case 'docx': return 'fa-file-word';
        case 'ppt':
        case 'pptx': return 'fa-file-powerpoint';
        case 'xls':
        case 'xlsx': return 'fa-file-excel';
        case 'txt':  return 'fa-file-lines';
        default:     return 'fa-file';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($assessment['title']); ?> - Submissions</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="teacher_class.css?v=5">
    <link rel="stylesheet" href="teacher_submissions.css">

    <style>
        /* ── Assessment attachments (Instructions tab) ── */
        .attach-section-title {
            font-size: 13px;
            font-weight: 700;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin: 20px 0 10px;
        }

        .tab-count {
            display: inline-block;
            min-width: 20px;
            margin-left: 6px;
            padding: 1px 7px;
            font-size: 11.5px;
            font-weight: 700;
            color: #3f87b5;
            background: #eaf3fa;
            border-radius: 20px;
            text-align: center;
        }

        .instr-images {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
        }
        .instr-images a { display: block; }
        .instr-images img {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #e8eef4;
            display: block;
            transition: box-shadow 0.2s, transform 0.2s;
        }
        .instr-images a:hover img {
            box-shadow: 0 4px 12px rgba(0,0,0,.12);
            transform: translateY(-1px);
        }

        .instr-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .instr-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 14px;
            background: #f8fafc;
            border: 1px solid #e0e8f0;
            border-radius: 9px;
            color: #222;
            text-decoration: none;
            font-size: 14px;
            min-width: 0;
            transition: border-color 0.2s, background 0.2s;
        }
        .instr-item i {
            color: #3f87b5;
            font-size: 18px;
            width: 22px;
            text-align: center;
            flex-shrink: 0;
        }
        .instr-item span {
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .instr-item:hover {
            border-color: #3f87b5;
            background: #f0f6fb;
            color: #222;
        }
        .instr-item .open-icon {
            color: #bbb;
            font-size: 13px;
            width: auto;
        }

        @media (max-width: 768px) {
            .instr-images {
                grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
            }
            .instr-images img { height: 110px; }
            .instr-item { padding: 10px 12px; font-size: 13.5px; }
        }
    </style>
</head>
<body>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <button class="toggle-btn" onclick="toggleSidebar()">
            <i class="fa-solid fa-bars"></i>
        </button>
        <button class="sidebar-close-btn" onclick="mobileSidebar()" aria-label="Close menu">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>
    <ul>
        <?php if ($isChair): ?>
            <li><a href="program_chair_dashboard.php"><i class="fa-solid fa-house"></i><span>Home</span></a></li>
        <?php else: ?>
            <li><a href="teacher_dashboard.php"><i class="fa-solid fa-house"></i><span>Home</span></a></li>
            <li><a href="teacher_classpage.php"><i class="fa-solid fa-users"></i><span>Classes</span></a></li>
            <li><a href="teacher_to_review.php"><i class="fa-solid fa-clipboard-list"></i><span>To Review</span></a></li>
            <li><a href="teacher_messages.php"><i class="fa-regular fa-message"></i><span>Messages</span></a></li>
            <li><a href="teacher_performance.php"><i class="fa-solid fa-chart-pie"></i><span>Performance</span></a></li>
            <li><a href="teacher_resources.php"><i class="fa-solid fa-book-open"></i><span>Resources</span></a></li>
        <?php endif; ?>
    </ul>
    <div class="logout-btn">
        <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a>
    </div>
</div>

<!-- Dims the page behind the sidebar on mobile; tapping it closes the menu -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="mobileSidebar()"></div>

<div class="main-content" id="mainContent">

    <div class="topbar">
        <div class="navbar-left">
            <button class="mobile-menu" onclick="mobileSidebar()"><i class="fa-solid fa-bars"></i></button>
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

    <div class="container py-4">

        <a href="teacher_assessment.php?id=<?php echo $class_id; ?>" class="back-link">
            <i class="fa-solid fa-arrow-left"></i> Back to Assessments
        </a>

        <?php if ($flash): ?>
            <div class="assess-flash mb-3"><i class="fa-solid fa-circle-check me-2"></i><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>

        <!-- TABS -->
        <div class="submissions-tabs">
            <button type="button" class="submissions-tab" id="tabBtn-instructions" onclick="showSubTab('instructions')">
                Instructions<?php if($attachmentTotal > 0): ?><span class="tab-count"><i class="fa-solid fa-paperclip"></i> <?php echo $attachmentTotal; ?></span><?php endif; ?>
            </button>
            <button type="button" class="submissions-tab active" id="tabBtn-studentwork" onclick="showSubTab('studentwork')">Student work</button>
        </div>

        <!-- INSTRUCTIONS PANEL -->
        <div class="submissions-panel" id="panel-instructions" style="display:none;">
            <div class="instructions-card">
                <h2 class="instructions-title"><?php echo htmlspecialchars($assessment['title']); ?></h2>
                <p class="submissions-sub">
                    <?php echo htmlspecialchars($assessment['type']); ?> &middot;
                    <?php echo (int) $assessment['points']; ?> pts &middot;
                    Due <?php echo !empty($assessment['due_date']) ? date("F d, Y", strtotime($assessment['due_date'])) : 'No due date'; ?>
                </p>

                <?php if(!empty($description)): ?>
                    <p class="instructions-desc"><?php echo nl2br(htmlspecialchars($description)); ?></p>
                <?php else: ?>
                    <p class="instructions-desc instructions-empty">No description provided.</p>
                <?php endif; ?>

                <!-- IMAGES -->
                <?php if(!empty($attImages)): ?>
                    <div class="attach-section-title">Images (<?php echo count($attImages); ?>)</div>
                    <div class="instr-images">
                        <?php foreach($attImages as $img): ?>
                            <a href="uploads/<?php echo rawurlencode(basename($img['file_path'])); ?>" target="_blank" rel="noopener">
                                <img src="uploads/<?php echo rawurlencode(basename($img['file_path'])); ?>"
                                     alt="<?php echo htmlspecialchars($img['original_name'] ?: 'Attached image'); ?>"
                                     loading="lazy">
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- FILES -->
                <?php if(!empty($attFiles)): ?>
                    <div class="attach-section-title">Files (<?php echo count($attFiles); ?>)</div>
                    <div class="instr-list">
                        <?php foreach($attFiles as $f): ?>
                            <?php $fname = $f['original_name'] ?: basename($f['file_path']); ?>
                            <a href="uploads/<?php echo rawurlencode(basename($f['file_path'])); ?>"
                               target="_blank" rel="noopener" class="instr-item">
                                <i class="fa-solid <?php echo attachment_icon($fname); ?>"></i>
                                <span><?php echo htmlspecialchars($fname); ?></span>
                                <i class="fa-solid fa-arrow-up-right-from-square open-icon"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- LINKS -->
                <?php if(!empty($attLinks)): ?>
                    <div class="attach-section-title">Links (<?php echo count($attLinks); ?>)</div>
                    <div class="instr-list">
                        <?php foreach($attLinks as $l): ?>
                            <?php
                                // only allow http(s) links to be clickable
                                $safeUrl = preg_match('#^https?://#i', $l['url']) ? $l['url'] : '#';
                            ?>
                            <a href="<?php echo htmlspecialchars($safeUrl); ?>"
                               target="_blank" rel="noopener noreferrer" class="instr-item">
                                <i class="fa-solid fa-link"></i>
                                <span><?php echo htmlspecialchars($l['url']); ?></span>
                                <i class="fa-solid fa-arrow-up-right-from-square open-icon"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- STUDENT WORK PANEL -->
        <div class="submissions-panel" id="panel-studentwork">

            <div class="submissions-header">
                <div>
                    <h2>Assessment: <?php echo htmlspecialchars($assessment['title']); ?></h2>
                </div>

                <div class="submissions-summary">
                    <div class="summary-pill"><strong><?php echo $turnedIn; ?>/<?php echo $total; ?></strong><span>Turned In</span></div>
                    <div class="summary-pill"><strong><?php echo $graded; ?>/<?php echo $total; ?></strong><span>Graded</span></div>
                </div>
            </div>

            <div class="submissions-table-wrap">
                <table class="submissions-table">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Submitted On</th>
                            <th>Status</th>
                            <th>Score</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if($total === 0): ?>
                        <tr>
                            <td colspan="4">
                                <div class="empty-state">
                                    <i class="fa-regular fa-folder-open"></i>
                                    <p>No students in this class yet.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: foreach($students as $s): ?>

                        <?php
                            $status = $s['status'];
                            if($status === 'done')          { $badge = ['label' => 'Marked done', 'class' => 'badge-done']; }
                            elseif($status === 'submitted') { $badge = ['label' => 'Submitted', 'class' => 'badge-submitted']; }
                            else                             { $badge = ['label' => 'Missing', 'class' => 'badge-missing']; }

                            $subId = $s['submission_id'] ?? null;
                            $attachmentCount = $subId ? count($attachmentsBySubmission[$subId] ?? []) : 0;
                            $submittedAtDisplay = !empty($s['submitted_at']) ? date("M d, h:i A", strtotime($s['submitted_at'])) : '';
                            $studentHasImage = hasValidProfileImage($s['profile_image'] ?? '');
                        ?>

                        <tr>
                            <td>
                                <?php if ($subId): ?>

                                    <a href="view_submission.php?id=<?php echo (int)$subId; ?>"
                                    class="student-name-cell student-name-clickable">

                                        <?php if($studentHasImage): ?>
                                            <img
                                                src="uploads/<?php echo htmlspecialchars(basename($s['profile_image'])); ?>"
                                                class="submission-avatar"
                                                alt="Student"
                                                onerror="this.outerHTML='<div class=&quot;submission-avatar submission-avatar-def&quot;><i class=&quot;fa-solid fa-user&quot;></i></div>';">
                                        <?php else: ?>
                                            <div class="submission-avatar submission-avatar-def">
                                                <i class="fa-solid fa-user"></i>
                                            </div>
                                        <?php endif; ?>

                                        <span><?php echo htmlspecialchars($s['name']); ?></span>

                                        <?php if($attachmentCount > 0): ?>
                                            <span class="attachment-count-badge">
                                                <i class="fa-solid fa-paperclip"></i>
                                                <?php echo $attachmentCount; ?>
                                            </span>
                                        <?php endif; ?>

                                    </a>

                                <?php else: ?>

                                    <div class="student-name-cell">

                                        <?php if($studentHasImage): ?>
                                            <img
                                                src="uploads/<?php echo htmlspecialchars(basename($s['profile_image'])); ?>"
                                                class="submission-avatar"
                                                alt="Student"
                                                onerror="this.outerHTML='<div class=&quot;submission-avatar submission-avatar-def&quot;><i class=&quot;fa-solid fa-user&quot;></i></div>';">
                                        <?php else: ?>
                                            <div class="submission-avatar submission-avatar-def">
                                                <i class="fa-solid fa-user"></i>
                                            </div>
                                        <?php endif; ?>

                                        <span><?php echo htmlspecialchars($s['name']); ?></span>

                                    </div>

                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if(!empty($submittedAtDisplay)): ?>
                                    <?php echo $submittedAtDisplay; ?>
                                <?php else: ?>
                                    <span class="cell-muted">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $badge['class']; ?>"><?php echo $badge['label']; ?></span>
                            </td>
                            <td>
                                <form action="save_grade.php" method="post" class="grade-form">
                                    <input type="hidden" name="assessment_id" value="<?php echo $assessment_id; ?>">
                                    <input type="hidden" name="student_id" value="<?php echo (int)$s['student_id']; ?>">

                                    <input
                                        type="number"
                                        name="grade"
                                        class="grade-input"
                                        placeholder="--"
                                        min="0"
                                        max="<?php echo (int) $assessment['points']; ?>"
                                        step="0.5"
                                        value="<?php echo $s['grade'] !== null ? htmlspecialchars($s['grade']) : ''; ?>">

                                    <span class="grade-total">/ <?php echo (int) $assessment['points']; ?></span>

                                    <button type="submit" class="btn-mini btn-mini-primary">Save</button>
                                </form>
                            </td>
                        </tr>

                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
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
    document.getElementById("sidebarOverlay").classList.toggle("show");
    document.body.classList.toggle("no-scroll");
}

/* Close the mobile menu with the Escape key */
document.addEventListener("keydown", function(e){
    if(e.key === "Escape" && document.getElementById("sidebar").classList.contains("show")){
        mobileSidebar();
    }
});
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