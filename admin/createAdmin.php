<?php
include '../shared/db_connection.php'; // Make sure this file connects to your MySQL DB

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    $confirm = trim($_POST['confirm']);

    if ($password !== $confirm) {
        echo json_encode(['status' => 'error', 'message' => 'Passwords do not match.']);
        exit();
    }

    // Store password in plain text (insecure)
    $plainPassword = $password;

    // Check if email already exists
    $stmt = $conn->prepare("SELECT * FROM admin WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $existing = $stmt->get_result();

    if ($existing->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Email already exists.']);
        exit();
    }

    // Insert new admin
    $insert = $conn->prepare("INSERT INTO admin (email, password) VALUES (?, ?)");
    $insert->bind_param("ss", $email, $plainPassword);

    if ($insert->execute()) {
        echo "<script>alert('Admin account created successfully.'); window.location.href='Alogin.html';</script>";
    } else {
        echo "<script>alert('Email already exists.'); window.history.back();</script>";
    }

    $stmt->close();
    $insert->close();
    $conn->close();
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
}
?>
