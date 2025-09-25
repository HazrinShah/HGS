<?php
// Database connection
include 'shared/db_connection.php';

echo "<h2>Adding FPX Payment Method to All Users</h2>";

// Get all hiker IDs
$hikerQuery = "SELECT hikerID, username FROM hiker";
$stmt = $conn->prepare($hikerQuery);
$stmt->execute();
$result = $stmt->get_result();
$hikers = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo "Found " . count($hikers) . " users.<br><br>";

$insertQuery = "INSERT INTO payment_methods (hikerID, methodType, cardName, cardNumber, expiryDate, createdAt) VALUES (?, 'FPX', '', '', '', NOW())";
$stmt = $conn->prepare($insertQuery);

$addedCount = 0;
$skippedCount = 0;

foreach ($hikers as $hiker) {
    $hikerID = $hiker['hikerID'];
    $username = $hiker['username'];
    
    // Check if user already has an FPX method
    $checkQuery = "SELECT COUNT(*) as count FROM payment_methods WHERE hikerID = ? AND methodType = 'FPX'";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bind_param("i", $hikerID);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $count = $checkResult->fetch_assoc()['count'];
    $checkStmt->close();
    
    if ($count == 0) {
        $stmt->bind_param("i", $hikerID);
        if ($stmt->execute()) {
            $addedCount++;
            echo "✅ Added FPX method for user: $username (ID: $hikerID)<br>";
        } else {
            echo "❌ Failed to add FPX method for user: $username (ID: $hikerID)<br>";
        }
    } else {
        $skippedCount++;
        echo "⏭️ User $username (ID: $hikerID) already has FPX method<br>";
    }
}

$stmt->close();

echo "<br><h3>Summary:</h3>";
echo "✅ FPX methods added: $addedCount<br>";
echo "⏭️ Users already had FPX: $skippedCount<br>";
echo "📊 Total users processed: " . count($hikers) . "<br>";

if ($addedCount > 0) {
    echo "<br><strong>✅ Success! All users now have FPX payment method.</strong><br>";
} else {
    echo "<br><strong>ℹ️ All users already had FPX payment method.</strong><br>";
}

$conn->close();
?>
