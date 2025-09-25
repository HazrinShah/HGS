<?php
session_start();
include '../shared/db_connection.php'; 

$email = $conn->real_escape_string($_POST['email']);
$password = $_POST['password'];

// find user by email
$query = "SELECT * FROM hiker WHERE email = '$email'";
$result = $conn->query($query);

if ($result->num_rows === 1) {
    $user = $result->fetch_assoc();
    

    if ($password === $user['password']) {
        // Store user info in session if needed
        $_SESSION['hikerID'] = $user['hikerID'];
        $_SESSION['username'] = $user['username'];

        header("Location: HHomePage.php");
        exit();
    } else {
        header("Location: HLogin.html?error=invalid_password");
        exit();
    }
} else {
    header("Location: HLogin.html?error=user_not_found");
    exit();
}

$conn->close();
?>
