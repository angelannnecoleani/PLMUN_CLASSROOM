<?php
session_start();
include 'database.php';

// Must be logged in
if(empty($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$user_id = intval($_SESSION['user_id']);

// Get material ID
if(!isset($_GET['id'])){
    die("Material not found.");
}

$material_id = intval($_GET['id']);

// Get material and verify that the logged-in teacher owns the class
$sql = "
    SELECT materials.*, classes.teacher_id
    FROM materials
    INNER JOIN classes ON classes.id = materials.class_id
    WHERE materials.id = '$material_id'
";

$result = mysqli_query($conn, $sql);

if(!$result || mysqli_num_rows($result) === 0){
    die("Material not found.");
}

$material = mysqli_fetch_assoc($result);

// Only the teacher who owns the class can download it
if(intval($material['teacher_id']) !== $user_id){
    die("You do not have permission to download this material.");
}

// Material must have a file
if(empty($material['file'])){
    die("This material does not have a downloadable file.");
}

// File location
$file = basename($material['file']);
$file_path = __DIR__ . "/uploads/" . $file;

// Check if file exists
if(!file_exists($file_path)){
    die("The file does not exist.");
}

// Get file information
$file_size = filesize($file_path);
$file_name = basename($file_path);

// Force download
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $file_name . '"');
header('Content-Length: ' . $file_size);
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: public');

readfile($file_path);
exit;
?>