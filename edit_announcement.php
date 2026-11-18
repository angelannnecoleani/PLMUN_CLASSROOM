<?php
session_start();
include 'database.php';

if (!isset($_GET['id'])) {
    die("Invalid request.");
}

$id = intval($_GET['id']);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content = mysqli_real_escape_string($conn, $_POST['content']);
    $link = mysqli_real_escape_string($conn, $_POST['link']);

    // Handle new image upload
    if (!empty($_FILES['image']['name'])) {
        $image = basename($_FILES['image']['name']);
        move_uploaded_file($_FILES['image']['tmp_name'], "uploads/" . $image);
    } else {
        // Keep existing image
        $image = mysqli_real_escape_string($conn, $_POST['existing_image']);
    }

    mysqli_query($conn, "
        UPDATE announcements
        SET content='$content', link='$link', image='$image'
        WHERE id='$id'
    ");

    // Get class_id to redirect back
    $result = mysqli_query($conn, "SELECT class_id FROM announcements WHERE id='$id'");
    $row = mysqli_fetch_assoc($result);

    header("Location: teacher_class.php?id=" . $row['class_id']);
    exit;
}

// Fetch existing announcement
$result = mysqli_query($conn, "SELECT * FROM announcements WHERE id='$id'");
$announcement = mysqli_fetch_assoc($result);

if (!$announcement) {
    die("Announcement not found.");
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

    <h4><i class="fa-solid fa-pen me-2 text-primary"></i>Edit Announcement</h4>

    <form action="edit_announcement.php?id=<?php echo $id; ?>" method="POST" enctype="multipart/form-data">

        <!-- CONTENT -->
        <div class="mb-4">
            <label class="form-label">Announcement</label>
            <textarea name="content" class="form-control"><?php echo htmlspecialchars($announcement['content']); ?></textarea>
        </div>

        <!-- LINK -->
        <div class="mb-4">
            <label class="form-label">Link <span class="text-muted fw-normal">(optional)</span></label>
            <input type="url" name="link" class="form-control"
                   value="<?php echo htmlspecialchars($announcement['link']); ?>"
                   placeholder="https://...">
        </div>

        <!-- EXISTING IMAGE -->
        <?php if (!empty($announcement['image'])): ?>
        <div class="mb-3" id="existingImageBox">
            <label class="form-label">Current Image</label>
            <img src="uploads/<?php echo htmlspecialchars($announcement['image']); ?>"
                 class="existing-image" alt="Current image">
            <button type="button" class="remove-image-btn" onclick="removeImage()">
                <i class="fa-solid fa-trash me-1"></i>Remove image
            </button>
        </div>
        <?php endif; ?>

        <input type="hidden" name="existing_image"
               id="existingImageInput"
               value="<?php echo htmlspecialchars($announcement['image']); ?>">

        <!-- NEW IMAGE UPLOAD -->
        <div class="mb-4">
            <label class="form-label">
                <?php echo !empty($announcement['image']) ? 'Replace Image' : 'Attach Image'; ?>
                <span class="text-muted fw-normal">(optional)</span>
            </label>
            <input type="file" name="image" class="form-control" accept="image/*">
        </div>

        <!-- BUTTONS -->
        <div class="d-flex justify-content-end gap-3 mt-2">
            <a href="teacher_class.php?id=<?php echo $announcement['class_id']; ?>"
               class="btn-cancel">
                Cancel
            </a>
            <button type="submit" class="btn-save">
                <i class="fa-solid fa-floppy-disk me-2"></i>Save Changes
            </button>
        </div>

    </form>
</div>

<script>
function removeImage() {
    // Hide the existing image preview
    document.getElementById('existingImageBox').style.display = 'none';
    // Clear the hidden input so the image gets removed on save
    document.getElementById('existingImageInput').value = '';
}
</script>

</body>
</html>