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

        // Verify password (plain text comparison here)
        if ($user['password'] === $password) {
            // Successful login: set session and show alert then redirect
            $_SESSION['guiderID'] = $user['guiderID'];      
            $_SESSION['username'] = $user['username'];

            echo "<script>
                alert('Login successful! Welcome, " . addslashes($user['username']) . "');
                window.location.href = 'GBooking.php';
            </script>";
            exit();
        } else {
            // Wrong password alert and go back
            echo "<script>
                alert('Incorrect password. Please try again.');
                window.history.back();
            </script>";
            exit();
        }
    } else {
        // Email not found alert and go back
        echo "<script>
            alert('Email not found. Please register first.');
            window.history.back();
        </script>";
        exit();
    }
} else {
    // Not a POST request, redirect to login form
    header("Location: GLogin.html");
    exit();
}
?>
