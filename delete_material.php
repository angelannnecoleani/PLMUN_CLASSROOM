<?php
session_start();
include 'database.php';

if(!isset($_GET['id'])){
    die("Invalid request.");
}

$id = intval($_GET['id']);

// Get class_id and file before deleting
$result   = mysqli_query($conn, "SELECT class_id, file FROM materials WHERE id='$id'");
$material = mysqli_fetch_assoc($result);

if(!$material){
    die("Material not found.");
}

$class_id = $material['class_id'];

// Delete file from server if it exists
if(!empty($material['file'])){
    $file_path = "uploads/" . $material['file'];
    if(file_exists($file_path)) unlink($file_path);
}

// Delete from database
mysqli_query($conn, "DELETE FROM materials WHERE id='$id'");

// Redirect back
header("Location: teacher_material.php?id=$class_id");
exit;
?>