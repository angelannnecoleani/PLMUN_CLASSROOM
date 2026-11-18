<?php
session_start();
include 'database.php';

if (!isset($_GET['id'])) {
    die("Invalid request.");
}

$id = intval($_GET['id']);

// Get current pin status
$result = mysqli_query($conn, "SELECT pinned, class_id FROM announcements WHERE id='$id'");
$row = mysqli_fetch_assoc($result);

if (!$row) {
    die("Announcement not found.");
}

$class_id = $row['class_id'];

// Toggle: if pinned, unpin; if not pinned, pin
$new_status = $row['pinned'] ? 0 : 1;

mysqli_query($conn, "UPDATE announcements SET pinned='$new_status' WHERE id='$id'");

// Redirect back to the class stream
header("Location: teacher_class.php?id=$class_id");
exit;
?>