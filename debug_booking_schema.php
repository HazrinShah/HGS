<?php
include 'shared/db_connection.php';

echo "Booking table schema:\n";
$result = $conn->query('DESCRIBE booking');
while ($row = $result->fetch_assoc()) {
    echo $row['Field'] . ' - ' . $row['Type'] . ' - ' . $row['Null'] . ' - ' . $row['Default'] . "\n";
}

echo "\nRecent bookings:\n";
$result = $conn->query('SELECT * FROM booking ORDER BY bookingID DESC LIMIT 5');
while ($row = $result->fetch_assoc()) {
    echo "ID: " . $row['bookingID'] . ", Hiker: " . $row['hikerID'] . ", Guider: " . $row['guiderID'] . ", Status: " . $row['status'] . ", GroupType: " . ($row['groupType'] ?? 'NULL') . "\n";
}

$conn->close();
?>
