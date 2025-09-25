<?php

include '../shared/db_connection.php';
include '../shared/email_validation.php';

// Debug: Check if form data is received
if (empty($_POST['username']) || empty($_POST['email']) || empty($_POST['password'])) {
    header("Location: HRegister.html?error=missing_data");
    exit();
}

$username = $conn->real_escape_string($_POST['username']);
$email = $conn->real_escape_string($_POST['email']);
$password = $conn->real_escape_string($_POST['password']);
$gender = $conn->real_escape_string($_POST['gender']);
$phone = intval($_POST['phone_number']);

// Validate email format and existence
$emailValidation = validateEmailForRegistration($email);
if (!$emailValidation['success']) {
    // Debug: Log the validation result
    error_log("Email validation failed for $email: " . $emailValidation['error']);
    header("Location: HRegister.html?error=invalid_email&message=" . urlencode($emailValidation['error']));
    exit();
}

// Debug: Log successful validation
error_log("Email validation passed for $email: " . $emailValidation['message']);

// Check if email already exists in database
$checkQuery = "SELECT email FROM hiker WHERE email = '$email'";
$result = $conn->query($checkQuery);

if ($result->num_rows > 0) {
  header("Location: HRegister.html?error=email_exists");
  exit();
}

// Insert new hiker into the database
$insertQuery = "INSERT INTO hiker (username, email, password, gender, phone_number)
                VALUES ('$username', '$email', '$password', '$gender', '$phone')";

if ($conn->query($insertQuery) === TRUE) {
  // Get the newly created hiker ID
  $hikerID = $conn->insert_id;
  
  // Automatically add FPX payment method for new user
  $fpxQuery = "INSERT INTO payment_methods (hikerID, methodType, cardName, cardNumber, expiryDate, createdAt) 
               VALUES ($hikerID, 'FPX', '', '', '', NOW())";
  $fpxResult = $conn->query($fpxQuery);
  
  if ($fpxResult) {
    header("Location: HLogin.html?success=registration");
    exit();
  } else {
    // Registration successful but FPX method failed
    header("Location: HLogin.html?success=registration&warning=fpx_failed");
    exit();
  }
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
  
  header("Location: HRegister.html?error=registration_failed&message=" . urlencode($error_message));
  exit();
}

$conn->close();
?>
