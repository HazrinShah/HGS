<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['hikerID'])) {
    header("Location: ../hiker/HLogin.html");
    exit;
}

$hikerID = $_SESSION['hikerID'];
$status = $_GET['status'] ?? '';
$billcode = $_GET['billcode'] ?? '';
$order_id = $_GET['order_id'] ?? '';

// Debug logging
error_log("Payment Failed Page - Status: $status, BillCode: $billcode, OrderID: $order_id");
error_log("Payment Failed Page - All GET params: " . json_encode($_GET));

// Database connection
include 'db_connection.php';

// Update payment transaction status to failed
if (!empty($billcode)) {
    $updatePaymentQuery = "UPDATE payment_transactions SET status = 'failed', updatedAt = NOW() WHERE billCode = ?";
    $stmt = $conn->prepare($updatePaymentQuery);
    $stmt->bind_param("s", $billcode);
    $stmt->execute();
    $stmt->close();
    
    // Get booking details for display
    $getBookingQuery = "SELECT pt.*, b.*, g.username as guiderName, m.name as mountainName 
                       FROM payment_transactions pt 
                       JOIN booking b ON pt.bookingID = b.bookingID 
                       JOIN guider g ON b.guiderID = g.guiderID 
                       JOIN mountain m ON b.mountainID = m.mountainID 
                       WHERE pt.billCode = ? AND b.hikerID = ?";
    $stmt = $conn->prepare($getBookingQuery);
    $stmt->bind_param("si", $billcode, $hikerID);
    $stmt->execute();
    $result = $stmt->get_result();
    $bookingData = $result->fetch_assoc();
    $stmt->close();
} else {
    $bookingData = null;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Payment Failed – Hiking Guidance System</title>
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

    .error-icon {
      width: 80px;
      height: 80px;
      background: linear-gradient(135deg, #ef4444, #dc2626);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 2rem;
      animation: errorPulse 2s infinite;
    }

    @keyframes errorPulse {
      0% { transform: scale(1); }
      50% { transform: scale(1.05); }
      100% { transform: scale(1); }
    }

    .result-title {
      color: #dc2626;
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
      background: #fef2f2;
      border-radius: 15px;
      padding: 1.5rem;
      margin: 2rem 0;
      border: 2px solid #fecaca;
      text-align: left;
    }

    .booking-summary-title {
      color: #dc2626;
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
      color: #dc2626;
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

    .retry-btn {
      background: linear-gradient(135deg, #ef4444, #dc2626);
      color: white;
    }

    .retry-btn:hover {
      background: linear-gradient(135deg, #dc2626, #b91c1c);
      color: white;
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
          <i class="fas fa-exclamation-triangle me-3"></i>
          Payment Failed
        </h1>
        <p class="section-subtitle">
          Your payment could not be processed at this time
        </p>
      </div>

      <!-- Payment Result Card -->
      <div class="payment-result-card">
        <div class="error-icon">
          <i class="fas fa-times text-white" style="font-size: 2rem;"></i>
        </div>
        <h2 class="result-title">Payment Failed</h2>
        <p class="result-message">
          We're sorry, but your payment could not be processed successfully. This could be due to various reasons such as insufficient funds, network issues, or payment gateway problems.
          <br><small class="text-muted">(Sandbox Mode - This is a test payment)</small>
        </p>
        
        <?php if ($bookingData): ?>
          <div class="booking-summary">
            <h5 class="booking-summary-title">
              <i class="fas fa-receipt me-2"></i>Booking Details
            </h5>
            <div class="booking-details">
              <div class="booking-detail">
                <span class="booking-detail-label">Mountain</span>
                <span class="booking-detail-value"><?php echo htmlspecialchars($bookingData['mountainName']); ?></span>
              </div>
              <div class="booking-detail">
                <span class="booking-detail-label">Guider</span>
                <span class="booking-detail-value"><?php echo htmlspecialchars($bookingData['guiderName']); ?></span>
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
          <a href="../hiker/HPayment.php" class="action-btn retry-btn">
            <i class="fas fa-redo me-2"></i>Try Payment Again
          </a>
          <a href="../hiker/HBooking.php" class="action-btn secondary-btn">
            <i class="fas fa-home me-2"></i>Back to Home
          </a>
        </div>
      </div>
    </div>
  </main>

  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
