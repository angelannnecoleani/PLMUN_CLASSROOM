<?php
session_start();
include 'database.php';
include 'viewer_helper.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit();
}

if(!isset($_GET['id'])){
    die("Class not found");
}

$class_id   = intval($_GET['id']);
$student_id = (int) $_SESSION['user_id'];


/* ==========================================
   PROFILE IMAGE HELPERS
========================================== */

/**
 * Returns a usable URL for a stored profile image, or '' if none exists.
 * Handles: plain filenames, values with a subfolder (profiles/abc.jpg),
 * values already prefixed with uploads/, and blocks ../ tricks.
 */
if (!function_exists('profileImageUrl')) {
    function profileImageUrl($value) {
        $value = trim((string)($value ?? ''));

        if ($value === '' || strtolower($value) === 'null') {
            return '';
        }

        $value = str_replace('\\', '/', $value);

        if (strpos($value, '..') !== false) {
            return '';
        }

        $name = basename($value);

        if (in_array(strtolower($name), ['default.png', 'default.jpg', 'default.jpeg'])) {
            return '';
        }

        $candidates = [
            ltrim($value, '/'),
            'uploads/' . ltrim($value, '/'),
            'uploads/' . $name,
        ];

        foreach ($candidates as $rel) {
            if (is_file(__DIR__ . '/' . $rel)) {
                return implode('/', array_map('rawurlencode', explode('/', $rel)));
            }
        }

        return '';
    }
}

/**
 * Renders an avatar: the image if it exists, otherwise a user icon.
 * If the image fails to load in the browser, it swaps to the icon too.
 */
if (!function_exists('avatarHtml')) {
    function avatarHtml($value, $class = 'announcement-avatar', $defaultClass = 'announcement-avatar-def') {
        $url = profileImageUrl($value);

        if ($url !== '') {
            return '<img src="' . htmlspecialchars($url) . '" class="' . $class . '" alt=""'
                 . ' onerror="this.outerHTML=\'<div class=&quot;' . $class . ' ' . $defaultClass
                 . '&quot;><i class=&quot;fa-solid fa-user&quot;></i></div>\'">';
        }

        return '<div class="' . $class . ' ' . $defaultClass . '"><i class="fa-solid fa-user"></i></div>';
    }
}


/* CHECK IF STUDENT BELONGS TO CLASS */
$check = mysqli_prepare($conn,
    "SELECT 1 FROM class_students WHERE class_id = ? AND student_id = ?");
mysqli_stmt_bind_param($check, "ii", $class_id, $student_id);
mysqli_stmt_execute($check);
mysqli_stmt_store_result($check);
if(mysqli_stmt_num_rows($check) == 0) die("Access denied");
mysqli_stmt_close($check);


/* CLASS INFO - ALLOW CLASSES WITHOUT AN ASSIGNED TEACHER */
$classStmt = mysqli_prepare($conn, "
    SELECT
        classes.*,
        users.name AS teacher_name
    FROM classes
    LEFT JOIN users ON classes.teacher_id = users.id
    WHERE classes.id = ?
");

mysqli_stmt_bind_param($classStmt, "i", $class_id);
mysqli_stmt_execute($classStmt);

$classResult = mysqli_stmt_get_result($classStmt);
$class = mysqli_fetch_assoc($classResult);

mysqli_stmt_close($classStmt);

if (!$class) {
    die("Class not found.");
}

/* ==========================================
   MATCH STUDENT DASHBOARD CLASS CARD COLOR
========================================== */

$cardColors = [
    '#4C8C92',
    '#A65D57',
    '#6B5B95',
    '#8E9B5C',
    '#C97B63',
    '#3F87B5',
    '#B58457',
    '#5C7F8E'
];

if (!function_exists('getClassColor')) {
    function getClassColor($classId, $colors) {
        $index = abs(crc32((string)$classId)) % count($colors);
        return $colors[$index];
    }
}

$classBannerColor = getClassColor($class['id'], $cardColors);


/* STUDENT INFO (for profile pic) */
$userQuery = mysqli_query($conn,
    "SELECT * FROM users WHERE id='$student_id'"
);
$user = mysqli_fetch_assoc($userQuery);

/* Font Awesome icon for a file extension */
if (!function_exists('fileIconClass')) {
    function fileIconClass($ext) {
        $ext = strtolower($ext);
        if ($ext === 'pdf') return 'fa-file-pdf';
        if (in_array($ext, ['doc', 'docx'])) return 'fa-file-word';
        if (in_array($ext, ['ppt', 'pptx'])) return 'fa-file-powerpoint';
        if (in_array($ext, ['xls', 'xlsx', 'csv'])) return 'fa-file-excel';
        if (in_array($ext, ['zip', 'rar'])) return 'fa-file-zipper';
        return 'fa-file';
    }
}

/* ANNOUNCEMENT ATTACHMENTS (multiple images/files/links per announcement)
   Pre-fetched here, keyed by announcement_id, so the stream loop below
   can show every attachment on an announcement post. */
$announcementAttachments = [];
$annAttachStmt = mysqli_prepare($conn, "
    SELECT aa.announcement_id, aa.type, aa.path, aa.original_name
    FROM announcement_attachments aa
    INNER JOIN announcements an ON aa.announcement_id = an.id
    WHERE an.class_id = ?
    ORDER BY aa.id ASC
");
mysqli_stmt_bind_param($annAttachStmt, "i", $class_id);
mysqli_stmt_execute($annAttachStmt);
$annAttachResult = mysqli_stmt_get_result($annAttachStmt);
while($a = mysqli_fetch_assoc($annAttachResult)){
    $announcementAttachments[$a['announcement_id']][] = $a;
}
mysqli_stmt_close($annAttachStmt);

/* MATERIAL ATTACHMENTS (multiple files/images/links per material)
   Pre-fetched here, keyed by material_id, so the stream loop below can
   show every attachment on a material post instead of just one file
   and one link. */
$materialAttachments = [];
$attachStmt = mysqli_prepare($conn, "
    SELECT ma.material_id, ma.type, ma.value
    FROM material_attachments ma
    INNER JOIN materials m ON ma.material_id = m.id
    WHERE m.class_id = ?
    ORDER BY ma.id ASC
");
mysqli_stmt_bind_param($attachStmt, "i", $class_id);
mysqli_stmt_execute($attachStmt);
$attachResult = mysqli_stmt_get_result($attachStmt);
while($a = mysqli_fetch_assoc($attachResult)){
    $materialAttachments[$a['material_id']][] = $a;
}
mysqli_stmt_close($attachStmt);

/* MATERIALS ALREADY SAVED TO RESOURCES BY THIS STUDENT
   Used so the bookmark button in the material modal renders already
   filled in for materials the student previously saved. */
$savedMaterialIds = [];
$savedStmt = mysqli_prepare($conn, "
    SELECT material_id FROM student_resources WHERE student_id = ?
");
mysqli_stmt_bind_param($savedStmt, "i", $student_id);
mysqli_stmt_execute($savedStmt);
$savedResult = mysqli_stmt_get_result($savedStmt);
while ($s = mysqli_fetch_assoc($savedResult)) {
    $savedMaterialIds[] = (int)$s['material_id'];
}
mysqli_stmt_close($savedStmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($class['class_name']); ?></title>

    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link rel="stylesheet" href="student_class.css?v=4">
    <link rel="stylesheet" href="class_chat_actions.css?v=1">
    <link rel="stylesheet" href="notifications.css">

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
        <li><a href="student_assignments.php"><i class="fa-solid fa-clipboard-list"></i><span>Assignment</span></a></li>
        <li><a href="student_messages.php"><i class="fa-regular fa-message"></i><span>Messages</span></a></li>
        <li><a href="student_performance.php"><i class="fa-solid fa-chart-pie"></i><span>Performance</span></a></li>
        <li><a href="student_resources.php"><i class="fa-solid fa-book-open"></i><span>Resources</span></a></li>
    </ul>
    <div class="logout-btn">
        <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a>
    </div>
</div>

<!-- SIDEBAR OVERLAY (mobile only) -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="mobileSidebar()"></div>

<!-- MAIN CONTENT -->
<div class="main-content" id="mainContent">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="navbar-left">
            <button class="mobile-menu" onclick="mobileSidebar()">
                <i class="fa-solid fa-bars"></i>
            </button>
            <img src="img/classroom logo.png" class="navbar-logo" alt="Logo">
        </div>
        <div class="navbar-right">
             <?php if (!empty($class['meet_link'])): ?>
                <a href="<?php echo htmlspecialchars($class['meet_link']); ?>" target="_blank" rel="noopener" class="meet-button" title="Join Google Meet">
                    <i class="fa-solid fa-video"></i>
                </a>
            <?php else: ?>
                <span class="meet-button meet-disabled" title="Wala pang Google Meet na naka-set para sa klaseng ito">
                    <i class="fa-solid fa-video"></i>
                </span>
            <?php endif; ?>

            <div class="dropdown">
                <a href="#" class="position-relative text-dark" role="button"
                data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                    <i class="fa-regular fa-bell fa-lg"></i>
                    <span class="notif-badge d-none" id="notifBadge">0</span>
                </a>

                <div class="dropdown-menu dropdown-menu-end notification-box p-0">
                    <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
                        <h6 class="fw-bold mb-0">Notifications</h6>
                        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none d-none"
                                id="markAllReadBtn">Mark all as read</button>
                    </div>
                    <div id="notifList">
                        <div class="notif-empty">No new notifications</div>
                    </div>
                </div>
            </div>


            <?php echo avatarHtml($user['profile_image'] ?? '', 'profile-icon', 'profile-icon-def'); ?>
        </div>
    </div>

    <!-- CLASS NAV -->
    <div class="class-nav">
        <a href="student_class.php?id=<?php echo $class_id; ?>" class="nav-active">Stream</a>
        <a href="student_assessment.php?id=<?php echo $class_id; ?>">Assignments</a>
        <a href="student_material.php?id=<?php echo $class_id; ?>">Materials</a>
        <a href="student_people.php?id=<?php echo $class_id; ?>">People</a>
    </div>

    <div class="container-fluid class-page-container py-4">

        <!-- BANNER -->
        <div class="class-banner mb-4"
            style="background: <?php echo htmlspecialchars($classBannerColor); ?>;">

            <h2>
                <?php echo htmlspecialchars($class['class_name']); ?>
            </h2>

            <h5>
                <?php
                echo !empty($class['teacher_name'])
                    ? htmlspecialchars($class['teacher_name'])
                    : 'No instructor assigned yet';
                ?>
            </h5>

            <p>
                <?php echo htmlspecialchars($class['section'] ?? ''); ?>
                |
                <?php echo htmlspecialchars($class['schedule'] ?? ''); ?>
            </p>

        </div>

        <div class="row g-4">

            <!-- LEFT: Class Code -->
            <div class="col-12 col-lg-3">
                <div class="class-code">
                    <h6>Class Code</h6>
                    <strong><?php echo htmlspecialchars($class['class_code']); ?></strong>
                </div>
            </div>

            <!-- RIGHT: Stream -->
            <div class="col-12 col-lg-9">

            <?php
            $stream = mysqli_query($conn, "
                SELECT
                    announcements.id,
                    announcements.content,
                    announcements.image,
                    announcements.link,
                    announcements.created_at,
                    users.name,
                    users.profile_image,
                    'announcement' AS post_type,
                    NULL AS title,
                    NULL AS instructions,
                    NULL AS attachment,
                    NULL AS due_date,
                    NULL AS points,
                    NULL AS assessment_type,
                    announcements.pinned AS pinned
                FROM announcements
                INNER JOIN users ON announcements.teacher_id = users.id
                WHERE announcements.class_id = '$class_id'

                UNION ALL

                SELECT
                    assessments.id,
                    NULL,
                    assessments.image,
                    assessments.link,
                    assessments.created_at,
                    COALESCE(users.name, 'No instructor assigned yet') AS name,
                    users.profile_image,
                    'assessment',
                    assessments.title,
                    assessments.instructions,
                    assessments.attachment,
                    assessments.due_date,
                    assessments.points,
                    assessments.type,
                    assessments.pinned AS pinned
                FROM assessments
                INNER JOIN classes ON assessments.class_id = classes.id
                LEFT JOIN users ON classes.teacher_id = users.id
                WHERE assessments.class_id = '$class_id'

                UNION ALL

                SELECT
                    materials.id,
                    NULL,
                    NULL,
                    materials.link,
                    materials.created_at,
                    COALESCE(users.name, 'No instructor assigned yet') AS name,
                    users.profile_image,
                    'material',
                    materials.title,
                    materials.description,
                    materials.file,
                    NULL,
                    NULL,
                    NULL,
                    materials.pinned AS pinned
                FROM materials
                INNER JOIN classes ON materials.class_id = classes.id
                LEFT JOIN users ON classes.teacher_id = users.id
                WHERE materials.class_id = '$class_id'

                ORDER BY pinned DESC, created_at DESC
            ");

            if (!$stream) die("SQL Error: " . mysqli_error($conn));

            while ($row = mysqli_fetch_assoc($stream)):
            ?>

                <?php if ($row['post_type'] === 'announcement'): ?>

                    <?php
                    // Split this announcement's attachments by type
                    $atts   = $announcementAttachments[$row['id']] ?? [];
                    $aImgs  = [];
                    $aFiles = [];
                    $aLinks = [];
                    foreach ($atts as $a) {
                        if ($a['type'] === 'image')     $aImgs[]  = $a;
                        elseif ($a['type'] === 'file')  $aFiles[] = $a;
                        elseif ($a['type'] === 'link')  $aLinks[] = $a;
                    }
                    ?>

                    <!-- ANNOUNCEMENT CARD -->
                    <div class="announcement-card mb-3">
                        <div class="announcement-header">
                            <?php echo avatarHtml($row['profile_image'], 'announcement-avatar', 'announcement-avatar-def'); ?>
                            <div>
                                <h6 class="mb-0"><?php echo htmlspecialchars($row['name']); ?></h6>
                                <small class="text-muted"><?php echo date("M d, Y h:i A", strtotime($row['created_at'])); ?></small>
                            </div>
                        </div>

                        <?php if (trim((string)$row['content']) !== ''): ?>
                            <p class="mt-3 mb-2"><?php echo nl2br(htmlspecialchars($row['content'])); ?></p>
                        <?php endif; ?>

                        <!-- old posts: single image / single link stored in the announcements table -->
                        <?php if (!empty($row['image'])): ?>
                            <img src="uploads/<?php echo htmlspecialchars($row['image']); ?>" class="announcement-image">
                        <?php endif; ?>

                        <?php if (!empty($row['link'])): ?>
                            <a href="<?php echo htmlspecialchars($row['link']); ?>" target="_blank" rel="noopener noreferrer">
                                <?php echo htmlspecialchars($row['link']); ?>
                            </a>
                        <?php endif; ?>

                        <!-- new posts: multiple attachments -->
                        <?php if ($atts): ?>
                        <div class="post-attachments">

                            <?php if ($aImgs): ?>
                            <div class="post-images">
                                <?php foreach ($aImgs as $a): ?>
                                    <a href="uploads/<?php echo htmlspecialchars($a['path']); ?>" target="_blank" rel="noopener">
                                        <img src="uploads/<?php echo htmlspecialchars($a['path']); ?>"
                                             alt="<?php echo htmlspecialchars($a['original_name'] ?? ''); ?>">
                                    </a>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <?php foreach ($aFiles as $a):
                                $fname = $a['original_name'] ?: basename($a['path']);
                                $fext  = pathinfo($a['path'], PATHINFO_EXTENSION);
                                $view  = get_viewer_link('uploads/' . $a['path']);
                            ?>
                                <a class="post-file"
                                   href="<?php echo htmlspecialchars($view['url']); ?>"
                                   target="<?php echo $view['target']; ?>"
                                   rel="noopener">
                                    <i class="fa-solid <?php echo fileIconClass($fext); ?>"></i>
                                    <span><?php echo htmlspecialchars($fname); ?></span>
                                </a>
                            <?php endforeach; ?>

                            <?php foreach ($aLinks as $a): ?>
                                <a class="post-link"
                                   href="<?php echo htmlspecialchars($a['path']); ?>"
                                   target="_blank"
                                   rel="noopener noreferrer">
                                    <i class="fa-solid fa-link"></i>
                                    <span><?php echo htmlspecialchars($a['path']); ?></span>
                                </a>
                            <?php endforeach; ?>

                        </div>
                        <?php endif; ?>

                        <?php if ($row['pinned']): ?>
                            <span class="text-warning mt-2 d-block">
                                <i class="fa-solid fa-thumbtack me-1"></i>Pinned
                            </span>
                        <?php endif; ?>
                    </div>

                <?php elseif ($row['post_type'] === 'assessment'): ?>

                    <!-- ASSESSMENT ROW -->
                    <div class="announcement-card assessment-post stream-row-card mb-3">
                        <a href="student_assessment_view.php?id=<?php echo $row['id']; ?>" class="stream-row">
                            <div class="stream-row-icon <?php echo (strtolower($row['assessment_type']) === 'quiz') ? 'icon-quiz' : 'icon-assignment'; ?>">
                                <i class="fa-solid fa-clipboard"></i>
                            </div>
                            <div class="stream-row-body">
                                <div class="stream-row-title">
                                    <?php if ($row['pinned']): ?>
                                        <span class="text-warning me-1">
                                            <i class="fa-solid fa-thumbtack"></i>
                                        </span>
                                    <?php endif; ?>
                                    <strong><?php echo htmlspecialchars($row['name']); ?></strong>
                                    posted a new <?php echo htmlspecialchars(strtolower($row['assessment_type'])); ?>:
                                    <strong><?php echo htmlspecialchars($row['title']); ?></strong>
                                </div>
                                <div class="stream-row-meta">
                                    <span><?php echo date("M d, Y h:i A", strtotime($row['created_at'])); ?></span>
                                    <span class="dot"></span>
                                    <span>Due <?php echo date("M d, Y", strtotime($row['due_date'])); ?></span>
                                    <span class="dot"></span>
                                    <span><?php echo htmlspecialchars($row['points']); ?> points</span>
                                </div>
                            </div>
                        </a>
                    </div>

                <?php else:
                    // Combine the multi-attachment rows with the legacy
                    // single file/link columns still returned by the UNION
                    // query above, so nothing posted before this update
                    // stops showing up.
                    $attachments = $materialAttachments[$row['id']] ?? [];
                    if (!empty($row['attachment'])) {
                        $attachments[] = ['type' => 'file', 'value' => $row['attachment']];
                    }
                    if (!empty($row['link'])) {
                        $attachments[] = ['type' => 'link', 'value' => $row['link']];
                    }
                    $isSaved = in_array((int)$row['id'], $savedMaterialIds, true);
                ?>

                <!-- MATERIAL ROW -->
                    <div class="announcement-card material-post stream-row-card mb-3">
                        <a href="#" class="stream-row" data-bs-toggle="modal" data-bs-target="#materialDetail<?php echo $row['id']; ?>">
                            <div class="stream-row-icon icon-material">
                                <i class="fa-solid fa-book-open"></i>
                            </div>
                            <div class="stream-row-body">
                                <div class="stream-row-title">
                                    <?php if ($row['pinned']): ?>
                                        <span class="text-warning me-1">
                                            <i class="fa-solid fa-thumbtack"></i>
                                        </span>
                                    <?php endif; ?>
                                    <strong><?php echo htmlspecialchars($row['name']); ?></strong>
                                    posted a new material:
                                    <strong><?php echo htmlspecialchars($row['title']); ?></strong>
                                </div>
                                <div class="stream-row-meta">
                                    <span><?php echo date("M d, Y h:i A", strtotime($row['created_at'])); ?></span>
                                    <?php if (count($attachments) > 1): ?>
                                        <span class="dot"></span>
                                        <span><?php echo count($attachments); ?> attachments</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    </div>

                    <!-- MATERIAL DETAIL MODAL -->
                    <div class="modal fade stream-detail-modal material-detail-modal" id="materialDetail<?php echo $row['id']; ?>" tabindex="-1">
                        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                            <div class="modal-content">

                                <!-- HEADER: icon + title + close -->
                                <div class="modal-header material-detail-header">
                                    <div class="material-detail-header-left">
                                        <div class="material-detail-icon">
                                            <i class="fa-solid fa-book-open"></i>
                                        </div>
                                        <h5 class="modal-title fw-bold mb-0"><?php echo htmlspecialchars($row['title']); ?></h5>
                                    </div>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>

                                <div class="modal-body">

                                    <!-- TEACHER ROW: avatar + name + date, bookmark on the right -->
                                    <div class="material-detail-meta-row">
                                        <div class="material-detail-meta-left">
                                            <?php echo avatarHtml($row['profile_image'], 'material-detail-avatar', 'material-detail-avatar-def'); ?>
                                            <div>
                                                <h6 class="mb-0"><?php echo htmlspecialchars($row['name']); ?></h6>
                                                <small class="text-muted"><?php echo date("M d, Y h:i A", strtotime($row['created_at'])); ?></small>
                                            </div>
                                        </div>

                                        <button type="button"
                                                class="material-detail-save-btn save-resource-btn<?php echo $isSaved ? ' saved' : ''; ?>"
                                                data-id="<?php echo $row['id']; ?>"
                                                data-saved="<?php echo $isSaved ? '1' : '0'; ?>"
                                                onclick="saveToResources(<?php echo $row['id']; ?>, this)"
                                                title="<?php echo $isSaved ? 'Saved to Resources' : 'Save to Resources'; ?>">
                                            <i class="fa-<?php echo $isSaved ? 'solid' : 'regular'; ?> fa-bookmark"></i>
                                        </button>
                                    </div>

                                    <?php if ($row['pinned']): ?>
                                        <span class="text-warning mt-2 d-block">
                                            <i class="fa-solid fa-thumbtack me-1"></i>Pinned
                                        </span>
                                    <?php endif; ?>

                                    <hr class="material-detail-divider">

                                    <!-- CAPTION -->
                                    <?php if (!empty($row['instructions'])): ?>
                                        <div class="stream-detail-desc"><?php echo nl2br(htmlspecialchars($row['instructions'])); ?></div>
                                    <?php endif; ?>

                                    <!-- LINKS (kept as before, shown above the file grid) -->
                                    <?php foreach ($attachments as $a): if ($a['type'] === 'link'): ?>
                                        <a href="<?php echo htmlspecialchars($a['value']); ?>" target="_blank" rel="noopener" class="stream-detail-link">
                                            <i class="fa-solid fa-link me-1"></i><?php echo htmlspecialchars($a['value']); ?>
                                        </a>
                                    <?php endif; endforeach; ?>

                                    <!-- FILE / IMAGE ATTACHMENTS: boxed preview + filename below -->
                                    <div class="material-detail-attachments">
                                    <?php foreach ($attachments as $a): if ($a['type'] === 'file'):
                                        $ext = strtolower(pathinfo($a['value'], PATHINFO_EXTENSION));
                                        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])): ?>

                                            <div class="material-detail-file">
                                                <a href="uploads/<?php echo htmlspecialchars($a['value']); ?>" target="_blank" rel="noopener">
                                                    <img src="uploads/<?php echo htmlspecialchars($a['value']); ?>" class="material-detail-file-preview">
                                                </a>
                                                <div class="material-detail-file-name"><?php echo htmlspecialchars($a['value']); ?></div>
                                            </div>

                                        <?php else:
                                            $view = get_viewer_link('uploads/' . $a['value']);
                                        ?>

                                            <div class="material-detail-file">
                                                <a href="<?php echo htmlspecialchars($view['url']); ?>" target="<?php echo $view['target']; ?>" rel="noopener"
                                                   class="material-detail-file-preview material-detail-file-icon">
                                                    <i class="fa-solid fa-file"></i>
                                                </a>
                                                <div class="material-detail-file-name"><?php echo htmlspecialchars($a['value']); ?></div>
                                            </div>

                                        <?php endif;
                                    endif; endforeach; ?>
                                    </div>

                                </div>

                                <div class="modal-footer border-0">
                                    <a href="student_material.php?id=<?php echo $class_id; ?>" class="btn btn-outline-primary">
                                        View in Materials
                                    </a>
                                </div>

                            </div>
                        </div>
                    </div>

                <?php endif; ?>

            <?php endwhile; ?>

            </div><!-- end col-lg-9 -->
        </div><!-- end row -->
    </div><!-- end container -->
</div><!-- end main-content -->

<!-- ===================== FLOATING GROUP CHAT WIDGET ===================== -->
<div class="gchat-widget" id="gchatWidget">
    <div class="gchat-header">
        <div class="gchat-header-left">
            <i class="fa-regular fa-comments me-2"></i>
            <span>Group Chat</span>
        </div>
        <button class="gchat-close-btn" onclick="toggleClassChat()">X</button>
    </div>
    <div class="gchat-body" id="gchatBody">
        <div class="gchat-empty" id="gchatEmpty">No messages yet. Say hello! 👋</div>
    </div>

    <!-- Pending attachment preview -->
    <div class="gchat-preview" id="gchatPreview" hidden></div>

    <div class="gchat-footer">
        <label for="gchatFileUpload" class="gchat-icon-btn" title="Attach file">
            <i class="fa-solid fa-paperclip"></i>
        </label>
        <input type="file" id="gchatFileUpload"
               accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.csv,.txt,.zip,.rar" hidden>

        <label for="gchatImageUpload" class="gchat-icon-btn" title="Send image">
            <i class="fa-regular fa-image"></i>
        </label>
        <input type="file" id="gchatImageUpload" accept="image/*" hidden>

        <textarea id="gchatInput" class="gchat-textarea" placeholder="Aa (paste a link to share it)" rows="1"></textarea>
        <button class="gchat-send-btn" id="gchatSendBtn" title="Send">
            <i class="fa-regular fa-paper-plane"></i>
        </button>
    </div>
</div>

<!-- FAB bubble -->
<button class="gchat-fab" id="gchatFab" onclick="toggleClassChat()" title="Group Chat">
    <i class="fa-regular fa-comments"></i>
</button>

<!-- NEW: edit / delete message logic (must load before the chat script below) -->
<script src="class_chat_actions.js?v=2"></script>
<!-- NEW: unread (missed message) badges -->
<script src="class_chat_unread.js?v=2"></script>
<!-- NEW: @mentions -->
<script src="class_chat_mentions.js?v=1"></script>
<script src="notifications.js"></script>
<script>
/* ── SIDEBAR (desktop collapse + mobile drawer) ── */
const sidebarEl = document.getElementById('sidebar');
const mainEl    = document.getElementById('mainContent');
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
    sidebarEl.classList.toggle('show');
    const isOpen = sidebarEl.classList.contains('show');
    overlayEl.classList.toggle('show', isOpen);
    document.body.classList.toggle('no-scroll', isOpen);
}

function closeMobileSidebar() {
    sidebarEl.classList.remove('show');
    overlayEl.classList.remove('show');
    document.body.classList.remove('no-scroll');
}

// Close the drawer after tapping a menu link
document.querySelectorAll('.sidebar a').forEach(function (a) {
    a.addEventListener('click', function () {
        if (isMobile()) closeMobileSidebar();
    });
});

// Reset if the window is resized up to desktop
window.addEventListener('resize', function () {
    if (!isMobile()) closeMobileSidebar();
});

/* ── GROUP CHAT (text + image + file + links + edit/delete) ── */
(function () {
    const CLASS_ID  = <?php echo json_encode($class_id); ?>;
    const FETCH_URL = 'class_chat_fetch.php';
    const SEND_URL  = 'class_chat_send.php';
    const POLL_MS   = 3000;
    const MAX_BYTES = 10 * 1024 * 1024;

    let lastId = 0, timer = null, isOpen = false, polling = false, sending = false;
    let pendingFile = null;

    const widget    = document.getElementById('gchatWidget');
    const fab       = document.getElementById('gchatFab');
    const body      = document.getElementById('gchatBody');
    const empty     = document.getElementById('gchatEmpty');
    const input     = document.getElementById('gchatInput');
    const sendBtn   = document.getElementById('gchatSendBtn');
    const imgInput  = document.getElementById('gchatImageUpload');
    const fileInput = document.getElementById('gchatFileUpload');
    const preview   = document.getElementById('gchatPreview');

    // NEW: @mentions (type @ to pick someone)
    ChatMentions.init({ meId: <?php echo (int)$student_id; ?> });
    ChatMentions.attach({ input: input, classId: CLASS_ID });


    // NEW: set up edit / delete for this chat widget
    ChatActions.init({
        classId: CLASS_ID,
        chatBody: body,
        linkify: ChatMentions.render,
        emptyId: 'gchatEmpty',
        classes: { row: 'gchat-row', bubble: 'gchat-bubble', time: 'gchat-time', col: 'gchat-col' }
    });

    // NEW: unread (missed messages) badge on the chat bubble
    const fabBadge = document.createElement('b');
    fabBadge.className = 'gchat-fab-badge';
    fabBadge.hidden = true;
    fab.appendChild(fabBadge);

    ChatUnread.onUpdate(function (u) {
        const n = isOpen ? 0 : (u.counts[CLASS_ID] || 0);
        const mn = isOpen ? 0 : ((u.mentions && u.mentions[CLASS_ID]) || 0);
        fabBadge.textContent = (mn > 0 ? '@' : '') + (n > 99 ? '99+' : n);
        fabBadge.classList.toggle('has-mention', mn > 0);
        fabBadge.title = mn > 0 ? 'You were mentioned' : '';
        fabBadge.hidden = n === 0;
    });

    // only count messages as read while the chat is open and the tab is visible
    function markReadIfVisible() {
        if (isOpen && lastId > 0 && !document.hidden) ChatUnread.markRead(CLASS_ID, lastId);
    }
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) markReadIfVisible();
    });

    window.toggleClassChat = function () {
        isOpen = !isOpen;
        widget.classList.toggle('open', isOpen);
        fab.classList.toggle('hidden', isOpen);
        if (isOpen) { startPolling(); scrollBottom(); input.focus(); }
        else        { stopPolling(); }
    };

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c =>
            ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    // Escape first, then turn http(s) URLs into clickable links
    function linkify(text) {
        return esc(text).replace(/(https?:\/\/[^\s<]+)/g,
            '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
    }
    function fmtTime(raw) {
        const d = new Date(String(raw).replace(' ', 'T'));
        return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }
    function scrollBottom() { body.scrollTop = body.scrollHeight; }

    function imageHtml(url, alt) {
        return `<a href="${url}" target="_blank" rel="noopener">
                    <img src="${url}" class="gchat-img" alt="${esc(alt)}">
                </a>`;
    }
    function fileHtml(url, name) {
        return `<a href="${url}" target="_blank" rel="noopener" download="${esc(name)}" class="gchat-file">
                    <i class="fa-solid fa-paperclip"></i><span>${esc(name)}</span>
                </a>`;
    }

    const DEFAULT_IMGS = ['', 'default.png', 'default.jpg', 'default.jpeg', 'null'];

    // Build a safe URL for a stored profile image value
    function profileSrc(value) {
        const raw = String(value || '').trim().replace(/\\/g, '/');

        if (raw.includes('..')) return '';

        const name = raw.split('/').pop();

        if (DEFAULT_IMGS.includes(name.toLowerCase())) return '';

        // value already has a folder (e.g. "uploads/x.jpg" or "profiles/x.jpg")
        if (raw.includes('/')) {
            return encodeURI(raw.replace(/^\/+/, ''));
        }

        return 'uploads/' + encodeURIComponent(name);
    }

    function renderMsg(m) {
        if (empty) empty.style.display = 'none';

        const row = document.createElement('div');
        row.className = 'gchat-row ' + (m.is_me ? 'me' : 'other');

        const src = profileSrc(m.profile_image);

        const avatar = src
            ? `<img src="${esc(src)}" class="gchat-avatar" alt="">`
            : `<div class="gchat-avatar gchat-avatar-def"><i class="fa-solid fa-user"></i></div>`;

        let content = '';
        const msg = (m.message || '').trim();

        // New format: attachment columns
        if (m.attachment) {
            const url = 'uploads/' + encodeURI(m.attachment);
            content += (m.attachment_type === 'image')
                ? imageHtml(url, m.attachment_name)
                : fileHtml(url, m.attachment_name || 'File');
        }

        // Old format: "[image:xxx]" / "[file:xxx]" stored in the message text
        const oldImg  = msg.match(/^\[image:(.+)\]$/);
        const oldFile = msg.match(/^\[file:(.+)\]$/);

        if (oldImg) {
            content += imageHtml('uploads/' + encodeURI(oldImg[1]), 'image');
        } else if (oldFile) {
            content += fileHtml('uploads/' + encodeURI(oldFile[1]), oldFile[1].replace(/^\d+_/, ''));
        } else if (msg !== '') {
            content += `<div class="gchat-bubble">${ChatMentions.render(m.message, m.mentions)}</div>`;
        }

        // NEW: deleted messages show a placeholder
        if (m.is_deleted) {
            content = '<div class="gchat-bubble msg-deleted"><i class="fa-solid fa-ban"></i> This message was deleted</div>';
        }

        row.innerHTML = `
            ${m.is_me ? '' : avatar}
            <div class="gchat-col">
                ${m.is_me ? '' : `<div class="gchat-name">${esc(m.name)}</div>`}
                ${content}
                <div class="gchat-time">${fmtTime(m.created_at)}</div>
            </div>
            ${m.is_me ? avatar : ''}
        `;

        // If a profile image fails to load, swap it for the user icon
        row.querySelectorAll('img.gchat-avatar').forEach(img => {
            img.addEventListener('error', () => {
                const d = document.createElement('div');
                d.className = 'gchat-avatar gchat-avatar-def';
                d.innerHTML = '<i class="fa-solid fa-user"></i>';
                img.replaceWith(d);
            });
        });

        body.appendChild(row);
        ChatActions.decorate(row, m);   // NEW: adds the ⋮ menu + "edited" tag
        lastId = Math.max(lastId, Number(m.id));
    }

    async function poll() {
        if (polling) return;
        polling = true;
        try {
            const r = await fetch(`${FETCH_URL}?class_id=${CLASS_ID}&after_id=${lastId}`);
            const d = await r.json();
            if (d.messages && d.messages.length) { d.messages.forEach(renderMsg); scrollBottom(); markReadIfVisible(); }
            else if (d.error) console.error('chat poll error:', d.error);
        } catch (e) { console.error('chat poll:', e); }
        finally { polling = false; }
    }

    // CHANGED: each tick also syncs edits / deletes made by other people
    async function tick() { await poll(); await ChatActions.sync(); }

    function startPolling() { tick(); if (!timer) timer = setInterval(tick, POLL_MS); }
    function stopPolling()  { clearInterval(timer); timer = null; }

    /* ---- attachment picking ---- */
    function setPending(file, kind) {
        if (!file) return;
        if (file.size > MAX_BYTES) {
            alert('File is too large (max 10MB).');
            imgInput.value = ''; fileInput.value = '';
            return;
        }
        pendingFile = file;
        // only one attachment at a time
        if (kind === 'image') fileInput.value = ''; else imgInput.value = '';
        preview.hidden = false;
        preview.innerHTML = '';
        if (kind === 'image') {
            const img = document.createElement('img');
            img.src = URL.createObjectURL(file);
            preview.appendChild(img);
        } else {
            preview.insertAdjacentHTML('beforeend', '<i class="fa-solid fa-file"></i>');
        }
        const name = document.createElement('span');
        name.textContent = file.name;
        const x = document.createElement('button');
        x.type = 'button'; x.className = 'gchat-preview-x'; x.textContent = '×';
        x.onclick = clearPending;
        preview.append(name, x);
    }
    function clearPending() {
        pendingFile = null;
        imgInput.value = ''; fileInput.value = '';
        preview.hidden = true; preview.innerHTML = '';
    }
    imgInput.addEventListener('change',  () => setPending(imgInput.files[0],  'image'));
    fileInput.addEventListener('change', () => setPending(fileInput.files[0], 'file'));

    /* ---- sending ---- */
    async function sendMessage() {
        if (sending) return;
        const text = input.value.trim();
        if (!text && !pendingFile) return;

        sending = true;
        const fd = new FormData();
        fd.append('class_id', CLASS_ID);
        fd.append('message', text);
        if (pendingFile) fd.append('attachment', pendingFile);

        input.disabled = true; sendBtn.disabled = true;
        try {
            const r = await fetch(SEND_URL, { method: 'POST', body: fd });
            const raw = await r.text();
            let d;
            try { d = JSON.parse(raw); }
            catch (e) {
                console.error('send: non-JSON response', r.status, raw);
                alert('Server error (HTTP ' + r.status + '):\n' + raw.slice(0, 300));
                return;
            }

            if (d.success) {
                input.value = ''; input.style.height = 'auto';
                clearPending();
                await poll();
            } else {
                alert(d.error || 'Send failed');
            }
        } catch (e) {
            console.error('chat send:', e);
            alert('Send failed: ' + e.message);
        } finally {
            sending = false;
            input.disabled = false; sendBtn.disabled = false; input.focus();
        }
    }

    sendBtn.addEventListener('click', sendMessage);
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    });
    input.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 80) + 'px';
    });
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    async function saveToResources(materialId, btn){
        const currentlySaved = btn.dataset.saved === '1';
        const nextAction = currentlySaved ? 'unsave' : 'save';

        btn.disabled = true;
        const originalHTML = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

        try{
            const res = await fetch('save_resource.php', {
                method: 'POST',
                body: new URLSearchParams({ material_id: materialId, action: nextAction })
            });

            const raw = await res.text();
            let data;
            try {
                data = JSON.parse(raw);
            } catch(parseErr) {
                console.error('save_resources.php did not return JSON. HTTP status:', res.status, 'Raw response:', raw);
                alert(`Save failed (HTTP ${res.status}). The server response wasn't valid JSON — check the browser console and confirm save_resources.php exists at this path and has no PHP errors.`);
                btn.innerHTML = originalHTML;
                return;
            }

            if(data.success){
                const isSaved = !!data.saved;
                btn.dataset.saved = isSaved ? '1' : '0';
                btn.classList.toggle('saved', isSaved);
                btn.title = isSaved ? 'Saved to Resources' : 'Save to Resources';
                btn.innerHTML = `<i class="fa-${isSaved ? 'solid' : 'regular'} fa-bookmark"></i>`;
            } else {
                alert('Failed: ' + (data.error || 'Unknown error'));
                btn.innerHTML = originalHTML;
            }
        } catch(e){
            console.error('saveToResources network error:', e);
            alert('Network error while saving: ' + e.message);
            btn.innerHTML = originalHTML;
        } finally {
            btn.disabled = false;
        }
    }
</script>
</body>
</html>