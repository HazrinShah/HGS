<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['hikerID'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$hikerID = $_SESSION['hikerID'];
$bookingID = $_POST['bookingID'] ?? null;
$appealType = $_POST['appealType'] ?? null;
$reason = $_POST['reason'] ?? '';

// Validate required fields
if (!$bookingID || !$appealType) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Validate appeal type
if (!in_array($appealType, ['cancellation', 'refund', 'change'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid appeal type']);
    exit;
}

// Database connection
include '../shared/db_connection.php';

try {
    // Check if booking belongs to user
    $checkBookingQuery = "SELECT bookingID FROM booking WHERE bookingID = ? AND hikerID = ? AND status = 'accepted'";
    $stmt = $conn->prepare($checkBookingQuery);
    $stmt->bind_param("ii", $bookingID, $hikerID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Booking not found or not accessible']);
        exit;
    }
    $stmt->close();
    
    // Check if there's already a pending appeal for this booking
    $checkAppealQuery = "SELECT appealID FROM appeal WHERE bookingID = ? AND status = 'pending'";
    $stmt = $conn->prepare($checkAppealQuery);
    $stmt->bind_param("i", $bookingID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'You already have a pending request for this booking']);
        exit;
    }
    $stmt->close();
    
    // Insert appeal request (hikerID set, guiderID NULL)
    $insertAppealQuery = "INSERT INTO appeal (bookingID, hikerID, guiderID, appealType, reason, status) VALUES (?, ?, NULL, ?, ?, 'pending')";
    $stmt = $conn->prepare($insertAppealQuery);
    $stmt->bind_param("iiss", $bookingID, $hikerID, $appealType, $reason);
    
    if ($stmt->execute()) {
        $appealID = $conn->insert_id;
        
        // Log the appeal submission
        error_log("Appeal submitted - ID: $appealID, Type: $appealType, Booking: $bookingID, Hiker: $hikerID");
        
        echo json_encode([
            'success' => true, 
            'message' => ucfirst($appealType) . ' request submitted successfully. Admin will review your request.',
            'appealID' => $appealID
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to submit request. Please try again.']);
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    error_log("Appeal submission error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}

$conn->close();
?>
