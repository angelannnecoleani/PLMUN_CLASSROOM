<?php

function addActivityLog(
    $conn,
    $user_id,
    $user_name,
    $user_role,
    $action,
    $description
) {

    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

    $stmt = mysqli_prepare(
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

    if (!$stmt) {
        return false;
    }

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

    $result = mysqli_stmt_execute($stmt);

    mysqli_stmt_close($stmt);

    return $result;
}
?>