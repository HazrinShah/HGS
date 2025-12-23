<?php
/**
 * INTEGRATION EXAMPLE: Add Sentiment Analysis to GPerformance.php
 * 
 * This file shows how to integrate sentiment analysis into the guider dashboard.
 * 
 * STEPS TO INTEGRATE:
 * 1. Copy this code
 * 2. Open guider/GPerformance.php
 * 3. Add the sentiment section after the existing performance metrics
 */

// ============================================================
// STEP 1: Add these includes at the top of GPerformance.php
// ============================================================

/*
// Add after the existing session_start() and database connection
require_once '../ml_api/config.php';
require_once '../ml_api/sentiment_guider_view.php';
*/

// ============================================================
// STEP 2: Add this HTML section where you want to display sentiment
// ============================================================

/*
<!-- Sentiment Analysis Section -->
<section class="performance-section sentiment-analysis-section" style="margin-top: 30px;">
    <div class="section-header">
        <h2 style="display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-chart-line"></i>
            Review Sentiment Analysis
        </h2>
        <p style="color: #6b7280; font-size: 14px; margin-top: 8px;">
            AI-powered analysis of your review feedback
        </p>
    </div>
    
    <?php
    // Display sentiment analysis for this guider
    // $_SESSION['uid'] contains the logged-in guider's ID
    displayGuiderSentimentAnalysis($_SESSION['uid'], $con);
    ?>
</section>
*/

// ============================================================
// COMPLETE EXAMPLE: Full Integration
// ============================================================

// This is a complete example showing where to place the code in GPerformance.php
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guider Performance - HGS</title>
    <!-- Your existing CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Add this CSS for better section styling */
        .performance-section {
            background: white;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .section-header h2 {
            font-size: 20px;
            color: #1f2937;
            margin-bottom: 8px;
        }
    </style>
</head>
<body>
    <?php
    session_start();
    include("../database/connection.php");
    
    // ADD THESE LINES:
    require_once '../ml_api/config.php';
    require_once '../ml_api/sentiment_guider_view.php';
    
    // Check if guider is logged in
    if (!isset($_SESSION['type']) || $_SESSION['type'] != 'guider') {
        header("Location: ../index.php");
        exit();
    }
    
    $guiderID = $_SESSION['uid'];
    ?>
    
    <div class="container">
        <h1>Performance Dashboard</h1>
        
        <!-- Your existing performance metrics sections -->
        <section class="performance-section">
            <h2>Booking Statistics</h2>
            <!-- Your existing booking stats code -->
        </section>
        
        <section class="performance-section">
            <h2>Ratings Overview</h2>
            <!-- Your existing ratings code -->
        </section>
        
        <!-- ADD THIS NEW SECTION: -->
        <section class="performance-section sentiment-analysis-section">
            <div class="section-header">
                <h2 style="display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-chart-line"></i>
                    Review Sentiment Analysis
                </h2>
                <p style="color: #6b7280; font-size: 14px; margin-top: 8px;">
                    AI-powered analysis of your review feedback
                </p>
            </div>
            
            <?php
            // This function displays all sentiment analysis UI
            displayGuiderSentimentAnalysis($guiderID, $con);
            ?>
        </section>
        
    </div>
</body>
</html>

<?php
// ============================================================
// TESTING NOTES
// ============================================================

/*
Before testing:
1. Make sure ML API is running: python app.py
2. Make sure you have reviews with comments in the database
3. Test the health endpoint: http://127.0.0.1:5000/api/health

Expected output:
- If ML API is running: Sentiment dashboard with analysis
- If ML API is offline: Yellow warning banner with fallback message
- If no reviews: Gray message "No reviews with comments yet"

Debug tips:
- Check browser console for errors
- Check Apache error log: C:\xampp\apache\logs\error.log
- Check Flask terminal output for API calls
*/
?>

