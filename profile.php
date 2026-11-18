<?php
session_start();
include 'database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

if(isset($_POST['save'])){

    $name = mysqli_real_escape_string(
        $conn,
        $_POST['fullname']
    );

    $getUser = mysqli_query(
        $conn,
        "SELECT * FROM users WHERE id='$user_id'"
    );

    $oldUser = mysqli_fetch_assoc($getUser);

    $profile_image = $oldUser['profile_image'];

    /* REMOVE PHOTO */

    if(isset($_POST['remove_image']) && $_POST['remove_image'] == "1"){

        if(!empty($profile_image) && file_exists("uploads/".$profile_image)){
            unlink("uploads/".$profile_image);
        }

        $profile_image = "";
    }

    /* NEW PHOTO UPLOAD (takes priority over remove if both happen somehow) */

    if(!empty($_FILES['profile_image']['name'])){

        if(!is_dir("uploads")){
            mkdir("uploads",0777,true);
        }

        if(!empty($oldUser['profile_image']) && file_exists("uploads/".$oldUser['profile_image'])){
            unlink("uploads/".$oldUser['profile_image']);
        }

        $profile_image =
        time().'_'.basename($_FILES['profile_image']['name']);

        move_uploaded_file(
            $_FILES['profile_image']['tmp_name'],
            "uploads/".$profile_image
        );
    }

    $profile_image_safe = mysqli_real_escape_string($conn, $profile_image);

    $result = mysqli_query(
    $conn,
    "UPDATE users
     SET name='$name',
         profile_image='$profile_image_safe'
     WHERE id='$user_id'"
);

if(!$result){
    die(mysqli_error($conn));
}

    header("Location: profile.php");
    exit();
}

$query = mysqli_query(
    $conn,
    "SELECT * FROM users WHERE id='$user_id'"
);

$user = mysqli_fetch_assoc($query);
?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>My Profile</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
      rel="stylesheet">

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="profile.css">

</head>
<body>

<div class="container">

<div class="profile-container">

<div class="profile-card">

    <div class="profile-banner">
        <a href="javascript:history.back()" class="back-btn">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
    </div>

    <div class="profile-content">

        <form method="POST" enctype="multipart/form-data">

            <div class="profile-picture-container">

                <?php
                $profilePic = "uploads/" . $user['profile_image'];
                $hasImage = !empty($user['profile_image']) && file_exists($profilePic);
                ?>

                <?php if($hasImage): ?>

                    <img src="<?php echo htmlspecialchars($profilePic); ?>"
                        id="previewImage"
                        class="profile-image">

                <?php else: ?>

                    <div class="profile-image profile-image-placeholder"
                         id="previewImage">
                        <i class="fa-solid fa-user"></i>
                    </div>

                <?php endif; ?>

                <label for="profile_image" class="camera-btn" title="Change photo">
                    <i class="fa-solid fa-camera"></i>
                </label>

                <input type="file"
                       id="profile_image"
                       name="profile_image"
                       accept="image/*"
                       hidden
                       onchange="previewProfile(event)">

                <?php if($hasImage): ?>
                <button type="button"
                        class="remove-btn"
                        title="Remove photo"
                        onclick="removeProfile()">
                    <i class="fa-solid fa-trash"></i>
                </button>
                <?php endif; ?>

            </div>

            <input type="hidden" name="remove_image" id="remove_image" value="0">

            <!-- ROLE -->

            <div class="role-badge">
                <?php echo ucfirst($user['role']); ?>
            </div>

            <!-- FORM -->

            <div class="profile-form">

                <div class="mb-3">

                    <label>
                        Full Name
                    </label>

                    <input type="text"
                           name="fullname"
                           class="form-control"
                           value="<?php echo htmlspecialchars($user['name']); ?>"
                           required>

                </div>

                <div class="mb-3">

                    <label>
                        Email Address
                    </label>

                    <input type="email"
                           class="form-control"
                           value="<?php echo htmlspecialchars($user['email']); ?>"
                           readonly>

                </div>

                <div class="mb-3">

                    <label>
                        Role
                    </label>

                    <input type="text"
                           class="form-control"
                           value="<?php echo ucfirst($user['role']); ?>"
                           readonly>

                </div>

                <button type="submit"
                        name="save"
                        class="btn btn-primary save-btn">

                    <i class="fa-solid fa-floppy-disk"></i>
                    Save Changes

                </button>

            </div>

        </form>

    </div>

</div>

</div>

</div>

<script>

function previewProfile(event){

    let container = document.getElementById('previewImage');
    let url = URL.createObjectURL(event.target.files[0]);

    let img = document.createElement('img');
    img.id = 'previewImage';
    img.className = 'profile-image';
    img.src = url;

    container.replaceWith(img);

    document.getElementById('remove_image').value = "0";
}

function removeProfile(){

    if(!confirm("Remove your profile picture?")) return;

    let container = document.getElementById('previewImage');

    let placeholder = document.createElement('div');
    placeholder.id = 'previewImage';
    placeholder.className = 'profile-image profile-image-placeholder';
    placeholder.innerHTML = '<i class="fa-solid fa-user"></i>';

    container.replaceWith(placeholder);

    document.getElementById('profile_image').value = "";
    document.getElementById('remove_image').value = "1";

    let removeBtn = document.querySelector('.remove-btn');
    if(removeBtn) removeBtn.style.display = "none";
}

</script>

</body>
</html>