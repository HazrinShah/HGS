<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['hikerID'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

include '../shared/db_connection.php';

$bookingID = $_GET['bookingID'] ?? null;

if (!$bookingID) {
    echo json_encode(['success' => false, 'message' => 'Booking ID required']);
    exit;
}

// verify booking ni milik hiker yang login
$verifyStmt = $conn->prepare("SELECT hikerID FROM booking WHERE bookingID = ?");
$verifyStmt->bind_param("i", $bookingID);
$verifyStmt->execute();
$verifyResult = $verifyStmt->get_result();
$booking = $verifyResult->fetch_assoc();
$verifyStmt->close();

if (!$booking || $booking['hikerID'] != $_SESSION['hikerID']) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// ambil hiker details
$stmt = $conn->prepare("
    SELECT hikerName, identityCard, address, phoneNumber, emergencyContactName, emergencyContactNumber
    FROM bookinghikerdetails
    WHERE bookingID = ?
    ORDER BY hikerDetailID ASC
");
$stmt->bind_param("i", $bookingID);
$stmt->execute();
$result = $stmt->get_result();
$hikers = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode(['success' => true, 'hikers' => $hikers]);
?>

