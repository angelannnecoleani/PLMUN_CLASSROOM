<?php
session_start();
include 'database.php';
mysqli_report(MYSQLI_REPORT_OFF);

function h($s){ return htmlspecialchars((string)$s); }
function ok($t){  echo "<p class='ok'>✅ $t</p>"; }
function bad($t){ echo "<p class='bad'>❌ $t</p>"; }
function note($t){ echo "<p class='note'>ℹ️ $t</p>"; }
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Notification check</title>
<style>
body{font-family:Segoe UI,Arial,sans-serif;max-width:760px;margin:30px auto;padding:0 16px;color:#222}
h2{margin-top:28px;border-bottom:1px solid #ddd;padding-bottom:6px}
p{margin:6px 0}
.ok{color:#198754}.bad{color:#dc3545;font-weight:600}.note{color:#555}
table{border-collapse:collapse;margin:8px 0}td,th{border:1px solid #ddd;padding:6px 10px;font-size:14px}
a.btn{display:inline-block;background:#3f87b5;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none;margin-top:8px}
</style>
</head>
<body>
<h1>Notification check</h1>

<?php
/* 1. LOGIN */
echo "<h2>1. Login</h2>";
if(!isset($_SESSION['user_id'])){
    bad("You are not logged in. Log in first (as the STUDENT), then open this page again.");
    echo "</body></html>";
    exit;
}
$uid = (int)$_SESSION['user_id'];
$u = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE id = $uid"));
ok("Logged in as user id <b>$uid</b> (" . h($u['name'] ?? '?') . ")");

/* 2. DATABASE TABLE */
echo "<h2>2. notifications table</h2>";
$exists = mysqli_query($conn, "SHOW TABLES LIKE 'notifications'");
if(!$exists || mysqli_num_rows($exists) === 0){
    note("Table not found. Creating it now...");
    $created = mysqli_query($conn, "CREATE TABLE notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        class_id INT NULL,
        type VARCHAR(30) NOT NULL,
        message VARCHAR(255) NOT NULL,
        link VARCHAR(255) NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_read (user_id, is_read, created_at)
    )");
    if($created) ok("Table created."); else bad("Could not create table: " . h(mysqli_error($conn)));
} else {
    ok("Table exists.");
}

/* 3. FILES */
echo "<h2>3. Files in this folder</h2>";
$need = ['notification_helper.php','fetch_notifications.php','mark_notifications_read.php','notifications.js','notifications.css'];
foreach($need as $f){
    if(file_exists(__DIR__ . '/' . $f)) ok($f); else bad("$f is MISSING. Copy it into this folder.");
}

/* 4. DO THE PAGES USE THE NEW CODE? */
echo "<h2>4. Is the new code pasted in your pages?</h2>";
$checks = [
    'student_dashboard.php'  => ['notifications.js', 'notifBadge'],
    'teacher_dashboard.php'  => ['notifications.js', 'notifBadge'],
    'save_announcement.php'  => ['notify_class_students'],
    'save_assessment.php'    => ['notify_class_students'],
    'save_material.php'      => ['notify_class_students'],
];
foreach($checks as $file => $words){
    $path = __DIR__ . '/' . $file;
    if(!file_exists($path)){ note("$file not found here."); continue; }
    $code = file_get_contents($path);
    $missing = [];
    foreach($words as $w) if(strpos($code, $w) === false) $missing[] = $w;
    if($missing) bad("$file does NOT contain: " . h(implode(', ', $missing)) . " (the old version is still there, replace it)");
    else ok("$file has the notification code");
}

/* 5. CLASSES */
echo "<h2>5. Your classes</h2>";
$q = mysqli_query($conn, "SELECT c.id, c.class_name, cs.archived
                          FROM class_students cs JOIN classes c ON c.id = cs.class_id
                          WHERE cs.student_id = $uid");
if(!$q){
    bad("Query error: " . h(mysqli_error($conn)));
} elseif(mysqli_num_rows($q) === 0){
    note("This account has not joined any class as a student. (If you are logged in as the teacher, that is fine. Log in as the student to test.)");
} else {
    echo "<table><tr><th>Class id</th><th>Class</th><th>archived</th></tr>";
    while($r = mysqli_fetch_assoc($q)){
        echo "<tr><td>{$r['id']}</td><td>" . h($r['class_name']) . "</td><td>{$r['archived']}</td></tr>";
    }
    echo "</table>";
    note("Only classes with archived = 0 receive notifications.");
}

/* 6. TRIGGERS */
echo "<h2>6. Triggers (can cause double notifications)</h2>";
$t = mysqli_query($conn, "SHOW TRIGGERS");
$names = [];
if($t) while($r = mysqli_fetch_assoc($t)) $names[] = $r['Trigger'];
if($names){
    note("Found: " . h(implode(', ', $names)));
    note("If you also use the PHP files above, drop these triggers to avoid duplicates.");
} else {
    ok("No triggers (good, PHP files do the notifying).");
}

/* 7. TEST NOTIFICATION */
echo "<h2>7. Test</h2>";
if(isset($_GET['test'])){
    $msg  = 'Test notification. If you see this on the bell, it works!';
    $link = 'student_dashboard.php';
    $type = 'announcement';
    $stmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id, class_id, type, message, link) VALUES (?, NULL, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "isss", $uid, $type, $msg, $link);
    if(mysqli_stmt_execute($stmt)) ok("Test notification added. Now open the student dashboard and look at the bell.");
    else bad("Insert failed: " . h(mysqli_error($conn)));
}
echo "<a class='btn' href='?test=1'>Send me a test notification</a> ";
echo "<a class='btn' href='student_dashboard.php' style='background:#555'>Open student dashboard</a>";

/* 8. WHAT IS IN THE TABLE */
echo "<h2>8. Latest notifications for you</h2>";
$tot = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM notifications"))['c'] ?? 0;
$mine = mysqli_query($conn, "SELECT id, type, message, is_read, created_at FROM notifications WHERE user_id = $uid ORDER BY id DESC LIMIT 5");
note("Total rows in notifications table: <b>$tot</b>");
if($mine && mysqli_num_rows($mine) > 0){
    echo "<table><tr><th>id</th><th>type</th><th>message</th><th>read</th><th>time</th></tr>";
    while($r = mysqli_fetch_assoc($mine)){
        echo "<tr><td>{$r['id']}</td><td>" . h($r['type']) . "</td><td>" . h($r['message']) . "</td><td>{$r['is_read']}</td><td>{$r['created_at']}</td></tr>";
    }
    echo "</table>";
} else {
    bad("No notifications for this account yet.");
}
?>
<p class="note" style="margin-top:30px">Delete notif_check.php when you are done.</p>
</body>
</html>