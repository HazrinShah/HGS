<?php
include '../shared/db_connection.php';
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in
if (!isset($_SESSION['guiderID'])) {
    header("Location: GLogin.html");
    exit();
}

$guiderID = $_SESSION['guiderID'];

// Fetch guider data from database (including skills, experience, about, mountains, no_acc for profile updates)
$sql = "SELECT guiderID, username, email, phone_number, gender, profile_picture, skills, experience, about, mountains, no_acc FROM guider WHERE guiderID = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $guiderID);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $guider = $result->fetch_assoc();
} else {
    echo "Guider not found.";
    exit();
}

// Fetch all mountains for the mountain selection
$mountainsQuery = "SELECT mountainID, name FROM mountain ORDER BY name";
$mountainsResult = $conn->query($mountainsQuery);
$allMountains = [];
if ($mountainsResult && $mountainsResult->num_rows > 0) {
    while ($row = $mountainsResult->fetch_assoc()) {
        $allMountains[] = $row;
    }
}

// Handle price update
if (isset($_POST['update_price'])) {
    $newPrice = $_POST['guider_price'];
    
    $updateQuery = $conn->prepare("UPDATE guider SET price = ? WHERE guiderID = ?");
    $updateQuery->bind_param("di", $newPrice, $guiderID);
    
    if ($updateQuery->execute()) {
        $success = "Price updated successfully!";
        $guider['price'] = $newPrice; // Update local data
    } else {
        $error = "Error updating price: " . $conn->error;
    }
}

// Handle profile update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_profile'])) {
    $username = $_POST['username'];
    $phone = $_POST['phone_number'];
    $no_acc = $_POST['no_acc'] ?? $guider['no_acc'] ?? '';
    // Keep existing values for skills, experience, about, and mountains (they are edited separately)
    $skills = $_POST['skills'] ?? $guider['skills'] ?? '';
    $experience = $_POST['experience'] ?? $guider['experience'] ?? '';
    $about = $_POST['about'] ?? $guider['about'] ?? '';
    $mountains = $_POST['mountains'] ?? $guider['mountains'] ?? '';

    // Set up upload directory (relative to the script location)
    $target_dir = "../uploads/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0755, true);
    }

    // Keep existing picture unless new one is uploaded
    $profile_picture_path = $guider['profile_picture'];

    if (isset($_FILES["profile_picture"]) && $_FILES["profile_picture"]["error"] == UPLOAD_ERR_OK) {
        // Validate file type
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $file_type = $_FILES["profile_picture"]["type"];
        
        if (in_array($file_type, $allowed_types)) {
            // Delete old picture if it exists and isn't the default
            if (!empty($profile_picture_path) && $profile_picture_path != 'default-profile.jpg' && file_exists('../' . $profile_picture_path)) {
                unlink($profile_picture_path);
            }

            // Generate unique filename
            $file_extension = pathinfo($_FILES["profile_picture"]["name"], PATHINFO_EXTENSION);
            $filename = "profile_" . $guiderID . "_" . uniqid() . "." . $file_extension;
            $target_file = $target_dir . $filename;
            
            if (move_uploaded_file($_FILES["profile_picture"]["tmp_name"], $target_file)) {
                // Store only the relative path in database (without ../)
                $profile_picture_path = "uploads/" . $filename;
            }
        }
    }

    // Update query - Only update username, phone_number, no_acc (email and gender are permanent)
    $sql = "UPDATE guider SET username=?, phone_number=?, no_acc=?, profile_picture=?, skills=?, experience=?, about=?, mountains=? WHERE guiderID=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssssssi", $username, $phone, $no_acc, $profile_picture_path, $skills, $experience, $about, $mountains, $guiderID);
    
    if ($stmt->execute()) {
        // Refresh the guider data after update
        $sql = "SELECT * FROM guider WHERE guiderID=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $guiderID);
        $stmt->execute();
        $result = $stmt->get_result();
        $guider = $result->fetch_assoc();
        
        header("Location: GProfile.php?updated=1");
        exit;
    } else {
        echo "Error updating profile: " . $conn->error;
    }
}

// Get latest data for display
$sql = "SELECT * FROM guider WHERE guiderID=?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $guiderID);
$stmt->execute();
$result = $stmt->get_result();
$guider = $result->fetch_assoc();

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Guider Profile</title>

  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" rel="stylesheet" />

  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.3.0/css/all.min.css" />

  <!-- Google Font -->
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />

  <!-- Bootsrap Logo -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">


  <style>
      
    :root {
      --guider-blue: #1e40af;
      --guider-blue-light: #3b82f6;
      --guider-blue-dark: #1e3a8a;
      --guider-blue-accent: #60a5fa;
      --guider-blue-soft: #dbeafe;
      --primary: var(--guider-blue);
      --accent: var(--guider-blue-light);
      --soft-bg: #f8fafc;
      --card-white: #ffffff;
      --success-color: #28a745;
      --warning-color: #ffc107;
      --danger-color: #dc3545;
      --dark-color: #343a40;
      --light-color: #f8f9fa;
    }

    body {
      background-color: var(--soft-bg);
      font-family: "Montserrat", sans-serif;
      margin: 0;
      padding: 0;
      min-height: 100vh;
    }

    /* Header */
    .navbar {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue)) !important;
      padding: 12px 0;
      box-shadow: 0 4px 20px rgba(30, 64, 175, 0.3);
    }
    .navbar-toggler {
      border: 1px solid rgba(255, 255, 255, 0.3);
    }
    .navbar-toggler-icon {
      background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba%28255, 255, 255, 1%29' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
    }

    .navbar-title {
      font-size: 22px;
      font-weight: bold;
      color: white;
      margin: 0 auto;
      text-shadow: 1px 1px 3px rgba(0,0,0,0.2);
    }

    .logo {
      width: 60px;
      height: 60px;
      object-fit: contain;
    }

    /* Offcanvas Menu */
    .offcanvas {
      background-color: var(--light-color);
    }

    .offcanvas-title {
      color: var(--primary-color);
      font-weight: 600;
    }

    .nav-link {
      color: var(--dark-color);
      font-weight: 500;
      padding: 10px 15px;
      border-radius: 8px;
      margin: 2px 0;
    }

    .nav-link:hover, .nav-link.active {
      background-color: var(--guider-blue-soft);
      color: var(--guider-blue-dark);
      border-color: var(--guider-blue);
    }

    /* Main Container */
    .main-container {
      padding: 2rem;
      max-width: 1400px;
      margin: 0 auto;
      background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
      min-height: 100vh;
    }

    /* Profile Header */
    .profile-header {
      text-align: center;
      margin-bottom: 3rem;
      padding: 2rem 0;
    }

    .profile-header h1 {
      font-size: 3rem;
      font-weight: 800;
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      margin-bottom: 0.5rem;
      text-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .profile-header p {
      font-size: 1.2rem;
      color: #64748b;
      margin: 0;
      font-weight: 500;
    }

    /* Main Profile Layout */
    .container-custom {
      display: grid;
      grid-template-columns: 380px 1fr;
      gap: 2rem;
      max-width: 1200px;
      margin: 0 auto;
    }

    .profile-container {
      display: contents;
    }

    /* Left Sidebar */
    .profile-left {
      background: var(--card-white);
      border-radius: 24px;
      padding: 2rem;
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
      border: 1px solid rgba(255, 255, 255, 0.2);
      backdrop-filter: blur(10px);
      height: fit-content;
      position: sticky;
      top: 2rem;
    }

    /* Right Content */
    .profile-right {
      display: flex;
      flex-direction: column;
      gap: 1.5rem;
    }

    @media (max-width: 1024px) {
      .container-custom {
        grid-template-columns: 1fr;
        gap: 1.5rem;
      }
      
      .profile-left {
        position: static;
        order: 2;
      }
      
      .profile-right {
        order: 1;
      }
    }

    .profile-pic {
      width: 180px;
      height: 180px;
      background: linear-gradient(135deg, var(--guider-blue-soft), #ffffff);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 48px;
      color: var(--guider-blue);
      border: 4px solid var(--card-white);
      overflow: hidden;
      box-shadow: 0 20px 40px rgba(30, 64, 175, 0.2);
      margin: 0 auto 2rem auto;
      position: relative;
    }

    .profile-pic::before {
      content: '';
      position: absolute;
      top: -4px;
      left: -4px;
      right: -4px;
      bottom: -4px;
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      border-radius: 50%;
      z-index: -1;
    }

    .profile-pic img {
      object-fit: cover;
      width: 100%;
      height: 100%;
      border-radius: 50%;
    }

    .profile-details {
      flex-grow: 1;
    }

    .profile-details .form-label {
      font-weight: 600;
      color: var(--guider-blue-dark);
      margin-bottom: 0.5rem;
    }

    .profile-details .form-control {
      background-color: #f8fafc;
      border: 2px solid #e2e8f0;
      border-radius: 12px;
      margin-bottom: 1rem;
      height: 48px;
      padding: 0.75rem 1rem;
      font-size: 1rem;
      transition: all 0.3s ease;
    }

    .profile-details .form-control:focus {
      background-color: var(--card-white);
      border-color: var(--guider-blue);
      box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
    }

    .edit-btn {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      border: none;
      padding: 1rem 2rem;
      border-radius: 16px;
      font-weight: 700;
      color: white;
      transition: all 0.3s ease;
      box-shadow: 0 8px 25px rgba(30, 64, 175, 0.3);
      font-size: 1rem;
      position: relative;
      overflow: hidden;
    }

    .edit-btn::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
      transition: left 0.5s ease;
    }

    .edit-btn:hover {
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      transform: translateY(-3px);
      box-shadow: 0 12px 30px rgba(30, 64, 175, 0.4);
      color: white;
    }

    .edit-btn:hover::before {
      left: 100%;
    }

    .btn-success {
      background: linear-gradient(135deg, #10b981, #059669);
      border: none;
      padding: 1rem 2rem;
      border-radius: 16px;
      font-weight: 700;
      color: white;
      transition: all 0.3s ease;
      box-shadow: 0 8px 25px rgba(16, 185, 129, 0.3);
      font-size: 1rem;
      position: relative;
      overflow: hidden;
    }

    .btn-success::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
      transition: left 0.5s ease;
    }

    .btn-success:hover {
      background: linear-gradient(135deg, #059669, #047857);
      transform: translateY(-3px);
      box-shadow: 0 12px 30px rgba(16, 185, 129, 0.4);
      color: white;
    }

    .btn-success:hover::before {
      left: 100%;
    }

    /* Price Section Specific Styles */
    .profile-left .profile-section {
      margin-bottom: 1rem;
      padding: 1.5rem;
    }

    .profile-left .input-group {
      margin-bottom: 0.5rem;
    }

    .profile-left .input-group-text {
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      border: none;
      color: white;
      font-weight: 600;
      font-size: 0.9rem;
      padding: 0.5rem 0.75rem;
    }

    .profile-left .form-control {
      border: 2px solid #e2e8f0;
      border-radius: 0 8px 8px 0;
      font-weight: 600;
      color: var(--guider-blue-dark);
      font-size: 0.9rem;
      padding: 0.5rem 0.75rem;
    }

    .profile-left .form-control:focus {
      border-color: var(--guider-blue);
      box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
    }

    .profile-left .btn-sm {
      padding: 0.5rem 0.75rem;
      font-size: 0.8rem;
      min-width: 40px;
    }

    .profile-left .text-muted {
      font-size: 0.75rem;
    }

    .experience-skill {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      border-radius: 20px;
      padding: 2rem;
      color: white;
      min-height: 200px;
      box-shadow: 0 8px 32px rgba(30, 64, 175, 0.2);
      border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .section-title {
      font-weight: 700;
      font-size: 1.5rem;
      margin-bottom: 1.5rem;
      color: white;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .section-title i {
      font-size: 1.25rem;
    }

    /* Notification System */
    .notification-container {
      position: fixed;
      bottom: 20px;
      right: 20px;
      z-index: 9999;
      max-width: 350px;
    }

    .notification {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(20px);
      border-radius: 12px;
      padding: 1rem;
      margin-bottom: 0.75rem;
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
      border: 1px solid rgba(255, 255, 255, 0.2);
      transform: translateX(100%);
      opacity: 0;
      transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
      position: relative;
      overflow: hidden;
    }

    .notification.show {
      transform: translateX(0);
      opacity: 1;
    }

    .notification.success::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, #10b981, #059669);
    }

    .notification.error::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, #ef4444, #dc2626);
    }

    /* Modal Enhancements */
    .modal-content {
      border-radius: 16px;
      border: none;
      box-shadow: 0 20px 60px rgba(30, 64, 175, 0.15);
    }

    .modal-header {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      color: white;
      border-radius: 16px 16px 0 0;
      border: none;
      padding: 1.5rem;
    }

    .modal-title {
      font-weight: 700;
      font-size: 1.25rem;
    }

    .modal-body {
      padding: 2rem;
    }

    .modal-footer {
      border: none;
      padding: 1.5rem 2rem;
      background: #f8fafc;
      border-radius: 0 0 16px 16px;
    }

    .btn-primary {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      border: none;
      border-radius: 8px;
      padding: 0.75rem 1.5rem;
      font-weight: 600;
      transition: all 0.3s ease;
    }

    .btn-primary:hover {
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(30, 64, 175, 0.4);
    }

    /* Modern Profile Sections */
    .profile-section {
      background: var(--card-white);
      border-radius: 20px;
      padding: 2rem;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
      border: 1px solid rgba(255, 255, 255, 0.2);
      backdrop-filter: blur(10px);
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
    }

    .profile-section::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, var(--guider-blue), var(--guider-blue-light));
    }

    .profile-section:hover {
      transform: translateY(-5px);
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.12);
    }

    .section-title {
      font-size: 1.5rem;
      font-weight: 700;
      color: var(--guider-blue-dark);
      margin-bottom: 1.5rem;
      display: flex;
      align-items: center;
      gap: 0.75rem;
      position: relative;
    }

    .section-title i {
      color: var(--guider-blue);
      font-size: 1.25rem;
      background: var(--guider-blue-soft);
      padding: 0.5rem;
      border-radius: 12px;
    }

    .skills-container {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      margin-bottom: 1.5rem;
      max-height: 250px;
      overflow-y: auto;
      padding: 1.5rem;
      border: 2px dashed #e2e8f0;
      border-radius: 16px;
      background: linear-gradient(135deg, #f8fafc, #ffffff);
      position: relative;
    }

    .skills-container::before {
      content: 'Click to select skills';
      position: absolute;
      top: 0.5rem;
      left: 1rem;
      font-size: 0.75rem;
      color: #94a3b8;
      font-weight: 500;
    }

    .skill-tag {
      background: linear-gradient(135deg, #ffffff, #f1f5f9);
      color: var(--guider-blue-dark);
      padding: 0.6rem 1.2rem;
      border-radius: 25px;
      font-size: 0.85rem;
      font-weight: 600;
      border: 2px solid var(--guider-blue-soft);
      cursor: pointer;
      transition: all 0.3s ease;
      white-space: nowrap;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
      position: relative;
      overflow: hidden;
    }

    .skill-tag::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
      transition: left 0.5s ease;
    }

    .skill-tag:hover {
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      color: white;
      transform: translateY(-2px) scale(1.05);
      box-shadow: 0 8px 20px rgba(30, 64, 175, 0.3);
      border-color: var(--guider-blue);
    }

    .skill-tag:hover::before {
      left: 100%;
    }

    .skill-tag.selected {
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      color: white;
      border-color: var(--guider-blue);
      box-shadow: 0 4px 15px rgba(30, 64, 175, 0.4);
    }

    /* Mountain Tags */
    .mountains-container {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      padding: 1rem;
      background: linear-gradient(135deg, #f8fafc, #f1f5f9);
      border-radius: 15px;
      border: 1px solid #e2e8f0;
    }

    .mountain-tag {
      background: linear-gradient(135deg, #ffffff, #f1f5f9);
      color: var(--guider-blue-dark);
      padding: 0.6rem 1.2rem;
      border-radius: 25px;
      font-size: 0.85rem;
      font-weight: 600;
      border: 2px solid var(--guider-blue-soft);
      cursor: pointer;
      transition: all 0.3s ease;
      white-space: nowrap;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
      position: relative;
      overflow: hidden;
    }

    .mountain-tag::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
      transition: left 0.5s ease;
    }

    .mountain-tag:hover {
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      color: white;
      transform: translateY(-2px) scale(1.05);
      box-shadow: 0 8px 20px rgba(30, 64, 175, 0.3);
      border-color: var(--guider-blue);
    }

    .mountain-tag:hover::before {
      left: 100%;
    }

    .mountain-tag.selected {
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      color: white;
      border-color: var(--guider-blue);
      box-shadow: 0 4px 15px rgba(30, 64, 175, 0.4);
    }

    .mountain-tag.selected::after {
      content: ' ✓';
    }

    .experience-options {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 1rem;
      margin-bottom: 1.5rem;
    }

    .experience-option {
      padding: 1.25rem 1rem;
      border: 2px solid #e2e8f0;
      border-radius: 16px;
      text-align: center;
      cursor: pointer;
      transition: all 0.3s ease;
      background: linear-gradient(135deg, #ffffff, #f8fafc);
      font-weight: 600;
      font-size: 0.9rem;
      position: relative;
      overflow: hidden;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    }

    .experience-option::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: linear-gradient(135deg, var(--guider-blue-soft), transparent);
      opacity: 0;
      transition: opacity 0.3s ease;
    }

    .experience-option:hover {
      border-color: var(--guider-blue);
      background: linear-gradient(135deg, var(--guider-blue-soft), #ffffff);
      transform: translateY(-3px);
      box-shadow: 0 8px 20px rgba(30, 64, 175, 0.2);
    }

    .experience-option:hover::before {
      opacity: 1;
    }

    .experience-option.selected {
      border-color: var(--guider-blue);
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      color: white;
      box-shadow: 0 8px 25px rgba(30, 64, 175, 0.4);
      transform: translateY(-2px);
    }

    .about-textarea {
      width: 100%;
      min-height: 150px;
      padding: 1.5rem;
      border: 2px solid #e2e8f0;
      border-radius: 16px;
      font-family: inherit;
      font-size: 1rem;
      resize: vertical;
      transition: all 0.3s ease;
      background: linear-gradient(135deg, #ffffff, #f8fafc);
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
      line-height: 1.6;
    }

    .about-textarea:focus {
      outline: none;
      border-color: var(--guider-blue);
      box-shadow: 0 0 0 4px rgba(30, 64, 175, 0.1), 0 4px 15px rgba(0, 0, 0, 0.1);
      background: #ffffff;
    }

    .about-textarea::placeholder {
      color: #94a3b8;
      font-style: italic;
    }

    .kotak-container {
      background-color: white;
      border-radius: 12px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.08);
      padding: 20px;
      margin-bottom: 20px;
      width:900px;
      max width : 1000px;
    }

    .luar-container {
      display: flex;
      justify-content: center;
      width: 1500px;
      border-radius : 12px;
      padding: 20px;
      background-color: rgba(82, 156, 205, 0.08);
      margin : auto;
      box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }

    .price-container {
      background-color: white;
      border-radius: 12px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.08);
      padding: 20px;
      margin-bottom: 20px;
      width:300px;
      max width : 500px;
      margin-right: 80px;
    }

    .profile-container {
      display: flex;
      gap: 20px;
      align-items: flex-start;
    }

    .profile-column {
      display: flex;
      flex-direction: column;
      align-items: center;
      width: 250px; /* Adjust as needed */
      flex-shrink: 0;
    }

    .details-column {
      flex-grow: 1;
    }



    /* Responsive adjustment */
    @media (max-width: 768px) {
      .profile-container {
        flex-direction: column;
      }
      
      .profile-column {
        width: 100%;
      }
    }
    



  </style>
  </head>
<body>
  <!-- Notification Container -->
  <div class="notification-container" id="notificationContainer"></div>

<!-- Header -->
<header>
    <nav class="navbar">
      <div class="container d-flex align-items-center justify-content-between">
        <!-- hamburger button (left) -->
        <button class="navbar-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasNavbar" aria-controls="offcanvasNavbar">
          <span class="navbar-toggler-icon"></span>
        </button>

        <!-- title (center) -->
        <h1 class="navbar-title mx-auto">HIKING GUIDANCE SYSTEM</h1>

        <!-- logo (right) -->
      <a class="navbar-brand" href="../index.php">
          <img src="../img/logo.png" class="img-fluid logo" alt="HGS Logo">
        </a>
      </div>

      <!-- Offcanvas menu -->
      <div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasNavbar" aria-labelledby="offcanvasNavbarLabel">
        <div class="offcanvas-header">
          <h5 class="offcanvas-title" id="offcanvasNavbarLabel">Menu</h5>
          <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
          <ul class="navbar-nav justify-content-end flex-grow-1 pe-3">
            <li class="nav-item"><a class="nav-link" href="GBooking.php">Booking</a></li>
          <li class="nav-item"><a class="nav-link" href="GHistory.php">History</a></li>
            <li class="nav-item"><a class="nav-link active" href="GProfile.php">Profile</a></li>
            <li class="nav-item"><a class="nav-link" href="GEarning.php">Earn & Receive</a></li>
            <li class="nav-item"><a class="nav-link" href="GPerformance.php">Performance Review</a></li>
            <form action="../shared/logout.php" method="POST" class="d-flex justify-content-center mt-5" >
              <button type="submit" class="btn btn-outline-danger">Logout</button>
            </form>
          </ul>
        </div>
      </div>
    </nav>
  </header>
<?php include_once '../shared/suspension_banner.php'; ?>
  <!-- End Header -->

  <?php if (isset($_GET['updated'])): ?>
  <script>
    alert("Profile updated successfully!");
  </script>
  <?php endif; ?>




<div class="main-container">
  <div class="profile-header">
    <h1><i class="fas fa-user-circle me-3"></i>My Profile</h1>
    <p>Manage your personal information and hiking rates</p>
  </div>

  <div class="container-custom">
    <!-- Left Sidebar -->
    <div class="profile-left">
      <div class="profile-pic">
          <img id="profileImagePreview" 
              src="<?php 
                  echo !empty($guider['profile_picture']) ? 
                      (filter_var($guider['profile_picture'], FILTER_VALIDATE_URL) ? 
                          $guider['profile_picture'] : 
                          '../' . $guider['profile_picture']) : 
                      '../default-profile.jpg'; 
              ?>" 
              class="img-fluid rounded-circle" 
              style="width: 100%; height: 100%; object-fit: cover;" 
              alt="Profile Picture">
        </div>

      <?php if (isset($guider['status']) && strtolower($guider['status']) === 'pending'): ?>
        <div class="alert alert-warning d-flex align-items-start gap-2" role="alert" style="border-left:4px solid #f59e0b;">
          <i class="bi bi-hourglass-split" style="color:#b45309;"></i>
          <div>
            <strong>Account Under Review</strong><br>
            Your account is under review by admin and will not appear to hikers until it is approved.
          </div>
        </div>
      <?php endif; ?>

      <!-- Price Setting -->
      <div class="profile-section">
        <h5 class="section-title">
          <i class="fas fa-dollar-sign"></i>Daily Rate
        </h5>
                  
                  <?php if (isset($success)): ?>
          <div class="alert alert-success alert-dismissible fade show mb-3">
                      <?php echo $success; ?>
                      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                  <?php elseif (isset($error)): ?>
          <div class="alert alert-danger alert-dismissible fade show mb-3">
                      <?php echo $error; ?>
                      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                  <?php endif; ?>
                  
                  <form method="POST">
          <div class="d-flex gap-2 mb-2">
            <div class="input-group flex-grow-1">
              <span class="input-group-text">RM</span>
                      <input type="number" class="form-control" name="guider_price" 
                            value="<?php echo isset($guider['price']) ? $guider['price'] : '250'; ?>" 
                            min="0" max="300" step="1" required>
            </div>
            <button class="btn btn-primary btn-sm" type="submit" name="update_price">
              <i class="bi bi-check-lg"></i>
                      </button>
                    </div>
          <small class="text-muted">Min RM250/trip</small>
                  </form>
                </div>
              </div>

    <!-- Right Content -->
    <div class="profile-right">
      <!-- Basic Information -->
      <div class="profile-section">
        <h5 class="section-title">
          <i class="fas fa-user"></i>Basic Information
        </h5>
        <div class="row">
          <div class="col-md-6">
                <label class="form-label">ID:</label>
                <input type="text" class="form-control" disabled value="<?php echo htmlspecialchars($guider['guiderID']); ?>">
          </div>
          <div class="col-md-6">
                <label class="form-label">Username:</label>
                <input type="text" class="form-control" disabled value="<?php echo htmlspecialchars($guider['username']); ?>">
          </div>
          <div class="col-md-6">
                <label class="form-label">Email:</label>
                <input type="text" class="form-control" disabled value="<?php echo htmlspecialchars($guider['email']); ?>">
          </div>
          <div class="col-md-6">
                <label class="form-label">Phone:</label>
                <input type="text" class="form-control" disabled value="<?php echo htmlspecialchars($guider['phone_number']); ?>">
          </div>
          <div class="col-md-6">
                <label class="form-label">Gender:</label>
                <input type="text" class="form-control" disabled value="<?php echo htmlspecialchars($guider['gender']); ?>">
          </div>
          <div class="col-md-6">
                <label class="form-label">Bank Account Number:</label>
                <input type="text" class="form-control" disabled value="<?php echo htmlspecialchars($guider['no_acc'] ?? 'Not specified'); ?>">
          </div>
        </div>
        <button type="button" class="edit-btn mt-3" data-bs-toggle="modal" data-bs-target="#editProfileModal">
          <i class="fas fa-edit"></i> Edit Profile
                </button>
              </div>

          <!-- Skills Section -->
          <div class="profile-section">
            <h5 class="section-title">
              <i class="fas fa-tools"></i>Skills
            </h5>
            <div class="skills-container">
              <?php 
              $availableSkills = ['First Aid', 'Navigation', 'Photography', 'Wildlife Knowledge', 'Weather Reading', 'Emergency Response', 'Group Leadership', 'Equipment Maintenance', 'Local History', 'Language Skills', 'Rock Climbing', 'Camping', 'Trail Running', 'Storytelling', 'Cultural Knowledge', 'Safety Training', 'GPS Navigation', 'Map Reading', 'Survival Skills', 'Team Building', 'Communication', 'Problem Solving', 'Mountain Climbing', 'Trail Maintenance', 'Environmental Awareness', 'Hiking Techniques', 'Backpacking', 'Outdoor Cooking', 'Wilderness First Aid', 'Trail Safety'];
              $selectedSkills = !empty($guider['skills']) ? explode(',', $guider['skills']) : [];
              foreach ($availableSkills as $skill): ?>
                <span class="skill-tag <?php echo in_array($skill, $selectedSkills) ? 'selected' : ''; ?>" 
                      data-skill="<?php echo $skill; ?>">
                  <?php echo $skill; ?>
                </span>
              <?php endforeach; ?>
            </div>
            <small class="text-muted">Click on skills to select/deselect them</small>
             </div>

          <!-- Experience Section -->
          <div class="profile-section">
            <h5 class="section-title">
              <i class="fas fa-calendar-alt"></i>Experience Level
            </h5>
            <div class="experience-options">
              <?php 
              $experienceLevels = ['Below 1 year', '1-2 years', '2-4 years', '4-6 years', '6-10 years', '10+ years'];
              $currentExperience = $guider['experience'] ?? '';
              foreach ($experienceLevels as $level): ?>
                <div class="experience-option <?php echo $currentExperience === $level ? 'selected' : ''; ?>" 
                     data-experience="<?php echo $level; ?>">
                  <?php echo $level; ?>
            </div>
              <?php endforeach; ?>
        </div>
      </div>

          <!-- Mountains Section -->
          <div class="profile-section">
            <h5 class="section-title">
              <i class="fas fa-mountain"></i>Mountains Conquered
            </h5>
            <div class="mountains-container">
              <?php 
              $selectedMountains = !empty($guider['mountains']) ? explode(',', $guider['mountains']) : [];
              if (!empty($allMountains)):
                foreach ($allMountains as $mountain): ?>
                  <span class="mountain-tag <?php echo in_array($mountain['name'], $selectedMountains) ? 'selected' : ''; ?>" 
                        data-mountain="<?php echo htmlspecialchars($mountain['name']); ?>">
                    <?php echo htmlspecialchars($mountain['name']); ?>
                  </span>
                <?php endforeach;
              else: ?>
                <p class="text-muted">No mountains available in the system.</p>
              <?php endif; ?>
            </div>
            <small class="text-muted">Click on mountains you have climbed before</small>
          </div>

          <!-- About Section -->
          <div class="profile-section">
            <h5 class="section-title">
              <i class="fas fa-info-circle"></i>About Me
            </h5>
            <textarea class="about-textarea" name="about" placeholder="Tell hikers about yourself, your hiking experience, and what makes you a great guide..."><?php echo htmlspecialchars($guider['about'] ?? ''); ?></textarea>
          </div>

      <!-- Save Changes Button -->
      <div class="profile-section">
        <button type="button" class="btn btn-success w-100" onclick="saveProfileChanges()">
          <i class="fas fa-save"></i> Save All Changes
        </button>
      </div>
    </div>
  </div>
</div>


<!-- Edit Profile Modal -->
<div class="modal fade" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" action="GProfile.php" enctype="multipart/form-data">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="editProfileModalLabel">Edit Profile</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <div class="text-center mb-3">
            <label for="profilePictureInput" class="position-relative d-inline-block" style="cursor: pointer;">
              <img id="modalProfileImagePreview" 
                src="<?php 
                  echo !empty($guider['profile_picture']) ? 
                    (filter_var($guider['profile_picture'], FILTER_VALIDATE_URL) ? 
                      $guider['profile_picture'] : 
                      '../' . $guider['profile_picture']) : 
                    '../default-profile.jpg'; 
                ?>" 
                class="rounded-circle" 
                style="width:120px; height:120px; object-fit:cover;" 
                alt="Profile">
              <i class="bi bi-camera position-absolute bottom-0 end-0 bg-white p-1 rounded-circle shadow" 
                style="transform: translate(25%, 25%);"></i>
            </label>
            <input type="file" class="form-control" name="profile_picture" id="profilePictureInput" accept="image/*" hidden>
          </div>

          <!-- Editable fields -->
          <label class="form-label">Username:</label>
          <input type="text" class="form-control" name="username" value="<?php echo htmlspecialchars($guider['username']); ?>" required>

          <label class="form-label">Email:</label>
          <input type="email" class="form-control" value="<?php echo htmlspecialchars($guider['email']); ?>" disabled style="background-color: #e9ecef; cursor: not-allowed;">

          <label class="form-label">Phone:</label>
          <input type="text" class="form-control" name="phone_number" value="<?php echo htmlspecialchars($guider['phone_number']); ?>" required>

          <label class="form-label">Bank Account Number:</label>
          <input type="text" class="form-control" name="no_acc" value="<?php echo htmlspecialchars($guider['no_acc'] ?? ''); ?>" placeholder="Enter your bank account number" maxlength="16" minlength="10" pattern="[0-9]{1,16}" title="Bank account number">

          <label class="form-label">Gender:</label>
          <input type="text" class="form-control" value="<?php echo htmlspecialchars($guider['gender'] ?? 'Not specified'); ?>" disabled style="background-color: #e9ecef; cursor: not-allowed;">
        </div> <!-- Correctly closes modal-body -->

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="update_profile" class="btn btn-primary">Save Changes</button>
        </div>
      </div>
    </form>
  </div>
</div>


  <script>
    // Enhanced file upload preview
    const input = document.getElementById('profilePictureInput');
    const imgPreview = document.getElementById('modalProfileImagePreview');
    const mainImgPreview = document.getElementById('profileImagePreview');

    input.addEventListener('change', function () {
      const file = this.files[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = function (e) {
          imgPreview.src = e.target.result;
          // Optional: Update the main profile preview immediately
          mainImgPreview.src = e.target.result;
        };
        reader.readAsDataURL(file);
      }
    });

    // Notification System
    function showNotification(title, message, type = 'info') {
        const container = document.getElementById('notificationContainer');
        
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        
        const iconMap = {
            success: 'fas fa-check',
            warning: 'fas fa-exclamation-triangle',
            error: 'fas fa-times',
            info: 'fas fa-info'
        };
        
        notification.innerHTML = `
            <button class="notification-close" onclick="this.parentElement.remove()" style="position: absolute; top: 0.75rem; right: 0.75rem; background: none; border: none; color: #9ca3af; cursor: pointer; font-size: 1rem; padding: 0; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; border-radius: 50%; transition: all 0.2s ease;">
                <i class="fas fa-times"></i>
            </button>
            <div style="display: flex; align-items: center; margin-bottom: 0.5rem;">
                <div style="width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 0.75rem; font-size: 1rem; color: white; background: linear-gradient(135deg, ${type === 'success' ? '#10b981, #059669' : type === 'error' ? '#ef4444, #dc2626' : '#3b82f6, #1e40af'});">
                    <i class="${iconMap[type]}"></i>
                </div>
                <h4 style="font-size: 1rem; font-weight: 600; color: #1f2937; margin: 0;">${title}</h4>
            </div>
            <p style="font-size: 0.85rem; color: #6b7280; margin: 0; line-height: 1.3;">${message}</p>
        `;
        
        container.appendChild(notification);
        
        // Trigger animation
        setTimeout(() => {
            notification.classList.add('show');
        }, 100);
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => {
                if (notification.parentElement) {
                    notification.remove();
                }
            }, 400);
        }, 5000);
    }

    // Show success notification if profile was updated
    <?php if (isset($_GET['updated'])): ?>
        document.addEventListener('DOMContentLoaded', function() {
            showNotification('Success!', 'Profile updated successfully!', 'success');
        });
    <?php endif; ?>

    <?php if (isset($success)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            showNotification('Success!', '<?php echo addslashes($success); ?>', 'success');
        });
    <?php endif; ?>

    <?php if (isset($error)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            showNotification('Error!', '<?php echo addslashes($error); ?>', 'error');
        });
    <?php endif; ?>

    // Skills Selection
    document.addEventListener('DOMContentLoaded', function() {
        const skillTags = document.querySelectorAll('.skill-tag');
        skillTags.forEach(tag => {
            tag.addEventListener('click', function() {
                this.classList.toggle('selected');
            });
        });

        // Experience Selection
        const experienceOptions = document.querySelectorAll('.experience-option');
        experienceOptions.forEach(option => {
            option.addEventListener('click', function() {
                // Remove selected class from all options
                experienceOptions.forEach(opt => opt.classList.remove('selected'));
                // Add selected class to clicked option
                this.classList.add('selected');
            });
        });

        // Mountain Selection
        const mountainTags = document.querySelectorAll('.mountain-tag');
        mountainTags.forEach(tag => {
            tag.addEventListener('click', function() {
                this.classList.toggle('selected');
            });
        });
    });

    // Save Profile Changes Function
    function saveProfileChanges() {
        const selectedSkills = Array.from(document.querySelectorAll('.skill-tag.selected'))
            .map(tag => tag.dataset.skill);
        
        const selectedMountains = Array.from(document.querySelectorAll('.mountain-tag.selected'))
            .map(tag => tag.dataset.mountain);
        
        const selectedExperience = document.querySelector('.experience-option.selected')?.dataset.experience || '';
        const aboutText = document.querySelector('.about-textarea').value;

        // Create form data
        const formData = new FormData();
        formData.append('update_profile', '1');
        formData.append('username', '<?php echo htmlspecialchars($guider['username']); ?>');
        formData.append('email', '<?php echo htmlspecialchars($guider['email']); ?>');
        formData.append('phone_number', '<?php echo htmlspecialchars($guider['phone_number']); ?>');
        formData.append('no_acc', '<?php echo htmlspecialchars($guider['no_acc'] ?? ''); ?>');
        formData.append('gender', '<?php echo htmlspecialchars($guider['gender']); ?>');
        formData.append('profile_picture', '<?php echo htmlspecialchars($guider['profile_picture']); ?>');
        formData.append('skills', selectedSkills.join(','));
        formData.append('experience', selectedExperience);
        formData.append('about', aboutText);
        formData.append('mountains', selectedMountains.join(','));

        // Submit via fetch
        fetch('GProfile.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            showNotification('Success!', 'Profile updated successfully!', 'success');
            // Reload page after a short delay
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        })
        .catch(error => {
            showNotification('Error!', 'Failed to update profile. Please try again.', 'error');
        });
    }
  </script>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
