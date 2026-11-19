
<?php
session_start();

require_once 'database.php';
require_once 'auth_helpers.php';
require_once 'notification_helper.php';

const MAX_ATTACHMENTS = 10;
const MAX_FILE_BYTES = 10 * 1024 * 1024; // 10 MB per file

/* =========================================
   LOGIN AND ROLE VALIDATION
========================================= */

$role = $_SESSION['role'] ?? '';

if (
    empty($_SESSION['user_id']) ||
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    !in_array($role, ['teacher', 'program_chair'], true)
) {
    header('Location: login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

$class_id = (int) ($_POST['class_id'] ?? 0);
$content = trim($_POST['content'] ?? '');

if ($class_id <= 0) {
    die('Invalid class.');
}

$back = classHomeUrl($role, $class_id);

/* =========================================
   VERIFY CLASS ACCESS
========================================= */

$hasAccess = false;

if ($role === 'teacher') {

    // Check primary instructor OR additional assigned instructor.
    $accessStmt = mysqli_prepare(
        $conn,
        "SELECT c.id
         FROM classes c
         WHERE c.id = ?
         AND (
             c.teacher_id = ?
             OR EXISTS (
                 SELECT 1
                 FROM class_instructors ci
                 WHERE ci.class_id = c.id
                 AND ci.teacher_id = ?
             )
         )
         LIMIT 1"
    );

    if (!$accessStmt) {
        die('Database error while checking instructor access.');
    }

    mysqli_stmt_bind_param(
        $accessStmt,
        "iii",
        $class_id,
        $user_id,
        $user_id
    );

    mysqli_stmt_execute($accessStmt);
    mysqli_stmt_store_result($accessStmt);

    $hasAccess = mysqli_stmt_num_rows($accessStmt) > 0;

    mysqli_stmt_close($accessStmt);

} elseif ($role === 'program_chair') {

    // Preserve the existing Program Chair access rules.
    $hasAccess = getClassAccess(
        $conn,
        $class_id,
        $user_id,
        $role
    );
}

if (!$hasAccess) {
    http_response_code(403);
    die('Access denied. You are not assigned to this class.');
}

/* =========================================
   ATTACHMENT CONFIGURATION
========================================= */

$imageExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

$fileExt = [
    'pdf', 'doc', 'docx',
    'ppt', 'pptx',
    'xls', 'xlsx',
    'txt', 'csv',
    'zip', 'rar'
];

$attachments = [];

/* =========================================
   PROCESS LINKS
========================================= */

$postedLinks = $_POST['links'] ?? [];

if (!is_array($postedLinks)) {
    $postedLinks = [];
}

$seen = [];

foreach ($postedLinks as $raw) {

    if (count($attachments) >= MAX_ATTACHMENTS) {
        break;
    }

    $url = trim((string) $raw);

    if ($url === '') {
        continue;
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        continue;
    }

    $scheme = strtolower(
        parse_url($url, PHP_URL_SCHEME) ?? ''
    );

    if (!in_array($scheme, ['http', 'https'], true)) {
        continue;
    }

    if (isset($seen[$url])) {
        continue;
    }

    $seen[$url] = true;

    $attachments[] = [
        'type' => 'link',
        'path' => $url,
        'name' => null
    ];
}

/* =========================================
   PROCESS UPLOADED FILES AND IMAGES
========================================= */

$uploadDir = __DIR__ . '/uploads/';

if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        die('Unable to create upload directory.');
    }
}

if (
    isset($_FILES['attachments']['name']) &&
    is_array($_FILES['attachments']['name'])
) {

    $count = count($_FILES['attachments']['name']);

    for ($i = 0; $i < $count; $i++) {

        if (count($attachments) >= MAX_ATTACHMENTS) {
            break;
        }

        if (
            !isset(
                $_FILES['attachments']['error'][$i],
                $_FILES['attachments']['size'][$i],
                $_FILES['attachments']['tmp_name'][$i],
                $_FILES['attachments']['name'][$i]
            )
        ) {
            continue;
        }

        if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }

        if ($_FILES['attachments']['size'][$i] > MAX_FILE_BYTES) {
            continue;
        }

        $origName = basename(
            $_FILES['attachments']['name'][$i]
        );

        $tmp = $_FILES['attachments']['tmp_name'][$i];

        $ext = strtolower(
            pathinfo($origName, PATHINFO_EXTENSION)
        );

        if (in_array($ext, $imageExt, true)) {

            if (@getimagesize($tmp) === false) {
                continue;
            }

            $type = 'image';

        } elseif (in_array($ext, $fileExt, true)) {

            $type = 'file';

        } else {
            continue;
        }

        $stored = bin2hex(random_bytes(8))
            . '_'
            . time()
            . '.'
            . $ext;

        if (!move_uploaded_file($tmp, $uploadDir . $stored)) {
            continue;
        }

        $attachments[] = [
            'type' => $type,
            'path' => $stored,
            'name' => $origName
        ];
    }
}

/* =========================================
   CHECK IF THERE IS CONTENT TO POST
========================================= */

if ($content === '' && count($attachments) === 0) {
    header('Location: ' . $back);
    exit;
}

/* =========================================
   SAVE ANNOUNCEMENT
========================================= */

$stmt = mysqli_prepare(
    $conn,
    "INSERT INTO announcements
        (class_id, teacher_id, content, image, link, pinned, created_at)
     VALUES (?, ?, ?, '', '', 0, NOW())"
);

if (!$stmt) {
    die('Could not prepare announcement: ' . mysqli_error($conn));
}

mysqli_stmt_bind_param(
    $stmt,
    "iis",
    $class_id,
    $user_id,
    $content
);

if (!mysqli_stmt_execute($stmt)) {
    $error = mysqli_stmt_error($stmt);
    mysqli_stmt_close($stmt);

    die('Could not save announcement: ' . htmlspecialchars($error));
}

$announcement_id = mysqli_insert_id($conn);

mysqli_stmt_close($stmt);

/* =========================================
   SAVE ANNOUNCEMENT ATTACHMENTS
========================================= */

if (!empty($attachments)) {

    $attachmentStmt = mysqli_prepare(
        $conn,
        "INSERT INTO announcement_attachments
            (announcement_id, type, path, original_name)
         VALUES (?, ?, ?, ?)"
    );

    if (!$attachmentStmt) {
        die('Could not prepare attachment records.');
    }

    foreach ($attachments as $a) {

        mysqli_stmt_bind_param(
            $attachmentStmt,
            "isss",
            $announcement_id,
            $a['type'],
            $a['path'],
            $a['name']
        );

        if (!mysqli_stmt_execute($attachmentStmt)) {
            error_log(
                'Announcement attachment error: '
                . mysqli_stmt_error($attachmentStmt)
            );
        }
    }

    mysqli_stmt_close($attachmentStmt);
}

/* =========================================
   NOTIFY ENROLLED STUDENTS
========================================= */

notify_class_students(
    $conn,
    $class_id,
    'announcement',
    'New announcement in ' . get_class_name($conn, $class_id),
    'student_class.php?id=' . $class_id
);

/* =========================================
   RETURN TO CLASS PAGE
========================================= */

header('Location: ' . $back);
exit;
?>
