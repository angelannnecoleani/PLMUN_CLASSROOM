<?php
session_start();
include 'database.php';

// Must be logged in
if(empty($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}
$teacher_id = intval($_SESSION['user_id']);

$userQuery = mysqli_query($conn, "SELECT * FROM users WHERE id='$teacher_id'");
$user = mysqli_fetch_assoc($userQuery);

// Optional search term (filters by subject/class name or section)
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$searchEsc = mysqli_real_escape_string($conn, $search);

// Fetch every class owned by this teacher, with a count of how many
// materials each one has.
$sql = "
    SELECT classes.*, COUNT(materials.id) AS material_count
    FROM classes
    LEFT JOIN materials ON materials.class_id = classes.id
    WHERE classes.teacher_id = '$teacher_id'
";

if($searchEsc !== ''){
    $sql .= " AND (classes.class_name LIKE '%$searchEsc%' OR classes.section LIKE '%$searchEsc%')";
}

$sql .= " GROUP BY classes.id ORDER BY classes.class_name ASC";

$classesQuery = mysqli_query($conn, $sql);

if($classesQuery === false){
    die("Database error: " . mysqli_error($conn));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resources</title>
    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">
    <link rel="stylesheet" href="teacher_class.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        /* Page-specific additions (kept small — everything else reuses teacher_class.css) */
        .resources-search-wrap {
            max-width: 700px;
            margin: 0 auto 28px;
            position: relative;
        }
        .resources-search-input {
            width: 100%;
            height: 52px;
            border: 1px solid #dde4ec;
            border-radius: 26px;
            padding: 0 80px 0 22px;
            font-size: 15px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .resources-search-input:focus {
            border-color: #3f87b5;
            box-shadow: 0 0 0 3px rgba(63,135,181,.15);
        }
        .resources-search-icon {
            position: absolute;
            right: 6px;
            top: 50%;
            transform: translateY(-50%);
            width: 40px;
            height: 40px;
            border: none;
            background: transparent;
            color: #666;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            z-index: 2;
            transition: background 0.2s, color 0.2s;
        }
        .resources-search-icon:hover {
            background: #eef4f9;
            color: #3f87b5;
        }
        .resources-clear-btn {
            position: absolute;
            right: 48px;
            top: 50%;
            transform: translateY(-50%);
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #999;
            font-size: 13px;
            cursor: pointer;
            z-index: 2;
            transition: background 0.2s, color 0.2s;
        }
        .resources-clear-btn:hover {
            background: #f0f0f0;
            color: #555;
        }
        .resources-list {
            max-width: 700px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #e0e8f0;
            border-radius: 12px;
            overflow: hidden;
        }
        .resource-row {
            display: block;
            padding: 16px 24px;
            border-bottom: 1px solid #f0f0f0;
            text-decoration: none;
            color: inherit;
            transition: background 0.2s;
        }
        .resource-row:last-child { border-bottom: none; }
        .resource-row:hover { background: #f8fafc; }
        .resource-subject {
            font-weight: 700;
            font-size: 14px;
            color: #3f87b5;
            margin-bottom: 3px;
        }
        .resource-title {
            font-size: 15px;
            color: #222;
            font-weight: 500;
        }
        .resource-meta {
            font-size: 12px;
            color: #999;
            margin-top: 4px;
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
        <li><a href="teacher_dashboard.php"><i class="fa-solid fa-house"></i><span>Home</span></a></li>
        <li><a href="teacher_classpage.php"><i class="fa-solid fa-users"></i><span>Classes</span></a></li>
        <li><a href="teacher_to_review.php"><i class="fa-solid fa-clipboard-list"></i><span>To Review</span></a></li>
        <li><a href="teacher_messages.php"><i class="fa-regular fa-message"></i><span>Messages</span></a></li>
        <li><a href="teacher_performance.php"><i class="fa-solid fa-chart-pie"></i><span>Performance</span></a></li>
        <li><a href="teacher_resources.php" class="active"><i class="fa-solid fa-book-open"></i><span>Resources</span></a></li>
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

    <div class="container py-4">

        <!-- SEARCH -->
        <form method="GET" class="resources-search-wrap">
            <input type="text" name="q" class="resources-search-input"
                   placeholder="Search resources.."
                   value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="resources-search-icon" aria-label="Search">
                <i class="fa-solid fa-magnifying-glass"></i>
            </button>
            <?php if($search !== ''): ?>
                <a href="teacher_resources.php" class="resources-clear-btn" aria-label="Clear search">
                    <i class="fa-solid fa-xmark"></i>
                </a>
            <?php endif; ?>
        </form>

        <!-- CLASS LIST -->
        <?php if(!$classesQuery || mysqli_num_rows($classesQuery) === 0): ?>
            <div class="empty-state">
                <i class="fa-solid fa-book-open"></i>
                <p>
                    <?php echo $search !== ''
                        ? 'No classes match "' . htmlspecialchars($search) . '".'
                        : 'No classes yet. Create a class to start adding materials.'; ?>
                </p>
            </div>
        <?php else: ?>
            <div class="resources-list">
                <?php while($class = mysqli_fetch_assoc($classesQuery)): ?>
                    <a class="resource-row"
                       href="teacher_material.php?id=<?php echo intval($class['id']); ?>">
                        <div class="resource-subject">
                            <?php echo htmlspecialchars($class['class_name']); ?>
                        </div>
                        <?php if(!empty($class['section'])): ?>
                            <div class="resource-title">
                                <?php echo htmlspecialchars($class['section']); ?>
                            </div>
                        <?php endif; ?>
                        <div class="resource-meta">
                            <?php echo intval($class['material_count']); ?>
                            material<?php echo intval($class['material_count']) === 1 ? '' : 's'; ?>
                        </div>
                    </a>
                <?php endwhile; ?>
            </div>
        <?php endif; ?>

    </div><!-- end container -->
</div><!-- end main-content -->

<script>
function toggleSidebar(){
    document.getElementById('sidebar').classList.toggle('collapsed');
    document.getElementById('mainContent').classList.toggle('expanded');
}
function mobileSidebar(){
    document.getElementById('sidebar').classList.toggle('show');
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>