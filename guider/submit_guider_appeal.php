<?php
session_start();

// Check if guider is logged in
if (!isset($_SESSION['guiderID'])) {
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

$guiderID = $_SESSION['guiderID'];
$bookingID = $_POST['bookingID'] ?? null;
$appealType = $_POST['appealType'] ?? null;
$reason = $_POST['reason'] ?? '';

// Validate required fields
if (!$bookingID || !$appealType) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Validate appeal type
if (!in_array($appealType, ['cancellation', 'change'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid appeal type']);
    exit;
}

// Database connection
include '../shared/db_connection.php';

try {
    // Debug: Check what bookings exist for this guider
    $debugQuery = "SELECT bookingID, status FROM booking WHERE guiderID = ?";
    $debugStmt = $conn->prepare($debugQuery);
    $debugStmt->bind_param("i", $guiderID);
    $debugStmt->execute();
    $debugResult = $debugStmt->get_result();
    
    $debugBookings = [];
    while ($row = $debugResult->fetch_assoc()) {
        $debugBookings[] = $row;
    }
    $debugStmt->close();
    
    // Log debug info
    error_log("Debug - Guider $guiderID has bookings: " . json_encode($debugBookings));
    error_log("Debug - Looking for booking $bookingID");
    
    // Check if booking belongs to guider and is in a cancellable state
    $checkBookingQuery = "SELECT bookingID, status FROM booking WHERE bookingID = ? AND guiderID = ? AND status IN ('accepted', 'paid')";
    $stmt = $conn->prepare($checkBookingQuery);
    $stmt->bind_param("ii", $bookingID, $guiderID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        // More detailed error message
        $detailQuery = "SELECT bookingID, status FROM booking WHERE bookingID = ? AND guiderID = ?";
        $detailStmt = $conn->prepare($detailQuery);
        $detailStmt->bind_param("ii", $bookingID, $guiderID);
        $detailStmt->execute();
        $detailResult = $detailStmt->get_result();
        
        if ($detailResult->num_rows > 0) {
            $booking = $detailResult->fetch_assoc();
            echo json_encode(['success' => false, 'message' => "Booking found but status is '{$booking['status']}' - only accepted or paid bookings can be cancelled"]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Booking not found or does not belong to you']);
        }
        $detailStmt->close();
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
    
    // Insert appeal request (guiderID set, hikerID NULL)
    $insertAppealQuery = "INSERT INTO appeal (bookingID, hikerID, guiderID, appealType, reason, status) VALUES (?, NULL, ?, ?, ?, 'pending')";
    $stmt = $conn->prepare($insertAppealQuery);
    $stmt->bind_param("iiss", $bookingID, $guiderID, $appealType, $reason);
    
    if ($stmt->execute()) {
        $appealID = $conn->insert_id;
        
        // Log the appeal submission
        error_log("Guider Appeal submitted - ID: $appealID, Type: $appealType, Booking: $bookingID, Guider: $guiderID");
        
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
    error_log("Guider Appeal submission error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}

$conn->close();
?>
