<?php
session_start();

// kalau tak login, hantar ke login page
if (!isset($_SESSION['email'])) {
    header("Location: ALogin.html");
    exit();
}

include '../shared/db_connection.php';

// check email ni admin ke tak
$email = $_SESSION['email'];
$stmt = $conn->prepare("SELECT * FROM admin WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    session_destroy();
    header("Location: ALogin.html");
    exit();
}

// ambil appeals dengan search
$whereConditions = [];
$params = [];
$types = [];

if (isset($_GET['search_booking_id']) && !empty($_GET['search_booking_id'])) {
    $whereConditions[] = "a.bookingID = ?";
    $params[] = $_GET['search_booking_id'];
    $types[] = "i";
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

$query = "SELECT a.*, 
                 h.username as hiker_name, h.email as hiker_email,
                 g.username as guider_name, g.email as guider_email,
                 b.startDate, b.endDate, b.status as booking_status
          FROM appeal a
          LEFT JOIN hiker h ON a.hikerID = h.hikerID
          LEFT JOIN guider g ON a.guiderID = g.guiderID
          LEFT JOIN booking b ON a.bookingID = b.bookingID
          $whereClause
          ORDER BY a.createdAt DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param(implode("", $types), ...$params);
}
$stmt->execute();
$appeals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// debug: tunjuk struktur data appeal
if (empty($appeals)) {
    // check ada appeals ke tak
    $debugQuery = "SELECT COUNT(*) as total FROM appeal";
    $debugResult = $conn->query($debugQuery);
    $debugCount = $debugResult->fetch_assoc()['total'];
    
    if ($debugCount > 0) {
        // tunjuk sample appeal data
        $sampleQuery = "SELECT * FROM appeal LIMIT 1";
        $sampleResult = $conn->query($sampleQuery);
        $sampleAppeal = $sampleResult->fetch_assoc();
        error_log("Sample appeal data: " . print_r($sampleAppeal, true));
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin - Appeal Management</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.3.0/css/all.min.css" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet" />
  <style>
    :root {
      --primary-color: #571785;
      --primary-dark: #4a0f6b;
      --primary-light: #ede6fa;
      --secondary-color: #4a0f6b;
      --success-color: #28a745;
      --warning-color: #ffc107;
      --danger-color: #dc3545;
      --dark-color: #343a40;
      --light-color: #f8f9fa;
      --header-bg: #571785;
      --tab-purple: #571785;
      --tab-purple-light: #ede6fa;
      --tab-purple-hover: #4a0f6b;
    }
    body {
      background-color: #f5f5f5;
      font-family: 'Montserrat', sans-serif;
      margin: 0;
      padding: 0;
    }
    .navbar {
      background-color: var(--header-bg);
      padding: 12px 0;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      z-index: 1002;
      width: 100%;
    }
    .navbar-title {
      font-size: 22px;
      font-weight: bold;
      color: rgb(255, 255, 255);
      margin: 0 auto;
      text-shadow: 1px 1px 3px rgba(0,0,0,0.2);
    }
    .logo {
      width: 60px;
      height: 60px;
      object-fit: contain;
    }
    .sidebar {
      background: linear-gradient(135deg, #571785 0%, #4a0f6b 100%);
      position: fixed;
      top: 0;
      left: -350px;
      width: 320px;
      height: 100vh;
      margin: 0;
      padding: 100px 15px 20px 15px !important;
      border-radius: 0;
      box-shadow: 0 8px 32px rgba(87, 23, 133, 0.4);
      border: 2px solid rgba(255, 255, 255, 0.1);
      z-index: 1000;
      transition: left 0.3s ease;
      overflow-y: auto;
    }

    .sidebar.mobile-open { left: 0 !important; }

    /* Mobile Menu Button */
    .mobile-menu-btn {
      display: inline-flex;
      position: static;
      z-index: 1001;
      background: linear-gradient(135deg, #571785 0%, #4a0f6b 100%);
      color: white;
      border: none;
      border-radius: 12px;
      padding: 12px;
      font-size: 1.2rem;
      box-shadow: 0 4px 12px rgba(87, 23, 133, 0.3);
      cursor: pointer;
      transition: all 0.3s ease;
      align-items: center;
      justify-content: center;
    }

    .mobile-menu-btn:hover {
      background: linear-gradient(135deg, #4a0f6b 0%, #3d0a5c 100%);
      transform: scale(1.05);
    }

    /* Mobile Overlay */
    .mobile-overlay {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      z-index: 999;
      transition: opacity 0.3s ease;
    }

    .mobile-overlay.show {
      display: block;
    }
    .sidebar .menu a {
      display: flex;
      align-items: center;
      padding: 15px 20px;
      color: #ffffff;
      font-weight: 600;
      text-decoration: none;
      margin-bottom: 12px;
      border-radius: 12px;
      transition: all 0.3s ease;
      border: 1px solid transparent;
      position: relative;
      overflow: hidden;
    }

    .sidebar .menu a::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
      transition: left 0.5s;
    }

    .sidebar .menu a:hover::before {
      left: 100%;
    }

    .sidebar .menu a:hover {
      background: rgba(255, 255, 255, 0.15);
      border-color: rgba(255, 255, 255, 0.3);
      transform: translateX(5px);
      box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    }

    .sidebar .menu a.active {
      background: rgba(255, 255, 255, 0.2);
      border-color: rgba(255, 255, 255, 0.4);
      box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    }

    .sidebar .menu i {
      margin-right: 15px;
      font-size: 18px;
      width: 20px;
      text-align: center;
    }
    .main-content {
      flex-grow: 1;
      padding: 0;
    }

    /* Main Container */
    .main-container {
      padding: 2rem;
      width: 100%;
      margin: 0;
      background: linear-gradient(135deg, var(--light-color) 0%, #e2e8f0 100%);
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
      color: var(--primary-dark);
      margin-bottom: 0.5rem;
      background: linear-gradient(135deg, var(--primary-dark), var(--primary-color));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .page-subtitle {
      font-size: 1.1rem;
      color: #64748b;
      font-weight: 500;
    }
    .wrapper { display: flex; min-height: 100vh; flex-direction: column; }
    body { padding-top: 80px; }
    .main-content { flex-grow: 1; padding: 80px 15px 20px 15px; width: 100%; margin-left: 0; }

    /* Card Container */
    .tab-container {
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

    .tab-container::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, var(--primary-color), var(--secondary-color), var(--primary-dark));
    }

    /* Filter Section */
    .search-bar {
      display: flex;
      align-items: center;
      gap: 1.5rem;
      background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
      padding: 1.5rem 2rem;
      border-radius: 20px;
      box-shadow: 0 8px 25px rgba(87, 23, 133, 0.15);
      border: 2px solid rgba(87, 23, 133, 0.1);
      margin-bottom: 2rem;
      position: relative;
      overflow: hidden;
    }

    .search-bar::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, var(--primary-color), var(--secondary-color), var(--primary-dark));
    }

    .filter-label {
      font-weight: 600;
      color: var(--primary-dark);
      margin: 0;
      display: flex;
      align-items: center;
    }
    .filter-input {
      border: 2px solid rgba(87, 23, 133, 0.2);
      border-radius: 15px;
      padding: 0.75rem 1.25rem;
      font-size: 1rem;
      background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
      color: var(--primary-dark);
      transition: all 0.3s ease;
      flex: 1;
      box-shadow: 0 2px 8px rgba(87, 23, 133, 0.1);
      font-weight: 500;
    }

    .filter-input:focus {
      outline: none;
      border-color: var(--primary-color);
      box-shadow: 0 0 0 4px rgba(87, 23, 133, 0.15);
      transform: translateY(-1px);
    }

    .filter-input:hover {
      border-color: var(--primary-color);
      box-shadow: 0 4px 12px rgba(87, 23, 133, 0.15);
    }
    .search-bar button {
      background: linear-gradient(135deg, var(--tab-purple) 0%, var(--primary-dark) 100%);
      color: #fff;
      border: none;
      border-radius: 15px;
      padding: 0.75rem 1.5rem;
      font-weight: 700;
      font-size: 1rem;
      transition: all 0.3s ease;
      box-shadow: 0 4px 12px rgba(87, 23, 133, 0.3);
      position: relative;
      overflow: hidden;
      border: 1px solid rgba(255,255,255,0.1);
    }

    .search-bar button::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
      transition: left 0.5s;
    }

    .search-bar button:hover::before {
      left: 100%;
    }

    .search-bar button:hover {
      background: linear-gradient(135deg, var(--primary-dark) 0%, #3d0a5c 100%);
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(108,59,184,0.3);
    }

    /* Table Styling */
    .appeal-table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      border-radius: 20px;
      overflow: hidden;
      box-shadow: 0 8px 16px rgba(0, 0, 0, 0.05);
      border: 1px solid rgba(108, 59, 184, 0.1);
    }

    .appeal-table th {
      background: #571785;
      color: #fff;
      font-weight: 800;
      text-align: center;
      padding: 18px 12px;
      border: none;
      font-size: 1.1rem;
      letter-spacing: 0.8px;
      text-shadow: 1px 1px 2px rgba(0,0,0,0.2);
    }
    .appeal-table td {
      background: linear-gradient(135deg, #ffffff 0%, #fafbff 100%);
      text-align: center;
      padding: 16px 12px;
      border: none;
      font-size: 1.05rem;
      color: #3d2461;
      font-weight: 500;
      transition: all 0.3s ease;
    }

    .appeal-table tr:hover td {
      background: linear-gradient(135deg, #f8f9ff 0%, #f0e8ff 100%);
      transform: scale(1.02);
    }

    .appeal-table tr:last-child td {
      border-bottom: none;
    }

    .appeal-table tr {
      border-radius: 0 0 18px 18px;
    }

    .appeal-table th:first-child {
      border-radius: 12px 0 0 0;
    }
    .appeal-table th:last-child {
      border-radius: 0 12px 0 0;
    }
    .appeal-table tr:last-child td:first-child {
      border-radius: 0 0 0 12px;
    }
    .appeal-table tr:last-child td:last-child {
      border-radius: 0 0 12px 0;
    }

    /* Professional action button base */
    .btn-action {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 14px;
      border-radius: 10px;
      font-weight: 600;
      font-size: 0.9rem;
      border: 1px solid rgba(255,255,255,0.12);
      box-shadow: 0 3px 10px rgba(0,0,0,0.08);
      transition: transform 0.15s ease, box-shadow 0.2s ease, background 0.3s ease, color 0.3s ease;
      letter-spacing: .2px;
      position: relative;
      overflow: hidden;
    }

    .btn-action i { font-size: 1rem; }

    .btn-action:hover {
      transform: translateY(-1px);
      box-shadow: 0 6px 16px rgba(0,0,0,0.12);
    }

    .btn-action:active { transform: translateY(0); }

    .btn-action:focus-visible {
      outline: none;
      box-shadow: 0 0 0 4px rgba(87, 23, 133, 0.18), 0 3px 10px rgba(0,0,0,0.08);
    }

    .btn-action:disabled,
    .btn-action[disabled] {
      opacity: 0.7;
      cursor: not-allowed;
      filter: grayscale(10%);
    }

    .btn-resolve {
      background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);
      color: #fff;
      border: 1px solid rgba(255,255,255,0.12);
      border-radius: 10px;
      box-shadow: 0 3px 10px rgba(40,167,69,0.25);
    }

    .btn-resolve:hover {
      background: linear-gradient(135deg, #1e7e34 0%, #155724 100%);
      color: #fff;
      transform: translateY(-2px);
      box-shadow: 0 5px 12px rgba(40,167,69,0.3);
    }

    .btn-dismiss {
      background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
      color: #fff;
      border: 1px solid rgba(255,255,255,0.12);
      border-radius: 10px;
      box-shadow: 0 3px 10px rgba(220,53,69,0.25);
    }

    .btn-dismiss:hover {
      background: linear-gradient(135deg, #c82333 0%, #a71e2a 100%);
      color: #fff;
      transform: translateY(-2px);
      box-shadow: 0 5px 12px rgba(220,53,69,0.3);
    }

    .btn-accept {
      background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);
      color: #fff;
      border: 1px solid rgba(255,255,255,0.12);
      border-radius: 10px;
      box-shadow: 0 3px 10px rgba(40,167,69,0.25);
    }

    .btn-accept:hover {
      background: linear-gradient(135deg, #1e7e34 0%, #155724 100%);
      color: #fff;
      transform: translateY(-2px);
      box-shadow: 0 5px 12px rgba(40,167,69,0.3);
    }

    .btn-reject {
      background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
      color: #fff;
      border: 1px solid rgba(255,255,255,0.12);
      border-radius: 10px;
      box-shadow: 0 3px 10px rgba(220,53,69,0.25);
    }

    .btn-reject:hover {
      background: linear-gradient(135deg, #c82333 0%, #a71e2a 100%);
      color: #fff;
      transform: translateY(-2px);
      box-shadow: 0 5px 12px rgba(220,53,69,0.3);
    }

    .btn-refund {
      background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
      color: #212529;
      border: 1px solid rgba(255,255,255,0.12);
      border-radius: 10px;
      box-shadow: 0 3px 10px rgba(255,193,7,0.25);
    }

    .btn-refund:hover {
      background: linear-gradient(135deg, #e0a800 0%, #d39e00 100%);
      color: #212529;
      transform: translateY(-2px);
      box-shadow: 0 5px 12px rgba(255,193,7,0.3);
    }

    .btn-change-guider {
      background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
      color: #fff;
      border: 1px solid rgba(255,255,255,0.12);
      border-radius: 10px;
      box-shadow: 0 3px 10px rgba(23,162,184,0.25);
    }

    .btn-change-guider:hover {
      background: linear-gradient(135deg, #138496 0%, #117a8b 100%);
      color: #fff;
      transform: translateY(-2px);
      box-shadow: 0 5px 12px rgba(23,162,184,0.3);
    }

    /* Neutral view button */
    .btn-view {
      background: linear-gradient(135deg, #6b7280 0%, #374151 100%);
      color: #fff;
      border: 1px solid rgba(255,255,255,0.12);
      border-radius: 10px;
      box-shadow: 0 3px 10px rgba(107,114,128,0.25);
    }
    .btn-view:hover {
      background: linear-gradient(135deg, #4b5563 0%, #1f2937 100%);
      transform: translateY(-2px);
      color: #fff;
    }

    .guider-card {
      transition: all 0.3s ease;
      border: 2px solid transparent;
    }

    .guider-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }

    .guider-card.border-primary {
      border-color: #0d6efd !important;
      background-color: #f8f9fa !important;
    }

    /* Mobile Responsive Styles */
    @media (max-width: 768px) {
      .mobile-menu-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        position: static;
        padding: 10px 12px;
        z-index: 1001;
      }
      
      .navbar {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 1002;
        width: 100%;
      }
      
      body {
        padding-top: 80px;
      }

      .sidebar {
        position: fixed;
        top: 0;
        left: -350px;
        width: 320px;
        height: 100vh;
        margin: 0;
        border-radius: 0;
        z-index: 1000;
        transition: left 0.3s ease;
        overflow-y: auto;
        padding: 100px 15px 20px 15px !important;
      }
      .sidebar.mobile-open {
        left: 0 !important;
      }

      .wrapper {
        flex-direction: column;
      }

      .main-content {
        margin-left: 0;
        padding: 60px 10px 20px 10px;
        width: 100%;
      }
      
      .sidebar .menu a {
        padding: 18px 20px;
        font-size: 16px;
        font-weight: 600;
      }
      
      .sidebar .menu i {
        margin-right: 18px;
        font-size: 20px;
        width: 24px;
      }

      .main-container {
        padding: 1rem;
        width: 100%;
      }

      .page-header {
        padding: 1.5rem 1rem;
        margin-bottom: 2rem;
      }

      .page-title {
        font-size: 2rem;
      }

      .search-bar {
        flex-direction: column;
        gap: 1rem;
        padding: 1rem;
      }

      .filter-input {
        width: 100%;
      }

      .appeal-table {
        font-size: 0.9rem;
      }

      .appeal-table th, .appeal-table td {
        padding: 8px 4px;
        font-size: 0.85rem;
      }
    }
  </style>
</head>
<body>
  <!-- ni overlay mobile -->
  <div class="mobile-overlay" onclick="closeMobileMenu()"></div>

  <!-- ni header -->
  <header>
    <nav class="navbar">
      <div class="container d-flex align-items-center">
        <!-- hamburger dalam header (mobile je) -->
        <button class="mobile-menu-btn me-2" onclick="toggleMobileMenu()">
          <i class="bi bi-list"></i>
        </button>
        <a class="navbar-brand d-flex align-items-center" href="../index.php">
          <img src="../img/logo.png" class="img-fluid logo me-2" alt="HGS Logo" style="width: 50px; height: 50px;">
          <span class="fs-6 fw-bold text-white">Admin</span>
        </a>
        <h1 class="navbar-title ms-auto me-auto">HIKING GUIDANCE SYSTEM</h1>
      </div>
    </nav>
  </header>
  <!-- habis header -->
  <div class="wrapper">
    <!-- ni sidebar -->
    <div class="sidebar">
      <div class="logo-admin">
        <strong class="ms-2 text-white">Menu</strong>
      </div>
      <div class="menu mt-4">
        <a href="ADashboard.html"><i class="bi bi-grid-fill"></i> Dashboard</a>
        <a href="AUser.html"><i class="bi bi-people-fill"></i> User</a>
        <a href="AMountain.php"><i class="bi bi-triangle-fill"></i> Mountain</a>
        <a href="AAppeal.php" class="active"><i class="bi bi-chat-dots-fill"></i> Appeal</a>
        <a href="ASentimentReport.php"><i class="fas fa-chart-line"></i> Sentiment Analysis</a>
        <a href="AReport.php"><i class="bi bi-file-earmark-text-fill"></i> Reports</a>
        <div class="text-center mt-4">
          <form action="../shared/logout.php" method="POST" class="d-flex justify-content-center">
            <button class="btn btn-danger logout-btn w-50" type="submit">
              <i class="bi bi-box-arrow-right"></i> Log Out
            </button>
          </form>
        </div>
      </div>
    </div>
    <div class="main-content">
      <div class="main-container">
        <div class="page-header">
          <h1 class="page-title">Appeal Management</h1>
          <p class="page-subtitle">Review and manage appeals from hikers and guiders</p>
        </div>
      <div class="tab-container">
        <div class="mb-3 fw-bold fs-5">Appeal List</div>
        <div class="search-bar">
          <div class="filter-label">
            <i class="bi bi-search me-2"></i>Search Appeals
          </div>
          <form method="GET" class="d-flex gap-2 flex-wrap">
            <input type="number" name="search_booking_id" class="filter-input" placeholder="Enter Booking ID" value="<?= htmlspecialchars($_GET['search_booking_id'] ?? '') ?>" style="min-width: 200px;">
            <button type="submit"><i class="bi bi-search"></i> Search</button>
            <a href="AAppeal.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-clockwise"></i> Clear</a>
          </form>
        </div>
        <div class="table-responsive">
          <table class="appeal-table">
            <thead>
              <tr>
                <th>Appeal ID</th>
                <th>Booking ID</th>
                <th>Hiker</th>
                <th>Guider</th>
                <th>Booking Dates</th>
                <th>Appeal Date</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($appeals)): ?>
                <tr>
                  <td colspan="8" class="text-center text-muted">No appeals found</td>
                </tr>
              <?php else: ?>
                <?php foreach ($appeals as $appeal): ?>
                  <tr data-appeal-id="<?= $appeal['appealID'] ?>">
                    <td><strong>#<?= $appeal['appealID'] ?></strong></td>
                    <td><strong>#<?= $appeal['bookingID'] ?></strong></td>
                    <td>
                      <div>
                        <strong><?= htmlspecialchars($appeal['hiker_name']) ?></strong><br>
                        <small class="text-muted"><?= htmlspecialchars($appeal['hiker_email']) ?></small>
                      </div>
                    </td>
                    <td>
                      <div>
                        <strong><?= htmlspecialchars($appeal['guider_name']) ?></strong><br>
                        <small class="text-muted"><?= htmlspecialchars($appeal['guider_email']) ?></small>
                      </div>
                    </td>
                    <td>
                      <div>
                        <strong>Start:</strong> <?= date('d/m/Y', strtotime($appeal['startDate'])) ?><br>
                        <strong>End:</strong> <?= date('d/m/Y', strtotime($appeal['endDate'])) ?>
                      </div>
                    </td>
                    <td><?= date('d/m/Y', strtotime($appeal['createdAt'])) ?></td>
                    <td>
                      <?php
                        $status = $appeal['status'];
                        $badgeClass = 'secondary';
                        switch ($status) {
                          case 'pending': $badgeClass = 'warning'; break;
                          case 'approved': $badgeClass = 'primary'; break;
                          case 'awaiting_hiker_choice': $badgeClass = 'info'; break;
                          case 'onhold': $badgeClass = 'info'; break;
                          case 'pending_refund': $badgeClass = 'warning'; break;
                          case 'refunded': $badgeClass = 'success'; break;
                          case 'refund_rejected': $badgeClass = 'danger'; break;
                          case 'resolved': $badgeClass = 'secondary'; break;
                          case 'rejected': $badgeClass = 'danger'; break;
                          default: $badgeClass = 'secondary';
                        }
                      ?>
                      <span class="badge bg-<?= $badgeClass ?>">
                        <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $status))) ?>
                      </span>
                    </td>
                    <td>
                      <div class="d-flex gap-2 justify-content-center flex-wrap">
                        <button class="btn-view btn-action" onclick="viewAppeal(<?= $appeal['appealID'] ?>)">
                          <i class="bi bi-eye"></i> View
                        </button>
                        <?php if ($appeal['status'] == 'pending'): ?>
                          <!-- action untuk guider appeal -->
                          <?php if ($appeal['guiderID'] && !$appeal['hikerID']): ?>
                            <button class="btn-accept btn-action" onclick="acceptGuiderAppeal(<?= $appeal['appealID'] ?>, <?= $appeal['bookingID'] ?>, '<?= htmlspecialchars($appeal['appealType']) ?>')">
                              <i class="bi bi-check-circle"></i> Accept
                            </button>
                            <button class="btn-reject btn-action" onclick="rejectGuiderAppeal(<?= $appeal['appealID'] ?>)">
                              <i class="bi bi-x-circle"></i> Reject
                            </button>
                          <?php endif; ?>
                          
                          <!-- action untuk hiker appeal -->
                          <?php if ($appeal['hikerID'] && !$appeal['guiderID']): ?>
                            <button class="btn-accept btn-action" onclick="acceptHikerAppeal(<?= $appeal['appealID'] ?>, <?= $appeal['bookingID'] ?>)">
                              <i class="bi bi-check-circle"></i> Accept
                            </button>
                            <button class="btn-reject btn-action" onclick="rejectHikerAppeal(<?= $appeal['appealID'] ?>)">
                              <i class="bi bi-x-circle"></i> Reject
                            </button>
                          <?php endif; ?>
                        <?php elseif ($appeal['status'] == 'awaiting_hiker_choice'): ?>
                          <!-- tunggu hiker pilih refund atau tukar guider -->
                          <span class="text-muted small">
                            <i class="bi bi-clock me-1"></i>Waiting for hiker choice
                          </span>
                        <?php elseif ($appeal['status'] == 'approved'): ?>
                          <!-- dah approved: tunjuk button View je (tak payah extra actions) -->
                          <span class="text-muted small"><i class="bi bi-info-circle me-1"></i>Approved</span>
                        <?php elseif ($appeal['status'] == 'onhold'): ?>
                          <?php if (isset($appeal['appealType']) && $appeal['appealType'] === 'change'): ?>
                            <button class="btn-change-guider btn-action" onclick="changeGuider(<?= $appeal['appealID'] ?>, <?= $appeal['bookingID'] ?>)">
                              <i class="bi bi-person-plus"></i> Change Guider
                            </button>
                          <?php else: ?>
                            <span class="text-muted small"><i class="bi bi-clock me-1"></i>Awaiting admin action</span>
                          <?php endif; ?>
                        <?php elseif ($appeal['status'] == 'pending_refund'): ?>
                          <button class="btn-accept btn-action" onclick="approveRefund(<?= $appeal['appealID'] ?>, <?= $appeal['bookingID'] ?>)">
                            <i class="bi bi-check-circle"></i> Approve
                          </button>
                          <button class="btn-reject btn-action" onclick="rejectRefund(<?= $appeal['appealID'] ?>, <?= $appeal['bookingID'] ?>)">
                            <i class="bi bi-x-circle"></i> Reject
                          </button>
                        <?php elseif ($appeal['status'] == 'refunded'): ?>
                          <span class="text-warning fw-semibold d-flex align-items-center gap-1">
                            <i class="bi bi-currency-dollar"></i>
                            Refunded — payment will be processed within 3 working days
                          </span>
                        <?php elseif ($appeal['status'] == 'resolved'): ?>
                          <span class="text-secondary fw-semibold d-flex align-items-center gap-1">
                            <i class="bi bi-check2-circle"></i>
                            Resolved — no further action required
                          </span>
                        <?php elseif ($appeal['status'] == 'rejected'): ?>
                          <span class="text-danger fw-semibold d-flex align-items-center gap-1">
                            <i class="bi bi-x-circle"></i>
                            Rejected — booking continues as scheduled
                          </span>
                        <?php elseif ($appeal['status'] == 'refund_rejected'): ?>
                          <span class="text-danger fw-semibold d-flex align-items-center gap-1">
                            <i class="bi bi-x-circle"></i>
                            Refund Rejected — booking cancelled without refund
                          </span>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        </div>
      </div>
    </div>
  </div>
  <script>
    // control sidebar mobile
    function toggleMobileMenu() {
      const sidebarEl = document.querySelector('.sidebar');
      const overlayEl = document.querySelector('.mobile-overlay');
      if (!sidebarEl || !overlayEl) return;
      const isOpen = sidebarEl.classList.toggle('mobile-open');
      overlayEl.classList.toggle('show', isOpen);
      document.body.style.overflow = isOpen ? 'hidden' : 'auto';
    }

    function closeMobileMenu() {
      const sidebarEl = document.querySelector('.sidebar');
      const overlayEl = document.querySelector('.mobile-overlay');
      if (!sidebarEl || !overlayEl) return;
      sidebarEl.classList.remove('mobile-open');
      overlayEl.classList.remove('show');
      document.body.style.overflow = 'auto';
    }

    // expose untuk inline onclick handlers
    window.toggleMobileMenu = toggleMobileMenu;
    window.closeMobileMenu = closeMobileMenu;

    // tutup kalau tekan ESC
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        closeMobileMenu();
      }
    });

    // tutup kalau klik link sidebar mana-mana
    document.querySelectorAll('.sidebar .menu a').forEach(function(a) {
      a.addEventListener('click', closeMobileMenu);
    });

    // function untuk format tarikh
    function formatDateDDMMYYYY(dateString) {
      if (!dateString) return 'N/A';
      const date = new Date(dateString);
      const day = String(date.getDate()).padStart(2, '0');
      const month = String(date.getMonth() + 1).padStart(2, '0');
      const year = date.getFullYear();
      return `${day}/${month}/${year}`;
    }

    // function manage appeals
    function viewAppeal(appealId) {
      // ambil detail appeal
      fetch('get_appeal_details.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          appealId: appealId
        })
      })
      .then(response => {
        console.log('Response status:', response.status);
        return response.json();
      })
      .then(data => {
        console.log('Response data:', data);
        if (data.success) {
          displayAppealModal(data.appeal);
        } else {
          alert('Error: ' + data.message);
        }
      })
      .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while loading appeal details: ' + error.message);
      });
    }

    function displayAppealModal(appeal) {
      console.log('Displaying appeal modal with data:', appeal);
      
      // helper function untuk set element content dengan selamat
      function setElementContent(id, content) {
        const element = document.getElementById(id);
        if (element) {
          element.textContent = content;
        } else {
          console.error('Element not found:', id);
        }
      }
      
      function setElementHTML(id, content) {
        const element = document.getElementById(id);
        if (element) {
          element.innerHTML = content;
        } else {
          console.error('Element not found:', id);
        }
      }
      
      // isi modal dengan data appeal
      setElementContent('viewAppealId', appeal.appealID || 'N/A');
      setElementContent('viewBookingId', appeal.bookingID || 'N/A');
      setElementContent('viewAppealType', appeal.appealType || 'N/A');
      setElementContent('viewReason', appeal.reason || 'No reason provided');
      setElementContent('viewCreatedAt', appeal.createdAt ? formatDateDDMMYYYY(appeal.createdAt) : 'N/A');
      
      // set warna status badge
      const statusBadge = document.getElementById('viewStatusBadge');
      if (statusBadge) {
        let badgeClass = 'danger';
        if (appeal.status == 'pending') badgeClass = 'warning';
        else if (appeal.status == 'approved') badgeClass = 'success';
        else if (appeal.status == 'awaiting_hiker_choice') badgeClass = 'info';
        
        statusBadge.className = 'badge bg-' + badgeClass;
        statusBadge.textContent = appeal.status ? appeal.status.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase()) : 'Unknown';
      }
      
      // tunjuk info hiker/guider
      if (appeal.hiker_name) {
        setElementHTML('viewHikerInfo', `
          <div class="mb-2">
            <strong>${appeal.hiker_name}</strong><br>
            <small class="text-muted">${appeal.hiker_email || 'N/A'}</small>
          </div>
          ${appeal.hiker_phone ? `<div><small class="text-muted"><i class="bi bi-phone me-1"></i>${appeal.hiker_phone}</small></div>` : ''}
        `);
        const hikerSection = document.getElementById('viewHikerSection');
        const guiderSection = document.getElementById('viewGuiderSection');
        if (hikerSection) hikerSection.style.display = 'block';
        if (guiderSection) guiderSection.style.display = 'none';
      } else if (appeal.guider_name) {
        setElementHTML('viewGuiderInfo', `
          <div class="mb-2">
            <strong>${appeal.guider_name}</strong><br>
            <small class="text-muted">${appeal.guider_email || 'N/A'}</small>
          </div>
          ${appeal.guider_phone ? `<div><small class="text-muted"><i class="bi bi-phone me-1"></i>${appeal.guider_phone}</small></div>` : ''}
        `);
        const hikerSection = document.getElementById('viewHikerSection');
        const guiderSection = document.getElementById('viewGuiderSection');
        if (hikerSection) hikerSection.style.display = 'none';
        if (guiderSection) guiderSection.style.display = 'block';
      }
      
      // tunjuk detail booking
      if (appeal.startDate && appeal.endDate) {
        let bookingHtml = `
          <div class="mb-2">
            <strong>Start:</strong> ${formatDateDDMMYYYY(appeal.startDate)}<br>
            <strong>End:</strong> ${formatDateDDMMYYYY(appeal.endDate)}
          </div>
        `;
        
        if (appeal.mountain_name) {
          bookingHtml += `<div class="mb-2"><strong>Mountain:</strong> ${appeal.mountain_name}</div>`;
        }
        
        if (appeal.price) {
          bookingHtml += `<div class="mb-2"><strong>Price:</strong> RM ${appeal.price}</div>`;
        }
        
        if (appeal.booking_status) {
          const statusColor = appeal.booking_status === 'confirmed' ? 'success' : 
                           appeal.booking_status === 'pending' ? 'warning' : 'danger';
          bookingHtml += `<div><strong>Status:</strong> <span class="badge bg-${statusColor}">${appeal.booking_status}</span></div>`;
        }
        
        setElementHTML('viewBookingDates', bookingHtml);
        const bookingSection = document.getElementById('viewBookingSection');
        if (bookingSection) bookingSection.style.display = 'block';
      } else {
        const bookingSection = document.getElementById('viewBookingSection');
        if (bookingSection) bookingSection.style.display = 'none';
      }
      
      // tunjuk modal guna Bootstrap vanilla JavaScript API
      const modal = new bootstrap.Modal(document.getElementById('viewAppealModal'));
      modal.show();
    }

    // action untuk guider appeal
    function acceptGuiderAppeal(appealId, bookingId, appealType) {
      let confirmMessage = '';
      let successMessage = '';
      
      if (appealType === 'cancellation') {
        confirmMessage = 'Accept this guider cancellation request? The booking will be CANCELLED immediately.';
        successMessage = 'Guider cancellation accepted! The booking has been cancelled.';
      } else if (appealType === 'change') {
        confirmMessage = 'Accept this guider change request? The booking will be put on hold for hiker to choose.';
        successMessage = 'Guider change request accepted! Waiting for hiker to choose.';
      } else {
        confirmMessage = 'Accept this guider appeal?';
        successMessage = 'Guider appeal accepted!';
      }
      
      if (confirm(confirmMessage)) {
        // update status appeal jadi Accepted
        fetch('process_appeal.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            action: 'accept_guider_appeal',
            appealId: appealId,
            bookingId: bookingId
          })
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            alert(successMessage);
            location.reload();
          } else {
            alert('Error: ' + data.message);
          }
        })
        .catch(error => {
          console.error('Error:', error);
          alert('An error occurred while processing the appeal.');
        });
      }
    }

    function rejectGuiderAppeal(appealId) {
      if (confirm('Reject this guider appeal? The booking will remain active.')) {
        fetch('process_appeal.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            action: 'reject_appeal',
            appealId: appealId
          })
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            alert('Guider appeal rejected!');
            location.reload();
          } else {
            alert('Error: ' + data.message);
          }
        })
        .catch(error => {
          console.error('Error:', error);
          alert('An error occurred while processing the appeal.');
        });
      }
    }

    // action untuk hiker appeal
    function acceptHikerAppeal(appealId, bookingId) {
      if (confirm('Accept this hiker appeal? The hiker can now choose refund or change guider.')) {
        fetch('process_appeal.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            action: 'accept_hiker_appeal',
            appealId: appealId,
            bookingId: bookingId
          })
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            alert('Hiker appeal accepted! The hiker can now choose refund or change guider.');
            location.reload();
          } else {
            alert('Error: ' + data.message);
          }
        })
        .catch(error => {
          console.error('Error:', error);
          alert('An error occurred while processing the appeal.');
        });
      }
    }

    function rejectHikerAppeal(appealId) {
      if (confirm('Reject this hiker appeal? The booking will remain as is.')) {
        fetch('process_appeal.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            action: 'reject_appeal',
            appealId: appealId
          })
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            alert('Hiker appeal rejected!');
            location.reload();
          } else {
            alert('Error: ' + data.message);
          }
        })
        .catch(error => {
          console.error('Error:', error);
          alert('An error occurred while processing the appeal.');
        });
      }
    }

    // action lepas accept
    function cancelBooking(bookingId) {
      if (confirm('Cancel this booking? This action cannot be undone.')) {
        fetch('process_appeal.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            action: 'cancel_booking',
            bookingId: bookingId
          })
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            alert('Booking cancelled successfully!');
            location.reload();
          } else {
            alert('Error: ' + data.message);
          }
        })
        .catch(error => {
          console.error('Error:', error);
          alert('An error occurred while cancelling the booking.');
        });
      }
    }

    function processRefund(appealId, bookingId) {
      if (confirm('Process refund for this booking? This will cancel the booking and initiate refund process.')) {
        fetch('process_appeal.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            action: 'process_refund',
            appealId: appealId,
            bookingId: bookingId
          })
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            // update row terus: badge + actions
            const row = document.querySelector(`tr[data-appeal-id="${appealId}"]`);
            if (row) {
              // update status badge cell (column ke-7 => td:nth-child(7))
              const statusCell = row.querySelector('td:nth-child(7) .badge');
              if (statusCell) {
                statusCell.className = 'badge bg-warning';
                statusCell.textContent = 'Refunded';
              }
              // replace actions cell (column ke-8) dengan mesej refunded
              const actionsCell = row.querySelector('td:nth-child(8) .d-flex');
              if (actionsCell) {
                actionsCell.innerHTML = `
                  <span class="text-warning fw-semibold d-flex align-items-center gap-1">
                    <i class="bi bi-currency-dollar"></i>
                    Refunded — payment will be processed within 3 working days
                  </span>
                `;
              }
            }
            // optional toast
            alert('Refund marked successfully.');
          } else {
            alert('Error: ' + data.message);
          }
        })
        .catch(error => {
          console.error('Error:', error);
          alert('An error occurred while processing the refund.');
        });
      }
    }

    function approveRefund(appealId, bookingId) {
      if (confirm('Approve this refund request? The booking will be cancelled and payment will be processed within 3 working days.')) {
        fetch('process_appeal.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            action: 'approve_refund',
            appealId: appealId,
            bookingId: bookingId
          })
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            alert('Refund approved successfully! Payment will be processed within 3 working days.');
            location.reload();
          } else {
            alert('Error: ' + data.message);
          }
        })
        .catch(error => {
          console.error('Error:', error);
          alert('An error occurred while approving the refund.');
        });
      }
    }

    function rejectRefund(appealId, bookingId) {
      if (confirm('Reject this refund request? The booking will be cancelled WITHOUT refund.')) {
        fetch('process_appeal.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            action: 'reject_refund',
            appealId: appealId,
            bookingId: bookingId
          })
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            alert('Refund rejected. Booking has been cancelled without refund.');
            location.reload();
          } else {
            alert('Error: ' + data.message);
          }
        })
        .catch(error => {
          console.error('Error:', error);
          alert('An error occurred while rejecting the refund.');
        });
      }
    }

    function changeGuider(appealId, bookingId) {
      // buka modal untuk pilih guider baru
      const modal = new bootstrap.Modal(document.getElementById('changeGuiderModal'));
      modal.show();
      
      // simpan appeal dan booking IDs untuk guna nanti
      document.getElementById('changeGuiderModal').dataset.appealId = appealId;
      document.getElementById('changeGuiderModal').dataset.bookingId = bookingId;
      
      // load guiders yang available untuk tarikh booking tu
      loadAvailableGuiders(bookingId);
    }

    function loadAvailableGuiders(bookingId) {
      fetch('process_appeal.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          action: 'get_available_guiders',
          bookingId: bookingId
        })
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          displayAvailableGuiders(data.guiders);
        } else {
          document.getElementById('availableGuidersList').innerHTML = 
            '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>' + data.message + '</div>';
        }
      })
      .catch(error => {
        console.error('Error:', error);
        document.getElementById('availableGuidersList').innerHTML = 
          '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>Error loading available guiders</div>';
      });
    }

    function displayAvailableGuiders(guiders) {
      const container = document.getElementById('availableGuidersList');
      
      if (guiders.length === 0) {
        container.innerHTML = '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>No available guiders found for the selected dates.</div>';
        return;
      }
      
      let html = '<div class="row">';
      guiders.forEach(guider => {
        html += `
          <div class="col-md-6 mb-3">
            <div class="card guider-card" onclick="selectGuider(this, ${guider.guiderID})" style="cursor: pointer;">
              <div class="card-body">
                <h6 class="card-title">${guider.username}</h6>
                <p class="card-text small text-muted mb-1">${guider.email}</p>
                <p class="card-text small text-muted mb-1">${guider.phoneNumber}</p>
                <p class="card-text small"><strong>Experience:</strong> ${guider.experience} years</p>
                <p class="card-text small"><strong>Skill:</strong> ${guider.skills}</p>
              </div>
            </div>
          </div>
        `;
      });
      html += '</div>';
      
      container.innerHTML = html;
    }

    function selectGuider(cardEl, guiderId) {
      // buang pilihan sebelum ni
      document.querySelectorAll('.guider-card').forEach(card => {
        card.classList.remove('border-primary', 'bg-light');
      });
      
      // tambah selection pada card yang diklik
      if (cardEl) {
        cardEl.classList.add('border-primary', 'bg-light');
      }
      
      // enable button confirm
      document.getElementById('confirmChangeGuider').disabled = false;
      document.getElementById('confirmChangeGuider').onclick = function() {
        confirmGuiderChange(guiderId);
      };
    }

    function confirmGuiderChange(newGuiderId) {
      const modalElement = document.getElementById('changeGuiderModal');
      const appealId = modalElement.dataset.appealId;
      const bookingId = modalElement.dataset.bookingId;
      
      fetch('process_appeal.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          action: 'change_guider',
          appealId: appealId,
          bookingId: bookingId,
          newGuiderId: newGuiderId
        })
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          alert('Guider changed successfully!');
          const modal = bootstrap.Modal.getInstance(modalElement);
          modal.hide();
          location.reload();
        } else {
          alert('Error: ' + data.message);
        }
      })
      .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while changing the guider.');
      });
    }
  </script>

  <!-- modal view appeal -->
  <div class="modal fade" id="viewAppealModal" tabindex="-1" aria-labelledby="viewAppealModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="viewAppealModalLabel">
            <i class="bi bi-eye me-2"></i>Appeal Details
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-md-6">
              <h6 class="fw-bold text-primary mb-3">Appeal Information</h6>
              <div class="mb-3">
                <label class="form-label fw-semibold">Appeal ID:</label>
                <div id="viewAppealId" class="text-muted">-</div>
              </div>
              <div class="mb-3">
                <label class="form-label fw-semibold">Booking ID:</label>
                <div id="viewBookingId" class="text-muted">-</div>
              </div>
              <div class="mb-3">
                <label class="form-label fw-semibold">Appeal Type:</label>
                <div id="viewAppealType" class="text-muted">-</div>
              </div>
              <div class="mb-3">
                <label class="form-label fw-semibold">Status:</label>
                <div><span id="viewStatusBadge" class="badge">-</span></div>
              </div>
              <div class="mb-3">
                <label class="form-label fw-semibold">Created At:</label>
                <div id="viewCreatedAt" class="text-muted">-</div>
              </div>
            </div>
            <div class="col-md-6">
              <h6 class="fw-bold text-primary mb-3">Reason</h6>
              <div class="bg-light p-3 rounded">
                <div id="viewReason" class="text-muted">No reason provided</div>
              </div>
            </div>
          </div>
          
          <hr class="my-4">
          
          <div class="row">
            <div class="col-md-6">
              <div id="viewHikerSection" style="display: none;">
                <h6 class="fw-bold text-success mb-3">Hiker Information</h6>
                <div id="viewHikerInfo" class="text-muted">-</div>
              </div>
              <div id="viewGuiderSection" style="display: none;">
                <h6 class="fw-bold text-info mb-3">Guider Information</h6>
                <div id="viewGuiderInfo" class="text-muted">-</div>
              </div>
            </div>
            <div class="col-md-6">
              <div id="viewBookingSection" style="display: none;">
                <h6 class="fw-bold text-warning mb-3">Booking Details</h6>
                <div id="viewBookingDates" class="text-muted">-</div>
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

  <!-- modal tukar guider -->
  <div class="modal fade" id="changeGuiderModal" tabindex="-1" aria-labelledby="changeGuiderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="changeGuiderModalLabel">
            <i class="bi bi-person-plus me-2"></i>Change Guider
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            Select a new guider who is available during the booking dates.
          </div>
          <div id="availableGuidersList">
            <div class="text-center py-3">
              <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
              </div>
              <p class="mt-2">Loading available guiders...</p>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" id="confirmChangeGuider" disabled>
            <i class="bi bi-check-circle me-1"></i>Assign Guider
          </button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
