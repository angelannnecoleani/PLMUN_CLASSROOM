<?php
session_start();
include 'database.php';
header('Content-Type: application/json');

if(!isset($_SESSION['user_id'])){
    echo json_encode(['success'=>false,'message'=>'Not logged in']);
    exit;
}

$teacher_id = $_SESSION['user_id'];
$class_id   = isset($_POST['id']) ? intval($_POST['id']) : 0;

if(!$class_id){
    echo json_encode(['success'=>false,'message'=>'Invalid class']);
    exit;
}

// Verify ownership — teacher can only delete their own class
$check = mysqli_prepare($conn, "SELECT id FROM classes WHERE id=? AND teacher_id=?");
mysqli_stmt_bind_param($check, "ii", $class_id, $teacher_id);
mysqli_stmt_execute($check);
mysqli_stmt_store_result($check);

if(mysqli_stmt_num_rows($check) === 0){
    echo json_encode(['success'=>false,'message'=>'Not authorised']);
    exit;
}
mysqli_stmt_close($check);

// Delete related data first (foreign key safety)
$tables = [
    "DELETE FROM class_chat_messages WHERE class_id=?",
    "DELETE FROM submissions          WHERE assessment_id IN (SELECT id FROM assessments WHERE class_id=?)",
    "DELETE FROM assessments          WHERE class_id=?",
    "DELETE FROM announcements        WHERE class_id=?",
    "DELETE FROM materials            WHERE class_id=?",
    "DELETE FROM class_students       WHERE class_id=?",
    "DELETE FROM classes              WHERE id=? AND teacher_id=?",
];

foreach($tables as $sql){
    $stmt = mysqli_prepare($conn, $sql);
    // Last query needs two params
    if(substr_count($sql, '?') === 2){
        mysqli_stmt_bind_param($stmt, "ii", $class_id, $teacher_id);
    } else {
        mysqli_stmt_bind_param($stmt, "i", $class_id);
    }
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

echo json_encode(['success'=>true]);
?>