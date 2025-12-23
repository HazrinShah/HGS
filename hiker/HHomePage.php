<?php
session_start();

if (!isset($_SESSION['hikerID'])) {
    echo "Session hikerID not set!";
    exit;
}
$hikerID = $_SESSION['hikerID'];


if (!isset($_SESSION['username'])) {
  header("Location: HLogin.html");
  exit();
}

include '../shared/db_connection.php';
$displayName = $_SESSION['username'];
if ($conn && isset($hikerID)) {
  if ($stmt = $conn->prepare("SELECT username FROM hiker WHERE hikerID = ?")) {
    $stmt->bind_param("i", $hikerID);
    if ($stmt->execute()) {
      $res = $stmt->get_result();
      if ($row = $res->fetch_assoc()) {
        if (!empty($row['username'])) {
          $displayName = $row['username'];
        }
      }
    }
    $stmt->close();
  }
}
if ($conn) { $conn->close(); }
?>


<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hiker Homepage</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.3.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">  
<style>
        body {
          font-family: "Montserrat", sans-serif;
          margin: 0;
          padding: 0;
          overflow-x: hidden;
        }
        
        /* Guider Blue Color Scheme */
        :root {
          --guider-blue: #1e40af;
          --guider-blue-light: #3b82f6;
          --guider-blue-dark: #1e3a8a;
          --guider-blue-accent: #60a5fa;
          --guider-blue-soft: #dbeafe;
        }
        
        /* Header */
        .navbar {
          background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue)) !important;
          box-shadow: 0 4px 20px rgba(30, 64, 175, 0.3);
        }

        .navbar-toggler-icon {
          background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='white' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
        }

        /* Remove the top-nav styles and hero margin-top */
        .hero-section {
          margin-top: 0;
          min-height: 100vh;
        }

        /* Hero Section with Mountain Background */
        .hero-section {
          position: relative;
          min-height: 100vh;
          background: url('../img/HHomePageBG.jpg') center/cover no-repeat;
          display: flex;
          align-items: center;
          justify-content: center;
          text-align: center;
          color: white;
        }

        .hero-content {
          position: relative;
          z-index: 2;
          max-width: 600px;
          padding: 1.5rem;
          background: rgba(0, 0, 0, 0.3);
          border-radius: 20px;
          backdrop-filter: blur(10px);
        }

        .hero-title {
          font-size: 2.8rem;
          font-weight: 800;
          margin-bottom: 1rem;
          text-shadow: 0 4px 8px rgba(0, 0, 0, 0.5);
          background: linear-gradient(135deg, #ffffff, var(--guider-blue-accent));
          -webkit-background-clip: text;
          -webkit-text-fill-color: transparent;
          background-clip: text;
        }

        .hero-subtitle {
          font-size: 1.3rem;
          margin-bottom: 2rem;
          opacity: 0.9;
          text-shadow: 0 2px 4px rgba(0, 0, 0, 0.5);
        }

        .username-highlight {
          background: linear-gradient(135deg, #fbbf24, #f59e0b);
          -webkit-background-clip: text;
          -webkit-text-fill-color: transparent;
          background-clip: text;
          font-weight: 700;
        }

        .hero-buttons {
          display: flex;
          gap: 1rem;
          justify-content: center;
          flex-wrap: wrap;
          margin-top: 2rem;
        }

        .btn-hero {
          background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
          border: none;
          border-radius: 50px;
          padding: 15px 35px;
          font-weight: 600;
          color: white;
          transition: all 0.3s ease;
          box-shadow: 0 8px 25px rgba(30, 64, 175, 0.4);
          text-decoration: none;
          display: inline-flex;
          align-items: center;
          gap: 0.5rem;
        }

        .btn-hero:hover {
          transform: translateY(-3px);
          box-shadow: 0 12px 35px rgba(30, 64, 175, 0.6);
          color: white;
        }

        .btn-hero.secondary {
          background: rgba(255, 255, 255, 0.2);
          backdrop-filter: blur(10px);
          border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .btn-hero.secondary:hover {
          background: rgba(255, 255, 255, 0.3);
          color: white;
        }

        /* Features Section */
        .features-section {
          padding: 5rem 0;
          background: linear-gradient(135deg, #f8fafc 0%, var(--guider-blue-soft) 100%);
        }

        .section-title {
          text-align: center;
          font-size: 2.5rem;
          font-weight: 700;
          color: var(--guider-blue-dark);
          margin-bottom: 3rem;
        }

        /* Action Cards */
        .action-card {
          background: white;
          border-radius: 20px;
          padding: 2.5rem;
          text-align: center;
          transition: all 0.3s ease;
          box-shadow: 0 10px 30px rgba(30, 64, 175, 0.1);
          height: 100%;
          border: 1px solid rgba(30, 64, 175, 0.1);
          display: flex;
          flex-direction: column;
          justify-content: space-between;
        }

        .action-card:hover {
          transform: translateY(-10px);
          box-shadow: 0 20px 40px rgba(30, 64, 175, 0.2);
        }

        .action-card .icon {
          font-size: 3.5rem;
          margin-bottom: 1.5rem;
          background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
          -webkit-background-clip: text;
          -webkit-text-fill-color: transparent;
          background-clip: text;
          flex-shrink: 0;
        }

        .action-card h3 {
          color: var(--guider-blue-dark);
          font-weight: 600;
          margin-bottom: 1rem;
          font-size: 1.5rem;
          flex-shrink: 0;
        }

        .action-card p {
          color: #64748b;
          margin-bottom: 2rem;
          line-height: 1.6;
          flex-grow: 1;
        }

        .action-card .btn-modern {
          margin-top: auto;
        }

        .btn-modern {
          background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
          border: none;
          border-radius: 12px;
          padding: 12px 30px;
          font-weight: 600;
          color: white;
          transition: all 0.3s ease;
          box-shadow: 0 4px 15px rgba(30, 64, 175, 0.3);
        }

        .btn-modern:hover {
          transform: translateY(-2px);
          box-shadow: 0 8px 25px rgba(30, 64, 175, 0.4);
          color: white;
        }

        /* Stats Section */
        .stats-section {
          background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
          padding: 4rem 0;
          color: white;
        }

        .stats-grid {
          display: grid;
          grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
          gap: 2rem;
          margin-top: 2rem;
        }

        .stat-card {
          background: rgba(255, 255, 255, 0.1);
          backdrop-filter: blur(20px);
          border: 1px solid rgba(255, 255, 255, 0.2);
          border-radius: 20px;
          padding: 2rem;
          text-align: center;
          transition: all 0.3s ease;
        }

        .stat-card:hover {
          transform: translateY(-5px);
          background: rgba(255, 255, 255, 0.15);
        }

        .stat-card .stat-number {
          font-size: 2.5rem;
          font-weight: 800;
          color: #fbbf24;
          margin-bottom: 0.5rem;
        }

        .stat-card .stat-label {
          font-size: 1rem;
          opacity: 0.9;
          font-weight: 500;
        }

        /* Welcome Section */
        .welcome-section {
          padding: 5rem 0;
          background: white;
        }

        .welcome-content {
          background: linear-gradient(135deg, var(--guider-blue-soft), rgba(255, 255, 255, 0.8));
          border-radius: 30px;
          padding: 4rem 3rem;
          text-align: center;
          border: 1px solid rgba(30, 64, 175, 0.1);
        }

        .welcome-content h2 {
          color: var(--guider-blue-dark);
          font-weight: 700;
          margin-bottom: 1.5rem;
          font-size: 2.2rem;
        }

        .welcome-content .lead {
          color: #64748b;
          font-size: 1.1rem;
          line-height: 1.7;
          margin-bottom: 1rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
          .hero-title {
            font-size: 2.5rem;
          }
          
          .hero-subtitle {
            font-size: 1.1rem;
          }
          
          .hero-buttons {
            flex-direction: column;
            align-items: center;
          }
          
          .btn-hero {
            width: 100%;
            max-width: 300px;
          }
          
          .action-card {
            margin-bottom: 2rem;
          }
          
          .welcome-content {
            padding: 3rem 2rem;
          }
        }

        /* Mobile responsive */
        @media (max-width: 768px) {
            .top-nav .container {
                padding: 0 1rem;
            }
            
            .nav-title {
                font-size: 1rem;
            }
            
            .nav-links {
                gap: 0.3rem;
                padding: 0.3rem;
            }
            
            .nav-link {
                font-size: 0.8rem;
                padding: 0.4rem 0.8rem;
            }
            
            .btn-logout {
                padding: 0.4rem 0.8rem;
                font-size: 0.8rem;
            }
        }

        @media (max-width: 480px) {
            .nav-brand {
                gap: 0.5rem;
            }
            
            .nav-logo {
                width: 35px;
                height: 35px;
            }
            
            .nav-title {
                font-size: 0.9rem;
            }
            
            .nav-links {
                flex-wrap: wrap;
                gap: 0.2rem;
            }
            
            .nav-link {
                font-size: 0.75rem;
                padding: 0.3rem 0.6rem;
            }
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

        /* Mobile Responsive for Notifications */
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

        /* Mountain Statistics Styling */
        .mountain-stats {
          display: flex;
          justify-content: space-around;
          background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
          border-radius: 10px;
          padding: 0.75rem 0.5rem;
          border: 1px solid #bae6fd;
        }
        
        .mountain-stats .stat-row {
          display: flex;
          flex-direction: column;
          align-items: center;
          gap: 0.25rem;
        }
        
        .mountain-stats .stat-icon {
          color: var(--guider-blue);
          font-size: 1.1rem;
        }
        
        .mountain-stats .stat-value {
          font-weight: 700;
          color: var(--guider-blue-dark);
          font-size: 1.1rem;
          line-height: 1;
        }
        
        .mountain-stats .stat-label {
          font-size: 0.7rem;
          color: #64748b;
          text-transform: uppercase;
          font-weight: 500;
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
          <li class="nav-item"><a class="nav-link active" href="HHomePage.php">Home</a></li>
          <li class="nav-item"><a class="nav-link" href="HProfile.php">Profile</a></li>
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

<!-- Hero Section with Mountain Background -->
<section class="hero-section">
    <div class="hero-content">
        <h1 class="hero-title">Welcome, <span class="username-highlight"><?php echo htmlspecialchars($displayName); ?></span>!</h1>
        <p class="hero-subtitle">
            Ready for your next hiking adventure? Explore Johor's beautiful mountains with our experienced guiders.
        </p>
        <div class="hero-buttons">
            <form action="./HBooking.php" style="display: inline;">
                <button type="submit" class="btn-hero">
                    <i class="fas fa-mountain me-2"></i>Book Your Guider
                </button>
            </form>
        </div>
    </div>
</section>

<!-- Articles moved to modal -->
<!-- Features Section -->
<section class="features-section">
    <div class="container">
        <h2 class="section-title">Explore</h2>
        <div class="row g-4">
            <div class="col-lg-4 col-md-6">
                <div class="action-card">
                    <div class="icon">
                        <i class="fas fa-mountain"></i>
                    </div>
                    <h3>Mountains</h3>
                    <p>Browse mountains available in the system with pictures, locations, and descriptions.</p>
                    <a href="#mountains" class="btn btn-modern w-100">
                        <i class="fas fa-image me-2"></i>View Mountains
                    </a>
                </div>
            </div>

            <div class="col-lg-4 col-md-6">
                <div class="action-card">
                    <div class="icon">
                        <i class="fas fa-lightbulb"></i>
                    </div>
                    <h3>Hiking Tips</h3>
                    <p>Learn essential tips to hike safely and enjoyably, from packing to trail etiquette.</p>
                    <a href="#tips" class="btn btn-modern w-100">
                        <i class="fas fa-list-check me-2"></i>View Tips
                    </a>
                </div>
            </div>

            <div class="col-lg-4 col-md-6">
                <div class="action-card">
                    <div class="icon">
                        <i class="fas fa-newspaper"></i>
                    </div>
                    <h3>Hiking Articles</h3>
                    <p>Read curated hiking articles and guides from trusted sources on the web.</p>
                    <button type="button" class="btn btn-modern w-100" data-bs-toggle="modal" data-bs-target="#articlesModal">
                        <i class="fas fa-newspaper me-2"></i>Browse Articles
                    </button>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Mountains Section -->
<section id="mountains" class="welcome-section" style="scroll-margin-top: 90px;">
    <div class="container">
        <div class="welcome-content">
            <h2 class="mb-4">Mountains</h2>
            <?php
            require '../shared/db_connection.php';
            $mountains = [];
            if ($conn) {
                // Fetch mountains with booking statistics
                $sql = "SELECT m.mountainID, m.name, m.location, m.description, m.picture, m.latitude, m.longitude,
                               COUNT(DISTINCT b.bookingID) AS total_bookings,
                               COALESCE(SUM(b.totalHiker), 0) AS total_hikers
                        FROM mountain m
                        LEFT JOIN booking b ON b.mountainID = m.mountainID AND b.status IN ('paid', 'completed')
                        GROUP BY m.mountainID, m.name, m.location, m.description, m.picture, m.latitude, m.longitude
                        ORDER BY m.name ASC";
                if ($res = $conn->query($sql)) {
                    $mountains = $res->fetch_all(MYSQLI_ASSOC);
                    $res->close();
                }
                $conn->close();
            }
            ?>
            <?php if (!empty($mountains)): ?>
                <div class="row g-4 mt-2">
                    <?php foreach ($mountains as $m): ?>
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <div class="card h-100 shadow-sm">
                                <?php
                                    $rawImg = isset($m['picture']) && $m['picture'] ? $m['picture'] : '';
                                    $fileName = $rawImg ? basename($rawImg) : '';
                                    $fsPath = $fileName ? dirname(__DIR__) . '/upload/' . $fileName : '';
                                    $webPath = $fileName ? '../upload/' . $fileName : '';
                                    $imgPath = ($fileName && file_exists($fsPath)) ? $webPath : '../img/mountain-default.jpg';
                                ?>
                                <img src="<?= htmlspecialchars($imgPath) ?>" onerror="this.onerror=null;this.src='../img/mountain-default.jpg';" class="card-img-top" alt="<?= htmlspecialchars($m['name']) ?>" style="height:180px; object-fit:cover;">
                                <div class="card-body d-flex flex-column">
                                    <h5 class="card-title mb-3" style="color: var(--guider-blue-dark);">
                                        <?= htmlspecialchars($m['name']) ?>
                                    </h5>
                                    <button type="button" class="btn btn-modern mt-auto w-100" data-bs-toggle="modal" data-bs-target="#mountainModal_<?= (int)$m['mountainID'] ?>">
                                        <i class="fas fa-eye me-2"></i>View Details
                                    </button>
                                    <!-- Mountain Statistics -->
                                    <div class="mountain-stats mt-3">
                                        <div class="stat-row">
                                            <span class="stat-icon"><i class="fas fa-hiking"></i></span>
                                            <span class="stat-value"><?= number_format((int)$m['total_hikers']) ?></span>
                                            <span class="stat-label">Hikers</span>
                                        </div>
                                        <div class="stat-row">
                                            <span class="stat-icon"><i class="fas fa-calendar-check"></i></span>
                                            <span class="stat-value"><?= number_format((int)$m['total_bookings']) ?></span>
                                            <span class="stat-label">Trips</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php foreach ($mountains as $m): ?>
                    <?php
                        $rawImg = isset($m['picture']) && $m['picture'] ? $m['picture'] : '';
                        $fileName = $rawImg ? basename($rawImg) : '';
                        $fsPath = $fileName ? dirname(__DIR__) . '/upload/' . $fileName : '';
                        $webPath = $fileName ? '../upload/' . $fileName : '';
                        $imgPath = ($fileName && file_exists($fsPath)) ? $webPath : '../img/mountain-default.jpg';
                        $lat = isset($m['latitude']) ? $m['latitude'] : null;
                        $lng = isset($m['longitude']) ? $m['longitude'] : null;
                    ?>
                    <div class="modal fade" id="mountainModal_<?= (int)$m['mountainID'] ?>" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title"><?= htmlspecialchars($m['name']) ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <img src="<?= htmlspecialchars($imgPath) ?>" onerror="this.onerror=null;this.src='../img/mountain-default.jpg';" class="img-fluid rounded mb-3 d-block mx-auto" alt="<?= htmlspecialchars($m['name']) ?>" style="width:100%; max-width:900px; height:auto; max-height:70vh; object-fit:contain;">
                                    <div class="mb-2"><i class="fas fa-location-dot me-2"></i><strong><?= htmlspecialchars($m['location']) ?></strong></div>
                                    <p style="color:#374151;"><?= nl2br(htmlspecialchars($m['description'])) ?></p>
                                    <?php if ($lat !== null && $lng !== null && $lat !== '' && $lng !== ''): ?>
                                        <div class="ratio ratio-16x9">
                                            <iframe src="https://maps.google.com/maps?q=<?= urlencode((string)$lat) ?>,<?= urlencode((string)$lng) ?>&z=13&output=embed" style="border:0;" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                                        </div>
                                    <?php else: ?>
                                        <div class="p-3 text-center border rounded" style="background:#f8fafc; color:#64748b;">Map location not available.</div>
                                    <?php endif; ?>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="lead mb-0">No mountains available yet. Please check back later.</p>
            <?php endif; ?>
        </div>
    </div>
    </section>

<!-- Tips Section -->
<section id="tips" class="features-section" style="scroll-margin-top: 90px;">
    <div class="container">
        <h2 class="section-title">Hiking Tips</h2>
        <div class="row g-4">
            <div class="col-lg-4 col-md-6">
                <div class="action-card">
                    <div class="icon"><i class="fas fa-box-open"></i></div>
                    <h3>Packing Essentials</h3>
                    <p>Water (2L+), snacks, first-aid kit, headlamp, map/compass, rain jacket, hat, sunscreen, and fully charged phone.</p>
                </div>
            </div>
            <div class="col-lg-4 col-md-6">
                <div class="action-card">
                    <div class="icon"><i class="fas fa-shield-halved"></i></div>
                    <h3>Safety First</h3>
                    <p>Check weather, tell someone your plan, stay on marked trails, pace yourself, and know your limits.</p>
                </div>
            </div>
            <div class="col-lg-4 col-md-6">
                <div class="action-card">
                    <div class="icon"><i class="fas fa-shoe-prints"></i></div>
                    <h3>Trail Etiquette</h3>
                    <p>Yield to uphill hikers, leave no trace, keep noise low, and respect wildlife and other hikers.</p>
                </div>
            </div>
        </div>
        <div class="text-center mt-3">
            <button type="button" class="btn btn-modern" data-bs-toggle="modal" data-bs-target="#tipsModal">
                <i class="fas fa-list me-2"></i>View More
            </button>
        </div>
    </div>
</section>

<div class="modal fade" id="tipsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Hiking Tips</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);">
        <div class="container-fluid">
          <div class="row g-4">
            <div class="col-12">
              <div class="p-3 bg-white rounded shadow-sm">
                <p class="mb-0" style="color:#475569;">Practical guidance to stay safe and comfortable on the trail.</p>
              </div>
            </div>
            <div class="col-md-6">
              <div class="p-3 bg-white rounded shadow-sm h-100">
                <h6 class="fw-bold mb-2"><i class="fas fa-route me-2"></i>Plan & Navigation</h6>
                <ul class="list-group list-group-flush">
                  <li class="list-group-item"><i class="fas fa-check-circle text-success me-2"></i>Plan your route and check trail conditions.</li>
                  <li class="list-group-item"><i class="fas fa-check-circle text-success me-2"></i>Download offline maps and bring a power bank.</li>
                  <li class="list-group-item"><i class="fas fa-check-circle text-success me-2"></i>Share your itinerary and ETA with a friend.</li>
                </ul>
              </div>
            </div>
            <div class="col-md-6">
              <div class="p-3 bg-white rounded shadow-sm h-100">
                <h6 class="fw-bold mb-2"><i class="fas fa-person-hiking me-2"></i>Clothing & Gear</h6>
                <ul class="list-group list-group-flush">
                  <li class="list-group-item"><i class="fas fa-check-circle text-success me-2"></i>Dress in layers; moisture-wicking base, insulating mid, waterproof outer.</li>
                  <li class="list-group-item"><i class="fas fa-check-circle text-success me-2"></i>Proper hiking shoes/boots; consider trekking poles.</li>
                  <li class="list-group-item"><i class="fas fa-check-circle text-success me-2"></i>Pack the ten essentials and a small first-aid kit.</li>
                </ul>
              </div>
            </div>
            <div class="col-md-6">
              <div class="p-3 bg-white rounded shadow-sm h-100">
                <h6 class="fw-bold mb-2"><i class="fas fa-tint me-2"></i>Hydration & Nutrition</h6>
                <ul class="list-group list-group-flush">
                  <li class="list-group-item"><i class="fas fa-check-circle text-success me-2"></i>Bring 2L+ water; add electrolytes for long hikes.</li>
                  <li class="list-group-item"><i class="fas fa-check-circle text-success me-2"></i>Snack regularly: nuts, bars, fruit.</li>
                  <li class="list-group-item"><i class="fas fa-check-circle text-success me-2"></i>Eat a balanced meal after hiking for recovery.</li>
                </ul>
              </div>
            </div>
            <div class="col-md-6">
              <div class="p-3 bg-white rounded shadow-sm h-100">
                <h6 class="fw-bold mb-2"><i class="fas fa-shield-alt me-2"></i>Safety & Etiquette</h6>
                <ul class="list-group list-group-flush">
                  <li class="list-group-item"><i class="fas fa-check-circle text-success me-2"></i>Start early; turn back if weather worsens.</li>
                  <li class="list-group-item"><i class="fas fa-check-circle text-success me-2"></i>Respect wildlife; keep noise low; leave no trace.</li>
                  <li class="list-group-item"><i class="fas fa-check-circle text-success me-2"></i>Yield to uphill hikers and be courteous on narrow trails.</li>
                </ul>
              </div>
            </div>
            <div class="col-md-6">
              <div class="p-3 bg-white rounded shadow-sm h-100">
                <h6 class="fw-bold mb-2"><i class="fas fa-cloud-sun-rain me-2"></i>Weather & Timing</h6>
                <ul class="list-group list-group-flush">
                  <li class="list-group-item"><i class="fas fa-check-circle text-success me-2"></i>Check forecast and avoid exposed ridgelines during storms.</li>
                  <li class="list-group-item"><i class="fas fa-check-circle text-success me-2"></i>Start before the heat; plan turnaround time to finish before dark.</li>
                  <li class="list-group-item"><i class="fas fa-check-circle text-success me-2"></i>Bring a headlamp even on day hikes—plans can change.</li>
                </ul>
              </div>
            </div>
            <div class="col-md-6">
              <div class="p-3 bg-white rounded shadow-sm h-100">
                <h6 class="fw-bold mb-2"><i class="fas fa-satellite-dish me-2"></i>Emergencies & Communication</h6>
                <ul class="list-group list-group-flush">
                  <li class="list-group-item"><i class="fas fa-check-circle text-success me-2"></i>Save local emergency numbers; know the trailhead address/coordinates.</li>
                  <li class="list-group-item"><i class="fas fa-check-circle text-success me-2"></i>Carry a whistle; three blasts is a universal distress signal.</li>
                  <li class="list-group-item"><i class="fas fa-check-circle text-success me-2"></i>In low-signal areas, pre-download maps; consider an offline GPS app.</li>
                </ul>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Stats Section -->
<!-- Articles Modal -->
<div class="modal fade" id="articlesModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-newspaper me-2"></i>Hiking Articles</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);">
        <div class="container-fluid">
          <div class="row g-4">
            <div class="col-lg-6">
              <div class="card h-100 shadow-sm">
                <div class="card-body d-flex flex-column">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="badge text-bg-primary"><i class="fas fa-globe me-1"></i>TheSmartLocal</span>
                    <span class="text-muted small">2025</span>
                  </div>
                  <h5 class="card-title">12 Nearby Hiking Trails In & Near Johor Bahru [2025]</h5>
                  <p class="card-text" style="color:#64748b;">Menyenaraikan 12 laluan dengan tahap kesukaran, lokasi, dan tips.</p>
                  <a href="https://www.thesmartlocal.com/read/johor-hiking-trails/" target="_blank" rel="noopener" class="btn btn-modern mt-auto">
                    <i class="fas fa-up-right-from-square me-2"></i>Read on TheSmartLocal
                  </a>
                </div>
              </div>
            </div>

            <div class="col-lg-6">
              <div class="card h-100 shadow-sm">
                <div class="card-body d-flex flex-column">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="badge text-bg-primary"><i class="fas fa-globe me-1"></i>TripZilla</span>
                    <span class="text-muted small">23 May 2025</span>
                  </div>
                  <h5 class="card-title">A Guide To Johor’s Hiking Trails for Every Kind of Adventurer</h5>
                  <p class="card-text" style="color:#64748b;">Dikelompokkan mengikut beginner, intermediate, dan challenging.</p>
                  <a href="https://www.tripzilla.com/hiking-trails-johor-bahru-guide/163286" target="_blank" rel="noopener" class="btn btn-modern mt-auto">
                    <i class="fas fa-up-right-from-square me-2"></i>Read on TripZilla
                  </a>
                </div>
              </div>
            </div>

            <div class="col-lg-6">
              <div class="card h-100 shadow-sm">
                <div class="card-body d-flex flex-column">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="badge text-bg-primary"><i class="fas fa-globe me-1"></i>The Travel Intern</span>
                    <span class="text-muted small">9 May 2025</span>
                  </div>
                  <h5 class="card-title">Kangkar Pulai JB Hiking Guide – A Secret Blue Lake near JB Sentral</h5>
                  <p class="card-text" style="color:#64748b;">Fokus kepada laluan ke “Blue Lake” di Kangkar Pulai (Johor).</p>
                  <a href="https://thetravelintern.com/kangkar-pulai-jb-hiking-guide-blue-lake/" target="_blank" rel="noopener" class="btn btn-modern mt-auto">
                    <i class="fas fa-up-right-from-square me-2"></i>Read on The Travel Intern
                  </a>
                </div>
              </div>
            </div>

            <div class="col-lg-6">
              <div class="card h-100 shadow-sm">
                <div class="card-body d-flex flex-column">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="badge text-bg-primary"><i class="fas fa-globe me-1"></i>CNA Lifestyle</span>
                    <span class="text-muted small">19 Oct 2024</span>
                  </div>
                  <h5 class="card-title">More than just malls: Outdoor activities around JB including trails, parks, campsites</h5>
                  <p class="card-text" style="color:#64748b;">Gambaran luas aktiviti luar & laluan hiking sekitar Johor Bahru.</p>
                  <a href="https://cnalifestyle.channelnewsasia.com/travel/johor-bahru-jb-outdoor-activities-campsite-trails-parks-411846" target="_blank" rel="noopener" class="btn btn-modern mt-auto">
                    <i class="fas fa-up-right-from-square me-2"></i>Read on CNA
                  </a>
                </div>
              </div>
            </div>

            <div class="col-lg-6">
              <div class="card h-100 shadow-sm">
                <div class="card-body d-flex flex-column">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="badge text-bg-primary"><i class="fas fa-globe me-1"></i>Silverstreak.sg</span>
                    <span class="text-muted small">30 Sept 2025</span>
                  </div>
                  <h5 class="card-title">5 Beginner-Friendly Malaysia Hiking Trails Near Johor</h5>
                  <p class="card-text" style="color:#64748b;">Menfokuskan laluan sesuai untuk pemula di kawasan Johor.</p>
                  <a href="https://silverstreak.sg/beginner-malaysia-hiking-trails/" target="_blank" rel="noopener" class="btn btn-modern mt-auto">
                    <i class="fas fa-up-right-from-square me-2"></i>Read on Silverstreak
                  </a>
                </div>
              </div>
            </div>

            <div class="col-lg-6">
              <div class="card h-100 shadow-sm">
                <div class="card-body d-flex flex-column">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="badge text-bg-primary"><i class="fas fa-globe me-1"></i>ShopMontigo Blog</span>
                    <span class="text-muted small">2025</span>
                  </div>
                  <h5 class="card-title">Top 7 Hidden Gems and Hiking Trails in Johor</h5>
                  <p class="card-text" style="color:#64748b;">Senaraikan Bukit Cinta, Bukit Jementah, Gunung Pulai & Blue Lake.</p>
                  <a href="https://shopmontigo.com/blogs/news/hiking-johor" target="_blank" rel="noopener" class="btn btn-modern mt-auto">
                    <i class="fas fa-up-right-from-square me-2"></i>Read on ShopMontigo
                  </a>
                </div>
              </div>
            </div>

            <div class="col-lg-6">
              <div class="card h-100 shadow-sm">
                <div class="card-body d-flex flex-column">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="badge text-bg-primary"><i class="fas fa-globe me-1"></i>Gem Car Rental</span>
                    <span class="text-muted small">—</span>
                  </div>
                  <h5 class="card-title">3 Breathtaking Hiking Trails in Johor (Beginners Friendly)</h5>
                  <p class="card-text" style="color:#64748b;">GPS & panduan mudah: Tasik Biru Kangkar Pulai, Seri Alam Jungle Park.</p>
                  <a href="https://www.gemcarrental.com.my/blog/3-breathtaking-hiking-trails-in-johor-beginners-friendly/" target="_blank" rel="noopener" class="btn btn-modern mt-auto">
                    <i class="fas fa-up-right-from-square me-2"></i>Read on Gem Car Rental
                  </a>
                </div>
              </div>
            </div>

            <div class="col-lg-6">
              <div class="card h-100 shadow-sm">
                <div class="card-body d-flex flex-column">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="badge text-bg-primary"><i class="fas fa-globe me-1"></i>Hikers For Life</span>
                    <span class="text-muted small">—</span>
                  </div>
                  <h5 class="card-title">Gunung Pulai Trail Guide</h5>
                  <p class="card-text" style="color:#64748b;">Trailhead, panjang laluan, masa mendaki & tahap kesukaran.</p>
                  <a href="https://hikersforlife.com/sharemyhikes/gunung-pulai/" target="_blank" rel="noopener" class="btn btn-modern mt-auto">
                    <i class="fas fa-up-right-from-square me-2"></i>Read Guide
                  </a>
                </div>
              </div>
            </div>

            <div class="col-lg-6">
              <div class="card h-100 shadow-sm">
                <div class="card-body d-flex flex-column">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="badge text-bg-primary"><i class="fas fa-globe me-1"></i>Hikers For Life</span>
                    <span class="text-muted small">—</span>
                  </div>
                  <h5 class="card-title">Bukit Mor Trail Guide</h5>
                  <p class="card-text" style="color:#64748b;">Koordinat GPS & gambar laluan di Parit Jawa, Muar.</p>
                  <a href="https://hikersforlife.com/sharemyhikes/bukit-mor/" target="_blank" rel="noopener" class="btn btn-modern mt-auto">
                    <i class="fas fa-up-right-from-square me-2"></i>Read Guide
                  </a>
                </div>
              </div>
            </div>

            <div class="col-lg-6">
              <div class="card h-100 shadow-sm">
                <div class="card-body d-flex flex-column">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="badge text-bg-primary"><i class="fas fa-globe me-1"></i>OutdoorActive</span>
                    <span class="text-muted small">—</span>
                  </div>
                  <h5 class="card-title">Hikes in Johor</h5>
                  <p class="card-text" style="color:#64748b;">Peta interaktif, senarai laluan mengikut daerah, jarak & ketinggian.</p>
                  <a href="https://www.outdooractive.com/mobile/en/hikes/johor/hikes-in-johor/235904144/" target="_blank" rel="noopener" class="btn btn-modern mt-auto">
                    <i class="fas fa-up-right-from-square me-2"></i>Explore on OutdoorActive
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
<?php
// Fetch dynamic counts for stats section
require '../shared/db_connection.php';
$guiderCount = 0;
$mountainCount = 0;
if ($conn) {
    if ($res = $conn->query("SELECT COUNT(*) AS c FROM guider")) {
        if ($row = $res->fetch_assoc()) { $guiderCount = (int)$row['c']; }
    }
    if ($res = $conn->query("SELECT COUNT(*) AS c FROM mountain")) {
        if ($row = $res->fetch_assoc()) { $mountainCount = (int)$row['c']; }
    }
    $conn->close();
}
?>
<section class="stats-section">
    <div class="container">
        <div class="text-center">
            <h2 class="text-white mb-4">Why Choose HGS?</h2>
            <p class="lead text-white-50">Your trusted partner for safe and memorable hiking adventures</p>
        </div>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= number_format($guiderCount) ?></div>
                <div class="stat-label">Certified Guiders</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= number_format($mountainCount) ?></div>
                <div class="stat-label">Mountains</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">24/7</div>
                <div class="stat-label">Service Available</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">100%</div>
                <div class="stat-label">Safety First</div>
            </div>
        </div>
    </div>
</section>

<!-- Welcome Message Section -->
<section class="welcome-section">
    <div class="container">
        <div class="welcome-content">
            <h2>Your Adventure Awaits</h2>
            <p class="lead">
                HGS connects you with professional hiking guiders to ensure you have the safest and most enjoyable hiking experience. 
                From beginner-friendly trails to challenging mountain peaks, we've got you covered.
            </p>
            <p class="lead">
                Explore the natural beauty of Johor with confidence, knowing that our experienced guiders will lead you safely 
                through every trail and help you create unforgettable memories.
            </p>
        </div>
    </div>
</section>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<!-- App JS -->
<script src="../js/app.js"></script>

<!-- Custom Notification System -->
<script>
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

// Show welcome notification and handle URL parameters
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    
    // Show welcome notification
    setTimeout(() => {
        notificationSystem.info('Welcome!', 'Ready to start your hiking adventure?');
    }, 1000);
    
    // Handle URL parameters for notifications
    if (urlParams.get('success') === 'registration') {
        notificationSystem.success('Welcome!', 'Registration successful! You can now start booking guiders.');
        // Clean URL
        window.history.replaceState({}, document.title, window.location.pathname);
    }
    
    if (urlParams.get('success') === 'login') {
        notificationSystem.success('Welcome Back!', 'You have successfully logged in.');
        // Clean URL
        window.history.replaceState({}, document.title, window.location.pathname);
    }
    
    if (urlParams.get('booking') === 'success') {
        notificationSystem.success('Booking Complete!', 'Your booking has been confirmed successfully.');
        // Clean URL
        window.history.replaceState({}, document.title, window.location.pathname);
    }
});
</script>

<?php include_once '../AIChatbox/chatbox_include.php'; ?>

</body>
</html>

