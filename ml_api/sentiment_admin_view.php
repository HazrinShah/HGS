<?php
/**
 * Sentiment Analysis Overview for Admin
 * 
 * This page shows sentiment analysis for all guiders (admin view)
 * Create as: admin/ASentimentReport.php
 */

session_start();
include("../database/connection.php");
include("../ml_api/config.php");

// Check if admin is logged in
if (!isset($_SESSION['type']) || $_SESSION['type'] != 'admin') {
    header("Location: ../index.php");
    exit();
}

$adminID = $_SESSION['uid'];

// Check ML API health
$mlApiAvailable = checkMLAPIHealth();

// Fetch all guiders with their review counts
$sql = "SELECT 
            u.userID as guiderID,
            u.username,
            u.phoneNo,
            COUNT(DISTINCT r.reviewID) as total_reviews,
            COUNT(DISTINCT CASE WHEN r.comment IS NOT NULL AND r.comment != '' THEN r.reviewID END) as reviews_with_comments,
            AVG(r.rating) as avg_rating
        FROM user u
        LEFT JOIN review r ON u.userID = r.guiderID
        WHERE u.type = 'guider'
        GROUP BY u.userID
        ORDER BY reviews_with_comments DESC";

$result = $con->query($sql);
$guiders = [];
while ($row = $result->fetch_assoc()) {
    $guiders[] = $row;
}

// If ML API available, analyze each guider's sentiment
$guidersWithSentiment = [];

if ($mlApiAvailable) {
    foreach ($guiders as $guider) {
        if ($guider['reviews_with_comments'] == 0) {
            $guider['sentiment_data'] = null;
            $guidersWithSentiment[] = $guider;
            continue;
        }
        
        // Fetch reviews for this guider
        $guiderID = $guider['guiderID'];
        $reviewSql = "SELECT reviewID, comment FROM review 
                     WHERE guiderID = ? 
                     AND comment IS NOT NULL 
                     AND comment != ''";
        $stmt = $con->prepare($reviewSql);
        $stmt->bind_param("i", $guiderID);
        $stmt->execute();
        $reviewResult = $stmt->get_result();
        
        $reviews = [];
        while ($reviewRow = $reviewResult->fetch_assoc()) {
            $reviews[] = $reviewRow;
        }
        
        // Analyze sentiment
        if (!empty($reviews)) {
            $analysisResult = analyzeGuiderReviews($reviews);
            $guider['sentiment_data'] = $analysisResult['success'] ? $analysisResult : null;
        } else {
            $guider['sentiment_data'] = null;
        }
        
        $guidersWithSentiment[] = $guider;
    }
} else {
    $guidersWithSentiment = $guiders;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sentiment Analysis Report - HGS Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f3f4f6;
            color: #1f2937;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 24px;
            border-radius: 8px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 28px;
            color: #1f2937;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .header p {
            color: #6b7280;
            font-size: 14px;
        }
        
        .status-banner {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .status-banner.success {
            background: #d1fae5;
            border: 1px solid #10b981;
            color: #065f46;
        }
        
        .status-banner.error {
            background: #fee2e2;
            border: 1px solid #ef4444;
            color: #991b1b;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .stat-card-title {
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .stat-card-value {
            font-size: 32px;
            font-weight: bold;
            color: #1f2937;
        }
        
        .guiders-table {
            background: white;
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .guiders-table h2 {
            font-size: 20px;
            margin-bottom: 20px;
            color: #1f2937;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background: #f9fafb;
        }
        
        th {
            padding: 12px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            border-bottom: 2px solid #e5e7eb;
        }
        
        td {
            padding: 16px 12px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 14px;
        }
        
        tr:hover {
            background: #f9fafb;
        }
        
        .guider-name {
            font-weight: 600;
            color: #1f2937;
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
    </style>
</head>
<body>
    <div class="container">
        <a href="Ahome.php" class="back-button">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        
        <div class="header">
            <h1>
                <i class="fas fa-chart-line"></i>
                Sentiment Analysis Report
            </h1>
            <p>AI-powered analysis of guider reviews and feedback</p>
        </div>
        
        <?php if ($mlApiAvailable): ?>
            <div class="status-banner success">
                <i class="fas fa-check-circle"></i>
                <strong>ML API Online</strong> - Real-time sentiment analysis is active
            </div>
        <?php else: ?>
            <div class="status-banner error">
                <i class="fas fa-exclamation-circle"></i>
                <strong>ML API Offline</strong> - Sentiment analysis unavailable. Please start the ML API server.
            </div>
        <?php endif; ?>
        
        <?php if ($mlApiAvailable): ?>
        <!-- Overall Statistics -->
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
            
            <div class="stat-card">
                <div class="stat-card-title">Overall Positive Rate</div>
                <div class="stat-card-value" style="color: #10b981;">
                    <?php echo $overallPositivePct; ?>%
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-card-title">Total Reviews Analyzed</div>
                <div class="stat-card-value">
                    <?php echo $totalReviews; ?>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-card-title">Active Guiders</div>
                <div class="stat-card-value">
                    <?php echo $guidersWithReviews; ?> / <?php echo $totalGuiders; ?>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-card-title">Sentiment Distribution</div>
                <div style="margin-top: 8px; font-size: 14px;">
                    <div style="color: #10b981;">✓ Positive: <?php echo $totalPositive; ?></div>
                    <div style="color: #6b7280;">○ Neutral: <?php echo $totalNeutral; ?></div>
                    <div style="color: #ef4444;">✗ Negative: <?php echo $totalNegative; ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Guiders Table -->
        <div class="guiders-table">
            <h2>📊 Guiders Sentiment Breakdown</h2>
            
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
                        <td><?php echo htmlspecialchars($guider['phoneNo']); ?></td>
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
                            } else {
                                echo '<span class="no-data">No data</span>';
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
</body>
</html>

