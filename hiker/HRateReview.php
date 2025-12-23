<?php
// TEMPORARY DEBUG - Remove after fixing
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Check if user is logged in
if (!isset($_SESSION['hikerID'])) {
    header("Location: HLogin.html");
    exit;
}

$hikerID = $_SESSION['hikerID'];

// Database connection
include '../shared/db_connection.php';

// Fetch completed bookings for rating (excluding already rated ones)
// Include: 1) close group owner, 2) open group owner, 3) open group participant
$completedBookingsQuery = "SELECT DISTINCT b.*, g.username as guiderName, g.price as guiderPrice, g.phone_number as guiderPhone, 
                         g.profile_picture as guiderPicture, g.email as guiderEmail, g.experience as guiderExperience,
                         m.name as mountainName, m.picture as mountainPicture,
                         r.rating as existingRating, r.comment as existingComment
                         FROM booking b 
                         JOIN guider g ON b.guiderID = g.guiderID 
                         JOIN mountain m ON b.mountainID = m.mountainID 
                         LEFT JOIN bookingparticipant bp ON bp.bookingID = b.bookingID AND bp.hikerID = ?
                         LEFT JOIN review r ON b.bookingID = r.bookingID AND r.hikerID = ?
                         WHERE b.status = 'completed'
                           AND (
                             b.hikerID = ?
                             OR
                             (b.groupType = 'open' AND bp.hikerID IS NOT NULL)
                           )
                         ORDER BY b.endDate DESC";

$stmt = $conn->prepare($completedBookingsQuery);
if (!$stmt) {
    error_log("HRateReview.php - Prepare failed: " . $conn->error);
    die("Database error: " . $conn->error);
}
$stmt->bind_param("iii", $hikerID, $hikerID, $hikerID);
$stmt->execute();
$result = $stmt->get_result();
$allCompletedBookings = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Separate into reviewed and unreviewed bookings
$unreviewedBookings = [];
$reviewedBookings = [];

foreach ($allCompletedBookings as $booking) {
    if (empty($booking['existingRating'])) {
        $unreviewedBookings[] = $booking;
    } else {
        $reviewedBookings[] = $booking;
    }
}

// Get filter preference from URL parameter
$filter = $_GET['filter'] ?? 'unreviewed'; // Default to showing unreviewed first

// Handle rating submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_rating'])) {
    $bookingID = intval($_POST['bookingID']);
    $rating = intval($_POST['rating']);
    $comment = trim($_POST['comment'] ?? '');
    
    // Debug: Check what we're receiving
    error_log("Raw POST comment: '" . ($_POST['comment'] ?? 'NULL') . "'");
    error_log("Trimmed comment: '" . $comment . "'");
    
    
    // Get guiderID for this booking
    $guiderQuery = "SELECT guiderID FROM booking WHERE bookingID = ?";
    $guiderStmt = $conn->prepare($guiderQuery);
    $guiderStmt->bind_param("i", $bookingID);
    $guiderStmt->execute();
    $guiderResult = $guiderStmt->get_result()->fetch_assoc();
    $guiderID = $guiderResult['guiderID'];
    $guiderStmt->close();
    
    // Check if review already exists
    $checkQuery = "SELECT reviewID FROM review WHERE bookingID = ? AND hikerID = ?";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bind_param("ii", $bookingID, $hikerID);
    $checkStmt->execute();
    $existingReview = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();
    
    if ($existingReview) {
        $updateQuery = "UPDATE review SET rating = ?, comment = ?, updatedAt = NOW() WHERE bookingID = ? AND hikerID = ?";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bind_param("isii", $rating, $comment, $bookingID, $hikerID);
        $updateStmt->execute();
        $updateStmt->close();
        $success_message = "Review updated successfully!";
    } else {
        $insertQuery = "INSERT INTO review (bookingID, hikerID, guiderID, rating, comment, createdAt) VALUES (?, ?, ?, ?, ?, NOW())";
        $insertStmt = $conn->prepare($insertQuery);
        $insertStmt->bind_param("iiiis", $bookingID, $hikerID, $guiderID, $rating, $comment);
        $insertStmt->execute();
        $insertStmt->close();
        $success_message = "Thank you for your review!";
    }
    
    // Refresh the page to show updated data
    header("Location: HRateReview.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Rate and Review - Hiking Guidance System</title>
  <!-- Bootstrap & FontAwesome -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.3.0/css/all.min.css" />
  <link rel="stylesheet" href="../css/style.css" />
  <style>
    /* Enhanced Color Scheme */
    :root {
      --primary: #1e40af;
      --primary-light: #3b82f6;
      --primary-dark: #1e3a8a;
      --accent: #f59e0b;
      --accent-light: #fbbf24;
      --success: #10b981;
      --warning: #f59e0b;
      --danger: #ef4444;
      --soft-bg: #f8fafc;
      --card-white: #ffffff;
      --text-primary: #1f2937;
      --text-secondary: #6b7280;
      --border-light: #e5e7eb;
    }
    
    body {
      background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
      font-family: "Montserrat", sans-serif;
      min-height: 100vh;
    }
    
    .navbar {
      background: linear-gradient(135deg, var(--primary-dark), var(--primary)) !important;
      box-shadow: 0 4px 20px rgba(30, 64, 175, 0.3);
    }
    
    .navbar-toggler {
      border-color: white;
    }
    
    .navbar-toggler-icon {
      background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba%28255, 255, 255, 1%29' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
    }
    
    .logo {
      width: 45px;
      border-radius: 50%;
    }
    
    .main-container {
      max-width: 1200px;
      margin: 0 auto;
      padding: 2rem 1rem;
    }
    
    .page-header {
      text-align: center;
      margin-bottom: 3rem;
    }
    
    .page-title {
      font-size: 2.5rem;
      font-weight: 800;
      color: var(--text-primary);
      margin-bottom: 0.5rem;
    }
    
    .page-subtitle {
      font-size: 1.1rem;
      color: var(--text-secondary);
      max-width: 600px;
      margin: 0 auto;
    }
    
    .booking-card {
      background: var(--card-white);
      border-radius: 20px;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
      margin-bottom: 2rem;
      overflow: hidden;
      transition: all 0.3s ease;
      border: 1px solid var(--border-light);
    }
    
    .booking-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 16px 48px rgba(0, 0, 0, 0.15);
    }
    
    .card-header {
      background: linear-gradient(135deg, var(--primary), var(--primary-light));
      color: white;
      padding: 1.5rem;
      position: relative;
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
    }
    
    .trip-status {
      background: var(--success);
      color: white;
      padding: 0.5rem 1rem;
      border-radius: 20px;
      font-size: 0.85rem;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
    }
    
    .mountain-info {
      display: flex;
      align-items: center;
      gap: 1rem;
    }
    
    .mountain-image {
      width: 80px;
      height: 80px;
      border-radius: 12px;
      object-fit: cover;
      border: 3px solid rgba(255, 255, 255, 0.3);
    }
    
    .mountain-details h3 {
      font-size: 1.5rem;
      font-weight: 700;
      margin: 0;
    }
    
    .trip-dates {
      font-size: 0.95rem;
      opacity: 0.9;
      margin-top: 0.25rem;
    }
    
    .card-body {
      padding: 2rem;
    }
    
    .guider-section {
      display: flex;
      align-items: center;
      gap: 1.5rem;
      margin-bottom: 1.5rem;
    }
    
    .guider-avatar {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      object-fit: cover;
      border: 4px solid var(--border-light);
    }
    
    .guider-avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      border-radius: 50%;
    }
    
    .guider-info h4 {
      font-size: 1.3rem;
      font-weight: 700;
      color: var(--text-primary);
      margin: 0 0 0.5rem 0;
    }
    
    .guider-experience {
      color: var(--text-secondary);
      font-size: 0.95rem;
      margin-bottom: 0.25rem;
    }
    
    .guider-price {
      font-size: 1.1rem;
      font-weight: 600;
      color: var(--primary);
    }
    
    .rating-section {
      background: var(--soft-bg);
      border-radius: 12px;
      padding: 1.5rem;
      margin-top: 1rem;
    }
    
    .rating-title {
      font-size: 1.1rem;
      font-weight: 600;
      color: var(--text-primary);
      margin-bottom: 1rem;
    }
    
    .star-rating {
      display: flex;
      gap: 0.5rem;
      margin-bottom: 1rem;
    }
    
    .star {
      font-size: 2rem;
      color: #e5e7eb;
      cursor: pointer;
      transition: color 0.2s;
    }
    
    .star.active {
      color: var(--accent);
    }
    
    .star:hover {
      color: var(--accent-light);
    }
    
    .rating-input {
      display: none;
    }
    
    .comment-textarea {
      border: 2px solid var(--border-light);
      border-radius: 12px;
      padding: 1rem;
      font-size: 0.95rem;
      resize: vertical;
      min-height: 100px;
      transition: border-color 0.2s;
    }
    
    .comment-textarea:focus {
      border-color: var(--primary);
      outline: none;
      box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
    }
    
    .submit-btn {
      background: linear-gradient(135deg, var(--primary), var(--primary-light));
      border: none;
      border-radius: 12px;
      padding: 0.75rem 2rem;
      color: white;
      font-weight: 600;
      font-size: 1rem;
      transition: all 0.2s;
      margin-top: 1rem;
    }
    
    .submit-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(30, 64, 175, 0.3);
    }
    
    .existing-rating {
      background: var(--success);
      color: white;
      padding: 0.5rem 1rem;
      border-radius: 20px;
      font-size: 0.9rem;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
    }
    
    .empty-state {
      text-align: center;
      padding: 4rem 2rem;
      background: var(--card-white);
      border-radius: 20px;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
    }
    
    .empty-state-icon {
      font-size: 4rem;
      color: var(--text-secondary);
      margin-bottom: 1.5rem;
    }
    
    .empty-state h3 {
      font-size: 1.5rem;
      font-weight: 700;
      color: var(--text-primary);
      margin-bottom: 1rem;
    }
    
    .empty-state p {
      color: var(--text-secondary);
      font-size: 1.1rem;
      margin-bottom: 2rem;
    }
    
    .btn-primary {
      background: linear-gradient(135deg, var(--primary), var(--primary-light));
      border: none;
      border-radius: 12px;
      padding: 0.75rem 2rem;
      font-weight: 600;
    }
    
    .btn-view {
      background: linear-gradient(135deg, #fbbf24, #f59e0b);
      border: none;
      border-radius: 12px;
      padding: 0.75rem 1.5rem;
      font-weight: 600;
      color: white;
      transition: all 0.2s;
    }
    
    .btn-view:hover {
      background: linear-gradient(135deg, #f59e0b, #d97706);
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(245, 158, 11, 0.3);
      color: white;
    }
    
    .alert {
      border-radius: 12px;
      border: none;
      margin-bottom: 2rem;
    }
    
    @media (max-width: 768px) {
      .main-container {
        padding: 1rem 0.5rem;
      }
      
      .page-title {
        font-size: 2rem;
      }
      
      .guider-section {
        flex-direction: column;
        text-align: center;
      }
      
      .mountain-info {
        flex-direction: column;
        text-align: center;
      }
      
      .card-body {
        padding: 1.5rem;
      }
    }

    /* Filter Section Styles */
    .filter-section {
      background: white;
      border-radius: 16px;
      padding: 1.5rem;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
      margin-bottom: 2rem;
    }

    .filter-buttons {
      display: flex;
      gap: 1rem;
      flex-wrap: wrap;
      justify-content: center;
    }

    .filter-btn {
      display: inline-flex;
      align-items: center;
      padding: 0.75rem 1.5rem;
      border-radius: 12px;
      text-decoration: none;
      font-weight: 600;
      font-size: 0.9rem;
      transition: all 0.3s ease;
      border: 2px solid transparent;
      background: #f8fafc;
      color: #64748b;
    }

    .filter-btn:hover {
      background: var(--primary-light);
      color: white;
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
    }

    .filter-btn.active {
      background: var(--primary);
      color: white;
      border-color: var(--primary-dark);
      box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
    }

    .filter-btn i {
      font-size: 1rem;
    }

    /* Status Section */
    .status-section {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
      align-items: flex-end;
      position: relative;
      z-index: 10;
    }

    /* Review Status Indicators */
    .review-status {
      display: inline-flex;
      align-items: center;
      padding: 0.4rem 0.8rem;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 600;
      white-space: nowrap;
      min-width: fit-content;
    }

    .review-status.reviewed {
      background: linear-gradient(135deg, #10b981, #059669);
      color: white;
    }

    .review-status.unreviewed {
      background: linear-gradient(135deg, #f59e0b, #d97706);
      color: white;
    }

    .review-status i {
      font-size: 0.7rem;
    }

    @media (max-width: 768px) {
      .filter-buttons {
        flex-direction: column;
        align-items: center;
      }
      
      .filter-btn {
        width: 100%;
        max-width: 300px;
        justify-content: center;
      }

      .review-status {
        font-size: 0.75rem;
        padding: 0.3rem 0.6rem;
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
            <li class="nav-item"><a class="nav-link active" href="HRateReview.php">Rate and Review</a></li>
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
  <!-- Rate and Review Section -->
  <main class="py-5">
    <div class="main-container">
      <!-- Page Header -->
      <div class="page-header">
        <h1 class="page-title">
          <i class="fas fa-star me-3" style="color: var(--accent);"></i>
          Rate and Review
        </h1>
        <p class="page-subtitle">
          Share your experience and help other hikers by rating your completed trips
        </p>
      </div>

      <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <i class="fas fa-check-circle me-2"></i>
          <?php echo htmlspecialchars($success_message); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <?php if (empty($allCompletedBookings)): ?>
        <!-- Empty State -->
        <div class="empty-state">
          <div class="empty-state-icon">
            <i class="fas fa-mountain"></i>
          </div>
          <h3>No Completed Trips Yet</h3>
          <p>You haven't completed any hiking trips yet. Once you mark a trip as done, you'll be able to rate and review your guider here.</p>
          <a href="HYourGuider.php" class="btn btn-primary btn-lg">
            <i class="fas fa-calendar-check me-2"></i>View Your Trips
          </a>
        </div>
      <?php else: ?>
        <!-- Filter Section -->
        <div class="filter-section mb-4">
          <div class="filter-buttons">
            <a href="?filter=unreviewed" class="filter-btn <?php echo $filter === 'unreviewed' ? 'active' : ''; ?>">
              <i class="fas fa-star-half-alt me-2"></i>
              Need Review (<?php echo count($unreviewedBookings); ?>)
            </a>
            <a href="?filter=reviewed" class="filter-btn <?php echo $filter === 'reviewed' ? 'active' : ''; ?>">
              <i class="fas fa-star me-2"></i>
              Already Reviewed (<?php echo count($reviewedBookings); ?>)
            </a>
            <a href="?filter=all" class="filter-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">
              <i class="fas fa-list me-2"></i>
              All (<?php echo count($allCompletedBookings); ?>)
            </a>
          </div>
        </div>

        <!-- Completed Bookings -->
        <?php 
        // Determine which bookings to display based on filter
        $bookingsToShow = [];
        switch ($filter) {
            case 'reviewed':
                $bookingsToShow = $reviewedBookings;
                break;
            case 'all':
                $bookingsToShow = array_merge($unreviewedBookings, $reviewedBookings);
                break;
            case 'unreviewed':
            default:
                $bookingsToShow = $unreviewedBookings; // Only show unreviewed bookings
                break;
        }
        
        foreach ($bookingsToShow as $booking): 
        ?>
          <div class="booking-card">
            <!-- Card Header -->
            <div class="card-header">
              <div class="mountain-info">
                <?php 
                  $raw = $booking['mountainPicture'] ?? '';
                  $raw = str_replace('\\', '/', $raw);
                  if ($raw === '' || $raw === null) {
                    $mpic = 'https://via.placeholder.com/80';
                  } elseif (strpos($raw, 'http') === 0) {
                    $mpic = $raw;
                  } elseif (strpos($raw, '../') === 0) {
                    $mpic = $raw;
                  } elseif (strpos($raw, '/') === 0) {
                    $mpic = '..' . $raw;
                  } else {
                    $mpic = '../' . $raw;
                  }
                ?>
                <img src="<?php echo htmlspecialchars($mpic); ?>" 
                     alt="<?php echo htmlspecialchars($booking['mountainName']); ?>" 
                     class="mountain-image">
                <div class="mountain-details">
                  <h3><?php echo htmlspecialchars($booking['mountainName']); ?></h3>
                  <div class="trip-dates">
                    <i class="fas fa-calendar-alt me-1"></i>
                    <?php echo date('M j, Y', strtotime($booking['startDate'])); ?> - 
                    <?php echo date('M j, Y', strtotime($booking['endDate'])); ?>
                  </div>
                </div>
              </div>
              <div class="status-section">
                <div class="trip-status">
                  <i class="fas fa-check-circle me-1"></i>
                  Completed
                </div>
                <?php if (!empty($booking['existingRating'])): ?>
                  <div class="review-status reviewed">
                    <i class="fas fa-star me-1"></i>
                    Reviewed
                  </div>
                <?php else: ?>
                  <div class="review-status unreviewed">
                    <i class="fas fa-star-half-alt me-1"></i>
                    Needs Review
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <!-- Card Body -->
            <div class="card-body">
              <!-- Guider Information -->
              <div class="guider-section">
                <div class="guider-avatar">
                  <img src="<?php echo htmlspecialchars(strpos($booking['guiderPicture'], 'http') === 0 ? $booking['guiderPicture'] : '../' . $booking['guiderPicture']); ?>" 
                       alt="<?php echo htmlspecialchars($booking['guiderName']); ?>">
                </div>
                <div class="guider-info">
                  <h4><?php echo htmlspecialchars($booking['guiderName']); ?></h4>
                  <div class="guider-experience">
                    <i class="fas fa-award me-1"></i>
                    <?php echo htmlspecialchars($booking['guiderExperience']); ?> years experience
                  </div>
                  <div class="guider-price">
                    <i class="fas fa-dollar-sign me-1"></i>
                    RM <?php echo number_format($booking['price'], 2); ?>
                  </div>
                </div>
              </div>

              <!-- Rating Section -->
              <div class="rating-section">
                <?php if ($booking['existingRating']): ?>
                  <!-- Existing Rating Display -->
                  <div class="rating-title">
                    <i class="fas fa-star me-2" style="color: var(--accent);"></i>
                    Your Rating
                  </div>
                  <div class="d-flex justify-content-between align-items-center">
                    <div class="existing-rating">
                      <i class="fas fa-star me-1"></i>
                      <?php echo $booking['existingRating']; ?>/5
                      <?php if ($booking['existingComment']): ?>
                        <span class="ms-2">- "<?php echo htmlspecialchars(substr($booking['existingComment'], 0, 50)); ?><?php echo strlen($booking['existingComment']) > 50 ? '...' : ''; ?>"</span>
                      <?php endif; ?>
                    </div>
                    <button class="btn btn-view" onclick="viewReview(<?php echo $booking['bookingID']; ?>, <?php echo $booking['existingRating']; ?>, '<?php echo htmlspecialchars($booking['existingComment']); ?>', '<?php echo htmlspecialchars($booking['guiderName']); ?>', '<?php echo htmlspecialchars($booking['mountainName']); ?>')">
                      <i class="fas fa-eye me-2"></i>VIEW
                    </button>
                  </div>
                <?php else: ?>
                  <!-- Rating Form -->
                  <div class="rating-title">
                    <i class="fas fa-star me-2" style="color: var(--accent);"></i>
                    Rate Your Experience
                  </div>
                  <form method="POST" action="">
                    <input type="hidden" name="bookingID" value="<?php echo $booking['bookingID']; ?>">
                    <input type="hidden" name="submit_rating" value="1">
                    
                    <!-- Star Rating -->
                    <div class="star-rating" data-booking="<?php echo $booking['bookingID']; ?>">
                      <span class="star" data-rating="1">&#9733;</span>
                      <span class="star" data-rating="2">&#9733;</span>
                      <span class="star" data-rating="3">&#9733;</span>
                      <span class="star" data-rating="4">&#9733;</span>
                      <span class="star" data-rating="5">&#9733;</span>
                    </div>
                    <input type="hidden" name="rating" id="rating_<?php echo $booking['bookingID']; ?>" value="0" required>
                    
                    <!-- Comment -->
                    <textarea name="comment" class="form-control comment-textarea" 
                              placeholder="Share your experience with this guider... (optional)"></textarea>
                    
                    <!-- Submit Button -->
                    <button type="submit" class="submit-btn">
                      <i class="fas fa-paper-plane me-2"></i>Submit Review
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </main>

  <!-- Review Details Modal -->
  <div class="modal fade" id="reviewModal" tabindex="-1" aria-labelledby="reviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header" style="background: linear-gradient(135deg, var(--primary), var(--primary-light)); color: white;">
          <h5 class="modal-title" id="reviewModalLabel">
            <i class="fas fa-star me-2"></i>Review Details
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-md-6">
              <h6 class="text-muted mb-2">Trip Details</h6>
              <p><strong>Mountain:</strong> <span id="modalMountainName"></span></p>
              <p><strong>Guider:</strong> <span id="modalGuiderName"></span></p>
            </div>
            <div class="col-md-6">
              <h6 class="text-muted mb-2">Your Rating</h6>
              <div id="modalRating" class="mb-3"></div>
            </div>
          </div>
          <hr>
          <div>
            <h6 class="text-muted mb-2">Your Comment</h6>
            <div id="modalComment" class="p-3 bg-light rounded" style="min-height: 100px;">
              <!-- Comment will be displayed here -->
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Star rating functionality
    document.addEventListener('DOMContentLoaded', function() {
      const starRatings = document.querySelectorAll('.star-rating');
      
      starRatings.forEach(function(ratingContainer) {
        const stars = ratingContainer.querySelectorAll('.star');
        const bookingID = ratingContainer.getAttribute('data-booking');
        const ratingInput = document.getElementById('rating_' + bookingID);
        
        stars.forEach(function(star, index) {
          star.addEventListener('click', function() {
            const rating = index + 1;
            ratingInput.value = rating;
            
            // Update star display
            stars.forEach(function(s, i) {
              if (i < rating) {
                s.classList.add('active');
              } else {
                s.classList.remove('active');
              }
            });
          });
          
          star.addEventListener('mouseenter', function() {
            const rating = index + 1;
            stars.forEach(function(s, i) {
              if (i < rating) {
                s.style.color = 'var(--accent-light)';
              } else {
                s.style.color = '#e5e7eb';
              }
            });
          });
        });
        
        ratingContainer.addEventListener('mouseleave', function() {
          const currentRating = parseInt(ratingInput.value) || 0;
          stars.forEach(function(s, i) {
            if (i < currentRating) {
              s.style.color = 'var(--accent)';
            } else {
              s.style.color = '#e5e7eb';
            }
          });
        });
      });
    });
    
    // View review functionality
    function viewReview(bookingID, rating, comment, guiderName, mountainName) {
      // Populate modal with review data
      document.getElementById('modalMountainName').textContent = mountainName;
      document.getElementById('modalGuiderName').textContent = guiderName;
      
      // Display rating as stars
      const ratingContainer = document.getElementById('modalRating');
      ratingContainer.innerHTML = '';
      for (let i = 1; i <= 5; i++) {
        const star = document.createElement('span');
        star.innerHTML = '&#9733;';
        star.style.fontSize = '1.5rem';
        star.style.color = i <= rating ? 'var(--accent)' : '#e5e7eb';
        star.style.marginRight = '0.25rem';
        ratingContainer.appendChild(star);
      }
      ratingContainer.innerHTML += ` <span class="ms-2 fw-bold">${rating}/5</span>`;
      
      // Display comment
      const commentContainer = document.getElementById('modalComment');
      if (comment && comment.trim() !== '') {
        commentContainer.innerHTML = `<p class="mb-0">"${comment}"</p>`;
      } else {
        commentContainer.innerHTML = '<p class="mb-0 text-muted"><em>No comment provided</em></p>';
      }
      
      // Show modal
      const modal = new bootstrap.Modal(document.getElementById('reviewModal'));
      modal.show();
    }
    
    // Form validation
    document.addEventListener('submit', function(e) {
      if (e.target.querySelector('input[name="submit_rating"]')) {
        const ratingInput = e.target.querySelector('input[name="rating"]');
        if (!ratingInput.value || ratingInput.value === '0') {
          e.preventDefault();
          alert('Please select a rating before submitting.');
          return false;
        }
      }
    });
  </script>

<?php include_once '../AIChatbox/chatbox_include.php'; ?>

</body>
</html>
