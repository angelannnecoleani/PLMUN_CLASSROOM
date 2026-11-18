<?php
session_start();
include 'database.php';

/* =========================================
   LOGIN VALIDATION
========================================= */

if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$student_id = (int) $_SESSION['user_id'];

/* =========================================
   FETCH CURRENT STUDENT
========================================= */

$stmt = mysqli_prepare($conn, "
    SELECT id, name, profile_image
    FROM users
    WHERE id = ?
    LIMIT 1
");

mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);

$userResult = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($userResult);

mysqli_stmt_close($stmt);

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

/* =========================================
   PROFILE IMAGE VALIDATION
========================================= */

$profileImageFile = '';
$hasProfileImage = false;

$rawProfileImage = trim((string)($user['profile_image'] ?? ''));

$defaultImages = [
    '',
    'default.png',
    'default.jpg',
    'default.jpeg',
    'default.svg',
    'null'
];

if ($rawProfileImage !== '') {

    // Get filename only
    $safeName = basename($rawProfileImage);

    // Ignore default profile images
    if (!in_array(strtolower($safeName), $defaultImages, true)) {

        $imagePath = __DIR__ . '/uploads/' . $safeName;

        // Verify image exists and is readable
        if (is_file($imagePath) && is_readable($imagePath)) {
            $profileImageFile = $safeName;
            $hasProfileImage = true;
        }
    }
}

/* =========================================
   FETCH SAVED RESOURCES
========================================= */

$stmt = mysqli_prepare($conn, "
    SELECT
        m.id,
        m.title,
        m.file,
        m.created_at,
        c.class_name AS subject
    FROM student_resources sr
    INNER JOIN materials m ON m.id = sr.material_id
    INNER JOIN classes c ON c.id = m.class_id
    WHERE sr.student_id = ?
    ORDER BY sr.saved_at DESC
");

mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);

$resources = mysqli_stmt_get_result($stmt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resources</title>
    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="student_class.css">
    <link rel="stylesheet" href="student_resources.css">
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <button class="toggle-btn" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
    </div>
    <ul>
        <li><a href="student_dashboard.php"><i class="fa-solid fa-house"></i><span>Home</span></a></li>
        <li><a href="student_class_page.php"><i class="fa-solid fa-users"></i><span>Classes</span></a></li>
        <li><a href="student_archived_classes.php"><i class="fa-solid fa-box-archive"></i><span>Archived</span></a></li>
        <li><a href="student_assignments.php"><i class="fa-solid fa-clipboard-list"></i><span>Assignment</span></a></li>
        <li><a href="student_messages.php"><i class="fa-regular fa-message"></i><span>Messages</span></a></li>
        <li><a href="student_performance.php"><i class="fa-solid fa-chart-pie"></i><span>Performance</span></a></li>
        <li><a href="student_resources.php" class="active"><i class="fa-solid fa-book-open"></i><span>Resources</span></a></li>
    </ul>
    <div class="logout-btn">
        <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a>
    </div>
</div>

<!-- MOBILE OVERLAY -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="main-content" id="mainContent">

    <div class="resources-header">
        <div class="logo">
            <button class="mobile-menu-btn" onclick="toggleSidebar()" aria-label="Open menu">
                <i class="fa-solid fa-bars"></i>
            </button>
            <img src="img/classroom logo.png" alt="Logo" class="navbar-logo">
        </div>
        <a href="profile.php" class="profile-icon">

            <?php if ($hasProfileImage): ?>

                <img
                    src="uploads/<?php echo rawurlencode($profileImageFile); ?>"
                    alt="Profile Picture"
                    class="profile-image"
                    onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                >

                <span class="profile-fallback" style="display:none;">
                    <i class="fa-solid fa-user"></i>
                </span>

            <?php else: ?>

                <span class="profile-fallback">
                    <i class="fa-solid fa-user"></i>
                </span>

            <?php endif; ?>

        </a>
    </div>

    <div class="search-box-wrap">
        <input type="text" id="searchInput" placeholder="Search resources..">
        <i class="fa-solid fa-magnifying-glass"></i>
    </div>

    <?php if(mysqli_num_rows($resources) === 0): ?>
        <div class="text-center text-muted py-5 px-3">
            <i class="fa-solid fa-book-open fa-2x mb-3 d-block"></i>
            <p>You haven't saved any resources yet. Save materials from your classes to see them here.</p>
        </div>
    <?php else: ?>

    <div class="resource-grid" id="resourceGrid">
        <?php while($r = mysqli_fetch_assoc($resources)):
            $ext = strtolower(pathinfo($r['file'] ?? '', PATHINFO_EXTENSION));
            $icon = 'fa-file';
            if(in_array($ext, ['jpg','jpeg','png','gif','webp'])) $icon = 'fa-file-image';
            elseif($ext === 'pdf') $icon = 'fa-file-pdf';
            elseif(in_array($ext, ['doc','docx'])) $icon = 'fa-file-word';
            elseif(in_array($ext, ['ppt','pptx'])) $icon = 'fa-file-powerpoint';
            elseif(in_array($ext, ['xls','xlsx'])) $icon = 'fa-file-excel';
        ?>
        <div class="resource-card" data-title="<?php echo strtolower(htmlspecialchars($r['title'])); ?>">
            <div class="resource-thumb"><i class="fa-solid <?php echo $icon; ?>"></i></div>
            <div class="resource-info">
                <div class="resource-title"><?php echo htmlspecialchars($r['title']); ?></div>
                <div class="resource-meta"><?php echo htmlspecialchars($r['subject']); ?></div>
                <div class="resource-meta">Saved <?php echo date("M d, Y", strtotime($r['created_at'])); ?></div>
            </div>
            <div class="resource-actions">
                <a href="download_resource.php?id=<?php echo $r['id']; ?>" class="btn-download">
                    <i class="fa-solid fa-download me-1"></i>Download
                </a>
                <button class="btn-remove" onclick="removeResource(<?php echo $r['id']; ?>, this)">
                    <i class="fa-solid fa-xmark me-1"></i>Remove
                </button>
            </div>
        </div>
        <?php endwhile; ?>
    </div>

    <?php endif; ?>
</div>

<script>
function toggleSidebar(){
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (window.innerWidth <= 768) {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('show');
    } else {
        sidebar.classList.toggle('collapsed');
        document.getElementById('mainContent').classList.toggle('expanded');
    }
}

// Reset the mobile drawer if the window is resized to desktop
window.addEventListener('resize', function(){
    if (window.innerWidth > 768) {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('show');
    }
});

document.getElementById('searchInput')?.addEventListener('input', function(){
    const q = this.value.toLowerCase();
    document.querySelectorAll('.resource-card').forEach(card => {
        card.style.display = card.dataset.title.includes(q) ? '' : 'none';
    });
});

async function removeResource(materialId, btn){
    if(!confirm('Remove this from your saved resources?')) return;
    try{
        const res = await fetch('save_resource.php', {
            method: 'POST',
            body: new URLSearchParams({ material_id: materialId, action: 'unsave' })
        });
        const data = await res.json();
        if(data.success){
            btn.closest('.resource-card').remove();
        } else {
            alert(data.error || 'Failed to remove');
        }
    } catch(e){
        alert('Failed to remove');
    }
}
</script>
</body>
</html>