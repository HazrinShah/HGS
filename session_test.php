<?php
session_start();

echo "<h2>Session Debug Information</h2>";
echo "<p><strong>Session ID:</strong> " . session_id() . "</p>";
echo "<p><strong>Session Status:</strong> " . (session_status() === PHP_SESSION_ACTIVE ? 'Active' : 'Inactive') . "</p>";

echo "<h3>Session Data:</h3>";
if (empty($_SESSION)) {
    echo "<p style='color: red;'>No session data found!</p>";
} else {
    echo "<pre>" . print_r($_SESSION, true) . "</pre>";
}

echo "<h3>Login Test:</h3>";
if (isset($_SESSION['hikerID'])) {
    echo "<p style='color: green;'>✓ User is logged in with hikerID: " . $_SESSION['hikerID'] . "</p>";
    
    // Test database connection and check for bookings
    include 'shared/db_connection.php';
    
    $hikerID = $_SESSION['hikerID'];
    $result = $conn->query("SELECT COUNT(*) as count FROM booking WHERE hikerID = $hikerID");
    $row = $result->fetch_assoc();
    echo "<p><strong>Total bookings for this user:</strong> " . $row['count'] . "</p>";
    
    $result = $conn->query("SELECT COUNT(*) as count FROM booking WHERE hikerID = $hikerID AND status = 'pending'");
    $row = $result->fetch_assoc();
    echo "<p><strong>Pending bookings for this user:</strong> " . $row['count'] . "</p>";
    
    $conn->close();
} else {
    echo "<p style='color: red;'>✗ User is NOT logged in</p>";
    echo "<p><a href='hiker/HLogin.html'>Click here to login</a></p>";
}

echo "<h3>Quick Login Test (for debugging):</h3>";
if (isset($_GET['test_login'])) {
    $_SESSION['hikerID'] = 9; // Use the hikerID that has bookings
    $_SESSION['username'] = 'Test User';
    echo "<p style='color: green;'>Test login set. <a href='session_test.php'>Refresh page</a></p>";
} else {
    echo "<p><a href='session_test.php?test_login=1'>Set test login (hikerID: 9)</a></p>";
}
?>
