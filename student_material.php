<?php
session_start();
include 'database.php';
include 'viewer_helper.php';

// Must be logged in
if(empty($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}
$student_id = intval($_SESSION['user_id']);

if(!isset($_GET['id'])){
    die("Class not found");
}
$class_id = intval($_GET['id']);

// Confirm the class exists
$stmt = mysqli_prepare($conn, "SELECT * FROM classes WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $class_id);
mysqli_stmt_execute($stmt);
$class = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);

if(!$class) die("Class not found");

// Confirm this student is enrolled in the class
// NOTE: adjust table/column names to match your actual enrollment table
$stmt = mysqli_prepare($conn, "SELECT id FROM class_students WHERE class_id = ? AND student_id = ?");
mysqli_stmt_bind_param($stmt, "ii", $class_id, $student_id);
mysqli_stmt_execute($stmt);
$enrolled = mysqli_stmt_get_result($stmt)->num_rows > 0;
mysqli_stmt_close($stmt);

if(!$enrolled){
    die("You are not enrolled in this class.");
}

// Fetch logged-in student's info (for profile icon)
$stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$user = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);

// Fetch the class teacher (shown in the material detail modal)
$teacher = null;
$teacher_id = intval($class['teacher_id'] ?? 0);
if($teacher_id > 0){
    $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $teacher_id);
    mysqli_stmt_execute($stmt);
    $teacher = mysqli_stmt_get_result($stmt)->fetch_assoc();
    mysqli_stmt_close($stmt);
}
$teacherName = trim(($teacher['first_name'] ?? '') . ' ' . ($teacher['last_name'] ?? ''));
if($teacherName === ''){
    $teacherName = $teacher['name'] ?? $teacher['fullname'] ?? $teacher['username'] ?? 'Teacher';
}

// Fetch materials for this class
$stmt = mysqli_prepare($conn, "SELECT * FROM materials WHERE class_id = ? ORDER BY id DESC");
mysqli_stmt_bind_param($stmt, "i", $class_id);
mysqli_stmt_execute($stmt);
$materialsResult = mysqli_stmt_get_result($stmt);

$materials = [];
while($row = mysqli_fetch_assoc($materialsResult)){
    $materials[] = $row;
}
mysqli_stmt_close($stmt);

// Fetch every attachment (image/file/link) for these materials in one go,
// then group them by material_id so a material can show more than
// one file, image, or link.
$attachmentsByMaterial = [];
if(count($materials) > 0){
    $ids = array_map(fn($m) => intval($m['id']), $materials);
    $idList = implode(',', $ids);
    $attachQuery = mysqli_query($conn, "
        SELECT * FROM material_attachments
        WHERE material_id IN ($idList)
        ORDER BY id ASC
    ");
    if($attachQuery){
        while($a = mysqli_fetch_assoc($attachQuery)){
            $attachmentsByMaterial[$a['material_id']][] = $a;
        }
    }
}

// Icon helper — same logic used on the teacher side, so files look
// consistent everywhere.
function material_icon_for($filename){
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if(in_array($ext, ['jpg','jpeg','png','gif','webp'])) return 'fa-file-image';
    if($ext === 'pdf') return 'fa-file-pdf';
    if(in_array($ext, ['doc','docx'])) return 'fa-file-word';
    if(in_array($ext, ['ppt','pptx'])) return 'fa-file-powerpoint';
    if(in_array($ext, ['xls','xlsx'])) return 'fa-file-excel';
    if(in_array($ext, ['mp4','mov','avi'])) return 'fa-file-video';
    return 'fa-file';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Materials – <?php echo htmlspecialchars($class['class_name']); ?></title>
    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">
    <link rel="stylesheet" href="teacher_class.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <!-- Extra styles for the material detail modal + mobile.
         Kept inline so you don't have to edit any CSS file. -->
    <style>
        /* Overlay + mobile drawer helpers (safe if already defined in your CSS) */
        .sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:999;opacity:0;transition:opacity .3s ease}
        .sidebar-overlay.show{display:block;opacity:1}
        .sidebar-close-btn{display:none;border:none;background:none;font-size:20px;cursor:pointer;color:#444;padding:6px 8px;border-radius:6px}
        body.no-scroll{overflow:hidden}

        /* Modal: header stays put, body scrolls when content is long */
        .material-detail-modal .modal-dialog{max-width:550px;width:calc(100% - 30px)}
        .material-modal-content{display:flex;flex-direction:column;max-height:calc(100vh - 40px);max-height:calc(100dvh - 40px)}
        .material-modal-header{flex-shrink:0}
        .material-modal-body{overflow-y:auto;-webkit-overflow-scrolling:touch}
        .material-modal-title{min-width:0}
        .material-title-area{flex:1}

        /* Multiple files grid */
        .material-file-grid{display:flex;flex-wrap:wrap;gap:18px;padding-left:26px}
        .material-file-item{display:flex;flex-direction:column;align-items:flex-start;width:130px}
        .material-file-item .material-file-name{padding-left:2px;max-width:130px}
        .material-title-wrap.material-open:hover .material-title{color:#3f87b5}
        .material-teacher-details{min-width:0}
        .material-teacher-details strong,.material-teacher-details span{overflow-wrap:anywhere}

        @media (max-width:768px){
            .sidebar-header{justify-content:flex-end}
            .sidebar-close-btn{display:block}
            .material-title-wrap{min-height:44px}
        }
        @media (max-width:576px){
            .material-detail-modal .modal-dialog{width:calc(100% - 20px);margin:10px auto}
            .material-file-grid{padding-left:0;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
            .material-file-item{width:100%;min-width:0}
            .material-file-item .material-file-preview{width:100%;height:120px}
            .material-file-item .material-file-name{max-width:100%;font-size:13px}
            .material-modal-title{font-size:18px}
        }
        @media (max-width:340px){
            .material-file-grid{grid-template-columns:1fr}
        }
    </style>
</head>
<body>

<!-- Dims the page and closes the mobile menu when tapped -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- SIDEBAR -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <button class="toggle-btn" onclick="toggleSidebar()">
            <i class="fa-solid fa-bars"></i>
        </button>
        <button class="sidebar-close-btn" onclick="closeMobileSidebar()" aria-label="Close menu">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>
    <ul>
        <li><a href="student_dashboard.php"><i class="fa-solid fa-house"></i><span>Home</span></a></li>
        <li><a href="student_classpage.php"><i class="fa-solid fa-users"></i><span>Classes</span></a></li>
        <li><a href="student_archived_classes.php"><i class="fa-solid fa-box-archive"></i><span>Archived</span></a></li>
        <li><a href="student_assignments.php"><i class="fa-solid fa-clipboard-list"></i><span>Asignment</span></a></li>
        <li><a href="student_messages.php"><i class="fa-regular fa-message"></i><span>Messages</span></a></li>
        <li><a href="student_performance.php"><i class="fa-solid fa-chart-pie"></i><span>Performance</span></a></li>
        <li><a href="student_resources.php"><i class="fa-solid fa-book-open"></i><span>Resources</span></a></li>
    </ul>
    <div class="logout-btn">
        <a href="logout.php">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Logout</span>
        </a>
    </div>
</div>

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

            <?php if(!empty($user['profile_image'])): ?>
                <img src="uploads/<?php echo htmlspecialchars($user['profile_image']); ?>" class="profile-icon" alt="Profile">
            <?php else: ?>
                <div class="profile-icon"><i class="fa-solid fa-user"></i></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- CLASS NAV -->
    <div class="class-nav">
        <a href="student_class.php?id=<?php echo $class_id; ?>">Stream</a>
        <a href="student_assessment.php?id=<?php echo $class_id; ?>">Assignments</a>
        <a href="student_materials.php?id=<?php echo $class_id; ?>" class="nav-active">Materials</a>
        <a href="student_people.php?id=<?php echo $class_id; ?>">People</a>

    </div>

    <div class="container py-4">

        <!-- MATERIALS LIST -->
        <?php if(count($materials) === 0): ?>
            <div class="empty-state">
                <i class="fa-solid fa-book-open"></i>
                <p>No materials have been posted yet.</p>
            </div>
        <?php else: ?>

        <div class="topic-block" data-topic="none">

            <!-- TOPIC HEADER (no options menu — student view) -->
            <div class="topic-header-bar">
                <div class="topic-header-left">
                    <button class="topic-collapse-btn" onclick="toggleTopic('topic-main')">
                        <i class="fa-solid fa-chevron-down"></i>
                    </button>
                    <span class="topic-name">Materials</span>
                </div>
            </div>

            <!-- MATERIAL ROWS -->
            <div class="topic-body" id="topic-main">

            <?php foreach($materials as $material):
                $attachments = $attachmentsByMaterial[$material['id']] ?? [];
                // Legacy single file/link columns still shown if present,
                // so materials saved before multi-attachment support keep working.
                if(!empty($material['file'])){
                    $attachments[] = ['type' => 'file', 'value' => $material['file']];
                }
                if(!empty($material['link'])){
                    $attachments[] = ['type' => 'link', 'value' => $material['link']];
                }
                // Lead icon for the row = first file's icon, else link, else generic doc
                $leadIcon = 'fa-file';
                foreach($attachments as $a){
                    if($a['type'] === 'file'){ $leadIcon = material_icon_for($a['value']); break; }
                    if($a['type'] === 'link'){ $leadIcon = 'fa-link'; }
                }

                // Data handed to the detail modal's JavaScript.
                // Files get the same viewer link/target used in the list below.
                $payloadAttachments = [];
                foreach($attachments as $a){
                    if($a['type'] === 'file'){
                        $v = get_viewer_link('uploads/' . $a['value']);
                        $payloadAttachments[] = [
                            'type'   => 'file',
                            'value'  => $a['value'],
                            'url'    => $v['url'],
                            'target' => $v['target'],
                        ];
                    } else {
                        $payloadAttachments[] = ['type' => 'link', 'value' => $a['value']];
                    }
                }
                $payload = [
                    'title'       => $material['title'] ?? '',
                    'description' => $material['description'] ?? '',
                    'posted'      => date("M d, Y · g:i A", strtotime($material['created_at'])),
                    'attachments' => $payloadAttachments,
                ];
                $payloadJson = htmlspecialchars(json_encode($payload), ENT_QUOTES, 'UTF-8');
            ?>

                <div class="material-item">

                    <div class="material-top-row">

                        <div class="material-title-wrap material-open"
                             role="button" tabindex="0" style="cursor:pointer"
                             data-material="<?php echo $payloadJson; ?>">
                            <i class="fa-regular <?php echo $leadIcon; ?> material-icon"></i>
                            <div>
                                <span class="material-title">
                                    <?php echo htmlspecialchars($material['title']); ?>
                                </span>
                                <div class="material-meta">
                                    Posted <?php echo date("M d, Y", strtotime($material['created_at'])); ?>
                                    <?php if(count($attachments) > 1): ?>
                                        &middot; <?php echo count($attachments); ?> attachments
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- no three-dot menu here — students can't edit/delete -->

                    </div>

                    <!-- DESCRIPTION + ATTACHMENTS -->
                    <?php if(!empty($material['description']) || count($attachments) > 0): ?>
                    <div class="material-body">

                        <?php if(!empty($material['description'])): ?>
                            <p class="material-description">
                                <?php echo nl2br(htmlspecialchars($material['description'])); ?>
                            </p>
                        <?php endif; ?>

                        <?php foreach($attachments as $a): ?>
                            <?php if($a['type'] === 'file'):
                                $view = get_viewer_link('uploads/' . $a['value']);
                            ?>
                                <a href="<?php echo htmlspecialchars($view['url']); ?>"
                                   class="material-attachment" target="<?php echo $view['target']; ?>" rel="noopener">
                                    <i class="fa-solid <?php echo material_icon_for($a['value']); ?> me-2"></i>
                                    <?php echo htmlspecialchars($a['value']); ?>
                                </a>
                            <?php else: ?>
                                <a href="<?php echo htmlspecialchars($a['value']); ?>"
                                   class="material-attachment" target="_blank" rel="noopener">
                                    <i class="fa-solid fa-link me-2"></i>
                                    <?php echo htmlspecialchars($a['value']); ?>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>

                    </div>
                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

            </div><!-- end topic-body -->
        </div><!-- end topic-block -->

        <?php endif; ?>

    </div><!-- end container -->
</div><!-- end main-content -->


<!-- MATERIAL DETAIL MODAL -->
<div class="modal fade material-detail-modal" id="materialDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content material-modal-content">

            <div class="material-modal-header">
                <div class="material-title-area">
                    <div class="material-title-icon"><i class="fa-solid fa-book-open"></i></div>
                    <h5 class="material-modal-title" id="mdTitle"></h5>
                </div>
                <button type="button" class="material-close-btn" data-bs-dismiss="modal" aria-label="Close">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="material-modal-body">
                <div class="material-teacher-row">
                    <div class="material-teacher-info">
                        <?php if(!empty($teacher['profile_image'])): ?>
                            <img src="uploads/<?php echo htmlspecialchars($teacher['profile_image']); ?>"
                                 class="material-teacher-avatar" alt="">
                        <?php else: ?>
                            <div class="material-teacher-avatar material-avatar-default">
                                <i class="fa-solid fa-user"></i>
                            </div>
                        <?php endif; ?>
                        <div class="material-teacher-details">
                            <strong><?php echo htmlspecialchars($teacherName); ?></strong>
                            <span id="mdPosted"></span>
                        </div>
                    </div>
                    <a href="#" class="material-bookmark-btn" id="mdBookmark" aria-label="Bookmark">
                        <i class="fa-regular fa-bookmark"></i>
                    </a>
                </div>

                <div class="material-divider"></div>

                <div class="material-caption" id="mdCaption"></div>
                <div id="mdLinks"></div>
                <div class="material-file-grid" id="mdFiles"></div>
            </div>

        </div>
    </div>
</div>


<!-- Bootstrap MUST load before the scripts below that use bootstrap.Modal -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

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

/* Close sidebar when navigation link is clicked */

document.querySelectorAll('.sidebar a').forEach(function(a) {

    a.addEventListener('click', function() {

        if (isMobile()) {
            closeMobileSidebar();
        }

    });

});

/* Close sidebar using overlay */

overlayEl.addEventListener('click', closeMobileSidebar);

/* Close sidebar using Escape */

document.addEventListener('keydown', function(event) {

    if (event.key === 'Escape') {
        closeMobileSidebar();
    }

});

/* Handle screen resizing */

window.addEventListener('resize', function() {

    if (!isMobile()) {
        closeMobileSidebar();
    }

});


// Picking a nav link closes the menu so it doesn't stay open on the new page
document.querySelectorAll('.sidebar ul li a, .sidebar .logout-btn a').forEach(function(link){
    link.addEventListener('click', function(){
        if(document.getElementById('sidebar').classList.contains('show')) closeMobileSidebar();
    });
});

function toggleTopic(id){
    const body = document.getElementById(id);
    const icon = body.previousElementSibling.querySelector('.topic-collapse-btn i');
    body.classList.toggle('collapsed');
    icon.classList.toggle('fa-chevron-down');
    icon.classList.toggle('fa-chevron-right');
}

/* ============================================================
   MATERIAL DETAIL MODAL — view uploaded materials
   ============================================================ */
(function(){
    const modalEl = document.getElementById('materialDetailModal');
    if(!modalEl) return;

    const modal   = new bootstrap.Modal(modalEl);
    const IMG_EXT = ['jpg','jpeg','png','gif','webp'];

    function extOf(name){
        return String(name).split('.').pop().toLowerCase();
    }

    function iconFor(name){
        const ext = extOf(name);
        if(IMG_EXT.includes(ext)) return 'fa-file-image';
        if(ext === 'pdf') return 'fa-file-pdf';
        if(['doc','docx'].includes(ext)) return 'fa-file-word';
        if(['ppt','pptx'].includes(ext)) return 'fa-file-powerpoint';
        if(['xls','xlsx'].includes(ext)) return 'fa-file-excel';
        if(['mp4','mov','avi'].includes(ext)) return 'fa-file-video';
        return 'fa-file';
    }

    function openMaterial(data){
        document.getElementById('mdTitle').textContent  = data.title || '';
        document.getElementById('mdPosted').textContent = 'Posted ' + (data.posted || '');

        const caption = document.getElementById('mdCaption');
        caption.textContent = data.description || 'No caption';
        caption.classList.toggle('material-caption-empty', !data.description);
        caption.style.whiteSpace = 'pre-wrap';

        const links = document.getElementById('mdLinks');
        const files = document.getElementById('mdFiles');
        links.innerHTML = '';
        files.innerHTML = '';

        (data.attachments || []).forEach(function(a){
            if(a.type === 'link'){
                const l = document.createElement('a');
                l.className = 'material-link-box';
                l.href = a.value;
                l.target = '_blank';
                l.rel = 'noopener';
                l.innerHTML = '<i class="fa-solid fa-link"></i><span></span>';
                l.querySelector('span').textContent = a.value;
                links.appendChild(l);
            } else {
                // Thumbnail uses the raw upload; clicking opens the viewer link
                const rawUrl  = 'uploads/' + encodeURIComponent(a.value);
                const openUrl = a.url || rawUrl;
                const target  = a.target || '_blank';
                const isImg   = IMG_EXT.includes(extOf(a.value));

                const item = document.createElement('div');
                item.className = 'material-file-item';

                const prev = document.createElement('a');
                prev.href = openUrl;
                prev.target = target;
                prev.rel = 'noopener';
                prev.className = 'material-file-preview' + (isImg ? ' image-preview' : '');
                if(isImg){
                    const img = document.createElement('img');
                    img.src = rawUrl;
                    img.alt = a.value;
                    prev.appendChild(img);
                } else {
                    prev.innerHTML = '<i class="fa-solid ' + iconFor(a.value) + '"></i>';
                }

                const name = document.createElement('a');
                name.href = openUrl;
                name.target = target;
                name.rel = 'noopener';
                name.className = 'material-file-name';
                name.textContent = a.value;

                item.appendChild(prev);
                item.appendChild(name);
                files.appendChild(item);
            }
        });

        modal.show();
    }

    document.querySelectorAll('.material-open').forEach(function(el){
        function go(){
            try {
                openMaterial(JSON.parse(el.dataset.material));
            } catch(err){
                console.error('Could not open material:', err);
            }
        }
        el.addEventListener('click', go);
        el.addEventListener('keydown', function(e){
            if(e.key === 'Enter' || e.key === ' '){ e.preventDefault(); go(); }
        });
    });

    // Bookmark toggle (visual only for now)
    document.getElementById('mdBookmark').addEventListener('click', function(e){
        e.preventDefault();
        const i = this.querySelector('i');
        i.classList.toggle('fa-regular');
        i.classList.toggle('fa-solid');
    });
})();
</script>
</body>
</html>