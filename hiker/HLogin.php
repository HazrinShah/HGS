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

    // Check account status first
    $status = strtolower($user['status'] ?? 'active');
    if ($status === 'banned') {
        // Banned users cannot log in
        header("Location: HLogin.html?error=banned_account");
        exit();
    }

    if ($password === $user['password']) {
        // Regenerate session ID upon successful login to prevent fixation
        if (function_exists('session_regenerate_id')) {
            session_regenerate_id(true);
        }
        // Store user info in session
        $_SESSION['hikerID'] = $user['hikerID'];
        $_SESSION['username'] = $user['username'];
        // Flag suspended users so pages can show banner and block bookings
        $_SESSION['hiker_status'] = $status; // 'suspended' or 'active'

        // Debug log to trace logins
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
