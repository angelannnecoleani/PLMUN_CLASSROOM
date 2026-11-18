<?php
session_start();
include "database.php";

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit();
}

$class_id = intval($_GET['id']);

$classQuery = mysqli_query($conn,
"SELECT * FROM classes WHERE id='$class_id'");

$class = mysqli_fetch_assoc($classQuery);

$roomName = "PLMUN_" . md5($class['class_code']);
?>

<!DOCTYPE html>
<html>
<head>

<meta charset="UTF-8">

<title>Video Conference</title>

<script src="https://meet.jit.si/external_api.js"></script>

<style>

html,
body{
    margin:0;
    height:100%;
    overflow:hidden;
}

#meet{
    width:100%;
    height:100vh;
}

</style>

</head>

<body>

<div id="meet"></div>

<script>

const domain = "meet.jit.si";

const options = {

    roomName: "<?php echo $roomName; ?>",

    width:"100%",

    height:"100%",

    parentNode: document.querySelector("#meet"),

    userInfo:{
        displayName:"<?php echo $_SESSION['name']; ?>"
    }

};

const api = new JitsiMeetExternalAPI(domain, options);

</script>

</body>
</html>