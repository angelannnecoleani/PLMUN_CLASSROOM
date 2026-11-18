<?php

include 'database.php';

$name = $_POST['name'];
$email = $_POST['email'];
$password = $_POST['password'];
$confirm = $_POST['confirm_password'];
$role = $_POST['role'];

if($password != $confirm){
    die("Passwords do not match.");
}

$check = mysqli_query($conn,
"SELECT * FROM users WHERE email='$email'");

if(mysqli_num_rows($check) > 0){
    die("Email already exists.");
}

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

$sql = "INSERT INTO users(name,email,password,role)
VALUES('$name','$email','$hashedPassword','$role')";

if(mysqli_query($conn,$sql)){
    echo "<script>
    alert('Registration Successful!');
    window.location='index.html';
    </script>";
}else{
    echo "Error";
}

?>