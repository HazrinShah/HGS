    <?php
session_start();
include '../shared/db_connection.php';

// Session debug: log entry with current user and URL
try {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $fullUrl = $scheme . '://' . $host . $uri;
    error_log("HPayment.php ENTRY: hikerID=" . ($_SESSION['hikerID'] ?? 'N/A') . " URL=" . $fullUrl);
} catch (Throwable $e) { /* ignore */ }

// Check if user is logged in
if (!isset($_SESSION['hikerID'])) {
    // Debug: Log session information
    error_log("HPayment.php - Session hikerID not set. Session data: " . print_r($_SESSION, true));
    header("Location: HLogin.html?error=session_expired");
    exit;
}

$hikerID = $_SESSION['hikerID'];

// Debug: Log successful session
error_log("HPayment.php - User logged in with hikerID: $hikerID");


include '../shared/db_connection.php';
 
// auto cancel booking yang lebih masa sebab tak bayo
// interval kene tuko kalau kau tuko masa 
if (isset($_SESSION['hikerID'])) {
  $hikerID = (int)$_SESSION['hikerID'];
  $sql = "
      UPDATE booking
      SET status = 'cancelled'
      WHERE hikerID = ?
        AND status = 'pending'
        AND (
          DATE(endDate) < CURRENT_DATE
          OR (groupType = 'close' AND created_at < (NOW() - INTERVAL 5 MINUTE))
        )
  ";
  if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('i', $hikerID);
    $stmt->execute();
    error_log('HPayment.php - Auto-cancel affected rows: ' . $stmt->affected_rows);
    $stmt->close();
  }
}

// Fetch pending bookings for the current user
$bookingQuery = "
  SELECT b.*, UNIX_TIMESTAMP(b.created_at) AS createdTs,
         g.username as guiderName, g.price as guiderPrice, m.name, m.picture, bp.qty AS participantQty
  FROM booking b
  JOIN guider g ON b.guiderID = g.guiderID
  JOIN mountain m ON b.mountainID = m.mountainID
  LEFT JOIN bookingParticipant bp ON bp.bookingID = b.bookingID AND bp.hikerID = ?
  WHERE b.status = 'pending'
    AND (
      (b.groupType = 'open' AND bp.hikerID IS NOT NULL)
      OR
      (b.groupType <> 'open' AND b.hikerID = ?)
    )
  ORDER BY b.created_at DESC
";
$stmt = $conn->prepare($bookingQuery);
$stmt->bind_param("ii", $hikerID, $hikerID);
$stmt->execute();
$result = $stmt->get_result();
$pendingBookings = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Debug: Log booking query results
error_log("HPayment.php - Found " . count($pendingBookings) . " pending bookings for hikerID: $hikerID");
if (count($pendingBookings) > 0) {
    foreach($pendingBookings as $booking) {
        error_log("HPayment.php - Booking ID: " . $booking['bookingID'] . ", Mountain: " . $booking['name']);
    }
} else {
    // Check if there are any bookings for this user at all
    $allBookingsQuery = "SELECT bookingID, status FROM booking WHERE hikerID = ? ORDER BY created_at DESC LIMIT 5";
    $stmt = $conn->prepare($allBookingsQuery);
    $stmt->bind_param("i", $hikerID);
    $stmt->execute();
    $result = $stmt->get_result();
    $allBookings = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    error_log("HPayment.php - All bookings for hikerID $hikerID: " . print_r($allBookings, true));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Payment - Hiking Guidance System</title>
  <!-- Bootstrap & FontAwesome -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.3.0/css/all.min.css" />
  <link rel="stylesheet" href="../css/style.css" />
  <style>
    /* Guider Blue Color Scheme - Matching HBooking */
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

    /* Main Content Container - Matching HBooking style */
    .main-content {
      padding: 2rem 0;
      min-height: 100vh;
    }

    /* Section Header - Matching HBooking style */
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

    /* Booking Cards - Matching HBooking card style */
    .booking-card {
      background: white;
      border-radius: 20px;
      padding: 1.5rem;
      box-shadow: 0 10px 30px rgba(30, 64, 175, 0.1);
      border: 1px solid rgba(30, 64, 175, 0.1);
      margin-bottom: 1.5rem;
      transition: all 0.3s ease;
    }

    .booking-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 15px 40px rgba(30, 64, 175, 0.15);
    }

    .booking-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1rem;
      padding-bottom: 1rem;
      border-bottom: 2px solid var(--guider-blue-soft);
    }

    .booking-title {
      color: var(--guider-blue-dark);
      font-weight: 700;
      font-size: 1.25rem;
      margin: 0;
    }

    .booking-status {
      background: linear-gradient(135deg, #f59e0b, #d97706);
      color: white;
      padding: 0.5rem 1rem;
      border-radius: 20px;
      font-size: 0.85rem;
      font-weight: 600;
    }

    .booking-content {
      display: grid;
      grid-template-columns: 1fr 2fr 1fr;
      gap: 1.5rem;
      align-items: center;
      position: relative;
      overflow: visible;
    }

    /* Ensure action buttons remain clickable; keep overall stacking normal so modal stays on top */
    .main-content .container { position: relative; z-index: 1; pointer-events: auto; }
    .booking-card { position: relative; z-index: 1; pointer-events: auto; }
    .booking-actions { position: relative; z-index: 10001; }
    .booking-actions .btn { position: relative; z-index: 10002; }
    /* Ensure click events pass through to buttons */
    .booking-actions, .booking-actions .btn { pointer-events: auto; }
    /* Prevent the image block from intercepting clicks */
    .mountain-image-container, .mountain-image { pointer-events: none; }

    .mountain-image {
      width: 100px;
      height: 100px;
      object-fit: cover;
      border-radius: 15px;
      border: 3px solid var(--guider-blue-soft);
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
      color: var(--guider-blue-dark);
      font-weight: 600;
      font-size: 1rem;
    }

    .booking-price {
      text-align: center;
    }

    .price-amount {
      color: var(--guider-blue-dark);
      font-size: 1.5rem;
      font-weight: 700;
      margin-bottom: 0.5rem;
    }

    .price-label {
      color: #64748b;
      font-size: 0.9rem;
    }

    /* Payment Button - Matching HBooking button style */
    .payment-btn {
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      border: none;
      border-radius: 12px;
      padding: 12px 24px;
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

    /* Empty State */
    .empty-state {
      text-align: center;
      padding: 4rem 2rem;
      background: white;
      border-radius: 20px;
      box-shadow: 0 10px 30px rgba(30, 64, 175, 0.1);
    }

    .empty-state-icon {
      width: 80px;
      height: 80px;
      background: var(--guider-blue-soft);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 1.5rem;
    }

    .empty-state-icon i {
      font-size: 2rem;
      color: var(--guider-blue);
    }

    .empty-state h3 {
      color: var(--guider-blue-dark);
      font-weight: 700;
      margin-bottom: 1rem;
    }

    .empty-state p {
      color: #64748b;
      margin-bottom: 2rem;
    }

    .empty-state-btn {
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      border: none;
      border-radius: 12px;
      padding: 12px 24px;
      font-weight: 600;
      color: white;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(30, 64, 175, 0.3);
      text-decoration: none;
      display: inline-block;
    }

    .empty-state-btn:hover {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(30, 64, 175, 0.4);
      color: white;
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
      .section-title {
        font-size: 1.75rem;
      }
      
      .booking-content {
        grid-template-columns: 1fr;
        gap: 1rem;
        text-align: center;
      }
      
      .booking-details {
        grid-template-columns: 1fr;
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

    /* Custom Popup Notifications */
    .custom-popup-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      display: flex;
      justify-content: center;
      align-items: center;
      z-index: 9999;
      animation: fadeIn 0.3s ease;
    }

    .custom-popup {
      background: white;
      border-radius: 20px;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
      max-width: 400px;
      width: 90%;
      animation: slideIn 0.3s ease;
    }

    .custom-popup-header {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      color: white;
      padding: 1.5rem;
      border-radius: 20px 20px 0 0;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .custom-popup-header h5 {
      margin: 0;
      font-weight: 600;
    }

    .btn-close-popup {
      background: none;
      border: none;
      color: white;
      font-size: 1.2rem;
      cursor: pointer;
      padding: 0;
      width: 30px;
      height: 30px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      transition: background-color 0.2s ease;
    }

    .btn-close-popup:hover {
      background-color: rgba(255, 255, 255, 0.2);
    }

    .custom-popup-body {
      padding: 1.5rem;
    }

    .custom-popup-body p {
      margin-bottom: 0.5rem;
      font-size: 1rem;
    }

    .custom-popup-footer {
      padding: 1rem 1.5rem 1.5rem;
      display: flex;
      gap: 1rem;
      justify-content: flex-end;
    }

    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    @keyframes slideIn {
      from { 
        opacity: 0;
        transform: translateY(-50px) scale(0.9);
      }
      to { 
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }
    /* Ensure modal is above and backdrop uses dim dark (no blur) */
    .modal { z-index: 40000 !important; }
    .modal-dialog { z-index: 40001 !important; }
    .modal-backdrop { z-index: 39990 !important; background: rgba(0,0,0,0.5) !important; }
    .modal-backdrop.show { opacity: 0.5 !important; }
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
<?php include_once '../shared/suspension_banner.php'; ?>

  <!-- Main Content -->
  <main class="main-content">
    <div class="container">
      <!-- Section Header -->
      <div class="section-header">
        <h1 class="section-title">
          <i class="fas fa-credit-card me-3"></i>Payment
        </h1>
        <p class="section-subtitle">Complete payment for your pending hiking bookings</p>
      </div>

      <?php if (empty($pendingBookings)): ?>
        <!-- Empty State -->
        <div class="empty-state">
          <div class="empty-state-icon">
            <i class="fas fa-receipt"></i>
          </div>
          <h3>No Pending Payments</h3>
          <p>You don't have any pending hiking bookings that require payment.</p>
          <a href="HBooking.php" class="empty-state-btn">
            <i class="fas fa-plus me-2"></i>Book a Hiking Trip
          </a>
        </div>
      <?php else: ?>
        <!-- Pending Bookings -->
        <?php foreach ($pendingBookings as $booking): ?>
          <?php
            // Compute open-group closing and payment deadlines (3m recruit + 5m payment)
            $closureTs = null; $paymentDeadlineTs = null; $recruitDeadlineTs = null; $isClosed = false;
            if ($booking['groupType'] === 'open') {
              // Ensure persistent closure table exists (Option B)
              $conn->query("CREATE TABLE IF NOT EXISTS open_group_closure (
                id INT AUTO_INCREMENT PRIMARY KEY,
                guiderID INT NOT NULL,
                mountainID INT NOT NULL,
                startDate DATE NOT NULL,
                endDate DATE NOT NULL,
                closed_at INT NOT NULL,
                UNIQUE KEY uniq_group (guiderID, mountainID, startDate, endDate)
              ) ENGINE=InnoDB");

              $stmt2 = $conn->prepare("\n                SELECT COALESCE(SUM(b2.totalHiker),0) AS existingHikers, UNIX_TIMESTAMP(MIN(b2.created_at)) AS groupStartTs\n                FROM booking b2\n                WHERE b2.mountainID = ?\n                  AND b2.guiderID = ?\n                  AND b2.groupType = 'open'\n                  AND b2.startDate <= ?\n                  AND b2.endDate >= ?\n                  AND b2.status IN ('pending','accepted','paid')\n              ");
              $stmt2->bind_param('iiss', $booking['mountainID'], $booking['guiderID'], $booking['endDate'], $booking['startDate']);
              $stmt2->execute();
              $grp = $stmt2->get_result()->fetch_assoc();
              $stmt2->close();
              $existingHikers = (int)($grp['existingHikers'] ?? 0);
              $groupStartTs = isset($grp['groupStartTs']) && $grp['groupStartTs'] ? (int)$grp['groupStartTs'] : time();
              $recruitDeadlineTs = $groupStartTs + (3 * 60);
              $isClosed = ($existingHikers >= 7) || (time() >= $recruitDeadlineTs);

              // Read persistent closure time if available
              $closureTs = null;
              if ($qclose = $conn->prepare("SELECT closed_at FROM open_group_closure WHERE guiderID = ? AND mountainID = ? AND startDate = ? AND endDate = ? LIMIT 1")) {
                $qclose->bind_param('iiss', $booking['guiderID'], $booking['mountainID'], $booking['startDate'], $booking['endDate']);
                $qclose->execute();
                $cres = $qclose->get_result()->fetch_assoc();
                $qclose->close();
                if ($cres && isset($cres['closed_at'])) { $closureTs = (int)$cres['closed_at']; }
              }

              // If group is closed and no record yet, persist the first close moment
              if ($isClosed && empty($closureTs)) {
                $firstCloseTs = ($existingHikers >= 7) ? time() : $recruitDeadlineTs;
                if ($insc = $conn->prepare("INSERT IGNORE INTO open_group_closure (guiderID, mountainID, startDate, endDate, closed_at) VALUES (?,?,?,?,?)")) {
                  $insc->bind_param('iissi', $booking['guiderID'], $booking['mountainID'], $booking['startDate'], $booking['endDate'], $firstCloseTs);
                  $insc->execute();
                  $insc->close();
                  $closureTs = $firstCloseTs;
                }
                // In case of race and record exists, read again
                if (empty($closureTs)) {
                  if ($q2 = $conn->prepare("SELECT closed_at FROM open_group_closure WHERE guiderID = ? AND mountainID = ? AND startDate = ? AND endDate = ? LIMIT 1")) {
                    $q2->bind_param('iiss', $booking['guiderID'], $booking['mountainID'], $booking['startDate'], $booking['endDate']);
                    $q2->execute();
                    $cres2 = $q2->get_result()->fetch_assoc();
                    $q2->close();
                    if ($cres2 && isset($cres2['closed_at'])) { $closureTs = (int)$cres2['closed_at']; }
                  }
                }
              }

              // If still not closed/persisted, leave paymentDeadlineTs empty until closure
              if (!empty($closureTs)) {
                $paymentDeadlineTs = $closureTs + (5 * 60);
              }
              // Auto-cancel if payment window passed
              if (!empty($paymentDeadlineTs) && $booking['status'] === 'pending' && time() > $paymentDeadlineTs) {
                if ($upd = $conn->prepare("UPDATE booking SET status = 'cancelled' WHERE bookingID = ? AND status = 'pending'")) {
                  $upd->bind_param('i', $booking['bookingID']);
                  $upd->execute();
                  $upd->close();
                }
              }
            } else {
              // Close group: fixed 5-minute payment window from booking creation
              $createdTs = isset($booking['createdTs']) ? (int)$booking['createdTs'] : (strtotime($booking['created_at'] ?? 'now') ?: time());
              $paymentDeadlineTs = $createdTs ? ($createdTs + (5 * 60)) : (time() + (5 * 60));
              // Auto-cancel if past 5-minute window
              if ($booking['status'] === 'pending' && time() > $paymentDeadlineTs) {
                if ($upd = $conn->prepare("UPDATE booking SET status = 'cancelled' WHERE bookingID = ? AND status = 'pending'")) {
                  $upd->bind_param('i', $booking['bookingID']);
                  $upd->execute();
                  $upd->close();
                }
              }
            }
            // Authoritatively fetch the current user's participant qty for open groups (avoid stale/mismatched join data)
            $userQty = (int)($booking['participantQty'] ?? 0);
            if ($booking['groupType'] === 'open') {
              if ($qp = $conn->prepare("SELECT qty FROM bookingParticipant WHERE bookingID = ? AND hikerID = ? LIMIT 1")) {
                $bid = (int)$booking['bookingID'];
                $qp->bind_param('ii', $bid, $hikerID);
                $qp->execute();
                $qr = $qp->get_result()->fetch_assoc();
                $qp->close();
                if ($qr && isset($qr['qty'])) { $userQty = (int)$qr['qty']; }
              }
            }
          ?>
          <div class="booking-card" data-booking-id="<?php echo $booking['bookingID']; ?>" data-created-time="<?php echo $booking['created_at']; ?>" data-created-ts="<?php echo (int)($booking['createdTs'] ?? 0); ?>" data-status="<?php echo htmlspecialchars($booking['status']); ?>" data-recruit-deadline="<?php echo isset($recruitDeadlineTs) ? date('c', $recruitDeadlineTs) : ''; ?>" data-recruit-deadline-ts="<?php echo isset($recruitDeadlineTs) ? $recruitDeadlineTs : ''; ?>" data-payment-deadline="<?php echo isset($paymentDeadlineTs) ? date('c', $paymentDeadlineTs) : ''; ?>" data-payment-deadline-ts="<?php echo isset($paymentDeadlineTs) ? $paymentDeadlineTs : ''; ?>" data-group-type="<?php echo htmlspecialchars($booking['groupType']); ?>" data-closed="<?php echo $isClosed ? '1' : '0'; ?>" data-total-hikers="<?php echo (int)$booking['totalHiker']; ?>" data-participant-qty="<?php echo (int)$userQty; ?>" data-group-price="<?php echo (float)$booking['guiderPrice']; ?>">
            <div class="booking-header">
              <h5 class="booking-title">
                <i class="fas fa-mountain me-2"></i><?php echo htmlspecialchars($booking['name']); ?>
              </h5>
              <span class="booking-status">
                <i class="fas fa-clock me-1"></i>Pending
              </span>
              <?php /* Forming countdown moved into the View button */ ?>
            </div>
            
            <div class="booking-content">
              <div class="mountain-image-container" style="display: flex; justify-content: center; align-items: center;">
                <?php 
                  $raw = $booking['picture'] ?? '';
                  $raw = str_replace('\\', '/', $raw);
                  if ($raw === '' || $raw === null) {
                    $pic = 'https://via.placeholder.com/100';
                  } elseif (strpos($raw, 'http') === 0) {
                    $pic = $raw;
                  } elseif (strpos($raw, '../') === 0) {
                    $pic = $raw;
                  } elseif (strpos($raw, '/') === 0) {
                    $pic = '..' . $raw;
                  } else {
                    $pic = '../' . $raw;
                  }
                ?>
                <img src="<?php echo htmlspecialchars($pic); ?>" 
                     alt="<?php echo htmlspecialchars($booking['name']); ?>" 
                     class="mountain-image">
              </div>
              
              <div class="booking-details">
                <div class="booking-detail">
                  <span class="booking-detail-label"><?php echo ($booking['groupType'] === 'open') ? 'Your Hikers' : 'Number of Hikers'; ?></span>
                  <span class="booking-detail-value"><?php echo htmlspecialchars(($booking['groupType'] === 'open') ? (string)max(0,(int)($booking['participantQty'] ?? 0)) : (string)$booking['totalHiker']); ?></span>
                </div>
                <div class="booking-detail">
                  <span class="booking-detail-label">Start Date</span>
                  <span class="booking-detail-value"><?php echo date('M j, Y', strtotime($booking['startDate'])); ?></span>
                </div>
                <div class="booking-detail">
                  <span class="booking-detail-label">End Date</span>
                  <span class="booking-detail-value"><?php echo date('M j, Y', strtotime($booking['endDate'])); ?></span>
                </div>
              </div>
              
              <?php
              $participantQty = (int)$userQty;
              $isOpen = ($booking['groupType'] === 'open');
              $displayAmount = 0.0;
              if ($isOpen) {
                $totalH = max(1, (int)$booking['totalHiker']);
                $perPerson = ((float)$booking['guiderPrice']) / $totalH;
                $displayAmount = $perPerson * max(0, $participantQty);
              } else {
                $displayAmount = (float)$booking['price'];
              }
            ?>
            <div class="booking-price">
                <div class="price-amount">RM <?php echo number_format($displayAmount, 2); ?></div>
                <div class="price-label"><?php echo $isOpen ? 'Your Amount' : 'Total Amount'; ?></div>
            <div class="booking-actions d-flex gap-2 mt-3">
                  <a href="HPayment1.php?bookingID=<?php echo $booking['bookingID']; ?>"
                     class="btn btn-outline-primary btn-sm"
                     data-action="view"
                     data-view-id="<?php echo $booking['bookingID']; ?>"
                     onclick="return openBookingModal(this);">
                     <i class="fas fa-eye me-1"></i>View
                  </a>
                  <?php
                    // Determine if payment is allowed
                    // Close group: allow immediately if pending and not expired
                    // Open group: allow only after recruit closes (3m or 7 hikers), then within 5m payment window
                    $isExpired = strtotime($booking['endDate'] . ' 23:59:59') < time();
                    $status = isset($booking['status']) ? $booking['status'] : '';
                    $canPay = false;

                    if ($status === 'pending' && !$isExpired) {
                      if ($booking['groupType'] === 'close') {
                        $canPay = (isset($paymentDeadlineTs) && time() <= $paymentDeadlineTs);
                      } else {
                        $canPay = $isClosed && (isset($paymentDeadlineTs) && time() <= $paymentDeadlineTs);
                      }
                    }
                    // Auto-cancel scenarios
                    if ($status === 'pending') {
                      if ($booking['groupType'] === 'open') {
                        if (!empty($paymentDeadlineTs) && time() > $paymentDeadlineTs) {
                          if ($upd = $conn->prepare("UPDATE booking SET status = 'cancelled' WHERE bookingID = ? AND status = 'pending'")) {
                            $upd->bind_param('i', $booking['bookingID']);
                            $upd->execute();
                            $upd->close();
                            $status = 'cancelled';
                            $canPay = false;
                          }
                        }
                      } else { // close group
                        if ($isExpired || (!empty($paymentDeadlineTs) && time() > $paymentDeadlineTs)) {
                          if ($upd2 = $conn->prepare("UPDATE booking SET status = 'cancelled' WHERE bookingID = ? AND status = 'pending'")) {
                            $upd2->bind_param('i', $booking['bookingID']);
                            $upd2->execute();
                            $upd2->close();
                            $status = 'cancelled';
                            $canPay = false;
                          }
                        }
                      }
                    }
                  ?>
                  <?php if (!$canPay): ?>
                    <button type="button" class="btn btn-secondary btn-sm" disabled title="Open group: 3m to form, then 5m to pay (or 7 hikers)">
                      <i class="fas fa-lock me-1"></i>Waiting
                    </button>
                  <?php else: ?>
                    <a href="HPayment1.php?bookingID=<?php echo $booking['bookingID']; ?>" class="btn btn-primary btn-sm">
                      <i class="fas fa-credit-card me-1"></i>Make Payment
                    </a>
                  <?php endif; ?>
                  <button type="button" class="btn btn-outline-danger btn-sm" onclick="cancelBooking(<?php echo $booking['bookingID']; ?>)">
                    <i class="fas fa-times me-1"></i>Cancel
                  </button>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </main>

  <!-- Booking Details Modal -->
  <div class="modal" id="bookingDetailsModal" tabindex="-1" aria-labelledby="bookingDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="bookingDetailsModalLabel">
            <i class="fas fa-info-circle me-2"></i>Booking Details
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" id="bookingDetailsContent">
          <!-- Content will be loaded dynamically -->
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

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
          <div class="notification-progress" style="width: 100%; animation: progress ${duration}ms linear forwards;"></div>
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

    // Add CSS for progress animation
    const style = document.createElement('style');
    style.textContent = `
      @keyframes progress {
        from { width: 100%; }
        to { width: 0%; }
      }
    `;
    document.head.appendChild(style);



    // Delegated listener: store ID only; rendering happens on modal events
    document.addEventListener('click', function(ev){
      const trigger = ev.target.closest('[data-action="view"]');
      if (!trigger) return;
      const idAttr = trigger.getAttribute('data-view-id');
      const id = parseInt(idAttr || '0', 10);
      window.lastViewBookingID = id;
    }, true);


    // After animation completes: rendering handled by openBookingModal()
    document.getElementById('bookingDetailsModal').addEventListener('shown.bs.modal', function(){
      // no-op; openBookingModal attaches a one-time shown handler per open
    });

    // Keyboard accessibility: Enter/Space on View button
    document.addEventListener('keydown', function(ev){
      if (ev.key !== 'Enter' && ev.key !== ' ') return;
      const trigger = ev.target.closest('[data-action="view"]');
      if (!trigger) return;
      const card = trigger.closest('.booking-card');
      if (!card) return;
      ev.preventDefault();
      const id = parseInt(card.getAttribute('data-booking-id') || '0', 10);
      if (id) { openBookingModal(trigger); }
    }, true);

    // Single entry point to open the modal without glitches
    function openBookingModal(triggerEl){
      const idAttr = triggerEl.getAttribute('data-view-id') || (triggerEl.closest('.booking-card')?.getAttribute('data-booking-id') || '0');
      const id = parseInt(idAttr || '0', 10);
      if (!id) return false;
      window.lastViewBookingID = id;
      const modalEl = document.getElementById('bookingDetailsModal');
      const bodyEl = document.getElementById('bookingDetailsContent');
      if (bodyEl) {
        bodyEl.innerHTML = '<div class="d-flex align-items-center justify-content-center py-5"><div class="spinner-border text-primary me-2" role="status"></div><span>Loading booking details...</span></div>';
      }
      // Clean up any stale Bootstrap state/backdrops before opening (defensive)
      document.body.classList.remove('modal-open');
      document.querySelectorAll('.modal-backdrop').forEach(function(el){ el.remove(); });
      // Render details immediately to avoid perceived delay
      try { viewBookingDetails(id); } catch(e) {}
      try {
        const modal = bootstrap ? bootstrap.Modal.getOrCreateInstance(modalEl) : null;
        if (modal) modal.show();
      } catch(e) {}
      return false;
    }

    // Ensure full cleanup when modal is fully hidden
    document.getElementById('bookingDetailsModal').addEventListener('hidden.bs.modal', function(){
      document.body.classList.remove('modal-open');
      document.querySelectorAll('.modal-backdrop').forEach(function(el){ el.remove(); });
    });

    // Helpers to extract values reliably after UI changes
    function getValueByLabel(card, labelText) {
      const rows = card.querySelectorAll('.booking-detail');
      for (const row of rows) {
        const lbl = row.querySelector('.booking-detail-label');
        const val = row.querySelector('.booking-detail-value');
        if (lbl && val && lbl.textContent.trim().toLowerCase().includes(labelText.toLowerCase())) {
          return val.textContent.trim();
        }
      }
      return '';
    }

    // View booking details function
    function viewBookingDetails(bookingID) {
      try { console.debug('[HPayment] viewBookingDetails start for', bookingID); } catch(e){}
      // Find the booking data from the current page
      const bookingCard = document.querySelector(`[data-booking-id="${bookingID}"]`);
      if (!bookingCard) {
        try { console.warn('[HPayment] bookingCard not found for', bookingID); } catch(e){}
        notificationSystem.error('Error', 'Booking details not found');
        return;
      }

      // Extract booking information from the card
      const mountainName = bookingCard.querySelector('.booking-title').textContent.trim();
      const guiderName = getValueByLabel(bookingCard, 'Guider');
      const startDate = getValueByLabel(bookingCard, 'Start Date');
      const endDate = getValueByLabel(bookingCard, 'End Date');
      const groupType = bookingCard.getAttribute('data-group-type') || '';
      const partQty = bookingCard.getAttribute('data-participant-qty') || '';
      const totalGroup = bookingCard.getAttribute('data-total-hikers') || '';
      const totalHikers = (groupType === 'open') ? partQty : totalGroup;
      const price = bookingCard.querySelector('.price-amount').textContent;
      const createdTime = bookingCard.getAttribute('data-created-time');

      // Compute pricing for open groups for display
      const groupPrice = parseFloat(bookingCard.getAttribute('data-group-price') || '0');
      const totalGroupInt = Math.max(1, parseInt(totalGroup || '1', 10));
      const yourQtyInt = Math.max(0, parseInt(partQty || '0', 10));
      const perPerson = groupType === 'open' ? (groupPrice / totalGroupInt) : groupPrice;
      const yourAmount = groupType === 'open' ? (perPerson * yourQtyInt) : groupPrice;

      // Create detailed content (closer to previous version but clearer for open groups)
      const content = `
        <div class="row">
          <div class="col-md-6">
            <h6 class="fw-bold text-primary mb-3">
              <i class="fas fa-mountain me-2"></i>Trip Information
            </h6>
            <div class="mb-2"><strong>Mountain:</strong> ${mountainName}</div>
            <div class="mb-2"><strong>Guider:</strong> ${guiderName}</div>
            <div class="mb-2"><strong>Start Date:</strong> ${startDate}</div>
            <div class="mb-2"><strong>End Date:</strong> ${endDate}</div>
            ${groupType === 'open'
              ? `<div class="mb-2"><strong>Group Total Hikers:</strong> ${totalGroupInt}</div>
                 <div class="mb-2"><strong>Your Hikers:</strong> ${yourQtyInt}</div>`
              : `<div class="mb-2"><strong>Number of Hikers:</strong> ${totalGroupInt}</div>`
            }
          </div>
          <div class="col-md-6">
            <h6 class="fw-bold text-primary mb-3">
              <i class="fas fa-credit-card me-2"></i>Payment Information
            </h6>
            ${groupType === 'open'
              ? `<div class="mb-2"><strong>Group Price:</strong> RM ${groupPrice.toFixed(2)}</div>
                 <div class="mb-2"><strong>Per Person:</strong> RM ${perPerson.toFixed(2)}</div>
                 <div class="mb-2"><strong>Your Amount:</strong> RM ${yourAmount.toFixed(2)}</div>`
              : `<div class="mb-2"><strong>Total Amount:</strong> ${price}</div>`
            }
            <div class="mb-2"><strong>Status:</strong> <span class="badge bg-warning">Pending Payment</span></div>
            <div class="mb-2"><strong>Booking ID:</strong> #${bookingID}</div>
            <div class="alert alert-warning mt-3">
              <i class="fas fa-clock me-2"></i>
              <strong>Payment Deadline:</strong>
              <div class="mt-2">
                <div class="countdown-timer" id="countdown-${bookingID}">
                  <span class="countdown-text">Calculating time remaining...</span>
                </div>
                <small class="text-muted">Complete payment before the timer expires to avoid automatic cancellation.</small>
              </div>
            </div>
          </div>
        </div>
      `;

      // Set content only (modal already shown by openBookingModal)
      document.getElementById('bookingDetailsContent').innerHTML = content;
      
      // Start modal countdown: prefer server-computed payment deadline if provided
      let recruitSec = parseInt(bookingCard.dataset.recruitDeadlineTs || '0', 10);
      let paySec = parseInt(bookingCard.dataset.paymentDeadlineTs || '0', 10);
      const groupTypeAttr = (bookingCard.getAttribute('data-group-type') || '').toLowerCase();
      if (!paySec && groupTypeAttr === 'close') {
        const createdTs = parseInt(bookingCard.getAttribute('data-created-ts') || '0', 10);
        if (createdTs) {
          paySec = createdTs + (5 * 60);
        }
      }
      startCountdown(bookingID, recruitSec ? recruitSec * 1000 : 0, paySec ? paySec * 1000 : 0);
    }


    
    // Countdown in modal: show 5-minute window only after forming ends
    function startCountdown(bookingID, recruitMs, payMs) {
      const el = document.getElementById(`countdown-${bookingID}`);
      if (!el) return;

      let formingTimer = null;
      let payTimer = null;

      const setupModalCleanup = () => {
        const modalEl = document.getElementById('bookingDetailsModal');
        if (!modalEl) return;
        const onHide = () => {
          if (formingTimer) clearInterval(formingTimer);
          if (payTimer) clearInterval(payTimer);
          modalEl.removeEventListener('hidden.bs.modal', onHide);
        };
        modalEl.addEventListener('hidden.bs.modal', onHide);
      };

      const now0 = Date.now();
      if (recruitMs && now0 < recruitMs) {
        const updateForming = () => {
          const n = Date.now();
          if (n < recruitMs) {
            // Clamp display to max 3 minutes to avoid timezone drift showing hours
            const rawLeft = Math.max(0, recruitMs - n);
            const timeLeft = Math.min(rawLeft, 3 * 60 * 1000);
            const minutes = Math.floor(timeLeft / (1000 * 60));
            const seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);
            const timeString = `${minutes}m ${seconds}s`;
            el.innerHTML = `<span class="countdown-text text-info fw-bold">Forming ends in ${timeString}</span>`;
          } else {
            if (formingTimer) clearInterval(formingTimer);
            startPaymentCountdown();
          }
        };
        formingTimer = setInterval(updateForming, 1000);
        updateForming();
        setupModalCleanup();
        return;
      }

      function startPaymentCountdown(){
        if (!payMs) { el.innerHTML = '<span class="text-muted">No payment window info</span>'; return; }
        const updatePay = () => {
          const now = Date.now();
          const timeLeft = payMs - now;
          if (timeLeft <= 0) {
            if (payTimer) clearInterval(payTimer);
            el.innerHTML = '<span class="text-danger fw-bold">EXPIRED - Booking will be cancelled</span>';
            return;
          }
          const hours = Math.floor(timeLeft / (1000 * 60 * 60));
          const minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
          const seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);
          let timeString = '';
          if (hours > 0) timeString += `${hours}h `;
          if (minutes > 0 || hours > 0) timeString += `${minutes}m `;
          timeString += `${seconds}s`;
          let colorClass = 'text-success';
          if (timeLeft < 10 * 1000) {
            colorClass = 'text-danger';
          } else if (timeLeft < 30 * 1000) {
            colorClass = 'text-warning';
          }
          el.innerHTML = `<span class="countdown-text ${colorClass} fw-bold">${timeString}</span>`;
        };
        payTimer = setInterval(updatePay, 1000);
        updatePay();
        setupModalCleanup();
      }

      // If forming already ended when opening modal
      startPaymentCountdown();
    }

    // Cancel booking function
    function cancelBooking(bookingID) {
      // Create custom confirmation popup
      const popup = document.createElement('div');
      popup.className = 'custom-popup-overlay';
      popup.innerHTML = `
        <div class="custom-popup">
          <div class="custom-popup-header">
            <h5><i class="fas fa-times-circle me-2"></i>Cancel Booking</h5>
            <button type="button" class="btn-close-popup" onclick="closePopup(this)">
              <i class="fas fa-times"></i>
            </button>
          </div>
          <div class="custom-popup-body">
            <p>Are you sure you want to cancel this booking?</p>
            <p class="text-muted">This action cannot be undone.</p>
          </div>
          <div class="custom-popup-footer">
            <button type="button" class="btn btn-secondary" onclick="closePopup(this)">Keep Booking</button>
            <button type="button" class="btn btn-danger" onclick="confirmCancel(${bookingID})">Cancel Booking</button>
          </div>
        </div>
      `;
      document.body.appendChild(popup);
    }

    function confirmCancel(bookingID) {
      closePopup();
      // Send AJAX request to cancel booking
      fetch('cancel_booking.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `bookingID=${bookingID}`
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          notificationSystem.success('Success', 'Booking cancelled successfully');
          // Remove the booking card from the page
          const bookingCard = document.querySelector(`[data-booking-id="${bookingID}"]`);
          if (bookingCard) {
            bookingCard.remove();
          }
          // Check if no more bookings
          const remainingBookings = document.querySelectorAll('.booking-card');
          if (remainingBookings.length === 0) {
            location.reload(); // Reload to show "no bookings" message
          }
        } else {
          notificationSystem.error('Error', data.message || 'Failed to cancel booking');
        }
      })
      .catch(error => {
        console.error('Error:', error);
        notificationSystem.error('Error', 'Failed to cancel booking. Please try again.');
      });
    }

    function closePopup() {
      const popup = document.querySelector('.custom-popup-overlay');
      if (popup) {
        popup.remove();
      }
    }

    // Check for payment reminders on page load (5-minute windows)
    document.addEventListener('DOMContentLoaded', function() {
      const bookingCards = document.querySelectorAll('.booking-card');
      let hasExpiringBookings = false;

      // Use server time to compute remaining to avoid timezone/clock skew
      const serverNowEl = document.getElementById('server-time');
      const serverNowSec = parseInt(serverNowEl?.getAttribute('data-server-now-ts') || '0', 10);
      const nowMs = serverNowSec ? (serverNowSec * 1000) : Date.now();
      bookingCards.forEach(card => {
        const bookingID = card.getAttribute('data-booking-id');
        const groupType = (card.getAttribute('data-group-type') || '').toLowerCase();
        const status = (card.getAttribute('data-status') || '').toLowerCase();
        if (status !== 'pending') return;
        let paySec = parseInt(card.getAttribute('data-payment-deadline-ts') || '0', 10);
        if (groupType === 'close') {
          const createdTs = parseInt(card.getAttribute('data-created-ts') || '0', 10);
          if (createdTs) {
            paySec = createdTs + (5 * 60);
          }
        }
        if (!paySec) return; // No payment window yet (e.g., open group not formed)

        const remainingMs = (paySec * 1000) - nowMs;
        // Do not show an error on expiry; server will cancel if needed.
        if (remainingMs <= 0) return;
        if (remainingMs <= 60 * 1000) {
          const secs = Math.ceil(remainingMs / 1000);
          notificationSystem.warning('Payment Deadline Approaching', `Booking #${bookingID} expires in ${secs}s. Please complete payment now.`, 20000);
          hasExpiringBookings = true;
        }
      });

      if (bookingCards.length > 0 && !hasExpiringBookings) {
        notificationSystem.info('Payment Reminder', 'Payment window is 5 minutes: for close groups from creation, for open groups after the group forms.', 20000);
      }
    });
  </script>

  <!-- Server time anchor to avoid client clock skew -->
  <div id="server-time" data-server-now-ts="<?php echo time(); ?>" style="display:none"></div>

  <script>
    (function(){
      function pad(n){ return n < 10 ? '0' + n : '' + n; }
      function fmt(ms){
        const totalSec = Math.max(0, Math.floor(ms / 1000));
        const m = Math.floor(totalSec / 60);
        const s = totalSec % 60;
        return pad(m) + ':' + pad(s);
      }

      // Removed per-card forming countdown on the button per request
      document.addEventListener('DOMContentLoaded', function(){ /* no-op */ });
    })();
  </script>

</body>
</html>
