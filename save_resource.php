<?php
// Buffer all output from this point on. If any include (database.php),
// warning, notice, or stray echo produces text before our json_encode,
// it gets swallowed by ob_clean() below instead of corrupting the
// response the frontend is trying to res.json() parse.
ob_start();

session_start();
include 'database.php';

// Discard anything printed so far (e.g. notices/warnings from includes)
// and set the header fresh, right before we send the real response.
ob_clean();
header('Content-Type: application/json');

if(empty($_SESSION['user_id'])){
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$student_id  = intval($_SESSION['user_id']);
$material_id = intval($_POST['material_id'] ?? 0);
$action      = $_POST['action'] ?? 'save';

if($material_id <= 0){
    echo json_encode(['success' => false, 'error' => 'Invalid material ID']);
    exit;
}

// Confirm student is enrolled in the class this material belongs to
$stmt = mysqli_prepare($conn, "
    SELECT m.id
    FROM materials m
    INNER JOIN class_students cs ON cs.class_id = m.class_id
    WHERE m.id = ? AND cs.student_id = ?
");
mysqli_stmt_bind_param($stmt, "ii", $material_id, $student_id);
mysqli_stmt_execute($stmt);
$authorized = mysqli_stmt_get_result($stmt)->num_rows > 0;
mysqli_stmt_close($stmt);

if(!$authorized){
    echo json_encode(['success' => false, 'error' => 'Not authorized to save this material']);
    exit;
}

if($action === 'unsave'){
    $stmt = mysqli_prepare($conn, "DELETE FROM student_resources WHERE student_id = ? AND material_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $student_id, $material_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    echo json_encode(['success' => true, 'saved' => false]);
    exit;
}

$stmt = mysqli_prepare($conn, "INSERT IGNORE INTO student_resources (student_id, material_id) VALUES (?, ?)");
mysqli_stmt_bind_param($stmt, "ii", $student_id, $material_id);
$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

if(!$ok){
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    exit;
}

echo json_encode(['success' => true, 'saved' => true]);