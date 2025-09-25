<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['hikerID'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$hikerID = $_SESSION['hikerID'];

// Check if bookingID is provided
if (!isset($_POST['bookingID']) || empty($_POST['bookingID'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Booking ID is required']);
    exit;
}

$bookingID = intval($_POST['bookingID']);

// Database connection
include '../shared/db_connection.php';

try {
    // Verify that the booking belongs to the current user and is pending
    $verifyQuery = "SELECT bookingID FROM booking WHERE bookingID = ? AND hikerID = ? AND status = 'pending'";
    $verifyStmt = $conn->prepare($verifyQuery);
    $verifyStmt->bind_param("ii", $bookingID, $hikerID);
    $verifyStmt->execute();
    $result = $verifyStmt->get_result();
    
    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found or already processed']);
        exit;
    }
    
    // Delete the booking
    $deleteQuery = "DELETE FROM booking WHERE bookingID = ? AND hikerID = ?";
    $deleteStmt = $conn->prepare($deleteQuery);
    $deleteStmt->bind_param("ii", $bookingID, $hikerID);
    
    if ($deleteStmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Booking cancelled successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to cancel booking']);
    }
    
    $deleteStmt->close();
    $verifyStmt->close();
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred while cancelling the booking']);
}

$conn->close();
?>
