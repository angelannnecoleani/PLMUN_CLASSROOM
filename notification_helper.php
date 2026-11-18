<?php
// Notify a single user
function create_notification($conn, $userId, $classId, $type, $message, $link = null){
    $stmt = mysqli_prepare($conn,
        "INSERT INTO notifications (user_id, class_id, type, message, link) VALUES (?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "iisss", $userId, $classId, $type, $message, $link);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

// Notify every active (non-archived) student in a class
function notify_class_students($conn, $classId, $type, $message, $link = null){
    $stmt = mysqli_prepare($conn,
        "INSERT INTO notifications (user_id, class_id, type, message, link)
         SELECT student_id, ?, ?, ?, ? FROM class_students
         WHERE class_id = ? AND archived = 0");
    mysqli_stmt_bind_param($stmt, "isssi", $classId, $type, $message, $link, $classId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function get_class_name($conn, $classId){
    $stmt = mysqli_prepare($conn, "SELECT class_name FROM classes WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $classId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $name);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    return $name ?: 'your class';
}

function get_user_name($conn, $userId){
    $stmt = mysqli_prepare($conn, "SELECT name FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $name);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    return $name ?: 'A student';
}