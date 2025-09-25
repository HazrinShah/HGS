<?php
session_start();
if (!isset($_SESSION['guiderID'])) {
    header("Location: GLogin.html");
    exit();
}
$guiderID = $_SESSION['guiderID'];

include '../shared/db_connection.php';

// Handle transfer payment form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfer_payment'])) {
    $bookingID = intval($_POST['bookingID']);
    
    try {
        // Update transfer status in database
        $stmt = $conn->prepare("UPDATE booking SET transfer_status = 'transferred' WHERE bookingID = ? AND guiderID = ? AND status = 'completed'");
        $stmt->bind_param("ii", $bookingID, $guiderID);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $success_message = "Payment transferred successfully!";
        } else {
            $error_message = "Transfer failed. Booking not found or already transferred.";
        }
    } catch (Exception $e) {
        $error_message = "Transfer failed: " . $e->getMessage();
    }
}

// Query for completed bookings ready for payment transfer to guider
$completedBookings = [];
$totalEarnings = 0;

if ($guiderID) {
    // Query for completed bookings ready for payment transfer to guider
    $stmt = $conn->prepare("
        SELECT 
            b.bookingID,
            b.totalHiker,
            b.groupType,
            b.price,
            b.status,
            b.transfer_status,
            b.startDate,
            b.endDate,
            h.username AS hikerName,
            m.name AS location,
            m.picture AS mountainPicture
        FROM booking b
        JOIN hiker h ON b.hikerID = h.hikerID
        JOIN mountain m ON b.mountainID = m.mountainID
        WHERE b.guiderID = ?
        AND b.status = 'completed'
        ORDER BY b.startDate DESC
    ");
    $stmt->bind_param("i", $guiderID);
    $stmt->execute();
    $result = $stmt->get_result();
    $completedBookings = $result->fetch_all(MYSQLI_ASSOC);
    
    // Calculate total earnings
    foreach ($completedBookings as $booking) {
        $totalEarnings += $booking['price'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Earn & Receive – Hiking Guidance System</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <style>
    /* Guider Blue Color Scheme - Matching GBooking */
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

    /* Header - Matching GBooking */
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

    /* Offcanvas Menu - Matching GBooking */
    .offcanvas {
      background-color: var(--light-color);
    }

    .offcanvas-title {
      color: var(--guider-blue-dark);
      font-weight: 600;
    }

    .nav-link {
      color: var(--dark-color);
      font-weight: 500;
      padding: 10px 15px;
      border-radius: 8px;
      margin: 2px 0;
      transition: all 0.3s ease;
    }

    .nav-link:hover, .nav-link.active {
      background-color: rgba(30, 64, 175, 0.1);
      color: var(--guider-blue);
    }
    /* Main Container - Matching GBooking */
    .main-container {
      padding: 2rem;
      max-width: 1400px;
      margin: 0 auto;
      background: linear-gradient(135deg, var(--soft-bg) 0%, #e2e8f0 100%);
      min-height: calc(100vh - 80px);
    }

    .page-header {
      text-align: center;
      margin-bottom: 3rem;
      padding: 2rem 0;
    }

    .page-title {
      font-size: 2.5rem;
      font-weight: 700;
      color: var(--guider-blue-dark);
      margin-bottom: 0.5rem;
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .page-subtitle {
      font-size: 1.1rem;
      color: #64748b;
      font-weight: 500;
    }

    /* Card Container - Matching GBooking Style */
    .card-container {
      background: var(--card-white);
      border-radius: 24px;
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
      border: 1px solid rgba(255, 255, 255, 0.2);
      backdrop-filter: blur(10px);
      padding: 2rem;
      margin-bottom: 2rem;
      position: relative;
      overflow: hidden;
    }

    .card-container::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, var(--guider-blue), var(--guider-blue-light), var(--guider-blue-accent));
    }

    /* Filter Section Styling */
    .status-filter-section, .date-filter-section {
      display: flex;
      align-items: center;
      gap: 1rem;
      background: linear-gradient(135deg, var(--guider-blue-soft), #f1f5ff);
      padding: 1rem 1.5rem;
      border-radius: 16px;
      box-shadow: 0 2px 8px rgba(30, 64, 175, 0.1);
    }

    .filter-label {
      font-weight: 600;
      color: var(--guider-blue-dark);
      margin: 0;
      display: flex;
      align-items: center;
      white-space: nowrap;
    }

    .status-filter-buttons {
      display: flex;
      gap: 0.5rem;
    }

    .status-filter-btn {
      display: flex;
      align-items: center;
      padding: 0.5rem 1rem;
      border: 2px solid var(--guider-blue-soft);
      border-radius: 12px;
      background: white;
      color: var(--guider-blue-dark);
      font-size: 0.85rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .status-filter-btn:hover {
      background: var(--guider-blue-soft);
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(30, 64, 175, 0.2);
    }

    .status-filter-btn.active {
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      color: white;
      border-color: var(--guider-blue);
      box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
    }

    .filter-input {
      border: 2px solid var(--guider-blue-soft);
      border-radius: 12px;
      padding: 0.5rem 1rem;
      font-size: 0.9rem;
      background: white;
      color: var(--guider-blue-dark);
      transition: all 0.3s ease;
    }

    .filter-input:focus {
      outline: none;
      border-color: var(--guider-blue);
      box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
    }

    /* No Data Message */
    .no-data-message {
      text-align: center;
      padding: 4rem 2rem;
      background: linear-gradient(135deg, #f8fafc, #ffffff);
      border-radius: 20px;
      border: 2px dashed var(--guider-blue-soft);
      margin: 2rem 0;
    }

    .no-data-icon {
      font-size: 4rem;
      color: var(--guider-blue-soft);
      margin-bottom: 1.5rem;
    }

    .no-data-message h3 {
      color: var(--guider-blue-dark);
      font-weight: 600;
      margin-bottom: 1rem;
    }

    .no-data-message p {
      color: #64748b;
      margin-bottom: 0.5rem;
    }

    /* Enhanced Location Info */
    .location-info {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
    }

    .location-name {
      font-weight: 600;
      color: var(--guider-blue-dark);
      line-height: 1.4;
    }

    .group-type {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      flex-wrap: wrap;
    }

    .group-badge {
      display: inline-flex;
      align-items: center;
      padding: 0.25rem 0.75rem;
      border-radius: 12px;
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
    }

    .group-badge.private {
      background: linear-gradient(135deg, #fef3c7, #fde68a);
      color: #92400e;
    }

    .group-badge.open {
      background: linear-gradient(135deg, #dbeafe, #bfdbfe);
      color: #1e40af;
    }

    .hiker-count {
      font-size: 0.8rem;
      color: #64748b;
      font-weight: 500;
    }

    /* Enhanced Status Badge */
    .status-badge.status-ready {
      background: linear-gradient(135deg, #fbbf24, #f59e0b);
      color: white;
      padding: 0.4rem 0.8rem;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
    }

    .status-badge.status-transferred {
      background: linear-gradient(135deg, #10b981, #059669);
      color: white;
      padding: 0.4rem 0.8rem;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
    }

    /* Enhanced Total Row */
    .total-info {
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 0.25rem;
    }

    .total-label {
      font-size: 0.9rem;
      opacity: 0.9;
      font-weight: 500;
    }

    .total-amount {
      font-size: 1.5rem;
      font-weight: 700;
    }

    /* Statistics Cards */
    .stats-container {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 1.5rem;
      margin-bottom: 2rem;
    }

    .stat-card {
      background: var(--card-white);
      border-radius: 20px;
      padding: 2rem;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
      border: 1px solid rgba(255, 255, 255, 0.2);
      backdrop-filter: blur(10px);
      display: flex;
      align-items: center;
      gap: 1.5rem;
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
    }

    .stat-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, var(--guider-blue), var(--guider-blue-light), var(--guider-blue-accent));
    }

    .stat-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 20px 40px rgba(30, 64, 175, 0.15);
    }

    .stat-icon {
      width: 60px;
      height: 60px;
      border-radius: 16px;
      background: linear-gradient(135deg, var(--guider-blue-soft), #f1f5ff);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
      color: var(--guider-blue);
      flex-shrink: 0;
    }

    .stat-content {
      flex: 1;
    }

    .stat-value {
      font-size: 1.75rem;
      font-weight: 700;
      color: var(--guider-blue-dark);
      margin-bottom: 0.25rem;
    }

    .stat-label {
      font-size: 0.9rem;
      color: #64748b;
      font-weight: 500;
    }

    /* Notification System */
    .notification {
      position: fixed;
      top: 20px;
      right: 20px;
      background: white;
      border-radius: 12px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
      padding: 1rem;
      z-index: 9999;
      transform: translateX(400px);
      transition: transform 0.3s ease;
      border-left: 4px solid var(--guider-blue);
      min-width: 300px;
    }

    .notification.show {
      transform: translateX(0);
    }

    .notification.success {
      border-left-color: var(--success-color);
    }

    .notification.error {
      border-left-color: var(--danger-color);
    }

    .notification-header {
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .notification-icon {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.2rem;
    }

    .notification.success .notification-icon {
      background: rgba(40, 167, 69, 0.1);
      color: var(--success-color);
    }

    .notification.error .notification-icon {
      background: rgba(220, 53, 69, 0.1);
      color: var(--danger-color);
    }

    .notification-content h4 {
      margin: 0 0 0.25rem 0;
      font-size: 1rem;
      font-weight: 600;
      color: var(--guider-blue-dark);
    }

    .notification-content p {
      margin: 0;
      font-size: 0.9rem;
      color: #64748b;
    }
    /* Table Styling - Modern GBooking Style */
    .table-header {
      display: flex;
      padding: 1rem 1.5rem;
      background: linear-gradient(135deg, var(--guider-blue-soft), #f1f5ff);
      border-radius: 16px;
      margin-bottom: 1rem;
      font-weight: 600;
      color: var(--guider-blue-dark);
      box-shadow: 0 2px 8px rgba(30, 64, 175, 0.1);
    }

    .table-row {
      display: flex;
      align-items: center;
      padding: 1.25rem 1.5rem;
      background: linear-gradient(135deg, #ffffff, #f8fafc);
      border-radius: 16px;
      margin-bottom: 0.75rem;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
      border: 1px solid rgba(30, 64, 175, 0.1);
      transition: all 0.3s ease;
    }

    .table-row:hover {
      box-shadow: 0 8px 25px rgba(30, 64, 175, 0.15);
      transform: translateY(-3px);
      border-color: var(--guider-blue-soft);
    }
    .col-bookingid { flex: 0 0 8%; }
    .col-name { flex: 0 0 15%; }
    .col-date { flex: 0 0 12%; }
    .col-location { flex: 0 0 20%; }
    .col-amount { flex: 0 0 10%; }
    .col-status { flex: 0 0 10%; }
    .col-action { flex: 0 0 15%; text-align: right; }
    .status-badge {
      padding: 5px 10px;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 600;
      display: inline-block;
      background-color: #d4edda;
      color: #155724;
    }
    /* Modern Button Styling - Matching GBooking */
    .btn-print {
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      color: white;
      border: none;
      border-radius: 12px;
      padding: 0.75rem 1.5rem;
      font-size: 0.9rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
      position: relative;
      overflow: hidden;
    }

    .btn-print::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
      transition: left 0.5s;
    }

    .btn-print:hover::before {
      left: 100%;
    }

    .btn-print:hover {
      background: linear-gradient(135deg, var(--guider-blue-light), var(--guider-blue-accent));
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(30, 64, 175, 0.4);
    }

    /* Action Buttons Container */
    .action-buttons {
      display: flex;
      gap: 0.5rem;
      flex-wrap: wrap;
    }

    /* Transfer Button */
    .btn-transfer {
      background: linear-gradient(135deg, var(--success-color), #20c997);
      color: white;
      border: none;
      border-radius: 12px;
      padding: 0.75rem 1.5rem;
      font-size: 0.9rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
      position: relative;
      overflow: hidden;
    }

    .btn-transfer::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
      transition: left 0.5s;
    }

    .btn-transfer:hover::before {
      left: 100%;
    }

    .btn-transfer:hover {
      background: linear-gradient(135deg, #20c997, #17a2b8);
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(40, 167, 69, 0.4);
    }
    .total-row {
      display: flex;
      align-items: center;
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      color: white;
      border-radius: 16px;
      padding: 1.5rem;
      margin-top: 1rem;
      box-shadow: 0 8px 25px rgba(30, 64, 175, 0.3);
      font-weight: 700;
      font-size: 1.1rem;
    }
    .total-row .col-action { text-align: right; }
    /* Responsive Design - Matching GBooking */
    @media (max-width: 992px) {
      .table-header, .table-row, .total-row { flex-wrap: wrap; }
      .col { flex: 0 0 50%; max-width: 50%; margin-bottom: 5px; }
      .col-action { flex: 0 0 100%; max-width: 100%; margin-top: 10px; }
      .main-container {
        padding: 1rem;
      }
      .page-title {
        font-size: 2rem;
      }
    }
    
    @media (max-width: 576px) {
      .col { flex: 0 0 100%; max-width: 100%; }
      .navbar-title { font-size: 18px; }
      .page-title {
        font-size: 1.75rem;
      }
      .card-container {
        padding: 1.5rem;
        border-radius: 16px;
      }
      .btn-print {
        padding: 0.6rem 1.2rem;
        font-size: 0.85rem;
      }
      .stats-container {
        grid-template-columns: 1fr;
        gap: 1rem;
      }
      .stat-card {
        padding: 1.5rem;
      }
      .stat-value {
        font-size: 1.5rem;
      }
      .d-flex.justify-content-between {
        flex-direction: column;
        gap: 1rem;
      }
      .status-filter-section, .date-filter-section {
        padding: 0.75rem 1rem;
      }
      .status-filter-buttons {
        flex-wrap: wrap;
        gap: 0.25rem;
      }
      .status-filter-btn {
        padding: 0.4rem 0.8rem;
        font-size: 0.8rem;
      }
    }
  </style>
</head>
<body>
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
        <a class="navbar-brand" href="../index.html">
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
            <li class="nav-item"><a class="nav-link" href="GProfile.php">Profile</a></li>
            <li class="nav-item"><a class="nav-link active" href="GEarning.php">Earn & Receive</a></li>
            <li class="nav-item"><a class="nav-link" href="GPerformance.php">Performance Review</a></li>
            <form action="../shared/logout.php" method="POST" class="d-flex justify-content-center mt-5" >
              <button type="submit" class="btn btn-outline-danger">Logout</button>
            </form>
          </ul>
        </div>
      </div>
    </nav>
  </header>
<!-- End Header -->

<div class="main-container">
  <!-- Page Header -->
  <div class="page-header">
    <h1 class="page-title">Earn & Receive</h1>
    <p class="page-subtitle">Track your earnings and manage your payments</p>
  </div>

  <!-- Statistics Cards -->
  <div class="stats-container">
    <div class="stat-card">
      <div class="stat-icon">
        <i class="fas fa-wallet"></i>
      </div>
      <div class="stat-content">
        <div class="stat-value">RM <?= number_format($totalEarnings, 0) ?></div>
        <div class="stat-label">Total Earnings</div>
      </div>
    </div>
    
    <div class="stat-card">
      <div class="stat-icon">
        <i class="fas fa-check-circle"></i>
      </div>
      <div class="stat-content">
        <div class="stat-value"><?= count($completedBookings) ?></div>
        <div class="stat-label">Completed Bookings</div>
      </div>
    </div>
    
    <div class="stat-card">
      <div class="stat-icon">
        <i class="fas fa-clock"></i>
      </div>
      <div class="stat-content">
        <div class="stat-value">Ready</div>
        <div class="stat-label">Payment Status</div>
      </div>
    </div>
  </div>

  <!-- Success/Error Messages -->
  <?php if (isset($success_message)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <i class="fas fa-check-circle me-2"></i><?= $success_message ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>
  
  <?php if (isset($error_message)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <i class="fas fa-exclamation-circle me-2"></i><?= $error_message ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Earning Card -->
  <div class="card-container">
    <!-- Filter Section -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div class="status-filter-section">
        <label class="filter-label">
          <i class="fas fa-filter me-2"></i>Filter by Status:
        </label>
        <div class="status-filter-buttons">
          <button class="status-filter-btn active" data-status="all">
            <i class="fas fa-list me-1"></i>All
          </button>
          <button class="status-filter-btn" data-status="ready">
            <i class="fas fa-clock me-1"></i>Ready
          </button>
          <button class="status-filter-btn" data-status="transferred">
            <i class="fas fa-check-circle me-1"></i>Transferred
          </button>
        </div>
      </div>
      
      <div class="date-filter-section">
        <label for="filterDate" class="filter-label">
          <i class="fas fa-calendar-alt me-2"></i>Filter by Date:
        </label>
        <input type="date" id="filterDate" class="filter-input" />
      </div>
    </div>
    <div class="table-header mt-3">
      <div class="col col-bookingid">BookingID</div>
      <div class="col col-name">Hiker Name</div>
      <div class="col col-date">From</div>
      <div class="col col-date">To</div>
      <div class="col col-location">Location</div>
      <div class="col col-amount">Amount</div>
      <div class="col col-status">Status</div>
      <div class="col col-action d-flex justify-content-center align-items-center">Action</div>
    </div>
    <?php if (empty($completedBookings)): ?>
      <div class="no-data-message">
        <div class="no-data-icon">
          <i class="fas fa-wallet"></i>
        </div>
        <h3>No Earnings Yet</h3>
        <p>You don't have any completed bookings ready for payment transfer.</p>
        <p class="text-muted">Earnings will appear here once your completed bookings are processed.</p>
      </div>
    <?php else: ?>
      <?php foreach ($completedBookings as $booking): ?>
        <div class="table-row" data-from="<?= $booking['startDate'] ?>" data-to="<?= $booking['endDate'] ?>" data-booking-id="<?= $booking['bookingID'] ?>">
          <div class="col col-bookingid"><?= htmlspecialchars($booking['bookingID']) ?></div>
          <div class="col col-name"><?= htmlspecialchars($booking['hikerName']) ?></div>
          <div class="col col-date"><?= date('d/m/Y', strtotime($booking['startDate'])) ?></div>
          <div class="col col-date"><?= date('d/m/Y', strtotime($booking['endDate'])) ?></div>
          <div class="col col-location">
            <div class="location-info">
              <div class="location-name"><?= htmlspecialchars($booking['location']) ?></div>
              <div class="group-type">
                <span class="group-badge <?= strtolower($booking['groupType']) ?>">
                  <i class="fas fa-users me-1"></i><?= htmlspecialchars($booking['groupType']) ?>
                </span>
                <span class="hiker-count"><?= $booking['totalHiker'] ?> hiker(s)</span>
              </div>
            </div>
          </div>
          <div class="col col-amount">RM <?= number_format($booking['price'], 0) ?></div>
          <div class="col col-status">
            <?php if ($booking['transfer_status'] === 'transferred'): ?>
              <span class="status-badge status-transferred">
                <i class="fas fa-check-circle me-1"></i>Transferred
              </span>
            <?php else: ?>
              <span class="status-badge status-ready">
                <i class="fas fa-clock me-1"></i>Ready
              </span>
            <?php endif; ?>
          </div>
          <div class="col col-action">
            <div class="action-buttons">
              <button class="btn-print d-flex align-items-center justify-content-center" 
                      onclick="printReceipt(<?= $booking['bookingID'] ?>)">
                <i class="fas fa-print me-2"></i> Print
              </button>
              <?php if ($booking['transfer_status'] !== 'transferred'): ?>
                <form method="POST" style="display: inline;">
                  <input type="hidden" name="bookingID" value="<?= $booking['bookingID'] ?>">
                  <button type="submit" name="transfer_payment" class="btn-transfer d-flex align-items-center justify-content-center">
                    <i class="fas fa-money-bill-transfer me-2"></i> Transfer
                  </button>
                </form>
              <?php else: ?>
                <button class="btn-transfer d-flex align-items-center justify-content-center" disabled>
                  <i class="fas fa-check me-2"></i> Transferred
                </button>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
      
      <div class="total-row d-flex align-items-center justify-content-end">
        <div class="d-flex align-items-center gap-3">
          <div class="total-info">
            <span class="total-label">TOTAL EARNINGS:</span>
            <span class="total-amount">RM <?= number_format($totalEarnings, 0) ?></span>
          </div>
          <button class="btn-print d-flex align-items-center justify-content-center" 
                  onclick="printAllReceipts()">
            <i class="fas fa-print me-2"></i> Print All
          </button>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Date filter functionality
document.getElementById('filterDate').addEventListener('change', function() {
  applyFilters();
});

// Status filter functionality
document.querySelectorAll('.status-filter-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    // Remove active class from all buttons
    document.querySelectorAll('.status-filter-btn').forEach(b => b.classList.remove('active'));
    // Add active class to clicked button
    this.classList.add('active');
    // Apply filters
    applyFilters();
  });
});

// Combined filter function
function applyFilters() {
  const dateFilter = document.getElementById('filterDate').value;
  const activeStatusBtn = document.querySelector('.status-filter-btn.active');
  const statusFilter = activeStatusBtn ? activeStatusBtn.dataset.status : 'all';
  
  document.querySelectorAll('.table-row[data-booking-id]').forEach(row => {
    let showRow = true;
    
    // Apply date filter
    if (dateFilter) {
      const from = row.getAttribute('data-from');
      const to = row.getAttribute('data-to');
      if (!(from <= dateFilter && to >= dateFilter)) {
        showRow = false;
      }
    }
    
    // Apply status filter
    if (statusFilter !== 'all' && showRow) {
      const statusBadge = row.querySelector('.status-badge');
      const statusText = statusBadge.textContent.toLowerCase().trim();
      
      if (statusFilter === 'ready' && !statusText.includes('ready')) {
        showRow = false;
      } else if (statusFilter === 'transferred' && !statusText.includes('transferred')) {
        showRow = false;
      }
    }
    
    // Show or hide row
    row.style.display = showRow ? '' : 'none';
  });
  
  // Update statistics
  updateFilteredStats();
}

// Update statistics based on filtered results
function updateFilteredStats() {
  const visibleRows = document.querySelectorAll('.table-row[data-booking-id]:not([style*="display: none"])');
  const readyCount = Array.from(visibleRows).filter(row => {
    const statusText = row.querySelector('.status-badge').textContent.toLowerCase().trim();
    return statusText.includes('ready');
  }).length;
  
  const transferredCount = Array.from(visibleRows).filter(row => {
    const statusText = row.querySelector('.status-badge').textContent.toLowerCase().trim();
    return statusText.includes('transferred');
  }).length;
  
  // Update stat cards if they exist
  const statCards = document.querySelectorAll('.stat-card');
  if (statCards.length >= 2) {
    statCards[1].querySelector('.stat-value').textContent = visibleRows.length;
  }
}

// Print functionality
function printReceipt(bookingID) {
  // Get the booking row data
  const bookingRow = document.querySelector(`[data-booking-id="${bookingID}"]`);
  if (!bookingRow) {
    showCustomNotification('Error', 'Booking data not found', 'error');
    return;
  }
  
  // Extract data from the row
  const bookingId = bookingRow.querySelector('.col-bookingid').textContent;
  const hikerName = bookingRow.querySelector('.col-name').textContent;
  const startDate = bookingRow.querySelector('.col-date').textContent;
  const endDate = bookingRow.querySelectorAll('.col-date')[1].textContent;
  const location = bookingRow.querySelector('.location-name').textContent;
  const amount = bookingRow.querySelector('.col-amount').textContent;
  
  // Create a new window for printing
  const printWindow = window.open('', '_blank', 'width=800,height=600');
  
  // Create print content with better styling
  const printContent = `
    <!DOCTYPE html>
    <html>
    <head>
      <title>Earning Receipt - Booking #${bookingID}</title>
      <style>
        * { box-sizing: border-box; }
        body { 
          font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
          margin: 0; 
          padding: 20px; 
          background: #f8fafc;
          color: #2d3748;
        }
        .receipt-container {
          max-width: 600px;
          margin: 0 auto;
          background: white;
          border-radius: 12px;
          box-shadow: 0 4px 20px rgba(0,0,0,0.1);
          overflow: hidden;
        }
        .header { 
          background: linear-gradient(135deg, #1e40af, #3b82f6);
          color: white;
          text-align: center; 
          padding: 30px 20px;
        }
        .logo { 
          font-size: 28px; 
          font-weight: bold; 
          margin-bottom: 8px;
        }
        .receipt-title { 
          font-size: 18px; 
          opacity: 0.9;
          margin-bottom: 5px;
        }
        .receipt-date {
          font-size: 14px;
          opacity: 0.8;
        }
        .content {
          padding: 30px;
        }
        .booking-details { 
          margin-bottom: 30px; 
        }
        .detail-row { 
          display: flex; 
          justify-content: space-between; 
          margin: 15px 0; 
          padding: 12px 0; 
          border-bottom: 1px solid #e2e8f0; 
        }
        .detail-label { 
          font-weight: 600; 
          color: #4a5568;
        }
        .detail-value {
          color: #2d3748;
          font-weight: 500;
        }
        .total-section { 
          background: linear-gradient(135deg, #f7fafc, #edf2f7); 
          padding: 25px; 
          border-radius: 12px; 
          text-align: center;
          border: 2px solid #e2e8f0;
        }
        .total-amount { 
          font-size: 32px; 
          font-weight: bold; 
          color: #1e40af; 
          margin-bottom: 10px;
        }
        .status-badge {
          display: inline-block;
          background: linear-gradient(135deg, #fbbf24, #f59e0b);
          color: white;
          padding: 8px 16px;
          border-radius: 20px;
          font-size: 14px;
          font-weight: 600;
          margin-top: 10px;
        }
        .footer { 
          text-align: center; 
          margin-top: 30px; 
          color: #718096; 
          font-size: 12px; 
          padding: 20px;
          border-top: 1px solid #e2e8f0;
        }
        @media print { 
          body { margin: 0; background: white; }
          .receipt-container { box-shadow: none; }
        }
      </style>
    </head>
    <body>
      <div class="receipt-container">
        <div class="header">
          <div class="logo">HIKING GUIDANCE SYSTEM</div>
          <div class="receipt-title">EARNING RECEIPT</div>
          <div class="receipt-date">Generated: ${new Date().toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
          })}</div>
        </div>
        
        <div class="content">
          <div class="booking-details">
            <div class="detail-row">
              <span class="detail-label">Booking ID:</span>
              <span class="detail-value">#${bookingId}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Hiker Name:</span>
              <span class="detail-value">${hikerName}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Location:</span>
              <span class="detail-value">${location}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Trip Duration:</span>
              <span class="detail-value">${startDate} - ${endDate}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Earning Amount:</span>
              <span class="detail-value">${amount}</span>
            </div>
          </div>
          
          <div class="total-section">
            <div class="total-amount">${amount}</div>
            <div class="status-badge">Ready for Transfer</div>
            <div style="margin-top: 15px; color: #718096; font-size: 14px;">
              This booking is completed and ready for earnings transfer to your account
            </div>
          </div>
        </div>
        
        <div class="footer">
          <p><strong>This is a system-generated receipt for your records.</strong></p>
          <p>Hiking Guidance System - Guider Earnings Portal</p>
          <p>For support, contact: hikingguidancesystem@gmail.com</p>
        </div>
      </div>
    </body>
    </html>
  `;
  
  printWindow.document.write(printContent);
  printWindow.document.close();
  
  // Wait for content to load, then print
  printWindow.onload = function() {
    printWindow.focus();
    printWindow.print();
    
    // Close window after printing (with delay)
    setTimeout(() => {
      printWindow.close();
    }, 1000);
  };
}

function printAllReceipts() {
  // Get all visible booking rows (respecting current filters)
  const bookingRows = document.querySelectorAll('.table-row[data-booking-id]:not([style*="display: none"])');
  if (bookingRows.length === 0) {
    showCustomNotification('No Data', 'No bookings available to print with current filters', 'warning');
    return;
  }
  
  // Collect all booking data
  const bookings = [];
  let totalAmount = 0;
  
  bookingRows.forEach(row => {
    const bookingId = row.querySelector('.col-bookingid').textContent;
    const hikerName = row.querySelector('.col-name').textContent;
    const startDate = row.querySelector('.col-date').textContent;
    const endDate = row.querySelectorAll('.col-date')[1].textContent;
    const location = row.querySelector('.location-name').textContent;
    const amount = row.querySelector('.col-amount').textContent;
    const status = row.querySelector('.status-badge').textContent.trim();
    
    // Extract numeric amount for total calculation
    const numericAmount = parseFloat(amount.replace('RM ', '').replace(',', ''));
    totalAmount += numericAmount;
    
    bookings.push({
      id: bookingId,
      hiker: hikerName,
      startDate: startDate,
      endDate: endDate,
      location: location,
      amount: amount,
      status: status
    });
  });
  
  // Create print window
  const printWindow = window.open('', '_blank', 'width=800,height=600');
  
  // Generate booking list HTML
  const bookingListHTML = bookings.map(booking => `
    <div class="booking-item">
      <div class="booking-header">
        <span class="booking-id">#${booking.id}</span>
        <span class="booking-status ${booking.status.toLowerCase().includes('transferred') ? 'transferred' : 'ready'}">${booking.status}</span>
      </div>
      <div class="booking-details">
        <div class="detail-row">
          <span class="label">Hiker:</span>
          <span class="value">${booking.hiker}</span>
        </div>
        <div class="detail-row">
          <span class="label">Location:</span>
          <span class="value">${booking.location}</span>
        </div>
        <div class="detail-row">
          <span class="label">Duration:</span>
          <span class="value">${booking.startDate} - ${booking.endDate}</span>
        </div>
        <div class="detail-row">
          <span class="label">Amount:</span>
          <span class="value amount">${booking.amount}</span>
        </div>
      </div>
    </div>
  `).join('');
  
  const printContent = `
    <!DOCTYPE html>
    <html>
    <head>
      <title>All Earnings Summary</title>
      <style>
        * { box-sizing: border-box; }
        body { 
          font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
          margin: 0; 
          padding: 20px; 
          background: #f8fafc;
          color: #2d3748;
        }
        .summary-container {
          max-width: 800px;
          margin: 0 auto;
          background: white;
          border-radius: 12px;
          box-shadow: 0 4px 20px rgba(0,0,0,0.1);
          overflow: hidden;
        }
        .header { 
          background: linear-gradient(135deg, #1e40af, #3b82f6);
          color: white;
          text-align: center; 
          padding: 30px 20px;
        }
        .logo { 
          font-size: 28px; 
          font-weight: bold; 
          margin-bottom: 8px;
        }
        .summary-title { 
          font-size: 20px; 
          opacity: 0.9;
          margin-bottom: 5px;
        }
        .summary-date {
          font-size: 14px;
          opacity: 0.8;
        }
        .content {
          padding: 30px;
        }
        .summary-stats {
          display: grid;
          grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
          gap: 20px;
          margin-bottom: 30px;
        }
        .stat-card {
          background: linear-gradient(135deg, #f7fafc, #edf2f7);
          padding: 20px;
          border-radius: 12px;
          text-align: center;
          border: 2px solid #e2e8f0;
        }
        .stat-value {
          font-size: 24px;
          font-weight: bold;
          color: #1e40af;
          margin-bottom: 5px;
        }
        .stat-label {
          font-size: 14px;
          color: #64748b;
          font-weight: 500;
        }
        .bookings-section {
          margin-top: 30px;
        }
        .section-title {
          font-size: 18px;
          font-weight: 600;
          color: #1e40af;
          margin-bottom: 20px;
          padding-bottom: 10px;
          border-bottom: 2px solid #e2e8f0;
        }
        .booking-item {
          background: #f8fafc;
          border-radius: 12px;
          padding: 20px;
          margin-bottom: 15px;
          border: 1px solid #e2e8f0;
        }
        .booking-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-bottom: 15px;
        }
        .booking-id {
          font-weight: 600;
          color: #1e40af;
          font-size: 16px;
        }
        .booking-status {
          padding: 4px 12px;
          border-radius: 12px;
          font-size: 12px;
          font-weight: 600;
          text-transform: uppercase;
        }
        .booking-status.ready {
          background: linear-gradient(135deg, #fbbf24, #f59e0b);
          color: white;
        }
        .booking-status.transferred {
          background: linear-gradient(135deg, #10b981, #059669);
          color: white;
        }
        .booking-details {
          display: grid;
          grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
          gap: 10px;
        }
        .detail-row {
          display: flex;
          justify-content: space-between;
          padding: 5px 0;
        }
        .detail-row .label {
          font-weight: 600;
          color: #4a5568;
        }
        .detail-row .value {
          color: #2d3748;
        }
        .detail-row .value.amount {
          font-weight: 700;
          color: #1e40af;
        }
        .footer { 
          text-align: center; 
          margin-top: 30px; 
          color: #718096; 
          font-size: 12px; 
          padding: 20px;
          border-top: 1px solid #e2e8f0;
        }
        @media print { 
          body { margin: 0; background: white; }
          .summary-container { box-shadow: none; }
        }
      </style>
    </head>
    <body>
      <div class="summary-container">
        <div class="header">
          <div class="logo">🏔️ HIKING GUIDANCE SYSTEM</div>
          <div class="summary-title">EARNINGS SUMMARY</div>
          <div class="summary-date">Generated: ${new Date().toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
          })}</div>
        </div>
        
        <div class="content">
          <div class="summary-stats">
            <div class="stat-card">
              <div class="stat-value">${bookings.length}</div>
              <div class="stat-label">Total Bookings</div>
            </div>
            <div class="stat-card">
              <div class="stat-value">RM ${totalAmount.toLocaleString()}</div>
              <div class="stat-label">Total Earnings</div>
            </div>
            <div class="stat-card">
              <div class="stat-value">${bookings.filter(b => b.status.toLowerCase().includes('ready')).length}</div>
              <div class="stat-label">Ready for Transfer</div>
            </div>
            <div class="stat-card">
              <div class="stat-value">${bookings.filter(b => b.status.toLowerCase().includes('transferred')).length}</div>
              <div class="stat-label">Already Transferred</div>
            </div>
          </div>
          
          <div class="bookings-section">
            <div class="section-title">Booking Details</div>
            ${bookingListHTML}
          </div>
        </div>
        
        <div class="footer">
          <p><strong>This is a system-generated summary for your records.</strong></p>
          <p>Hiking Guidance System - Guider Earnings Portal</p>
          <p>For support, contact: support@hgs.com</p>
        </div>
      </div>
    </body>
    </html>
  `;
  
  printWindow.document.write(printContent);
  printWindow.document.close();
  
  // Wait for content to load, then print
  printWindow.onload = function() {
    printWindow.focus();
    printWindow.print();
    
    // Close window after printing (with delay)
    setTimeout(() => {
      printWindow.close();
    }, 1000);
  };
}

// Transfer payment functionality
function transferPayment(bookingID) {
  // Get booking details for confirmation
  const bookingRow = document.querySelector(`[data-booking-id="${bookingID}"]`);
  if (!bookingRow) {
    showCustomNotification('Error', 'Booking data not found', 'error');
    return;
  }
  
  const bookingId = bookingRow.querySelector('.col-bookingid').textContent;
  const amount = bookingRow.querySelector('.col-amount').textContent;
  const hikerName = bookingRow.querySelector('.col-name').textContent;
  
  // Create custom confirmation modal
  showTransferConfirmation(bookingID, bookingId, amount, hikerName);
}

// Custom transfer confirmation modal
function showTransferConfirmation(bookingID, bookingId, amount, hikerName) {
  // Create modal overlay
  const modalOverlay = document.createElement('div');
  modalOverlay.className = 'transfer-modal-overlay';
  modalOverlay.innerHTML = `
    <div class="transfer-modal">
      <div class="transfer-modal-header">
        <div class="transfer-icon">
          <i class="fas fa-money-bill-transfer"></i>
        </div>
        <h3>Confirm Payment Transfer</h3>
        <button class="close-modal" onclick="closeTransferModal()">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <div class="transfer-modal-body">
        <div class="transfer-details">
          <div class="detail-item">
            <span class="label">Booking ID:</span>
            <span class="value">#${bookingId}</span>
          </div>
          <div class="detail-item">
            <span class="label">Hiker:</span>
            <span class="value">${hikerName}</span>
          </div>
          <div class="detail-item">
            <span class="label">Amount:</span>
            <span class="value amount">${amount}</span>
          </div>
        </div>
        
        <div class="transfer-warning">
          <i class="fas fa-exclamation-triangle"></i>
          <p>This action will initiate a payment transfer from HGS to your account. The transfer will be processed within 1-2 business days.</p>
        </div>
        
        <div class="transfer-actions">
          <button class="btn-cancel" onclick="closeTransferModal()">
            <i class="fas fa-times me-2"></i>Cancel
          </button>
          <button class="btn-confirm" onclick="confirmTransfer(${bookingID})">
            <i class="fas fa-check me-2"></i>Confirm Transfer
          </button>
        </div>
      </div>
    </div>
  `;
  
  document.body.appendChild(modalOverlay);
  
  // Add CSS for modal
  if (!document.getElementById('transfer-modal-styles')) {
    const styles = document.createElement('style');
    styles.id = 'transfer-modal-styles';
    styles.textContent = `
      .transfer-modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10000;
        backdrop-filter: blur(5px);
      }
      
      .transfer-modal {
        background: white;
        border-radius: 20px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        max-width: 500px;
        width: 90%;
        max-height: 90vh;
        overflow: hidden;
        animation: modalSlideIn 0.3s ease;
      }
      
      @keyframes modalSlideIn {
        from {
          opacity: 0;
          transform: translateY(-50px) scale(0.9);
        }
        to {
          opacity: 1;
          transform: translateY(0) scale(1);
        }
      }
      
      .transfer-modal-header {
        background: linear-gradient(135deg, #1e40af, #3b82f6);
        color: white;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        position: relative;
      }
      
      .transfer-icon {
        width: 50px;
        height: 50px;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
      }
      
      .transfer-modal-header h3 {
        margin: 0;
        font-size: 20px;
        font-weight: 600;
      }
      
      .close-modal {
        position: absolute;
        top: 15px;
        right: 15px;
        background: none;
        border: none;
        color: white;
        font-size: 18px;
        cursor: pointer;
        padding: 5px;
        border-radius: 50%;
        width: 30px;
        height: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background 0.2s;
      }
      
      .close-modal:hover {
        background: rgba(255, 255, 255, 0.2);
      }
      
      .transfer-modal-body {
        padding: 25px;
      }
      
      .transfer-details {
        background: #f8fafc;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 20px;
      }
      
      .detail-item {
        display: flex;
        justify-content: space-between;
        margin-bottom: 10px;
        padding: 8px 0;
      }
      
      .detail-item:last-child {
        margin-bottom: 0;
      }
      
      .detail-item .label {
        font-weight: 600;
        color: #4a5568;
      }
      
      .detail-item .value {
        color: #2d3748;
      }
      
      .detail-item .value.amount {
        font-weight: 700;
        color: #1e40af;
        font-size: 18px;
      }
      
      .transfer-warning {
        background: linear-gradient(135deg, #fef3c7, #fde68a);
        border: 1px solid #f59e0b;
        border-radius: 12px;
        padding: 15px;
        margin-bottom: 25px;
        display: flex;
        align-items: flex-start;
        gap: 10px;
      }
      
      .transfer-warning i {
        color: #d97706;
        font-size: 18px;
        margin-top: 2px;
      }
      
      .transfer-warning p {
        margin: 0;
        color: #92400e;
        font-size: 14px;
        line-height: 1.5;
      }
      
      .transfer-actions {
        display: flex;
        gap: 15px;
        justify-content: flex-end;
      }
      
      .btn-cancel, .btn-confirm {
        padding: 12px 24px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.3s ease;
        border: none;
        display: flex;
        align-items: center;
      }
      
      .btn-cancel {
        background: #f1f5f9;
        color: #64748b;
      }
      
      .btn-cancel:hover {
        background: #e2e8f0;
        transform: translateY(-2px);
      }
      
      .btn-confirm {
        background: linear-gradient(135deg, #10b981, #059669);
        color: white;
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
      }
      
      .btn-confirm:hover {
        background: linear-gradient(135deg, #059669, #047857);
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4);
      }
    `;
    document.head.appendChild(styles);
  }
}

function closeTransferModal() {
  const modal = document.querySelector('.transfer-modal-overlay');
  if (modal) {
    modal.remove();
  }
}

function confirmTransfer(bookingID) {
  closeTransferModal();
  
  // Get the transfer button
  const button = document.querySelector(`[onclick="transferPayment(${bookingID})"]`);
  const originalText = button.innerHTML;
  
  // Show loading state
  button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Transferring...';
  button.disabled = true;
  
  // Call backend API
  fetch('shared/transfer_payment.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      bookingID: bookingID
    })
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      // Show success notification
      showCustomNotification(
        'Transfer Successful!', 
        `Payment of RM ${data.amount} has been transferred successfully to your account.`,
        'success'
      );
      
      // Update the row to show "Transferred" status
      const bookingRow = document.querySelector(`[data-booking-id="${bookingID}"]`);
      if (bookingRow) {
        const statusBadge = bookingRow.querySelector('.status-badge');
        statusBadge.innerHTML = '<i class="fas fa-check-circle me-1"></i>Transferred';
        statusBadge.className = 'status-badge status-transferred';
        
        // Disable the transfer button
        button.innerHTML = '<i class="fas fa-check me-2"></i> Transferred';
        button.disabled = true;
        button.style.background = '#6b7280';
      }
    } else {
      // Show error notification
      showCustomNotification(
        'Transfer Failed', 
        data.message || 'An error occurred during transfer. Please try again.',
        'error'
      );
      
      // Reset button
      button.innerHTML = originalText;
      button.disabled = false;
    }
  })
  .catch(error => {
    console.error('Transfer error:', error);
    showCustomNotification(
      'Transfer Failed', 
      'Network error occurred. Please check your connection and try again.',
      'error'
    );
    
    // Reset button
    button.innerHTML = originalText;
    button.disabled = false;
  });
}

// Simple notification system (keeping for print functions)
function showCustomNotification(title, message, type = 'info') {
  // Create notification element
  const notification = document.createElement('div');
  notification.className = `custom-notification ${type}`;
  
  // Get appropriate icon and colors
  let icon, bgColor, borderColor;
  switch(type) {
    case 'success':
      icon = 'fas fa-check-circle';
      bgColor = 'linear-gradient(135deg, #10b981, #059669)';
      borderColor = '#10b981';
      break;
    case 'error':
      icon = 'fas fa-exclamation-circle';
      bgColor = 'linear-gradient(135deg, #ef4444, #dc2626)';
      borderColor = '#ef4444';
      break;
    case 'warning':
      icon = 'fas fa-exclamation-triangle';
      bgColor = 'linear-gradient(135deg, #f59e0b, #d97706)';
      borderColor = '#f59e0b';
      break;
    default:
      icon = 'fas fa-info-circle';
      bgColor = 'linear-gradient(135deg, #3b82f6, #1e40af)';
      borderColor = '#3b82f6';
  }
  
  notification.innerHTML = `
    <div class="notification-content">
      <div class="notification-icon" style="background: ${bgColor};">
        <i class="${icon}"></i>
      </div>
      <div class="notification-text">
        <h4>${title}</h4>
        <p>${message}</p>
      </div>
      <button class="notification-close" onclick="closeNotification(this)">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <div class="notification-progress"></div>
  `;
  
  // Add to page
  document.body.appendChild(notification);
  
  // Show notification with animation
  setTimeout(() => {
    notification.classList.add('show');
    // Start progress bar
    const progress = notification.querySelector('.notification-progress');
    progress.style.animation = 'progressBar 5s linear forwards';
  }, 100);
  
  // Auto remove after 5 seconds
  setTimeout(() => {
    closeNotification(notification.querySelector('.notification-close'));
  }, 5000);
}

function closeNotification(closeBtn) {
  const notification = closeBtn.closest('.custom-notification');
  notification.classList.remove('show');
  setTimeout(() => {
    if (notification.parentNode) {
      notification.parentNode.removeChild(notification);
    }
  }, 300);
}

// Add enhanced notification styles
if (!document.getElementById('custom-notification-styles')) {
  const styles = document.createElement('style');
  styles.id = 'custom-notification-styles';
  styles.textContent = `
    .custom-notification {
      position: fixed;
      top: 20px;
      right: 20px;
      background: white;
      border-radius: 16px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
      border-left: 4px solid #3b82f6;
      min-width: 350px;
      max-width: 450px;
      z-index: 10001;
      transform: translateX(500px);
      transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
      overflow: hidden;
    }
    
    .custom-notification.show {
      transform: translateX(0);
    }
    
    .custom-notification.success {
      border-left-color: #10b981;
    }
    
    .custom-notification.error {
      border-left-color: #ef4444;
    }
    
    .custom-notification.warning {
      border-left-color: #f59e0b;
    }
    
    .notification-content {
      display: flex;
      align-items: flex-start;
      padding: 20px;
      gap: 15px;
    }
    
    .notification-icon {
      width: 45px;
      height: 45px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 18px;
      flex-shrink: 0;
    }
    
    .notification-text {
      flex: 1;
    }
    
    .notification-text h4 {
      margin: 0 0 5px 0;
      font-size: 16px;
      font-weight: 600;
      color: #1f2937;
    }
    
    .notification-text p {
      margin: 0;
      font-size: 14px;
      color: #6b7280;
      line-height: 1.4;
    }
    
    .notification-close {
      background: none;
      border: none;
      color: #9ca3af;
      font-size: 14px;
      cursor: pointer;
      padding: 5px;
      border-radius: 50%;
      width: 25px;
      height: 25px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.2s;
      flex-shrink: 0;
    }
    
    .notification-close:hover {
      background: #f3f4f6;
      color: #374151;
    }
    
    .notification-progress {
      height: 3px;
      background: #e5e7eb;
      position: relative;
      overflow: hidden;
    }
    
    .notification-progress::after {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      height: 100%;
      width: 100%;
      background: linear-gradient(90deg, #3b82f6, #1e40af);
      transform: translateX(-100%);
    }
    
    .custom-notification.success .notification-progress::after {
      background: linear-gradient(90deg, #10b981, #059669);
    }
    
    .custom-notification.error .notification-progress::after {
      background: linear-gradient(90deg, #ef4444, #dc2626);
    }
    
    .custom-notification.warning .notification-progress::after {
      background: linear-gradient(90deg, #f59e0b, #d97706);
    }
    
    @keyframes progressBar {
      from {
        transform: translateX(-100%);
      }
      to {
        transform: translateX(0);
      }
    }
    
    @media (max-width: 768px) {
      .custom-notification {
        right: 10px;
        left: 10px;
        min-width: auto;
        max-width: none;
      }
    }
  `;
  document.head.appendChild(styles);
}
</script>
</body>
</html> 