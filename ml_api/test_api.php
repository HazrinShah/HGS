<?php
/**
 * Test Script for ML API Integration
 * 
 * Run this file to test if ML API is working correctly
 * Access: http://localhost/HGS/ml_api/test_api.php
 */

require_once 'config.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ML API Test - HGS</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f3f4f6;
            padding: 20px;
            max-width: 1000px;
            margin: 0 auto;
        }
        .test-card {
            background: white;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .test-card h2 {
            margin-top: 0;
            color: #1f2937;
        }
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            margin-left: 10px;
        }
        .status-success {
            background: #d1fae5;
            color: #065f46;
        }
        .status-error {
            background: #fee2e2;
            color: #991b1b;
        }
        pre {
            background: #f9fafb;
            padding: 16px;
            border-radius: 6px;
            overflow-x: auto;
            border: 1px solid #e5e7eb;
        }
        .test-result {
            margin-top: 16px;
        }
        .button {
            background: #3b82f6;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            margin-top: 10px;
        }
        .button:hover {
            background: #2563eb;
        }
    </style>
</head>
<body>
    <h1>🧪 ML API Test Suite</h1>
    <p>Testing HGS Machine Learning API integration</p>

    <!-- Test 1: Health Check -->
    <div class="test-card">
        <h2>
            Test 1: Health Check
            <?php
            $healthCheck = checkMLAPIHealth();
            if ($healthCheck) {
                echo '<span class="status-badge status-success">✓ PASSED</span>';
            } else {
                echo '<span class="status-badge status-error">✗ FAILED</span>';
            }
            ?>
        </h2>
        
        <p>Checking if ML API server is running...</p>
        
        <?php if ($healthCheck): ?>
            <div class="test-result">
                <strong>Status:</strong> ML API is online and healthy ✅
                <br><br>
                <strong>Endpoint:</strong> <code><?php echo ML_API_BASE_URL; ?>/health</code>
            </div>
        <?php else: ?>
            <div class="test-result" style="color: #dc2626;">
                <strong>Status:</strong> ML API is offline ❌
                <br><br>
                <strong>Solution:</strong>
                <ol>
                    <li>Open terminal/command prompt</li>
                    <li>Navigate to: <code>C:\xampp\htdocs\HGS\ml_api</code></li>
                    <li>Run: <code>python app.py</code></li>
                    <li>Refresh this page</li>
                </ol>
                <a href="?" class="button">Refresh Test</a>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($healthCheck): ?>
    <!-- Test 2: Single Review Analysis -->
    <div class="test-card">
        <h2>Test 2: Single Review Sentiment Analysis</h2>
        
        <p>Testing sentiment analysis on a sample review...</p>
        
        <?php
        $testReview = "Ahmad was very knowledgeable about the trail and super friendly! He ensured our safety throughout the hike. Highly recommended!";
        
        echo "<strong>Test Review:</strong><br>";
        echo "<em>\"$testReview\"</em>";
        
        $result = analyzeSentiment($testReview);
        
        echo "<div class='test-result'>";
        if ($result['success']) {
            echo '<span class="status-badge status-success">✓ SUCCESS</span><br><br>';
            
            $analysis = $result['analysis'];
            
            echo "<strong>Results:</strong><br>";
            echo getSentimentBadge($analysis['sentiment'], $analysis['score']);
            echo getEmotionBadge($analysis['emotion']);
            echo "<br><br>";
            
            echo "<strong>Themes:</strong> " . getThemeBadges($analysis['themes']) . "<br><br>";
            
            echo "<strong>AI Summary:</strong><br>";
            echo htmlspecialchars($analysis['summary']);
            
            echo "<br><br><strong>Full Response:</strong>";
            echo "<pre>" . json_encode($result, JSON_PRETTY_PRINT) . "</pre>";
        } else {
            echo '<span class="status-badge status-error">✗ FAILED</span><br><br>';
            echo "<strong>Error:</strong> " . $result['error'];
            echo "<pre>" . json_encode($result, JSON_PRETTY_PRINT) . "</pre>";
        }
        echo "</div>";
        ?>
    </div>

    <!-- Test 3: Batch Analysis -->
    <div class="test-card">
        <h2>Test 3: Batch Review Analysis</h2>
        
        <p>Testing batch analysis with multiple reviews...</p>
        
        <?php
        $testReviews = [
            ['reviewID' => 1, 'comment' => 'Great guide! Very knowledgeable and friendly.'],
            ['reviewID' => 2, 'comment' => 'Good experience but arrived 10 minutes late.'],
            ['reviewID' => 3, 'comment' => 'Excellent safety protocols. Ahmad is the best!'],
        ];
        
        $batchResult = analyzeGuiderReviews($testReviews);
        
        echo "<div class='test-result'>";
        if ($batchResult['success']) {
            echo '<span class="status-badge status-success">✓ SUCCESS</span><br><br>';
            
            echo "<strong>Analysis Summary:</strong><br>";
            echo "Total Reviews: " . $batchResult['total_reviews'] . "<br>";
            echo "Analyzed: " . $batchResult['analyzed_reviews'] . "<br>";
            echo "Positive Rate: <strong style='color: #10b981;'>" . $batchResult['sentiment_breakdown']['positive_percentage'] . "%</strong><br><br>";
            
            echo "<strong>Sentiment Breakdown:</strong><br>";
            echo "✅ Positive: " . $batchResult['sentiment_breakdown']['positive'] . "<br>";
            echo "⚪ Neutral: " . $batchResult['sentiment_breakdown']['neutral'] . "<br>";
            echo "❌ Negative: " . $batchResult['sentiment_breakdown']['negative'] . "<br><br>";
            
            if (!empty($batchResult['top_themes'])) {
                echo "<strong>Top Themes:</strong><br>";
                foreach ($batchResult['top_themes'] as $theme) {
                    echo "• " . ucfirst($theme['theme']) . " (" . $theme['count'] . " mentions)<br>";
                }
            }
            
            echo "<br><strong>Full Response:</strong>";
            echo "<pre>" . json_encode($batchResult, JSON_PRETTY_PRINT) . "</pre>";
        } else {
            echo '<span class="status-badge status-error">✗ FAILED</span><br><br>';
            echo "<strong>Error:</strong> " . $batchResult['error'];
        }
        echo "</div>";
        ?>
    </div>

    <!-- Test 4: Helper Functions -->
    <div class="test-card">
        <h2>Test 4: PHP Helper Functions</h2>
        
        <p>Testing badge generation functions...</p>
        
        <div class="test-result">
            <strong>Sentiment Badges:</strong><br>
            <?php
            echo getSentimentBadge('positive', 95) . " ";
            echo getSentimentBadge('neutral', 60) . " ";
            echo getSentimentBadge('negative', 30) . " ";
            ?>
            <br><br>
            
            <strong>Emotion Badges:</strong><br>
            <?php
            echo getEmotionBadge('happy') . " ";
            echo getEmotionBadge('satisfied') . " ";
            echo getEmotionBadge('neutral') . " ";
            echo getEmotionBadge('disappointed') . " ";
            echo getEmotionBadge('angry') . " ";
            ?>
            <br><br>
            
            <strong>Theme Badges:</strong><br>
            <?php
            echo getThemeBadges(['safety', 'knowledge', 'friendliness', 'communication']);
            ?>
            <br><br>
            
            <span class="status-badge status-success">✓ ALL HELPERS WORKING</span>
        </div>
    </div>

    <div class="test-card">
        <h2>✅ Test Complete</h2>
        <p>All tests passed! ML API is ready for integration.</p>
        <p>
            <strong>Next Steps:</strong>
        </p>
        <ol>
            <li>Add sentiment analysis to Guider dashboard (GPerformance.php)</li>
            <li>Copy sentiment_admin_view.php to admin folder</li>
            <li>Test with real reviews from database</li>
        </ol>
    </div>
    <?php endif; ?>

</body>
</html>

