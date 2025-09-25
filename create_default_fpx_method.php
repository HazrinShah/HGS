<?php
// Database connection
include 'shared/db_connection.php';

echo "Creating default FPX payment method for all users...<br>";

// Get all hiker IDs
$hikerQuery = "SELECT hikerID FROM hiker";
$stmt = $conn->prepare($hikerQuery);
$stmt->execute();
$result = $stmt->get_result();
$hikers = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$insertQuery = "INSERT INTO payment_methods (hikerID, methodType, cardName, cardNumber, expiryDate, createdAt) VALUES (?, 'FPX', '', '', '', NOW())";
$stmt = $conn->prepare($insertQuery);

$addedCount = 0;
foreach ($hikers as $hiker) {
    $hikerID = $hiker['hikerID'];
    
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
        $stmt->execute();
        $addedCount++;
        echo "Added FPX method for hiker ID: $hikerID<br>";
    } else {
        echo "Hiker ID $hikerID already has FPX method<br>";
    }
}

$stmt->close();

echo "<br>Total FPX methods added: $addedCount<br>";
echo "Done!<br>";

$conn->close();
?>
