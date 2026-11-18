
<?php

/* =========================================================
   CLASS ACCESS AUTHORIZATION

   TEACHER:
   Can access only classes assigned to their teacher_id.

   PROGRAM CHAIR:
   Can access classes belonging to programs assigned
   through program_chair_assignments.
========================================================= */

function getClassAccess(
    mysqli $conn,
    int $classId,
    int $userId,
    string $role
): ?array {

    /* ================= TEACHER ACCESS ================= */

    if ($role === 'teacher') {

        $stmt = mysqli_prepare(
            $conn,
            "SELECT *
             FROM classes
             WHERE id = ?
               AND teacher_id = ?
             LIMIT 1"
        );

        if (!$stmt) {
            return null;
        }

        mysqli_stmt_bind_param(
            $stmt,
            "ii",
            $classId,
            $userId
        );

    }

    /* ================= PROGRAM CHAIR ACCESS ================= */

    elseif ($role === 'program_chair') {

        $stmt = mysqli_prepare(
            $conn,
            "SELECT
                c.*,
                p.program_name AS assigned_program,
                p.college_name AS assigned_college
             FROM classes c
             INNER JOIN programs p
                ON TRIM(c.program) = TRIM(p.program_name)
             INNER JOIN program_chair_assignments pca
                ON pca.program_id = p.id
             INNER JOIN users u
                ON u.id = pca.user_id
             WHERE c.id = ?
               AND pca.user_id = ?
               AND u.role = 'program_chair'
             LIMIT 1"
        );

        if (!$stmt) {
            return null;
        }

        mysqli_stmt_bind_param(
            $stmt,
            "ii",
            $classId,
            $userId
        );

    }

    /* ================= OTHER ROLES ================= */

    else {
        return null;
    }

    /* ================= EXECUTE QUERY ================= */

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return null;
    }

    $result = mysqli_stmt_get_result($stmt);

    $row = $result
        ? mysqli_fetch_assoc($result)
        : null;

    mysqli_stmt_close($stmt);

    return $row ?: null;
}


/* =========================================================
   CLASS HOME URL

   Returns the correct class page based on the user's role.
========================================================= */

function classHomeUrl(
    string $role,
    int $classId
): string {

    if ($role === 'program_chair') {
        return "program_chair_class.php?id=" . $classId;
    }

    return "teacher_class.php?id=" . $classId;
}


/* =========================================================
   CHECK IF USER CAN ACCESS A CLASS

   Returns true if getClassAccess() finds an authorized class.
========================================================= */

function canAccessClass(
    mysqli $conn,
    int $classId,
    int $userId,
    string $role
): bool {

    return getClassAccess(
        $conn,
        $classId,
        $userId,
        $role
    ) !== null;
}

?>
