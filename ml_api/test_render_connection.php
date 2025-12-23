<?php
/**
 * Test Render ML API Connection
 * Upload this to Hostinger and visit it in browser
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔍 Testing Render ML API Connection</h1>";

// Test 1: Check config
require_once __DIR__ . '/config.php';
echo "<h2>1. Config Check</h2>";
echo "<p><strong>API URL:</strong> " . ML_API_BASE_URL . "</p>";

if (strpos(ML_API_BASE_URL, 'render') !== false) {
    echo "<p>✅ Config is pointing to Render</p>";
} else {
    echo "<p>❌ Config is still pointing to localhost! Update config.php</p>";
}

// Test 2: Health check
echo "<h2>2. Health Check</h2>";
$healthResult = checkMLAPIHealth();
echo "<p><strong>Health Check:</strong> " . ($healthResult ? "✅ API is Online" : "❌ API is Offline") . "</p>";

// Test 3: Raw cURL test
echo "<h2>3. Raw API Test</h2>";
$url = ML_API_BASE_URL . '/health';
echo "<p><strong>Testing URL:</strong> $url</p>";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 90);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // In case of SSL issues

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
$info = curl_getinfo($ch);
curl_close($ch);

echo "<p><strong>HTTP Code:</strong> $httpCode</p>";

if ($error) {
    echo "<p>❌ <strong>cURL Error:</strong> $error</p>";
} else {
    echo "<p>✅ <strong>Response:</strong></p>";
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
}

// Test 4: Test sentiment analysis
echo "<h2>4. Sentiment Analysis Test</h2>";
$testReview = "Great guide! Very helpful and knowledgeable.";
echo "<p><strong>Test Review:</strong> \"$testReview\"</p>";

$result = analyzeSentiment($testReview);
echo "<p><strong>Result:</strong></p>";
echo "<pre>" . htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT)) . "</pre>";

// Test 5: Check database for reviews
echo "<h2>5. Database Review Check</h2>";
require_once __DIR__ . '/../shared/db_connection.php';

$sql = "SELECT COUNT(*) as total FROM review WHERE comment IS NOT NULL AND comment != ''";
$result = $conn->query($sql);
if ($result) {
    $row = $result->fetch_assoc();
    echo "<p><strong>Reviews with comments:</strong> " . $row['total'] . "</p>";
    
    if ($row['total'] == 0) {
        echo "<p>⚠️ No reviews with comments found in database!</p>";
    } else {
        echo "<p>✅ Reviews exist in database</p>";
        
        // Show sample
        $sampleSql = "SELECT r.reviewID, r.comment, b.guiderID 
                      FROM review r 
                      JOIN booking b ON r.bookingID = b.bookingID 
                      WHERE r.comment IS NOT NULL AND r.comment != '' 
                      LIMIT 3";
        $sampleResult = $conn->query($sampleSql);
        if ($sampleResult && $sampleResult->num_rows > 0) {
            echo "<p><strong>Sample reviews:</strong></p>";
            echo "<ul>";
            while ($row = $sampleResult->fetch_assoc()) {
                echo "<li>Guider #" . $row['guiderID'] . ": \"" . htmlspecialchars(substr($row['comment'], 0, 50)) . "...\"</li>";
            }
            echo "</ul>";
        }
    }
} else {
    echo "<p>❌ Database query failed: " . $conn->error . "</p>";
}

echo "<hr>";
echo "<p><em>Test completed at: " . date('Y-m-d H:i:s') . "</em></p>";
?>
