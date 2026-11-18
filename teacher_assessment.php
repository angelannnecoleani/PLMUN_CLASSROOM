
<?php
session_start();
include 'database.php';
include 'auth_helpers.php';

$role = $_SESSION['role'] ?? '';

if (empty($_SESSION['user_id']) || !in_array($role, ['teacher', 'program_chair'], true)) {
    header("Location: login.php");
    exit;
}

if (!isset($_GET['id'])) {
    die("Class not found");
}

$class_id   = (int) $_GET['id'];
$teacher_id = (int) $_SESSION['user_id'];
$isChair    = ($role === 'program_chair');

/* Teacher: own class / Program chair: class in their program */
$class = getClassAccess($conn, $class_id, $teacher_id, $role);

if (!$class) {
    http_response_code(403);
    die("Access denied. This class is not assigned to you.");
}

/* Fetch current user */
$uStmt = mysqli_prepare($conn, "SELECT * FROM users WHERE id = ?");
mysqli_stmt_bind_param($uStmt, "i", $teacher_id);
mysqli_stmt_execute($uStmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($uStmt));
mysqli_stmt_close($uStmt);

/* Fetch assessments with counts */
$aStmt = mysqli_prepare($conn, "
    SELECT
        a.*,
        (SELECT COUNT(*) FROM class_students cs
            WHERE cs.class_id = a.class_id) AS assigned_count,
        (SELECT COUNT(*) FROM submissions s
            WHERE s.assessment_id = a.id
            AND s.status IN ('submitted','done')) AS turned_in_count,
        (SELECT COUNT(*) FROM submissions s
            WHERE s.assessment_id = a.id
            AND s.grade IS NOT NULL) AS graded_count
    FROM assessments a
    WHERE a.class_id = ?
    ORDER BY a.type ASC, a.id DESC
");

mysqli_stmt_bind_param($aStmt, "i", $class_id);
mysqli_stmt_execute($aStmt);
$assessmentResult = mysqli_stmt_get_result($aStmt);

/* Group assessments by type */
$grouped = [];

while ($a = mysqli_fetch_assoc($assessmentResult)) {
    $grouped[$a['type']][] = $a;
}

mysqli_stmt_close($aStmt);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Assessments – <?php echo htmlspecialchars($class['class_name']); ?></title>

    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Existing teacher styles -->
    <link rel="stylesheet" href="teacher_class.css">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        /* =====================================================
           GENERAL
        ===================================================== */

        * {
            box-sizing: border-box;
        }

        html,
        body {
            width: 100%;
            max-width: 100%;
            overflow-x: hidden;
        }

        .main-content {
            min-width: 0;
            max-width: 100%;
        }

        .sidebar-close-btn {
            display: none;
            border: none;
            background: transparent;
            color: #333;
            font-size: 23px;
            cursor: pointer;
        }

        /* =====================================================
           CLASS NAVIGATION
        ===================================================== */

        .class-nav {
            width: 100%;
            max-width: 100%;
            display: flex;
            align-items: center;
            overflow-x: auto;
            overflow-y: hidden;
            white-space: nowrap;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }

        .class-nav::-webkit-scrollbar {
            display: none;
        }

        .class-nav a {
            flex-shrink: 0;
            white-space: nowrap;
        }

        /* =====================================================
           ASSESSMENT ACTIONS
        ===================================================== */

        .assessment-actions {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 24px;
        }

        .create-btn {
            flex-shrink: 0;
        }

        .topic-filter {
            height: 52px;
            min-width: 220px;
            border: 1px solid #dde4ec;
            border-radius: 10px;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 18px;
            font-size: 15px;
            font-weight: 500;
            color: #333;
            cursor: pointer;
            user-select: none;
        }

        /* =====================================================
           TOPIC GROUPS
        ===================================================== */

        .topic-block {
            width: 100%;
            min-width: 0;
            overflow: visible;
            border-radius: 10px;
        }

        .topic-header-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            min-width: 0;
        }

        .topic-header-left {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .topic-name {
            min-width: 0;
            overflow-wrap: anywhere;
        }

        .topic-option-btn {
            border: none;
            background: none;
            color: #888;
            font-size: 18px;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 50%;
            transition: background 0.2s;
            line-height: 1;
            flex-shrink: 0;
        }

        .topic-option-btn:hover {
            background: #f0f0f0;
            color: #333;
        }

        /* =====================================================
           TOPIC BODY
        ===================================================== */

        .topic-body {
            display: flex;
            flex-direction: column;
            gap: 12px;
            width: 100%;
            min-width: 0;
            padding: 14px 16px;
            background: #f8fafc;
        }

        .topic-body.collapsed {
            display: none;
        }

        /* =====================================================
           ASSESSMENT CARDS
        ===================================================== */

        .assessment-item {
            width: 100%;
            min-width: 0;
            margin: 0 !important;
            padding: 18px 20px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.04);
        }

        .assessment-top-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            min-width: 0;
        }

        .assessment-title-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            min-width: 0;
        }

        .assessment-icon {
            flex-shrink: 0;
        }

        .assessment-title-link {
            text-decoration: none;
            color: #222;
            font-size: 15px;
            font-weight: 600;
            transition: color 0.2s;
            min-width: 0;
            overflow-wrap: anywhere;
            word-break: break-word;
            line-height: 1.5;
        }

        .assessment-title-link:hover {
            color: #3f87b5;
        }

        .assessment-due-wrap {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            flex-shrink: 0;
            min-width: 0;
        }

        .due-date {
            overflow-wrap: anywhere;
        }

        .option-btn {
            flex-shrink: 0;
        }

        .assessment-stats-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 18px;
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px solid #eee;
        }

        .stat-cell {
            display: flex;
            align-items: center;
            gap: 5px;
            min-width: 0;
        }

        .stat-cell strong,
        .stat-cell span {
            white-space: nowrap;
        }

        .posted-label {
            flex-shrink: 0;
        }

        /* =====================================================
           EMPTY STATE
        ===================================================== */

        .assessment-empty {
            width: 100%;
            text-align: center;
            padding: 60px 20px;
            color: #bbb;
        }

        .assessment-empty i {
            font-size: 48px;
            display: block;
            margin-bottom: 14px;
        }

        .assessment-empty p {
            font-size: 15px;
        }

        /* =====================================================
           ATTACHMENTS
        ===================================================== */

        .attach-list {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            gap: 10px;
            width: 100%;
            min-width: 0;
        }

        .attach-chip {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
            max-width: 100%;
        }

        .attach-chip-name {
            min-width: 0;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .attach-thumb {
            max-width: 100%;
            object-fit: cover;
        }

        .attachment-icons {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .icon-btn {
            background: none;
            border: none;
            padding: 0;
        }

        .instruction-box {
            width: 100%;
            min-width: 0;
        }

        .instruction-box textarea {
            width: 100%;
            max-width: 100%;
            min-width: 0;
        }

        .instruction-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .send-icon {
            border: none;
            background: none;
            font-size: 22px;
            color: #666;
            cursor: pointer;
            transition: color 0.2s;
        }

        .send-icon:hover {
            color: #3f87b5;
        }

        /* =====================================================
           LINK ATTACHMENT
        ===================================================== */

        .link-add-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
        }

        .link-add-row[hidden] {
            display: none;
        }

        .link-add-row i {
            color: #3f87b5;
            flex-shrink: 0;
        }

        .link-add-row input {
            flex: 1;
            min-width: 0;
            height: 40px;
            padding: 0 12px;
            border: 1px solid #dde4ec;
            border-radius: 6px;
            font-size: 14px;
            outline: none;
        }

        .link-add-row input:focus {
            border-color: #3f87b5;
            box-shadow: 0 0 0 3px rgba(63, 135, 181, .15);
        }

        .link-add-btn {
            height: 40px;
            padding: 0 16px;
            border: none;
            border-radius: 6px;
            background: #3f87b5;
            color: #fff;
            font-weight: 600;
            font-size: 14px;
            flex-shrink: 0;
            transition: background 0.2s;
        }

        .link-add-btn:hover {
            background: #2f76a7;
        }

        /* =====================================================
           ASSESSMENT MODAL - GENERAL
        ===================================================== */

        .assessment-modal {
            width: 100%;
            max-width: 100%;
            min-width: 0;
            border: none;
            overflow: hidden;
            border-radius: 14px;
        }

        /* Form must be flex so modal body can scroll */
        .assessment-modal form {
            display: flex;
            flex-direction: column;
            flex: 1 1 auto;
            min-height: 0;
            overflow: hidden;
        }

        .assessment-modal .modal-header {
            flex-shrink: 0;
            padding: 20px 24px 10px;
        }

        .assessment-modal .modal-title {
            font-size: 21px;
            font-weight: 700;
        }

        .assessment-modal .modal-body {
            flex: 1 1 auto;
            min-height: 0;
            min-width: 0;
            padding: 18px 24px;
            overflow-y: auto;
            overflow-x: hidden;
            overscroll-behavior: contain;
        }

        .assessment-modal .modal-body > *,
        .assessment-modal .modal-body .row > div {
            min-width: 0;
        }

        .assessment-modal .modal-footer {
            flex-shrink: 0;
            display: flex;
            justify-content: flex-end;
            flex-wrap: wrap;
            gap: 10px;
            padding: 14px 24px 20px;
        }

        .assessment-modal input,
        .assessment-modal select,
        .assessment-modal textarea {
            max-width: 100%;
        }

        .assessment-modal .form-control,
        .assessment-modal .form-select {
            min-width: 0;
            border-radius: 8px;
        }

        .assessment-modal .d-flex.gap-2 > input {
            min-width: 0;
            flex: 1 1 0;
        }

        /* Modal buttons */
        .cancel-btn,
        .save-btn {
            height: 52px;
            min-width: 130px;
            border-radius: 30px;
            font-size: 16px;
            font-weight: 600;
            flex-shrink: 0;
        }

        .cancel-btn {
            background: #fff;
        }

        .save-btn {
            background: #3f87b5;
            border: none;
            color: #fff;
            transition: background 0.2s;
        }

        .save-btn:hover {
            background: #2f76a7;
            color: #fff;
        }

        /* =====================================================
           TABLET - 768px
        ===================================================== */

        @media (max-width: 768px) {

            /* Sidebar */
            .sidebar {
                position: fixed !important;
                top: 0;
                left: 0;
                bottom: 0;
                width: 260px !important;
                max-width: 85vw;
                height: 100dvh;
                z-index: 1001;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
                overflow-y: auto;
                overflow-x: hidden;
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .sidebar.collapsed {
                width: 260px !important;
            }

            .sidebar.collapsed span {
                display: inline !important;
            }

            .sidebar.collapsed ul li a,
            .sidebar.collapsed .logout-btn a {
                justify-content: flex-start;
                padding-left: 20px;
                padding-right: 20px;
            }

            .sidebar-header {
                display: flex;
                align-items: center;
                justify-content: flex-end;
                padding: 12px 15px;
            }

            .toggle-btn {
                display: none !important;
            }

            .sidebar-close-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 38px;
                height: 38px;
                border-radius: 6px;
            }

            .sidebar-close-btn:hover {
                background: #f1f1f1;
            }

            /* Sidebar overlay */
            .sidebar-overlay {
                position: fixed;
                inset: 0;
                z-index: 1000;
                display: block;
                background: rgba(0, 0, 0, 0.4);
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.3s ease, visibility 0.3s ease;
            }

            .sidebar-overlay.show {
                opacity: 1;
                visibility: visible;
            }

            body.no-scroll {
                overflow: hidden;
            }

            /* Main content */
            .main-content,
            .main-content.expanded {
                width: 100% !important;
                max-width: 100%;
                margin-left: 0 !important;
            }

            /* Topbar */
            .topbar {
                width: 100%;
                max-width: 100%;
                padding: 0 15px;
            }

            .navbar-left {
                display: flex;
                align-items: center;
                gap: 12px;
                min-width: 0;
            }

            .navbar-logo {
                max-width: 165px;
                height: auto;
                object-fit: contain;
            }

            .mobile-menu {
                display: inline-flex !important;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }

            .navbar-right {
                flex-shrink: 0;
            }

            /* Class navigation */
            .class-nav {
                padding-left: 12px;
                padding-right: 12px;
            }

            /* Main content spacing */
            .container.py-4 {
                padding: 20px 16px !important;
            }

            /* Assessment cards */
            .assessment-item {
                padding: 16px;
            }

            .assessment-top-row {
                align-items: flex-start;
                flex-direction: column;
                gap: 10px;
            }

            .assessment-title-wrap {
                width: 100%;
            }

            .assessment-due-wrap {
                justify-content: flex-start;
                width: 100%;
                padding-left: 30px;
            }

            .assessment-stats-row {
                gap: 12px 18px;
            }

            /* Modal tablet sizing */
            .assessment-modal .modal-header {
                padding: 17px 20px 10px;
            }

            .assessment-modal .modal-body {
                padding: 18px 20px;
            }

            .assessment-modal .modal-footer {
                padding: 13px 20px 17px;
            }

            .assessment-modal .modal-title {
                font-size: 20px;
            }

            .cancel-btn,
            .save-btn {
                height: 46px;
                font-size: 15px;
            }
        }

        /* =====================================================
           MOBILE - 576px
        ===================================================== */

        @media (max-width: 576px) {

            /* Topbar */
            .topbar {
                min-height: 58px;
                padding: 0 12px;
            }

            .navbar-left {
                gap: 9px;
            }

            .navbar-logo {
                max-width: 135px;
                max-height: 36px;
            }

            .navbar-right {
                gap: 8px;
            }

            .profile-icon {
                width: 35px;
                height: 35px;
                min-width: 35px;
            }

            .mobile-menu {
                font-size: 19px;
            }

            /* Class navigation */
            .class-nav {
                padding-left: 10px;
                padding-right: 10px;
            }

            .class-nav a {
                padding: 12px 15px;
                font-size: 13px;
            }

            /* Main content */
            .container.py-4 {
                padding: 16px 12px !important;
            }

            .assessment-actions {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                margin-bottom: 18px;
            }

            .create-btn,
            .topic-filter {
                width: 100%;
            }

            .create-btn {
                min-height: 44px;
            }

            /* Topic */
            .topic-header-bar {
                padding: 12px;
            }

            .topic-header-left {
                gap: 9px;
            }

            .topic-name {
                font-size: 15px;
                line-height: 1.4;
            }

            .topic-option-btn {
                font-size: 17px;
                padding: 5px 8px;
            }

            .topic-body {
                padding: 10px;
                gap: 10px;
            }

            /* Assessment cards */
            .assessment-item {
                padding: 14px 12px;
                border-radius: 8px;
            }

            .assessment-top-row {
                gap: 10px;
            }

            .assessment-title-wrap {
                gap: 9px;
                align-items: flex-start;
            }

            .assessment-title-link {
                font-size: 14px;
                line-height: 1.5;
            }

            .assessment-icon {
                margin-top: 3px;
            }

            .assessment-due-wrap {
                padding-left: 25px;
                gap: 7px;
                flex-wrap: wrap;
                font-size: 12px;
            }

            /* Assessment statistics */
            .assessment-stats-row {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px 8px;
                margin-top: 12px;
                padding-top: 10px;
            }

            .posted-label {
                grid-column: 1 / -1;
                width: fit-content;
                max-width: 100%;
            }

            .stat-cell {
                flex-wrap: wrap;
                gap: 4px;
                font-size: 12px;
            }

            .stat-cell strong {
                font-size: 14px;
            }

            .stat-cell span {
                font-size: 12px;
            }

            /* Empty state */
            .assessment-empty {
                padding: 35px 12px;
            }

            .assessment-empty i {
                font-size: 36px;
            }

            .assessment-empty p {
                font-size: 14px;
                line-height: 1.6;
            }

            /* =================================================
               FULL-SCREEN MOBILE MODAL
            ================================================= */

            .assessment-modal {
                width: 100%;
                height: 100%;
                max-height: 100%;
                border-radius: 0 !important;
            }

            .assessment-modal .modal-header {
                padding: 15px 16px;
                border-bottom: 1px solid #eee !important;
            }

            .assessment-modal .modal-title {
                font-size: 18px;
            }

            .assessment-modal .modal-body {
                flex: 1 1 auto;
                min-height: 0;
                padding: 16px;
                overflow-y: auto;
                overflow-x: hidden;
                -webkit-overflow-scrolling: touch;
            }

            .assessment-modal .modal-footer {
                display: flex;
                flex-direction: column-reverse;
                align-items: stretch;
                gap: 8px;
                padding: 12px 16px;
                padding-bottom: max(12px, env(safe-area-inset-bottom));
                border-top: 1px solid #eee !important;
                background: #fff;
            }

            .cancel-btn,
            .save-btn {
                width: 100%;
                min-width: 0;
                height: 45px;
                margin: 0 !important;
                font-size: 14px;
                flex: none;
            }

            /* Modal form controls */
            .assessment-modal .form-control,
            .assessment-modal .form-select {
                width: 100%;
                min-width: 0;
                min-height: 44px;
                font-size: 16px;
            }

            .assessment-modal .form-label {
                font-size: 13px;
                margin-bottom: 7px;
            }

            .assessment-modal .row.g-3 {
                --bs-gutter-x: 12px;
                --bs-gutter-y: 14px;
            }

            .assessment-modal .row.g-3 > div {
                width: 100%;
                max-width: 100%;
            }

            /* Date and time fields */
            .assessment-modal .d-flex.gap-2 {
                display: flex !important;
                flex-direction: column;
                gap: 9px !important;
            }

            .assessment-modal .d-flex.gap-2 input {
                width: 100%;
                min-width: 0;
                flex: none;
            }

            /* Instructions */
            .instruction-box {
                padding: 12px;
            }

            .instruction-box textarea {
                min-height: 100px;
                font-size: 16px;
            }

            .instruction-footer {
                align-items: center;
                gap: 10px;
            }

            /* Attachment controls */
            .attachment-icons {
                gap: 5px;
            }

            .icon-btn {
                min-width: 38px;
                min-height: 38px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            .send-icon {
                min-width: 38px;
                min-height: 38px;
            }

            .attach-list {
                gap: 7px;
            }

            .attach-chip {
                width: 100%;
                max-width: 100%;
            }

            .attach-chip-name {
                font-size: 12px;
                overflow-wrap: anywhere;
            }

            .link-add-row {
                display: flex;
                flex-wrap: wrap;
            }

            .link-add-row input {
                flex: 1 1 100%;
                width: 100%;
            }

            .link-add-btn {
                width: 100%;
            }

            .assessment-modal input[type="file"] {
                max-width: 100%;
                font-size: 12px;
                overflow: hidden;
            }
        }

        /* =====================================================
           EXTRA SMALL PHONES - 360px
        ===================================================== */

        @media (max-width: 360px) {

            .topbar {
                padding: 0 9px;
            }

            .navbar-logo {
                max-width: 112px;
            }

            .container.py-4 {
                padding: 14px 9px !important;
            }

            .class-nav a {
                padding: 11px 12px;
                font-size: 12px;
            }

            .topic-header-bar {
                padding: 10px;
            }

            .topic-name {
                font-size: 14px;
            }

            .topic-body {
                padding: 8px;
                gap: 9px;
            }

            .assessment-item {
                padding: 12px 10px;
            }

            .assessment-title-link {
                font-size: 13px;
            }

            .assessment-stats-row {
                column-gap: 5px;
            }

            .stat-cell {
                font-size: 11px;
            }

            .stat-cell strong {
                font-size: 13px;
            }

            .assessment-modal .modal-header {
                padding: 13px;
            }

            .assessment-modal .modal-body {
                padding: 12px;
            }

            .assessment-modal .modal-footer {
                padding: 10px 13px;
                padding-bottom: max(10px, env(safe-area-inset-bottom));
            }

            .assessment-modal .modal-title {
                font-size: 16px;
            }

            .assessment-modal .form-control,
            .assessment-modal .form-select {
                font-size: 16px;
            }
        }
    </style>
</head>

<body>

<!-- =====================================================
     SIDEBAR
===================================================== -->

<div class="sidebar" id="sidebar">

    <div class="sidebar-header">

        <button class="toggle-btn" onclick="toggleSidebar()">
            <i class="fa-solid fa-bars"></i>
        </button>

        <button class="sidebar-close-btn"
                onclick="closeMobileSidebar()"
                aria-label="Close menu">
            <i class="fa-solid fa-xmark"></i>
        </button>

    </div>

    <ul>

        <?php if ($isChair): ?>

            <li>
                <a href="program_chair_dashboard.php">
                    <i class="fa-solid fa-house"></i>
                    <span>Home</span>
                </a>
            </li>

        <?php else: ?>

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

        <?php endif; ?>

    </ul>

    <div class="logout-btn">
        <a href="logout.php">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Logout</span>
        </a>
    </div>

</div>

<!-- Sidebar overlay -->
<div class="sidebar-overlay"
     id="sidebarOverlay"
     onclick="closeMobileSidebar()">
</div>

<!-- =====================================================
     MAIN CONTENT
===================================================== -->

<div class="main-content" id="mainContent">

    <!-- TOPBAR -->
    <div class="topbar">

        <div class="navbar-left">

            <button class="mobile-menu" onclick="mobileSidebar()">
                <i class="fa-solid fa-bars"></i>
            </button>

            <img src="img/classroom logo.png"
                 class="navbar-logo"
                 alt="Logo">

        </div>

        <div class="navbar-right">

            <?php
                $profileFile = $user['profile_image'] ?? '';

                $hasProfileImage = !empty($profileFile)
                    && !in_array(
                        strtolower($profileFile),
                        ['default.png', 'default.jpg', 'default.jpeg', 'null']
                    )
                    && file_exists(__DIR__ . '/uploads/' . basename($profileFile));
            ?>

            <a href="profile.php" class="profile-icon">

                <?php if ($hasProfileImage): ?>

                    <img src="uploads/<?php echo htmlspecialchars(basename($profileFile)); ?>"
                         alt="Profile"
                         onerror="this.style.display='none'; this.parentElement.insertAdjacentHTML('beforeend','<i class=&quot;fa-solid fa-user&quot;></i>');">

                <?php else: ?>

                    <i class="fa-solid fa-user"></i>

                <?php endif; ?>

            </a>

        </div>

    </div>

    <!-- CLASS NAVIGATION -->
    <div class="class-nav">

        <?php if ($isChair): ?>

            <a href="program_chair_class.php?id=<?php echo $class_id; ?>&tab=stream">
                Stream
            </a>

            <a href="teacher_assessment.php?id=<?php echo $class_id; ?>"
               class="nav-active">
                Assessments
            </a>

            <a href="teacher_material.php?id=<?php echo $class_id; ?>">
                Materials
            </a>

            <a href="program_chair_class.php?id=<?php echo $class_id; ?>&tab=people">
                People
            </a>

        <?php else: ?>

            <a href="teacher_class.php?id=<?php echo $class_id; ?>">
                Stream
            </a>

            <a href="teacher_assessment.php?id=<?php echo $class_id; ?>"
               class="nav-active">
                Assessments
            </a>

            <a href="teacher_material.php?id=<?php echo $class_id; ?>">
                Materials
            </a>

            <a href="teacher_people.php?id=<?php echo $class_id; ?>">
                People
            </a>

            <a href="teacher_grades.php?id=<?php echo $class_id; ?>">
                Grades
            </a>

        <?php endif; ?>

    </div>

    <!-- =================================================
         ASSESSMENT CONTENT
    ================================================== -->

    <div class="container py-4">

        <!-- ACTIONS -->
        <div class="assessment-actions">

            <button class="create-btn"
                    data-bs-toggle="modal"
                    data-bs-target="#assessmentModal">

                <i class="fa-solid fa-plus"></i>
                Create

            </button>

        </div>

        <!-- ASSESSMENT GROUPS -->

        <?php if (empty($grouped)): ?>

            <div class="assessment-empty">

                <i class="fa-solid fa-clipboard-list"></i>

                <p>
                    No assessments yet.
                    Click <strong>Create</strong> to add one.
                </p>

            </div>

        <?php else: ?>

            <?php foreach ($grouped as $type => $assessments): ?>

                <div class="topic-block mb-3"
                     id="group-<?php echo htmlspecialchars($type); ?>">

                    <!-- TOPIC HEADER -->
                    <div class="topic-header-bar">

                        <div class="topic-header-left">

                            <button class="topic-collapse-btn"
                                    onclick="toggleGroup('body-<?php echo htmlspecialchars($type); ?>', this)">

                                <i class="fa-solid fa-chevron-down"></i>

                            </button>

                            <span class="topic-name">
                                <?php echo htmlspecialchars($type); ?>
                            </span>

                        </div>

                        <div class="dropdown">

                            <button class="topic-option-btn"
                                    type="button"
                                    data-bs-toggle="dropdown"
                                    aria-expanded="false">

                                <i class="fa-solid fa-ellipsis-vertical"></i>

                            </button>

                            <ul class="dropdown-menu dropdown-menu-end">

                                <li>
                                    <a class="dropdown-item"
                                       href="#"
                                       onclick="event.preventDefault(); filterByType('<?php echo htmlspecialchars(addslashes($type)); ?>')">

                                        <i class="fa-solid fa-filter me-2"></i>
                                        Show only this type

                                    </a>
                                </li>

                                <li>
                                    <a class="dropdown-item"
                                       href="#"
                                       onclick="event.preventDefault(); showAllGroups()">

                                        <i class="fa-solid fa-list me-2"></i>
                                        Show all types

                                    </a>
                                </li>

                            </ul>

                        </div>

                    </div>

                    <!-- ASSESSMENT ROWS -->
                    <div class="topic-body"
                         id="body-<?php echo htmlspecialchars($type); ?>">

                        <?php foreach ($assessments as $assessment): ?>

                            <div class="assessment-item">

                                <div class="assessment-top-row">

                                    <div class="assessment-title-wrap">

                                        <i class="fa-regular fa-clipboard assessment-icon"></i>

                                        <a href="teacher_submissions.php?id=<?php echo (int)$assessment['id']; ?>"
                                           class="assessment-title-link">

                                            <?php echo htmlspecialchars($assessment['title']); ?>

                                        </a>

                                    </div>

                                    <div class="assessment-due-wrap">

                                        <span class="due-label">
                                            Due
                                        </span>

                                        <span class="due-date">

                                            <?php
                                                echo !empty($assessment['due_date'])
                                                    ? date("M d, Y", strtotime($assessment['due_date']))
                                                    : 'No due date';
                                            ?>

                                        </span>

                                        <div class="dropdown">

                                            <button class="option-btn"
                                                    type="button"
                                                    data-bs-toggle="dropdown"
                                                    aria-expanded="false">

                                                <i class="fa-solid fa-ellipsis-vertical"></i>

                                            </button>

                                            <ul class="dropdown-menu dropdown-menu-end">

                                                <li>
                                                    <a class="dropdown-item"
                                                       href="edit_assessment.php?id=<?php echo (int)$assessment['id']; ?>&class_id=<?php echo $class_id; ?>">

                                                        <i class="fa-solid fa-pen-to-square me-2"></i>
                                                        Edit

                                                    </a>
                                                </li>

                                                <li>
                                                    <hr class="dropdown-divider">
                                                </li>

                                                <li>
                                                    <a class="dropdown-item text-danger delete-assessment-btn"
                                                       href="#"
                                                       data-id="<?php echo (int)$assessment['id']; ?>">

                                                        <i class="fa-solid fa-trash me-2"></i>
                                                        Delete

                                                    </a>
                                                </li>

                                            </ul>

                                        </div>

                                    </div>

                                </div>

                                <!-- ASSESSMENT STATISTICS -->
                                <div class="assessment-stats-row">

                                    <div class="posted-label">
                                        Posted
                                    </div>

                                    <div class="stat-cell">

                                        <strong>
                                            <?php echo (int)$assessment['turned_in_count']; ?>
                                        </strong>

                                        <span>Turned In</span>

                                    </div>

                                    <div class="stat-cell">

                                        <strong>
                                            <?php echo (int)$assessment['assigned_count']; ?>
                                        </strong>

                                        <span>Assigned</span>

                                    </div>

                                    <div class="stat-cell">

                                        <strong>
                                            <?php echo (int)$assessment['graded_count']; ?>
                                        </strong>

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

<!-- =====================================================
     CREATE ASSESSMENT MODAL
===================================================== -->

<div class="modal fade"
     id="assessmentModal"
     tabindex="-1"
     aria-labelledby="assessmentModalLabel"
     aria-hidden="true">

    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">

        <div class="modal-content assessment-modal">

            <!-- MODAL HEADER -->
            <div class="modal-header border-0 pb-0">

                <h4 class="modal-title fw-bold"
                    id="assessmentModalLabel">

                    Create New Assessment

                </h4>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close">
                </button>

            </div>

            <!-- FORM -->
            <form action="save_assessment.php"
                  method="POST"
                  enctype="multipart/form-data"
                  id="assessmentForm">

                <input type="hidden"
                       name="class_id"
                       value="<?php echo $class_id; ?>">

                <!-- MODAL BODY -->
                <div class="modal-body pt-3">

                    <!-- TOPIC -->
                    <div class="mb-3">

                        <input type="text"
                               name="topic"
                               class="form-control assessment-input"
                               placeholder="Topic">

                    </div>

                    <!-- TITLE -->
                    <div class="mb-3">

                        <input type="text"
                               name="title"
                               class="form-control assessment-input"
                               placeholder="Title*"
                               required>

                    </div>

                    <!-- INSTRUCTIONS AND ATTACHMENTS -->
                    <div class="instruction-box mb-3">

                        <textarea name="instructions"
                                  class="form-control"
                                  placeholder="Instructions (optional)"></textarea>

                        <!-- ATTACHMENT LIST -->
                        <div id="attachList" class="attach-list"></div>

                        <!-- LINK INPUT -->
                        <div id="linkRow"
                             class="link-add-row"
                             hidden>

                            <i class="fa-solid fa-link"></i>

                            <input type="url"
                                   id="linkInput"
                                   placeholder="Paste a link, then press Enter or Add">

                            <button type="button"
                                    id="linkAddBtn"
                                    class="link-add-btn">

                                Add

                            </button>

                        </div>

                        <!-- ATTACHMENT MESSAGE -->
                        <div id="attachMsg" class="attach-msg"></div>

                        <!-- INSTRUCTION FOOTER -->
                        <div class="instruction-footer">

                            <div class="attachment-icons">

                                <button type="button"
                                        class="icon-btn"
                                        id="pickImageBtn"
                                        title="Attach images">

                                    <i class="fa-regular fa-image"></i>

                                </button>

                                <button type="button"
                                        class="icon-btn"
                                        id="pickFileBtn"
                                        title="Attach files">

                                    <i class="fa-solid fa-arrow-up-from-bracket"></i>

                                </button>

                                <button type="button"
                                        class="icon-btn"
                                        id="pickLinkBtn"
                                        title="Attach link">

                                    <i class="fa-solid fa-link"></i>

                                </button>

                                <span id="attachCounter"
                                      class="attach-counter">

                                    0/10

                                </span>

                            </div>

                            <button type="submit"
                                    class="send-icon"
                                    title="Post">

                                <i class="fa-regular fa-paper-plane"></i>

                            </button>

                        </div>

                        <!-- FILE PICKERS -->
                        <input type="file"
                               id="imagePicker"
                               accept="image/*"
                               multiple
                               hidden>

                        <input type="file"
                               id="filePicker"
                               accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt"
                               multiple
                               hidden>

                        <!-- SUBMITTED FILE HOLDERS -->
                        <input type="file"
                               name="images[]"
                               id="imagesHolder"
                               multiple
                               hidden>

                        <input type="file"
                               name="attachments[]"
                               id="filesHolder"
                               multiple
                               hidden>

                        <div id="linkHolders"></div>

                    </div>

                    <!-- TYPE / POINTS / ASSIGN TO -->
                    <div class="row g-3 mb-3">

                        <div class="col-md-4">

                            <label class="form-label fw-bold">
                                Type
                            </label>

                            <select class="form-select assessment-select"
                                    name="type">

                                <option>Quiz</option>
                                <option>Assignment</option>
                                <option>Activity</option>
                                <option>Exam</option>

                            </select>

                        </div>

                        <div class="col-md-4">

                            <label class="form-label fw-bold">
                                Points
                            </label>

                            <select class="form-select assessment-select"
                                    name="points">

                                <option value="100">100</option>
                                <option value="50">50</option>
                                <option value="25">25</option>
                                <option value="10">10</option>
                                <option value="custom">Custom</option>

                            </select>

                        </div>

                        <div class="col-md-4">

                            <label class="form-label fw-bold">
                                Assign to
                            </label>

                            <div class="input-group">

                                <span class="input-group-text bg-white border-end-0">
                                    <i class="fa-solid fa-gear text-secondary"></i>
                                </span>

                                <select class="form-select assessment-select border-start-0"
                                        style="border-left:none;">

                                    <option>All Students</option>

                                </select>

                            </div>

                        </div>

                    </div>

                    <!-- CUSTOM POINTS -->
                    <div id="customPointsBox"
                         class="mb-3"
                         style="display:none;">

                        <label class="form-label fw-bold">
                            Custom Points
                        </label>

                        <input type="number"
                               name="custom_points"
                               class="form-control assessment-input"
                               placeholder="Enter points"
                               min="0">

                    </div>

                    <!-- START DATE AND END DATE -->
                    <div class="row g-3">

                        <div class="col-md-6">

                            <label class="form-label fw-bold">
                                Start Date
                            </label>

                            <div class="d-flex gap-2 mt-1">

                                <input type="date"
                                       class="form-control"
                                       name="start_date">

                                <input type="time"
                                       class="form-control"
                                       name="start_time">

                            </div>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label fw-bold">
                                End Date
                            </label>

                            <div class="d-flex gap-2 mt-1">

                                <input type="date"
                                       class="form-control"
                                       name="due_date"
                                       required>

                                <input type="time"
                                       class="form-control"
                                       name="due_time">

                            </div>

                        </div>

                    </div>

                </div>

                <!-- MODAL FOOTER -->
                <div class="modal-footer border-0 pt-2">

                    <button type="button"
                            class="btn btn-outline-dark cancel-btn"
                            data-bs-dismiss="modal">

                        Cancel

                    </button>

                    <button type="submit"
                            class="btn save-btn">

                        Save

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- =====================================================
     SCRIPTS
===================================================== -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const CLASS_ID = <?php echo json_encode($class_id); ?>;

/* =========================================================
   RESPONSIVE SIDEBAR
========================================================= */

const sidebar = document.getElementById('sidebar');
const mainContent = document.getElementById('mainContent');
const sidebarOverlay = document.getElementById('sidebarOverlay');

function toggleSidebar() {
    if (window.innerWidth > 768) {
        sidebar.classList.toggle('collapsed');
        mainContent.classList.toggle('expanded');
    }
}

function mobileSidebar() {
    if (window.innerWidth <= 768) {
        sidebar.classList.add('show');
        sidebarOverlay.classList.add('show');
        document.body.classList.add('no-scroll');
    }
}

function closeMobileSidebar() {
    sidebar.classList.remove('show');
    sidebarOverlay.classList.remove('show');
    document.body.classList.remove('no-scroll');
}

/* Close sidebar after selecting a link */
document.querySelectorAll('.sidebar ul a, .logout-btn a').forEach(link => {

    link.addEventListener('click', function() {

        if (window.innerWidth <= 768) {
            closeMobileSidebar();
        }

    });

});

/* Close sidebar using Escape */
document.addEventListener('keydown', function(e) {

    if (e.key === 'Escape') {
        closeMobileSidebar();
    }

});

/* Reset mobile sidebar on desktop resize */
window.addEventListener('resize', function() {

    if (window.innerWidth > 768) {
        closeMobileSidebar();
    }

});

/* =========================================================
   TOPIC GROUP COLLAPSE
========================================================= */

function toggleGroup(bodyId, btn) {

    var body = document.getElementById(bodyId);
    var icon = btn.querySelector('i');

    body.classList.toggle('collapsed');

    icon.classList.toggle('fa-chevron-down');
    icon.classList.toggle('fa-chevron-right');

}

/* =========================================================
   TOPIC FILTER
========================================================= */

function filterByType(type) {

    document.querySelectorAll('.topic-block').forEach(function(block) {

        block.style.display =
            block.id === 'group-' + type ? 'block' : 'none';

    });

}

function showAllGroups() {

    document.querySelectorAll('.topic-block').forEach(function(block) {

        block.style.display = 'block';

    });

}

/* =========================================================
   DELETE ASSESSMENT AND CUSTOM POINTS
========================================================= */

document.addEventListener('DOMContentLoaded', function() {

    document.querySelectorAll('.delete-assessment-btn').forEach(function(btn) {

        btn.addEventListener('click', function(e) {

            e.preventDefault();

            var id = this.dataset.id;

            if (confirm('Are you sure you want to delete this assessment? This cannot be undone.')) {

                window.location.href =
                    'delete_assessment.php?id=' + id +
                    '&class_id=' + CLASS_ID;

            }

        });

    });

    /* Custom points */
    var pointsSelect = document.querySelector('select[name="points"]');

    if (pointsSelect) {

        pointsSelect.addEventListener('change', function() {

            var box = document.getElementById('customPointsBox');

            box.style.display =
                this.value === 'custom' ? 'block' : 'none';

        });

    }

});

/* =========================================================
   MULTIPLE ATTACHMENTS
   Maximum 10 total attachments
========================================================= */

(function() {

    var MAX = 10;
    var MAX_MB = 10;

    var FILE_EXT = [
        'pdf',
        'doc',
        'docx',
        'ppt',
        'pptx',
        'xls',
        'xlsx',
        'txt'
    ];

    var items = [];

    function $(id) {
        return document.getElementById(id);
    }

    function showMsg(message) {

        var msg = $('attachMsg');

        if (msg) {
            msg.textContent = message || '';
        }

    }

    /* =====================================================
       SYNC ATTACHMENTS WITH FORM
    ===================================================== */

    function sync() {

        var imagesHolder = $('imagesHolder');
        var filesHolder = $('filesHolder');
        var linkHolders = $('linkHolders');
        var counter = $('attachCounter');

        linkHolders.innerHTML = '';

        var canSetFiles = (typeof DataTransfer !== 'undefined');

        var dtI = canSetFiles ? new DataTransfer() : null;
        var dtF = canSetFiles ? new DataTransfer() : null;

        items.forEach(function(it) {

            if (it.type === 'image') {

                if (dtI) {
                    dtI.items.add(it.file);
                }

            } else if (it.type === 'file') {

                if (dtF) {
                    dtF.items.add(it.file);
                }

            } else {

                var h = document.createElement('input');

                h.type = 'hidden';
                h.name = 'links[]';
                h.value = it.url;

                linkHolders.appendChild(h);

            }

        });

        if (canSetFiles) {

            imagesHolder.files = dtI.files;
            filesHolder.files = dtF.files;

        }

        counter.textContent = items.length + '/' + MAX;

        counter.classList.toggle(
            'full',
            items.length >= MAX
        );

    }

    /* =====================================================
       RENDER ATTACHMENT LIST
    ===================================================== */

    function render() {

        var list = $('attachList');

        list.innerHTML = '';

        items.forEach(function(it) {

            var chip = document.createElement('div');

            chip.className = 'attach-chip';

            if (it.type === 'image') {

                var img = document.createElement('img');

                img.className = 'attach-thumb';
                img.src = it.preview;
                img.alt = '';

                chip.appendChild(img);

            } else {

                var ic = document.createElement('i');

                ic.className =
                    'type-icon fa-solid ' +
                    (it.type === 'link' ? 'fa-link' : 'fa-file');

                chip.appendChild(ic);

            }

            var name = document.createElement('span');

            name.className = 'attach-chip-name';

            name.textContent =
                it.type === 'link' ? it.url : it.file.name;

            chip.appendChild(name);

            /* Remove attachment */
            var x = document.createElement('button');

            x.type = 'button';
            x.className = 'attach-remove';
            x.setAttribute('aria-label', 'Remove');
            x.innerHTML = '&times;';

            x.addEventListener('click', function() {

                if (it.preview) {
                    URL.revokeObjectURL(it.preview);
                }

                items.splice(items.indexOf(it), 1);

                showMsg('');

                render();

            });

            chip.appendChild(x);

            list.appendChild(chip);

        });

        sync();

    }

    /* =====================================================
       ADD FILES
    ===================================================== */

    function addFiles(fileList, type) {

        showMsg('');

        Array.prototype.forEach.call(fileList, function(f) {

            if (items.length >= MAX) {

                showMsg('You can attach up to ' + MAX + ' items only.');

                return;

            }

            if (f.size > MAX_MB * 1024 * 1024) {

                showMsg(
                    '"' + f.name + '" is larger than ' + MAX_MB + ' MB.'
                );

                return;

            }

            if (type === 'image' && f.type.indexOf('image/') !== 0) {

                showMsg('"' + f.name + '" is not an image.');

                return;

            }

            if (type === 'file') {

                var ext = f.name.split('.').pop().toLowerCase();

                if (FILE_EXT.indexOf(ext) === -1) {

                    showMsg(
                        '"' + f.name + '" is not a supported file type.'
                    );

                    return;

                }

            }

            /* Prevent duplicate files */
            var dup = items.some(function(it) {

                return it.file &&
                    it.type === type &&
                    it.file.name === f.name &&
                    it.file.size === f.size;

            });

            if (dup) {
                return;
            }

            items.push({

                type: type,

                file: f,

                preview: type === 'image'
                    ? URL.createObjectURL(f)
                    : null

            });

        });

        render();

    }

    /* =====================================================
       ADD LINK
    ===================================================== */

    function addLink() {

        var linkInput = $('linkInput');

        var v = linkInput.value.trim();

        if (!v) {
            return;
        }

        if (!/^https?:\/\//i.test(v)) {
            v = 'https://' + v;
        }

        try {

            new URL(v);

        } catch (e) {

            showMsg("That doesn't look like a valid link.");

            return;

        }

        if (items.length >= MAX) {

            showMsg('You can attach up to ' + MAX + ' items only.');

            return;

        }

        if (items.some(function(it) {

            return it.type === 'link' && it.url === v;

        })) {

            showMsg('That link is already added.');

            return;

        }

        items.push({

            type: 'link',
            url: v

        });

        linkInput.value = '';

        showMsg('');

        render();

    }

    /* =====================================================
       INITIALIZE ATTACHMENT EVENTS
    ===================================================== */

    document.addEventListener('DOMContentLoaded', function() {

        var linkRow = $('linkRow');
        var linkInput = $('linkInput');

        /* Image picker */
        $('pickImageBtn').addEventListener('click', function() {

            $('imagePicker').click();

        });

        /* File picker */
        $('pickFileBtn').addEventListener('click', function() {

            $('filePicker').click();

        });

        /* Link picker */
        $('pickLinkBtn').addEventListener('click', function() {

            linkRow.hidden = !linkRow.hidden;

            if (!linkRow.hidden) {
                linkInput.focus();
            }

        });

        /* Image selection */
        $('imagePicker').addEventListener('change', function() {

            addFiles(this.files, 'image');

            this.value = '';

        });

        /* File selection */
        $('filePicker').addEventListener('change', function() {

            addFiles(this.files, 'file');

            this.value = '';

        });

        /* Add link button */
        $('linkAddBtn').addEventListener('click', addLink);

        /* Add link using Enter */
        linkInput.addEventListener('keydown', function(e) {

            if (e.key === 'Enter') {

                e.preventDefault();

                addLink();

            }

        });

        /* Before submitting form */
        $('assessmentForm').addEventListener('submit', function() {

            if (linkInput.value.trim() !== '') {
                addLink();
            }

            sync();

        });

        /* Reset modal after closing */
        $('assessmentModal').addEventListener(
            'hidden.bs.modal',
            function() {

                items.forEach(function(it) {

                    if (it.preview) {
                        URL.revokeObjectURL(it.preview);
                    }

                });

                items = [];

                linkRow.hidden = true;

                linkInput.value = '';

                showMsg('');

                $('assessmentForm').reset();

                document.getElementById('customPointsBox').style.display = 'none';

                render();

            }
        );

        render();

    });

})();
</script>

</body>
</html>