<?php
session_start();
include 'database.php';
include 'auth_helpers.php';

$role = $_SESSION['role'] ?? '';

// Must be logged in as a teacher or program chair
if (empty($_SESSION['user_id']) || !in_array($role, ['teacher', 'program_chair'], true)) {
    header("Location: login.php");
    exit;
}
$user_id = intval($_SESSION['user_id']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['class_id'])) {
    die("Invalid request");
}
$class_id = intval($_POST['class_id']);

/* teacher: own class / program chair: any class in their program */
if (!getClassAccess($conn, $class_id, $user_id, $role)) {
    http_response_code(403);
    die("Class not found or you do not have access to it.");
}

$title = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');
if ($title === '') {
    die("Title is required");
}

// Where uploaded files get moved to
$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// File types that may be uploaded (blocks .php and other executable types)
$allowedExt = [
    'jpg', 'jpeg', 'png', 'gif', 'webp',
    'pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx',
    'txt', 'csv', 'zip', 'rar', 'mp4', 'mov', 'avi'
];

// Insert the material row itself
$stmt = mysqli_prepare($conn, "
    INSERT INTO materials (class_id, title, description, created_at)
    VALUES (?, ?, ?, NOW())
");
if (!$stmt) {
    die("Database error: " . mysqli_error($conn));
}
mysqli_stmt_bind_param($stmt, "iss", $class_id, $title, $description);
if (!mysqli_stmt_execute($stmt)) {
    die("Could not save material: " . mysqli_stmt_error($stmt));
}
$material_id = mysqli_insert_id($conn);
mysqli_stmt_close($stmt);

// Helper: move one uploaded file into uploads/ with a collision-safe name.
// Returns the stored name, or null if the type is not allowed / the move failed.
function store_uploaded_file($tmpPath, $originalName, $uploadDir, $allowedExt) {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $safeExt = preg_replace('/[^a-z0-9]/', '', $ext);

    if ($safeExt === '' || !in_array($safeExt, $allowedExt, true)) {
        return null;
    }

    $storedName = uniqid('mat_', true) . '.' . $safeExt;
    if (!move_uploaded_file($tmpPath, $uploadDir . $storedName)) {
        return null;
    }
    return $storedName;
}

$attachStmt = mysqli_prepare($conn, "
    INSERT INTO material_attachments (material_id, type, value)
    VALUES (?, ?, ?)
");

// Multiple images (input name="images[]")
if (!empty($_FILES['images']['name'][0])) {
    foreach ($_FILES['images']['name'] as $i => $originalName) {
        if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) continue;
        $storedName = store_uploaded_file($_FILES['images']['tmp_name'][$i], $originalName, $uploadDir, $allowedExt);
        if ($storedName === null) continue;
        $type = 'file';
        mysqli_stmt_bind_param($attachStmt, "iss", $material_id, $type, $storedName);
        mysqli_stmt_execute($attachStmt);
    }
}

// Multiple files (input name="files[]")
if (!empty($_FILES['files']['name'][0])) {
    foreach ($_FILES['files']['name'] as $i => $originalName) {
        if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) continue;
        $storedName = store_uploaded_file($_FILES['files']['tmp_name'][$i], $originalName, $uploadDir, $allowedExt);
        if ($storedName === null) continue;
        $type = 'file';
        mysqli_stmt_bind_param($attachStmt, "iss", $material_id, $type, $storedName);
        mysqli_stmt_execute($attachStmt);
    }
}

// Multiple links (input name="links[]") — http/https only
if (!empty($_POST['links']) && is_array($_POST['links'])) {
    foreach ($_POST['links'] as $link) {
        $link = trim($link);
        if ($link === '') continue;
        if (!filter_var($link, FILTER_VALIDATE_URL)) continue;
        $scheme = strtolower(parse_url($link, PHP_URL_SCHEME) ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) continue;
        $type = 'link';
        mysqli_stmt_bind_param($attachStmt, "iss", $material_id, $type, $link);
        mysqli_stmt_execute($attachStmt);
    }
}
mysqli_stmt_close($attachStmt);

// teacher_material.php now serves both teachers and program chairs
header("Location: teacher_material.php?id=" . $class_id);
exit;