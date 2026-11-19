<?php
session_start();

require_once 'database.php';

header('Content-Type: application/json; charset=utf-8');

/* ==========================================
   LOGIN AND ROLE VALIDATION
========================================== */

if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'teacher'
) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized access.'
    ]);
    exit;
}

$teacher_id = (int) $_SESSION['user_id'];
$class_id = isset($_GET['class_id']) ? (int) $_GET['class_id'] : 0;

if ($class_id <= 0) {
    echo json_encode([
        'success' => false,
        'error' => 'Missing or invalid class_id.'
    ]);
    exit;
}

/* ==========================================
   VERIFY CLASS ACCESS
   Primary teacher OR assigned co-instructor
========================================== */

$check = mysqli_prepare($conn, "
    SELECT c.id
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
    LIMIT 1
");

if (!$check) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Unable to verify class access.'
    ]);
    exit;
}

mysqli_stmt_bind_param(
    $check,
    "iii",
    $class_id,
    $teacher_id,
    $teacher_id
);

mysqli_stmt_execute($check);
$accessResult = mysqli_stmt_get_result($check);

if (!$accessResult || mysqli_num_rows($accessResult) === 0) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'You are not authorized to access this class.'
    ]);
    exit;
}

mysqli_stmt_close($check);

/* ==========================================
   FETCH ACTIVE STUDENTS
========================================== */

$sStmt = mysqli_prepare($conn, "
    SELECT
        u.id,
        u.name,
        u.profile_image
    FROM class_students cs
    INNER JOIN users u ON cs.student_id = u.id
    WHERE cs.class_id = ?
      AND (cs.archived = 0 OR cs.archived IS NULL)
      AND u.role = 'student'
    ORDER BY u.name ASC
");

if (!$sStmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Unable to retrieve students.'
    ]);
    exit;
}

mysqli_stmt_bind_param($sStmt, "i", $class_id);
mysqli_stmt_execute($sStmt);

$studentResult = mysqli_stmt_get_result($sStmt);
$students = $studentResult
    ? $studentResult->fetch_all(MYSQLI_ASSOC)
    : [];

mysqli_stmt_close($sStmt);

/* ==========================================
   FETCH ASSESSMENTS
========================================== */

$aStmt = mysqli_prepare($conn, "
    SELECT id, title, points
    FROM assessments
    WHERE class_id = ?
    ORDER BY id ASC
");

if (!$aStmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Unable to retrieve assessments.'
    ]);
    exit;
}

mysqli_stmt_bind_param($aStmt, "i", $class_id);
mysqli_stmt_execute($aStmt);

$assessmentResult = mysqli_stmt_get_result($aStmt);
$assessments = $assessmentResult
    ? $assessmentResult->fetch_all(MYSQLI_ASSOC)
    : [];

mysqli_stmt_close($aStmt);

/* ==========================================
   FETCH GRADES
========================================== */

$gStmt = mysqli_prepare($conn, "
    SELECT
        s.student_id,
        s.assessment_id,
        s.grade
    FROM submissions s
    INNER JOIN assessments a ON s.assessment_id = a.id
    WHERE a.class_id = ?
");

if (!$gStmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Unable to retrieve grades.'
    ]);
    exit;
}

mysqli_stmt_bind_param($gStmt, "i", $class_id);
mysqli_stmt_execute($gStmt);

$gradeResult = mysqli_stmt_get_result($gStmt);
$gradeRows = $gradeResult
    ? $gradeResult->fetch_all(MYSQLI_ASSOC)
    : [];

mysqli_stmt_close($gStmt);

$grades = [];

foreach ($gradeRows as $g) {
    $grades[$g['student_id']][$g['assessment_id']] = $g['grade'];
}

/* ==========================================
   CALCULATE PER-STUDENT PERFORMANCE
========================================== */

$studentPerf = [];

foreach ($students as $s) {

    $total = 0;
    $maxTotal = 0;
    $count = 0;

    foreach ($assessments as $a) {

        $g = $grades[$s['id']][$a['id']] ?? null;

        if ($g !== null) {
            $total += (float) $g;
            $maxTotal += (float) $a['points'];
            $count++;
        }
    }

    $pct = ($maxTotal > 0)
        ? round(($total / $maxTotal) * 100, 1)
        : null;

    $studentPerf[$s['id']] = [
        'pct' => $pct,
        'graded' => $count,
        'total_assessed' => count($assessments)
    ];
}

/* ==========================================
   SUMMARY COUNTS
========================================== */

$counts = [
    'excellent' => 0,
    'good' => 0,
    'average' => 0,
    'needs_help' => 0,
    'ungraded' => 0
];

foreach ($studentPerf as $sp) {

    if ($sp['pct'] === null) {
        $counts['ungraded']++;
        continue;
    }

    if ($sp['pct'] >= 90) {
        $counts['excellent']++;
    } elseif ($sp['pct'] >= 75) {
        $counts['good']++;
    } elseif ($sp['pct'] >= 60) {
        $counts['average']++;
    } else {
        $counts['needs_help']++;
    }
}

/* ==========================================
   LINE CHART DATASETS
========================================== */

$colors = [
    '#3f87b5',
    '#28a745',
    '#f0a500',
    '#dc3545',
    '#7b5fc4',
    '#17a2b8',
    '#e83e8c',
    '#fd7e14',
    '#6c757d',
    '#20c997'
];

$lineDatasets = [];

foreach ($students as $i => $s) {

    $pts = [];

    foreach ($assessments as $a) {

        $g = $grades[$s['id']][$a['id']] ?? null;

        $pts[] = (
            $g !== null &&
            (float) $a['points'] > 0
        )
            ? round(((float) $g / (float) $a['points']) * 100, 1)
            : null;
    }

    $color = $colors[$i % count($colors)];

    $lineDatasets[] = [
        'label' => $s['name'],
        'data' => $pts,
        'borderColor' => $color
    ];
}

/* ==========================================
   BAR CHART DATA
   Class average per assessment
========================================== */

$barData = [];

foreach ($assessments as $a) {

    $total = 0;
    $count = 0;

    foreach ($students as $s) {

        $g = $grades[$s['id']][$a['id']] ?? null;

        if ($g !== null && (float) $a['points'] > 0) {
            $total += ((float) $g / (float) $a['points']) * 100;
            $count++;
        }
    }

    $barData[] = ($count > 0)
        ? round($total / $count, 1)
        : 0;
}

/* ==========================================
   DONUT CHART DATA
========================================== */

$donutData = [
    $counts['excellent'],
    $counts['good'],
    $counts['average'],
    $counts['needs_help'],
    $counts['ungraded']
];

/* ==========================================
   SORT STUDENTS BY PERFORMANCE
========================================== */

$sortedStudents = $students;

usort($sortedStudents, function ($a, $b) use ($studentPerf) {

    $scoreA = $studentPerf[$a['id']]['pct'] ?? -1;
    $scoreB = $studentPerf[$b['id']]['pct'] ?? -1;

    return $scoreB <=> $scoreA;
});

/* ==========================================
   HELPER: VALIDATE PROFILE IMAGE
========================================== */

function getStudentAvatar($profileImage) {

    if (empty($profileImage)) {
        return '<div class="s-avatar"><i class="fa-solid fa-user"></i></div>';
    }

    $filename = basename($profileImage);

    $invalidImages = [
        'default.png',
        'default.jpg',
        'default.jpeg',
        'default.svg',
        'null'
    ];

    if (
        in_array(strtolower($filename), $invalidImages, true) ||
        !is_file(__DIR__ . '/uploads/' . $filename)
    ) {
        return '<div class="s-avatar"><i class="fa-solid fa-user"></i></div>';
    }

    $imagePath = 'uploads/' . rawurlencode($filename);

    return '<img src="' . htmlspecialchars($imagePath, ENT_QUOTES, 'UTF-8') . '" class="s-avatar" alt="">';
}

/* ==========================================
   GENERATE STUDENT LIST HTML
========================================== */

ob_start();

foreach ($sortedStudents as $rank => $s):

    $sp = $studentPerf[$s['id']];
    $pct = $sp['pct'];

    if ($pct === null) {

        $barClass = '';
        $badgeClass = 'ungraded';
        $badgeText = 'No grades yet';
        $bw = 0;

    } elseif ($pct >= 90) {

        $barClass = 'high';
        $badgeClass = 'excellent';
        $badgeText = '⭐ Excellent';
        $bw = $pct;

    } elseif ($pct >= 75) {

        $barClass = 'high';
        $badgeClass = 'good';
        $badgeText = '✅ Good';
        $bw = $pct;

    } elseif ($pct >= 60) {

        $barClass = 'mid';
        $badgeClass = 'average';
        $badgeText = '📈 Average';
        $bw = $pct;

    } else {

        $barClass = 'low';
        $badgeClass = 'needs-help';
        $badgeText = '⚠️ Needs Help';
        $bw = $pct;
    }

    $avatar = getStudentAvatar($s['profile_image']);

    ?>

    <div class="student-perf-row">

        <?php echo $avatar; ?>

        <div class="s-info">
            <div class="s-name">
                #<?php echo $rank + 1; ?>
                <?php echo htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8'); ?>
            </div>

            <div class="s-sub">
                <?php echo (int) $sp['graded']; ?>/<?php echo (int) $sp['total_assessed']; ?> assessed
            </div>
        </div>

        <div class="bar-wrap">
            <div
                class="bar-fill <?php echo $barClass; ?>"
                style="width:0"
                data-width="<?php echo htmlspecialchars((string) $bw, ENT_QUOTES, 'UTF-8'); ?>"
            ></div>
        </div>

        <div class="score-pct">
            <?php echo $pct !== null ? htmlspecialchars((string) $pct, ENT_QUOTES, 'UTF-8') . '%' : '—'; ?>
        </div>

        <span class="status-badge <?php echo $badgeClass; ?>">
            <?php echo htmlspecialchars($badgeText, ENT_QUOTES, 'UTF-8'); ?>
        </span>

    </div>

<?php
endforeach;

$studentListHtml = ob_get_clean();

/* ==========================================
   RETURN JSON RESPONSE
========================================== */

echo json_encode([
    'success' => true,
    'counts' => $counts,
    'lineDatasets' => $lineDatasets,
    'barData' => $barData,
    'donutData' => $donutData,
    'studentListHtml' => $studentListHtml
]);

exit;
?>