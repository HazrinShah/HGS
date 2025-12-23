<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../shared/db_connection.php'; 
include '../shared/email_validation.php';

// Debug: Log that the script is being executed
error_log("Guider registration script started");

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("No POST data received");
    header("Location: GRegister.html?error=no_post_data");
    exit();
}

// Debug: Log POST data
error_log("POST data received: " . print_r($_POST, true));

$uploadDir = "../upload/certificates/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Get and sanitize form data
$username = $conn->real_escape_string($_POST['username']);
$email = $conn->real_escape_string($_POST['email']);
$password = $conn->real_escape_string($_POST['password']);
$confirmPassword = $conn->real_escape_string($_POST['confirmPassword'] ?? '');
$gender = $conn->real_escape_string($_POST['gender']);
$phone = $conn->real_escape_string($_POST['phone_number']);

// Password confirmation check (server-side guard)
if ($password !== $confirmPassword) {
    header("Location: GRegister.html?error=password_mismatch");
    exit();
}

// Validate email format and existence
$emailValidation = validateEmailForRegistration($email);
if (!$emailValidation['success']) {
    header("Location: GRegister.html?error=invalid_email&message=" . urlencode($emailValidation['error']));
    exit();
}

// Validate phone number length
$phoneLength = strlen($phone);
if ($phoneLength < 11 || $phoneLength > 12) {
    header("Location: GRegister.html?error=invalid_phone&message=" . urlencode("Phone number must be between 11 and 12 digits."));
    exit();
}

// Handle certificate upload
$certificateName = basename($_FILES['certificate']['name']);
$targetPath = $uploadDir . time() . "_" . $certificateName;
$certificatePath = $conn->real_escape_string($targetPath);

// Check for duplicate email in database
$checkQuery = "SELECT * FROM guider WHERE email = '$email'";
$result = $conn->query($checkQuery);

if ($result->num_rows > 0) {
    header("Location: GRegister.html?error=email_exists");
    exit();
}

// Check for duplicate phone number in database
$phoneCheckQuery = "SELECT * FROM guider WHERE phone_number = '$phone'";
$phoneResult = $conn->query($phoneCheckQuery);

if ($phoneResult->num_rows > 0) {
    header("Location: GRegister.html?error=phone_exists");
    exit();
}

// Move uploaded certificate file
if (move_uploaded_file($_FILES['certificate']['tmp_name'], $targetPath)) {
    // Set default status to 'pending' until admin validation
    // Set default profile picture for new guiders
    $defaultProfilePic = 'img/default-guider.jpg';
    $insertQuery = "INSERT INTO guider (username, email, certificate, password, gender, phone_number, status, profile_picture)
                    VALUES ('$username', '$email', '$certificatePath', '$password', '$gender', '$phone', 'pending', '$defaultProfilePic')";

    if ($conn->query($insertQuery) === TRUE) {
        // Registration successful - redirect to login with success notification
        header("Location: GLogin.html?success=registration");
        exit();
    } else {
        // Database error - check for specific errors
        $error_message = "Registration failed. Please try again.";
        
        if ($conn->error) {
            // Check for duplicate phone number error
            if (strpos($conn->error, 'phone_number') !== false && strpos($conn->error, 'Duplicate entry') !== false) {
                $error_message = "This phone number is already registered. Please use a different phone number or contact support if this is an error.";
            } else {
                $error_message .= " Error: " . $conn->error;
            }
        }
        
        header("Location: GRegister.html?error=registration_failed&message=" . urlencode($error_message));
        exit();
    }
} else {
    header("Location: GRegister.html?error=upload_failed&message=" . urlencode("Failed to upload certificate. Please try again."));
    exit();
}

$conn->close();
?>
