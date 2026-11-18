<?php
session_start();
include 'database.php';
header('Content-Type: application/json');

if(!isset($_SESSION['user_id'])){
    echo json_encode(['success'=>false,'message'=>'Not logged in']);
    exit;
}

$user_id = $_SESSION['user_id'];

/* ============================================================
   STUDENT — leave a class they're enrolled in
   (student dashboard posts: class_id=<id>)
   ============================================================ */
if(isset($_POST['class_id'])){

    $student_id = $user_id;
    $class_id   = intval($_POST['class_id']);

    if(!$class_id){
        echo json_encode(['success'=>false,'message'=>'Invalid class']);
        exit;
    }

    // Only someone actually enrolled can leave
    $check = mysqli_prepare($conn, "SELECT 1 FROM class_students WHERE student_id=? AND class_id=?");
    mysqli_stmt_bind_param($check, "ii", $student_id, $class_id);
    mysqli_stmt_execute($check);
    mysqli_stmt_store_result($check);

    if(mysqli_stmt_num_rows($check) === 0){
        echo json_encode(['success'=>false,'message'=>'You are not enrolled in this class']);
        mysqli_stmt_close($check);
        exit;
    }
    mysqli_stmt_close($check);

    $stmt = mysqli_prepare($conn, "DELETE FROM class_students WHERE student_id=? AND class_id=?");
    mysqli_stmt_bind_param($stmt, "ii", $student_id, $class_id);

    if(mysqli_stmt_execute($stmt)){
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'message'=>'Could not leave this class']);
    }

    mysqli_stmt_close($stmt);
    exit;
}

/* ============================================================
   TEACHER — leave / archive a class they own
   (teacher dashboard posts: id=<id>)
   ============================================================ */
if(isset($_POST['id'])){

    $teacher_id = $user_id;
    $class_id   = intval($_POST['id']);

    if(!$class_id){
        echo json_encode(['success'=>false,'message'=>'Invalid class']);
        exit;
    }

    // Only the owner can leave (which archives the class)
    $check = mysqli_prepare($conn, "SELECT id FROM classes WHERE id=? AND teacher_id=?");
    mysqli_stmt_bind_param($check, "ii", $class_id, $teacher_id);
    mysqli_stmt_execute($check);
    mysqli_stmt_store_result($check);

    if(mysqli_stmt_num_rows($check) === 0){
        echo json_encode(['success'=>false,'message'=>'Not authorised']);
        mysqli_stmt_close($check);
        exit;
    }
    mysqli_stmt_close($check);

    // Try archived column first; fall back to just removing the teacher
    $archiveStmt = mysqli_prepare($conn, "UPDATE classes SET archived=1 WHERE id=? AND teacher_id=?");
    if($archiveStmt){
        mysqli_stmt_bind_param($archiveStmt, "ii", $class_id, $teacher_id);
        $ok = mysqli_stmt_execute($archiveStmt);
        mysqli_stmt_close($archiveStmt);
        if($ok){
            echo json_encode(['success'=>true]);
            exit;
        }
    }

    // If archived column doesn't exist, set teacher_id to NULL
    $nullStmt = mysqli_prepare($conn, "UPDATE classes SET teacher_id=NULL WHERE id=? AND teacher_id=?");
    mysqli_stmt_bind_param($nullStmt, "ii", $class_id, $teacher_id);
    mysqli_stmt_execute($nullStmt);
    mysqli_stmt_close($nullStmt);

    echo json_encode(['success'=>true]);
    exit;
}

/* Neither a student nor a teacher payload was sent */
echo json_encode(['success'=>false,'message'=>'Missing class identifier']);