<?php
session_start();

require_once 'database.php';
require_once 'activity_log.php';

/* =========================================================
   GET CURRENT USER SESSION
========================================================= */

$userId = (int)($_SESSION['user_id'] ?? 0);
$userName = $_SESSION['name'] ?? 'Unknown User';
$userRole = $_SESSION['role'] ?? 'unknown';

/* =========================================================
   RECORD LOGOUT ACTIVITY
========================================================= */

if ($userId > 0) {

    addActivityLog(
        $conn,
        $userId,
        $userName,
        $userRole,
        'Logout',
        'User successfully logged out of the PLMUN Classroom system.'
    );

}

/* =========================================================
   DESTROY SESSION
========================================================= */

$_SESSION = [];

/* Remove session cookie */
if (ini_get("session.use_cookies")) {

    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

/* =========================================================
   REDIRECT TO LOGIN PAGE
========================================================= */

header("Location: index.html");
exit();

?>
