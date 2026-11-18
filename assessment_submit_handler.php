<?php
/**
 * assessment_submit_handler.php
 *
 * Extended from the original single-file version to support multiple file
 * attachments + links per submission. Requires the submission_attachments
 * table (see submission_attachments.sql) since `submissions.file_path` can
 * only hold one file.
 */

session_start();
include 'database.php';
header('Content-Type: application/json');

function respond($ok, $extra = []) {
    echo json_encode(array_merge(['success' => $ok], $extra));
    exit();
}

if (!isset($_SESSION['user_id'])) {
    respond(false, ['error' => 'Not logged in']);
}

$student_id    = $_SESSION['user_id'];
$assessment_id = intval($_POST['assessment_id'] ?? 0);
$action        = $_POST['action'] ?? '';

if (!$assessment_id || !in_array($action, ['turn_in', 'mark_done', 'unsubmit'], true)) {
    respond(false, ['error' => 'Invalid request']);
}

/* VERIFY THE ASSESSMENT EXISTS AND THE STUDENT BELONGS TO ITS CLASS */
$aq = mysqli_prepare($conn, "SELECT class_id FROM assessments WHERE id = ?");
mysqli_stmt_bind_param($aq, "i", $assessment_id);
mysqli_stmt_execute($aq);
$assessment = mysqli_stmt_get_result($aq)->fetch_assoc();
if (!$assessment) respond(false, ['error' => 'Assessment not found']);

$class_id = $assessment['class_id'];

$check = mysqli_prepare($conn,
    "SELECT 1 FROM class_students WHERE class_id = ? AND student_id = ?");
mysqli_stmt_bind_param($check, "ii", $class_id, $student_id);
mysqli_stmt_execute($check);
mysqli_stmt_store_result($check);
if (mysqli_stmt_num_rows($check) == 0) respond(false, ['error' => 'Access denied']);
mysqli_stmt_close($check);

/* DOES A SUBMISSION ROW ALREADY EXIST FOR THIS STUDENT + ASSESSMENT? */
$existingQ = mysqli_prepare($conn,
    "SELECT id, file_path FROM submissions WHERE assessment_id = ? AND student_id = ?");
mysqli_stmt_bind_param($existingQ, "ii", $assessment_id, $student_id);
mysqli_stmt_execute($existingQ);
$existing = mysqli_stmt_get_result($existingQ)->fetch_assoc();

$uploadDir = __DIR__ . '/uploads/';

/* Removes every file backing an existing submission — rows from
   submission_attachments plus, for older single-file submissions made
   before this table existed, the legacy `submissions.file_path` column —
   and clears the attachment rows themselves. Shared by unsubmit and by
   turn_in when resubmitting. */
function clearOldAttachments($conn, $existing, $uploadDir) {
    if (!$existing) return;

    $aq = mysqli_prepare($conn,
        "SELECT file_path FROM submission_attachments WHERE submission_id = ? AND type = 'file'");
    mysqli_stmt_bind_param($aq, "i", $existing['id']);
    mysqli_stmt_execute($aq);
    $files = mysqli_stmt_get_result($aq)->fetch_all(MYSQLI_ASSOC);
    foreach ($files as $f) {
        $path = $uploadDir . $f['file_path'];
        if (is_file($path)) @unlink($path);
    }

    $del = mysqli_prepare($conn, "DELETE FROM submission_attachments WHERE submission_id = ?");
    mysqli_stmt_bind_param($del, "i", $existing['id']);
    mysqli_stmt_execute($del);

    // Legacy single-file submissions made before multi-attachment support
    if (!empty($existing['file_path'])) {
        $oldPath = $uploadDir . $existing['file_path'];
        if (is_file($oldPath)) @unlink($oldPath);
    }
}

/* ── MARK AS DONE (no file needed) ── */
if ($action === 'mark_done') {
    if ($existing) {
        $stmt = mysqli_prepare($conn,
            "UPDATE submissions SET status = 'done', submitted_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $existing['id']);
    } else {
        $stmt = mysqli_prepare($conn,
            "INSERT INTO submissions (assessment_id, student_id, status, submitted_at) VALUES (?, ?, 'done', NOW())");
        mysqli_stmt_bind_param($stmt, "ii", $assessment_id, $student_id);
    }
    if (!mysqli_stmt_execute($stmt)) respond(false, ['error' => 'Database error']);
    respond(true);
}

/* ── UNSUBMIT (undo turned-in or marked-done, back to no submission) ── */
if ($action === 'unsubmit') {
    if ($existing) {
        clearOldAttachments($conn, $existing, $uploadDir);
        $stmt = mysqli_prepare($conn, "DELETE FROM submissions WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $existing['id']);
        if (!mysqli_stmt_execute($stmt)) respond(false, ['error' => 'Database error']);
    }
    respond(true);
}

/* ── TURN IN (multiple files + links) ── */
if ($action === 'turn_in') {
    $MAX_ATTACHMENTS = 10;
    $MAX_FILE_SIZE   = 20 * 1024 * 1024; // 20MB per file, same limit as before
    $allowedExt = ['pdf','doc','docx','ppt','pptx','xls','xlsx','txt',
                   'jpg','jpeg','png','gif','webp','zip'];

    $links = $_POST['links'] ?? [];
    if (!is_array($links)) $links = [$links];
    $links = array_values(array_filter(array_map('trim', $links)));

    $fileCount  = isset($_FILES['files']['name']) ? count($_FILES['files']['name']) : 0;
    $totalCount = $fileCount + count($links);

    if ($totalCount === 0) {
        respond(false, ['error' => 'Attach at least one file or link.']);
    }
    if ($totalCount > $MAX_ATTACHMENTS) {
        respond(false, ['error' => "You can attach up to $MAX_ATTACHMENTS items."]);
    }

    foreach ($links as $l) {
        if (!preg_match('/^https?:\/\//i', $l)) {
            respond(false, ['error' => 'Links must start with http:// or https://']);
        }
    }

    // Validate every file up front, before saving anything to disk.
    for ($i = 0; $i < $fileCount; $i++) {
        if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
            respond(false, ['error' => 'File upload failed']);
        }
        if ($_FILES['files']['size'][$i] > $MAX_FILE_SIZE) {
            respond(false, ['error' => 'File is too large (max 20MB)']);
        }
        $ext = strtolower(pathinfo($_FILES['files']['name'][$i], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            respond(false, ['error' => 'File type not allowed']);
        }
    }

    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    // Save every new file to disk first. Only once all of them are safely
    // saved do we touch the database or remove anything from a previous
    // submission — so a failed upload never destroys a working one.
    $savedFiles = []; // [storedFileName, originalName]
    for ($i = 0; $i < $fileCount; $i++) {
        $originalName   = basename($_FILES['files']['name'][$i]);
        $storedFileName = 'sub_' . $student_id . '_' . $assessment_id . '_' . time() . '_' . $i . '_' .
                           preg_replace('/[^A-Za-z0-9_.-]/', '_', $originalName);

        if (!move_uploaded_file($_FILES['files']['tmp_name'][$i], $uploadDir . $storedFileName)) {
            foreach ($savedFiles as $sf) {
                $p = $uploadDir . $sf[0];
                if (is_file($p)) @unlink($p);
            }
            respond(false, ['error' => 'Could not save file']);
        }
        $savedFiles[] = [$storedFileName, $originalName];
    }

    // This is a resubmission if a row already exists — replace whatever was there before.
    if ($existing) {
        clearOldAttachments($conn, $existing, $uploadDir);
        $stmt = mysqli_prepare($conn,
            "UPDATE submissions SET status = 'submitted', submitted_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $existing['id']);
        mysqli_stmt_execute($stmt);
        $submission_id = $existing['id'];
    } else {
        $stmt = mysqli_prepare($conn,
            "INSERT INTO submissions (assessment_id, student_id, status, submitted_at) VALUES (?, ?, 'submitted', NOW())");
        mysqli_stmt_bind_param($stmt, "ii", $assessment_id, $student_id);
        mysqli_stmt_execute($stmt);
        $submission_id = mysqli_insert_id($conn);
    }

    foreach ($savedFiles as $sf) {
        $fa = mysqli_prepare($conn,
            "INSERT INTO submission_attachments (submission_id, type, file_path, original_name) VALUES (?, 'file', ?, ?)");
        mysqli_stmt_bind_param($fa, "iss", $submission_id, $sf[0], $sf[1]);
        mysqli_stmt_execute($fa);
    }

    foreach ($links as $l) {
        $la = mysqli_prepare($conn,
            "INSERT INTO submission_attachments (submission_id, type, link_url) VALUES (?, 'link', ?)");
        mysqli_stmt_bind_param($la, "is", $submission_id, $l);
        mysqli_stmt_execute($la);
    }

    respond(true);
}