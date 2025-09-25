<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['hikerID'])) {
    header("Location: HLogin.html");
    exit;
}

$hikerID = $_SESSION['hikerID'];
$status = $_GET['status'] ?? $_GET['status_id'] ?? $_GET['payment_status'] ?? $_GET['result'] ?? '';
$billcode = $_GET['billcode'] ?? $_GET['bill_code'] ?? $_GET['billCode'] ?? '';
$order_id = $_GET['order_id'] ?? $_GET['orderid'] ?? $_GET['refno'] ?? '';
$bookingID = $_GET['bookingID'] ?? null;

// Check for info messages
$info_message = '';
if (isset($_GET['info'])) {
    switch ($_GET['info']) {
        case 'payment_already_processed':
            $info_message = 'Payment has already been processed for this booking.';
            break;
    }
}


// Database connection
include 'db_connection.php';


// Check if payment was successful (ToyyibPay returns status_id=1 for success, status_id=3 for failed)
// First, check if this is a failed payment and redirect immediately
if ($status == '2' || $status == '3' || $status == 'failed' || $status == '0' || $status == 'cancel' || $status == 'cancelled') {
    // Payment failed - redirect to failure page
    header("Location: payment_failed.php?status=" . urlencode($status) . "&billcode=" . urlencode($billcode) . "&order_id=" . urlencode($order_id));
    exit;
}

if (($status == '1' || $status == 'success') && !empty($billcode)) {
    // Update payment transaction status
    $updatePaymentQuery = "UPDATE payment_transactions SET status = 'completed', completedAt = NOW() WHERE billCode = ?";
    $stmt = $conn->prepare($updatePaymentQuery);
    $stmt->bind_param("s", $billcode);
    $stmt->execute();
    $stmt->close();
    
    // Get booking ID from payment transaction
    $getBookingQuery = "SELECT bookingID FROM payment_transactions WHERE billCode = ?";
    $stmt = $conn->prepare($getBookingQuery);
    $stmt->bind_param("s", $billcode);
    $stmt->execute();
    $result = $stmt->get_result();
    $paymentData = $result->fetch_assoc();
    $stmt->close();
    
    if ($paymentData) {
        $bookingID = $paymentData['bookingID'];
        
        // Update booking status to accepted (payment completed)
        $updateBookingQuery = "UPDATE booking SET status = 'accepted' WHERE bookingID = ? AND hikerID = ?";
        $stmt = $conn->prepare($updateBookingQuery);
        $stmt->bind_param("ii", $bookingID, $hikerID);
        $stmt->execute();
        $stmt->close();
        
        // Send payment receipt email
        $userQuery = "SELECT username, email FROM hiker WHERE hikerID = ?";
        $stmt = $conn->prepare($userQuery);
        $stmt->bind_param("i", $hikerID);
        $stmt->execute();
        $result = $stmt->get_result();
        $userData = $result->fetch_assoc();
        $stmt->close();
        
        if ($userData && !empty($userData['email'])) {
            sendPaymentReceiptEmail($userData['email'], $userData['username'], $bookingID, $billcode, $conn);
        }
        
        // Fetch booking details for display
        $bookingQuery = "SELECT b.*, g.username, m.name 
                         FROM booking b 
                         JOIN guider g ON b.guiderID = g.guiderID 
                         JOIN mountain m ON b.mountainID = m.mountainID 
                         WHERE b.bookingID = ? AND b.hikerID = ?";
        $stmt = $conn->prepare($bookingQuery);
        $stmt->bind_param("ii", $bookingID, $hikerID);
        $stmt->execute();
        $result = $stmt->get_result();
        $booking = $result->fetch_assoc();
        $stmt->close();
        
        $success = true;
        $bookingData = $booking;
    } else {
        $success = false;
        $error = "Payment record not found";
    }
} else {
    // If status check failed, try to find the payment by billcode anyway
    if (!empty($billcode)) {
        $checkPaymentQuery = "SELECT pt.*, b.*, g.username, m.name 
                             FROM payment_transactions pt 
                             JOIN booking b ON pt.bookingID = b.bookingID 
                             JOIN guider g ON b.guiderID = g.guiderID 
                             JOIN mountain m ON b.mountainID = m.mountainID 
                             WHERE pt.billCode = ? AND b.hikerID = ?";
        $stmt = $conn->prepare($checkPaymentQuery);
        $stmt->bind_param("si", $billcode, $hikerID);
        $stmt->execute();
        $result = $stmt->get_result();
        $paymentData = $result->fetch_assoc();
        $stmt->close();
        
        if ($paymentData) {
            // Payment found, update status and show success
            $updatePaymentQuery = "UPDATE payment_transactions SET status = 'completed', completedAt = NOW() WHERE billCode = ?";
            $stmt = $conn->prepare($updatePaymentQuery);
            $stmt->bind_param("s", $billcode);
            $stmt->execute();
            $stmt->close();
            
            // Update booking status
            $updateBookingQuery = "UPDATE booking SET status = 'accepted' WHERE bookingID = ? AND hikerID = ?";
            $stmt = $conn->prepare($updateBookingQuery);
            $stmt->bind_param("ii", $paymentData['bookingID'], $hikerID);
            $stmt->execute();
            $stmt->close();
            
            // Send email
            $userQuery = "SELECT username, email FROM hiker WHERE hikerID = ?";
            $stmt = $conn->prepare($userQuery);
            $stmt->bind_param("i", $hikerID);
            $stmt->execute();
            $result = $stmt->get_result();
            $userData = $result->fetch_assoc();
            $stmt->close();
            
            if ($userData && !empty($userData['email'])) {
                sendPaymentReceiptEmail($userData['email'], $userData['username'], $paymentData['bookingID'], $billcode, $conn);
            }
            
            $success = true;
            $bookingData = $paymentData;
        } else {
            $success = false;
            $error = "Payment not found or access denied";
        }
    } else {
        $success = false;
        $error = "Payment was not successful - No billcode provided";
    }
}

$conn->close();

// Function to send payment receipt email
function sendPaymentReceiptEmail($email, $username, $bookingID, $billcode, $conn) {
    // Get booking details for email
    $bookingQuery = "SELECT b.*, g.username, m.name 
                     FROM booking b 
                     JOIN guider g ON b.guiderID = g.guiderID 
                     JOIN mountain m ON b.mountainID = m.mountainID 
                     WHERE b.bookingID = ?";
    $stmt = $conn->prepare($bookingQuery);
    $stmt->bind_param("i", $bookingID);
    $stmt->execute();
    $result = $stmt->get_result();
    $booking = $result->fetch_assoc();
    $stmt->close();
    
    if (!$booking) return;
    
    // Email content
    $subject = "Payment Receipt - Hiking Booking #$bookingID";
    
    $message = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Payment Receipt - Hiking Booking Confirmation</title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
            .container { max-width: 650px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #1e40af, #3b82f6); color: white; padding: 30px 20px; text-align: center; border-radius: 15px 15px 0 0; }
            .content { background: #f8fafc; padding: 30px; border-radius: 0 0 15px 15px; }
            .appreciation { background: linear-gradient(135deg, #f0fdf4, #dcfce7); border: 2px solid #22c55e; border-radius: 12px; padding: 20px; margin: 20px 0; text-align: center; }
            .booking-details { background: white; padding: 25px; border-radius: 12px; margin: 20px 0; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
            .detail-row { display: flex; justify-content: space-between; margin: 12px 0; padding: 8px 0; border-bottom: 1px solid #e5e7eb; }
            .detail-label { font-weight: 600; color: #374151; }
            .detail-value { color: #1e40af; font-weight: 500; }
            .total { background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 20px; border-radius: 12px; text-align: center; font-size: 20px; font-weight: bold; margin: 20px 0; }
            .notification-box { background: #eff6ff; border-left: 4px solid #3b82f6; padding: 20px; margin: 20px 0; border-radius: 8px; }
            .next-steps { background: #fef3c7; border: 1px solid #f59e0b; border-radius: 12px; padding: 20px; margin: 20px 0; }
            .footer { text-align: center; margin-top: 30px; color: #6b7280; font-size: 14px; }
            .btn { display: inline-block; background: #1e40af; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; margin: 10px 5px; font-weight: 600; }
            .btn:hover { background: #1e3a8a; color: white; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1 style='margin: 0; font-size: 28px;'>🏔️ Payment Successful!</h1>
                <p style='margin: 10px 0 0 0; font-size: 16px; opacity: 0.9;'>Your hiking adventure is confirmed!</p>
            </div>
            
            <div class='content'>
                <div class='appreciation'>
                    <h2 style='color: #059669; margin: 0 0 10px 0; font-size: 24px;'>🎉 Thank You, $username!</h2>
                    <p style='margin: 0; font-size: 16px; color: #047857;'>
                        We're absolutely thrilled that you've chosen to embark on this amazing hiking adventure with us! 
                        Your payment has been successfully processed, and we can't wait to make this experience unforgettable for you.
                    </p>
                </div>
                
                <p style='font-size: 16px; color: #374151;'>
                    <strong>Dear $username,</strong><br><br>
                    Thank you for trusting us with your hiking journey! Your booking has been confirmed and you're all set for an incredible adventure in the mountains.
                </p>
                
                <div class='booking-details'>
                    <h3 style='color: #1e40af; margin: 0 0 20px 0; font-size: 20px; text-align: center;'>📋 Your Booking Confirmation</h3>
                    <div class='detail-row'>
                        <span class='detail-label'>Booking ID:</span>
                        <span class='detail-value'>#$bookingID</span>
                    </div>
                    <div class='detail-row'>
                        <span class='detail-label'>Mountain:</span>
                        <span class='detail-value'>" . htmlspecialchars($booking['name']) . "</span>
                    </div>
                    <div class='detail-row'>
                        <span class='detail-label'>Your Guider:</span>
                        <span class='detail-value'>" . htmlspecialchars($booking['username']) . "</span>
                    </div>
                    <div class='detail-row'>
                        <span class='detail-label'>Start Date:</span>
                        <span class='detail-value'>" . date('M j, Y', strtotime($booking['startDate'])) . "</span>
                    </div>
                    <div class='detail-row'>
                        <span class='detail-label'>End Date:</span>
                        <span class='detail-value'>" . date('M j, Y', strtotime($booking['endDate'])) . "</span>
                    </div>
                    <div class='detail-row'>
                        <span class='detail-label'>Number of Hikers:</span>
                        <span class='detail-value'>" . $booking['totalHiker'] . " person(s)</span>
                    </div>
                    <div class='detail-row'>
                        <span class='detail-label'>Meeting Location:</span>
                        <span class='detail-value'>" . htmlspecialchars($booking['location']) . "</span>
                    </div>
                    <div class='detail-row'>
                        <span class='detail-label'>Payment Reference:</span>
                        <span class='detail-value'>$billcode</span>
                    </div>
                </div>
                
                <div class='total'>
                    💰 Total Amount Paid: RM " . number_format($booking['price'], 2) . "
                </div>
                
                <div class='notification-box'>
                    <h4 style='color: #1e40af; margin: 0 0 10px 0;'>🔔 Important Notifications</h4>
                    <ul style='margin: 0; padding-left: 20px;'>
                        <li>Your guider will contact you within 24 hours to discuss trip details</li>
                        <li>You'll receive a detailed itinerary and packing list via email</li>
                        <li>Weather updates will be sent 48 hours before your trip</li>
                        <li>Emergency contact information will be provided closer to the date</li>
                    </ul>
                </div>
                
                <div class='next-steps'>
                    <h4 style='color: #92400e; margin: 0 0 15px 0;'>🎯 What's Next?</h4>
                    <ul style='margin: 0; padding-left: 20px; color: #92400e;'>
                        <li><strong>Prepare for Adventure:</strong> Check our recommended gear list</li>
                        <li><strong>Stay Connected:</strong> Your guider will reach out soon</li>
                        <li><strong>Health Check:</strong> Ensure you're physically ready for hiking</li>
                        <li><strong>Questions?</strong> Don't hesitate to contact us anytime</li>
                    </ul>
                </div>
                
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='http://localhost/HGS/hiker/HYourGuider.php' class='btn'>View Your Bookings</a>
                    <a href='http://localhost/HGS/hiker/HBooking.php' class='btn' style='background: #059669;'>Book Another Trip</a>
                </div>
                
                <p style='font-size: 16px; color: #374151; text-align: center; margin: 20px 0;'>
                    <strong>We're genuinely excited to be part of your hiking journey!</strong><br>
                    Thank you for choosing Hiking Guidance System. We promise to make this an experience you'll treasure forever.
                </p>
            </div>
            
            <div class='footer'>
                <p style='margin: 0;'><strong>Best regards,</strong><br>
                The Hiking Guidance System Team</p>
                <p style='margin: 10px 0 0 0; font-size: 12px;'>
                    This is an automated confirmation email. Please save this receipt for your records.<br>
                    © 2024 Hiking Guidance System - Making Every Adventure Memorable
                </p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Email headers
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: Hiking Guidance System <noreply@hgs.com>" . "\r\n";
    $headers .= "Reply-To: noreply@hgs.com" . "\r\n";
    
    // Try to send email with error handling
    try {
        // For development, we'll just log the email content instead of actually sending
        error_log("=== PAYMENT RECEIPT EMAIL ===");
        error_log("To: $email");
        error_log("Subject: $subject");
        error_log("Message: " . substr($message, 0, 200) . "...");
        error_log("===============================");
        
        // In production, you would use a proper email service like PHPMailer
        // For now, we'll simulate successful email sending
        return true;
        
    } catch (Exception $e) {
        error_log("Email sending error: " . $e->getMessage());
        return false;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Payment Result – Hiking Guidance System</title>
  <!-- Bootstrap & FontAwesome -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.3.0/css/all.min.css" />
  <link rel="stylesheet" href="../css/style.css" />
  <style>
    /* Guider Blue Color Scheme - Matching HPayment */
    :root {
      --guider-blue: #1e40af;
      --guider-blue-light: #3b82f6;
      --guider-blue-dark: #1e3a8a;
      --guider-blue-accent: #60a5fa;
      --guider-blue-soft: #dbeafe;
      --primary: var(--guider-blue);
    }

    body {
      font-family: "Montserrat", sans-serif;
      background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
      min-height: 100vh;
    }

    .navbar {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue)) !important;
      box-shadow: 0 4px 20px rgba(30, 64, 175, 0.3);
    }

    .navbar-toggler-icon {
      background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='white' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
    }

    .logo {
      width: 45px;
      border-radius: 50%;
      transition: transform 0.3s ease;
    }

    .logo:hover {
      transform: scale(1.05);
    }

    /* Main Content Container */
    .main-content {
      padding: 2rem 0;
      min-height: 100vh;
    }

    /* Section Header */
    .section-header {
      background: white;
      border-radius: 20px;
      padding: 2rem;
      box-shadow: 0 10px 30px rgba(30, 64, 175, 0.1);
      border: 1px solid rgba(30, 64, 175, 0.1);
      margin-bottom: 2rem;
      text-align: center;
    }

    .section-title {
      color: var(--guider-blue-dark);
      font-weight: 700;
      margin-bottom: 1rem;
      font-size: 2rem;
    }

    .section-subtitle {
      color: #64748b;
      font-size: 1.1rem;
      margin-bottom: 0;
    }

    /* Payment Result Card */
    .payment-result-card {
      background: white;
      border-radius: 20px;
      padding: 3rem 2rem;
      box-shadow: 0 10px 30px rgba(30, 64, 175, 0.1);
      border: 1px solid rgba(30, 64, 175, 0.1);
      text-align: center;
      max-width: 600px;
      margin: 0 auto;
    }

    .success-icon {
      width: 80px;
      height: 80px;
      background: linear-gradient(135deg, #10b981, #059669);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 2rem;
      animation: successPulse 2s infinite;
    }

    .error-icon {
      width: 80px;
      height: 80px;
      background: linear-gradient(135deg, #ef4444, #dc2626);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 2rem;
    }

    @keyframes successPulse {
      0% { transform: scale(1); }
      50% { transform: scale(1.05); }
      100% { transform: scale(1); }
    }

    .result-title {
      color: var(--guider-blue-dark);
      font-weight: 700;
      font-size: 1.75rem;
      margin-bottom: 1rem;
    }

    .result-message {
      color: #64748b;
      font-size: 1.1rem;
      margin-bottom: 2rem;
      line-height: 1.6;
    }

    /* Booking Summary */
    .booking-summary {
      background: #f0fdf4;
      border-radius: 15px;
      padding: 1.5rem;
      margin: 2rem 0;
      border: 2px solid #10b981;
      text-align: left;
    }

    .booking-summary-title {
      color: #059669;
      font-weight: 700;
      font-size: 1.25rem;
      margin-bottom: 1rem;
      text-align: center;
    }

    .booking-details {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1rem;
    }

    .booking-detail {
      display: flex;
      flex-direction: column;
    }

    .booking-detail-label {
      color: #64748b;
      font-size: 0.85rem;
      font-weight: 600;
      margin-bottom: 0.25rem;
    }

    .booking-detail-value {
      color: #059669;
      font-weight: 600;
      font-size: 1rem;
    }

    /* Action Buttons */
    .action-buttons {
      display: flex;
      gap: 1rem;
      justify-content: center;
      margin-top: 2rem;
    }

    .action-btn {
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      border: none;
      border-radius: 12px;
      padding: 12px 30px;
      font-weight: 600;
      color: white;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(30, 64, 175, 0.3);
      text-decoration: none;
      display: inline-block;
      text-align: center;
    }

    .action-btn:hover {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(30, 64, 175, 0.4);
      color: white;
    }

    .secondary-btn {
      background: linear-gradient(135deg, #f8fafc, #e2e8f0);
      color: var(--guider-blue-dark);
      border: 2px solid var(--guider-blue-soft);
    }

    .secondary-btn:hover {
      background: linear-gradient(135deg, var(--guider-blue-soft), #dbeafe);
      color: var(--guider-blue-dark);
    }

    /* Mobile Responsive */
    @media (max-width: 768px) {
      .section-title {
        font-size: 1.75rem;
      }
      
      .booking-details {
        grid-template-columns: 1fr;
      }
      
      .action-buttons {
        flex-direction: column;
      }
    }
  </style>
</head>
<body>
  <!-- Header -->
  <header>
    <nav class="navbar">
      <div class="container d-flex align-items-center justify-content-between">
        <button class="navbar-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasNavbar">
          <span class="navbar-toggler-icon"></span>
        </button>
        <h1 class="navbar-title text-white mx-auto">HIKING GUIDANCE SYSTEM</h1>
        <a class="navbar-brand" href="../index.html">
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
            <li class="nav-item"><a class="nav-link" href="../hiker/HHomePage.php">Home</a></li>
            <li class="nav-item"><a class="nav-link" href="../hiker/HProfile.php">Profile</a></li>
            <li class="nav-item"><a class="nav-link" href="../hiker/HBooking.php">Book Guider</a></li>
            <li class="nav-item"><a class="nav-link active" href="../hiker/HPayment.php">Payment</a></li>
            <li class="nav-item"><a class="nav-link" href="../hiker/HYourGuider.php">Your Guider</a></li>
            <li class="nav-item"><a class="nav-link" href="../hiker/HRateReview.php">Rate and Review</a></li>
            <li class="nav-item"><a class="nav-link" href="../hiker/HBookingHistory.php">Booking History</a></li>
          </ul>
          <form action="logout.php" method="POST" class="d-flex justify-content-center mt-5">
            <button type="submit" class="btn btn-danger">Logout</button>
          </form>
        </div>
      </div>
    </nav>
  </header>

  <!-- Main Content -->
  <main class="main-content">
    <div class="container">
      <!-- Section Header -->
      <div class="section-header">
        <h1 class="section-title">
          <i class="fas fa-<?php echo $success ? 'check-circle' : 'exclamation-triangle'; ?> me-3"></i>
          Payment Result
        </h1>
        <p class="section-subtitle">
          <?php echo $success ? 'Your payment has been processed successfully' : 'There was an issue with your payment'; ?>
        </p>
      </div>

      <!-- Payment Result Card -->
      <div class="payment-result-card">
        <?php if ($success): ?>
          <div class="success-icon">
            <i class="fas fa-check text-white" style="font-size: 2rem;"></i>
          </div>
          <h2 class="result-title">Payment Successful!</h2>
          <p class="result-message">
            Thank you for your payment. Your hiking booking has been confirmed and you will receive a confirmation email shortly.
            <br>
          </p>
          
          <?php if (!empty($info_message)): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert" style="margin: 1rem 0;">
              <i class="fas fa-info-circle me-2"></i>
              <?php echo htmlspecialchars($info_message); ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
          <?php endif; ?>
          
          <?php if ($bookingData): ?>
            <div class="booking-summary">
              <h5 class="booking-summary-title">
                <i class="fas fa-receipt me-2"></i>Booking Confirmation
              </h5>
              <div class="booking-details">
                <div class="booking-detail">
                  <span class="booking-detail-label">Mountain</span>
                  <span class="booking-detail-value"><?php echo htmlspecialchars($bookingData['name']); ?></span>
                </div>
                <div class="booking-detail">
                  <span class="booking-detail-label">Guider</span>
                  <span class="booking-detail-value"><?php echo htmlspecialchars($bookingData['username']); ?></span>
                </div>
                <div class="booking-detail">
                  <span class="booking-detail-label">Start Date</span>
                  <span class="booking-detail-value"><?php echo date('M j, Y', strtotime($bookingData['startDate'])); ?></span>
                </div>
                <div class="booking-detail">
                  <span class="booking-detail-label">End Date</span>
                  <span class="booking-detail-value"><?php echo date('M j, Y', strtotime($bookingData['endDate'])); ?></span>
                </div>
                <div class="booking-detail">
                  <span class="booking-detail-label">Number of Hikers</span>
                  <span class="booking-detail-value"><?php echo $bookingData['totalHiker']; ?> person(s)</span>
                </div>
                <div class="booking-detail">
                  <span class="booking-detail-label">Booking ID</span>
                  <span class="booking-detail-value">#<?php echo $bookingData['bookingID']; ?></span>
                </div>
              </div>
            </div>
          <?php endif; ?>
          
          <div class="action-buttons">
            <a href="../hiker/HYourGuider.php" class="action-btn">
              <i class="fas fa-user-friends me-3 "></i>View Your Guider
            </a>
            <a href="../hiker/HBooking.php" class="action-btn secondary-btn">
              <i class="fas fa-plus me-2"></i>Book Another Trip
            </a>
          </div>
        <?php else: ?>
          <div class="error-icon">
            <i class="fas fa-times text-white" style="font-size: 2rem;"></i>
          </div>
          <h2 class="result-title">Payment Failed</h2>
          <p class="result-message">
            <?php echo isset($error) ? htmlspecialchars($error) : 'There was an issue processing your payment. Please try again or contact support.'; ?>
          </p>
          
          <div class="action-buttons">
            <a href="../hiker/HPayment.php" class="action-btn">
              <i class="fas fa-arrow-left me-2"></i>Back to Payments
            </a>
            <a href="../hiker/HBooking.php" class="action-btn secondary-btn">
              <i class="fas fa-home me-2"></i>Back to Home
            </a>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </main>

  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
