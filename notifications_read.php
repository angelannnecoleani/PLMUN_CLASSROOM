<?php
session_start();
include 'database.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false]);
    exit();
}

$sid     = (int)$_SESSION['user_id'];
$items   = json_decode($_POST['items'] ?? '[]', true);
$allowed = ['announcement', 'assessment', 'material', 'mention', 'due', 'overdue', 'review'];

if (!is_array($items)) {
    echo json_encode(['success' => false]);
    exit();
}

$stmt = mysqli_prepare($conn,
    "INSERT IGNORE INTO notification_reads (student_id, post_type, post_id) VALUES (?, ?, ?)");

foreach ($items as $it) {
    $type = $it['type'] ?? '';
    $pid  = (int)($it['id'] ?? 0);
    if (!in_array($type, $allowed, true) || $pid <= 0) continue;
    mysqli_stmt_bind_param($stmt, "isi", $sid, $type, $pid);
    mysqli_stmt_execute($stmt);
}
mysqli_stmt_close($stmt);

echo json_encode(['success' => true]);