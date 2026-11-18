<?php
session_start();
include 'database.php';

if(empty($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}
$teacher_id = intval($_SESSION['user_id']);

if(!isset($_GET['id'])){
    die("Material not found");
}
$material_id = intval($_GET['id']);

// Fetch the material + its class, verifying this teacher owns that class
$query = mysqli_query($conn, "
    SELECT materials.id, materials.pinned, materials.class_id
    FROM materials
    INNER JOIN classes ON materials.class_id = classes.id
    WHERE materials.id = '$material_id' AND classes.teacher_id = '$teacher_id'
");
$material = mysqli_fetch_assoc($query);
if(!$material) die("Material not found or you do not have access to it.");

$class_id = intval($material['class_id']);
$newPinned = $material['pinned'] ? 0 : 1;

mysqli_query($conn, "UPDATE materials SET pinned = '$newPinned' WHERE id = '$material_id'");

header("Location: teacher_class.php?id=" . $class_id);
exit;