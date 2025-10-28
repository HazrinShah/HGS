<?php
session_start();

// Restrict quick login to localhost and DEV mode only
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']);
$devMode = getenv('DEV_MODE') === '1' || (defined('DEV_MODE') && DEV_MODE === true);
if (!$isLocal || !$devMode) {
    http_response_code(403);
    echo 'Quick login is disabled.';
    exit;
}

echo "<h2>Quick Login for Testing</h2>";

// Set session for user 9 (who has the bookings)
$_SESSION['hikerID'] = 9;
$_SESSION['username'] = 'user 1';

echo "<p style='color: green;'><strong>✓ Logged in as User 1 (hikerID: 9)</strong></p>";
echo "<p><strong>Session hikerID:</strong> " . $_SESSION['hikerID'] . "</p>";
echo "<p><strong>Session username:</strong> " . $_SESSION['username'] . "</p>";

// Check bookings
include 'shared/db_connection.php';
$result = $conn->query("SELECT COUNT(*) as count FROM booking WHERE hikerID = 9 AND status = 'pending'");
$row = $result->fetch_assoc();
echo "<p><strong>Pending bookings:</strong> " . $row['count'] . "</p>";

$result = $conn->query("SELECT bookingID, status, created_at FROM booking WHERE hikerID = 9 ORDER BY created_at DESC LIMIT 5");
echo "<p><strong>Recent bookings:</strong></p>";
echo "<ul>";
while($row = $result->fetch_assoc()) {
    echo "<li>Booking ID: " . $row['bookingID'] . " - Status: " . $row['status'] . " - Created: " . $row['created_at'] . "</li>";
}
echo "</ul>";

$conn->close();

echo "<p><a href='hiker/HPayment.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Go to Payment Page</a></p>";
echo "<p><a href='session_test.php' style='background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Check Session Status</a></p>";
?>
