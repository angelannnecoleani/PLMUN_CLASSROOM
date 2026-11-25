<?php
session_start();
require_once 'database.php';

/* LOGIN AND ROLE CHECK */

if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'teacher'
) {
    header("Location: login.php");
    exit;
}

$teacher_id = (int) $_SESSION['user_id'];

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

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

    $html  = '<div class="avatar' . ($file !== '' ? ' has-img' : '') . '"';
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
function threadDate($datetime) {
    $ts = strtotime($datetime);

    if (date('Y-m-d', $ts) === date('Y-m-d')) {
        return date('g:i A', $ts);
    }

    if (date('Y', $ts) === date('Y')) {
        return date('M j', $ts);
    }

    return date('M j, Y', $ts);
}

function preview($text, $length = 110) {
    $text = trim(preg_replace('/\s+/', ' ', (string)$text));

    return mb_strimwidth($text, 0, $length, '...', 'UTF-8');
}

/* GET ALL PRIVATE COMMENT THREADS FOR THIS TEACHER
   Includes classes where the teacher is the main teacher
   OR an assigned instructor (class_instructors). */

$sql = "
    SELECT
        a.id AS assessment_id,
        a.title AS assessment_title,
        c.id AS class_id,
        c.class_name,
        cs.student_id,
        u.name AS student_name,
        u.profile_image AS student_image,
        pc.message AS last_message,
        pc.created_at AS last_message_date,
        pc.sender_id AS last_sender_id,

        (
            SELECT COUNT(*)
            FROM assessment_private_comments unread
            WHERE unread.assessment_id = pc.assessment_id
              AND unread.student_id = pc.student_id
              AND unread.sender_id = pc.student_id
              AND unread.read_at IS NULL
        ) AS unread_count

    FROM (
        SELECT
            assessment_id,
            student_id,
            MAX(id) AS latest_id
        FROM assessment_private_comments
        GROUP BY assessment_id, student_id
    ) latest

    INNER JOIN assessment_private_comments pc
        ON pc.id = latest.latest_id

    INNER JOIN assessments a
        ON a.id = pc.assessment_id

    INNER JOIN classes c
        ON c.id = a.class_id

    INNER JOIN users u
        ON u.id = pc.student_id

    INNER JOIN class_students cs
        ON cs.class_id = c.id
       AND cs.student_id = pc.student_id
       AND cs.archived = 0

    WHERE (
        c.teacher_id = ?
        OR EXISTS (
            SELECT 1
            FROM class_instructors ci
            WHERE ci.class_id = c.id
              AND ci.teacher_id = ?
        )
    )

    ORDER BY pc.created_at DESC
";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die("Database error: " . e(mysqli_error($conn)));
}

mysqli_stmt_bind_param($stmt, "ii", $teacher_id, $teacher_id);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$threads = [];

while ($row = mysqli_fetch_assoc($result)) {
    $threads[] = $row;
}

mysqli_stmt_close($stmt);

$total_threads = count($threads);

$unread_total   = 0;
$unread_threads = 0;

foreach ($threads as $thread) {
    $count = (int)$thread['unread_count'];

    $unread_total += $count;

    if ($count > 0) {
        $unread_threads++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Private Comments</title>
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
            --gc-title: #202124;
            --gc-text: #3c4043;
            --gc-muted: #5f6368;
            --gc-border: #dadce0;
            --gc-hover: #f1f3f4;
        }

        * { box-sizing: border-box; }

        body {
            background: #fff;
            font-family: 'Roboto', 'Segoe UI', Arial, sans-serif;
            color: var(--gc-text);
            min-height: 100vh;
        }

        /* ── Top bar ── */
        .top-bar {
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

        .back-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gc-muted);
            text-decoration: none;
            flex-shrink: 0;
            transition: background .15s;
        }

        .back-btn:hover {
            background: var(--gc-hover);
            color: var(--gc-title);
        }

        .top-bar h1 {
            font-size: 20px;
            font-weight: 500;
            color: var(--gc-title);
            margin: 0;
        }

        /* ── Page ── */
        .page-container {
            max-width: 860px;
            margin: 0 auto;
            padding: 26px 16px 60px;
        }

        .summary {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 18px;
        }

        .tabs {
            display: flex;
            gap: 6px;
        }

        .tab-btn {
            border: 1px solid var(--gc-border);
            background: #fff;
            color: var(--gc-text);
            border-radius: 999px;
            padding: 7px 16px;
            font-size: 14px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: background .15s, border-color .15s, color .15s;
        }

        .tab-btn:hover { background: var(--gc-hover); }

        .tab-btn.active {
            background: var(--gc-blue-soft);
            border-color: var(--gc-blue-soft);
            color: var(--gc-blue-dark);
        }

        .tab-count {
            background: var(--gc-blue);
            color: #fff;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            padding: 1px 8px;
        }

        .search-box {
            position: relative;
            flex: 1;
            min-width: 200px;
            max-width: 320px;
        }

        .search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gc-muted);
            font-size: 14px;
        }

        .search-box input {
            width: 100%;
            border: 1px solid var(--gc-border);
            border-radius: 999px;
            padding: 9px 16px 9px 38px;
            font-size: 14px;
            outline: none;
            background: #f8f9fa;
            transition: border-color .15s, background .15s, box-shadow .15s;
        }

        .search-box input:focus {
            background: #fff;
            border-color: var(--gc-blue);
            box-shadow: 0 0 0 1px var(--gc-blue);
        }

        /* ── Thread list ── */
        .thread-list {
            border: 1px solid var(--gc-border);
            border-radius: 12px;
            overflow: hidden;
        }

        .thread-row {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px 20px;
            border-bottom: 1px solid var(--gc-border);
            text-decoration: none;
            color: inherit;
            background: #fff;
            transition: background .15s;
        }

        .thread-row:last-child { border-bottom: none; }

        .thread-row:hover {
            background: var(--gc-hover);
            color: inherit;
        }

        .thread-row.unread { background: #f6faff; }
        .thread-row.unread:hover { background: #ecf3fd; }

        /* ── Avatar: profile photo, or a user icon as the fallback ── */
        .avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 18px;
            flex-shrink: 0;
            overflow: hidden;
            position: relative;
        }

        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        /* Hide the icon when a photo is showing. */
        .avatar.has-img i {
            display: none;
        }

        .thread-main {
            min-width: 0;
            flex: 1;
        }

        .thread-top {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
        }

        .student-name {
            font-size: 15px;
            font-weight: 500;
            color: var(--gc-title);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .thread-row.unread .student-name { font-weight: 700; }

        .thread-date {
            font-size: 12px;
            color: var(--gc-muted);
            white-space: nowrap;
            flex-shrink: 0;
        }

        .thread-row.unread .thread-date {
            color: var(--gc-blue-dark);
            font-weight: 500;
        }

        .thread-context {
            font-size: 13px;
            color: var(--gc-muted);
            margin-top: 1px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .thread-context i { margin-right: 4px; }

        .thread-preview {
            font-size: 14px;
            color: var(--gc-text);
            margin-top: 4px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .thread-row.unread .thread-preview {
            color: var(--gc-title);
            font-weight: 500;
        }

        .thread-preview .you { color: var(--gc-muted); }

        .unread-badge {
            background: var(--gc-blue);
            color: #fff;
            border-radius: 999px;
            min-width: 24px;
            height: 24px;
            padding: 0 8px;
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .chevron {
            color: #bdc1c6;
            font-size: 13px;
            flex-shrink: 0;
        }

        /* ── Empty states ── */
        .empty-state {
            padding: 70px 20px;
            text-align: center;
            color: var(--gc-muted);
        }

        .empty-icon {
            width: 72px;
            height: 72px;
            margin: 0 auto 16px;
            border-radius: 50%;
            background: var(--gc-blue-soft);
            color: var(--gc-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }

        .empty-state h5 {
            color: var(--gc-title);
            font-weight: 500;
            margin-bottom: 6px;
        }

        #noResults {
            display: none;
            border: 1px solid var(--gc-border);
            border-radius: 12px;
        }

        @media (max-width: 576px) {
            .top-bar { height: 56px; padding: 0 8px; }
            .top-bar h1 { font-size: 18px; }

            .page-container { padding: 18px 10px 40px; }

            .search-box { max-width: none; flex-basis: 100%; }

            .thread-row { padding: 14px 12px; gap: 12px; }
            .avatar { width: 40px; height: 40px; font-size: 16px; }
            .chevron { display: none; }
        }

    </style>

</head>

<body>

<!-- TOP BAR -->
<div class="top-bar">

    <a href="teacher_dashboard.php" class="back-btn" aria-label="Back to dashboard">
        <i class="fa-solid fa-arrow-left"></i>
    </a>

    <h1>Private comments</h1>

</div>

<div class="page-container">

    <?php if (empty($threads)): ?>

        <div class="empty-state">

            <div class="empty-icon">
                <i class="fa-regular fa-comments"></i>
            </div>

            <h5>No private comments yet</h5>

            <p class="mb-0">
                When students send you a private comment about an assessment,
                it will appear here.
            </p>

        </div>

    <?php else: ?>

        <!-- TABS + SEARCH -->
        <div class="summary">

            <div class="tabs">

                <button type="button" class="tab-btn active" data-filter="all">
                    All
                    <span class="text-muted small"><?php echo $total_threads; ?></span>
                </button>

                <button type="button" class="tab-btn" data-filter="unread">
                    Unread
                    <?php if ($unread_threads > 0): ?>
                        <span class="tab-count"><?php echo $unread_total; ?></span>
                    <?php endif; ?>
                </button>

            </div>

            <div class="search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input
                    type="text"
                    id="searchInput"
                    placeholder="Search student, class or assessment"
                    aria-label="Search conversations">
            </div>

        </div>

        <!-- THREAD LIST -->
        <div class="thread-list" id="threadList">

            <?php foreach ($threads as $thread): ?>

                <?php
                    $isUnread  = ((int)$thread['unread_count'] > 0);
                    $searchKey = mb_strtolower(
                        $thread['student_name'] . ' ' .
                        $thread['class_name'] . ' ' .
                        $thread['assessment_title']
                    );
                ?>

                <a
                    href="assessment_private_comments.php?assessment_id=<?php
                        echo (int)$thread['assessment_id'];
                    ?>&student_id=<?php
                        echo (int)$thread['student_id'];
                    ?>"
                    class="thread-row<?php echo $isUnread ? ' unread' : ''; ?>"
                    data-unread="<?php echo $isUnread ? '1' : '0'; ?>"
                    data-search="<?php echo e($searchKey); ?>">

                    <?php echo renderAvatar($thread['student_id'], $thread['student_image'] ?? ''); ?>

                    <div class="thread-main">

                        <div class="thread-top">

                            <span class="student-name">
                                <?php echo e($thread['student_name']); ?>
                            </span>

                            <span class="thread-date">
                                <?php echo e(threadDate($thread['last_message_date'])); ?>
                            </span>

                        </div>

                        <div class="thread-context">
                            <i class="fa-solid fa-clipboard"></i>
                            <?php echo e($thread['assessment_title']); ?>
                            &middot;
                            <?php echo e($thread['class_name']); ?>
                        </div>

                        <div class="thread-preview">

                            <?php if ((int)$thread['last_sender_id'] !== (int)$thread['student_id']): ?>
                                <span class="you">You:</span>
                            <?php endif; ?>

                            <?php echo e(preview($thread['last_message'])); ?>

                        </div>

                    </div>

                    <?php if ($isUnread): ?>
                        <span class="unread-badge">
                            <?php echo (int)$thread['unread_count']; ?>
                        </span>
                    <?php endif; ?>

                    <i class="fa-solid fa-chevron-right chevron"></i>

                </a>

            <?php endforeach; ?>

        </div>

        <div class="empty-state" id="noResults">

            <div class="empty-icon">
                <i class="fa-solid fa-magnifying-glass"></i>
            </div>

            <h5>No matching conversations</h5>

            <p class="mb-0">Try a different search or switch tabs.</p>

        </div>

    <?php endif; ?>

</div>

<script>
(function () {
    const rows      = Array.from(document.querySelectorAll('.thread-row'));
    const tabs      = Array.from(document.querySelectorAll('.tab-btn'));
    const search    = document.getElementById('searchInput');
    const noResults = document.getElementById('noResults');
    const list      = document.getElementById('threadList');

    if (!rows.length) return;

    let filter = 'all';

    function applyFilters() {
        const query = (search.value || '').trim().toLowerCase();
        let visible = 0;

        rows.forEach(function (row) {
            const matchesTab =
                filter === 'all' || row.dataset.unread === '1';

            const matchesSearch =
                query === '' || row.dataset.search.indexOf(query) !== -1;

            const show = matchesTab && matchesSearch;

            row.style.display = show ? '' : 'none';

            if (show) visible++;
        });

        noResults.style.display = visible === 0 ? 'block' : 'none';
        list.style.display      = visible === 0 ? 'none' : '';
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');

            filter = tab.dataset.filter;
            applyFilters();
        });
    });

    search.addEventListener('input', applyFilters);
})();
</script>

</body>
</html>