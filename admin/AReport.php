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

// Booking status counts - with filter support
$statusCounts = [ 'completed' => 0, 'paid' => 0, 'accepted' => 0, 'pending' => 0, 'cancelled' => 0 ];
$filterYear = isset($_GET['year']) ? (int)$_GET['year'] : 0;
$filterMonth = isset($_GET['month']) ? (int)$_GET['month'] : 0;

if ($bkDateCol) {
  $wheres = [];
  if ($filterYear > 0) $wheres[] = "YEAR($bkDateCol) = " . $filterYear;
  if ($filterMonth > 0) $wheres[] = "MONTH($bkDateCol) = " . $filterMonth;
  $whereSql = count($wheres) ? ("WHERE " . implode(' AND ', $wheres)) : '';
  $q = $conn->query("SELECT status, COUNT(*) c FROM booking $whereSql GROUP BY status");
} else {
$q = $conn->query("SELECT status, COUNT(*) c FROM booking GROUP BY status");
}
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

// Recent bookings table with search functionality
$recent = [];
$showAllCurrent = isset($_GET['show']) && $_GET['show'] === 'all_current';
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$dateYear = isset($_GET['date_year']) ? (int)$_GET['date_year'] : 0;
$dateMonth = isset($_GET['date_month']) ? (int)$_GET['date_month'] : 0;
$dateDay = isset($_GET['date_day']) ? (int)$_GET['date_day'] : 0;

if ($bkDateCol) {
  $wheres = [];
  
  // Text search functionality
  if (!empty($searchQuery)) {
    $searchEscaped = $conn->real_escape_string($searchQuery);
    $wheres[] = "(
      b.bookingID LIKE '%{$searchEscaped}%' OR
      h.username LIKE '%{$searchEscaped}%' OR
      m.name LIKE '%{$searchEscaped}%' OR
      g.username LIKE '%{$searchEscaped}%'
    )";
  }
  
  // Date filter functionality
  if ($dateYear > 0) {
    $wheres[] = "YEAR(b.$bkDateCol) = " . $dateYear;
    
    if ($dateMonth > 0) {
      $wheres[] = "MONTH(b.$bkDateCol) = " . $dateMonth;
      
      if ($dateDay > 0) {
        $wheres[] = "DAY(b.$bkDateCol) = " . $dateDay;
      }
    }
  }
  
  $whereSql = count($wheres) ? ("WHERE " . implode(' AND ', $wheres)) : '';
  
  // "load all" removes LIMIT but keeps the search/filters
  $limitSql = $showAllCurrent ? '' : 'LIMIT 20';
  
  $sql = "SELECT b.bookingID, 
                 COALESCE(h.username, CONCAT('Hiker #', b.hikerID)) AS customer,
                 COALESCE(g.username, CONCAT('Guider #', b.guiderID)) AS guider,
                 m.name AS mountain, 
                 DATE(b.$bkDateCol) AS dt, 
                 b.price, 
                 b.status
          FROM booking b
          LEFT JOIN hiker h ON h.hikerID = b.hikerID
          LEFT JOIN guider g ON g.guiderID = b.guiderID
          LEFT JOIN mountain m ON m.mountainID = b.mountainID
          $whereSql
          ORDER BY b.$bkDateCol DESC
          $limitSql";
} else {
  $limitSql = $showAllCurrent ? '' : 'LIMIT 20';
  
  $wheres = [];
  if (!empty($searchQuery)) {
    $searchEscaped = $conn->real_escape_string($searchQuery);
    $wheres[] = "(
      b.bookingID LIKE '%{$searchEscaped}%' OR
      h.username LIKE '%{$searchEscaped}%' OR
      m.name LIKE '%{$searchEscaped}%' OR
      g.username LIKE '%{$searchEscaped}%'
    )";
  }
  $whereSql = count($wheres) ? ("WHERE " . implode(' AND ', $wheres)) : '';
  
  $sql = "SELECT b.bookingID, 
                 COALESCE(h.username, CONCAT('Hiker #', b.hikerID)) AS customer,
                 COALESCE(g.username, CONCAT('Guider #', b.guiderID)) AS guider,
                 m.name AS mountain, 
                 NULL AS dt, 
                 b.price, 
                 b.status
          FROM booking b
          LEFT JOIN hiker h ON h.hikerID = b.hikerID
          LEFT JOIN guider g ON g.guiderID = b.guiderID
          LEFT JOIN mountain m ON m.mountainID = b.mountainID
          $whereSql
          ORDER BY b.bookingID DESC
          $limitSql";
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

// Guider KPIs
$kpi_top_guider_name = '-';
$kpi_top_guider_bookings = 0;
$kpi_best_rating_guider_name = '-';
$kpi_best_rating_value = 0.0;
$kpi_highest_income_guider_name = '-';
$kpi_highest_income_value = 0.0;
$kpi_total_guiders = 0;
if ($r = $conn->query("SELECT COUNT(*) c FROM guider")) { $kpi_total_guiders = (int)val($r->fetch_assoc(),'c',0); $r->close(); }
if ($r = $conn->query("SELECT g.username AS name, COUNT(b.bookingID) cnt FROM guider g LEFT JOIN booking b ON b.guiderID=g.guiderID GROUP BY g.guiderID, g.username ORDER BY cnt DESC LIMIT 1")) { $row=$r->fetch_assoc(); if ($row) { $kpi_top_guider_name=$row['name']; $kpi_top_guider_bookings=(int)$row['cnt']; } $r->close(); }
if ($r = $conn->query("SELECT g.username AS name, COALESCE(AVG(rv.rating),0) avgR FROM guider g LEFT JOIN review rv ON rv.guiderID=g.guiderID GROUP BY g.guiderID, g.username ORDER BY avgR DESC LIMIT 1")) { $row=$r->fetch_assoc(); if ($row) { $kpi_best_rating_guider_name=$row['name']; $kpi_best_rating_value=round((float)$row['avgR'],1); } $r->close(); }
if ($r = $conn->query("SELECT g.username AS name, COALESCE(SUM(CASE WHEN b.status IN ('paid','completed') THEN b.price ELSE 0 END),0) income FROM guider g LEFT JOIN booking b ON b.guiderID=g.guiderID GROUP BY g.guiderID, g.username ORDER BY income DESC LIMIT 1")) { $row=$r->fetch_assoc(); if ($row) { $kpi_highest_income_guider_name=$row['name']; $kpi_highest_income_value=(float)$row['income']; } $r->close(); }

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

// Active users (last 30 days)
$activeHikers30d = 0; $activeGuiders30d = isset($activeGuiders30d) ? $activeGuiders30d : 0;
if ($bkDateCol) {
  if ($r = $conn->query("SELECT COUNT(DISTINCT hikerID) c FROM booking WHERE $bkDateCol >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")) {
    $activeHikers30d = (int)val($r->fetch_assoc(),'c',0); $r->close();
  }
  if ($r = $conn->query("SELECT COUNT(DISTINCT guiderID) c FROM booking WHERE $bkDateCol >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")) {
    $activeGuiders30d = (int)val($r->fetch_assoc(),'c',0); $r->close();
  }
}

// Financial KPIs
$kpi_total_income = 0.0;
$kpi_last_month_income = 0.0;
$kpi_bookings_current_month = 0;
if ($bkDateCol) {
  // Total income across all time (paid + completed)
  if ($r = $conn->query("SELECT COALESCE(SUM(price),0) s FROM booking WHERE status IN ('paid','completed')")) {
    $kpi_total_income = (float)val($r->fetch_assoc(),'s',0); $r->close();
  }
  // Last month income (paid + completed)
  $sql = "SELECT COALESCE(SUM(price),0) s FROM booking 
          WHERE status IN ('paid','completed') 
            AND DATE_FORMAT($bkDateCol,'%Y-%m') = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH),'%Y-%m')";
  if ($r = $conn->query($sql)) { $kpi_last_month_income = (float)val($r->fetch_assoc(),'s',0); $r->close(); }
  // Total bookings current month (all statuses)
  $sql = "SELECT COUNT(*) c FROM booking 
          WHERE DATE_FORMAT($bkDateCol,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')";
  if ($r = $conn->query($sql)) { $kpi_bookings_current_month = (int)val($r->fetch_assoc(),'c',0); $r->close(); }
}

// financial report - Last 6 months data
$labels = []; $revenues = [];
if ($bkDateCol) {
  $sql = "SELECT g.username AS guider, COALESCE(SUM(b.price),0) AS income
          FROM booking b
          JOIN guider g ON g.guiderID = b.guiderID
          WHERE b.status IN ('paid','completed')
            AND b.$bkDateCol >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
          GROUP BY g.guiderID, g.username
          ORDER BY income DESC
          LIMIT 10";
  if ($r = $conn->query($sql)) {
    while ($row = $r->fetch_assoc()) { $labels[] = $row['guider']; $revenues[] = (float)$row['income']; }
    $r->close();
  }
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
        <a class="navbar-brand d-flex align-items-center" href="../index.php">
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
        <a href="ASentimentReport.php"><i class="fas fa-chart-line"></i> Sentiment Analysis</a>
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
            <div class="stat-item"><div class="stat-number">RM <?php echo number_format($kpi_total_income,2); ?></div><div class="stat-label">Total Income</div></div>
            <div class="stat-item"><div class="stat-number">RM <?php echo number_format($kpi_last_month_income,2); ?></div><div class="stat-label">Last Month Income</div></div>
            <div class="stat-item"><div class="stat-number"><?php echo number_format($kpi_bookings_current_month); ?></div><div class="stat-label">Total Bookings (This Month)</div></div>
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
          <form class="mb-4" method="GET" action="AReport.php" style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); padding: 1.5rem; border-radius: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.06);">
            <!-- Search Bar -->
            <div class="row g-3 mb-3">
              <div class="col-12">
                <label class="form-label fw-semibold" style="color: #571785;">
                  <i class="bi bi-search me-2"></i>Search Bookings
                </label>
                <div class="input-group input-group-lg">
                  <span class="input-group-text bg-white border-end-0">
                    <i class="bi bi-search text-muted"></i>
                  </span>
                  <input type="text" 
                         name="search" 
                         class="form-control border-start-0 ps-0" 
                         placeholder="Search by Booking ID, Customer, Mountain, or Guider name..." 
                         value="<?php echo htmlspecialchars($searchQuery); ?>"
                         style="font-size: 1rem;">
                </div>
              </div>
            </div>
            
            <!-- Date Filter -->
            <div class="row g-3 mb-3">
              <div class="col-12">
                <label class="form-label fw-semibold" style="color: #571785;">
                  <i class="bi bi-calendar3 me-2"></i>Filter by Date
                </label>
              </div>
              <div class="col-12 col-md-4">
                <select name="date_year" class="form-select" id="dateYear" onchange="updateDateFilter()">
                  <option value="">Select Year</option>
                  <?php 
                  $currentYear = (int)date('Y');
                  for ($y = $currentYear; $y >= $currentYear - 5; $y--): 
                    $selected = ($dateYear === $y) ? 'selected' : '';
                  ?>
                    <option value="<?php echo $y; ?>" <?php echo $selected; ?>><?php echo $y; ?></option>
                <?php endfor; ?>
              </select>
            </div>
              <div class="col-12 col-md-4">
                <select name="date_month" class="form-select" id="dateMonth" onchange="updateDateFilter()" <?php echo ($dateYear == 0) ? 'disabled' : ''; ?>>
                  <option value="">Select Month</option>
                  <?php 
                  $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                  foreach ($months as $idx => $month): 
                    $monthNum = $idx + 1;
                    $selected = ($dateMonth === $monthNum) ? 'selected' : '';
                  ?>
                    <option value="<?php echo $monthNum; ?>" <?php echo $selected; ?>><?php echo $month; ?></option>
                  <?php endforeach; ?>
              </select>
            </div>
              <div class="col-12 col-md-4">
                <select name="date_day" class="form-select" id="dateDay" data-selected="<?php echo $dateDay; ?>" <?php echo ($dateMonth == 0) ? 'disabled' : ''; ?>>
                  <option value="">Select Day</option>
                  <!-- Days will be populated by JavaScript based on month/year -->
                </select>
              </div>
            </div>
            
            <!-- Action Buttons -->
            <div class="row g-2">
              <div class="col-12 d-flex flex-wrap gap-2 justify-content-between align-items-center">
                <div class="d-flex gap-2">
                  <button type="submit" class="btn btn-primary px-4">
                    <i class="bi bi-funnel-fill me-2"></i>Apply Filters
                  </button>
                  <?php if (!empty($searchQuery) || $dateYear > 0): ?>
                    <a href="AReport.php" class="btn btn-outline-secondary px-4">
                      <i class="bi bi-arrow-counterclockwise me-2"></i>Clear All
                    </a>
                  <?php endif; ?>
                </div>
                <div class="d-flex gap-2">
              <?php if ($showAllCurrent): ?>
                <a href="AReport.php<?php
                      $params = [];
                      if (!empty($searchQuery)) $params[] = 'search='.urlencode($searchQuery);
                      if ($dateYear > 0) $params[] = 'date_year='.$dateYear;
                      if ($dateMonth > 0) $params[] = 'date_month='.$dateMonth;
                      if ($dateDay > 0) $params[] = 'date_day='.$dateDay;
                      echo $params ? '?'.implode('&', $params) : '';
                    ?>" class="btn btn-warning">
                      <i class="bi bi-list me-2"></i>Show 20
                    </a>
              <?php else: ?>
                <a href="AReport.php?show=all_current<?php
                      $params = [];
                      if (!empty($searchQuery)) $params[] = 'search='.urlencode($searchQuery);
                      if ($dateYear > 0) $params[] = 'date_year='.$dateYear;
                      if ($dateMonth > 0) $params[] = 'date_month='.$dateMonth;
                      if ($dateDay > 0) $params[] = 'date_day='.$dateDay;
                      echo $params ? '&'.implode('&', $params) : '';
                    ?>" class="btn btn-success">
                      <i class="bi bi-list-ul me-2"></i>Load All
                    </a>
              <?php endif; ?>
            </div>
              </div>
            </div>
            
            <!-- Active Filters Display -->
            <?php if (!empty($searchQuery) || $dateYear > 0): ?>
              <div class="mt-3 pt-3 border-top">
                <small class="text-muted d-block mb-2"><strong>Active Filters:</strong></small>
                <div class="d-flex flex-wrap gap-2">
                  <?php if (!empty($searchQuery)): ?>
                    <span class="badge bg-primary" style="font-size: 0.85rem; padding: 0.5rem 0.75rem;">
                      <i class="bi bi-search me-1"></i>Search: "<?php echo htmlspecialchars($searchQuery); ?>"
                    </span>
                  <?php endif; ?>
                  <?php if ($dateYear > 0): ?>
                    <span class="badge bg-info" style="font-size: 0.85rem; padding: 0.5rem 0.75rem;">
                      <i class="bi bi-calendar3 me-1"></i>
                      <?php 
                        echo $dateYear;
                        if ($dateMonth > 0) echo ' - ' . $months[$dateMonth - 1];
                        if ($dateDay > 0) echo ' - ' . $dateDay;
                      ?>
                    </span>
                  <?php endif; ?>
                </div>
              </div>
            <?php endif; ?>
          </form>
          
          <script>
          // Initialize on page load
          document.addEventListener('DOMContentLoaded', function() {
            const daySelect = document.getElementById('dateDay');
            const savedDay = daySelect.getAttribute('data-selected');
            updateDateFilter(savedDay);
          });
          
          function updateDateFilter(preserveDay) {
            const year = document.getElementById('dateYear').value;
            const month = document.getElementById('dateMonth');
            const day = document.getElementById('dateDay');
            const currentDayValue = preserveDay || day.value; // Save current selection
            
            // Enable/disable month based on year
            if (year) {
              month.disabled = false;
            } else {
              month.disabled = true;
              month.value = '';
              day.disabled = true;
              day.value = '';
              populateDays(0, 0, '');
              return;
            }
            
            // Enable/disable day based on month
            if (month.value) {
              day.disabled = false;
              populateDays(parseInt(year), parseInt(month.value), currentDayValue);
            } else {
              day.disabled = true;
              day.value = '';
              populateDays(0, 0, '');
            }
          }
          
          function populateDays(year, month, selectedDay) {
            const daySelect = document.getElementById('dateDay');
            const currentValue = selectedDay || daySelect.value || daySelect.getAttribute('data-selected');
            
            // Clear existing options except the first one
            daySelect.innerHTML = '<option value="">Select Day</option>';
            
            if (month === 0) return;
            
            // Calculate days in month
            let daysInMonth;
            
            if (month === 2) {
              // February - check for leap year
              const isLeapYear = (year % 4 === 0 && year % 100 !== 0) || (year % 400 === 0);
              daysInMonth = isLeapYear ? 29 : 28;
            } else if ([4, 6, 9, 11].includes(month)) {
              // April, June, September, November
              daysInMonth = 30;
            } else {
              // January, March, May, July, August, October, December
              daysInMonth = 31;
            }
            
            // Populate days
            for (let d = 1; d <= daysInMonth; d++) {
              const option = document.createElement('option');
              option.value = d;
              option.textContent = d;
              
              // Restore selection if valid
              if (currentValue && parseInt(currentValue) === d) {
                option.selected = true;
              }
              
              daySelect.appendChild(option);
            }
            
            // If previously selected day is now invalid, clear it
            if (currentValue && parseInt(currentValue) > daysInMonth) {
              daySelect.value = '';
            }
          }
          </script>
          <div class="stats-grid">
            <div class="stat-item"><div class="stat-number"><?php echo number_format($statusCounts['paid'] + $statusCounts['completed'] ?? 0); ?></div><div class="stat-label">Completed/Paid</div></div>
            <div class="stat-item"><div class="stat-number"><?php echo number_format($statusCounts['pending'] ?? 0); ?></div><div class="stat-label">Pending</div></div>
            <div class="stat-item"><div class="stat-number"><?php echo number_format($statusCounts['cancelled'] ?? 0); ?></div><div class="stat-label">Cancelled</div></div>
            <div class="stat-item"><div class="stat-number"><?php echo number_format(array_sum($statusCounts)); ?></div><div class="stat-label">Total</div></div>
          </div>
          <div class="table-responsive">
            <table class="table">
              <thead><tr><th>Booking ID</th><th>Customer</th><th>Guider</th><th>Mountain</th><th>Date</th><th>Amount</th><th>Status</th></tr></thead>
              <tbody>
                <?php if (empty($recent)): ?>
                  <tr><td colspan="7" class="text-center text-muted">No bookings found</td></tr>
                <?php else: foreach ($recent as $row): $st = strtolower($row['status']); ?>
                  <tr>
                    <td>#<?php echo (int)$row['bookingID']; ?></td>
                    <td><?php echo htmlspecialchars($row['customer'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['guider'] ?? ''); ?></td>
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
            <div class="stat-item"><div class="stat-number"><?php echo htmlspecialchars($kpi_top_guider_name); ?></div><div class="stat-label">Top Guider (Most Bookings: <?php echo number_format($kpi_top_guider_bookings); ?>)</div></div>
            <div class="stat-item"><div class="stat-number"><?php echo htmlspecialchars($kpi_best_rating_guider_name); ?></div><div class="stat-label">Best Rating: <?php echo number_format($kpi_best_rating_value,1); ?></div></div>
            <div class="stat-item"><div class="stat-number"><?php echo htmlspecialchars($kpi_highest_income_guider_name); ?></div><div class="stat-label">Highest Income: RM <?php echo number_format($kpi_highest_income_value,2); ?></div></div>
            <div class="stat-item"><div class="stat-number"><?php echo number_format($kpi_total_guiders); ?></div><div class="stat-label">Total Guiders</div></div>
          </div>
          <div class="row g-3">
            <div class="col-12">
              <div class="chart-container" style="height:320px;">
                <canvas id="performanceBookingsChart"></canvas>
              </div>
            </div>
            <div class="col-12">
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
              <div class="btn-group" role="group">
                <button type="button" class="btn btn-sm btn-outline-primary" id="mountainChartColor" onclick="toggleMountainChartColor(true)" title="Export with Color">
                  <i class="bi bi-palette"></i> Color
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary active" id="mountainChartBW" onclick="toggleMountainChartColor(false)" title="Export Black & White">
                  <i class="bi bi-circle"></i> B&W
                </button>
              </div>
              <button class="export-btn" onclick="exportReport('mountainReport')"><i class="bi bi-download me-1"></i> Export</button>
            </div>
          </div>
          <div class="chart-container" style="height:360px;">
            <canvas id="mountainPopularityChart"></canvas>
          </div>
          <div class="mountain-stats-list" style="margin-top: 20px; padding: 20px; background: #f8f9fa; border-radius: 8px;">
            <h5 style="margin-bottom: 15px; color: #571785; font-weight: 600;">Mountain Statistics</h5>
            <div id="mountainPercentagesList"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
    // Track mountain chart color preference
    let mountainChartUseColor = false;
    
    function toggleMountainChartColor(useColor) {
      mountainChartUseColor = useColor;
      const colorBtn = document.getElementById('mountainChartColor');
      const bwBtn = document.getElementById('mountainChartBW');
      
      if (useColor) {
        colorBtn.classList.add('active', 'btn-primary');
        colorBtn.classList.remove('btn-outline-primary');
        bwBtn.classList.remove('active', 'btn-secondary');
        bwBtn.classList.add('btn-outline-secondary');
      } else {
        bwBtn.classList.add('active', 'btn-secondary');
        bwBtn.classList.remove('btn-outline-secondary');
        colorBtn.classList.remove('active', 'btn-primary');
        colorBtn.classList.add('btn-outline-primary');
      }
    }
    
    // Export a single report-card by ID to a clean print view
    function exportReport(cardId) {
      try {
        const card = document.getElementById(cardId);
        if (!card) return;

        // Get report data based on type
        let reportHTML = '';
        const now = new Date();
        const dateStr = now.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
        const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
        
        switch(cardId) {
          case 'financialReport':
            reportHTML = generateFinancialReport(card, dateStr, timeStr);
            break;
          case 'bookingReport':
            reportHTML = generateBookingReport(card, dateStr, timeStr);
            break;
          case 'guiderReport':
            reportHTML = generateGuiderReport(card, dateStr, timeStr);
            break;
          case 'mountainReport':
            reportHTML = generateMountainReport(card, dateStr, timeStr);
            break;
          default:
            reportHTML = generateGenericReport(card, dateStr, timeStr);
        }

        // Open print window
        const win = window.open('', '_blank');
        if (!win) return;
        
        win.document.write(reportHTML);
        win.document.close();
        
        // Give browser time to render
        setTimeout(() => { win.focus(); win.print(); }, 800);
      } catch (e) {
        console.error('Export failed', e);
        alert('Failed to generate report. Please try again.');
      }
    }

    function generateFinancialReport(card, dateStr, timeStr) {
      // Extract stats and create table
      const stats = card.querySelectorAll('.stat-item');
      let statsTable = '<table><thead><tr><th>Metric</th><th>Value</th></tr></thead><tbody>';
      stats.forEach(stat => {
        const label = stat.querySelector('.stat-label')?.textContent || '';
        const number = stat.querySelector('.stat-number')?.textContent || '';
        statsTable += `<tr><td>${label}</td><td>${number}</td></tr>`;
      });
      statsTable += '</tbody></table>';
      
      // Get chart image (convert to grayscale)
      const canvas = card.querySelector('canvas');
      let chartHTML = '';
      if (canvas) {
        const chartImg = canvas.toDataURL('image/png');
        chartHTML = `<div class="chart-section"><h3 class="section-title">Revenue Analysis Chart</h3><img src="${chartImg}" class="bw-chart" style="max-width:100%; height:auto;"></div>`;
      }
      
      return `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Financial Report</title>${getFormalPrintStyles()}</head><body>
${getFormalReportHeader('FINANCIAL REPORT', dateStr, timeStr)}
<div class="section"><h3 class="section-title">Financial Overview</h3>${statsTable}</div>
${chartHTML}
${getFormalReportFooter()}</body></html>`;
    }

    function generateBookingReport(card, dateStr, timeStr) {
      // Extract stats and create table
      const stats = card.querySelectorAll('.stat-item');
      let statsTable = '<table><thead><tr><th>Status</th><th>Count</th></tr></thead><tbody>';
      stats.forEach(stat => {
        const label = stat.querySelector('.stat-label')?.textContent || '';
        const number = stat.querySelector('.stat-number')?.textContent || '';
        statsTable += `<tr><td>${label}</td><td>${number}</td></tr>`;
      });
      statsTable += '</tbody></table>';
      
      // Extract booking table (remove all interface elements)
      const table = card.querySelector('table');
      let tableHTML = '';
      if (table) {
        const clone = table.cloneNode(true);
        // Remove all interface elements
        clone.querySelectorAll('button, form, input, select, .btn, .badge').forEach(el => el.remove());
        // Clean up status badges - just show text
        clone.querySelectorAll('.status-badge').forEach(badge => {
          badge.outerHTML = badge.textContent.trim();
        });
        tableHTML = clone.outerHTML;
      }
      
      return `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Booking Report</title>${getFormalPrintStyles()}</head><body>
${getFormalReportHeader('BOOKING REPORT', dateStr, timeStr)}
<div class="section"><h3 class="section-title">Booking Statistics</h3>${statsTable}</div>
${tableHTML ? `<div class="section"><h3 class="section-title">Booking Details</h3>${tableHTML}</div>` : ''}
${getFormalReportFooter()}</body></html>`;
    }

    function generateGuiderReport(card, dateStr, timeStr) {
      // Extract stats and create table
      const stats = card.querySelectorAll('.stat-item');
      let statsTable = '<table><thead><tr><th>Metric</th><th>Value</th></tr></thead><tbody>';
      stats.forEach(stat => {
        const label = stat.querySelector('.stat-label')?.textContent || '';
        const number = stat.querySelector('.stat-number')?.textContent || '';
        statsTable += `<tr><td>${label}</td><td>${number}</td></tr>`;
      });
      statsTable += '</tbody></table>';
      
      // Get charts
      const canvases = card.querySelectorAll('canvas');
      let chartsHTML = '';
      if (canvases.length > 0) {
        chartsHTML = '<div class="section"><h3 class="section-title">Performance Charts</h3>';
        canvases.forEach((canvas, idx) => {
          const imgData = canvas.toDataURL('image/png');
          const titles = ['Total Bookings per Guider', 'Average Rating per Guider'];
          chartsHTML += `<div class="chart-section"><h4 class="chart-title">${titles[idx] || 'Chart'}</h4><img src="${imgData}" class="bw-chart" style="max-width:100%; height:auto;"></div>`;
        });
        chartsHTML += '</div>';
      }
      
      return `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Guider Performance Report</title>${getFormalPrintStyles()}</head><body>
${getFormalReportHeader('GUIDER PERFORMANCE REPORT', dateStr, timeStr)}
<div class="section"><h3 class="section-title">Performance Metrics</h3>${statsTable}</div>
${chartsHTML}
${getFormalReportFooter()}</body></html>`;
    }

    function generateMountainReport(card, dateStr, timeStr) {
      // Get chart
      const canvas = card.querySelector('#mountainPopularityChart');
      let chartHTML = '';
      if (canvas) {
        const chartImg = canvas.toDataURL('image/png');
        const chartClass = mountainChartUseColor ? '' : 'bw-chart';
        chartHTML = `<div class="section"><h3 class="section-title">Popularity Distribution</h3><div class="chart-section"><img src="${chartImg}" class="${chartClass}" style="max-width:100%; height:auto;"></div></div>`;
      }
      
      // Get mountain data and calculate percentages
      const mpLabels = <?php echo json_encode($mpLabels); ?>;
      const mpValues = <?php echo json_encode($mpValues); ?>;
      const colors = ['#571785', '#6f42c1', '#17a2b8', '#28a745', '#ffc107', '#fd7e14', '#dc3545', '#20c997', '#0dcaf0', '#6610f2'];
      
      const total = mpValues.reduce((a, b) => a + b, 0);
      let percentagesHTML = '';
      
      if (total > 0 && mpLabels.length > 0) {
        percentagesHTML = '<table><thead><tr><th>Mountain</th><th>Bookings</th><th>Percentage</th></tr></thead><tbody>';
        mpLabels.forEach((label, idx) => {
          const value = mpValues[idx] || 0;
          const percentage = ((value / total) * 100).toFixed(1);
          const color = colors[idx % colors.length];
          percentagesHTML += `<tr>
            <td>
              <span class="color-indicator" style="display:inline-block;width:12px;height:12px;background-color:${mountainChartUseColor ? color : '#000000'};border:1px solid #000000;margin-right:8px;vertical-align:middle;"></span>
              ${label}
            </td>
            <td>${value}</td>
            <td><strong>${percentage}%</strong></td>
          </tr>`;
        });
        percentagesHTML += '</tbody></table>';
      }
      
      return `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Mountain Popularity Report</title>${getFormalPrintStyles()}</head><body>
${getFormalReportHeader('MOUNTAIN POPULARITY REPORT', dateStr, timeStr)}
${chartHTML}
${percentagesHTML ? `<div class="section"><h3 class="section-title">Mountain Statistics with Percentages</h3>${percentagesHTML}</div>` : ''}
${getFormalReportFooter()}</body></html>`;
    }

    function getFormalReportHeader(title, dateStr, timeStr) {
      return `<div class="report-header">
<div class="company-info">
<h1 class="company-name">HIKING GUIDANCE SYSTEM</h1>
<p class="company-tagline">Administrative Reports & Analytics</p>
</div>
<div class="report-meta">
<h2 class="report-title">${title}</h2>
<div class="report-date"><span><strong>Date:</strong> ${dateStr}</span><span><strong>Time:</strong> ${timeStr}</span></div>
</div></div><div class="divider"></div>`;
    }

    function getFormalReportFooter() {
      return `<div class="report-footer"><div class="footer-content">
<p><strong>Hiking Guidance System</strong> - Administrative Dashboard</p>
<p>This is a computer-generated report. No signature required.</p>
<p class="confidential">CONFIDENTIAL - For Internal Use Only</p>
</div></div>`;
    }

    function getFormalPrintStyles() {
      return `<style>
@page { size: A4; margin: 20mm; }
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Times New Roman', Times, serif; font-size: 11pt; color: #000000; line-height: 1.5; background: #ffffff; }
.report-header { margin-bottom: 25px; }
.company-info { text-align: center; margin-bottom: 20px; padding: 10px 0; border-bottom: 2px solid #000000; }
.company-name { font-size: 18pt; font-weight: bold; margin-bottom: 5px; color: #000000; }
.company-tagline { font-size: 10pt; color: #000000; }
.report-meta { text-align: center; margin-top: 15px; }
.report-title { font-size: 16pt; font-weight: bold; margin-bottom: 10px; text-transform: uppercase; color: #000000; }
.report-date { display: flex; justify-content: center; gap: 30px; font-size: 10pt; color: #000000; margin-top: 5px; }
.divider { height: 1px; background: #000000; margin: 20px 0; }
.section { margin: 20px 0; page-break-inside: avoid; }
.section-title { font-size: 12pt; font-weight: bold; color: #000000; margin-bottom: 12px; padding-bottom: 5px; border-bottom: 1px solid #000000; }
table { width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 10pt; border: 1px solid #000000; }
thead { background: #ffffff; }
th { padding: 8px 10px; text-align: left; font-weight: bold; font-size: 10pt; border: 1px solid #000000; background: #f0f0f0; color: #000000; }
td { padding: 8px 10px; border: 1px solid #000000; color: #000000; }
tbody tr:nth-child(even) { background: #f9f9f9; }
tbody tr:nth-child(odd) { background: #ffffff; }
.chart-section { margin: 20px 0; page-break-inside: avoid; text-align: center; }
.chart-title { font-size: 11pt; font-weight: bold; color: #000000; margin-bottom: 10px; }
.bw-chart { filter: grayscale(100%); -webkit-filter: grayscale(100%); }
.report-footer { margin-top: 40px; padding-top: 15px; border-top: 1px solid #000000; text-align: center; font-size: 9pt; color: #000000; }
.footer-content p { margin: 3px 0; }
.confidential { font-weight: bold; color: #000000; margin-top: 10px; }
.color-indicator { display: inline-block; width: 12px; height: 12px; border: 1px solid #000000; margin-right: 8px; vertical-align: middle; }
button, form, input, select, .btn, .badge, .export-btn, .report-icon { display: none !important; }
@media print {
  body { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
  .section { page-break-inside: avoid; }
  @page { margin: 20mm; }
}
</style>`;
    }

    // Keep old functions for backward compatibility
    function getReportHeader(title, dateStr, timeStr) {
      return getFormalReportHeader(title, dateStr, timeStr);
    }

    function getReportFooter() {
      return getFormalReportFooter();
    }

    function getPrintStyles() {
      return getFormalPrintStyles();
    }

    function generateGenericReport(card, dateStr, timeStr) {
      const clone = card.cloneNode(true);
      // Remove all interface elements
      clone.querySelectorAll('button, form, input, select, .btn, .badge, .export-btn, .report-icon').forEach(el => el.remove());
      const title = clone.querySelector('.report-title')?.textContent || 'Report';
      return `<!DOCTYPE html><html><head><meta charset="utf-8"><title>${title}</title>${getFormalPrintStyles()}</head><body>${getFormalReportHeader(title.toUpperCase(), dateStr, timeStr)}<div class="section">${clone.innerHTML}</div>${getFormalReportFooter()}</body></html>`;
    }
    // Financial Chart: Current Month Income per Guider (Bar)
    const finLabels = <?php echo json_encode($labels); ?>;
    const finData = <?php echo json_encode($revenues); ?>;
    new Chart(document.getElementById('financialChart'), {
      type: 'bar',
      data: {
        labels: finLabels,
        datasets: [{
          label: 'Income (Last 6 Months) - RM',
          data: finData,
          backgroundColor: '#571785',
          borderRadius: 6
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
                const v = ctx.parsed.x || 0;
                return 'RM ' + Number(v).toLocaleString('en-MY', {minimumFractionDigits: 2, maximumFractionDigits: 2});
              }
            }
          }
        },
        scales: {
          x: {
            beginAtZero: true,
            ticks: { callback: (v) => 'RM ' + Number(v).toLocaleString() },
            grid: { color: 'rgba(0,0,0,0.06)' }
          },
          y: {
            ticks: { autoSkip: false },
            grid: { display: false }
          }
        },
        indexAxis: 'y'
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

    // Populate mountain percentages list with color indicators
    function populateMountainPercentages() {
      const mpLabels = <?php echo json_encode($mpLabels); ?>;
      const mpValues = <?php echo json_encode($mpValues); ?>;
      const colors = ['#571785', '#6f42c1', '#17a2b8', '#28a745', '#ffc107', '#fd7e14', '#dc3545', '#20c997', '#0dcaf0', '#6610f2'];
      const listContainer = document.getElementById('mountainPercentagesList');
      
      if (!listContainer || mpLabels.length === 0) return;
      
      const total = mpValues.reduce((a, b) => a + b, 0);
      if (total === 0) return;
      
      let html = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">';
      mpLabels.forEach((label, idx) => {
        const value = mpValues[idx] || 0;
        const percentage = ((value / total) * 100).toFixed(1);
        const color = colors[idx % colors.length];
        
        html += `<div style="display:flex;align-items:center;padding:12px;background:white;border-radius:8px;border-left:4px solid ${color};box-shadow:0 1px 3px rgba(0,0,0,0.1);">
          <span style="display:inline-block;width:16px;height:16px;background-color:${color};border-radius:4px;margin-right:12px;border:1px solid rgba(0,0,0,0.1);flex-shrink:0;"></span>
          <div style="flex:1;min-width:0;">
            <div style="font-weight:600;color:#333;font-size:13px;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${label}</div>
            <div style="display:flex;align-items:center;gap:8px;">
              <span style="font-size:16px;font-weight:700;color:#571785;">${percentage}%</span>
              <span style="font-size:11px;color:#666;">(${value})</span>
            </div>
          </div>
        </div>`;
      });
      html += '</div>';
      
      listContainer.innerHTML = html;
    }
    
    // Populate on page load
    populateMountainPercentages();

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
