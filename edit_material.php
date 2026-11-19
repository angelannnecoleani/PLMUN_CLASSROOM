
<?php
session_start();
require_once 'database.php';
require_once 'auth_helpers.php';

/* =========================================
   LOGIN AND ROLE CHECK
========================================= */
if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$role = $_SESSION['role'] ?? '';
$user_id = (int) $_SESSION['user_id'];

if (!in_array($role, ['teacher', 'program_chair'], true)) {
    http_response_code(403);
    exit("You do not have permission to access this page.");
}

/* =========================================
   HELPER FUNCTIONS
========================================= */
function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function redirectToMaterials($class_id) {
    header("Location: teacher_material.php?id=" . (int)$class_id);
    exit;
}

/* =========================================
   GET MATERIAL ID
========================================= */
$id = 0;

if (isset($_POST['id'])) {
    $id = (int) $_POST['id'];
} elseif (isset($_GET['id'])) {
    $id = (int) $_GET['id'];
}

if ($id <= 0) {
    exit("Invalid material request.");
}

/* =========================================
   FETCH MATERIAL
========================================= */
$stmt = mysqli_prepare(
    $conn,
    "SELECT * FROM materials WHERE id = ? LIMIT 1"
);

if (!$stmt) {
    exit("Database error: " . e(mysqli_error($conn)));
}

mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$material = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);

if (!$material) {
    http_response_code(404);
    exit("Material not found.");
}

$class_id = (int) $material['class_id'];

/* =========================================
   VERIFY CLASS ACCESS
========================================= */
$class = false;

if ($role === 'teacher') {

    // Primary instructor OR additional assigned instructor.
    $accessStmt = mysqli_prepare(
        $conn,
        "SELECT c.*
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
         LIMIT 1"
    );

    if (!$accessStmt) {
        exit("Database error: " . e(mysqli_error($conn)));
    }

    mysqli_stmt_bind_param(
        $accessStmt,
        "iii",
        $class_id,
        $user_id,
        $user_id
    );

    mysqli_stmt_execute($accessStmt);
    $accessResult = mysqli_stmt_get_result($accessStmt);
    $class = mysqli_fetch_assoc($accessResult);
    mysqli_stmt_close($accessStmt);

} elseif ($role === 'program_chair') {

    // Preserve existing Program Chair access.
    $class = getClassAccess(
        $conn,
        $class_id,
        $user_id,
        $role
    );
}

if (!$class) {
    http_response_code(403);
    exit("You do not have permission to edit materials in this class.");
}

/* =========================================
   CSRF TOKEN
========================================= */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* =========================================
   HANDLE FORM SUBMISSION
========================================= */
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Validate CSRF token.
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        empty($submittedToken) ||
        !hash_equals($_SESSION['csrf_token'], $submittedToken)
    ) {
        exit("Invalid form request. Please go back and try again.");
    }

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $link = trim($_POST['link'] ?? '');

    if ($title === '') {
        $error = "Please enter a material title.";
    }

    // Preserve the existing file unless the user removes it.
    $oldFile = basename((string)($material['file'] ?? ''));
    $file = $oldFile;

    if (isset($_POST['existing_file']) && trim($_POST['existing_file']) === '') {
        $file = '';
    }

    // Validate link if provided.
    if ($error === '' && $link !== '' && !filter_var($link, FILTER_VALIDATE_URL)) {
        $error = "Please enter a valid URL.";
    }

    $newUploadPath = '';
    $uploadDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';

    /* =====================================
       HANDLE NEW FILE UPLOAD
    ===================================== */
    if (
        $error === '' &&
        isset($_FILES['file']) &&
        $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE
    ) {
        if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $error = "The file could not be uploaded. Please try again.";
        } else {

            $allowed = [
                'pdf', 'doc', 'docx', 'ppt', 'pptx',
                'xls', 'xlsx', 'jpg', 'jpeg', 'png',
                'gif', 'webp', 'mp4', 'mov', 'avi'
            ];

            $original = basename($_FILES['file']['name']);
            $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));

            if (!in_array($extension, $allowed, true)) {
                $error = "This file type is not allowed.";
            } elseif ($_FILES['file']['size'] > 20 * 1024 * 1024) {
                $error = "File too large. Maximum file size is 20MB.";
            } elseif (!is_uploaded_file($_FILES['file']['tmp_name'])) {
                $error = "Invalid uploaded file.";
            } else {

                if (!is_dir($uploadDirectory)) {
                    if (!mkdir($uploadDirectory, 0755, true)) {
                        $error = "Unable to create the uploads directory.";
                    }
                }

                if ($error === '') {
                    $newFileName = bin2hex(random_bytes(16)) . '.' . $extension;
                    $newUploadPath = $uploadDirectory . DIRECTORY_SEPARATOR . $newFileName;

                    if (move_uploaded_file($_FILES['file']['tmp_name'], $newUploadPath)) {
                        $file = $newFileName;
                    } else {
                        $error = "Failed to save the uploaded file.";
                    }
                }
            }
        }
    }

    /* =====================================
       UPDATE MATERIAL
    ===================================== */
    if ($error === '') {

        $updateStmt = mysqli_prepare(
            $conn,
            "UPDATE materials
             SET title = ?,
                 description = ?,
                 file = ?,
                 link = ?
             WHERE id = ? AND class_id = ?"
        );

        if (!$updateStmt) {
            if ($newUploadPath !== '' && file_exists($newUploadPath)) {
                unlink($newUploadPath);
            }

            $error = "Database error: " . mysqli_error($conn);

        } else {

            mysqli_stmt_bind_param(
                $updateStmt,
                "ssssii",
                $title,
                $description,
                $file,
                $link,
                $id,
                $class_id
            );

            if (mysqli_stmt_execute($updateStmt)) {

                mysqli_stmt_close($updateStmt);

                // Remove the previous file only after the database update succeeds.
                if (
                    $oldFile !== '' &&
                    $file !== $oldFile
                ) {
                    $oldPath = $uploadDirectory . DIRECTORY_SEPARATOR . $oldFile;

                    if (is_file($oldPath)) {
                        unlink($oldPath);
                    }
                }

                redirectToMaterials($class_id);

            } else {
                $error = "Unable to update material: " . mysqli_stmt_error($updateStmt);
                mysqli_stmt_close($updateStmt);

                // Remove newly uploaded file if the database update failed.
                if ($newUploadPath !== '' && file_exists($newUploadPath)) {
                    unlink($newUploadPath);
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Material</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="teacher_class.css">

    <style>
        body {
            background: #f5f6f8;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 30px 15px;
        }

        .edit-card {
            background: #fff;
            border-radius: 14px;
            padding: 36px 32px;
            width: 100%;
            max-width: 620px;
            box-shadow: 0 4px 20px rgba(0,0,0,.1);
        }

        .edit-card h4 {
            font-weight: 700;
            margin-bottom: 24px;
            color: #1a2d3e;
        }

        .form-label {
            font-weight: 600;
            font-size: 14px;
            color: #444;
        }

        .form-control {
            border-radius: 8px;
            border: 1px solid #dde4ec;
            font-size: 15px;
            padding: 10px 14px;
        }

        .form-control:focus {
            border-color: #3f87b5;
            box-shadow: 0 0 0 3px rgba(63,135,181,.15);
        }

        textarea.form-control {
            min-height: 110px;
            resize: vertical;
        }

        .existing-file-box {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #f0f6fb;
            border: 1px solid #cde0ef;
            border-radius: 8px;
            padding: 10px 14px;
            margin-bottom: 10px;
        }

        .existing-file-box i {
            font-size: 22px;
            color: #3f87b5;
        }

        .existing-file-box span {
            font-size: 14px;
            color: #333;
            word-break: break-all;
            flex: 1;
        }

        .remove-file-btn {
            font-size: 13px;
            color: #dc3545;
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
        }

        .remove-file-btn:hover {
            text-decoration: underline;
        }

        .btn-cancel {
            height: 44px;
            padding: 0 24px;
            border-radius: 8px;
            border: 1px solid #dde4ec;
            background: #fff;
            color: #555;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }

        .btn-cancel:hover {
            background: #f5f5f5;
            color: #333;
        }

        .btn-save {
            height: 44px;
            padding: 0 28px;
            border-radius: 8px;
            border: none;
            background: #3f87b5;
            color: #fff;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-save:hover {
            background: #2f76a7;
        }
    </style>
</head>
<body>

<div class="edit-card">

    <h4>
        <i class="fa-solid fa-pen me-2 text-primary"></i>
        Edit Material
    </h4>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger">
            <?php echo e($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">

        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">

        <!-- TITLE -->
        <div class="mb-4">
            <label class="form-label">Title</label>
            <input
                type="text"
                name="title"
                class="form-control"
                value="<?php echo e($_POST['title'] ?? $material['title']); ?>"
                required
            >
        </div>

        <!-- DESCRIPTION -->
        <div class="mb-4">
            <label class="form-label">
                Description
                <span class="text-muted fw-normal">(optional)</span>
            </label>

            <textarea name="description" class="form-control"><?php
                echo e($_POST['description'] ?? $material['description']);
            ?></textarea>
        </div>

        <!-- EXISTING FILE -->
        <?php if (!empty($material['file'])): ?>
            <div class="mb-3" id="existingFileBox">
                <label class="form-label">Current File</label>

                <div class="existing-file-box">
                    <i class="fa-regular fa-file"></i>

                    <span><?php echo e($material['file']); ?></span>

                    <button
                        type="button"
                        class="remove-file-btn"
                        onclick="removeFile()"
                    >
                        <i class="fa-solid fa-trash me-1"></i>
                        Remove
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <input
            type="hidden"
            name="existing_file"
            id="existingFileInput"
            value="<?php echo e($material['file']); ?>"
        >

        <!-- NEW FILE UPLOAD -->
        <div class="mb-4">
            <label class="form-label">
                <?php echo !empty($material['file']) ? 'Replace File' : 'Attach File'; ?>
                <span class="text-muted fw-normal">(optional)</span>
            </label>

            <input
                type="file"
                name="file"
                class="form-control"
                accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.webp,.mp4,.mov,.avi"
            >

            <small class="text-muted">Maximum file size: 20MB.</small>
        </div>

        <!-- LINK -->
        <div class="mb-4">
            <label class="form-label">
                Link
                <span class="text-muted fw-normal">(optional)</span>
            </label>

            <input
                type="url"
                name="link"
                class="form-control"
                value="<?php echo e($_POST['link'] ?? $material['link']); ?>"
                placeholder="https://..."
            >
        </div>

        <!-- BUTTONS -->
        <div class="d-flex justify-content-end gap-3 mt-2">

            <a
                href="teacher_material.php?id=<?php echo $class_id; ?>"
                class="btn-cancel"
            >
                Cancel
            </a>

            <button type="submit" class="btn-save">
                <i class="fa-solid fa-floppy-disk me-2"></i>
                Save Changes
            </button>

        </div>

    </form>
</div>

<script>
function removeFile() {
    const fileBox = document.getElementById('existingFileBox');
    const fileInput = document.getElementById('existingFileInput');

    if (fileBox) {
        fileBox.style.display = 'none';
    }

    if (fileInput) {
        fileInput.value = '';
    }
}
</script>

</body>
</html>
