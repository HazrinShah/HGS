<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['hikerID'])) {
    header("Location: HLogin.html");
    exit;
}

$hikerID = $_SESSION['hikerID'];

// Database connection
include '../shared/db_connection.php';

// Function to fetch hiker details for a booking
function getHikerDetails($conn, $bookingID) {
    $stmt = $conn->prepare("
        SELECT hikerName, identityCard, address, phoneNumber, emergencyContactName, emergencyContactNumber
        FROM bookinghikerdetails
        WHERE bookingID = ?
        ORDER BY hikerDetailID ASC
    ");
    $stmt->bind_param("i", $bookingID);
    $stmt->execute();
    $result = $stmt->get_result();
    $details = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $details;
}

// Fetch completed bookings for the current user with review information
$bookingQuery = "SELECT b.*, g.username as guiderName, g.profile_picture as guiderPicture, 
                        m.name as mountainName, m.picture as mountainPicture, m.location,
                        pt.status as paymentStatus, pt.transactionType,
                        r.rating as existingRating, r.comment as existingComment, r.reviewID
                 FROM booking b 
                 JOIN guider g ON b.guiderID = g.guiderID 
                 JOIN mountain m ON b.mountainID = m.mountainID
                 LEFT JOIN payment_transactions pt ON b.bookingID = pt.bookingID
                 LEFT JOIN review r ON b.bookingID = r.bookingID
                 WHERE b.hikerID = ? AND b.status = 'completed'
                 GROUP BY b.bookingID
                 ORDER BY b.endDate DESC, b.created_at DESC";

$stmt = $conn->prepare($bookingQuery);
$stmt->bind_param("i", $hikerID);
$stmt->execute();
$result = $stmt->get_result();
$bookings = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch appeal history (cancelled/refunded/resolved/rejected)
$appealHistory = [];
$appealQuery = "SELECT a.*, b.startDate, b.endDate, b.price, g.username as guiderName, m.name as mountainName, m.picture as mountainPicture
                FROM appeal a
                JOIN booking b ON a.bookingID = b.bookingID
                JOIN guider g ON b.guiderID = g.guiderID
                JOIN mountain m ON b.mountainID = m.mountainID
                WHERE b.hikerID = ? AND a.status IN ('cancelled','refunded','resolved','rejected')
                ORDER BY a.updatedAt DESC, a.createdAt DESC";
$stmt = $conn->prepare($appealQuery);
$stmt->bind_param("i", $hikerID);
$stmt->execute();
$result = $stmt->get_result();
$appealHistory = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conn->close();

// Determine active tab (appeals or bookings)
$activeTab = isset($_GET['tab']) && in_array($_GET['tab'], ['appeals','bookings']) ? $_GET['tab'] : 'appeals';
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Booking History - Hiking Guidance System</title>
  <!-- Bootstrap & FontAwesome -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.3.0/css/all.min.css" />
  <link rel="stylesheet" href="../css/style.css" />
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    /* Guider Blue Color Scheme */
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
      --history-bg: var(--guider-blue-soft);
      --history-card: var(--guider-blue);
      --history-img: var(--guider-blue-dark);
    }
    body {
      background-color: var(--soft-bg);
      font-family: "Montserrat", sans-serif;
    }
    .main-content {
      padding-top: 2rem;
    }
    .page-header {
      margin-bottom: 2rem;
    }
    /* Themed pills */
    .nav-pills .nav-link {
      border-radius: 12px;
      font-weight: 600;
      color: var(--guider-blue-dark);
      background: #eef2ff;
      border: 1px solid #e2e8f0;
      transition: all .2s ease;
    }
    .nav-pills .nav-link:hover { background: var(--guider-blue-soft); }
    .nav-pills .nav-link.active {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      color: #fff;
      box-shadow: 0 6px 18px rgba(30,64,175,.25);
      border-color: transparent;
    }
    .navbar {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue)) !important;
      box-shadow: 0 4px 20px rgba(30, 64, 175, 0.3);
    }
    .navbar-toggler {
      border: 1px solid rgba(255, 255, 255, 0.3);
    }
    .navbar-toggler-icon {
      background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba%28255, 255, 255, 1%29' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
    }
    .logo {
      width: 45px;
      border-radius: 50%;
    }
    .history-card {
      background: white;
      border-radius: 12px;
      border-left: 4px solid var(--guider-blue);
      box-shadow: 0 2px 8px rgba(0,0,0,0.06), 0 0 0 1px rgba(0,0,0,0.04);
      padding: 1.5rem;
      transition: all 0.3s ease;
      margin-bottom: 2.5rem;
    }
    .history-card-inner {
      display: flex;
      gap: 1.5rem;
      align-items: flex-start;
      width: 100%;
      position: relative;
      transition: transform 0.3s ease;
      z-index: 1;
    }
    .history-card:hover {
      box-shadow: 0 4px 16px rgba(30, 64, 175, 0.12);
      border-left-color: var(--guider-blue-light);
      z-index: 2;
    }
    .history-card:hover .history-card-inner {
      transform: translateX(4px);
    }
    
    /* Compact Image - Fixed Size */
    .history-image {
      width: 120px !important;
      min-width: 120px !important;
      max-width: 120px !important;
      height: 120px !important;
      min-height: 120px !important;
      max-height: 120px !important;
      border-radius: 10px;
      overflow: hidden;
      background: #f1f5f9;
      flex-shrink: 0;
      box-sizing: border-box;
    }
    .history-image img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    
    /* Content Area */
    .history-content {
      flex: 1;
      min-width: 0;
      display: flex;
      flex-direction: column;
    }
    .history-title {
      font-size: 1.25rem;
      font-weight: 700;
      color: #1e293b;
      margin: 0 0 0.75rem 0;
      display: flex;
      align-items: center;
      gap: 0.5rem;
      line-height: 1.4;
    }
    .history-title i {
      color: var(--guider-blue);
      font-size: 1.1rem;
      flex-shrink: 0;
    }
    
    .history-status {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      padding: 0.4rem 0.9rem;
      background: #dcfce7;
      color: #166534;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 0.75rem;
      box-shadow: 0 2px 4px rgba(34, 197, 94, 0.2);
    }
    .status-completed {
      background: #dcfce7;
      color: #166534;
    }
    
    /* Details */
    .history-details {
      display: flex;
      flex-wrap: wrap;
      gap: 1rem 1.5rem;
      margin: 1rem 0;
      padding: 0.75rem 0;
    }
    .detail-item {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      color: #64748b;
      font-size: 0.875rem;
    }
    .detail-item i {
      color: var(--guider-blue);
      font-size: 0.9rem;
      width: 16px;
      flex-shrink: 0;
    }
    .detail-item span {
      font-weight: 500;
    }
    
    /* Footer */
    .history-footer {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-top: auto;
      padding-top: 1rem;
      border-top: 1px solid #e2e8f0;
      flex-wrap: wrap;
      gap: 1rem;
    }
    .history-price {
      font-size: 1.5rem;
      font-weight: 800;
      color: var(--guider-blue-dark);
    }
    .history-actions {
      display: flex;
      gap: 0.75rem;
      flex-wrap: wrap;
    }
    .btn-history {
      padding: 0.65rem 1.35rem;
      border-radius: 8px;
      font-weight: 600;
      font-size: 0.875rem;
      border: none;
      cursor: pointer;
      transition: all 0.3s ease;
      text-decoration: none;
      white-space: nowrap;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    }
    .btn-rate {
      background: var(--guider-blue);
      color: white;
    }
    .btn-rate:hover {
      background: var(--guider-blue-dark);
      color: white;
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
    }
    .btn-view {
      background: white;
      color: var(--guider-blue);
      border: 1.5px solid var(--guider-blue);
    }
    .btn-view:hover {
      background: var(--guider-blue-soft);
      color: var(--guider-blue-dark);
      border-color: var(--guider-blue);
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(30, 64, 175, 0.2);
    }
    .empty-state {
      text-align: center;
      padding: 4rem 2rem;
      color: #64748b;
      background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
      border-radius: 20px;
      border: 1px solid #e2e8f0;
      margin: 2rem 0;
    }
    .empty-state i {
      font-size: 4rem;
      margin-bottom: 1.5rem;
      color: var(--guider-blue);
      opacity: 0.7;
    }
    .empty-state h3 {
      color: var(--guider-blue-dark);
      margin-bottom: 1rem;
      font-weight: 600;
    }
    .empty-state p {
      margin-bottom: 2rem;
      font-size: 1.1rem;
      color: #64748b;
    }
    .page-header {
      text-align: center;
      margin-bottom: 3rem;
    }
    .page-title {
      font-size: 2.5rem;
      font-weight: 700;
      color: var(--guider-blue-dark);
      margin-bottom: 0.5rem;
    }
    .page-subtitle {
      color: #64748b;
      font-size: 1.1rem;
    }
    .guider-info {
      display: inline-flex;
      align-items: center;
      gap: 0.6rem;
      margin-bottom: 0.75rem;
      padding: 0.5rem 0.75rem;
      background: #f8fafc;
      border-radius: 8px;
      border: 1px solid #e2e8f0;
    }
    .guider-avatar {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      overflow: hidden;
      background: var(--guider-blue-soft);
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--guider-blue);
      flex-shrink: 0;
    }
    .guider-avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .guider-name {
      font-weight: 600;
      color: #475569;
      font-size: 0.9rem;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      max-width: 200px;
    }
    .payment-info {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      font-size: 0.8rem;
      color: #64748b;
      margin-top: 0.5rem;
    }
    .payment-info i {
      color: var(--guider-blue);
      font-size: 0.85rem;
    }
    .payment-type {
      font-weight: 600;
      color: var(--guider-blue-dark);
      font-size: 0.8rem;
    }
    .rating-display {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.4rem 0.75rem;
      background: #fef3c7;
      border-radius: 6px;
      margin-top: 0.5rem;
    }
    .rating-display .stars {
      display: flex;
      gap: 0.15rem;
    }
    .rating-display .stars i {
      font-size: 0.9rem;
      color: #f59e0b;
    }
    .rating-display span {
      font-size: 0.75rem;
      font-weight: 600;
      color: #92400e;
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
      border: none;
      padding: 1.5rem;
    }
    .modal-title {
      font-weight: 700;
      font-size: 1.5rem;
    }
    .modal-body {
      padding: 2rem;
    }
    .booking-detail-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 1rem 0;
      border-bottom: 1px solid #e2e8f0;
    }
    .booking-detail-item:last-child {
      border-bottom: none;
    }
    .booking-detail-label {
      font-weight: 600;
      color: var(--guider-blue-dark);
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    .booking-detail-label i {
      width: 20px;
      color: var(--guider-blue);
    }
    .booking-detail-value {
      color: #64748b;
      font-weight: 500;
    }
    .review-section {
      background: linear-gradient(135deg, #f8fafc, #e2e8f0);
      border-radius: 16px;
      padding: 1.5rem;
      margin-top: 1.5rem;
      border: 1px solid #e2e8f0;
    }
    .review-section h6 {
      color: var(--guider-blue-dark);
      font-weight: 700;
      margin-bottom: 1rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    .review-stars {
      display: flex;
      gap: 0.25rem;
      margin-bottom: 1rem;
    }
    .review-stars i {
      font-size: 1.5rem;
    }
    .review-comment {
      background: white;
      border-radius: 12px;
      padding: 1rem;
      border: 1px solid #e2e8f0;
    }
    .modal-footer {
      border: none;
      padding: 1.5rem 2rem;
      background: #f8fafc;
      border-radius: 0 0 20px 20px;
    }
    .btn-close-modal {
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      border: none;
      border-radius: 12px;
      padding: 0.75rem 2rem;
      font-weight: 600;
      color: white;
      transition: all 0.3s ease;
    }
    .btn-close-modal:hover {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(30, 64, 175, 0.4);
      color: white;
    }
    
    /* Responsive Design */
    @media (max-width: 968px) {
      .history-details {
        grid-template-columns: 1fr;
      }
    }
    
    @media (max-width: 768px) {
      .history-card-inner {
        flex-direction: column;
      }
      .history-image {
        width: 100% !important;
        height: 180px !important;
        min-width: 100% !important;
        max-width: 100% !important;
      }
      .history-content {
        max-width: 100% !important;
      }
      .history-title {
        font-size: 1.1rem;
      }
      .history-footer {
        flex-direction: column;
        align-items: flex-start;
      }
      .history-actions {
        width: 100%;
        flex-direction: column;
      }
      .btn-history {
        width: 100%;
        justify-content: center;
      }
    }
    
    @media (max-width: 480px) {
      .history-container {
        padding: 1rem 0.5rem;
      }
      .page-title {
        font-size: 1.5rem;
      }
      .history-card {
        padding: 1rem;
        gap: 1rem;
      }
      .history-image {
        width: 100px;
        min-width: 100px;
        height: 100px;
      }
      .history-title {
        font-size: 1rem;
      }
      .detail-item {
        font-size: 0.8rem;
      }
      .history-price {
        font-size: 1.25rem;
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
            <li class="nav-item"><a class="nav-link" href="HProfile.php">Profile</a></li>
            <li class="nav-item"><a class="nav-link" href="HBooking.php">Book Guider</a></li>
            <li class="nav-item"><a class="nav-link" href="HPayment.php">Payment</a></li>
            <li class="nav-item"><a class="nav-link" href="HYourGuider.php">Your Guider</a></li>
            <li class="nav-item"><a class="nav-link" href="HRateReview.php">Rate and Review</a></li>
            <li class="nav-item"><a class="nav-link active" href="HBookingHistory.php">Booking History</a></li>
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
      <!-- Tabs: Appeals | Bookings -->
      <ul class="nav nav-pills mb-4" role="tablist">
        <li class="nav-item" role="presentation">
          <a href="?tab=appeals" class="nav-link <?php echo $activeTab === 'appeals' ? 'active' : ''; ?>">
            <i class="fas fa-flag me-2"></i>Appeals
          </a>
        </li>
        <li class="nav-item" role="presentation">
          <a href="?tab=bookings" class="nav-link <?php echo $activeTab === 'bookings' ? 'active' : ''; ?>">
            <i class="fas fa-history me-2"></i>Bookings
          </a>
        </li>
      </ul>
      <?php if ($activeTab === 'appeals'): ?>
        <!-- Appeal History -->
        <div class="page-header">
          <h2 class="page-title">
            <i class="fas fa-flag me-3"></i>Appeal History
          </h2>
          <p class="page-subtitle">All processed appeals for your bookings</p>
        </div>

        <?php if (empty($appealHistory)): ?>
          <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <h3>No Appeal History</h3>
            <p>Processed appeals (cancelled, refunded, resolved or rejected) will appear here.</p>
          </div>
        <?php else: ?>
          <div class="row">
            <?php foreach ($appealHistory as $appeal): ?>
              <div class="col-12">
                <div class="history-card">
                  <div class="history-card-inner">
                    <div class="history-image">
                    <?php 
                      $imgA = $appeal['mountainPicture'] ?? '';
                      $imgA = str_replace('\\', '/', $imgA);
                      if ($imgA === '' || $imgA === null) {
                        $mountainSrcA = '../img/mountain-default.jpg';
                      } elseif (strpos($imgA, 'http') === 0) {
                        $mountainSrcA = $imgA;
                      } elseif (strpos($imgA, '../') === 0) {
                        $mountainSrcA = $imgA;
                      } elseif (strpos($imgA, '/') === 0) {
                        $mountainSrcA = '..' . $imgA; // absolute to site root
                      } else {
                        $mountainSrcA = '../' . $imgA; // relative path
                      }
                    ?>
                    <img src="<?php echo htmlspecialchars($mountainSrcA); ?>" alt="<?php echo htmlspecialchars($appeal['mountainName']); ?>" onerror="this.src='../img/mountain-default.jpg'">
                  </div>
                    <div class="history-content">
                      <?php 
                        $status = $appeal['status'];
                        $badge = 'bg-secondary';
                        if ($status === 'refunded') $badge = 'bg-warning text-dark';
                        if ($status === 'resolved') $badge = 'bg-secondary';
                        if ($status === 'rejected') $badge = 'bg-danger';
                        if ($status === 'cancelled') $badge = 'bg-danger';
                      ?>
                      <div class="history-status" style="background: <?php 
                        echo $status === 'refunded' ? '#fef3c7' : 
                             ($status === 'resolved' ? '#e2e8f0' : '#fee2e2'); 
                      ?>; color: <?php 
                        echo $status === 'refunded' ? '#92400e' : 
                             ($status === 'resolved' ? '#475569' : '#991b1b'); 
                      ?>;">
                        <i class="fas fa-<?php 
                          echo $status === 'refunded' ? 'money-bill-wave' : 
                               ($status === 'resolved' ? 'check-circle' : 'times-circle'); 
                        ?>"></i><?php echo htmlspecialchars(ucfirst(str_replace('_',' ', $status))); ?>
                      </div>
                      <h3 class="history-title">
                        <i class="fas fa-mountain"></i>
                        <?php echo htmlspecialchars($appeal['mountainName']); ?>
                      </h3>
                      <div class="guider-info">
                        <div class="guider-avatar"><i class="fas fa-user"></i></div>
                        <span class="guider-name"><?php echo htmlspecialchars($appeal['guiderName']); ?></span>
                      </div>
                      <div class="text-muted" style="font-size: 0.85rem; margin-top: 0.5rem;">
                        Appeal #<?php echo $appeal['appealID']; ?> • Updated <?php echo date('d M Y, h:i A', strtotime($appeal['updatedAt'] ?? $appeal['createdAt'])); ?>
                      </div>
                      
                      <div class="history-details">
                        <div class="detail-item">
                          <i class="fas fa-calendar"></i>
                          <span><?php echo date('d/m/Y', strtotime($appeal['startDate'])); ?> - <?php echo date('d/m/Y', strtotime($appeal['endDate'])); ?></span>
                        </div>
                        <div class="detail-item">
                          <i class="fas fa-hashtag"></i>
                          <span>Booking #<?php echo $appeal['bookingID']; ?></span>
                        </div>
                      </div>
                      
                      <?php if ($appeal['status'] === 'refunded'): ?>
                        <div class="alert alert-warning py-2 mb-2"><i class="bi bi-currency-dollar me-1"></i>Refund will be processed within 3 working days.</div>
                      <?php endif; ?>
                      
                      <?php if (!empty($appeal['reason'])): ?>
                        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e2e8f0;">
                          <div class="text-muted"><strong>Reason:</strong> <?php echo nl2br(htmlspecialchars($appeal['reason'])); ?></div>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($activeTab === 'bookings'): ?>
        <!-- Page Header -->
        <div class="page-header">
          <h1 class="page-title">
            <i class="fas fa-history me-3"></i>Booking History
          </h1>
          <p class="page-subtitle">Your completed hiking adventures</p>
        </div>

        <?php if (empty($bookings)): ?>
        <div class="empty-state">
          <i class="fas fa-mountain"></i>
          <h3>No Completed Bookings Yet</h3>
          <p>Your completed hiking adventures will appear here once you finish your trips.</p>
          <a href="HBooking.php" class="btn-history btn-rate mt-3">
            <i class="fas fa-calendar-plus me-2"></i>Book Your First Adventure
          </a>
        </div>
      <?php else: ?>
        <div class="row">
          <?php foreach ($bookings as $booking): ?>
            <div class="col-12">
              <div class="history-card">
                <div class="history-card-inner">
                  <div class="history-image">
                  <?php 
                    $imgB = $booking['mountainPicture'] ?? '';
                    $imgB = str_replace('\\', '/', $imgB);
                    if ($imgB === '' || $imgB === null) {
                      $mountainSrcB = '../img/mountain-default.jpg';
                    } elseif (strpos($imgB, 'http') === 0) {
                      $mountainSrcB = $imgB;
                    } elseif (strpos($imgB, '../') === 0) {
                      $mountainSrcB = $imgB;
                    } elseif (strpos($imgB, '/') === 0) {
                      $mountainSrcB = '..' . $imgB;
                    } else {
                      $mountainSrcB = '../' . $imgB;
                    }
                  ?>
                  <img src="<?php echo htmlspecialchars($mountainSrcB); ?>" 
                       alt="<?php echo htmlspecialchars($booking['mountainName']); ?>"
                       onerror="this.src='../img/mountain-default.jpg'">
                </div>
                <div class="history-content">
                  <div class="history-status status-completed">
                    <i class="fas fa-check-circle"></i>Completed
                  </div>
                  <h3 class="history-title">
                    <i class="fas fa-mountain"></i>
                    <?php echo htmlspecialchars($booking['mountainName']); ?>
                  </h3>
                  <div class="guider-info">
                    <div class="guider-avatar">
                      <?php if (!empty($booking['guiderPicture'])): ?>
                        <img src="<?php echo htmlspecialchars(strpos($booking['guiderPicture'], 'http') === 0 ? $booking['guiderPicture'] : '../' . $booking['guiderPicture']); ?>" 
                             alt="<?php echo htmlspecialchars($booking['guiderName']); ?>"
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                      <?php endif; ?>
                      <div style="display: <?php echo empty($booking['guiderPicture']) ? 'flex' : 'none'; ?>; align-items: center; justify-content: center; width: 100%; height: 100%;">
                        <i class="fas fa-user"></i>
                      </div>
                    </div>
                    <span class="guider-name"><?php echo htmlspecialchars($booking['guiderName']); ?></span>
                  </div>

                  <div class="history-details">
                    <div class="detail-item">
                      <i class="fas fa-calendar"></i>
                      <span><?php echo date('M j, Y', strtotime($booking['startDate'])); ?> - <?php echo date('M j, Y', strtotime($booking['endDate'])); ?></span>
                    </div>
                    <div class="detail-item">
                      <i class="fas fa-users"></i>
                      <span><?php echo $booking['totalHiker']; ?> person(s)</span>
                    </div>
                    <div class="detail-item">
                      <i class="fas fa-map-marker-alt"></i>
                      <span><?php echo htmlspecialchars($booking['location']); ?></span>
                    </div>
                    <div class="detail-item">
                      <i class="fas fa-hashtag"></i>
                      <span>Booking #<?php echo $booking['bookingID']; ?></span>
                    </div>
                  </div>

                  <div class="history-footer">
                    <div>
                      <div class="history-price">RM <?php echo number_format($booking['price'], 2); ?></div>
                      <?php if (!empty($booking['transactionType'])): ?>
                        <div class="payment-info">
                          <i class="fas fa-credit-card"></i>
                          <span class="payment-type"><?php echo strtoupper($booking['transactionType']); ?></span>
                          <span>Payment</span>
                        </div>
                      <?php endif; ?>
                    </div>
                     <div class="history-actions">
                       <?php if (!empty($booking['existingRating'])): ?>
                         <!-- Show stars if already reviewed -->
                         <div class="rating-display">
                           <div class="stars">
                             <?php for ($i = 1; $i <= 5; $i++): ?>
                               <i class="fas fa-star <?php echo $i <= $booking['existingRating'] ? 'text-warning' : 'text-muted'; ?>"></i>
                             <?php endfor; ?>
                           </div>
                           <small class="text-muted">Reviewed</small>
                         </div>
                       <?php else: ?>
                         <!-- Show rate button if not reviewed -->
                         <a href="HRateReview.php" class="btn-history btn-rate">
                           <i class="fas fa-star me-1"></i>Rate & Review
                         </a>
                       <?php endif; ?>
                       <button class="btn-history btn-view" onclick="viewBookingDetails(<?php echo $booking['bookingID']; ?>, '<?php echo addslashes($booking['mountainName']); ?>', '<?php echo addslashes($booking['guiderName']); ?>', '<?php echo $booking['startDate']; ?>', '<?php echo $booking['endDate']; ?>', '<?php echo $booking['totalHiker']; ?>', '<?php echo addslashes($booking['location']); ?>', '<?php echo number_format($booking['price'], 2); ?>', '<?php echo $booking['transactionType'] ?? 'N/A'; ?>', '<?php echo $booking['existingRating'] ?? ''; ?>', '<?php echo addslashes($booking['existingComment'] ?? ''); ?>')">
                         <i class="fas fa-eye me-1"></i>View Details
                       </button>
                     </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php endif; ?>

  </main>

  <!-- Booking Details Modal -->
  <div class="modal fade" id="bookingDetailsModal" tabindex="-1" aria-labelledby="bookingDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="bookingDetailsModalLabel">
            <i class="fas fa-info-circle me-2"></i>Booking Details
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="booking-detail-item">
            <span class="booking-detail-label">
              <i class="fas fa-hashtag"></i>Booking ID
            </span>
            <span class="booking-detail-value" id="modal-booking-id">-</span>
          </div>
          <div class="booking-detail-item">
            <span class="booking-detail-label">
              <i class="fas fa-mountain"></i>Mountain
            </span>
            <span class="booking-detail-value" id="modal-mountain">-</span>
          </div>
          <div class="booking-detail-item">
            <span class="booking-detail-label">
              <i class="fas fa-user"></i>Guider
            </span>
            <span class="booking-detail-value" id="modal-guider">-</span>
          </div>
          <div class="booking-detail-item">
            <span class="booking-detail-label">
              <i class="fas fa-calendar-alt"></i>Start Date
            </span>
            <span class="booking-detail-value" id="modal-start-date">-</span>
          </div>
          <div class="booking-detail-item">
            <span class="booking-detail-label">
              <i class="fas fa-calendar-check"></i>End Date
            </span>
            <span class="booking-detail-value" id="modal-end-date">-</span>
          </div>
          <div class="booking-detail-item">
            <span class="booking-detail-label">
              <i class="fas fa-users"></i>Number of Hikers
            </span>
            <span class="booking-detail-value" id="modal-hikers">-</span>
          </div>
          <div class="booking-detail-item" id="modal-hiker-details-container" style="display: none;">
            <span class="booking-detail-label">
              <i class="fas fa-user-friends"></i>Hiker Details
            </span>
            <div class="mt-2" id="modal-hiker-details-content">
              <!-- Hiker details will be populated by JavaScript -->
            </div>
          </div>
          <div class="booking-detail-item">
            <span class="booking-detail-label">
              <i class="fas fa-map-marker-alt"></i>Location
            </span>
            <span class="booking-detail-value" id="modal-location">-</span>
          </div>
          <div class="booking-detail-item">
            <span class="booking-detail-label">
              <i class="fas fa-dollar-sign"></i>Total Price
            </span>
            <span class="booking-detail-value" id="modal-price">-</span>
          </div>
          <div class="booking-detail-item">
            <span class="booking-detail-label">
              <i class="fas fa-credit-card"></i>Payment Method
            </span>
            <span class="booking-detail-value" id="modal-payment">-</span>
          </div>
          <div class="booking-detail-item">
            <span class="booking-detail-label">
              <i class="fas fa-check-circle"></i>Status
            </span>
            <span class="booking-detail-value">
              <span class="badge bg-success">
                <i class="fas fa-check-circle me-1"></i>Completed
              </span>
            </span>
          </div>
          
          <!-- Review Section -->
          <div class="review-section" id="review-section" style="display: none;">
            <h6>
              <i class="fas fa-star"></i>Your Review
            </h6>
            <div class="review-stars" id="modal-review-stars">
              <!-- Stars will be populated by JavaScript -->
            </div>
            <div class="review-comment" id="modal-review-comment">
              <!-- Comment will be populated by JavaScript -->
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-close-modal" data-bs-dismiss="modal">
            <i class="fas fa-times me-2"></i>Close
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  
  <script>
    function testFunction(bookingID) {
      alert('Test function working! Booking ID: ' + bookingID);
    }
    
    function viewBookingDetails(bookingID, mountainName, guiderName, startDate, endDate, totalHikers, location, price, paymentMethod, existingRating, existingComment) {
      try {
        console.log('View booking details clicked for ID:', bookingID);
        
        // Populate modal with booking details
        document.getElementById('modal-booking-id').textContent = '#' + bookingID;
        document.getElementById('modal-mountain').textContent = mountainName;
        document.getElementById('modal-guider').textContent = guiderName;
        document.getElementById('modal-start-date').textContent = formatDate(startDate);
        document.getElementById('modal-end-date').textContent = formatDate(endDate);
        document.getElementById('modal-hikers').textContent = totalHikers + ' person(s)';
        document.getElementById('modal-location').textContent = location;
        document.getElementById('modal-price').textContent = 'RM ' + price;
        document.getElementById('modal-payment').textContent = paymentMethod.toUpperCase();
        
        // Fetch hiker details via AJAX
        fetch('get_hiker_details.php?bookingID=' + bookingID)
          .then(response => response.json())
          .then(data => {
            const container = document.getElementById('modal-hiker-details-container');
            const content = document.getElementById('modal-hiker-details-content');
            
            if (data.success && data.hikers && data.hikers.length > 0) {
              container.style.display = 'block';
              let html = '<div class="accordion" id="hikerDetailsAccordion">';
              data.hikers.forEach((hiker, index) => {
                html += `
                  <div class="accordion-item mb-2">
                    <h2 class="accordion-header" id="heading${index}">
                      <button class="accordion-button ${index > 0 ? 'collapsed' : ''}" type="button" data-bs-toggle="collapse" data-bs-target="#collapse${index}">
                        <i class="fas fa-user me-2"></i>Hiker ${index + 1}: ${hiker.hikerName}
                      </button>
                    </h2>
                    <div id="collapse${index}" class="accordion-collapse collapse ${index === 0 ? 'show' : ''}">
                      <div class="accordion-body">
                        <div class="row g-2 small">
                          <div class="col-md-6"><strong>IC/Passport:</strong> ${hiker.identityCard}</div>
                          <div class="col-md-6"><strong>Phone:</strong> ${hiker.phoneNumber}</div>
                          <div class="col-12"><strong>Address:</strong> ${hiker.address.replace(/\n/g, '<br>')}</div>
                          <div class="col-md-6"><strong>Emergency Contact:</strong> ${hiker.emergencyContactName}</div>
                          <div class="col-md-6"><strong>Emergency Phone:</strong> ${hiker.emergencyContactNumber}</div>
                        </div>
                      </div>
                    </div>
                  </div>
                `;
              });
              html += '</div>';
              content.innerHTML = html;
            } else {
              container.style.display = 'none';
            }
          })
          .catch(error => {
            console.error('Error fetching hiker details:', error);
            document.getElementById('modal-hiker-details-container').style.display = 'none';
          });
        
        // Show review section if there's a review
        const reviewSection = document.getElementById('review-section');
        const reviewStars = document.getElementById('modal-review-stars');
        const reviewComment = document.getElementById('modal-review-comment');
        
        if (existingRating && existingRating !== '') {
          reviewSection.style.display = 'block';
          
          // Populate stars
          reviewStars.innerHTML = '';
          for (let i = 1; i <= 5; i++) {
            const star = document.createElement('i');
            star.className = 'fas fa-star ' + (i <= existingRating ? 'text-warning' : 'text-muted');
            reviewStars.appendChild(star);
          }
          
          // Populate comment
          if (existingComment && existingComment !== '') {
            reviewComment.innerHTML = '<p class="mb-0"><strong>Comment:</strong> ' + existingComment + '</p>';
          } else {
            reviewComment.innerHTML = '<p class="mb-0 text-muted">No comment provided</p>';
          }
        } else {
          reviewSection.style.display = 'none';
        }
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('bookingDetailsModal'));
        modal.show();
      } catch (error) {
        console.error('Error in viewBookingDetails:', error);
        alert('Error displaying booking details. Please try again.');
      }
    }
    
    function formatDate(dateString) {
      const date = new Date(dateString);
      return date.toLocaleDateString('en-US', { 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric' 
      });
    }
    
    // Test function to make sure JavaScript is working
    console.log('Booking History page JavaScript loaded successfully');
    
  </script>

<?php include_once '../AIChatbox/chatbox_include.php'; ?>

</body>
</html>

