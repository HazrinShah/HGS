<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['hikerID'])) {
    header("Location: HLogin.html");
    exit;
}

$hikerID = $_SESSION['hikerID'];
$bookingID = $_GET['bookingID'] ?? null;

if (!$bookingID) {
    header("Location: HPayment.php");
    exit;
}

// Database connection
include '../shared/db_connection.php';

// Fetch specific booking details
$bookingQuery = "SELECT b.*, g.username as guiderName, g.price as guiderPrice, m.name, m.picture
                 FROM booking b 
                 JOIN guider g ON b.guiderID = g.guiderID 
                 JOIN mountain m ON b.mountainID = m.mountainID 
                 WHERE b.bookingID = ? AND b.hikerID = ? AND b.status = 'pending'";
$stmt = $conn->prepare($bookingQuery);
$stmt->bind_param("ii", $bookingID, $hikerID);
$stmt->execute();
$result = $stmt->get_result();
$booking = $result->fetch_assoc();
$stmt->close();

if (!$booking) {
    header("Location: HPayment.php");
    exit;
}



$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Payment Checkout – Hiking Guidance System</title>
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

    /* Main Content Container - Matching HPayment style */
    .main-content {
      padding: 2rem 0;
      min-height: 100vh;
    }

    /* Section Header - Matching HPayment style */
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

    /* Checkout Container - Matching HPayment card style */
    .checkout-container {
      background: white;
      border-radius: 20px;
      padding: 2rem;
      box-shadow: 0 10px 30px rgba(30, 64, 175, 0.1);
      border: 1px solid rgba(30, 64, 175, 0.1);
      margin-bottom: 2rem;
    }

    .checkout-title {
      color: var(--guider-blue-dark);
      font-weight: 700;
      margin-bottom: 1.5rem;
      font-size: 1.5rem;
    }

    /* Booking Summary Card */
    .booking-summary-card {
      background: #f0fdf4;
      border-radius: 15px;
      padding: 1.5rem;
      margin-bottom: 2rem;
      border: 2px solid #10b981;
    }

    .booking-summary-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1rem;
      padding-bottom: 1rem;
      border-bottom: 2px solid #10b981;
    }

    .booking-summary-title {
      color: #059669;
      font-weight: 700;
      font-size: 1.25rem;
      margin: 0;
    }

    .booking-summary-content {
      display: grid;
      grid-template-columns: 1fr 2fr 1fr;
      gap: 1.5rem;
      align-items: center;
    }

    .mountain-image {
      width: 100px;
      height: 100px;
      object-fit: cover;
      border-radius: 15px;
      border: 3px solid #10b981;
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

    .booking-price {
      text-align: center;
    }

    .price-amount {
      color: #059669;
      font-size: 1.5rem;
      font-weight: 700;
      margin-bottom: 0.5rem;
    }

    .price-label {
      color: #64748b;
      font-size: 0.9rem;
    }

    /* Payment Method Selection */
    .payment-methods-container {
      background: white;
      border-radius: 15px;
      padding: 1.5rem;
      margin-bottom: 2rem;
      box-shadow: 0 5px 15px rgba(30, 64, 175, 0.1);
      border: 1px solid var(--guider-blue-soft);
    }

    .payment-method-card {
      background: #f8fafc;
      border: 2px solid var(--guider-blue-soft);
      border-radius: 12px;
      padding: 1rem;
      margin-bottom: 1rem;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .payment-method-card:hover {
      border-color: var(--guider-blue);
      background: var(--guider-blue-soft);
    }

    .payment-method-card.selected {
      border-color: var(--guider-blue);
      background: var(--guider-blue-soft);
    }

    .payment-method-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 0.5rem;
    }

    .payment-method-type {
      font-weight: 600;
      color: var(--guider-blue-dark);
    }

    .payment-method-radio {
      transform: scale(1.2);
      accent-color: var(--guider-blue);
    }

    .payment-method-details {
      color: #64748b;
      font-size: 0.9rem;
    }

    /* Payment Buttons */
    .payment-actions {
      display: flex;
      gap: 1rem;
      justify-content: center;
    }

    .payment-btn {
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

    .payment-btn:hover {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(30, 64, 175, 0.4);
      color: white;
    }

    .payment-btn:disabled {
      background: #9ca3af;
      cursor: not-allowed;
      transform: none;
      box-shadow: none;
    }

    .back-btn {
      background: linear-gradient(135deg, #f8fafc, #e2e8f0);
      color: var(--guider-blue-dark);
      border: 2px solid var(--guider-blue-soft);
    }

    .back-btn:hover {
      background: linear-gradient(135deg, var(--guider-blue-soft), #dbeafe);
      color: var(--guider-blue-dark);
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
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
      margin-bottom: 15px;
      padding: 16px 20px;
      border-left: 5px solid;
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

    /* Mobile Responsive */
    @media (max-width: 768px) {
      .section-title {
        font-size: 1.75rem;
      }
      
      .booking-summary-content {
        grid-template-columns: 1fr;
        gap: 1rem;
        text-align: center;
      }
      
      .booking-details {
        grid-template-columns: 1fr;
      }
      
      .payment-actions {
        flex-direction: column;
      }
      
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
<body>
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
            <li class="nav-item"><a class="nav-link" href="HHomePage.php">Home</a></li>
            <li class="nav-item"><a class="nav-link" href="HProfile.php">Profile</a></li>
            <li class="nav-item"><a class="nav-link" href="HBooking.php">Book Guider</a></li>
            <li class="nav-item"><a class="nav-link active" href="HPayment.php">Payment</a></li>
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

  <!-- Main Content -->
  <main class="main-content">
    <div class="container">
      <!-- Section Header -->
      <div class="section-header">
        <h1 class="section-title">
          <i class="fas fa-credit-card me-3"></i>Payment Checkout
        </h1>
        <p class="section-subtitle">Complete your payment for the selected hiking booking</p>
        <?php if (isset($_GET['error']) && $_GET['error'] == 'payment_failed'): ?>
          <div class="alert alert-danger mt-3" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            Payment processing failed. Please try again or contact support.
          </div>
        <?php endif; ?>
      </div>

      <!-- Booking Summary -->
      <div class="booking-summary-card">
        <div class="booking-summary-header">
          <h5 class="booking-summary-title">
            <i class="fas fa-receipt me-2"></i>Booking Summary
          </h5>
          <span class="booking-status" style="background: linear-gradient(135deg, #f59e0b, #d97706); color: white; padding: 0.5rem 1rem; border-radius: 20px; font-size: 0.85rem; font-weight: 600;">
            <i class="fas fa-clock me-1"></i>Pending Payment
          </span>
        </div>
        
        <div class="booking-summary-content">
          <div class="mountain-image-container" style="display: flex; justify-content: center; align-items: center;">
            <img src="<?php echo htmlspecialchars(strpos($booking['picture'], 'http') === 0 ? $booking['picture'] : '../' . $booking['picture']); ?>" 
                 alt="<?php echo htmlspecialchars($booking['name']); ?>" 
                 class="mountain-image">
          </div>
          
          <div class="booking-details">
            <div class="booking-detail">
              <span class="booking-detail-label">Mountain</span>
              <span class="booking-detail-value"><?php echo htmlspecialchars($booking['name']); ?></span>
            </div>
            <div class="booking-detail">
              <span class="booking-detail-label">Guider</span>
              <span class="booking-detail-value"><?php echo htmlspecialchars($booking['guiderName']); ?></span>
            </div>
            <div class="booking-detail">
              <span class="booking-detail-label">Start Date</span>
              <span class="booking-detail-value"><?php echo date('M j, Y', strtotime($booking['startDate'])); ?></span>
            </div>
            <div class="booking-detail">
              <span class="booking-detail-label">End Date</span>
              <span class="booking-detail-value"><?php echo date('M j, Y', strtotime($booking['endDate'])); ?></span>
            </div>
            <div class="booking-detail">
              <span class="booking-detail-label">Number of Hikers</span>
              <span class="booking-detail-value"><?php echo $booking['totalHiker']; ?> person(s)</span>
            </div>
            <div class="booking-detail">
              <span class="booking-detail-label">Booking ID</span>
              <span class="booking-detail-value">#<?php echo $booking['bookingID']; ?></span>
            </div>
          </div>
          
          <div class="booking-price">
            <div class="price-amount">RM <?php echo number_format($booking['price'], 2); ?></div>
            <div class="price-label">Total Amount</div>
          </div>
        </div>
      </div>

      <!-- Payment Method -->
      <div class="payment-methods-container">
        <h5 class="checkout-title">
          <i class="fas fa-university me-2"></i>Payment Method
        </h5>
        
        <div class="payment-method-card selected">
          <div class="payment-method-header">
            <span class="payment-method-type">
              <i class="fas fa-university me-2"></i>
              FPX Online Banking
            </span>
            <input type="radio" name="paymentMethod" value="1" 
                   class="payment-method-radio" id="method_fpx" checked>
          </div>
          <div class="payment-method-details">
            <div><strong>FPX Online Banking</strong></div>
            <div>Pay via online banking - secure and convenient</div>
          </div>
        </div>
      </div>

      <!-- Payment Actions -->
      <div class="payment-actions">
        <a href="HPayment.php" class="payment-btn back-btn">
          <i class="fas fa-arrow-left me-2"></i>Back to Payments
        </a>
        <button type="button" class="payment-btn" id="processPaymentBtn" onclick="processPayment()">
          <i class="fas fa-check me-2"></i>Process Payment
        </button>
        </div>
      </form>
    </div>
  </main>

  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <!-- Custom Notification System -->
  <script>
    class NotificationSystem {
      constructor() {
        this.container = document.getElementById('notificationContainer');
      }

      show(type, title, message, duration = 5000) {
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        
        const icons = {
          success: 'fas fa-check',
          error: 'fas fa-times',
          warning: 'fas fa-exclamation-triangle',
          info: 'fas fa-info-circle'
        };

        notification.innerHTML = `
          <div style="display: flex; align-items: flex-start; gap: 12px;">
            <div class="notification-icon">
              <i class="${icons[type]}"></i>
            </div>
            <div class="notification-content">
              <div class="notification-title">${title}</div>
              <div class="notification-message">${message}</div>
            </div>
            <button class="notification-close" onclick="this.parentElement.parentElement.remove()">
              <i class="fas fa-times"></i>
            </button>
          </div>
        `;

        this.container.appendChild(notification);

        // Trigger animation
        setTimeout(() => {
          notification.classList.add('show');
        }, 100);

        // Auto remove
        if (duration > 0) {
          setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => {
              if (notification.parentElement) {
                notification.remove();
              }
            }, 400);
          }, duration);
        }

        return notification;
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


    // Process payment
    function processPayment() {
      console.log('processPayment function called');
      
      // Show loading state
      const btn = document.getElementById('processPaymentBtn');
      const originalText = btn.innerHTML;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
      btn.disabled = true;
      
      console.log('Redirecting to process_payment.php with FPX payment method');
      
      // Redirect to process_payment.php with FPX (paymentMethodID = 1)
      setTimeout(() => {
        window.location.href = '../shared/process_payment.php?bookingID=<?php echo $bookingID; ?>&paymentMethodID=1';
      }, 1000);
    }
  </script>

</body>
</html>
