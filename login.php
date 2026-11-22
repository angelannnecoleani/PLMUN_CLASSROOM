<?php
session_start();

require_once 'database.php';
require_once 'activity_log.php';

/* =========================================================
   HELPER: RECORD LOGIN ATTEMPTS
========================================================= */

function recordLoginAttempt(mysqli $conn, string $email, bool $success): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

    $stmt = $conn->prepare("
        INSERT INTO security_login_attempts
        (identifier, ip_address, success)
        VALUES (?, ?, ?)
    ");

    if ($stmt) {
        $status = $success ? 1 : 0;

        $stmt->bind_param("ssi", $email, $ip, $status);
        $stmt->execute();
        $stmt->close();
    }
}

/* =========================================================
   GET LOGIN DATA
========================================================= */

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

/* =========================================================
   VALIDATION
========================================================= */

if ($email === '' || $password === '') {
    echo "<script>
        alert('Please enter your email and password.');
        window.location='index.html';
    </script>";
    exit();
}

/* =========================================================
   FIND USER USING PREPARED STATEMENT
========================================================= */

$stmt = $conn->prepare("
    SELECT id, name, email, password, role
    FROM users
    WHERE email = ?
    LIMIT 1
");

if (!$stmt) {
    error_log("Login query preparation failed: " . $conn->error);
    die("Unable to process login. Please try again later.");
}

$stmt->bind_param("s", $email);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();

$stmt->close();

/* =========================================================
   VERIFY USER AND PASSWORD
========================================================= */

if (!$user || !password_verify($password, $user['password'])) {

    // Record failed login
    recordLoginAttempt($conn, $email, false);

    echo "<script>
        alert('Invalid email or password.');
        window.location='index.html';
    </script>";

    exit();
}

/* =========================================================
   CHECK VALID USER ROLE
========================================================= */

$allowedRoles = [
    'admin',
    'director',
    'program_chair',
    'teacher',
    'student'
];

if (!in_array($user['role'], $allowedRoles, true)) {

    recordLoginAttempt($conn, $email, false);

    echo "<script>
        alert('Invalid user role. Please contact the administrator.');
        window.location='index.html';
    </script>";

    exit();
}

/* =========================================================
   REGENERATE SESSION ID
========================================================= */

session_regenerate_id(true);

/* =========================================================
   CREATE SESSION
========================================================= */

$_SESSION['user_id'] = (int)$user['id'];
$_SESSION['name'] = $user['name'];
$_SESSION['role'] = $user['role'];

/* =========================================================
   RECORD SUCCESSFUL LOGIN
========================================================= */

recordLoginAttempt($conn, $email, true);

/* =========================================================
   ACTIVITY AUDIT LOG
========================================================= */

addActivityLog(
    $conn,
    (int)$user['id'],
    $user['name'],
    $user['role'],
    'Login',
    'User successfully logged into the PLMUN Classroom system.'
);

/* =========================================================
   REDIRECT ACCORDING TO ROLE
========================================================= */

switch ($user['role']) {

    case 'admin':
        header("Location: admin_dashboard.php");
        exit();

    case 'director':
        header("Location: director_dashboard.php");
        exit();

    case 'program_chair':
        header("Location: program_chair_dashboard.php");
        exit();

    case 'teacher':
        header("Location: teacher_dashboard.php");
        exit();

    case 'student':
        header("Location: student_dashboard.php");
        exit();

    default:
        session_unset();
        session_destroy();

        header("Location: index.html");
        exit();
}
?>
