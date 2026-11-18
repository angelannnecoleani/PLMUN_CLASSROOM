
<?php
session_start();
require_once 'database.php';

/* =========================================
   ADMIN ACCESS CONTROL
========================================= */

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.html");
    exit();
}

$admin_id = (int) $_SESSION['user_id'];

/* =========================================
   HELPER FUNCTIONS
========================================= */

function e($value) {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function getSetting($conn, $key, $default = '') {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT setting_value
         FROM system_settings
         WHERE setting_key = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return $default;
    }

    mysqli_stmt_bind_param($stmt, "s", $key);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    return $row ? $row['setting_value'] : $default;
}

/* =========================================
   DEFAULT SETTINGS
========================================= */

$defaults = [
    'system_name' => 'PLMUN Classroom',
    'institution_name' => 'Pamantasan ng Lungsod ng Muntinlupa',
    'system_description' => 'A Web-Based Learning Enhancement and Classroom Management System for Digital Learning Environments.',
    'contact_email' => '',

    'academic_year' => '2026-2027',
    'semester' => 'First Semester',

    'allow_registration' => '1',
    'maintenance_mode' => '0',

    'notify_announcements' => '1',
    'notify_assessments' => '1',
    'notify_messages' => '1'
];

/* =========================================
   CSRF PROTECTION
========================================= */

if (empty($_SESSION['settings_csrf'])) {
    $_SESSION['settings_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['settings_csrf'];

$message = '';
$messageType = 'success';

/* =========================================
   SAVE / RESET SETTINGS
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, $submittedToken)) {

        $message = "Invalid request. Please refresh the page.";
        $messageType = 'danger';

    } else {

        $action = $_POST['action'] ?? '';

        if ($action === 'save') {

            $settings = [
                'system_name' => trim($_POST['system_name'] ?? ''),
                'institution_name' => trim($_POST['institution_name'] ?? ''),
                'system_description' => trim($_POST['system_description'] ?? ''),
                'contact_email' => trim($_POST['contact_email'] ?? ''),

                'academic_year' => trim($_POST['academic_year'] ?? ''),
                'semester' => trim($_POST['semester'] ?? ''),

                'allow_registration' => isset($_POST['allow_registration']) ? '1' : '0',
                'maintenance_mode' => isset($_POST['maintenance_mode']) ? '1' : '0',

                'notify_announcements' => isset($_POST['notify_announcements']) ? '1' : '0',
                'notify_assessments' => isset($_POST['notify_assessments']) ? '1' : '0',
                'notify_messages' => isset($_POST['notify_messages']) ? '1' : '0'
            ];

            /* VALIDATION */

            if (
                $settings['system_name'] === '' ||
                $settings['institution_name'] === '' ||
                $settings['academic_year'] === ''
            ) {

                $message = "Please complete all required fields.";
                $messageType = 'danger';

            } elseif (
                $settings['contact_email'] !== '' &&
                !filter_var($settings['contact_email'], FILTER_VALIDATE_EMAIL)
            ) {

                $message = "Please enter a valid contact email.";
                $messageType = 'danger';

            } elseif (!in_array(
                $settings['semester'],
                ['First Semester', 'Second Semester', 'Summer'],
                true
            )) {

                $message = "Invalid semester selection.";
                $messageType = 'danger';

            } else {

                mysqli_begin_transaction($conn);

                try {

                    foreach ($settings as $key => $value) {

                        $stmt = mysqli_prepare(
                            $conn,
                            "INSERT INTO system_settings
                                (setting_key, setting_value)
                             VALUES (?, ?)
                             ON DUPLICATE KEY UPDATE
                                setting_value = VALUES(setting_value)"
                        );

                        if (!$stmt) {
                            throw new Exception("Unable to prepare settings query.");
                        }

                        mysqli_stmt_bind_param(
                            $stmt,
                            "ss",
                            $key,
                            $value
                        );

                        if (!mysqli_stmt_execute($stmt)) {
                            throw new Exception("Unable to save settings.");
                        }

                        mysqli_stmt_close($stmt);
                    }

                    mysqli_commit($conn);

                    $message = "System settings have been saved successfully.";

                } catch (Throwable $ex) {

                    mysqli_rollback($conn);

                    error_log("Settings save error: " . $ex->getMessage());

                    $message = "Unable to save settings. Please try again.";
                    $messageType = 'danger';
                }
            }

        } elseif ($action === 'reset') {

            mysqli_begin_transaction($conn);

            try {

                foreach ($defaults as $key => $value) {

                    $stmt = mysqli_prepare(
                        $conn,
                        "INSERT INTO system_settings
                            (setting_key, setting_value)
                         VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE
                            setting_value = VALUES(setting_value)"
                    );

                    if (!$stmt) {
                        throw new Exception("Unable to prepare reset query.");
                    }

                    mysqli_stmt_bind_param(
                        $stmt,
                        "ss",
                        $key,
                        $value
                    );

                    if (!mysqli_stmt_execute($stmt)) {
                        throw new Exception("Unable to reset settings.");
                    }

                    mysqli_stmt_close($stmt);
                }

                mysqli_commit($conn);

                $message = "Settings have been restored to their default values.";

            } catch (Throwable $ex) {

                mysqli_rollback($conn);

                error_log("Settings reset error: " . $ex->getMessage());

                $message = "Unable to reset settings.";
                $messageType = 'danger';
            }
        }
    }
}

/* =========================================
   GET CURRENT SETTINGS
========================================= */

$settings = [];

foreach ($defaults as $key => $default) {
    $settings[$key] = getSetting($conn, $key, $default);
}

/* =========================================
   GET ADMIN INFORMATION
========================================= */

$stmt = mysqli_prepare(
    $conn,
    "SELECT * FROM users WHERE id = ? LIMIT 1"
);

if (!$stmt) {
    die("Unable to retrieve administrator information.");
}

mysqli_stmt_bind_param($stmt, "i", $admin_id);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$admin = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);

if (!$admin) {
    session_destroy();
    header("Location: login.html");
    exit();
}

/* =========================================
   PROFILE IMAGE
========================================= */

$profileImage = $admin['profile_image'] ?? '';

$hasProfileImage = (
    !empty($profileImage) &&
    $profileImage !== 'default.png' &&
    file_exists('uploads/' . $profileImage)
);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>System Settings | PLMUN Classroom</title>

    <link rel="shortcut icon"
          href="img/plmun logo.png"
          type="image/x-icon">

    <link rel="stylesheet"
          href="admin_dashboard.css">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>

/* =========================================
   SETTINGS PAGE
========================================= */

.settings-page-header {
    margin-bottom: 25px;
}

.settings-page-header h2 {
    font-size: 25px;
    font-weight: 700;
    color: #253047;
    margin-bottom: 5px;
}

.settings-page-header p {
    color: #7b8494;
    font-size: 14px;
    margin: 0;
}

.settings-container {
    max-width: 1050px;
}

.settings-card {
    background: #fff;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 22px;
    border: 1px solid #e8ebf0;
    box-shadow: 0 2px 8px rgba(0,0,0,.035);
}

.settings-card-header {
    display: flex;
    align-items: center;
    gap: 13px;
    padding-bottom: 18px;
    margin-bottom: 20px;
    border-bottom: 1px solid #edf0f4;
}

.settings-icon {
    width: 43px;
    height: 43px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #eaf2ff;
    color: #3478c8;
    font-size: 18px;
}

.settings-card-header h5 {
    font-size: 16px;
    font-weight: 700;
    color: #29364b;
    margin: 0;
}

.settings-card-header p {
    font-size: 12px;
    color: #8791a1;
    margin: 4px 0 0;
}

.settings-label {
    font-size: 13px;
    font-weight: 600;
    color: #465269;
    margin-bottom: 8px;
}

.settings-input {
    font-size: 13px !important;
    padding: 11px 13px !important;
    border: 1px solid #dfe4eb !important;
    border-radius: 7px !important;
}

.settings-input:focus {
    border-color: #4b83cb !important;
    box-shadow: 0 0 0 3px rgba(75,131,203,.10) !important;
}

.settings-help {
    font-size: 11px;
    color: #929baa;
    margin-top: 6px;
}

.settings-option {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    padding: 15px 0;
    border-bottom: 1px solid #f0f1f4;
}

.settings-option:last-child {
    border-bottom: none;
}

.settings-option h6 {
    font-size: 13px;
    font-weight: 600;
    color: #3b465a;
    margin-bottom: 4px;
}

.settings-option p {
    font-size: 11px;
    color: #8b94a2;
    margin: 0;
}

.form-check-input {
    width: 39px !important;
    height: 20px !important;
    cursor: pointer;
}

.form-check-input:checked {
    background-color: #3478c8 !important;
    border-color: #3478c8 !important;
}

.settings-actions {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    padding-bottom: 30px;
}

.settings-save {
    background: #3478c8;
    border: none;
    color: #fff;
    padding: 10px 22px;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 600;
}

.settings-save:hover {
    background: #2865ad;
    color: white;
}

.settings-reset {
    background: #fff;
    border: 1px solid #dfe4eb;
    color: #657084;
    padding: 10px 20px;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 600;
}

.settings-reset:hover {
    background: #f4f6f9;
}

.settings-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 600;
    padding: 6px 10px;
    border-radius: 20px;
    background: #e9f8ef;
    color: #23834d;
}

@media(max-width: 768px) {

    .settings-card {
        padding: 18px;
    }

    .settings-page-header h2 {
        font-size: 21px;
    }

    .settings-actions {
        justify-content: stretch;
    }

    .settings-actions button {
        flex: 1;
    }

}

    </style>

</head>

<body>

<!-- =========================================
     SIDEBAR
========================================= -->

<div class="admin-sidebar" id="adminSidebar">

    <div class="admin-sidebar-header">
        <h1>ADMIN</h1>
    </div>

    <ul class="admin-menu">

        <li>
            <a href="admin_dashboard.php">
                <i class="fa-solid fa-house"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <li>
            <a href="admin_users.php">
                <i class="fa-solid fa-user-group"></i>
                <span>User Management</span>
            </a>
        </li>

        <li>
            <a href="admin_settings.php" class="active">
                <i class="fa-solid fa-gear"></i>
                <span>System Settings</span>
            </a>
        </li>

        <li>
            <a href="admin_monitor.php">
                <i class="fa-solid fa-desktop"></i>
                <span>System Monitor</span>
            </a>
        </li>

        <li>
            <a href="admin_reports.php">
                <i class="fa-solid fa-wave-square"></i>
                <span>Reports &amp; Analytics</span>
            </a>
        </li>

        <li>
            <a href="admin_logs.php">
                <i class="fa-regular fa-clock"></i>
                <span>Logs &amp; Activity</span>
            </a>
        </li>

        <li>
            <a href="admin_security.php">
                <i class="fa-solid fa-shield-halved"></i>
                <span>Backup &amp; Security</span>
            </a>
        </li>

    </ul>

    <div class="admin-logout">
        <a href="logout.php">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Log out</span>
        </a>
    </div>

</div>

<!-- MOBILE OVERLAY -->

<div class="admin-overlay"
     id="adminOverlay"
     onclick="closeAdminSidebar()"></div>


<!-- =========================================
     MAIN CONTENT
========================================= -->

<div class="admin-main" id="adminMain">

    <!-- TOPBAR -->

    <header class="admin-topbar">

        <div class="admin-top-left">

            <button class="admin-mobile-btn"
                    type="button"
                    onclick="toggleAdminSidebar()">

                <i class="fa-solid fa-bars"></i>

            </button>

            <img src="img/classroom logo.png"
                 alt="PLMUN Classroom"
                 class="admin-logo">

        </div>

        <div class="admin-top-right">

            <div class="admin-user-name">
                <span><?php echo e($admin['name'] ?? 'Administrator'); ?></span>
            </div>

            <a href="profile.php" class="admin-profile">

                <?php if ($hasProfileImage): ?>

                    <img src="uploads/<?php echo e($profileImage); ?>"
                         alt="Profile">

                <?php else: ?>

                    <i class="fa-solid fa-user"></i>

                <?php endif; ?>

            </a>

        </div>

    </header>


    <!-- =====================================
         SETTINGS CONTENT
    ====================================== -->

    <main class="admin-content">

        <div class="settings-page-header">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">

                <div>
                    <h2>System Settings</h2>
                    <p>Manage and configure your PLMUN Classroom system.</p>
                </div>

                <span class="settings-status">
                    <i class="fa-solid fa-circle-check"></i>
                    Administrator Access
                </span>

            </div>

        </div>


        <?php if ($message !== ''): ?>

            <div class="alert alert-<?php echo e($messageType); ?> alert-dismissible fade show"
                 role="alert">

                <?php echo e($message); ?>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"></button>

            </div>

        <?php endif; ?>


        <form method="POST" action="admin_settings.php">

            <input type="hidden"
                   name="csrf_token"
                   value="<?php echo e($csrfToken); ?>">

            <input type="hidden"
                   name="action"
                   value="save">


            <div class="settings-container">

                <!-- =================================
                     GENERAL SETTINGS
                ================================== -->

                <section class="settings-card">

                    <div class="settings-card-header">

                        <div class="settings-icon">
                            <i class="fa-solid fa-sliders"></i>
                        </div>

                        <div>
                            <h5>General Settings</h5>
                            <p>Configure the basic information of your platform.</p>
                        </div>

                    </div>


                    <div class="row g-3">

                        <div class="col-md-6">

                            <label class="settings-label">
                                System Name *
                            </label>

                            <input type="text"
                                   class="form-control settings-input"
                                   name="system_name"
                                   value="<?php echo e($settings['system_name']); ?>"
                                   maxlength="150"
                                   required>

                        </div>


                        <div class="col-md-6">

                            <label class="settings-label">
                                Institution Name *
                            </label>

                            <input type="text"
                                   class="form-control settings-input"
                                   name="institution_name"
                                   value="<?php echo e($settings['institution_name']); ?>"
                                   maxlength="200"
                                   required>

                        </div>


                        <div class="col-12">

                            <label class="settings-label">
                                System Description
                            </label>

                            <textarea class="form-control settings-input"
                                      name="system_description"
                                      rows="3"
                                      maxlength="1000"><?php echo e($settings['system_description']); ?></textarea>

                        </div>


                        <div class="col-md-6">

                            <label class="settings-label">
                                Contact Email
                            </label>

                            <input type="email"
                                   class="form-control settings-input"
                                   name="contact_email"
                                   value="<?php echo e($settings['contact_email']); ?>"
                                   maxlength="254">

                            <div class="settings-help">
                                Official contact email for the platform.
                            </div>

                        </div>

                    </div>

                </section>


                <!-- =================================
                     ACADEMIC SETTINGS
                ================================== -->

                <section class="settings-card">

                    <div class="settings-card-header">

                        <div class="settings-icon">
                            <i class="fa-solid fa-graduation-cap"></i>
                        </div>

                        <div>
                            <h5>Academic Settings</h5>
                            <p>Configure the current academic period.</p>
                        </div>

                    </div>


                    <div class="row g-3">

                        <div class="col-md-6">

                            <label class="settings-label">
                                Academic Year *
                            </label>

                            <input type="text"
                                   class="form-control settings-input"
                                   name="academic_year"
                                   value="<?php echo e($settings['academic_year']); ?>"
                                   placeholder="2026-2027"
                                   maxlength="20"
                                   required>

                        </div>


                        <div class="col-md-6">

                            <label class="settings-label">
                                Current Semester
                            </label>

                            <select class="form-select settings-input"
                                    name="semester">

                                <option value="First Semester"
                                    <?php echo $settings['semester'] === 'First Semester' ? 'selected' : ''; ?>>
                                    First Semester
                                </option>

                                <option value="Second Semester"
                                    <?php echo $settings['semester'] === 'Second Semester' ? 'selected' : ''; ?>>
                                    Second Semester
                                </option>

                                <option value="Summer"
                                    <?php echo $settings['semester'] === 'Summer' ? 'selected' : ''; ?>>
                                    Summer
                                </option>

                            </select>

                        </div>

                    </div>

                </section>


                <!-- =================================
                     USER SETTINGS
                ================================== -->

                <section class="settings-card">

                    <div class="settings-card-header">

                        <div class="settings-icon">
                            <i class="fa-solid fa-users-gear"></i>
                        </div>

                        <div>
                            <h5>User &amp; Access Settings</h5>
                            <p>Manage user registration and system availability.</p>
                        </div>

                    </div>


                    <div class="settings-option">

                        <div>
                            <h6>Allow User Registration</h6>
                            <p>Allow new users to register accounts in the system.</p>
                        </div>

                        <div class="form-check form-switch">

                            <input class="form-check-input"
                                   type="checkbox"
                                   name="allow_registration"
                                   value="1"
                                   <?php echo $settings['allow_registration'] === '1' ? 'checked' : ''; ?>>

                        </div>

                    </div>


                    <div class="settings-option">

                        <div>
                            <h6>Maintenance Mode</h6>
                            <p>Save the maintenance status for the platform.</p>
                        </div>

                        <div class="form-check form-switch">

                            <input class="form-check-input"
                                   type="checkbox"
                                   name="maintenance_mode"
                                   value="1"
                                   <?php echo $settings['maintenance_mode'] === '1' ? 'checked' : ''; ?>>

                        </div>

                    </div>

                </section>


                <!-- =================================
                     NOTIFICATION SETTINGS
                ================================== -->

                <section class="settings-card">

                    <div class="settings-card-header">

                        <div class="settings-icon">
                            <i class="fa-solid fa-bell"></i>
                        </div>

                        <div>
                            <h5>Notification Settings</h5>
                            <p>Manage which system notification categories are enabled.</p>
                        </div>

                    </div>


                    <div class="settings-option">

                        <div>
                            <h6>Announcement Notifications</h6>
                            <p>Notifications for new class announcements.</p>
                        </div>

                        <div class="form-check form-switch">

                            <input class="form-check-input"
                                   type="checkbox"
                                   name="notify_announcements"
                                   value="1"
                                   <?php echo $settings['notify_announcements'] === '1' ? 'checked' : ''; ?>>

                        </div>

                    </div>


                    <div class="settings-option">

                        <div>
                            <h6>Assessment Notifications</h6>
                            <p>Notifications for new assessments and activities.</p>
                        </div>

                        <div class="form-check form-switch">

                            <input class="form-check-input"
                                   type="checkbox"
                                   name="notify_assessments"
                                   value="1"
                                   <?php echo $settings['notify_assessments'] === '1' ? 'checked' : ''; ?>>

                        </div>

                    </div>


                    <div class="settings-option">

                        <div>
                            <h6>Message Notifications</h6>
                            <p>Notifications for new messages and conversations.</p>
                        </div>

                        <div class="form-check form-switch">

                            <input class="form-check-input"
                                   type="checkbox"
                                   name="notify_messages"
                                   value="1"
                                   <?php echo $settings['notify_messages'] === '1' ? 'checked' : ''; ?>>

                        </div>

                    </div>

                </section>


                <!-- =================================
                     SAVE ACTIONS
                ================================== -->

                <div class="settings-actions">

                    <button type="button"
                            class="settings-reset"
                            onclick="confirmReset()">

                        <i class="fa-solid fa-rotate-left me-1"></i>
                        Reset to Default

                    </button>


                    <button type="submit"
                            class="settings-save">

                        <i class="fa-solid fa-floppy-disk me-1"></i>
                        Save Changes

                    </button>

                </div>

            </div>

        </form>


        <!-- SEPARATE RESET FORM -->

        <form method="POST"
              action="admin_settings.php"
              id="resetForm">

            <input type="hidden"
                   name="csrf_token"
                   value="<?php echo e($csrfToken); ?>">

            <input type="hidden"
                   name="action"
                   value="reset">

        </form>

    </main>

</div>


<!-- =========================================
     JAVASCRIPT
========================================= -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

function toggleAdminSidebar() {

    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.getElementById('adminOverlay');

    sidebar.classList.toggle('show');
    overlay.classList.toggle('show');

}

function closeAdminSidebar() {

    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.getElementById('adminOverlay');

    sidebar.classList.remove('show');
    overlay.classList.remove('show');

}

window.addEventListener('resize', function() {

    if (window.innerWidth > 768) {
        closeAdminSidebar();
    }

});

function confirmReset() {

    const confirmed = confirm(
        "Are you sure you want to restore all system settings to their default values?"
    );

    if (confirmed) {
        document.getElementById('resetForm').submit();
    }

}

</script>

</body>
</html>
