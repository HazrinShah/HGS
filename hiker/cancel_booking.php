<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['hikerID'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$hikerID = (int)$_SESSION['hikerID'];

// Check if bookingID is provided
if (!isset($_POST['bookingID']) || empty($_POST['bookingID'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Booking ID is required']);
    exit;
}

$bookingID = (int)$_POST['bookingID'];

// Database connection
include '../shared/db_connection.php';

try {
    // Fetch booking info
    $bq = $conn->prepare("SELECT bookingID, hikerID, groupType, status, totalHiker FROM booking WHERE bookingID = ? LIMIT 1");
    $bq->bind_param('i', $bookingID);
    $bq->execute();
    $booking = $bq->get_result()->fetch_assoc();
    $bq->close();

    if (!$booking) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found']);
        exit;
    }

    if ($booking['status'] !== 'pending') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Only pending bookings can be cancelled']);
        exit;
    }

    if ($booking['groupType'] === 'open') {
        // Open group: user cancels their participation only
        // Confirm the user has a participant row
        $qp = $conn->prepare("SELECT qty FROM bookingParticipant WHERE bookingID = ? AND hikerID = ? LIMIT 1");
        $qp->bind_param('ii', $bookingID, $hikerID);
        $qp->execute();
        $prow = $qp->get_result()->fetch_assoc();
        $qp->close();

        if (!$prow) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'You are not a participant of this booking']);
            exit;
        }

        $qty = (int)$prow['qty'];
        if ($qty <= 0) { $qty = 0; }

        $conn->begin_transaction();
        try {
            // Remove participant row
            $delP = $conn->prepare("DELETE FROM bookingParticipant WHERE bookingID = ? AND hikerID = ?");
            $delP->bind_param('ii', $bookingID, $hikerID);
            $delP->execute();
            $delP->close();

            if ($qty > 0) {
                // Decrement group totalHiker safely
                $updB = $conn->prepare("UPDATE booking SET totalHiker = GREATEST(0, totalHiker - ?) WHERE bookingID = ?");
                $updB->bind_param('ii', $qty, $bookingID);
                $updB->execute();
                $updB->close();
            }

            // If no participants remain, delete the booking
            $chk = $conn->prepare("SELECT COALESCE(SUM(qty),0) AS sumQty FROM bookingParticipant WHERE bookingID = ?");
            $chk->bind_param('i', $bookingID);
            $chk->execute();
            $sum = $chk->get_result()->fetch_assoc();
            $chk->close();
            $sumQty = (int)($sum['sumQty'] ?? 0);

            if ($sumQty <= 0) {
                // No participants -> delete the booking row entirely (regardless of owner)
                $delB = $conn->prepare("DELETE FROM booking WHERE bookingID = ? AND status = 'pending'");
                $delB->bind_param('i', $bookingID);
                $delB->execute();
                $delB->close();
            }

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Cancelled successfully']);
            exit;
        } catch (Throwable $tx) {
            $conn->rollback();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to cancel booking']);
            exit;
        }
    } else {
        // Close group: only owner can cancel their own pending booking
        if ((int)$booking['hikerID'] !== $hikerID) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You do not own this booking']);
            exit;
        }

        $del = $conn->prepare("DELETE FROM booking WHERE bookingID = ? AND hikerID = ? AND status = 'pending'");
        $del->bind_param('ii', $bookingID, $hikerID);
        if ($del->execute()) {
            echo json_encode(['success' => true, 'message' => 'Cancelled successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to cancel booking']);
        }
        $del->close();
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred while cancelling the booking']);
}

$conn->close();
?>
