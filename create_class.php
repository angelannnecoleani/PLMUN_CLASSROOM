<?php
include 'database.php';
session_start();

$teacher_id = intval($_SESSION['user_id']);

$userQuery = mysqli_query($conn, "SELECT * FROM users WHERE id='$teacher_id'");
$user = mysqli_fetch_assoc($userQuery);
?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Create Class</title>

<link rel="stylesheet" href="create_class.css">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

</head>
<body>

<!-- SIDEBAR -->

<div class="sidebar" id="sidebar">

    <div class="sidebar-header">

        <button class="toggle-btn" onclick="toggleSidebar()">
            <i class="fa-solid fa-bars"></i>
        </button>

    </div>

    <ul class="menu">

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

    <!-- FORM -->

    <div class="form-wrapper">

        <div class="create-class-card">

            <div class="card-header-custom">

                <h2>Create Class</h2>

            </div>

            <form action="save_class.php"
                  method="POST">

                <div class="form-body">

                    <input type="text"
                           name="class_name"
                           class="form-control custom-input"
                           placeholder="Class Name *"
                           required>

                    <input type="text"
                           name="section"
                           class="form-control custom-input"
                           placeholder="Section *"
                           required>

                    <input type="text"
                           name="schedule"
                           class="form-control custom-input"
                           placeholder="Schedule *"
                           required>

                    <input type="text"
                           name="room"
                           class="form-control custom-input"
                           placeholder="Room">

                </div>

                <div class="card-footer-custom">

                    <a href="teacher_dashboard.php"
                       class="btn btn-outline-dark btn-lg">

                        Cancel

                    </a>

                    <button type="submit"
                            class="btn btn-primary btn-lg">

                        Create Class

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<script>

function toggleSidebar(){

    if(window.innerWidth > 768){

        document.getElementById('sidebar')
        .classList.toggle('collapsed');

        document.getElementById('mainContent')
        .classList.toggle('expanded');
    }
}

function mobileSidebar(){

    document.getElementById('sidebar')
    .classList.toggle('show');
}

</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>