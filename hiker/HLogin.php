<?php
session_start();
include '../shared/db_connection.php'; 

$email = $conn->real_escape_string($_POST['email']);
$password = $_POST['password'];

// cari user guna email
$query = "SELECT * FROM hiker WHERE email = '$email'";
$result = $conn->query($query);

if ($result->num_rows === 1) {
    $user = $result->fetch_assoc();

    // check account status dulu
    $status = strtolower($user['status'] ?? 'active');
    if ($status === 'banned') {
        // user yang banned tak boleh login
        header("Location: HLogin.html?error=banned_account");
        exit();
    }

    if ($password === $user['password']) {
        // regenerate session ID lepas login successful untuk prevent fixation
        if (function_exists('session_regenerate_id')) {
            session_regenerate_id(true);
        }
        // simpan user info dalam session
        $_SESSION['hikerID'] = $user['hikerID'];
        $_SESSION['username'] = $user['username'];
        // flag suspended users supaya pages boleh tunjuk banner dan block bookings
        $_SESSION['hiker_status'] = $status; // 'suspended' or 'active'

        // debug log untuk trace logins
        error_log('HLogin.php - Successful login: hikerID=' . $_SESSION['hikerID']);

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
