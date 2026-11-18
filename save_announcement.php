<?php
session_start();
include 'database.php';
include 'auth_helpers.php';
include_once 'notification_helper.php';

const MAX_ATTACHMENTS = 10;
const MAX_FILE_BYTES  = 10 * 1024 * 1024; // 10 MB per file

$role = $_SESSION['role'] ?? '';

if (
    !isset($_SESSION['user_id'])
    || $_SERVER['REQUEST_METHOD'] !== 'POST'
    || !in_array($role, ['teacher', 'program_chair'], true)
) {
    header('Location: login.php');
    exit;
}

$teacher_id = (int) $_SESSION['user_id'];   // the poster (teacher or program chair)
$class_id   = intval($_POST['class_id'] ?? 0);
$content    = trim($_POST['content'] ?? '');
$back       = classHomeUrl($role, $class_id);

/* ---- teacher: own class / program chair: any class in their program ---- */
if (!getClassAccess($conn, $class_id, $teacher_id, $role)) {
    http_response_code(403);
    die("Access denied");
}

$imageExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$fileExt  = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'csv', 'zip', 'rar'];

$attachments = []; // each: ['type' => ..., 'path' => ..., 'name' => ...]

/* ---- LINKS (http/https only, no duplicates) ---- */
$postedLinks = $_POST['links'] ?? [];
if (!is_array($postedLinks)) $postedLinks = [];

$seen = [];
foreach ($postedLinks as $raw) {
    if (count($attachments) >= MAX_ATTACHMENTS) break;
    $url = trim((string) $raw);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) continue;
    $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) continue;
    if (isset($seen[$url])) continue;
    $seen[$url] = true;
    $attachments[] = ['type' => 'link', 'path' => $url, 'name' => null];
}

/* ---- FILES & IMAGES ---- */
$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

if (!empty($_FILES['attachments']['name']) && is_array($_FILES['attachments']['name'])) {
    $count = count($_FILES['attachments']['name']);

    for ($i = 0; $i < $count; $i++) {
        if (count($attachments) >= MAX_ATTACHMENTS) break;

        if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($_FILES['attachments']['size'][$i] > MAX_FILE_BYTES) continue;

        $origName = basename($_FILES['attachments']['name'][$i]);
        $tmp      = $_FILES['attachments']['tmp_name'][$i];
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (in_array($ext, $imageExt, true)) {
            if (@getimagesize($tmp) === false) continue; // not a real image
            $type = 'image';
        } elseif (in_array($ext, $fileExt, true)) {
            $type = 'file';
        } else {
            continue; // extension not allowed
        }

        $stored = bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
        if (!move_uploaded_file($tmp, $uploadDir . $stored)) continue;

        $attachments[] = ['type' => $type, 'path' => $stored, 'name' => $origName];
    }
}

/* ---- nothing to post? ---- */
if ($content === '' && count($attachments) === 0) {
    header("Location: " . $back);
    exit;
}

/* ---- save the announcement ---- */
$stmt = mysqli_prepare(
    $conn,
    "INSERT INTO announcements (class_id, teacher_id, content, image, link, pinned, created_at)
     VALUES (?, ?, ?, '', '', 0, NOW())"
);
mysqli_stmt_bind_param($stmt, "iis", $class_id, $teacher_id, $content);
if (!mysqli_stmt_execute($stmt)) {
    die("Could not save announcement: " . mysqli_error($conn));
}
$announcement_id = mysqli_insert_id($conn);
mysqli_stmt_close($stmt);

/* ---- save the attachments ---- */
if ($attachments) {
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO announcement_attachments (announcement_id, type, path, original_name)
         VALUES (?, ?, ?, ?)"
    );
    foreach ($attachments as $a) {
        mysqli_stmt_bind_param($stmt, "isss", $announcement_id, $a['type'], $a['path'], $a['name']);
        mysqli_stmt_execute($stmt);
    }
    mysqli_stmt_close($stmt);
}

/* ---- NOTIFY STUDENTS ---- */
notify_class_students(
    $conn,
    $class_id,
    'announcement',
    'New announcement in ' . get_class_name($conn, $class_id),
    'student_class.php?id=' . $class_id
);

header("Location: " . $back);
exit;