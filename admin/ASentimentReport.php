<?php
/**
 * Sentiment Analysis Overview for Admin
 * 
 * This page shows sentiment analysis for all guiders (admin view)
 */

session_start();

// Increase timeout for sentiment analysis (processing multiple guiders)
set_time_limit(300); // 5 minutes max
ini_set('max_execution_time', 300);

// Redirect to login if not logged in
if (!isset($_SESSION['email'])) {
    header("Location: ALogin.html");
    exit();
}

include("../shared/db_connection.php");
include("../ml_api/config.php");

// Verify the email belongs to an admin
$email = $_SESSION['email'];
$stmt = $conn->prepare("SELECT 1 FROM admin WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: ALogin.html");
    exit();
}

// Check ML API health
$mlApiAvailable = checkMLAPIHealth();

// Fetch all guiders with their review counts
$sql = "SELECT 
            g.guiderID,
            g.username,
            g.phone_number,
            COUNT(DISTINCT r.reviewID) as total_reviews,
            COUNT(DISTINCT CASE WHEN r.comment IS NOT NULL AND r.comment != '' THEN r.reviewID END) as reviews_with_comments,
            AVG(r.rating) as avg_rating
        FROM guider g
        LEFT JOIN booking b ON g.guiderID = b.guiderID
        LEFT JOIN review r ON b.bookingID = r.bookingID
        WHERE g.status = 'active'
        GROUP BY g.guiderID
        ORDER BY reviews_with_comments DESC";

$result = $conn->query($sql);

// Check for SQL error
if (!$result) {
    die("SQL Error: " . $conn->error . "<br><br>Query: " . $sql);
}

$guiders = [];
while ($row = $result->fetch_assoc()) {
    $guiders[] = $row;
}

echo "<!-- Debug: Found " . count($guiders) . " guiders -->\n";

// ULTRA-FAST MODE: Use cached sentiment data (loads in <5 seconds!)
$guidersWithSentiment = [];

// Check if sentiment_cache table exists
$cacheTableExists = false;
$checkTable = $conn->query("SHOW TABLES LIKE 'sentiment_cache'");
if ($checkTable && $checkTable->num_rows > 0) {
    $cacheTableExists = true;
}

foreach ($guiders as $guider) {
    if ($guider['reviews_with_comments'] == 0) {
        $guider['sentiment_data'] = null;
        $guider['cache_age'] = null;
        $guidersWithSentiment[] = $guider;
        continue;
    }
    
    // Try to load from cache (INSTANT!)
    if ($cacheTableExists) {
        $cacheStmt = $conn->prepare("SELECT * FROM sentiment_cache WHERE guiderID = ?");
        $cacheStmt->bind_param("i", $guider['guiderID']);
        $cacheStmt->execute();
        $cacheResult = $cacheStmt->get_result();
        
        if ($cacheResult->num_rows > 0) {
            $cache = $cacheResult->fetch_assoc();
            
            // Use cached data (SUPER FAST!)
            $guider['sentiment_data'] = [
                'success' => true,
                'sentiment_breakdown' => [
                    'positive' => $cache['positive_count'],
                    'negative' => $cache['negative_count'],
                    'neutral' => $cache['neutral_count'],
                    'positive_percentage' => (float)$cache['positive_percentage']
                ],
                'top_themes' => json_decode($cache['top_themes'], true) ?: [],
                'analyzed_reviews' => $cache['total_reviews_analyzed']
            ];
            
            // Calculate cache age
            $lastUpdated = strtotime($cache['last_updated']);
            $hoursAgo = round((time() - $lastUpdated) / 3600, 1);
            $guider['cache_age'] = $hoursAgo;
        } else {
            // No cache - show placeholder
            $guider['sentiment_data'] = null;
            $guider['cache_age'] = null;
        }
    } else {
        $guider['sentiment_data'] = null;
        $guider['cache_age'] = null;
    }
    
    $guidersWithSentiment[] = $guider;
}

echo "<!-- CACHE MODE: Loading from database cache (instant!) -->\n";
echo "<!-- To update cache, use the refresh button on guider performance pages -->\n";

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sentiment Analysis Report - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.3.0/css/all.min.css" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet" />
    <style>
        :root {
            --primary-color: #571785;
            --secondary-color: #4a0f6b;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
        }
        
        body {
            background-color: #f5f5f5;
            font-family: 'Montserrat', sans-serif;
            margin: 0;
            padding: 0;
            padding-top: 80px;
        }
        
        /* Navbar */
        .navbar {
            background-color: #571785 !important;
            padding: 12px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .navbar-title {
            font-size: 22px;
            font-weight: bold;
            color: white;
            margin: 0 auto;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.2);
        }
        
        .logo {
            width: 50px;
            height: 50px;
            object-fit: contain;
        }
        
        /* Sidebar */
        .sidebar {
            background: linear-gradient(135deg, #571785 0%, #4a0f6b 100%) !important;
            position: fixed;
            top: 0;
            left: -350px;
            width: 320px;
            height: 100vh;
            padding: 100px 15px 20px 15px !important;
            box-shadow: 0 8px 32px rgba(87, 23, 133, 0.4);
            border: 2px solid rgba(255, 255, 255, 0.1);
            z-index: 1000;
            transition: left 0.3s ease;
            overflow-y: auto;
        }
        
        .sidebar.mobile-open {
            left: 0;
        }
        
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
            color: #fff;
            font-weight: 600;
            text-decoration: none;
            margin-bottom: 8px;
            border-radius: 12px;
            transition: all 0.3s ease;
            border: 1px solid transparent;
        }
        
        .sidebar .menu a.active {
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.4);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }
        
        .sidebar .menu a:hover {
            background: rgba(255, 255, 255, 0.15);
            border-color: rgba(255, 255, 255, 0.3);
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }
        
        .sidebar .menu i {
            margin-right: 15px;
            font-size: 18px;
            width: 20px;
            text-align: center;
        }
        
        /* Main Content */
        .main-content {
            flex-grow: 1;
            padding: 80px 15px 20px 15px;
            width: 100%;
            margin-left: 0;
        }
        
        .wrapper {
            display: flex;
            min-height: 100vh;
            flex-direction: column;
        }
        
        /* Page Hero Header */
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
        
        /* Status Banner */
        .status-banner {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        }
        
        .status-banner i {
            font-size: 1.25rem;
        }
        
        /* Report Cards */
        .report-card {
            background: #fff;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s ease;
        }
        
        .report-card:hover {
            transform: translateY(-2px);
        }
        
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .report-title {
            font-size: 20px;
            font-weight: 600;
            color: #571785;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .stat-item {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            border-left: 4px solid #571785;
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #571785;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 14px;
            color: #666;
            font-weight: 500;
        }
        
        /* Table Styles */
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
        }
        
        table {
            width: 100%;
            margin-bottom: 0;
            border-collapse: collapse;
        }
        
        table th {
            background: #571785;
            color: #fff;
            border: none;
            font-weight: 600;
            padding: 15px;
            text-align: left;
        }
        
        table td {
            padding: 12px 15px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f0f0;
        }
        
        table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .guider-name {
            font-weight: 600;
            color: #571785;
        }
        
        .sentiment-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .sentiment-positive {
            background: #d1fae5;
            color: #065f46;
        }
        
        .sentiment-negative {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .sentiment-neutral {
            background: #e5e7eb;
            color: #374151;
        }
        
        .themes-list {
            font-size: 12px;
            color: #6b7280;
        }
        
        .theme-badge {
            display: inline-block;
            background: #dbeafe;
            color: #1e40af;
            padding: 2px 8px;
            border-radius: 6px;
            margin-right: 4px;
            margin-bottom: 4px;
        }
        
        .no-data {
            color: #9ca3af;
            font-style: italic;
        }
        
        .back-button {
            display: inline-block;
            padding: 10px 20px;
            background: #3b82f6;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 20px;
            transition: background 0.2s;
        }
        
        .back-button:hover {
            background: #2563eb;
        }
        
        .rating-display {
            color: #f59e0b;
            font-weight: 600;
        }
        
        /* Logout Button */
        .logout-btn {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            border: none;
            font-weight: 600;
            padding: 10px 20px;
            transition: all 0.3s ease;
        }
        
        .logout-btn:hover {
            background: linear-gradient(135deg, #c82333 0%, #bd2130 100%);
            transform: scale(1.05);
        }
    </style>
    <script>
        function toggleMobileMenu() {
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.querySelector('.mobile-overlay');
            sidebar.classList.toggle('mobile-open');
            overlay.classList.toggle('show');
        }
        
        function closeMobileMenu() {
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.querySelector('.mobile-overlay');
            sidebar.classList.remove('mobile-open');
            overlay.classList.remove('show');
        }
    </script>
</head>
<body>
    <div class="mobile-overlay" onclick="closeMobileMenu()"></div>
    
    <!-- Navbar -->
    <header>
        <nav class="navbar fixed-top">
            <div class="container d-flex align-items-center">
                <button class="mobile-menu-btn me-2" onclick="toggleMobileMenu()">
                    <i class="bi bi-list"></i>
                </button>
                <a class="navbar-brand d-flex align-items-center" href="../index.php">
                    <img src="../img/logo.png" class="img-fluid logo me-2" alt="HGS Logo">
                    <span class="fs-6 fw-bold text-white">Admin</span>
                </a>
                <h1 class="navbar-title ms-auto me-auto">HIKING GUIDANCE SYSTEM</h1>
            </div>
        </nav>
    </header>
    
    <div class="wrapper">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="logo-admin"><strong class="ms-2 text-white">Menu</strong></div>
            <div class="menu mt-4">
                <a href="ADashboard.html"><i class="bi bi-grid-fill"></i> Dashboard</a>
                <a href="AUser.html"><i class="bi bi-people-fill"></i> User</a>
                <a href="AMountain.php"><i class="bi bi-triangle-fill"></i> Mountain</a>
                <a href="AAppeal.php"><i class="bi bi-chat-dots-fill"></i> Appeal</a>
                <a href="ASentimentReport.php" class="active"><i class="fas fa-chart-line"></i> Sentiment Analysis</a>
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
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="container">
                <!-- Page Hero Header -->
                <div class="page-hero">
                    <h1 class="page-hero-title">
                        <i class="fas fa-brain"></i> Sentiment Analysis Report
                    </h1>
                    <p class="page-hero-subtitle">AI-powered analysis of guider reviews and feedback</p>
                </div>
        
        <div class="status-banner" style="background: #dbeafe; border: 1px solid #3b82f6; color: #1e40af;">
            <i class="fas fa-bolt"></i>
            <div>
                <strong>⚡ Ultra-Fast Cache Mode</strong> - Loading sentiment data from database cache (instant!)<br>
                <small style="opacity: 0.9;">
                    <?php if ($cacheTableExists): ?>
                        Cache table ready. Sentiment analysis is automatically saved when guiders view their performance pages.
                    <?php else: ?>
                        ⚠️ Cache table not created yet. Run <code>ml_api/sentiment_cache_table.sql</code> in phpMyAdmin to enable caching.
                    <?php endif; ?>
                </small>
            </div>
        </div>
        
        <?php if ($mlApiAvailable): ?>
        <!-- Overall Statistics -->
        <div class="report-card">
            <div class="report-header">
                <h2 class="report-title">
                    <i class="fas fa-chart-pie"></i> Overall Statistics
                </h2>
            </div>
            <div class="stats-grid">
            <?php
            $totalGuiders = count($guidersWithSentiment);
            $guidersWithReviews = count(array_filter($guidersWithSentiment, function($g) {
                return $g['reviews_with_comments'] > 0;
            }));
            $totalPositive = 0;
            $totalNegative = 0;
            $totalNeutral = 0;
            
            foreach ($guidersWithSentiment as $guider) {
                if ($guider['sentiment_data']) {
                    $totalPositive += $guider['sentiment_data']['sentiment_breakdown']['positive'];
                    $totalNegative += $guider['sentiment_data']['sentiment_breakdown']['negative'];
                    $totalNeutral += $guider['sentiment_data']['sentiment_breakdown']['neutral'];
                }
            }
            
            $totalReviews = $totalPositive + $totalNegative + $totalNeutral;
            $overallPositivePct = $totalReviews > 0 ? round(($totalPositive / $totalReviews) * 100, 1) : 0;
            ?>
            
            <div class="stat-item">
                <div class="stat-number" style="color: #10b981;">
                    <?php echo $overallPositivePct; ?>%
                </div>
                <div class="stat-label">Overall Positive Rate</div>
            </div>
            
            <div class="stat-item">
                <div class="stat-number">
                    <?php echo $totalReviews; ?>
                </div>
                <div class="stat-label">Total Reviews Analyzed</div>
            </div>
            
            <div class="stat-item">
                <div class="stat-number">
                    <?php echo $guidersWithReviews; ?> / <?php echo $totalGuiders; ?>
                </div>
                <div class="stat-label">Active Guiders</div>
            </div>
            
            <div class="stat-item">
                <div class="stat-label" style="margin-bottom: 10px;">Sentiment Distribution</div>
                <div style="font-size: 14px; text-align: left;">
                    <div style="color: #10b981; font-weight: 600;">😊 Positive: <?php echo $totalPositive; ?></div>
                    <div style="color: #6b7280; font-weight: 600;">😐 Neutral: <?php echo $totalNeutral; ?></div>
                    <div style="color: #ef4444; font-weight: 600;">😞 Negative: <?php echo $totalNegative; ?></div>
                </div>
            </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Guiders Table -->
        <div class="report-card">
            <div class="report-header">
                <h2 class="report-title">
                    <i class="fas fa-users"></i> Guiders Sentiment Breakdown
                </h2>
            </div>
            
            <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Guider</th>
                        <th>Contact</th>
                        <th>Avg Rating</th>
                        <th>Reviews</th>
                        <?php if ($mlApiAvailable): ?>
                        <th>Sentiment</th>
                        <th>Positive %</th>
                        <th>Top Themes</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($guidersWithSentiment as $guider): ?>
                    <tr>
                        <td class="guider-name">
                            <?php echo htmlspecialchars($guider['username']); ?>
                        </td>
                        <td><?php echo htmlspecialchars($guider['phone_number']); ?></td>
                        <td class="rating-display">
                            <?php 
                            echo $guider['avg_rating'] ? '⭐ ' . number_format($guider['avg_rating'], 1) : '-';
                            ?>
                        </td>
                        <td>
                            <?php echo $guider['reviews_with_comments']; ?> reviews
                        </td>
                        
                        <?php if ($mlApiAvailable): ?>
                        <td>
                            <?php 
                            if ($guider['sentiment_data']) {
                                $sentiment = $guider['sentiment_data']['sentiment_breakdown'];
                                $positive = $sentiment['positive'];
                                $negative = $sentiment['negative'];
                                $neutral = $sentiment['neutral'];
                                
                                if ($positive > $negative && $positive > $neutral) {
                                    echo '<span class="sentiment-badge sentiment-positive">😊 Positive</span>';
                                } elseif ($negative > $positive) {
                                    echo '<span class="sentiment-badge sentiment-negative">😞 Negative</span>';
                                } else {
                                    echo '<span class="sentiment-badge sentiment-neutral">😐 Neutral</span>';
                                }
                                
                                // Show cache age
                                if (isset($guider['cache_age']) && $guider['cache_age'] !== null) {
                                    $age = $guider['cache_age'];
                                    if ($age < 1) {
                                        echo '<br><small style="color: #10b981;">⚡ Fresh (&lt;1h)</small>';
                                    } elseif ($age < 24) {
                                        echo '<br><small style="color: #3b82f6;">📦 ' . round($age) . 'h ago</small>';
                                    } else {
                                        $days = round($age / 24, 1);
                                        echo '<br><small style="color: #f59e0b;">⏰ ' . $days . 'd ago</small>';
                                    }
                                }
                            } else {
                                echo '<span class="no-data">Not analyzed</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <?php 
                            if ($guider['sentiment_data']) {
                                echo '<strong>' . $guider['sentiment_data']['sentiment_breakdown']['positive_percentage'] . '%</strong>';
                            } else {
                                echo '<span class="no-data">-</span>';
                            }
                            ?>
                        </td>
                        <td class="themes-list">
                            <?php 
                            if ($guider['sentiment_data'] && !empty($guider['sentiment_data']['top_themes'])) {
                                $topThemes = array_slice($guider['sentiment_data']['top_themes'], 0, 3);
                                foreach ($topThemes as $theme) {
                                    echo '<span class="theme-badge">' . ucfirst($theme['theme']) . ' (' . $theme['count'] . ')</span>';
                                }
                            } else {
                                echo '<span class="no-data">No themes detected</span>';
                            }
                            ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

