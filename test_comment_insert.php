<?php
include 'shared/db_connection.php';

echo "<h2>Testing Comment Insert</h2>";

// Test inserting a comment directly
$testComment = "This is a test comment for debugging";
$bookingID = 58; // Use the booking ID from your logs
$hikerID = 1; // You'll need to adjust this to your actual hiker ID
$guiderID = 6; // From your logs, guider is fatih with ID 6
$rating = 5;

echo "<p>Testing with:</p>";
echo "<ul>";
echo "<li>Booking ID: $bookingID</li>";
echo "<li>Hiker ID: $hikerID</li>";
echo "<li>Guider ID: $guiderID</li>";
echo "<li>Rating: $rating</li>";
echo "<li>Comment: '$testComment'</li>";
echo "</ul>";

// Test the exact same query as in HRateReview.php
$insertQuery = "INSERT INTO review (bookingID, hikerID, guiderID, rating, comment, createdAt) VALUES (?, ?, ?, ?, ?, NOW())";
$insertStmt = $conn->prepare($insertQuery);
$insertStmt->bind_param("iiisi", $bookingID, $hikerID, $guiderID, $rating, $testComment);

if ($insertStmt->execute()) {
    echo "<p style='color: green;'><strong>SUCCESS:</strong> Comment inserted successfully!</p>";
    echo "<p>Insert ID: " . $conn->insert_id . "</p>";
} else {
    echo "<p style='color: red;'><strong>ERROR:</strong> " . $insertStmt->error . "</p>";
}

$insertStmt->close();

// Check what was actually inserted
echo "<h3>Verifying Insert:</h3>";
$checkQuery = "SELECT * FROM review WHERE bookingID = ? AND hikerID = ?";
$checkStmt = $conn->prepare($checkQuery);
$checkStmt->bind_param("ii", $bookingID, $hikerID);
$checkStmt->execute();
$result = $checkStmt->get_result();

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "<p>Review ID: " . $row['reviewID'] . "</p>";
        echo "<p>Comment: '" . $row['comment'] . "'</p>";
        echo "<p>Comment Length: " . strlen($row['comment']) . "</p>";
    }
} else {
    echo "<p>No reviews found for this booking/hiker combination.</p>";
}

$checkStmt->close();
$conn->close();
?>
