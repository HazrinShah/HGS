<?php
// Start output buffering to catch any stray output
ob_start();

session_start();

// Disable display of errors - only log them
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/process_appeal_errors.log');

// Set JSON header
header('Content-Type: application/json');

// Custom error handler to prevent any output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    return true; // Don't execute PHP's internal error handler
});

// Custom exception handler
set_exception_handler(function($exception) {
    error_log("Uncaught Exception: " . $exception->getMessage());
    ob_end_clean(); // Clear any output
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
    exit();
});

require_once '../shared/db_connection.php';

// Log the request for debugging
error_log("process_appeal.php called - Session data: " . print_r($_SESSION, true));

// dapat JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Log the input
error_log("Input received: " . print_r($input, true));

if (!$input || !isset($input['action'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request - no action specified']);
    exit();
}

$action = $input['action'];

// Actions that hikers can perform
$hikerAllowedActions = ['hiker_chose_refund', 'hiker_chose_change'];

// Check authentication based on action type
if (in_array($action, $hikerAllowedActions)) {
    // Allow hiker session for these actions
    if (!isset($_SESSION['hikerID'])) {
        error_log("Hiker session not found for action: $action");
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized access - please login as hiker. Session: ' . (empty($_SESSION) ? 'empty' : 'exists but no hikerID')]);
        exit();
    }
    error_log("Hiker authenticated: hikerID = " . $_SESSION['hikerID']);
} else {
    // Require admin session for all other actions
    if (!isset($_SESSION['email'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
        exit();
    }
    
    // verify email ni admin ke tak
    $email = $_SESSION['email'];
    $stmt = $conn->prepare("SELECT * FROM admin WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
        exit();
    }
}

$response = ['success' => false, 'message' => 'Unknown action'];

try {
    switch ($action) {
        case 'accept_guider_appeal':
            $response = acceptGuiderAppeal($conn, $input);
            break;
            
        case 'reject_appeal':
            $response = rejectAppeal($conn, $input);
            break;
            
        case 'accept_hiker_appeal':
            $response = acceptHikerAppeal($conn, $input);
            break;
            
        case 'cancel_booking':
            $response = cancelBooking($conn, $input);
            break;
            
        case 'process_refund':
            $response = processRefund($conn, $input);
            break;
            
        case 'change_guider':
            $response = changeGuider($conn, $input);
            break;
            
        case 'get_available_guiders':
            $response = getAvailableGuiders($conn, $input);
            break;
            
        case 'hiker_chose_refund':
            $response = hikerChoseRefund($conn, $input);
            break;
            
        case 'approve_refund':
            $response = approveRefund($conn, $input);
            break;
            
        case 'reject_refund':
            $response = rejectRefund($conn, $input);
            break;
            
        case 'hiker_chose_change':
            $response = hikerChoseChange($conn, $input);
            break;
            
        default:
            $response = ['success' => false, 'message' => 'Invalid action'];
    }
} catch (Exception $e) {
    error_log("Exception caught: " . $e->getMessage());
    $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
}

// Clear any buffered output and send clean JSON
ob_end_clean();
echo json_encode($response);

function acceptGuiderAppeal($conn, $input) {
    error_log("acceptGuiderAppeal called with input: " . print_r($input, true));
    
    if (!isset($input['appealId']) || !isset($input['bookingId'])) {
        error_log("Missing appealId or bookingId in acceptGuiderAppeal");
        return ['success' => false, 'message' => 'Missing appealId or bookingId'];
    }
    
    $appealId = intval($input['appealId']);
    $bookingId = intval($input['bookingId']);
    
    // check kalau ni change request
    $checkStmt = $conn->prepare("SELECT appealType FROM appeal WHERE appealID = ?");
    if (!$checkStmt) {
        error_log("Prepare failed for appealType check: " . $conn->error);
        return ['success' => false, 'message' => 'Database error: ' . $conn->error];
    }
    $checkStmt->bind_param("i", $appealId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    $appeal = $result->fetch_assoc();
    $checkStmt->close();
    
    error_log("Appeal data: " . print_r($appeal, true));
    
    // kalau ni change request, set status jadi 'onhold' supaya button change muncul
    // kalau cancellation request, cancel booking terus
    // kalau bukan, set jadi 'approved'
    $appealType = ($appeal && isset($appeal['appealType'])) ? $appeal['appealType'] : '';
    
    if ($appealType === 'change') {
        $newStatus = 'onhold';
    } else {
        $newStatus = 'approved';
    }
    
    error_log("Setting appeal status to: $newStatus for appealType: $appealType");
    
    // update appeal status
    $stmt = $conn->prepare("UPDATE appeal SET status = ?, updatedAt = NOW() WHERE appealID = ?");
    if (!$stmt) {
        error_log("Prepare failed for appeal update: " . $conn->error);
        return ['success' => false, 'message' => 'Database error: ' . $conn->error];
    }
    $stmt->bind_param("si", $newStatus, $appealId);
    
    if ($stmt->execute()) {
        error_log("Appeal status updated successfully");
        
        // If it's a change request, put booking on hold
        if ($appealType === 'change') {
            $stmt2 = $conn->prepare("UPDATE booking SET status = 'OnHold' WHERE bookingID = ?");
            if ($stmt2) {
                $stmt2->bind_param("i", $bookingId);
                $stmt2->execute();
                error_log("Booking set to OnHold");
            }
        }
        
        // If it's a cancellation request from guider, cancel the booking immediately
        if ($appealType === 'cancellation') {
            $stmt3 = $conn->prepare("UPDATE booking SET status = 'cancelled' WHERE bookingID = ?");
            if ($stmt3) {
                $stmt3->bind_param("i", $bookingId);
                $stmt3->execute();
                error_log("Booking $bookingId cancelled due to approved guider cancellation appeal");
            }
        }
        
        return ['success' => true, 'message' => 'Guider appeal accepted successfully'];
    } else {
        error_log("Failed to update appeal status: " . $stmt->error);
        return ['success' => false, 'message' => 'Failed to accept guider appeal: ' . $stmt->error];
    }
}

function rejectAppeal($conn, $input) {
    $appealId = $input['appealId'];
    
    // update appeal status jadi rejected
    $stmt = $conn->prepare("UPDATE appeal SET status = 'rejected', updatedAt = NOW() WHERE appealID = ?");
    $stmt->bind_param("i", $appealId);
    
    if ($stmt->execute()) {
        // Re-activate the related booking so both parties must attend
        // Align with hiker page which expects booking.status = 'accepted'
        $stmt2 = $conn->prepare("UPDATE booking SET status = 'accepted' WHERE bookingID = (SELECT bookingID FROM appeal WHERE appealID = ?)");
        $stmt2->bind_param("i", $appealId);
        $stmt2->execute();

        return ['success' => true, 'message' => 'Appeal rejected successfully'];
    } else {
        return ['success' => false, 'message' => 'Failed to reject appeal'];
    }
}

function acceptHikerAppeal($conn, $input) {
    $appealId = $input['appealId'];
    $bookingId = $input['bookingId'];
    
    // check kalau ni change request
    $checkStmt = $conn->prepare("SELECT appealType FROM appeal WHERE appealID = ?");
    $checkStmt->bind_param("i", $appealId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    $appeal = $result->fetch_assoc();
    $checkStmt->close();
    
    // kalau ni change request, set status jadi 'onhold' supaya button change muncul
    // kalau bukan, set jadi 'approved' untuk cancellation/refund requests
    $newStatus = ($appeal && isset($appeal['appealType']) && $appeal['appealType'] === 'change') ? 'onhold' : 'approved';
    
    // update appeal status
    $stmt = $conn->prepare("UPDATE appeal SET status = ?, updatedAt = NOW() WHERE appealID = ?");
    $stmt->bind_param("si", $newStatus, $appealId);
    
    if ($stmt->execute()) {
        // Put the booking on hold so it won't appear as an active booking
        $stmt2 = $conn->prepare("UPDATE booking SET status = 'OnHold' WHERE bookingID = ?");
        $stmt2->bind_param("i", $bookingId);
        $stmt2->execute();

        return ['success' => true, 'message' => 'Appeal approved. You can now process refund or change guider.'];
    } else {
        return ['success' => false, 'message' => 'Failed to accept hiker appeal'];
    }
}

function cancelBooking($conn, $input) {
    $bookingId = $input['bookingId'];
    
    // Update booking status to Cancelled
    $stmt = $conn->prepare("UPDATE booking SET status = 'Cancelled' WHERE bookingID = ?");
    $stmt->bind_param("i", $bookingId);
    
    if ($stmt->execute()) {
        // Also update related appeal status to resolved
        $stmt2 = $conn->prepare("UPDATE appeal SET status = 'resolved', updatedAt = NOW() WHERE bookingID = ?");
        $stmt2->bind_param("i", $bookingId);
        $stmt2->execute();
        
        return ['success' => true, 'message' => 'Booking cancelled successfully'];
    } else {
        return ['success' => false, 'message' => 'Failed to cancel booking'];
    }
}

function processRefund($conn, $input) {
    $appealId = $input['appealId'];
    $bookingId = $input['bookingId'];
    
    // Update booking status to cancelled (standardized lowercase)
    $stmt = $conn->prepare("UPDATE booking SET status = 'cancelled' WHERE bookingID = ?");
    $stmt->bind_param("i", $bookingId);
    
    if ($stmt->execute()) {
        // Update appeal status to refunded (aligned with hiker flow)
        $stmt2 = $conn->prepare("UPDATE appeal SET status = 'refunded', updatedAt = NOW() WHERE appealID = ?");
        $stmt2->bind_param("i", $appealId);
        $stmt2->execute();
        
        // TODO: Add refund processing logic here
        // This could involve updating payment records, sending notifications, etc.
        
        return ['success' => true, 'message' => 'Refund process initiated successfully'];
    } else {
        return ['success' => false, 'message' => 'Failed to process refund'];
    }
}

function changeGuider($conn, $input) {
    $appealId = $input['appealId'];
    $bookingId = $input['bookingId'];
    $newGuiderId = $input['newGuiderId'];
    $stmt = $conn->prepare("UPDATE booking SET guiderID = ? WHERE bookingID = ?");
    $stmt->bind_param("ii", $newGuiderId, $bookingId);
    
    if ($stmt->execute()) {
        // Update appeal status to resolved and reactivate booking
        $stmt2 = $conn->prepare("UPDATE appeal SET status = 'resolved', updatedAt = NOW() WHERE appealID = ?");
        $stmt2->bind_param("i", $appealId);
        $stmt2->execute();

        // Set booking back to accepted so it resumes normally
        $stmt3 = $conn->prepare("UPDATE booking SET status = 'accepted' WHERE bookingID = ?");
        $stmt3->bind_param("i", $bookingId);
        $stmt3->execute();
        
        return ['success' => true, 'message' => 'Guider changed successfully'];
    } else {
        return ['success' => false, 'message' => 'Failed to change guider. Please try again later.'];
    }
}

function getAvailableGuiders($conn, $input) {
    $bookingId = $input['bookingId'];
    
    // Get booking dates
    $stmt = $conn->prepare("SELECT startDate, endDate FROM booking WHERE bookingID = ?");
    $stmt->bind_param("i", $bookingId);
    $stmt->execute();
    $result = $stmt->get_result();
    $booking = $result->fetch_assoc();
    
    if (!$booking) {
        return ['success' => false, 'message' => 'Booking not found'];
    }
    
    $startDate = $booking['startDate'];
    $endDate = $booking['endDate'];
    
    // Get available guiders (active, not already booked during these dates in active statuses)
    $query = "SELECT g.guiderID, g.username, g.email, g.phone_number AS phoneNumber, g.experience, g.skills
              FROM guider g 
              WHERE g.status = 'Active' 
              AND g.guiderID NOT IN (
                  SELECT DISTINCT b2.guiderID 
                  FROM booking b2 
                  WHERE b2.status IN ('accepted','OnHold','Confirmed','confirmed','Pending','pending') 
                  AND (
                      (b2.startDate <= ? AND b2.endDate >= ?) OR
                      (b2.startDate <= ? AND b2.endDate >= ?) OR
                      (b2.startDate >= ? AND b2.endDate <= ?)
                  )
              )
              ORDER BY g.username";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ssssss", $endDate, $startDate, $endDate, $startDate, $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();
    $guiders = $result->fetch_all(MYSQLI_ASSOC);
    
    return ['success' => true, 'guiders' => $guiders];
}

function hikerChoseRefund($conn, $input) {
    $appealId = $input['appealId'];
    $bookingId = $input['bookingId'];
    
    // Debug logging
    error_log("hikerChoseRefund called - appealId: $appealId, bookingId: $bookingId");
    
    // Set appeal to pending_refund - waiting for admin approval
    $stmt = $conn->prepare("UPDATE appeal SET status = 'pending_refund', updatedAt = NOW() WHERE appealID = ?");
    $stmt->bind_param("i", $appealId);
    
    if ($stmt->execute()) {
        $affectedRows = $stmt->affected_rows;
        error_log("hikerChoseRefund success - affected rows: $affectedRows");
        return ['success' => true, 'message' => 'Refund request submitted. Waiting for admin approval.', 'affectedRows' => $affectedRows];
    } else {
        error_log("hikerChoseRefund failed - error: " . $stmt->error);
        return ['success' => false, 'message' => 'Failed to submit refund request: ' . $stmt->error];
    }
}

function hikerChoseChange($conn, $input) {
    $appealId = $input['appealId'];
    $bookingId = $input['bookingId'];
    
    // Mark appeal as change requested; keep status onhold for admin to assign guider
    $stmt = $conn->prepare("UPDATE appeal SET appealType = 'change', status = 'onhold', updatedAt = NOW() WHERE appealID = ?");
    $stmt->bind_param("i", $appealId);
    
    if ($stmt->execute()) {
        return ['success' => true, 'message' => 'Hiker chose to change guider. Admin can now assign a new guider.'];
    } else {
        return ['success' => false, 'message' => 'Failed to update appeal status'];
    }
}

function approveRefund($conn, $input) {
    error_log("approveRefund called with input: " . print_r($input, true));
    
    if (!isset($input['appealId']) || !isset($input['bookingId'])) {
        error_log("Missing appealId or bookingId");
        return ['success' => false, 'message' => 'Missing appealId or bookingId'];
    }
    
    $appealId = intval($input['appealId']);
    $bookingId = intval($input['bookingId']);
    
    error_log("Processing: appealId=$appealId, bookingId=$bookingId");
    
    // Cancel the booking
    $stmt = $conn->prepare("UPDATE booking SET status = 'Cancelled' WHERE bookingID = ?");
    if (!$stmt) {
        error_log("Prepare failed for booking update: " . $conn->error);
        return ['success' => false, 'message' => 'Database prepare error: ' . $conn->error];
    }
    $stmt->bind_param("i", $bookingId);
    
    if ($stmt->execute()) {
        error_log("Booking updated successfully, affected rows: " . $stmt->affected_rows);
        
        // Update appeal status to refunded
        $stmt2 = $conn->prepare("UPDATE appeal SET status = 'refunded', updatedAt = NOW() WHERE appealID = ?");
        if (!$stmt2) {
            error_log("Prepare failed for appeal update: " . $conn->error);
            return ['success' => false, 'message' => 'Database prepare error for appeal: ' . $conn->error];
        }
        $stmt2->bind_param("i", $appealId);
        
        if ($stmt2->execute()) {
            error_log("Appeal updated successfully, affected rows: " . $stmt2->affected_rows);
            return ['success' => true, 'message' => 'Refund approved. Payment will be processed within 3 working days.'];
        } else {
            error_log("Appeal update failed: " . $stmt2->error);
            return ['success' => false, 'message' => 'Failed to update appeal: ' . $stmt2->error];
        }
    } else {
        error_log("Booking update failed: " . $stmt->error);
        return ['success' => false, 'message' => 'Failed to approve refund: ' . $stmt->error];
    }
}

function rejectRefund($conn, $input) {
    error_log("rejectRefund called with input: " . print_r($input, true));
    
    if (!isset($input['appealId']) || !isset($input['bookingId'])) {
        error_log("Missing appealId or bookingId");
        return ['success' => false, 'message' => 'Missing appealId or bookingId'];
    }
    
    $appealId = intval($input['appealId']);
    $bookingId = intval($input['bookingId']);
    
    // Cancel the booking without refund
    $stmt = $conn->prepare("UPDATE booking SET status = 'Cancelled' WHERE bookingID = ?");
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        return ['success' => false, 'message' => 'Database prepare error'];
    }
    $stmt->bind_param("i", $bookingId);
    
    if ($stmt->execute()) {
        // Update appeal status to rejected
        $stmt2 = $conn->prepare("UPDATE appeal SET status = 'refund_rejected', updatedAt = NOW() WHERE appealID = ?");
        if (!$stmt2) {
            error_log("Prepare failed for appeal: " . $conn->error);
            return ['success' => false, 'message' => 'Database prepare error for appeal'];
        }
        $stmt2->bind_param("i", $appealId);
        
        if ($stmt2->execute()) {
            return ['success' => true, 'message' => 'Refund rejected. Booking has been cancelled without refund.'];
        } else {
            error_log("Appeal update failed: " . $stmt2->error);
            return ['success' => false, 'message' => 'Failed to update appeal'];
        }
    } else {
        error_log("Booking update failed: " . $stmt->error);
        return ['success' => false, 'message' => 'Failed to reject refund'];
    }
}
?>
