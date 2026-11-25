<?php
session_start();
require_once 'database.php';
require_once 'assessment_attachments.php';

if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function stopPage($message, $code = 403) {
    http_response_code($code);
    exit($message);
}

/* DESIGN HELPERS */

/* Avatar color picked from the user's id, so it stays consistent. */
function avatarColor($id) {
    $colors = [
        '#1a73e8', '#188038', '#d93025', '#e37400',
        '#9334e6', '#12b5cb', '#c5221f', '#5f6368'
    ];

    return $colors[((int)$id) % count($colors)];
}

/* Returns a valid profile image file name, or '' if there is none. */
function profileImageFile($raw) {
    $raw = trim((string)$raw);

    if ($raw === '') {
        return '';
    }

    $name = basename($raw);

    $defaults = [
        'default.png', 'default.jpg', 'default.jpeg',
        'default.svg', 'null'
    ];

    if (in_array(strtolower($name), $defaults, true)) {
        return '';
    }

    $path = __DIR__ . '/uploads/' . $name;

    return (is_file($path) && is_readable($path)) ? $name : '';
}

/* Prints an avatar: the profile photo if the user has one,
   otherwise a user icon. */
function renderAvatar($userId, $rawImage) {
    $file  = profileImageFile($rawImage);
    $color = avatarColor($userId);

    $html  = '<div class="gc-avatar' . ($file !== '' ? ' has-img' : '') . '"';
    $html .= ' style="background: ' . e($color) . ';" aria-hidden="true">';

    if ($file !== '') {
        $html .= '<img src="uploads/' . e(rawurlencode($file)) . '" alt=""';
        $html .= ' onerror="this.parentNode.classList.remove(\'has-img\'); this.remove();">';
    }

    $html .= '<i class="fa-solid fa-user"></i>';
    $html .= '</div>';

    return $html;
}

/* Time for today, "Nov 24" for this year, full date otherwise. */
function commentDate($datetime) {
    $ts = strtotime($datetime);

    if (date('Y-m-d', $ts) === date('Y-m-d')) {
        return date('g:i A', $ts);
    }

    if (date('Y', $ts) === date('Y')) {
        return date('M j', $ts);
    }

    return date('M j, Y', $ts);
}

/* Escapes the text, keeps line breaks, and makes links clickable. */
function formatText($text) {
    $safe = htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');

    $safe = preg_replace(
        '~(https?://[^\s<]+)~i',
        '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
        $safe
    );

    return nl2br($safe);
}

/* Icon for an attached file, based on its extension. */
function attIcon($name) {
    $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));

    switch ($ext) {
        case 'pdf':
            return 'fa-file-pdf';
        case 'doc':
        case 'docx':
            return 'fa-file-word';
        case 'ppt':
        case 'pptx':
            return 'fa-file-powerpoint';
        case 'xls':
        case 'xlsx':
            return 'fa-file-excel';
        case 'txt':
            return 'fa-file-lines';
        default:
            return 'fa-file';
    }
}

if (!in_array($role, ['student', 'teacher'], true)) {
    stopPage('Access denied.');
}

$assessment_id = filter_input(INPUT_GET, 'assessment_id', FILTER_VALIDATE_INT);

if (!$assessment_id || $assessment_id < 1) {
    stopPage('Invalid assessment.', 400);
}

/* GET ASSESSMENT AND CLASS INFORMATION
   (a.* includes the instructions, points, due date and attachments) */

$stmt = mysqli_prepare($conn, "
    SELECT
        a.*,
        c.class_name,
        c.teacher_id AS class_teacher_id
    FROM assessments a
    INNER JOIN classes c ON c.id = a.class_id
    WHERE a.id = ?
    LIMIT 1
");

mysqli_stmt_bind_param($stmt, "i", $assessment_id);
mysqli_stmt_execute($stmt);

$assessment = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$assessment) {
    stopPage('Assessment not found.', 404);
}

$teacher_id = (int) ($assessment['class_teacher_id'] ?? 0);
$class_id = (int) $assessment['class_id'];

/* GET MAIN INSTRUCTOR NAME (used for labels) */

$teacher_name = 'Instructor';

if ($teacher_id > 0) {

    $stmt = mysqli_prepare($conn, "
        SELECT name
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $teacher_id);
        mysqli_stmt_execute($stmt);

        $teacherRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!empty($teacherRow['name'])) {
            $teacher_name = $teacherRow['name'];
        }
    }
}

/* WHO POSTED THE ASSESSMENT
   Uses created_by when the column exists, otherwise the main instructor. */

$posted_by = $teacher_name;

if (!empty($assessment['created_by'])) {

    $stmt = mysqli_prepare($conn, "
        SELECT name
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    if ($stmt) {
        $authorId = (int)$assessment['created_by'];

        mysqli_stmt_bind_param($stmt, "i", $authorId);
        mysqli_stmt_execute($stmt);

        $authorRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!empty($authorRow['name'])) {
            $posted_by = $authorRow['name'];
        }
    }
}

/* GET THE LOGGED-IN USER'S NAME AND PROFILE IMAGE (for the reply box) */

$currentUserName  = '';
$currentUserImage = '';

$stmt = mysqli_prepare($conn, "
    SELECT name, profile_image
    FROM users
    WHERE id = ?
    LIMIT 1
");

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);

    $meRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    $currentUserName  = (string)($meRow['name'] ?? '');
    $currentUserImage = (string)($meRow['profile_image'] ?? '');
}

/* DETERMINE THE STUDENT IN THIS CONVERSATION */

if ($role === 'student') {

    $student_id = $user_id;

    /* CHECK ACTIVE ENROLLMENT */

    $stmt = mysqli_prepare($conn, "
        SELECT 1
        FROM class_students
        WHERE class_id = ?
          AND student_id = ?
          AND (archived = 0 OR archived IS NULL)
        LIMIT 1
    ");

    mysqli_stmt_bind_param($stmt, "ii", $class_id, $student_id);
    mysqli_stmt_execute($stmt);

    $enrolled = mysqli_stmt_get_result($stmt)->num_rows > 0;
    mysqli_stmt_close($stmt);

    if (!$enrolled) {
        stopPage('You are not enrolled in this class.');
    }

} else {

    /* ONLY AN ASSIGNED INSTRUCTOR CAN OPEN THE THREAD
       (the main teacher OR any instructor in class_instructors) */

    $isAssignedInstructor = ($teacher_id === $user_id);

    if (!$isAssignedInstructor) {

        $stmt = mysqli_prepare($conn, "
            SELECT 1
            FROM class_instructors
            WHERE class_id = ?
              AND teacher_id = ?
            LIMIT 1
        ");

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ii", $class_id, $user_id);
            mysqli_stmt_execute($stmt);

            $isAssignedInstructor = mysqli_stmt_get_result($stmt)->num_rows > 0;
            mysqli_stmt_close($stmt);
        }
    }

    if (!$isAssignedInstructor) {
        stopPage('You are not authorized to view this conversation. You are not an instructor of this class.');
    }

    $student_id = filter_input(INPUT_GET, 'student_id', FILTER_VALIDATE_INT);

    if (!$student_id || $student_id < 1) {
        stopPage('Invalid student.', 400);
    }

    /* VERIFY STUDENT ENROLLMENT */

    $stmt = mysqli_prepare($conn, "
        SELECT 1
        FROM class_students
        WHERE class_id = ?
          AND student_id = ?
          AND (archived = 0 OR archived IS NULL)
        LIMIT 1
    ");

    mysqli_stmt_bind_param($stmt, "ii", $class_id, $student_id);
    mysqli_stmt_execute($stmt);

    $enrolled = mysqli_stmt_get_result($stmt)->num_rows > 0;
    mysqli_stmt_close($stmt);

    if (!$enrolled) {
        stopPage('Student is not enrolled in this class.');
    }
}

/* GET STUDENT NAME */

$stmt = mysqli_prepare($conn, "
    SELECT name
    FROM users
    WHERE id = ?
    LIMIT 1
");

mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);

$student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$student) {
    stopPage('Student not found.', 404);
}

/* TEACHER-ADDED ASSESSMENT ATTACHMENTS (images, files, links) */

$assessmentAttachments = aa_fetch($conn, $assessment_id);

/* Backward compatibility for older assessments that used the
   original image, attachment, and link columns. */

if (empty($assessmentAttachments)) {

    if (!empty($assessment['image'])) {
        $assessmentAttachments[] = [
            'type'          => 'image',
            'file_path'     => $assessment['image'],
            'original_name' => $assessment['image'],
            'url'           => null
        ];
    }

    if (!empty($assessment['attachment'])) {
        $assessmentAttachments[] = [
            'type'          => 'file',
            'file_path'     => $assessment['attachment'],
            'original_name' => preg_replace(
                '/^assessment_file_[0-9a-f]{13}(?:\.[0-9]+)?_/',
                '',
                $assessment['attachment']
            ),
            'url' => null
        ];
    }

    if (!empty($assessment['link'])) {
        $assessmentAttachments[] = [
            'type'          => 'link',
            'file_path'     => null,
            'original_name' => null,
            'url'           => $assessment['link']
        ];
    }
}

$attImages = [];
$attFiles  = [];
$attLinks  = [];

foreach ($assessmentAttachments as $att) {

    if (($att['type'] ?? '') === 'image') {
        $attImages[] = $att;

    } elseif (($att['type'] ?? '') === 'file') {
        $attFiles[] = $att;

    } elseif (($att['type'] ?? '') === 'link') {
        $attLinks[] = $att;
    }
}

/* CSRF TOKEN */

if (empty($_SESSION['private_comment_token'])) {
    $_SESSION['private_comment_token'] = bin2hex(random_bytes(32));
}

/* SEND PRIVATE COMMENT */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['private_comment_token'], $token)) {
        stopPage('Invalid request token. Please refresh the page.', 403);
    }

    $message = trim($_POST['message'] ?? '');

    if ($message === '') {
        $_SESSION['comment_error'] = 'Please enter a message.';
    } elseif (mb_strlen($message) > 5000) {
        $_SESSION['comment_error'] = 'Message cannot exceed 5,000 characters.';
    } elseif ($teacher_id < 1) {
        $_SESSION['comment_error'] = 'No instructor is assigned to this class yet.';
    } else {

        $stmt = mysqli_prepare($conn, "
            INSERT INTO assessment_private_comments
                (assessment_id, student_id, sender_id, message)
            VALUES (?, ?, ?, ?)
        ");

        if (!$stmt) {
            stopPage('Unable to prepare message. Please try again.', 500);
        }

        mysqli_stmt_bind_param(
            $stmt,
            "iiis",
            $assessment_id,
            $student_id,
            $user_id,
            $message
        );

        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            stopPage('Unable to send message. Please try again.', 500);
        }

        mysqli_stmt_close($stmt);

        $_SESSION['comment_success'] = 'Your private comment was sent successfully.';
    }

    $url = 'assessment_private_comments.php?assessment_id=' . $assessment_id;

    if ($role === 'teacher') {
        $url .= '&student_id=' . $student_id;
    }

    header("Location: " . $url);
    exit;
}

/* MARK RECEIVED MESSAGES AS READ
   Opening the conversation marks everything sent by the other person as read. */

$stmt = mysqli_prepare($conn, "
    UPDATE assessment_private_comments
    SET read_at = NOW()
    WHERE assessment_id = ?
      AND student_id = ?
      AND sender_id != ?
      AND read_at IS NULL
");

mysqli_stmt_bind_param($stmt, "iii", $assessment_id, $student_id, $user_id);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

/* GET CONVERSATION (with each sender's profile image) */

$stmt = mysqli_prepare($conn, "
    SELECT
        pc.id,
        pc.sender_id,
        pc.message,
        pc.created_at,
        u.name AS sender_name,
        u.profile_image AS sender_image
    FROM assessment_private_comments pc
    INNER JOIN users u ON u.id = pc.sender_id
    WHERE pc.assessment_id = ?
      AND pc.student_id = ?
    ORDER BY pc.created_at ASC, pc.id ASC
");

mysqli_stmt_bind_param($stmt, "ii", $assessment_id, $student_id);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$comments = [];

while ($row = mysqli_fetch_assoc($result)) {
    $comments[] = $row;
}

mysqli_stmt_close($stmt);

/* FLASH MESSAGES */

$success = $_SESSION['comment_success'] ?? '';
$error = $_SESSION['comment_error'] ?? '';

unset($_SESSION['comment_success'], $_SESSION['comment_error']);

/* DESIGN: role-aware labels and back link */

$backUrl = $role === 'student'
    ? 'student_assessment_view.php?id=' . $assessment_id
    : 'teacher_assessment_comments.php';

$otherName = $role === 'student' ? $teacher_name : $student['name'];

$hasAttachments = (!empty($attImages) || !empty($attFiles) || !empty($attLinks));

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Private comments - <?php echo e($assessment['title']); ?></title>
    <link rel="shortcut icon" href="img/plmun logo.png" type="image/x-icon">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --gc-blue: #1a73e8;
            --gc-blue-dark: #1967d2;
            --gc-blue-soft: #e8f0fe;
            --gc-text: #3c4043;
            --gc-title: #202124;
            --gc-muted: #5f6368;
            --gc-border: #dadce0;
            --gc-bg: #ffffff;
            --gc-hover: #f1f3f4;
        }

        * { box-sizing: border-box; }

        body {
            background: var(--gc-bg);
            font-family: 'Roboto', 'Segoe UI', Arial, sans-serif;
            color: var(--gc-text);
            min-height: 100vh;
        }

        /* ── Top bar ── */
        .gc-topbar {
            display: flex;
            align-items: center;
            gap: 12px;
            height: 64px;
            padding: 0 16px;
            border-bottom: 1px solid var(--gc-border);
            background: #fff;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .gc-back {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gc-muted);
            text-decoration: none;
            transition: background .15s;
            flex-shrink: 0;
        }

        .gc-back:hover {
            background: var(--gc-hover);
            color: var(--gc-title);
        }

        .gc-topbar-title {
            min-width: 0;
        }

        .gc-topbar-title .title {
            font-size: 18px;
            font-weight: 500;
            color: var(--gc-title);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .gc-topbar-title .subtitle {
            font-size: 12px;
            color: var(--gc-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* ── Page ── */
        .gc-page {
            max-width: 760px;
            margin: 0 auto;
            padding: 28px 16px 60px;
        }

        /* ── Assignment header ── */
        .gc-assign-head {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            padding-bottom: 16px;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--gc-blue);
        }

        .gc-assign-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--gc-blue);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
            margin-top: 4px;
        }

        .gc-assign-head h1 {
            font-size: 26px;
            font-weight: 400;
            color: var(--gc-blue-dark);
            margin: 0 0 4px;
            overflow-wrap: anywhere;
        }

        .gc-assign-head .meta {
            font-size: 14px;
            color: var(--gc-muted);
            line-height: 1.6;
        }

        .gc-assign-head .meta .dot {
            margin: 0 6px;
        }

        .gc-assign-head .meta .student-chip {
            display: inline-block;
            margin-top: 4px;
            background: var(--gc-blue-soft);
            color: var(--gc-blue-dark);
            border-radius: 999px;
            padding: 2px 12px;
            font-size: 13px;
            font-weight: 500;
        }

        /* ── Teacher's post: instructions + attachments ── */
        .gc-post {
            margin-bottom: 26px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--gc-border);
        }

        .gc-instructions {
            font-size: 15px;
            line-height: 1.65;
            color: var(--gc-title);
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .gc-instructions a {
            color: #0b57d0;
            text-decoration: underline;
        }

        .gc-no-instructions {
            font-size: 14px;
            color: var(--gc-muted);
            margin: 0;
        }

        .gc-att-title {
            font-size: 12px;
            font-weight: 700;
            color: var(--gc-muted);
            text-transform: uppercase;
            letter-spacing: .5px;
            margin: 22px 0 10px;
        }

        .gc-img-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
        }

        .gc-img-grid a { display: block; }

        .gc-img-grid img {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid var(--gc-border);
            display: block;
            transition: box-shadow .2s, transform .2s;
        }

        .gc-img-grid a:hover img {
            box-shadow: 0 4px 12px rgba(0,0,0,.14);
            transform: translateY(-1px);
        }

        .gc-att-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .gc-att-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 14px;
            border: 1px solid var(--gc-border);
            border-radius: 8px;
            color: var(--gc-title);
            text-decoration: none;
            font-size: 14px;
            min-width: 0;
            transition: background .15s, border-color .15s;
        }

        .gc-att-item:hover {
            background: var(--gc-hover);
            color: var(--gc-title);
        }

        .gc-att-item > i:first-child {
            color: var(--gc-blue);
            font-size: 18px;
            width: 22px;
            text-align: center;
            flex-shrink: 0;
        }

        .gc-att-item span {
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .gc-att-item .open-icon {
            color: #9aa0a6;
            font-size: 12px;
            flex-shrink: 0;
        }

        /* ── Private comments section ── */
        .gc-section-title {
            display: flex;
            align-items: center;
            gap: 14px;
            font-size: 18px;
            font-weight: 400;
            color: var(--gc-blue-dark);
            margin-bottom: 18px;
        }

        .gc-section-title i {
            font-size: 18px;
            color: var(--gc-blue-dark);
        }

        .gc-flash {
            font-size: 14px;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .gc-flash.success { background: #e6f4ea; color: #137333; }
        .gc-flash.error   { background: #fce8e6; color: #c5221f; }

        /* ── Comment list ── */
        .gc-comments {
            display: flex;
            flex-direction: column;
            gap: 22px;
            margin-bottom: 26px;
        }

        .gc-comment {
            display: flex;
            gap: 16px;
        }

        /* ── Avatar: profile photo, or a user icon as the fallback ── */
        .gc-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 15px;
            margin-top: 2px;
            overflow: hidden;
            position: relative;
        }

        .gc-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        /* Hide the icon when a photo is showing. */
        .gc-avatar.has-img i {
            display: none;
        }

        .gc-comment-body {
            min-width: 0;
            flex: 1;
        }

        .gc-comment-head {
            display: flex;
            align-items: baseline;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 2px;
        }

        .gc-comment-name {
            font-size: 14px;
            font-weight: 500;
            color: var(--gc-title);
        }

        .gc-comment-date {
            font-size: 12px;
            color: var(--gc-muted);
        }

        .gc-comment-text {
            font-size: 14px;
            line-height: 1.55;
            color: var(--gc-text);
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .gc-comment-text a {
            color: #0b57d0;
            text-decoration: underline;
        }

        .gc-empty {
            font-size: 14px;
            color: var(--gc-muted);
            margin-bottom: 26px;
        }

        /* ── Add private comment box ── */
        .gc-compose {
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }

        .gc-compose .gc-avatar {
            margin-top: 4px;
        }

        .gc-input-wrap {
            flex: 1;
            min-width: 0;
            display: flex;
            align-items: flex-end;
            border: 1px solid var(--gc-border);
            border-radius: 28px;
            padding: 4px 6px 4px 18px;
            background: #fff;
            transition: border-color .15s, box-shadow .15s;
        }

        .gc-input-wrap:hover {
            background: #fafafa;
        }

        .gc-input-wrap:focus-within {
            border-color: var(--gc-blue);
            box-shadow: 0 0 0 1px var(--gc-blue);
            background: #fff;
        }

        .gc-input-wrap textarea {
            flex: 1;
            border: none;
            outline: none;
            resize: none;
            background: transparent;
            font-family: inherit;
            font-size: 14px;
            line-height: 1.5;
            color: var(--gc-title);
            padding: 10px 0;
            min-height: 40px;
            max-height: 160px;
            overflow-y: auto;
        }

        .gc-input-wrap textarea::placeholder {
            color: var(--gc-muted);
        }

        .gc-send {
            width: 36px;
            height: 36px;
            margin: 0 0 0 6px;
            border: none;
            border-radius: 50%;
            background: transparent;
            color: var(--gc-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            cursor: pointer;
            flex-shrink: 0;
            transition: background .15s;
        }

        .gc-send:hover:not(:disabled) {
            background: var(--gc-blue-soft);
        }

        .gc-send:disabled {
            color: #bdc1c6;
            cursor: not-allowed;
        }

        .gc-hint {
            margin: 10px 0 0 52px;
            font-size: 12px;
            color: var(--gc-muted);
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .gc-count.warn { color: #e37400; font-weight: 500; }
        .gc-count.max  { color: #d93025; font-weight: 500; }

        /* ── Mobile ── */
        @media (max-width: 576px) {
            .gc-topbar { height: 56px; padding: 0 8px; }
            .gc-topbar-title .title { font-size: 16px; }

            .gc-page { padding: 20px 14px 40px; }

            .gc-assign-head h1 { font-size: 21px; }

            .gc-img-grid {
                grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
            }

            .gc-img-grid img { height: 110px; }

            .gc-comment,
            .gc-compose { gap: 12px; }

            .gc-hint { margin-left: 48px; }
            .gc-hint .gc-keys { display: none; }
        }
    </style>
</head>

<body>

<!-- TOP BAR -->
<div class="gc-topbar">

    <a href="<?php echo e($backUrl); ?>" class="gc-back" aria-label="Back">
        <i class="fa-solid fa-arrow-left"></i>
    </a>

    <div class="gc-topbar-title">
        <div class="title"><?php echo e($assessment['title']); ?></div>
        <div class="subtitle"><?php echo e($assessment['class_name']); ?></div>
    </div>

</div>

<div class="gc-page">

    <!-- ASSIGNMENT HEADER -->
    <div class="gc-assign-head">

        <div class="gc-assign-icon">
            <i class="fa-solid fa-clipboard"></i>
        </div>

        <div>
            <h1><?php echo e($assessment['title']); ?></h1>

            <div class="meta">

                <?php echo e($posted_by); ?>

                <?php if (!empty($assessment['created_at'])): ?>
                    <span class="dot">&bull;</span>
                    <?php echo e(date('M d, Y', strtotime($assessment['created_at']))); ?>
                <?php endif; ?>

                <?php if (isset($assessment['points']) && $assessment['points'] !== ''): ?>
                    <span class="dot">&bull;</span>
                    <?php echo e($assessment['points']); ?> points
                <?php endif; ?>

                <?php if (!empty($assessment['due_date'])): ?>
                    <span class="dot">&bull;</span>
                    Due <?php echo e(date('M d, Y h:i A', strtotime($assessment['due_date']))); ?>
                <?php endif; ?>

                <br>

                <?php echo e($assessment['class_name']); ?>

                <?php if ($role === 'teacher'): ?>
                    <span class="dot">&bull;</span>
                    <span class="student-chip">
                        <i class="fa-solid fa-user-graduate me-1"></i>
                        <?php echo e($student['name']); ?>
                    </span>
                <?php endif; ?>

            </div>
        </div>

    </div>

    <!-- WHAT THE TEACHER POSTED -->
    <div class="gc-post">

        <?php if (!empty($assessment['instructions'])): ?>
            <div class="gc-instructions">
                <?php echo formatText($assessment['instructions']); ?>
            </div>
        <?php else: ?>
            <p class="gc-no-instructions">No additional instructions.</p>
        <?php endif; ?>

        <!-- IMAGES -->
        <?php if (!empty($attImages)): ?>
            <div class="gc-att-title">Images (<?php echo count($attImages); ?>)</div>
            <div class="gc-img-grid">
                <?php foreach ($attImages as $img): ?>
                    <a href="uploads/<?php echo rawurlencode(basename($img['file_path'])); ?>"
                       target="_blank" rel="noopener">
                        <img src="uploads/<?php echo rawurlencode(basename($img['file_path'])); ?>"
                             alt="<?php echo e($img['original_name'] ?: 'Attached image'); ?>"
                             loading="lazy">
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- FILES -->
        <?php if (!empty($attFiles)): ?>
            <div class="gc-att-title">Files (<?php echo count($attFiles); ?>)</div>
            <div class="gc-att-list">
                <?php foreach ($attFiles as $f): ?>
                    <?php $fname = $f['original_name'] ?: basename($f['file_path']); ?>
                    <a href="uploads/<?php echo rawurlencode(basename($f['file_path'])); ?>"
                       target="_blank" rel="noopener" class="gc-att-item">
                        <i class="fa-solid <?php echo e(attIcon($fname)); ?>"></i>
                        <span><?php echo e($fname); ?></span>
                        <i class="fa-solid fa-arrow-up-right-from-square open-icon"></i>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- LINKS -->
        <?php if (!empty($attLinks)): ?>
            <div class="gc-att-title">Links (<?php echo count($attLinks); ?>)</div>
            <div class="gc-att-list">
                <?php foreach ($attLinks as $l): ?>
                    <?php
                        // only allow http(s) links to be clickable
                        $safeUrl = preg_match('#^https?://#i', (string)$l['url']) ? $l['url'] : '#';
                    ?>
                    <a href="<?php echo e($safeUrl); ?>"
                       target="_blank" rel="noopener noreferrer" class="gc-att-item">
                        <i class="fa-solid fa-link"></i>
                        <span><?php echo e($l['url']); ?></span>
                        <i class="fa-solid fa-arrow-up-right-from-square open-icon"></i>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>

    <!-- PRIVATE COMMENTS -->
    <div class="gc-section-title">
        <i class="fa-regular fa-user"></i>
        <span>
            <?php
                $count = count($comments);

                if ($count === 0) {
                    echo 'Private comments';
                } elseif ($count === 1) {
                    echo '1 private comment';
                } else {
                    echo $count . ' private comments';
                }
            ?>
        </span>
    </div>

    <?php if ($success): ?>
        <div class="gc-flash success" role="status">
            <i class="fa-solid fa-circle-check"></i>
            <?php echo e($success); ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="gc-flash error" role="alert">
            <i class="fa-solid fa-circle-exclamation"></i>
            <?php echo e($error); ?>
        </div>
    <?php endif; ?>

    <?php if (empty($comments)): ?>

        <div class="gc-empty">
            No private comments yet. Only you and
            <?php echo e($otherName); ?> can see this conversation.
        </div>

    <?php else: ?>

        <div class="gc-comments" id="commentList">

            <?php foreach ($comments as $comment): ?>

                <div class="gc-comment">

                    <?php echo renderAvatar($comment['sender_id'], $comment['sender_image'] ?? ''); ?>

                    <div class="gc-comment-body">

                        <div class="gc-comment-head">
                            <span class="gc-comment-name">
                                <?php echo e($comment['sender_name']); ?>
                            </span>
                            <span class="gc-comment-date">
                                <?php echo e(commentDate($comment['created_at'])); ?>
                            </span>
                        </div>

                        <div class="gc-comment-text">
                            <?php echo formatText($comment['message']); ?>
                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

    <!-- ADD PRIVATE COMMENT -->
    <form method="POST" id="commentForm">

        <input
            type="hidden"
            name="csrf_token"
            value="<?php echo e($_SESSION['private_comment_token']); ?>">

        <div class="gc-compose">

            <?php echo renderAvatar($user_id, $currentUserImage); ?>

            <div class="gc-input-wrap">

                <textarea
                    name="message"
                    id="messageInput"
                    rows="1"
                    maxlength="5000"
                    placeholder="Add private comment to <?php echo e($otherName); ?>"
                    aria-label="Add private comment"
                    required></textarea>

                <button
                    type="submit"
                    class="gc-send"
                    id="sendBtn"
                    aria-label="Post private comment"
                    disabled>
                    <i class="fa-solid fa-paper-plane"></i>
                </button>

            </div>

        </div>

        <div class="gc-hint">
            <span class="gc-keys">Press Ctrl + Enter to post</span>
            <span class="gc-count" id="charCount">0 / 5000</span>
        </div>

    </form>

</div>

<script>
(function () {
    const MAX = 5000;

    const form      = document.getElementById('commentForm');
    const input     = document.getElementById('messageInput');
    const sendBtn   = document.getElementById('sendBtn');
    const charCount = document.getElementById('charCount');

    // Auto-grow textarea.
    function autoGrow() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 160) + 'px';
    }

    // Counter + enable/disable post button.
    function updateState() {
        const len = input.value.length;

        charCount.textContent = len + ' / ' + MAX;
        charCount.classList.toggle('warn', len >= MAX * 0.9 && len < MAX);
        charCount.classList.toggle('max', len >= MAX);

        sendBtn.disabled = input.value.trim().length === 0;
    }

    input.addEventListener('input', function () {
        autoGrow();
        updateState();
    });

    // Ctrl/Cmd + Enter posts the comment.
    input.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();

            if (!sendBtn.disabled) {
                form.requestSubmit();
            }
        }
    });

    // Prevent double posts.
    form.addEventListener('submit', function () {
        sendBtn.disabled = true;
    });

    autoGrow();
    updateState();

    // If the page loaded after posting, keep the new comment box in view.
    const list = document.getElementById('commentList');

    if (list && window.location.search) {
        input.scrollIntoView({ block: 'end' });
    }
})();
</script>

</body>
</html>