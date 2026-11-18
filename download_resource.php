<?php
session_start();
include 'database.php';

if(empty($_SESSION['user_id'])){
    die("You must be logged in.");
}
$student_id  = intval($_SESSION['user_id']);
$material_id = intval($_GET['id'] ?? 0);

$stmt = mysqli_prepare($conn, "
    SELECT m.file, m.title
    FROM materials m
    INNER JOIN class_students cs ON cs.class_id = m.class_id
    WHERE m.id = ? AND cs.student_id = ?
");
mysqli_stmt_bind_param($stmt, "ii", $material_id, $student_id);
mysqli_stmt_execute($stmt);
$material = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);

if(!$material || empty($material['file'])){
    die("File not found or not authorized.");
}

$filePath = __DIR__ . '/uploads/' . $material['file'];
if(!file_exists($filePath)){
    die("File missing on server.");
}

$ext = pathinfo($filePath, PATHINFO_EXTENSION);
$downloadName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $material['title']) . '.' . $ext;

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-cache, must-revalidate');
readfile($filePath);
exit;