
<?php
session_start();

require_once 'database.php';
require_once 'auth_helpers.php';

$role = $_SESSION['role'] ?? '';

/* =========================================
   LOGIN AND ROLE VALIDATION
========================================= */

if (
    empty($_SESSION['user_id']) ||
    !in_array($role, ['teacher', 'program_chair'], true)
) {
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];

/* =========================================
   REQUEST VALIDATION
========================================= */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request.");
}

$class_id = (int) ($_POST['class_id'] ?? 0);

if ($class_id <= 0) {
    die("Invalid class.");
}

/* =========================================
   VERIFY CLASS ACCESS
========================================= */

$hasAccess = false;

if ($role === 'teacher') {

    // Primary instructor OR additional assigned instructor.

    $accessStmt = mysqli_prepare(
        $conn,
        "SELECT c.id
         FROM classes c
         WHERE c.id = ?
         AND (
             c.teacher_id = ?
             OR EXISTS (
                 SELECT 1
                 FROM class_instructors ci
                 WHERE ci.class_id = c.id
                 AND ci.teacher_id = ?
             )
         )
         LIMIT 1"
    );

    if (!$accessStmt) {
        die("Database error while checking instructor access.");
    }

    mysqli_stmt_bind_param(
        $accessStmt,
        "iii",
        $class_id,
        $user_id,
        $user_id
    );

    mysqli_stmt_execute($accessStmt);
    mysqli_stmt_store_result($accessStmt);

    $hasAccess = mysqli_stmt_num_rows($accessStmt) > 0;

    mysqli_stmt_close($accessStmt);

} elseif ($role === 'program_chair') {

    $hasAccess = (bool) getClassAccess(
        $conn,
        $class_id,
        $user_id,
        $role
    );
}

if (!$hasAccess) {
    http_response_code(403);
    die("Class not found or you do not have access to it.");
}

/* =========================================
   GET MATERIAL INFORMATION
========================================= */

$title = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');

if ($title === '') {
    die("Title is required.");
}

/* =========================================
   UPLOAD DIRECTORY
========================================= */

$created_by = (int)$_SESSION['user_id'];

$uploadDir = __DIR__ . '/uploads/';

if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        die("Unable to create upload directory.");
    }
}

/* =========================================
   ALLOWED FILE EXTENSIONS
========================================= */

$allowedExt = [
    'jpg', 'jpeg', 'png', 'gif', 'webp',
    'pdf', 'doc', 'docx',
    'ppt', 'pptx',
    'xls', 'xlsx',
    'txt', 'csv',
    'zip', 'rar',
    'mp4', 'mov', 'avi'
];

/* =========================================
   HELPER: STORE UPLOADED FILE
========================================= */

function store_uploaded_file(
    $tmpPath,
    $originalName,
    $uploadDir,
    $allowedExt
) {

    $ext = strtolower(
        pathinfo($originalName, PATHINFO_EXTENSION)
    );

    $safeExt = preg_replace('/[^a-z0-9]/', '', $ext);

    if (
        $safeExt === '' ||
        !in_array($safeExt, $allowedExt, true)
    ) {
        return null;
    }

    if (!is_uploaded_file($tmpPath)) {
        return null;
    }

    $storedName = bin2hex(random_bytes(16))
        . '_'
        . time()
        . '.'
        . $safeExt;

    if (!move_uploaded_file($tmpPath, $uploadDir . $storedName)) {
        return null;
    }

    return $storedName;
}

/* =========================================
   SAVE MATERIAL
   SAVE ACTUAL CREATOR ID
========================================= */

$stmt = mysqli_prepare(
    $conn,
    "INSERT INTO materials
        (class_id, created_by, title, description, created_at)
     VALUES (?, ?, ?, ?, NOW())"
);

if (!$stmt) {
    die("Database error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param(
    $stmt,
    "iiss",
    $class_id,
    $user_id,
    $title,
    $description
);

if (!mysqli_stmt_execute($stmt)) {
    $error = mysqli_stmt_error($stmt);
    mysqli_stmt_close($stmt);

    die("Could not save material: " . htmlspecialchars($error));
}

$material_id = mysqli_insert_id($conn);

mysqli_stmt_close($stmt);

/* =========================================
   PREPARE ATTACHMENT INSERT
========================================= */

$attachStmt = mysqli_prepare(
    $conn,
    "INSERT INTO material_attachments
        (material_id, type, value)
     VALUES (?, ?, ?)"
);

if (!$attachStmt) {
    die("Could not prepare material attachments: " . mysqli_error($conn));
}

/* =========================================
   HELPER: SAVE ATTACHMENT
========================================= */

function save_material_attachment(
    $attachStmt,
    $material_id,
    $type,
    $value
) {

    mysqli_stmt_bind_param(
        $attachStmt,
        "iss",
        $material_id,
        $type,
        $value
    );

    return mysqli_stmt_execute($attachStmt);
}

/* =========================================
   UPLOAD MULTIPLE IMAGES
   INPUT NAME: images[]
========================================= */

if (
    isset($_FILES['images']['name']) &&
    is_array($_FILES['images']['name'])
) {

    foreach ($_FILES['images']['name'] as $i => $originalName) {

        if (
            !isset(
                $_FILES['images']['error'][$i],
                $_FILES['images']['tmp_name'][$i],
                $_FILES['images']['size'][$i]
            )
        ) {
            continue;
        }

        if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }

        if ($_FILES['images']['size'][$i] > 10 * 1024 * 1024) {
            continue;
        }

        $storedName = store_uploaded_file(
            $_FILES['images']['tmp_name'][$i],
            $originalName,
            $uploadDir,
            $allowedExt
        );

        if ($storedName === null) {
            continue;
        }

        save_material_attachment(
            $attachStmt,
            $material_id,
            'file',
            $storedName
        );
    }
}

/* =========================================
   UPLOAD MULTIPLE FILES
   INPUT NAME: files[]
========================================= */

if (
    isset($_FILES['files']['name']) &&
    is_array($_FILES['files']['name'])
) {

    foreach ($_FILES['files']['name'] as $i => $originalName) {

        if (
            !isset(
                $_FILES['files']['error'][$i],
                $_FILES['files']['tmp_name'][$i],
                $_FILES['files']['size'][$i]
            )
        ) {
            continue;
        }

        if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }

        if ($_FILES['files']['size'][$i] > 10 * 1024 * 1024) {
            continue;
        }

        $storedName = store_uploaded_file(
            $_FILES['files']['tmp_name'][$i],
            $originalName,
            $uploadDir,
            $allowedExt
        );

        if ($storedName === null) {
            continue;
        }

        save_material_attachment(
            $attachStmt,
            $material_id,
            'file',
            $storedName
        );
    }
}

/* =========================================
   SAVE MULTIPLE LINKS
   INPUT NAME: links[]
========================================= */

if (
    !empty($_POST['links']) &&
    is_array($_POST['links'])
) {

    $seenLinks = [];

    foreach ($_POST['links'] as $link) {

        $link = trim((string) $link);

        if ($link === '') {
            continue;
        }

        if (!filter_var($link, FILTER_VALIDATE_URL)) {
            continue;
        }

        $scheme = strtolower(
            parse_url($link, PHP_URL_SCHEME) ?? ''
        );

        if (!in_array($scheme, ['http', 'https'], true)) {
            continue;
        }

        if (isset($seenLinks[$link])) {
            continue;
        }

        $seenLinks[$link] = true;

        save_material_attachment(
            $attachStmt,
            $material_id,
            'link',
            $link
        );
    }
}

mysqli_stmt_close($attachStmt);

/* =========================================
   RETURN TO MATERIAL PAGE
========================================= */

header("Location: teacher_material.php?id=" . $class_id);
exit();
?>
