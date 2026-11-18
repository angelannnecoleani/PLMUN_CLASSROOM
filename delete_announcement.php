<?php
session_start();
include 'database.php';

if (!isset($_GET['id'])) {
    die("Invalid request.");
}

$id = intval($_GET['id']);

// Get class_id and image before deleting
$result = mysqli_query($conn, "SELECT class_id, image FROM announcements WHERE id='$id'");
$announcement = mysqli_fetch_assoc($result);

if (!$announcement) {
    die("Announcement not found.");
}

$class_id = $announcement['class_id'];

// Delete the image file from server if it exists
if (!empty($announcement['image'])) {
    $image_path = "uploads/" . $announcement['image'];
    if (file_exists($image_path)) {
        unlink($image_path);
    }
}

// Delete the announcement from the database
mysqli_query($conn, "DELETE FROM announcements WHERE id='$id'");

// Redirect back to the class stream
header("Location: teacher_class.php?id=$class_id");
exit;
?>