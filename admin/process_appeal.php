<?php
session_start();

// Check if admin is logged in
if (!isset($_SESSION['email'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

require_once '../shared/db_connection.php';

// Verify the email belongs to an admin
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

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['action'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

$action = $input['action'];
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
            
        case 'hiker_chose_change':
            $response = hikerChoseChange($conn, $input);
            break;
            
        default:
            $response = ['success' => false, 'message' => 'Invalid action'];
    }
} catch (Exception $e) {
    $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
}

echo json_encode($response);

function acceptGuiderAppeal($conn, $input) {
    $appealId = $input['appealId'];
    $bookingId = $input['bookingId'];
    
    // Update appeal status to approved
    $stmt = $conn->prepare("UPDATE appeal SET status = 'approved', updatedAt = NOW() WHERE appealID = ?");
    $stmt->bind_param("i", $appealId);
    
    if ($stmt->execute()) {
        return ['success' => true, 'message' => 'Guider appeal accepted successfully'];
    } else {
        return ['success' => false, 'message' => 'Failed to accept guider appeal'];
    }
}

function rejectAppeal($conn, $input) {
    $appealId = $input['appealId'];
    
    // Update appeal status to rejected
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
    
    // Mark appeal as approved so admin card shows refund/change actions
    $stmt = $conn->prepare("UPDATE appeal SET status = 'approved', updatedAt = NOW() WHERE appealID = ?");
    $stmt->bind_param("i", $appealId);
    
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
    
    // Immediately mark booking cancelled and appeal refunded
    $stmt = $conn->prepare("UPDATE booking SET status = 'cancelled' WHERE bookingID = ?");
    $stmt->bind_param("i", $bookingId);
    
    if ($stmt->execute()) {
        $stmt2 = $conn->prepare("UPDATE appeal SET status = 'refunded', updatedAt = NOW() WHERE appealID = ?");
        $stmt2->bind_param("i", $appealId);
        $stmt2->execute();
        
        return ['success' => true, 'message' => 'Refund confirmed. Payment will be processed within 3 working days.'];
    } else {
        return ['success' => false, 'message' => 'Failed to cancel booking for refund'];
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
?>
