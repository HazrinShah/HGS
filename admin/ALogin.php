<?php
session_start();
include '../shared/db_connection.php';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    $stmt = $conn->prepare("SELECT * FROM admin WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 1) {
        $admin = $res->fetch_assoc();

        // Plain text password comparison (replace with password_verify if hashed)
        if ($password === $admin['password']) {
            $_SESSION['email'] = $admin['email'];

            echo "<script>
                alert('Login successful!');
                window.location.href = 'ADashboard.html';
            </script>";
            exit();
        } else {
            echo "<script>
                alert('Incorrect password. Please try again.');
                window.history.back();
            </script>";
            exit();
        }
    } else {
        echo "<script>
            alert('Admin not found. Please check your email.');
            window.history.back();
        </script>";
        exit();
    }

    $stmt->close();
    $conn->close();
} else {
    // Not a POST request, redirect to login page
    header("Location: ALogin.html");
    exit();
}
?>
