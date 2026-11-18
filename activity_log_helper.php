
<?php

if (!function_exists('addActivityLog')) {

    function addActivityLog($conn, $action, $description = '') {

        // Start session if it has not started.
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Check if a user is logged in.
        if (empty($_SESSION['user_id'])) {
            return false;
        }

        $user_id = (int) $_SESSION['user_id'];

        // Validate activity information.
        $action = trim((string) $action);
        $description = trim((string) $description);

        if ($action === '') {
            return false;
        }

        // Retrieve the logged-in user's name and role.
        $userQuery = mysqli_prepare(
            $conn,
            "SELECT name, role
             FROM users
             WHERE id = ?
             LIMIT 1"
        );

        if (!$userQuery) {
            error_log("Activity log user query failed: " . mysqli_error($conn));
            return false;
        }

        mysqli_stmt_bind_param($userQuery, "i", $user_id);
        mysqli_stmt_execute($userQuery);

        $userResult = mysqli_stmt_get_result($userQuery);
        $user = mysqli_fetch_assoc($userResult);

        mysqli_stmt_close($userQuery);

        if (!$user) {
            return false;
        }

        $user_name = $user['name'];
        $user_role = $user['role'];
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

        // Record the activity.
        $logQuery = mysqli_prepare(
            $conn,
            "INSERT INTO activity_logs
            (
                user_id,
                user_name,
                user_role,
                action,
                description,
                ip_address
            )
            VALUES (?, ?, ?, ?, ?, ?)"
        );

        if (!$logQuery) {
            error_log("Activity log insert preparation failed: " . mysqli_error($conn));
            return false;
        }

        mysqli_stmt_bind_param(
            $logQuery,
            "isssss",
            $user_id,
            $user_name,
            $user_role,
            $action,
            $description,
            $ip_address
        );

        $success = mysqli_stmt_execute($logQuery);

        if (!$success) {
            error_log("Activity log insert failed: " . mysqli_stmt_error($logQuery));
        }

        mysqli_stmt_close($logQuery);

        return $success;
    }
}
