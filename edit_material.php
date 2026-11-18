<?php
session_start();
include 'database.php';

// Read id from POST or GET — no die() that blocks POST
$id = 0;
if(isset($_POST['id']))  $id = intval($_POST['id']);
elseif(isset($_GET['id'])) $id = intval($_GET['id']);

if(!$id) die("Invalid request.");

// Always fetch material first — needed for both GET form render and POST redirect
$result   = mysqli_query($conn, "SELECT * FROM materials WHERE id='$id'");
$material = mysqli_fetch_assoc($result);
if(!$material) die("Material not found.");

$class_id = $material['class_id'];

// Handle form submission
if($_SERVER['REQUEST_METHOD'] === 'POST'){

    $title       = mysqli_real_escape_string($conn, trim($_POST['title']));
    $description = mysqli_real_escape_string($conn, trim($_POST['description'] ?? ''));
    $link        = mysqli_real_escape_string($conn, trim($_POST['link'] ?? ''));
    $file        = mysqli_real_escape_string($conn, $_POST['existing_file'] ?? '');

    // Handle new file upload
    if(!empty($_FILES['file']['name'])){

        $allowed = ['pdf','doc','docx','ppt','pptx','xls','xlsx',
                    'jpg','jpeg','png','gif','webp','mp4','mov','avi'];
        $original = basename($_FILES['file']['name']);
        $ext      = strtolower(pathinfo($original, PATHINFO_EXTENSION));

        if(!in_array($ext, $allowed)) die("File type not allowed.");
        if($_FILES['file']['size'] > 20 * 1024 * 1024) die("File too large. Max 20MB.");

        // Delete old file from server
        if(!empty($material['file'])){
            $old = "uploads/" . $material['file'];
            if(file_exists($old)) unlink($old);
        }

        // Save new file with unique name
        $file = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $original);
        move_uploaded_file($_FILES['file']['tmp_name'], "uploads/" . $file);
    }

    mysqli_query($conn, "
        UPDATE materials
        SET title       = '$title',
            description = '$description',
            file        = '$file',
            link        = '$link'
        WHERE id        = '$id'
    ");

    header("Location: teacher_material.php?id=$class_id");
    exit;
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
        .edit-card h4 { font-weight: 700; margin-bottom: 24px; color: #1a2d3e; }
        .form-label   { font-weight: 600; font-size: 14px; color: #444; }
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
        textarea.form-control { min-height: 110px; resize: vertical; }
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
        .existing-file-box i    { font-size: 22px; color: #3f87b5; }
        .existing-file-box span { font-size: 14px; color: #333; word-break: break-all; flex: 1; }
        .remove-file-btn {
            font-size: 13px; color: #dc3545;
            background: none; border: none; cursor: pointer; padding: 0;
        }
        .remove-file-btn:hover { text-decoration: underline; }
        .btn-cancel {
            height: 44px; padding: 0 24px; border-radius: 8px;
            border: 1px solid #dde4ec; background: #fff; color: #555;
            font-size: 15px; font-weight: 600; text-decoration: none;
            display: inline-flex; align-items: center; transition: background .2s;
        }
        .btn-cancel:hover { background: #f5f5f5; color: #333; }
        .btn-save {
            height: 44px; padding: 0 28px; border-radius: 8px;
            border: none; background: #3f87b5; color: #fff;
            font-size: 15px; font-weight: 600; cursor: pointer; transition: background .2s;
        }
        .btn-save:hover { background: #2f76a7; }
    </style>
</head>
<body>

<div class="edit-card">

    <h4><i class="fa-solid fa-pen me-2 text-primary"></i>Edit Material</h4>

    <!-- action="" posts to the current URL, id passed via hidden field -->
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="id" value="<?php echo $id; ?>">

        <!-- TITLE -->
        <div class="mb-4">
            <label class="form-label">Title</label>
            <input type="text" name="title" class="form-control"
                   value="<?php echo htmlspecialchars($material['title']); ?>" required>
        </div>

        <!-- DESCRIPTION -->
        <div class="mb-4">
            <label class="form-label">Description
                <span class="text-muted fw-normal">(optional)</span>
            </label>
            <textarea name="description" class="form-control"><?php echo htmlspecialchars($material['description']); ?></textarea>
        </div>

        <!-- EXISTING FILE -->
        <?php if(!empty($material['file'])): ?>
        <div class="mb-3" id="existingFileBox">
            <label class="form-label">Current File</label>
            <div class="existing-file-box">
                <i class="fa-regular fa-file"></i>
                <span><?php echo htmlspecialchars($material['file']); ?></span>
                <button type="button" class="remove-file-btn" onclick="removeFile()">
                    <i class="fa-solid fa-trash me-1"></i>Remove
                </button>
            </div>
        </div>
        <?php endif; ?>

        <input type="hidden" name="existing_file" id="existingFileInput"
               value="<?php echo htmlspecialchars($material['file']); ?>">

        <!-- NEW FILE UPLOAD -->
        <div class="mb-4">
            <label class="form-label">
                <?php echo !empty($material['file']) ? 'Replace File' : 'Attach File'; ?>
                <span class="text-muted fw-normal">(optional)</span>
            </label>
            <input type="file" name="file" class="form-control"
                   accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.webp,.mp4,.mov,.avi">
        </div>

        <!-- LINK -->
        <div class="mb-4">
            <label class="form-label">Link
                <span class="text-muted fw-normal">(optional)</span>
            </label>
            <input type="url" name="link" class="form-control"
                   value="<?php echo htmlspecialchars($material['link']); ?>"
                   placeholder="https://...">
        </div>

        <!-- BUTTONS -->
        <div class="d-flex justify-content-end gap-3 mt-2">
            <a href="teacher_material.php?id=<?php echo $class_id; ?>" class="btn-cancel">
                Cancel
            </a>
            <button type="submit" class="btn-save">
                <i class="fa-solid fa-floppy-disk me-2"></i>Save Changes
            </button>
        </div>

    </form>
</div>

<script>
function removeFile(){
    document.getElementById('existingFileBox').style.display = 'none';
    document.getElementById('existingFileInput').value = '';
}
</script>
</body>
</html>