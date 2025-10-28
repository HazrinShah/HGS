<?php
session_start();
include '../shared/db_connection.php'; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $conn->real_escape_string($_POST['email']);
    $password = $conn->real_escape_string($_POST['password']);

    // find user by email
    $query = "SELECT * FROM guider WHERE email = '$email' LIMIT 1";
    $result = $conn->query($query);

    if ($result && $result->num_rows == 1) {
        $user = $result->fetch_assoc();
        $status = strtolower($user['status'] ?? 'active');

        // Block banned accounts
        if ($status === 'banned') {
            header("Location: GLogin.html?error=banned_account");
            exit();
        }

        // Verify password (plain text comparison here)
        if ($user['password'] === $password) {
            // Successful login: set session and redirect
            $_SESSION['guiderID'] = $user['guiderID'];      
            $_SESSION['username'] = $user['username'];
            $_SESSION['guider_status'] = $status; // 'suspended' or 'active'

            header("Location: GBooking.php");
            exit();
        } else {
            header("Location: GLogin.html?error=invalid_password");
            exit();
        }
    } else {
        header("Location: GLogin.html?error=user_not_found");
        exit();
    }
} else {
    // Not a POST request, redirect to login form
    header("Location: GLogin.html");
    exit();
}
?>
