<?php
session_start();
if (!isset($_SESSION['guiderID'])) {
    header("Location: GLogin.html");
    exit();
}
$guiderID = $_SESSION['guiderID'];

include '../shared/db_connection.php';

// Get guider performance data
$guiderData = [];
$averageRating = 0;
$totalReviews = 0;
$recentReviews = [];

if ($guiderID) {
    // Get guider basic info and ratings
    $stmt = $conn->prepare("
        SELECT 
            g.guiderID,
            g.username,
            g.average_rating,
            g.total_reviews,
            g.profile_picture
        FROM guider g
        WHERE g.guiderID = ?
    ");
    $stmt->bind_param("i", $guiderID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $guiderData = $result->fetch_assoc();
        $averageRating = $guiderData['average_rating'] ?? 0;
        $totalReviews = $guiderData['total_reviews'] ?? 0;
    }
    
    // Get recent reviews
    $stmt = $conn->prepare("
        SELECT 
            r.rating,
            r.comment,
            r.createdAt,
            h.username as hikerName,
            b.bookingID,
            m.name as mountainName
        FROM review r
        JOIN booking b ON r.bookingID = b.bookingID
        JOIN hiker h ON b.hikerID = h.hikerID
        JOIN mountain m ON b.mountainID = m.mountainID
        WHERE b.guiderID = ?
        ORDER BY r.createdAt DESC
        LIMIT 10
    ");
    $stmt->bind_param("i", $guiderID);
    $stmt->execute();
    $result = $stmt->get_result();
    $recentReviews = $result->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Performance Review – Hiking Guidance System</title>
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
      border-radius: 8px;
      padding: 0.5rem;
    }

    .navbar-toggler-icon {
      background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba%28255, 255, 255, 0.8%29' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
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
      background-color: var(--guider-blue-soft);
      color: var(--guider-blue-dark);
      border-color: var(--guider-blue);
    }

    /* Main Container - Matching GBooking */
    .main-container {
      padding: 1.5rem;
      max-width: 1400px;
      margin: 0 auto;
      background: linear-gradient(135deg, var(--soft-bg) 0%, #e2e8f0 100%);
      min-height: calc(100vh - 80px);
    }

    /* Page Header - Matching GBooking */
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

    /* Performance Cards */
    .performance-grid {
      display: grid;
      grid-template-columns: 1fr;
      max-width: 500px;
      margin: 0 auto 3rem auto;
      gap: 2rem;
    }

    .performance-card {
      background: var(--card-white);
      border-radius: 20px;
      padding: 2rem;
      box-shadow: 0 10px 30px rgba(30, 64, 175, 0.1);
      border: 1px solid var(--guider-blue-soft);
      transition: all 0.3s ease;
    }

    .performance-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 20px 40px rgba(30, 64, 175, 0.15);
    }

    .card-title {
      font-size: 1.25rem;
      font-weight: 600;
      color: var(--guider-blue-dark);
      margin-bottom: 1rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .card-title i {
      color: var(--guider-blue);
    }

    .rating-display {
      text-align: center;
      margin: 1.5rem 0;
    }

    .rating-number {
      font-size: 3rem;
      font-weight: 700;
      color: var(--guider-blue);
      margin-bottom: 0.5rem;
    }

    .rating-stars {
      display: flex;
      justify-content: center;
      gap: 0.25rem;
      margin-bottom: 1rem;
    }

    .rating-stars .fa-star {
      color: #ffc107;
      font-size: 1.5rem;
    }

    .rating-stars .fa-star-half-alt {
      color: #ffc107;
      font-size: 1.5rem;
    }

    .rating-stats {
      display: flex;
      justify-content: space-around;
      margin-top: 1rem;
      padding-top: 1rem;
      border-top: 1px solid var(--guider-blue-soft);
    }

    .stat-item {
      text-align: center;
    }

    .stat-number {
      font-size: 1.5rem;
      font-weight: 700;
      color: var(--guider-blue-dark);
    }

    .stat-label {
      font-size: 0.875rem;
      color: #64748b;
      font-weight: 500;
    }

    /* Reviews Section */
    .reviews-section {
      background: var(--card-white);
      border-radius: 20px;
      padding: 2rem;
      box-shadow: 0 10px 30px rgba(30, 64, 175, 0.1);
      border: 1px solid var(--guider-blue-soft);
    }

    .reviews-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 2rem;
      padding-bottom: 1rem;
      border-bottom: 2px solid var(--guider-blue-soft);
    }

    .reviews-title {
      font-size: 1.5rem;
      font-weight: 700;
      color: var(--guider-blue-dark);
      margin: 0;
    }

    .review-item {
      background: var(--guider-blue-soft);
      border-radius: 15px;
      padding: 1.5rem;
      margin-bottom: 1rem;
      border-left: 4px solid var(--guider-blue);
    }

    .review-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1rem;
    }

    .reviewer-info {
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }

    .reviewer-name {
      font-weight: 600;
      color: var(--guider-blue-dark);
    }

    .review-location {
      font-size: 0.875rem;
      color: #64748b;
    }

    .review-rating {
      display: flex;
      gap: 0.25rem;
    }

    .review-rating .fa-star {
      color: #ffc107;
      font-size: 1rem;
    }

    .review-comment {
      color: var(--dark-color);
      line-height: 1.6;
      margin-bottom: 0.5rem;
    }

    .review-date {
      font-size: 0.875rem;
      color: #64748b;
    }

    .no-reviews {
      text-align: center;
      padding: 3rem 2rem;
      color: #64748b;
    }

    .no-reviews i {
      font-size: 3rem;
      color: var(--guider-blue-soft);
      margin-bottom: 1rem;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
      .main-container {
        padding: 1rem;
      }
      
      .page-title {
        font-size: 2rem;
      }
      
      .performance-grid {
        grid-template-columns: 1fr;
        gap: 1.5rem;
      }
      
      .performance-card {
        padding: 1.5rem;
      }
      
      .rating-number {
        font-size: 2.5rem;
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
          <li class="nav-item"><a class="nav-link" href="GEarning.php">Earn & Receive</a></li>
          <li class="nav-item"><a class="nav-link active" href="GPerformance.php">Performance Review</a></li>
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
    <h1 class="page-title">Performance Review</h1>
    <p class="page-subtitle">Track your ratings and hiker feedback</p>
  </div>

  <!-- Performance Overview -->
  <div class="performance-grid">
    <!-- Overall Rating Card -->
    <div class="performance-card">
      <div class="card-title">
        <i class="fas fa-star"></i>
        Overall Rating
      </div>
      <div class="rating-display">
        <div class="rating-number"><?= number_format($averageRating, 1) ?></div>
        <div class="rating-stars">
          <?php
          $fullStars = floor($averageRating);
          $hasHalfStar = ($averageRating - $fullStars) >= 0.5;
          $emptyStars = 5 - $fullStars - ($hasHalfStar ? 1 : 0);
          
          // Full stars
          for ($i = 0; $i < $fullStars; $i++) {
              echo '<i class="fas fa-star"></i>';
          }
          
          // Half star
          if ($hasHalfStar) {
              echo '<i class="fas fa-star-half-alt"></i>';
          }
          
          // Empty stars
          for ($i = 0; $i < $emptyStars; $i++) {
              echo '<i class="far fa-star"></i>';
          }
          ?>
        </div>
        <div class="rating-stats">
          <div class="stat-item">
            <div class="stat-number"><?= $totalReviews ?></div>
            <div class="stat-label">Total Reviews</div>
          </div>
          <div class="stat-item">
            <div class="stat-number"><?= $averageRating > 0 ? 'Active' : 'New' ?></div>
            <div class="stat-label">Status</div>
          </div>
        </div>
      </div>
    </div>

  </div>

  <!-- Recent Reviews Section -->
  <div class="reviews-section">
    <div class="reviews-header">
      <h2 class="reviews-title">Recent Reviews</h2>
      <span class="badge bg-primary"><?= count($recentReviews) ?> reviews</span>
    </div>

    <?php if (empty($recentReviews)): ?>
      <div class="no-reviews">
        <i class="fas fa-comments"></i>
        <h4>No Reviews Yet</h4>
        <p>Complete some bookings to start receiving reviews from hikers!</p>
      </div>
    <?php else: ?>
      <?php foreach ($recentReviews as $review): ?>
        <div class="review-item">
          <div class="review-header">
            <div class="reviewer-info">
              <div>
                <div class="reviewer-name"><?= htmlspecialchars($review['hikerName']) ?></div>
                <div class="review-location"><?= htmlspecialchars($review['mountainName']) ?> • Booking #<?= $review['bookingID'] ?></div>
              </div>
            </div>
            <div class="review-rating">
              <?php
              $rating = $review['rating'];
              for ($i = 1; $i <= 5; $i++) {
                  if ($i <= $rating) {
                      echo '<i class="fas fa-star"></i>';
                  } else {
                      echo '<i class="far fa-star"></i>';
                  }
              }
              ?>
            </div>
          </div>
          <?php if (!empty($review['comment'])): ?>
            <div class="review-comment"><?= htmlspecialchars($review['comment']) ?></div>
          <?php endif; ?>
          <div class="review-date">
            <i class="fas fa-clock me-1"></i>
            <?= date('M j, Y', strtotime($review['createdAt'])) ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>