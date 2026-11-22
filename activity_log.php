
<?php

function addActivityLog(
    $conn,
    $user_id,
    $user_name,
    $user_role,
    $action,
    $description
) {
    // Check database connection
    if (!$conn || !($conn instanceof mysqli)) {
        error_log("Activity Log Error: Invalid database connection.");
        return false;
    }

    // Get user's IP address
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

    // Prepare activity log query
    $sql = "INSERT INTO activity_logs
            (
                user_id,
                user_name,
                user_role,
                action,
                description,
                ip_address
            )
            VALUES (?, ?, ?, ?, ?, ?)";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        error_log("Activity Log Prepare Error: " . mysqli_error($conn));
        return false;
    }

    // Bind parameters
    mysqli_stmt_bind_param(
        $stmt,
        "isssss",
        $user_id,
        $user_name,
        $user_role,
        $action,
        $description,
        $ip_address
    );

    // Execute query
    $result = mysqli_stmt_execute($stmt);

    if (!$result) {
        error_log("Activity Log Execute Error: " . mysqli_stmt_error($stmt));
    }

    mysqli_stmt_close($stmt);

    return $result;
}

?>
