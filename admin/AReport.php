<?php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['email'])) {
    header("Location: ALogin.html");
    exit();
}

include '../shared/db_connection.php';

// Verify the email belongs to an admin
$email = $_SESSION['email'];
$stmt = $conn->prepare("SELECT 1 FROM admin WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) { session_destroy(); header("Location: ALogin.html"); exit(); }
$stmt->close();

// Utilities
function hasColumn(mysqli $conn, string $table, string $column): bool {
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    if ($res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'")) {
        $ok = $res->num_rows > 0;
        $res->close();
        return $ok;
    }
    return false;
}

// Helper for safe fetch
function val($row, $key, $default = 0) { return isset($row[$key]) ? $row[$key] : $default; }

// Financial metrics
$totals = [
  'revenue_total' => 0.0,
  'revenue_this_month' => 0.0,
  'revenue_last_month' => 0.0,
  'bookings_total' => 0,
];

// Total revenue (paid)
$q = $conn->query("SELECT COALESCE(SUM(price),0) AS s FROM booking WHERE status='paid'");
$totals['revenue_total'] = (float)val($q->fetch_assoc(), 's', 0); $q->close();

// Decide which date column to use for booking date-based metrics
$bkDateCol = null;
if (hasColumn($conn, 'booking', 'created_at')) $bkDateCol = 'created_at';
elseif (hasColumn($conn, 'booking', 'startDate')) $bkDateCol = 'startDate';

// This month revenue (paid)
if ($bkDateCol) {
  $q = $conn->query("SELECT COALESCE(SUM(price),0) AS s FROM booking WHERE status='paid' AND YEAR($bkDateCol)=YEAR(CURDATE()) AND MONTH($bkDateCol)=MONTH(CURDATE())");
  $totals['revenue_this_month'] = (float)val($q->fetch_assoc(), 's', 0); $q->close();
} else {
  $totals['revenue_this_month'] = 0.0;
}

// Last month revenue (paid)
if ($bkDateCol) {
  $q = $conn->query("SELECT COALESCE(SUM(price),0) AS s FROM booking WHERE status='paid' AND DATE_FORMAT($bkDateCol,'%Y-%m') = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH),'%Y-%m')");
  $totals['revenue_last_month'] = (float)val($q->fetch_assoc(), 's', 0); $q->close();
} else {
  $totals['revenue_last_month'] = 0.0;
}

// Total bookings
$q = $conn->query("SELECT COUNT(*) AS c FROM booking");
$totals['bookings_total'] = (int)val($q->fetch_assoc(), 'c', 0); $q->close();

// Booking status counts
$statusCounts = [ 'completed' => 0, 'paid' => 0, 'accepted' => 0, 'pending' => 0, 'cancelled' => 0 ];
$q = $conn->query("SELECT status, COUNT(*) c FROM booking GROUP BY status");
while ($r = $q->fetch_assoc()) { $statusCounts[strtolower($r['status'])] = (int)$r['c']; }
$q->close();

// KPI metrics
$totalCount = array_sum($statusCounts);
$paidCount = (int)($statusCounts['paid'] ?? 0);
$cancelCount = (int)($statusCounts['cancelled'] ?? 0);
$paidRate = $totalCount > 0 ? round(($paidCount / $totalCount) * 100, 1) : 0.0;
$cancelRate = $totalCount > 0 ? round(($cancelCount / $totalCount) * 100, 1) : 0.0;

// Average rating
$avgRating = 0.0;
if ($r = $conn->query("SELECT ROUND(AVG(rating),1) AS ar FROM review")) { $avgRating = (float)val($r->fetch_assoc(),'ar',0); $r->close(); }

// Active guiders last 30 days (paid)
$activeGuiders30d = 0;
if ($bkDateCol) {
  if ($r = $conn->query("SELECT COUNT(DISTINCT guiderID) c FROM booking WHERE status='paid' AND $bkDateCol >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")) {
    $activeGuiders30d = (int)val($r->fetch_assoc(),'c',0); $r->close();
  }
}

// Recent bookings table (latest 20)
$recent = [];
if ($bkDateCol) {
  $sql = "SELECT b.bookingID, COALESCE(h.username, CONCAT('Hiker #', b.hikerID)) AS customer,
                 m.name AS mountain, DATE(b.$bkDateCol) AS dt, b.price, b.status
          FROM booking b
          LEFT JOIN hiker h ON h.hikerID = b.hikerID
          LEFT JOIN mountain m ON m.mountainID = b.mountainID
          ORDER BY b.$bkDateCol DESC
          LIMIT 20";
} else {
  $sql = "SELECT b.bookingID, COALESCE(h.username, CONCAT('Hiker #', b.hikerID)) AS customer,
                 m.name AS mountain, NULL AS dt, b.price, b.status
          FROM booking b
          LEFT JOIN hiker h ON h.hikerID = b.hikerID
          LEFT JOIN mountain m ON m.mountainID = b.mountainID
          ORDER BY b.bookingID DESC
          LIMIT 20";
}
if ($r = $conn->query($sql)) { $recent = $r->fetch_all(MYSQLI_ASSOC); $r->close(); }

// Guider performance: top 5 guiders by paid revenue
$topGuiders = [];
$sql = "SELECT g.username AS guider, COALESCE(SUM(b.price),0) AS revenue, COUNT(*) AS tours
        FROM booking b
        JOIN guider g ON g.guiderID = b.guiderID
        WHERE b.status='paid'
        GROUP BY g.guiderID, g.username
        ORDER BY revenue DESC
        LIMIT 5";
if ($r = $conn->query($sql)) { $topGuiders = $r->fetch_all(MYSQLI_ASSOC); $r->close(); }

// Guider performance (Dashboard-like): names, total bookings, average rating (from review)
$gpNames = []; $gpBookings = []; $gpRatings = [];
$sql = "SELECT g.guiderID, g.username,
               COALESCE(AVG(rv.rating),0) AS avgRating,
               COUNT(b.bookingID) AS totalBookings
        FROM guider g
        LEFT JOIN booking b ON b.guiderID = g.guiderID
        LEFT JOIN review rv ON rv.guiderID = g.guiderID
        GROUP BY g.guiderID, g.username
        HAVING totalBookings > 0
        ORDER BY totalBookings DESC, avgRating DESC
        LIMIT 10";
if ($r = $conn->query($sql)) {
  while ($row = $r->fetch_assoc()) {
    $gpNames[] = $row['username'];
    $gpBookings[] = (int)$row['totalBookings'];
    $gpRatings[] = round((float)$row['avgRating'], 1);
  }
  $r->close();
}

// Top Mountains by paid revenue (top 5)
$topMountains = [];
$sql = "SELECT m.name AS mountain, 
               COALESCE(SUM(CASE WHEN b.status='paid' THEN b.price ELSE 0 END),0) AS revenue,
               SUM(CASE WHEN b.status='paid' THEN 1 ELSE 0 END) AS paidCnt,
               ROUND(AVG(rv.rating),1) AS avgRating
        FROM mountain m
        LEFT JOIN booking b ON b.mountainID = m.mountainID
        LEFT JOIN review rv ON rv.bookingID = b.bookingID
        GROUP BY m.mountainID, m.name
        ORDER BY revenue DESC
        LIMIT 5";
if ($r = $conn->query($sql)) { $topMountains = $r->fetch_all(MYSQLI_ASSOC); $r->close(); }

// Users metrics
$users = [ 'total' => 0, 'new_this_month' => 0, 'active_pct' => 0 ];
$q = $conn->query("SELECT COUNT(*) c FROM hiker"); $users['total'] = (int)val($q->fetch_assoc(), 'c', 0); $q->close();

// Detect hiker created column
$hkDateCol = null;
if (hasColumn($conn, 'hiker', 'created_at')) $hkDateCol = 'created_at';
elseif (hasColumn($conn, 'hiker', 'createdAt')) $hkDateCol = 'createdAt';

if ($hkDateCol) {
  $q = $conn->query("SELECT COUNT(*) c FROM hiker WHERE YEAR($hkDateCol)=YEAR(CURDATE()) AND MONTH($hkDateCol)=MONTH(CURDATE())");
  $users['new_this_month'] = (int)val($q->fetch_assoc(), 'c', 0); $q->close();
} elseif ($bkDateCol) {
  // Fallback: new users approximated by distinct hikers who made their first booking this month
  $sqlNewUsers = "SELECT COUNT(*) c FROM (
                   SELECT b.hikerID, MIN(DATE_FORMAT($bkDateCol,'%Y-%m')) AS firstYM
                   FROM booking b GROUP BY b.hikerID
                 ) t WHERE t.firstYM = DATE_FORMAT(CURDATE(), '%Y-%m')";
  if ($r2 = $conn->query($sqlNewUsers)) { $users['new_this_month'] = (int)val($r2->fetch_assoc(), 'c', 0); $r2->close(); }
} else {
  $users['new_this_month'] = 0;
}

// Active users last 30 days via bookings
if ($bkDateCol) {
  $q = $conn->query("SELECT COUNT(DISTINCT hikerID) c FROM booking WHERE $bkDateCol >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
  $activeUsers = (int)val($q->fetch_assoc(), 'c', 0); $q->close();
} else {
  $activeUsers = 0;
}
$users['active_pct'] = $users['total'] > 0 ? round(($activeUsers / $users['total']) * 100) : 0;

// Engagement & retention
$repeatCustomers = 0;
if ($r = $conn->query("SELECT COUNT(*) c FROM (SELECT hikerID, COUNT(*) c FROM booking WHERE status='paid' GROUP BY hikerID HAVING c>=2) t")) {
  $repeatCustomers = (int)val($r->fetch_assoc(),'c',0); $r->close();
}

$returningSharePct = 0;
if ($bkDateCol) {
  $paidMonthTotal = 0; $returningMonth = 0;
  if ($r = $conn->query("SELECT COUNT(*) c FROM booking WHERE status='paid' AND DATE_FORMAT($bkDateCol,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')")) {
    $paidMonthTotal = (int)val($r->fetch_assoc(),'c',0); $r->close();
  }
  $sqlRet = "SELECT COUNT(*) c FROM booking b 
             WHERE b.status='paid' AND DATE_FORMAT(b.$bkDateCol,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')
               AND EXISTS (
                 SELECT 1 FROM booking b2
                 WHERE b2.hikerID = b.hikerID AND b2.status='paid'
                   AND DATE_FORMAT(b2.$bkDateCol,'%Y-%m') < DATE_FORMAT(CURDATE(),'%Y-%m')
               )";
  if ($r = $conn->query($sqlRet)) { $returningMonth = (int)val($r->fetch_assoc(),'c',0); $r->close(); }
  $returningSharePct = $paidMonthTotal > 0 ? round(($returningMonth / $paidMonthTotal) * 100) : 0;
}

// Mountain popularity (top 10 by paid/completed bookings), fallback to all statuses if empty
$mountainPopularity = [];
$sql = "SELECT m.name AS mountain, COUNT(*) AS cnt
        FROM booking b
        JOIN mountain m ON m.mountainID = b.mountainID
        WHERE b.status IN ('paid','completed')
        GROUP BY b.mountainID, m.name
        ORDER BY cnt DESC
        LIMIT 10";
if ($r = $conn->query($sql)) { $mountainPopularity = $r->fetch_all(MYSQLI_ASSOC); $r->close(); }
// Fallback: if still no data, count all bookings regardless of status
if (empty($mountainPopularity)) {
  $sql = "SELECT m.name AS mountain, COUNT(*) AS cnt
          FROM booking b
          JOIN mountain m ON m.mountainID = b.mountainID
          GROUP BY b.mountainID, m.name
          ORDER BY cnt DESC
          LIMIT 10";
  if ($r = $conn->query($sql)) { $mountainPopularity = $r->fetch_all(MYSQLI_ASSOC); $r->close(); }
}
$mpLabels = array_map(fn($x) => $x['mountain'], $mountainPopularity);
$mpValues = array_map(fn($x) => (int)$x['cnt'], $mountainPopularity);

// Revenue by Mountain (Top 10 by paid revenue)
$revByMtn = [];
$sql = "SELECT m.name AS mountain, COALESCE(SUM(b.price),0) AS revenue
        FROM booking b
        JOIN mountain m ON m.mountainID = b.mountainID
        WHERE b.status='paid'
        GROUP BY b.mountainID, m.name
        ORDER BY revenue DESC
        LIMIT 10";
if ($r = $conn->query($sql)) { $revByMtn = $r->fetch_all(MYSQLI_ASSOC); $r->close(); }
$rbmLabels = array_map(fn($x) => $x['mountain'], $revByMtn);
$rbmValues = array_map(fn($x) => (float)$x['revenue'], $revByMtn);

// Bookings by status per month (last 6 months)
$bmsLabels = [];
$bmsPaid = []; $bmsPending = []; $bmsCancelled = [];
if ($bkDateCol) {
  // Pre-fill months
  for ($i=5; $i>=0; $i--) {
    $ym = date('Y-m', strtotime("-{$i} month"));
    $bmsLabels[] = date('M', strtotime($ym.'-01'));
    $bmsPaid[$ym] = 0; $bmsPending[$ym] = 0; $bmsCancelled[$ym] = 0;
  }
  $sql = "SELECT DATE_FORMAT($bkDateCol,'%Y-%m') ym,
                  SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) AS paid,
                  SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending,
                  SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) AS cancelled
           FROM booking
           WHERE $bkDateCol >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
           GROUP BY ym";
  if ($r = $conn->query($sql)) {
    while ($row = $r->fetch_assoc()) {
      $ym = $row['ym'];
      if (isset($bmsPaid[$ym])) {
        $bmsPaid[$ym] = (int)$row['paid'];
        $bmsPending[$ym] = (int)$row['pending'];
        $bmsCancelled[$ym] = (int)$row['cancelled'];
      }
    }
    $r->close();
  }
}
$bmsPaidArr = array_values($bmsPaid);
$bmsPendingArr = array_values($bmsPending);
$bmsCancelledArr = array_values($bmsCancelled);

// Financial chart: last 6 months revenue
$labels = []; $revenues = [];
$tmp = [];
if ($bkDateCol) {
  $q = $conn->query("SELECT DATE_FORMAT($bkDateCol,'%Y-%m') ym, COALESCE(SUM(price),0) s
                     FROM booking WHERE status='paid'
                     GROUP BY ym ORDER BY ym DESC LIMIT 6");
  while ($row = $q->fetch_assoc()) { $tmp[$row['ym']] = (float)$row['s']; }
  $q->close();
}
// Fill last 6 months in chronological order
for ($i = 5; $i >= 0; $i--) {
  $ym = date('Y-m', strtotime("-{$i} month"));
  $labels[] = date('M', strtotime($ym.'-01'));
  $revenues[] = isset($tmp[$ym]) ? $tmp[$ym] : 0.0;
}

// Guider doughnut chart data
$gLabels = array_map(fn($x) => $x['guider'], $topGuiders);
$gValues = array_map(fn($x) => (float)$x['revenue'], $topGuiders);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Reports</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.3.0/css/all.min.css" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet" />
  <style>
    :root { --primary-color:#3fd847; --secondary-color:#85fb5a; --success-color:#28a745; --warning-color:#ffc107; --danger-color:#dc3545; --dark-color:#343a40; --light-color:#f8f9fa; }
    body { background-color:#f5f5f5; font-family:'Montserrat',sans-serif; margin:0; padding:0; }
    .navbar { background-color:#571785 !important; padding:12px 0; box-shadow:0 2px 10px rgba(0,0,0,0.1); }
    .navbar-title { font-size:22px; font-weight:bold; color:white; margin:0 auto; text-shadow:1px 1px 3px rgba(0,0,0,0.2); }
    .logo { width:60px; height:60px; object-fit:contain; }
    .sidebar { background:linear-gradient(135deg,#571785 0%,#4a0f6b 100%) !important; position:fixed; top:0; left:-350px; width:320px; height:100vh; padding:100px 15px 20px 15px !important; box-shadow:0 8px 32px rgba(87,23,133,0.4); border:2px solid rgba(255,255,255,0.1); z-index:1000; transition:left .3s ease; overflow-y:auto; }
    .sidebar.mobile-open { left:0; }
    .mobile-menu-btn { display:inline-flex; position:static; z-index:1001; background:linear-gradient(135deg,#571785 0%,#4a0f6b 100%); color:white; border:none; border-radius:12px; padding:12px; font-size:1.2rem; box-shadow:0 4px 12px rgba(87,23,133,0.3); cursor:pointer; transition:all .3s ease; align-items:center; justify-content:center; }
    .mobile-menu-btn:hover { background:linear-gradient(135deg,#4a0f6b 0%,#3d0a5c 100%); transform:scale(1.05); }
    .mobile-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,.5); z-index:999; transition:opacity .3s ease; }
    .mobile-overlay.show { display:block; }
    .sidebar .menu a { display:flex; align-items:center; padding:15px 20px; color:#fff; font-weight:600; text-decoration:none; margin-bottom:8px; border-radius:12px; transition:all .3s ease; border:1px solid transparent; position:relative; overflow:hidden; }
    .sidebar .menu a::before { content:''; position:absolute; top:0; left:-100%; width:100%; height:100%; background:linear-gradient(90deg,transparent,rgba(255,255,255,.2),transparent); transition:left .5s; }
    .sidebar .menu a:hover::before { left:100%; }
    .sidebar .menu a.active { background:rgba(255,255,255,.2); border-color:rgba(255,255,255,.4); box-shadow:0 4px 12px rgba(0,0,0,.3); }
    .sidebar .menu a:hover { background:rgba(255,255,255,.15); border-color:rgba(255,255,255,.3); transform:translateX(5px); box-shadow:0 4px 12px rgba(0,0,0,.2); }
    .sidebar .menu i { margin-right:15px; font-size:18px; width:20px; text-align:center; }
    .wrapper { display:flex; min-height:100vh; flex-direction:column; }
    body { padding-top:80px; }
    .main-content { flex-grow:1; padding:80px 15px 20px 15px; width:100%; margin-left:0; }

    /* Page hero header (match Appeal Management style) */
    .page-hero {
      background: linear-gradient(180deg, #f6f8ff 0%, #f7fbff 100%);
      border-radius: 20px;
      padding: 40px 20px;
      margin-bottom: 24px;
      text-align: center;
      box-shadow: 0 6px 18px rgba(87, 23, 133, 0.06);
      border: 1px solid rgba(87, 23, 133, 0.08);
    }
    .page-hero-title {
      font-size: 2.5rem;
      font-weight: 700;
      margin: 0 0 6px 0;
      background: linear-gradient(135deg, #4a0f6b, #571785);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      letter-spacing: 0.2px;
    }
    .page-hero-subtitle {
      color: #64748b;
      font-weight: 500;
      font-size: 1.05rem;
      margin: 0;
    }
    .report-card { background:#fff; border-radius:15px; padding:25px; margin-bottom:25px; box-shadow:0 4px 8px rgba(0,0,0,0.1); transition: transform .2s ease; }
    .report-card:hover { transform: translateY(-2px); }
    .report-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-bottom:15px; border-bottom:2px solid #f0f0f0; }
    .report-title { font-size:20px; font-weight:600; color:#571785; margin:0; }
    .report-icon { width:50px; height:50px; background:#571785; border-radius:10px; display:flex; align-items:center; justify-content:center; color:#fff; font-size:20px; }
    .stats-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(200px,1fr)); gap:20px; margin-bottom:20px; }
    .stat-item { text-align:center; padding:15px; background:#f8f9fa; border-radius:10px; border-left:4px solid #571785; }
    .stat-number { font-size:24px; font-weight:bold; color:#571785; margin-bottom:5px; }
    .stat-label { font-size:14px; color:#666; font-weight:500; }
    .table-responsive { border-radius:10px; overflow:hidden; }
    .table { margin-bottom:0; }
    .table th { background:#571785; color:#fff; border:none; font-weight:600; padding:15px; }
    .table td { padding:12px 15px; vertical-align:middle; border-bottom:1px solid #f0f0f0; }
    .status-badge { padding:5px 12px; border-radius:20px; font-size:12px; font-weight:600; text-transform:uppercase; }
    .status-paid,.status-completed { background:#d4edda; color:#155724; }
    .status-pending { background:#fff3cd; color:#856404; }
    .status-cancelled { background:#f8d7da; color:#721c24; }
    .export-btn { background:#571785; color:#fff; border:none; padding:8px 16px; border-radius:5px; font-size:14px; font-weight:500; }
  </style>
</head>
<body>
  <div class="mobile-overlay" onclick="closeMobileMenu()"></div>
  <header>
    <nav class="navbar fixed-top">
      <div class="container d-flex align-items-center">
        <button class="mobile-menu-btn me-2" onclick="toggleMobileMenu()"><i class="bi bi-list"></i></button>
        <a class="navbar-brand d-flex align-items-center" href="../index.html">
          <img src="../img/logo.png" class="img-fluid logo me-2" alt="HGS Logo" style="width: 50px; height: 50px;">
          <span class="fs-6 fw-bold text-white">Admin</span>
        </a>
        <h1 class="navbar-title ms-auto me-auto">HIKING GUIDANCE SYSTEM</h1>
      </div>
    </nav>
  </header>

  <div class="wrapper">
    <div class="sidebar">
      <div class="logo-admin"><strong class="ms-2 text-white">Menu</strong></div>
      <div class="menu mt-4">
        <a href="ADashboard.html"><i class="bi bi-grid-fill"></i> Dashboard</a>
        <a href="AUser.html"><i class="bi bi-people-fill"></i> User</a>
        <a href="AMountain.php"><i class="bi bi-triangle-fill"></i> Mountain</a>
        <a href="AAppeal.php"><i class="bi bi-chat-dots-fill"></i> Appeal</a>
        <a class="active" href="AReport.php"><i class="bi bi-file-earmark-text-fill"></i> Reports</a>
        <div class="text-center mt-4">
          <form action="../shared/logout.php" method="POST" class="d-flex justify-content-center">
            <button class="btn btn-danger logout-btn w-50" type="submit"><i class="bi bi-box-arrow-right"></i> Log Out</button>
          </form>
        </div>
      </div>
    </div>

    <div class="main-content">
      <div class="container">
        <section class="page-hero">
          <h1 class="page-hero-title">Reports & Analytics</h1>
          <p class="page-hero-subtitle">View revenue, bookings, guider performance, and user analytics</p>
        </section>

        <!-- Key Metrics -->
        <div class="report-card" id="keyMetricsReport">
          <div class="report-header">
            <h2 class="report-title">Key Metrics</h2>
          </div>
          <div class="stats-grid">
            <div class="stat-item"><div class="stat-number"><?php echo number_format($paidRate, 1); ?>%</div><div class="stat-label">Paid Rate</div></div>
            <div class="stat-item"><div class="stat-number"><?php echo number_format($cancelRate, 1); ?>%</div><div class="stat-label">Cancellation Rate</div></div>
            <div class="stat-item"><div class="stat-number"><?php echo number_format($avgRating, 1); ?></div><div class="stat-label">Average Rating</div></div>
            <div class="stat-item"><div class="stat-number"><?php echo number_format($activeGuiders30d); ?></div><div class="stat-label">Active Guiders (30d)</div></div>
          </div>
        </div>

        <!-- Financial Report -->
        <div class="report-card" id="financialReport">
          <div class="report-header">
            <h2 class="report-title">Financial Report</h2>
            <div class="d-flex align-items-center gap-3">
              <div class="report-icon"><i class="bi bi-currency-dollar"></i></div>
              <button class="export-btn" onclick="exportReport('financialReport')"><i class="bi bi-download me-1"></i> Export</button>
            </div>
          </div>
          <div class="stats-grid">
            <div class="stat-item"><div class="stat-number">RM <?php echo number_format($totals['revenue_total'],2); ?></div><div class="stat-label">Total Revenue</div></div>
            <div class="stat-item"><div class="stat-number">RM <?php echo number_format($totals['revenue_this_month'],2); ?></div><div class="stat-label">This Month</div></div>
            <div class="stat-item"><div class="stat-number">RM <?php echo number_format($totals['revenue_last_month'],2); ?></div><div class="stat-label">Last Month</div></div>
            <div class="stat-item"><div class="stat-number"><?php echo number_format($totals['bookings_total']); ?></div><div class="stat-label">Total Bookings</div></div>
          </div>
          <div class="chart-container" style="height:300px;">
            <canvas id="financialChart"></canvas>
          </div>
        </div>

        <!-- Booking Report -->
        <div class="report-card" id="bookingReport">
          <div class="report-header">
            <h2 class="report-title">Booking Report</h2>
            <div class="d-flex align-items-center gap-3">
              <div class="report-icon"><i class="bi bi-calendar-check"></i></div>
              <button class="export-btn" onclick="exportReport('bookingReport')"><i class="bi bi-download me-1"></i> Export</button>
            </div>
          </div>
          <div class="stats-grid">
            <div class="stat-item"><div class="stat-number"><?php echo number_format($statusCounts['paid'] + $statusCounts['completed'] ?? 0); ?></div><div class="stat-label">Completed/Paid</div></div>
            <div class="stat-item"><div class="stat-number"><?php echo number_format($statusCounts['pending'] ?? 0); ?></div><div class="stat-label">Pending</div></div>
            <div class="stat-item"><div class="stat-number"><?php echo number_format($statusCounts['cancelled'] ?? 0); ?></div><div class="stat-label">Cancelled</div></div>
            <div class="stat-item"><div class="stat-number"><?php echo number_format(array_sum($statusCounts)); ?></div><div class="stat-label">Total</div></div>
          </div>
          <div class="table-responsive">
            <table class="table">
              <thead><tr><th>Booking ID</th><th>Customer</th><th>Mountain</th><th>Date</th><th>Amount</th><th>Status</th></tr></thead>
              <tbody>
                <?php if (empty($recent)): ?>
                  <tr><td colspan="6" class="text-center text-muted">No bookings found</td></tr>
                <?php else: foreach ($recent as $row): $st = strtolower($row['status']); ?>
                  <tr>
                    <td>#<?php echo (int)$row['bookingID']; ?></td>
                    <td><?php echo htmlspecialchars($row['customer'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['mountain'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['dt'] ?? ''); ?></td>
                    <td>RM <?php echo number_format((float)$row['price'], 2); ?></td>
                    <td><span class="status-badge status-<?php echo $st; ?>"><?php echo ucfirst($st); ?></span></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Guider Performance Report -->
        <div class="report-card" id="guiderReport">
          <div class="report-header">
            <h2 class="report-title">Guider Performance Report</h2>
            <div class="d-flex align-items-center gap-3">
              <div class="report-icon"><i class="bi bi-person-badge"></i></div>
              <button class="export-btn" onclick="exportReport('guiderReport')"><i class="bi bi-download me-1"></i> Export</button>
            </div>
          </div>
          <div class="stats-grid">
            <div class="stat-item"><div class="stat-number"><?php echo number_format(count($topGuiders)); ?></div><div class="stat-label">Top Guiders Shown</div></div>
            <div class="stat-item"><div class="stat-number"><?php echo number_format(array_sum($gValues), 2); ?></div><div class="stat-label">Revenue (Top 5)</div></div>
            <div class="stat-item"><div class="stat-number"><?php echo number_format(array_sum(array_map(fn($x)=>$x['tours'],$topGuiders))); ?></div><div class="stat-label">Tours (Top 5)</div></div>
            <div class="stat-item"><div class="stat-number"><?php $r=$conn->query("SELECT COUNT(*) c FROM guider"); echo number_format((int)val($r->fetch_assoc(),'c',0)); $r->close(); ?></div><div class="stat-label">Total Guiders</div></div>
          </div>
          <div class="row g-3">
            <div class="col-12 col-lg-7">
              <div class="chart-container" style="height:320px;">
                <canvas id="performanceBookingsChart"></canvas>
              </div>
            </div>
            <div class="col-12 col-lg-5">
              <div class="chart-container" style="height:320px;">
                <canvas id="performanceRatingChart"></canvas>
              </div>
            </div>
          </div>
        </div>

        <!-- Mountain Popularity -->
        <div class="report-card" id="mountainReport">
          <div class="report-header">
            <h2 class="report-title">Mountain Popularity</h2>
            <div class="d-flex align-items-center gap-3">
              <div class="report-icon"><i class="bi bi-bar-chart"></i></div>
              <button class="export-btn" onclick="exportReport('mountainReport')"><i class="bi bi-download me-1"></i> Export</button>
            </div>
          </div>
          <div class="chart-container" style="height:360px;">
            <canvas id="mountainPopularityChart"></canvas>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
    // Export a single report-card by ID to a clean print view
    function exportReport(cardId) {
      try {
        const card = document.getElementById(cardId);
        if (!card) return;

        // Clone the card to avoid mutating original
        const clone = card.cloneNode(true);

        // Convert canvases inside the clone into images using original canvas data
        const canvases = card.querySelectorAll('canvas');
        const cloneCanvases = clone.querySelectorAll('canvas');
        cloneCanvases.forEach((c, idx) => {
          const orig = canvases[idx];
          if (!orig) return;
          const img = document.createElement('img');
          try { img.src = orig.toDataURL('image/png'); } catch (e) {}
          img.style.maxWidth = '100%';
          img.style.display = 'block';
          img.style.margin = '10px 0';
          c.replaceWith(img);
        });

        // Build print document
        const win = window.open('', '_blank');
        if (!win) return;
        const styles = `
          <style>
            @page { size: A4; margin: 18mm; }
            body { font-family: Montserrat, Arial, sans-serif; color: #111827; }
            .report { width: 100%; }
            .header { text-align: center; margin-bottom: 16px; }
            .title { font-size: 22px; font-weight: 800; color: #571785; margin: 0; }
            .subtitle { font-size: 12px; color: #6b7280; margin: 4px 0 0 0; }
            .content { background:#fff; border:1px solid #eee; border-radius:12px; padding:18px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #e5e7eb; padding: 8px 10px; font-size: 12px; }
            th { background: #571785; color: #fff; text-align: left; }
            .stats-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 12px; }
            .stat-item { border:1px solid #e5e7eb; border-radius:10px; padding:12px; }
            .stat-number { font-size: 18px; color:#571785; font-weight:700; margin-bottom:4px; }
            .stat-label { font-size: 12px; color:#6b7280; font-weight:600; }
          </style>
        `;
        const title = clone.querySelector('.report-title')?.textContent || 'Report';
        win.document.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>${title}</title>${styles}</head><body>`);
        win.document.write(`<div class="report"><div class="header"><h1 class="title">${title}</h1><p class="subtitle">Generated ${new Date().toLocaleString()}</p></div>`);
        win.document.write(`<div class="content">${clone.innerHTML}</div></div>`);
        win.document.write('</body></html>');
        win.document.close();
        // Give the browser a tick to render images
        setTimeout(() => { win.focus(); win.print(); win.close(); }, 300);
      } catch (e) {
        console.error('Export failed', e);
        window.print();
      }
    }
    // Financial Chart (Revenue Trend) - same data, improved styling
    const finLabels = <?php echo json_encode($labels); ?>;
    const finData = <?php echo json_encode($revenues); ?>;
    const finCtx = document.getElementById('financialChart').getContext('2d');
    const finGradient = finCtx.createLinearGradient(0, 0, 0, 300);
    finGradient.addColorStop(0, 'rgba(87, 23, 133, 0.25)');
    finGradient.addColorStop(1, 'rgba(87, 23, 133, 0.02)');
    new Chart(finCtx, {
      type: 'line',
      data: {
        labels: finLabels,
        datasets: [{
          label: 'Revenue (RM)',
          data: finData,
          borderColor: '#571785',
          backgroundColor: finGradient,
          borderWidth: 3,
          pointRadius: 3,
          pointHoverRadius: 5,
          pointBackgroundColor: '#571785',
          tension: 0.35,
          fill: true
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function(ctx) {
                const v = ctx.parsed.y || 0;
                return 'RM ' + Number(v).toLocaleString(undefined, { minimumFractionDigits: 0 });
              }
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              callback: function(value) { return 'RM ' + Number(value).toLocaleString(); }
            },
            grid: { color: 'rgba(0,0,0,0.06)' }
          },
          x: { grid: { display: false } }
        }
      }
    });

    // Guider Performance Charts
    const gpNames = <?php echo json_encode($gpNames); ?>;
    const gpBookings = <?php echo json_encode($gpBookings); ?>;
    const gpRatings = <?php echo json_encode($gpRatings); ?>;

    // 1) Total Bookings per Guider (Bar)
    new Chart(document.getElementById('performanceBookingsChart'), {
      type: 'bar',
      data: {
        labels: gpNames,
        datasets: [{
          label: 'Total Bookings',
          data: gpBookings,
          backgroundColor: '#571785',
          borderRadius: 6
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(0,0,0,0.06)' } },
          x: { ticks: { autoSkip: false, maxRotation: 45, minRotation: 0 } }
        }
      }
    });

    // 2) Average Rating per Guider (Line)
    const prCtx = document.getElementById('performanceRatingChart').getContext('2d');
    const prGradient = prCtx.createLinearGradient(0, 0, 0, 300);
    prGradient.addColorStop(0, 'rgba(40,167,69,0.2)');
    prGradient.addColorStop(1, 'rgba(40,167,69,0.02)');
    new Chart(prCtx, {
      type: 'line',
      data: {
        labels: gpNames,
        datasets: [{
          label: 'Avg Rating',
          data: gpRatings,
          borderColor: '#28a745',
          backgroundColor: prGradient,
          borderWidth: 3,
          pointRadius: 3,
          pointBackgroundColor: '#28a745',
          tension: 0.35,
          fill: true
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, min: 0, max: 5, grid: { color: 'rgba(0,0,0,0.06)' } },
          x: { ticks: { autoSkip: false, maxRotation: 45, minRotation: 0 } }
        }
      }
    });

    // Mountain Popularity (Top 10 by paid bookings) - Pie chart
    const mpLabels = <?php echo json_encode($mpLabels); ?>;
    const mpValues = <?php echo json_encode($mpValues); ?>;
    new Chart(document.getElementById('mountainPopularityChart'), {
      type: 'pie',
      data: {
        labels: mpLabels,
        datasets: [{
          data: mpValues,
          backgroundColor: [
            '#571785', '#6f42c1', '#17a2b8', '#28a745', '#ffc107',
            '#fd7e14', '#dc3545', '#20c997', '#0dcaf0', '#6610f2'
          ],
          borderWidth: 0
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom' },
          tooltip: {
            callbacks: {
              label: function(ctx) {
                const total = ctx.dataset.data.reduce((a,b)=>a+b,0);
                const v = ctx.parsed;
                const pct = total ? ((v/total)*100).toFixed(1) : 0;
                return `${ctx.label}: ${v} (${pct}%)`;
              }
            }
          }
        }
      }
    });

    // Sidebar controls
    function toggleMobileMenu(){ const s=document.querySelector('.sidebar'); const o=document.querySelector('.mobile-overlay'); const b=document.querySelector('.mobile-menu-btn'); if(!s||!o)return; const on=s.classList.toggle('mobile-open'); o.classList.toggle('show',on); const i=b&&b.querySelector('i'); if(i) i.className=on?'bi bi-x':'bi bi-list'; }
    function closeMobileMenu(){ const s=document.querySelector('.sidebar'); const o=document.querySelector('.mobile-overlay'); const b=document.querySelector('.mobile-menu-btn'); if(!s||!o)return; s.classList.remove('mobile-open'); o.classList.remove('show'); const i=b&&b.querySelector('i'); if(i) i.className='bi bi-list'; }
    document.addEventListener('click', function(e){ const s=document.querySelector('.sidebar'); const b=document.querySelector('.mobile-menu-btn'); if(window.innerWidth<=768){ if(!s.contains(e.target) && !b.contains(e.target)) closeMobileMenu(); } });
    window.addEventListener('resize', function(){ if(window.innerWidth>768) closeMobileMenu(); });
    document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeMobileMenu(); });
    document.querySelectorAll('.sidebar .menu a').forEach(a=>a.addEventListener('click', closeMobileMenu));
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
