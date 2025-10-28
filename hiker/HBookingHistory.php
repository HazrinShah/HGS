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
    .history-container {
      background: var(--soft-bg);
      max-width: 1000px;
      margin: 0 auto;
      padding: 2rem;
    }
    .history-card {
      display: flex;
      background: var(--card-white);
      border-radius: 16px;
      box-shadow: 0 6px 18px rgba(30,64,175,0.08);
      margin-bottom: 1.5rem;
      overflow: hidden;
      transition: all 0.3s ease;
      border: 1px solid #e2e8f0;
    }
    .history-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    }
    .history-image {
      width: 200px;
      height: 150px;
      background: var(--history-img);
      position: relative;
      overflow: hidden;
    }
    .history-image img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .history-content {
      flex: 1;
      padding: 1.5rem;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }
    .history-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 1rem;
    }
    .history-title {
      font-size: 1.25rem;
      font-weight: 600;
      color: var(--guider-blue-dark);
      margin: 0;
    }
    .badge { border-radius: 12px; padding: .4rem .6rem; font-weight: 600; }
    .history-status {
      padding: 0.25rem 0.75rem;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .status-completed {
      background: #dcfce7;
      color: #166534;
    }
    .history-details {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1rem;
      margin-bottom: 1rem;
    }
    .detail-item {
      display: flex;
      align-items: center;
      color: #64748b;
      font-size: 0.9rem;
    }
    .detail-item i {
      width: 16px;
      margin-right: 0.5rem;
      color: var(--guider-blue);
    }
    .history-footer {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding-top: 1rem;
      border-top: 1px solid #e2e8f0;
    }
    .history-price {
      font-size: 1.1rem;
      font-weight: 600;
      color: var(--guider-blue-dark);
    }
    .history-actions {
      display: flex;
      gap: 0.5rem;
    }
    .btn-history {
      padding: 0.75rem 1.5rem;
      border-radius: 12px;
      font-size: 1rem;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.3s ease;
      border: none;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
    }
    .btn-rate {
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      color: white;
      border: none;
      border-radius: 12px;
      padding: 12px 30px;
      font-weight: 600;
      box-shadow: 0 4px 15px rgba(30, 64, 175, 0.3);
      font-size: 1rem;
      transition: all 0.3s ease;
      text-decoration: none;
      display: inline-block;
      text-align: center;
      font-family: "Montserrat", sans-serif;
    }
    .btn-rate:hover {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      color: white;
      box-shadow: 0 8px 25px rgba(30, 64, 175, 0.4);
      transform: translateY(-2px);
      text-decoration: none;
    }
    .btn-rate:active {
      transform: translateY(0);
      box-shadow: 0 2px 8px rgba(42, 82, 190, 0.3);
    }
    .btn-rate i {
      font-size: 0.9rem;
    }
    .btn-view {
      background: #f1f5f9;
      color: var(--guider-blue-dark);
      border: 1px solid #e2e8f0;
    }
    .btn-view:hover {
      background: var(--guider-blue-soft);
      color: var(--guider-blue-dark);
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
      display: flex;
      align-items: center;
      margin-bottom: 0.5rem;
    }
    .guider-avatar {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      margin-right: 0.75rem;
      overflow: hidden;
      background: var(--guider-blue-soft);
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .guider-avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .guider-name {
      font-weight: 500;
      color: var(--guider-blue-dark);
    }
    .payment-info {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.8rem;
      color: #64748b;
    }
    .payment-type {
      padding: 0.2rem 0.5rem;
      border-radius: 12px;
      background: #f1f5f9;
      color: #475569;
    }
    .rating-display {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 0.25rem;
    }
    .rating-display .stars {
      display: flex;
      gap: 0.1rem;
    }
    .rating-display .stars i {
      font-size: 0.9rem;
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
          <div class="row mb-4">
            <?php foreach ($appealHistory as $appeal): ?>
              <div class="col-12 mb-3">
                <div class="history-card">
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
                    <div class="history-header">
                      <div>
                        <h3 class="history-title"><?php echo htmlspecialchars($appeal['mountainName']); ?></h3>
                        <div class="guider-info">
                          <div class="guider-avatar"><i class="fas fa-user"></i></div>
                          <span class="guider-name">Guider: <?php echo htmlspecialchars($appeal['guiderName']); ?></span>
                        </div>
                        <small class="text-muted">Appeal #<?php echo $appeal['appealID']; ?> • Updated <?php echo date('d M Y, h:i A', strtotime($appeal['updatedAt'] ?? $appeal['createdAt'])); ?></small>
                      </div>
                      <?php 
                        $status = $appeal['status'];
                        $badge = 'bg-secondary';
                        if ($status === 'refunded') $badge = 'bg-warning text-dark';
                        if ($status === 'resolved') $badge = 'bg-secondary';
                        if ($status === 'rejected') $badge = 'bg-danger';
                        if ($status === 'cancelled') $badge = 'bg-danger';
                      ?>
                      <span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_',' ', $status))); ?></span>
                    </div>
                    <div class="history-details">
                      <div class="detail-item"><i class="fas fa-calendar"></i>
                        <span><?php echo date('d/m/Y', strtotime($appeal['startDate'])); ?> - <?php echo date('d/m/Y', strtotime($appeal['endDate'])); ?></span>
                      </div>
                      <div class="detail-item"><i class="fas fa-hashtag"></i>
                        <span>Booking #<?php echo $appeal['bookingID']; ?></span>
                      </div>
                    </div>
                    <?php if ($appeal['status'] === 'refunded'): ?>
                      <div class="alert alert-warning py-2 mb-2"><i class="bi bi-currency-dollar me-1"></i>Refund will be processed within 3 working days.</div>
                    <?php endif; ?>
                    <?php if (!empty($appeal['reason'])): ?>
                      <div class="history-footer" style="border-top:none;padding-top:0;">
                        <div class="text-muted"><strong>Reason:</strong> <?php echo nl2br(htmlspecialchars($appeal['reason'])); ?></div>
                      </div>
                    <?php endif; ?>
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
                  <div class="history-header">
                    <div>
                      <h3 class="history-title"><?php echo htmlspecialchars($booking['mountainName']); ?></h3>
                      <div class="guider-info">
                        <div class="guider-avatar">
                          <?php if (!empty($booking['guiderPicture'])): ?>
                            <img src="<?php echo htmlspecialchars(strpos($booking['guiderPicture'], 'http') === 0 ? $booking['guiderPicture'] : '../' . $booking['guiderPicture']); ?>" 
                                 alt="<?php echo htmlspecialchars($booking['guiderName']); ?>"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                          <?php endif; ?>
                          <div style="display: <?php echo empty($booking['guiderPicture']) ? 'flex' : 'none'; ?>; align-items: center; justify-content: center; width: 100%; height: 100%; background: var(--guider-blue-soft); color: var(--guider-blue);">
                            <i class="fas fa-user"></i>
                          </div>
                        </div>
                        <span class="guider-name"><?php echo htmlspecialchars($booking['guiderName']); ?></span>
                      </div>
                    </div>
                    <span class="history-status status-completed">
                      <i class="fas fa-check-circle me-1"></i>Completed
                    </span>
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
</body>
</html>

