
<?php
session_start();
require_once 'database.php';

/* ==========================================
   LOGIN AND ROLE VALIDATION
========================================== */

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

/* ==========================================
   VALIDATE ANNOUNCEMENT ID
========================================== */

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    die("Invalid announcement request.");
}

/* ==========================================
   FETCH ANNOUNCEMENT AND VERIFY ACCESS
========================================== */

$stmt = mysqli_prepare($conn, "
    SELECT a.*
    FROM announcements a
    INNER JOIN classes c ON a.class_id = c.id
    WHERE a.id = ?
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

if (!$stmt) {
    die("Database error.");
}

mysqli_stmt_bind_param($stmt, "iii", $id, $teacher_id, $teacher_id);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$announcement = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);

if (!$announcement) {
    die("Announcement not found or you do not have access to it.");
}

$class_id = (int) $announcement['class_id'];

/* ==========================================
   CSRF TOKEN
========================================== */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';

/* ==========================================
   HANDLE FORM SUBMISSION
========================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        die("Invalid request token. Please reload the page and try again.");
    }

    $content = trim($_POST['content'] ?? '');
    $link = trim($_POST['link'] ?? '');
    $remove_image = isset($_POST['remove_image']) &&
                    $_POST['remove_image'] === '1';

    if ($content === '') {
        $error = "Announcement content cannot be empty.";
    }

    /* Validate optional link */

    if ($error === '' && $link !== '') {
        if (
            !filter_var($link, FILTER_VALIDATE_URL) ||
            !in_array(strtolower(parse_url($link, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)
        ) {
            $error = "Please enter a valid HTTP or HTTPS link.";
        }
    }

    /* Keep the current database image by default */

    $old_image = $announcement['image'] ?? '';
    $image = $old_image;
    $new_uploaded_file = '';

    /* Remove current image if requested */

    if ($remove_image) {
        $image = '';
    }

    /* ======================================
       HANDLE NEW IMAGE UPLOAD
    ====================================== */

    if (
        isset($_FILES['image']) &&
        $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE &&
        $error === ''
    ) {

        if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {

            $error = "Image upload failed. Please try again.";

        } elseif ($_FILES['image']['size'] > 5 * 1024 * 1024) {

            $error = "Image must not exceed 5 MB.";

        } else {

            $tmp_name = $_FILES['image']['tmp_name'];

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($tmp_name);

            $allowed_types = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/gif'  => 'gif',
                'image/webp' => 'webp'
            ];

            if (!isset($allowed_types[$mime]) || !getimagesize($tmp_name)) {

                $error = "Only JPG, JPEG, PNG, GIF, and WEBP images are allowed.";

            } else {

                $upload_dir = __DIR__ . '/uploads/';

                if (!is_dir($upload_dir)) {
                    if (!mkdir($upload_dir, 0755, true)) {
                        $error = "Unable to create upload directory.";
                    }
                }

                if ($error === '' && !is_writable($upload_dir)) {
                    $error = "Upload directory is not writable.";
                }

                if ($error === '') {

                    $filename = bin2hex(random_bytes(16)) . '.' . $allowed_types[$mime];

                    $destination = $upload_dir . $filename;

                    if (move_uploaded_file($tmp_name, $destination)) {
                        $image = $filename;
                        $new_uploaded_file = $destination;
                    } else {
                        $error = "Unable to save uploaded image.";
                    }
                }
            }
        }
    }

    /* ======================================
       UPDATE ANNOUNCEMENT
    ====================================== */

    if ($error === '') {

        $update = mysqli_prepare($conn, "
            UPDATE announcements
            SET content = ?, link = ?, image = ?
            WHERE id = ?
        ");

        if (!$update) {

            $error = "Unable to prepare announcement update.";

        } else {

            mysqli_stmt_bind_param(
                $update,
                "sssi",
                $content,
                $link,
                $image,
                $id
            );

            if (mysqli_stmt_execute($update)) {

                mysqli_stmt_close($update);

                /* Delete old image only after successful update */

                if (
                    $old_image !== '' &&
                    $old_image !== $image
                ) {
                    $old_path = __DIR__ . '/uploads/' . basename($old_image);

                    if (is_file($old_path)) {
                        unlink($old_path);
                    }
                }

                header("Location: teacher_class.php?id=" . $class_id);
                exit;

            } else {

                $error = "Failed to update announcement.";
                mysqli_stmt_close($update);
            }
        }

        /* Remove newly uploaded image if update failed */

        if ($error !== '' && $new_uploaded_file !== '' && is_file($new_uploaded_file)) {
            unlink($new_uploaded_file);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Announcement</title>

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
            min-height: 130px;
            resize: vertical;
        }

        .existing-image {
            width: 100%;
            max-width: 300px;
            border-radius: 8px;
            margin-bottom: 10px;
            display: block;
        }

        .remove-image-btn {
            font-size: 13px;
            color: #dc3545;
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
            margin-bottom: 12px;
        }

        .remove-image-btn:hover {
            text-decoration: underline;
        }

        .btn-cancel {
            background: #f0f0f0;
            color: #333;
            border: none;
            border-radius: 8px;
            padding: 10px 24px;
            font-weight: 600;
            font-size: 15px;
            text-decoration: none;
            transition: background .2s;
        }

        .btn-cancel:hover {
            background: #e0e0e0;
            color: #333;
        }

        .btn-save {
            background: #3f87b5;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 28px;
            font-weight: 600;
            font-size: 15px;
            transition: background .2s;
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
        Edit Announcement
    </h4>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger">
            <?php echo e($error); ?>
        </div>
    <?php endif; ?>

    <form
        action="edit_announcement.php?id=<?php echo $id; ?>"
        method="POST"
        enctype="multipart/form-data"
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?php echo e($_SESSION['csrf_token']); ?>"
        >

        <!-- ANNOUNCEMENT CONTENT -->

        <div class="mb-4">
            <label class="form-label">Announcement</label>

            <textarea
                name="content"
                class="form-control"
                required
            ><?php echo e($_POST['content'] ?? $announcement['content']); ?></textarea>
        </div>

        <!-- LINK -->

        <div class="mb-4">
            <label class="form-label">
                Link <span class="text-muted fw-normal">(optional)</span>
            </label>

            <input
                type="url"
                name="link"
                class="form-control"
                value="<?php echo e($_POST['link'] ?? $announcement['link']); ?>"
                placeholder="https://..."
            >
        </div>

        <!-- EXISTING IMAGE -->

        <?php if (!empty($announcement['image']) && empty($_POST['remove_image'])): ?>

            <div class="mb-3" id="existingImageBox">

                <label class="form-label">Current Image</label>

                <img
                    src="uploads/<?php echo rawurlencode(basename($announcement['image'])); ?>"
                    class="existing-image"
                    alt="Current image"
                >

                <button
                    type="button"
                    class="remove-image-btn"
                    onclick="removeImage()"
                >
                    <i class="fa-solid fa-trash me-1"></i>
                    Remove image
                </button>

            </div>

        <?php endif; ?>

        <input
            type="hidden"
            name="remove_image"
            id="removeImageInput"
            value="<?php echo !empty($_POST['remove_image']) ? '1' : '0'; ?>"
        >

        <!-- NEW IMAGE -->

        <div class="mb-4">
            <label class="form-label">
                Replace or Attach Image
                <span class="text-muted fw-normal">(optional)</span>
            </label>

            <input
                type="file"
                name="image"
                class="form-control"
                accept=".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/gif,image/webp"
            >

            <small class="text-muted">
                Accepted formats: JPG, PNG, GIF, WEBP. Maximum size: 5 MB.
            </small>
        </div>

        <!-- BUTTONS -->

        <div class="d-flex justify-content-end gap-3 mt-2">

            <a
                href="teacher_class.php?id=<?php echo $class_id; ?>"
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
function removeImage() {
    const imageBox = document.getElementById('existingImageBox');
    const removeInput = document.getElementById('removeImageInput');

    if (imageBox) {
        imageBox.style.display = 'none';
    }

    removeInput.value = '1';
}
</script>

</body>
</html>
