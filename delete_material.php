
<?php
session_start();
require_once 'database.php';
require_once 'auth_helpers.php';

/* =========================================
   LOGIN AND ROLE CHECK
========================================= */
if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$role = $_SESSION['role'] ?? '';
$user_id = (int) $_SESSION['user_id'];

if (!in_array($role, ['teacher', 'program_chair'], true)) {
    http_response_code(403);
    exit("You do not have permission to delete materials.");
}

/* =========================================
   GET MATERIAL ID
========================================= */
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    exit("Invalid material request.");
}

/* =========================================
   FETCH MATERIAL
========================================= */
$stmt = mysqli_prepare(
    $conn,
    "SELECT id, class_id, file
     FROM materials
     WHERE id = ?
     LIMIT 1"
);

if (!$stmt) {
    exit("Database error: " . htmlspecialchars(mysqli_error($conn)));
}

mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$material = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);

if (!$material) {
    http_response_code(404);
    exit("Material not found.");
}

$class_id = (int) $material['class_id'];

/* =========================================
   VERIFY CLASS ACCESS
========================================= */
$class = false;

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
        exit("Database error: " . htmlspecialchars(mysqli_error($conn)));
    }

    mysqli_stmt_bind_param(
        $accessStmt,
        "iii",
        $class_id,
        $user_id,
        $user_id
    );

    mysqli_stmt_execute($accessStmt);
    $accessResult = mysqli_stmt_get_result($accessStmt);
    $class = mysqli_fetch_assoc($accessResult);

    mysqli_stmt_close($accessStmt);

} elseif ($role === 'program_chair') {

    // Preserve existing Program Chair access.
    $class = getClassAccess(
        $conn,
        $class_id,
        $user_id,
        $role
    );
}

if (!$class) {
    http_response_code(403);
    exit("You do not have permission to delete materials in this class.");
}

/* =========================================
   COLLECT ATTACHMENT FILES
========================================= */
$filesToDelete = [];

$uploadDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';

// Include the legacy materials.file attachment.
if (!empty($material['file'])) {
    $legacyFile = basename($material['file']);

    if ($legacyFile !== '') {
        $filesToDelete[] = $legacyFile;
    }
}

/* =========================================
   FETCH MULTIPLE ATTACHMENTS
========================================= */
$attachmentStmt = mysqli_prepare(
    $conn,
    "SELECT type, value
     FROM material_attachments
     WHERE material_id = ?"
);

if (!$attachmentStmt) {
    exit("Database error: " . htmlspecialchars(mysqli_error($conn)));
}

mysqli_stmt_bind_param($attachmentStmt, "i", $id);
mysqli_stmt_execute($attachmentStmt);

$attachmentResult = mysqli_stmt_get_result($attachmentStmt);

while ($attachment = mysqli_fetch_assoc($attachmentResult)) {

    $type = strtolower(trim($attachment['type'] ?? ''));
    $value = trim($attachment['value'] ?? '');

    // Only remove uploaded image/file attachments.
    // External links must not be treated as local files.
    if (in_array($type, ['file', 'image'], true) && $value !== '') {

        // Ignore URLs.
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            continue;
        }

        // Support either "filename.jpg" or "uploads/filename.jpg".
        $fileName = basename(str_replace('\\', '/', $value));

        if ($fileName !== '') {
            $filesToDelete[] = $fileName;
        }
    }
}

mysqli_stmt_close($attachmentStmt);

/* =========================================
   DELETE DATABASE RECORDS
========================================= */
mysqli_begin_transaction($conn);

try {

    // Delete attachment records first.
    $deleteAttachments = mysqli_prepare(
        $conn,
        "DELETE FROM material_attachments
         WHERE material_id = ?"
    );

    if (!$deleteAttachments) {
        throw new Exception(mysqli_error($conn));
    }

    mysqli_stmt_bind_param($deleteAttachments, "i", $id);

    if (!mysqli_stmt_execute($deleteAttachments)) {
        throw new Exception(mysqli_stmt_error($deleteAttachments));
    }

    mysqli_stmt_close($deleteAttachments);

    // Delete the material itself.
    $deleteMaterial = mysqli_prepare(
        $conn,
        "DELETE FROM materials
         WHERE id = ? AND class_id = ?"
    );

    if (!$deleteMaterial) {
        throw new Exception(mysqli_error($conn));
    }

    mysqli_stmt_bind_param(
        $deleteMaterial,
        "ii",
        $id,
        $class_id
    );

    if (!mysqli_stmt_execute($deleteMaterial)) {
        throw new Exception(mysqli_stmt_error($deleteMaterial));
    }

    if (mysqli_stmt_affected_rows($deleteMaterial) !== 1) {
        throw new Exception("The material could not be deleted.");
    }

    mysqli_stmt_close($deleteMaterial);

    mysqli_commit($conn);

} catch (Throwable $e) {

    mysqli_rollback($conn);

    http_response_code(500);
    exit("Unable to delete material: " . htmlspecialchars($e->getMessage()));
}

/* =========================================
   DELETE UPLOADED FILES
========================================= */
$filesToDelete = array_unique($filesToDelete);

foreach ($filesToDelete as $fileName) {

    $safeFileName = basename($fileName);

    if ($safeFileName === '') {
        continue;
    }

    $filePath = $uploadDirectory . DIRECTORY_SEPARATOR . $safeFileName;

    if (is_file($filePath)) {
        @unlink($filePath);
    }
}

/* =========================================
   REDIRECT BACK TO MATERIALS
========================================= */
header("Location: teacher_material.php?id=" . $class_id);
exit;
?>