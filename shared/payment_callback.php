<?php
// ToyyibPay Payment Callback Handler
// This file receives payment status updates from ToyyibPay

// Log the callback for debugging
error_log("ToyyibPay Callback Received: " . json_encode($_POST));

// Database connection
include 'db_connection.php';

// Get callback data from ToyyibPay
$billcode = $_POST['billcode'] ?? '';
$status = $_POST['status'] ?? '';
$order_id = $_POST['order_id'] ?? '';

// Validate required parameters
if (empty($billcode) || empty($status)) {
    error_log("ToyyibPay Callback: Missing required parameters");
    http_response_code(400);
    exit('Missing required parameters');
}

// Update payment transaction status
$updateQuery = "UPDATE payment_transactions SET 
                status = CASE 
                    WHEN ? = '1' THEN 'completed'
                    WHEN ? = '2' THEN 'failed'
                    ELSE 'pending'
                END,
                callbackData = ?,
                updatedAt = NOW()
                WHERE billCode = ?";

$callbackData = json_encode($_POST);
$stmt = $conn->prepare($updateQuery);
$stmt->bind_param("ssss", $status, $status, $callbackData, $billcode);
$stmt->execute();

if ($stmt->affected_rows > 0) {
    // If payment was successful, update booking status
    if ($status == '1') {
        // Get booking ID from payment transaction
        $getBookingQuery = "SELECT bookingID FROM payment_transactions WHERE billCode = ?";
        $stmt2 = $conn->prepare($getBookingQuery);
        $stmt2->bind_param("s", $billcode);
        $stmt2->execute();
        $result = $stmt2->get_result();
        $paymentData = $result->fetch_assoc();
        $stmt2->close();
        
        if ($paymentData) {
            $bookingID = $paymentData['bookingID'];
            
            // Update booking status to accepted (payment completed)
            $updateBookingQuery = "UPDATE booking SET status = 'accepted' WHERE bookingID = ?";
            $stmt3 = $conn->prepare($updateBookingQuery);
            $stmt3->bind_param("i", $bookingID);
            $stmt3->execute();
            $stmt3->close();
            
            error_log("Booking $bookingID status updated to accepted");
        }
    }
    
    error_log("Payment transaction updated successfully for billcode: $billcode");
    http_response_code(200);
    echo 'OK';
} else {
    error_log("No payment transaction found for billcode: $billcode");
    http_response_code(404);
    echo 'Transaction not found';
}

$stmt->close();
$conn->close();
?>
