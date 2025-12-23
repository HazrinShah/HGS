<?php
include '../shared/db_connection.php';
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in
if (!isset($_SESSION['hikerID'])) {
    header("Location: HLogin.html");
    exit();
}

$hikerID = $_SESSION['hikerID'];

// --- Handle AJAX request for deleting a payment method ---
// This block checks for a POST request that isn't a profile or picture update.
if ($_SERVER["REQUEST_METHOD"] == "POST" && empty($_POST)) {
    $data = json_decode(file_get_contents('php://input'), true);

    // Check if the action is to delete a payment
    if (isset($data['action']) && $data['action'] === 'delete_payment') {
        header('Content-Type: application/json');
        $paymentID = $data['paymentID'] ?? null;

        if (!$paymentID) {
            echo json_encode(['success' => false, 'message' => 'Payment ID is required.']);
            exit();
        }

        // Check if the payment method is FPX (cannot be deleted)
        $checkQuery = "SELECT methodType FROM payment_methods WHERE paymentID = ? AND hikerID = ?";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bind_param("ii", $paymentID, $hikerID);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        $paymentMethod = $result->fetch_assoc();
        $checkStmt->close();

        if ($paymentMethod && $paymentMethod['methodType'] === 'FPX') {
            echo json_encode(['success' => false, 'message' => 'FPX payment method cannot be deleted.']);
            exit();
        }

        $stmt = $conn->prepare("DELETE FROM payment_methods WHERE paymentID = ? AND hikerID = ?");
        $stmt->bind_param("ii", $paymentID, $hikerID);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Payment method deleted successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete payment method.']);
        }

        exit(); // Stop script execution after handling the AJAX request
    }
}

// Fetch hiker data from database
$sql = "SELECT hikerID, username, email, phone_number, gender, profile_picture FROM hiker WHERE hikerID = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $hikerID);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $hiker = $result->fetch_assoc();
} else {
    echo "Hiker not found.";
    exit();
}

// Fetch payment methods for the current hiker
$paymentMethods = [];
$paymentQuery = "SELECT paymentID, methodType FROM payment_methods WHERE hikerID = ? ORDER BY paymentID DESC";
$paymentStmt = $conn->prepare($paymentQuery);
$paymentStmt->bind_param("i", $hikerID);
$paymentStmt->execute();
$paymentResult = $paymentStmt->get_result();

while ($payment = $paymentResult->fetch_assoc()) {
    $paymentMethods[] = $payment;
}

// Handle profile update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_profile'])) {
    $username = $_POST['username'];
    $phone = $_POST['phone_number'];

    // Set up upload directory
    $target_dir = "../uploads/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0755, true);
    }

    // Keep existing picture unless new one is uploaded
    $profile_picture_path = $hiker['profile_picture'];

    if (isset($_FILES["profile_picture"]) && $_FILES["profile_picture"]["error"] == UPLOAD_ERR_OK) {
        // Validate file type
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
        $file_type = $_FILES["profile_picture"]["type"];
        
        if (in_array($file_type, $allowed_types)) {
            // Delete old picture if it exists and isn't the default
            if (!empty($profile_picture_path) && $profile_picture_path != 'default-profile.jpg' && file_exists('../' . $profile_picture_path)) {
                unlink('../' . $profile_picture_path);
            }

            // Generate unique filename
            $file_extension = pathinfo($_FILES["profile_picture"]["name"], PATHINFO_EXTENSION);
            $filename = "profile_" . $hikerID . "_" . uniqid() . "." . $file_extension;
            $target_file = $target_dir . $filename;
            
            if (move_uploaded_file($_FILES["profile_picture"]["tmp_name"], $target_file)) {
                // Store only the relative path in database (without ../)
                $profile_picture_path = "uploads/" . $filename;
            } else {
                header("Location: HProfile.php?error=" . urlencode("Failed to upload profile picture. Please try again."));
                exit;
            }
        } else {
            header("Location: HProfile.php?error=" . urlencode("Invalid file type. Please upload only JPEG, PNG, or JPG images."));
            exit;
        }
    }

    // Update query - Only update username and phone_number (email and gender are permanent)
    $sql = "UPDATE hiker SET username=?, phone_number=?, profile_picture=? WHERE hikerID=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssi", $username, $phone, $profile_picture_path, $hikerID);
    
    if ($stmt->execute()) {
        // Refresh the hiker data after update
        $sql = "SELECT * FROM hiker WHERE hikerID=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $hikerID);
        $stmt->execute();
        $result = $stmt->get_result();
        $hiker = $result->fetch_assoc();
        
        header("Location: HProfile.php?updated=1");
        exit;
    } else {
        $error = "Error updating profile: " . $conn->error;
    }
}

// Handle profile picture upload only
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_picture'])) {
    if (isset($_FILES["profile_picture"]) && $_FILES["profile_picture"]["error"] == UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
        $file_type = $_FILES["profile_picture"]["type"];
        
        if (in_array($file_type, $allowed_types)) {
            // Delete old picture if it exists and isn't the default
            if (!empty($hiker['profile_picture']) && $hiker['profile_picture'] != 'default-profile.jpg' && file_exists('../' . $hiker['profile_picture'])) {
                unlink('../' . $hiker['profile_picture']);
            }

            // Generate unique filename
            $file_extension = pathinfo($_FILES["profile_picture"]["name"], PATHINFO_EXTENSION);
            $filename = "profile_" . $hikerID . "_" . uniqid() . "." . $file_extension;
            $target_file = "../uploads/" . $filename;
            
            if (move_uploaded_file($_FILES["profile_picture"]["tmp_name"], $target_file)) {
                // Store only the relative path in database (without ../)
                $db_path = "uploads/" . $filename;
                
                // Update database
                $sql = "UPDATE hiker SET profile_picture=? WHERE hikerID=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("si", $db_path, $hikerID);
                
                if ($stmt->execute()) {
                    $hiker['profile_picture'] = $db_path;
                    header("Location: HProfile.php?picture_updated=1");
                    exit;
                } else {
                    header("Location: HProfile.php?error=" . urlencode("Failed to update profile picture in database. Please try again."));
                    exit;
                }
            } else {
                header("Location: HProfile.php?error=" . urlencode("Failed to upload profile picture. Please try again."));
                exit;
            }
        } else {
            header("Location: HProfile.php?error=" . urlencode("Invalid file type. Please upload only JPEG, PNG, or JPG images."));
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Hiker Profile</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap & Custom CSS -->
     <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.3.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">  

<style>
        body {
          font-family: "Montserrat", sans-serif;
          background-color: #f8fafc;
        }
        
        /* Guider Blue Color Scheme */
        :root {
          --guider-blue: #1e40af;
          --guider-blue-light: #3b82f6;
          --guider-blue-dark: #1e3a8a;
          --guider-blue-accent: #60a5fa;
          --guider-blue-soft: #dbeafe;
        }
        
        .navbar {
          background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue)) !important;
          box-shadow: 0 4px 20px rgba(30, 64, 175, 0.3);
        }

        .navbar-toggler-icon {
          background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='white' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
        }
        
        .btn-warning {
          background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
          border: none;
          color: white;
          font-weight: 600;
        }
        
        .btn-warning:hover {
          background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
          color: white;
        }

        .form-check-input:checked {
          background-color: var(--guider-blue);
          border-color: var(--guider-blue);
        }
        
        .list-group-item:hover {
          background-color: var(--guider-blue-soft);
        }

        /* Enhanced Profile Design */
        .profile-card {
          background: white;
          border-radius: 20px;
          padding: 2rem;
          box-shadow: 0 10px 30px rgba(30, 64, 175, 0.1);
          border: 1px solid rgba(30, 64, 175, 0.1);
          margin-bottom: 2rem;
        }
        
        .profile-header {
          text-align: center;
          margin-bottom: 2rem;
        }
        
        .profile-picture {
          background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light)) !important;
          border: 4px solid var(--guider-blue-dark);
          box-shadow: 0 8px 25px rgba(30, 64, 175, 0.3);
          transition: transform 0.3s ease;
          cursor: pointer;
        }
        
        .profile-picture:hover {
          transform: scale(1.05);
        }
        
        .profile-info {
          background: var(--guider-blue-soft);
          border-radius: 15px;
          padding: 1.5rem;
          margin-bottom: 1rem;
        }
        
        .info-row {
          display: flex;
          align-items: center;
          margin-bottom: 1rem;
          padding: 0.75rem;
          background: white;
          border-radius: 10px;
          box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        
        .info-label {
          font-weight: 600;
          color: var(--guider-blue-dark);
          min-width: 100px;
          margin-right: 1rem;
        }
        
        .info-value {
          background: var(--guider-blue-soft);
          padding: 0.5rem 1rem;
          border-radius: 20px;
          color: var(--guider-blue-dark);
          font-weight: 500;
          flex: 1;
        }
        
        .payment-card {
          background: white;
          border-radius: 20px;
          padding: 2rem;
          box-shadow: 0 10px 30px rgba(30, 64, 175, 0.1);
          border: 1px solid rgba(30, 64, 175, 0.1);
        }
        
        .payment-item {
          background: white;
          border: 2px solid var(--guider-blue-soft);
          border-radius: 15px;
          padding: 1rem;
          margin-bottom: 0.75rem;
          transition: all 0.3s ease;
          cursor: pointer;
        }
        
        .payment-item:hover {
          border-color: var(--guider-blue);
          background: var(--guider-blue-soft);
          transform: translateY(-2px);
          box-shadow: 0 5px 15px rgba(30, 64, 175, 0.2);
        }
        
        .payment-item.selected {
          border-color: var(--guider-blue);
          background: var(--guider-blue-soft);
        }

        /* Enhanced Payment Method Display */
        .payment-icon {
          display: flex;
          align-items: center;
          justify-content: center;
          width: 50px;
          height: 50px;
          background: var(--guider-blue-soft);
          border-radius: 12px;
          transition: all 0.3s ease;
        }

        .payment-item:hover .payment-icon {
          background: var(--guider-blue);
          color: white;
        }

        .payment-details {
          flex: 1;
        }

        .payment-actions {
          display: flex;
          align-items: center;
          gap: 0.5rem;
        }

        .delete-payment-btn {
          transition: all 0.3s ease;
        }

        .delete-payment-btn:hover {
          background-color: #dc3545;
          border-color: #dc3545;
          color: white;
          transform: scale(1.1);
        }
        
        .section-title {
          color: var(--guider-blue-dark);
          font-weight: 700;
          margin-bottom: 1.5rem;
          position: relative;
        }
        
        /* Modal Styles */
        .modal-content {
          border-radius: 20px;
          border: none;
          box-shadow: 0 20px 60px rgba(30, 64, 175, 0.3);
        }
        
        .modal-header {
          background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
          color: white;
          border-radius: 20px 20px 0 0;
        }
        
        .form-control:focus {
          border-color: var(--guider-blue);
          box-shadow: 0 0 0 0.2rem rgba(30, 64, 175, 0.25);
        }

        /* Custom Notification System */
        .notification-container {
          position: fixed;
          bottom: 20px;
          right: 20px;
          z-index: 9999;
          max-width: 400px;
        }

        .notification {
          background: white;
          border-radius: 12px;
          box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
          margin-bottom: 12px;
          padding: 16px 20px;
          display: flex;
          align-items: center;
          gap: 12px;
          border-left: 4px solid;
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

        .notification.hide {
          transform: translateX(100%);
          opacity: 0;
        }

        .notification.success {
          border-left-color: #10b981;
          background: linear-gradient(135deg, #f0fdf4, #ecfdf5);
        }

        .notification.error {
          border-left-color: #ef4444;
          background: linear-gradient(135deg, #fef2f2, #fee2e2);
        }

        .notification.warning {
          border-left-color: #f59e0b;
          background: linear-gradient(135deg, #fffbeb, #fef3c7);
        }

        .notification.info {
          border-left-color: var(--guider-blue);
          background: linear-gradient(135deg, var(--guider-blue-soft), #e0f2fe);
        }

        .notification-icon {
          width: 24px;
          height: 24px;
          display: flex;
          align-items: center;
          justify-content: center;
          border-radius: 50%;
          flex-shrink: 0;
        }

        .notification.success .notification-icon {
          background: #10b981;
          color: white;
        }

        .notification.error .notification-icon {
          background: #ef4444;
          color: white;
        }

        .notification.warning .notification-icon {
          background: #f59e0b;
          color: white;
        }

        .notification.info .notification-icon {
          background: var(--guider-blue);
          color: white;
        }

        .notification-content {
          flex: 1;
          min-width: 0;
        }

        .notification-title {
          font-weight: 600;
          font-size: 14px;
          margin: 0 0 4px 0;
          color: #1f2937;
        }

        .notification-message {
          font-size: 13px;
          margin: 0;
          color: #6b7280;
          line-height: 1.4;
        }

        .notification-close {
          background: none;
          border: none;
          color: #9ca3af;
          cursor: pointer;
          padding: 4px;
          border-radius: 4px;
          transition: all 0.2s ease;
          flex-shrink: 0;
        }

        .notification-close:hover {
          background: rgba(0, 0, 0, 0.1);
          color: #374151;
        }

        .notification-progress {
          position: absolute;
          bottom: 0;
          left: 0;
          height: 3px;
          background: rgba(0, 0, 0, 0.1);
          border-radius: 0 0 12px 12px;
          transition: width linear;
        }

        .notification.success .notification-progress {
          background: #10b981;
        }

        .notification.error .notification-progress {
          background: #ef4444;
        }

        .notification.warning .notification-progress {
          background: #f59e0b;
        }

        .notification.info .notification-progress {
          background: var(--guider-blue);
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
          .notification-container {
            bottom: 10px;
            right: 10px;
            left: 10px;
            max-width: none;
          }
          
          .notification {
            margin-bottom: 8px;
            padding: 12px 16px;
          }
        }
</style>
</head>
<body style="background-color:rgb(243, 243, 243); min-height: 100vh;">

<!-- Custom Notification Container -->
<div class="notification-container" id="notificationContainer"></div>

<!-- Header -->
<header>
  <nav class="navbar">
    <div class="container d-flex align-items-center justify-content-between">
      <button class="navbar-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasNavbar">
        <span class="navbar-toggler-icon"></span>
      </button>
      <h1 class="navbar-title text-white mx-auto">HIKING GUIDANCE SYSTEM</h1>
      <a class="navbar-brand" href="../index.php">
        <img src="../img/logo.png" class="img-fluid logo" alt="HGS Logo">
      </a>
    </div>

    <div class="offcanvas offcanvas-start" id="offcanvasNavbar">
      <div class="offcanvas-header">
        <h5 class="offcanvas-title">Menu</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
      </div>
      <div class="offcanvas-body">
        <ul class="navbar-nav">
          <li class="nav-item"><a class="nav-link" href="HHomePage.php">Home</a></li>
          <li class="nav-item"><a class="nav-link active" href="HProfile.php">Profile</a></li>
          <li class="nav-item"><a class="nav-link" href="HBooking.php">Book Guider</a></li>
          <li class="nav-item"><a class="nav-link" href="HPayment.php">Payment</a></li>
          <li class="nav-item"><a class="nav-link" href="HYourGuider.php">Your Guider</a></li>
          <li class="nav-item"><a class="nav-link" href="HRateReview.php">Rate and Review</a></li>
          <li class="nav-item"><a class="nav-link" href="HBookingHistory.php">Booking History</a></li>
        </ul>
        <form action="../shared/logout.php" method="POST" class="d-flex justify-content-center mt-5">
          <button type="submit" class="btn btn-danger">Logout</button>
        </form>
      </div>
    </div>
  </nav>
</header>
<?php include_once '../shared/suspension_banner.php'; ?>

<!-- Main Content -->
<main>
  <div class="container py-5">
    
    <!-- Profile Section -->
    <div class="profile-card">
      <div class="profile-header">
        <form method="POST" enctype="multipart/form-data" id="pictureForm">
          <input type="hidden" name="update_picture" value="1">
          <input type="file" name="profile_picture" id="profilePictureInput" accept="image/*" style="display: none;" onchange="this.form.submit()">
          <div class="profile-picture rounded-4 d-flex justify-content-center align-items-center" style="width: 150px; height: 150px; margin: 0 auto;" onclick="document.getElementById('profilePictureInput').click()">
            <?php 
            $profilePicPath = !empty($hiker['profile_picture']) ? 
                (filter_var($hiker['profile_picture'], FILTER_VALIDATE_URL) ? 
                    $hiker['profile_picture'] : 
                    '../' . $hiker['profile_picture']) : 
                '../default-profile.jpg';
            ?>
            <!-- Debug: Profile picture path: <?php echo htmlspecialchars($profilePicPath); ?> -->
            <img src="<?php echo $profilePicPath; ?>" alt="Profile Picture" style="width: 100%; height: 100%; object-fit: cover; border-radius: 12px;" onerror="console.log('Image failed to load:', this.src); this.src='../default-profile.jpg';">
          </div>
        </form>
        <h3 class="section-title mt-3">PROFILE INFORMATION</h3>
      </div>
      
      <div class="profile-info">
        <div class="info-row">
          <div class="info-label">Username:</div>
          <div class="info-value"><?php echo htmlspecialchars($hiker['username']); ?></div>
        </div>
        <div class="info-row">
          <div class="info-label">Email:</div>
          <div class="info-value"><?php echo htmlspecialchars($hiker['email']); ?></div>
        </div>
        <div class="info-row">
          <div class="info-label">Phone:</div>
          <div class="info-value"><?php echo !empty($hiker['phone_number']) ? htmlspecialchars($hiker['phone_number']) : 'Not set yet'; ?></div>
        </div>
        <div class="info-row">
          <div class="info-label">Gender:</div>
          <div class="info-value"><?php echo !empty($hiker['gender']) ? htmlspecialchars($hiker['gender']) : 'Not set yet'; ?></div>
        </div>
      </div>
      
      <div class="text-center">
        <button class="btn btn-warning rounded-pill px-4 fw-bold" data-bs-toggle="modal" data-bs-target="#editProfileModal">
          <i class="fa-solid fa-edit me-2"></i>EDIT PROFILE
        </button>
      </div>
    </div>

    <!-- Payment Method Section -->
    <div class="payment-card">
      <h3 class="section-title">PAYMENT METHODS</h3>
      <div id="payment-methods-list">
        <?php if (empty($paymentMethods)): ?>
          <div class="text-center py-4">
            <i class="fa-solid fa-credit-card fa-3x text-muted mb-3"></i>
            <p id="no-payment-methods" class="text-muted mb-0">No payment methods saved yet.</p>
            <small class="text-muted">Add a payment method to make bookings easier.</small>
          </div>
        <?php else: ?>
          <?php foreach ($paymentMethods as $method): ?>
            <div class="payment-item d-flex justify-content-between align-items-center p-3 mb-2 border rounded">
              <div class="d-flex align-items-center">
                <div class="payment-icon me-3">
                  <i class="fa-solid <?= $method['methodType'] === 'Debit Card' ? 'fa-credit-card' : ($method['methodType'] === 'FPX' ? 'fa-university' : 'fa-money-bill-wave') ?> fa-2x text-primary"></i>
                </div>
                <div class="payment-details">
                  <?php if ($method['methodType'] === 'COD'): ?>
                    <div class="fw-bold text-dark">Cash on Delivery (COD)</div>
                    <small class="text-muted">Pay when you meet your guider</small>
                  <?php elseif ($method['methodType'] === 'FPX'): ?>
                    <div class="fw-bold text-dark">FPX Online Banking</div>
                    <small class="text-muted">Pay via online banking</small>
                  <?php elseif ($method['methodType'] === 'Debit Card'): ?>
                    <div class="fw-bold text-dark"><?= htmlspecialchars($method['cardNumber']) ?></div>
                    <?php if (!empty($method['cardName'])): ?>
                      <small class="text-muted"><?= htmlspecialchars($method['cardName']) ?></small>
                    <?php endif; ?>
                    <?php if (!empty($method['expiryDate'])): ?>
                      <small class="text-muted">Expires: <?= htmlspecialchars($method['expiryDate']) ?></small>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
              <div class="payment-actions">
                <?php if ($method['methodType'] !== 'FPX'): ?>
                  <button class="btn btn-sm btn-outline-danger delete-payment-btn" data-payment-id="<?= $method['paymentID'] ?>" title="Delete payment method">
                    <i class="fa-solid fa-trash-alt"></i>
                  </button>
                <?php else: ?>
                  <span class="badge bg-success" title="FPX payment method cannot be deleted">
                    <i class="fa-solid fa-lock me-1"></i>Default
                  </span>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

  </div>
</main>


<!-- Edit Profile Modal -->
<div class="modal fade" id="editProfileModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-edit me-2"></i>Edit Profile</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <div class="modal-body">
          <input type="hidden" name="update_profile" value="1">
          
          <div class="row">
            <div class="col-md-6 mb-3">
              <label for="username" class="form-label">Username</label>
              <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($hiker['username']); ?>" required>
            </div>
            <div class="col-md-6 mb-3">
              <label for="email" class="form-label">Email</label>
              <input type="email" class="form-control" id="email" value="<?php echo htmlspecialchars($hiker['email']); ?>" disabled style="background-color: #e9ecef; cursor: not-allowed;">
            </div>
          </div>
          
          <div class="row">
            <div class="col-md-6 mb-3">
              <label for="phone_number" class="form-label">Phone Number</label>
              <input type="tel" class="form-control" id="phone_number" name="phone_number" value="<?php echo htmlspecialchars($hiker['phone_number']); ?>" placeholder="Enter your phone number">
            </div>
            <div class="col-md-6 mb-3">
              <label for="gender" class="form-label">Gender</label>
              <input type="text" class="form-control" id="gender" value="<?php echo htmlspecialchars($hiker['gender'] ?? 'Not specified'); ?>" disabled style="background-color: #e9ecef; cursor: not-allowed;">
            </div>
          </div>
          
          <div class="mb-3">
            <label for="profile_picture" class="form-label">Profile Picture</label>
            <div class="d-flex align-items-center">
              <div class="profile-picture rounded-4 d-flex justify-content-center align-items-center me-3" style="width: 80px; height: 80px; cursor: pointer;" onclick="document.getElementById('profile_picture').click()">
                <img src="<?php echo $profilePicPath; ?>" alt="Profile Picture" style="width: 100%; height: 100%; object-fit: cover; border-radius: 12px;" onerror="this.src='../default-profile.jpg';">
              </div>
              <div class="flex-grow-1">
                <input type="file" class="form-control" id="profile_picture" name="profile_picture" accept="image/*" style="display: none;">
                <button type="button" class="btn btn-outline-primary btn-sm" onclick="document.getElementById('profile_picture').click()">
                  <i class="fa-solid fa-camera me-1"></i> Change Picture
                </button>
                <div class="form-text mt-1">Click to upload a new profile picture (JPEG, PNG, or JPG)</div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning">
            <i class="fa-solid fa-save me-2"></i>Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {

    // --- Client-Side Validation ---




    // --- Delete Payment Method ---
    const deleteButtons = document.querySelectorAll('.delete-payment-btn');
    deleteButtons.forEach(button => {
        button.addEventListener('click', function() {
            const paymentID = this.getAttribute('data-payment-id');
            
            if (confirm('Are you sure you want to delete this payment method?')) {
                fetch('HProfile.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ action: 'delete_payment', paymentID: paymentID }),
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        notificationSystem.success('Success!', 'Payment method deleted successfully!');
                        // Reload the page to reflect the change
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000);
                    } else {
                        notificationSystem.error('Error!', result.message || 'Could not delete payment method.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    notificationSystem.error('Network Error!', 'A network error occurred. Please try again.');
                });
            }
        });
    });


});

// Custom Notification System
class NotificationSystem {
    constructor() {
        this.container = document.getElementById('notificationContainer');
        this.notifications = new Map();
        this.notificationId = 0;
    }

    show(type, title, message, duration = 5000) {
        const id = ++this.notificationId;
        const notification = this.createNotification(id, type, title, message, duration);
        
        this.container.appendChild(notification);
        this.notifications.set(id, notification);

        // Trigger animation
        setTimeout(() => {
            notification.classList.add('show');
        }, 10);

        // Auto remove
        if (duration > 0) {
            setTimeout(() => {
                this.remove(id);
            }, duration);
        }

        return id;
    }

    createNotification(id, type, title, message, duration) {
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        notification.dataset.id = id;

        const icons = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-triangle',
            warning: 'fa-exclamation-circle',
            info: 'fa-info-circle'
        };

        notification.innerHTML = `
            <div class="notification-icon">
                <i class="fa-solid ${icons[type]}"></i>
            </div>
            <div class="notification-content">
                <div class="notification-title">${title}</div>
                <div class="notification-message">${message}</div>
            </div>
            <button class="notification-close" onclick="notificationSystem.remove(${id})">
                <i class="fa-solid fa-times"></i>
            </button>
            <div class="notification-progress" style="width: 100%; transition-duration: ${duration}ms;"></div>
        `;

        // Start progress bar
        setTimeout(() => {
            const progressBar = notification.querySelector('.notification-progress');
            if (progressBar) {
                progressBar.style.width = '0%';
            }
        }, 10);

        return notification;
    }

    remove(id) {
        const notification = this.notifications.get(id);
        if (notification) {
            notification.classList.add('hide');
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
                this.notifications.delete(id);
            }, 400);
        }
    }

    success(title, message, duration) {
        return this.show('success', title, message, duration);
    }

    error(title, message, duration) {
        return this.show('error', title, message, duration);
    }

    warning(title, message, duration) {
        return this.show('warning', title, message, duration);
    }

    info(title, message, duration) {
        return this.show('info', title, message, duration);
    }
}

// Initialize notification system
const notificationSystem = new NotificationSystem();

// Show notifications based on URL parameters
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    
    if (urlParams.get('updated') === '1') {
        notificationSystem.success('Success!', 'Profile updated successfully!');
        // Clean URL
        window.history.replaceState({}, document.title, window.location.pathname);
    }
    
    if (urlParams.get('picture_updated') === '1') {
        notificationSystem.success('Success!', 'Profile picture updated successfully!');
        // Clean URL
        window.history.replaceState({}, document.title, window.location.pathname);
    }
    
    if (urlParams.get('error')) {
        notificationSystem.error('Error!', decodeURIComponent(urlParams.get('error')));
        // Clean URL
        window.history.replaceState({}, document.title, window.location.pathname);
    }
});

// Profile picture preview in edit modal
document.getElementById('profile_picture').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            // Update the profile picture preview in the edit modal
            const modalImg = document.querySelector('#editProfileModal .profile-picture img');
            if (modalImg) {
                modalImg.src = e.target.result;
            }
        };
        reader.readAsDataURL(file);
    }
});
</script>

<?php include_once '../AIChatbox/chatbox_include.php'; ?>

</body>
</html> 
