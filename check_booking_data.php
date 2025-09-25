<?php
include 'shared/db_connection.php';

echo "=== Booking 58 Details ===\n";
$bookingQuery = "SELECT * FROM booking WHERE bookingID = 58";
$result = $conn->query($bookingQuery);
if ($row = $result->fetch_assoc()) {
    echo "Booking ID: " . $row['bookingID'] . "\n";
    echo "Hiker ID: " . $row['hikerID'] . "\n";
    echo "Guider ID: " . $row['guiderID'] . "\n";
    echo "Status: " . $row['status'] . "\n";
} else {
    echo "Booking 58 not found\n";
}

echo "\n=== Existing Reviews ===\n";
$reviewQuery = "SELECT * FROM review";
$result = $conn->query($reviewQuery);
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "Review ID: " . $row['reviewID'] . "\n";
        echo "Booking ID: " . $row['bookingID'] . "\n";
        echo "Hiker ID: " . $row['hikerID'] . "\n";
        echo "Rating: " . $row['rating'] . "\n";
        echo "Comment: '" . ($row['comment'] ?: 'NULL/EMPTY') . "'\n";
        echo "---\n";
    }
} else {
    echo "No reviews found\n";
}

$conn->close();
?>
