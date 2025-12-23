<?php
// TEMPORARY DEBUG - Remove after fixing
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// check user dah login ke tak
if (!isset($_SESSION['hikerID'])) {
    header("Location: HLogin.html");
    exit;
}

$hikerID = $_SESSION['hikerID'];

// success message
$success_message = '';
$info_message = '';
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'cod_booking_confirmed':
            $success_message = 'Your COD booking has been confirmed! You can pay when you meet your guider.';
            break;
        case 'booking_completed':
            $success_message = 'Booking marked as completed successfully!';
            break;
    }
}

if (isset($_GET['info'])) {
    switch ($_GET['info']) {
        case 'payment_already_chosen':
            $info_message = 'Payment method has already been chosen for this booking.';
            break;
    }
}


include '../shared/db_connection.php';

// Ensure table exists with lowercase name (case-sensitive on Linux)
$conn->query("CREATE TABLE IF NOT EXISTS bookinghikerdetails (
    hikerDetailID INT AUTO_INCREMENT PRIMARY KEY,
    bookingID INT NOT NULL,
    hikerName VARCHAR(255) NOT NULL,
    identityCard VARCHAR(50) NOT NULL,
    address TEXT NOT NULL,
    phoneNumber VARCHAR(20) NOT NULL,
    emergencyContactName VARCHAR(255) NOT NULL,
    emergencyContactNumber VARCHAR(20) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bookingID (bookingID)
) ENGINE=InnoDB");

function getHikerDetails($conn, $bookingID) {
    $stmt = $conn->prepare("
        SELECT hikerName, identityCard, address, phoneNumber, emergencyContactName, emergencyContactNumber
        FROM bookinghikerdetails
        WHERE bookingID = ?
        ORDER BY hikerDetailID ASC
    ");
    if (!$stmt) {
        error_log("getHikerDetails prepare failed: " . $conn->error);
        return [];
    }
    $stmt->bind_param("i", $bookingID);
    $stmt->execute();
    $result = $stmt->get_result();
    $details = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $details;
}

// fetch booking dengan appeal yang complete atau pending. appeal la senang cakap
// include: 1) close group bookings (hiker owner), 2) open group owner, 3) open group participant
$bookingQuery = "SELECT DISTINCT b.*, g.username as guiderName, g.price as guiderPrice, g.phone_number as guiderPhone, 
                 g.profile_picture as guiderPicture, g.email as guiderEmail, g.experience as guiderExperience,
                 m.name as mountainName, m.picture as mountainPicture, m.latitude as mountainLatitude, m.longitude as mountainLongitude,
                 bp.qty AS participantQty,
                 CASE WHEN b.hikerID = ? THEN 1 ELSE 0 END as isOwner
                 FROM booking b 
                 JOIN guider g ON b.guiderID = g.guiderID 
                 JOIN mountain m ON b.mountainID = m.mountainID 
                 LEFT JOIN bookingparticipant bp ON bp.bookingID = b.bookingID AND bp.hikerID = ?
                 WHERE b.status = 'accepted' 
                   AND (
                     b.hikerID = ?
                     OR
                     (b.groupType = 'open' AND bp.hikerID IS NOT NULL)
                   )
                 AND b.bookingID NOT IN (
                     SELECT DISTINCT bookingID FROM appeal 
                     WHERE status = 'pending'
                 )
                 ORDER BY b.created_at DESC";
$stmt = $conn->prepare($bookingQuery);
$stmt->bind_param("iii", $hikerID, $hikerID, $hikerID);
$stmt->execute();
$result = $stmt->get_result();
$acceptedBookings = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// fetch appeal yang baru, 2 minit je visible passtu ilang
$recentAppeals = [];
$recentAppealsQuery = "SELECT a.*, b.startDate, b.endDate, g.username as guiderName, m.name as mountainName
                       FROM appeal a
                       JOIN booking b ON a.bookingID = b.bookingID
                       JOIN guider g ON b.guiderID = g.guiderID
                       JOIN mountain m ON b.mountainID = m.mountainID
                       WHERE b.hikerID = ?
                         AND a.status IN ('cancelled','refunded','resolved','rejected')
                         AND a.updatedAt >= (NOW() - INTERVAL 2 MINUTE)
                       ORDER BY a.updatedAt DESC";
$stmt = $conn->prepare($recentAppealsQuery);
$stmt->bind_param("i", $hikerID);
$stmt->execute();
$result = $stmt->get_result();
$recentAppeals = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ambil appeals related dengan booking hiker ni (both hiker dan guider appeals)
$hikerAppeals = [];
if ($hikerID) {
    $appealStmt = $conn->prepare("
        SELECT 
            a.appealID,
            a.bookingID,
            a.hikerID,
            a.guiderID,
            a.appealType,
            a.reason,
            a.status,
            a.createdAt,
            b.startDate,
            b.endDate,
            g.username AS guiderName,
            g.profile_picture AS guiderPicture,
            m.name AS location,
            CASE 
                WHEN a.hikerID IS NOT NULL AND a.guiderID IS NULL THEN 'hiker'
                WHEN a.guiderID IS NOT NULL AND a.hikerID IS NULL THEN 'guider'
                ELSE 'unknown'
            END AS appealFrom
        FROM appeal a
        JOIN booking b ON a.bookingID = b.bookingID
        JOIN guider g ON b.guiderID = g.guiderID
        JOIN mountain m ON b.mountainID = m.mountainID
        WHERE b.hikerID = ?
        ORDER BY a.createdAt DESC
    ");
    $appealStmt->bind_param("i", $hikerID);
    $appealStmt->execute();
    $appealResult = $appealStmt->get_result();
    $hikerAppeals = $appealResult->fetch_all(MYSQLI_ASSOC);
    $appealStmt->close();
}

// Fetch pending appeals for the current user (both hiker and guider appeals related to hiker's bookings)
$appealQuery = "SELECT a.*, b.startDate, b.endDate, b.price, b.hikerID as bookingHikerID, g.username as guiderName, g.profile_picture as guiderPicture, m.name as mountainName,
                CASE 
                    WHEN a.hikerID IS NOT NULL AND a.guiderID IS NULL THEN 'hiker'
                    WHEN a.guiderID IS NOT NULL AND a.hikerID IS NULL THEN 'guider'
                    ELSE 'unknown'
                END AS appealFrom
                FROM appeal a 
                JOIN booking b ON a.bookingID = b.bookingID 
                JOIN guider g ON b.guiderID = g.guiderID 
                JOIN mountain m ON b.mountainID = m.mountainID 
                WHERE (b.hikerID = ? OR a.hikerID = ?) AND a.status IN ('pending', 'pending_refund', 'approved', 'onhold')
                ORDER BY a.createdAt DESC";
$stmt = $conn->prepare($appealQuery);
$stmt->bind_param("ii", $hikerID, $hikerID);
$stmt->execute();
$result = $stmt->get_result();
$pendingAppeals = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch processed appeals for the current user (approved/rejected/refunded/pending_refund)
$processedAppealQuery = "SELECT a.*, b.startDate, b.endDate, b.price, g.username as guiderName, g.profile_picture as guiderPicture, m.name as mountainName,
                        CASE 
                            WHEN a.hikerID IS NOT NULL AND a.guiderID IS NULL THEN 'hiker'
                            WHEN a.guiderID IS NOT NULL AND a.hikerID IS NULL THEN 'guider'
                            ELSE 'unknown'
                        END AS appealFrom
                        FROM appeal a 
                        JOIN booking b ON a.bookingID = b.bookingID 
                        JOIN guider g ON b.guiderID = g.guiderID 
                        JOIN mountain m ON b.mountainID = m.mountainID 
                        WHERE b.hikerID = ? AND a.status IN ('approved', 'rejected', 'refunded', 'resolved', 'pending_refund', 'refund_rejected')
                        ORDER BY a.updatedAt DESC
                        LIMIT 10";
$stmt = $conn->prepare($processedAppealQuery);
$stmt->bind_param("i", $hikerID);
$stmt->execute();
$result = $stmt->get_result();
$processedAppeals = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch appeals awaiting hiker choice 
$awaitingQuery = "SELECT a.appealID, a.bookingID, a.createdAt, b.startDate, b.endDate, m.name as mountainName
                  FROM appeal a
                  JOIN booking b ON a.bookingID = b.bookingID
                  JOIN mountain m ON b.mountainID = m.mountainID
                  WHERE a.hikerID = ? AND a.status = 'onhold' AND a.appealType = 'cancellation'
                  ORDER BY a.createdAt DESC";
$stmt = $conn->prepare($awaitingQuery);
$stmt->bind_param("i", $hikerID);
$stmt->execute();
$result = $stmt->get_result();
$awaitingAppeals = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Handle success messages from URL parameters
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'cancellation':
            // Verify pending cancellation
            if (isset($_GET['bookingID']) && ctype_digit((string)$_GET['bookingID'])) {
                $bookingIDVerify = (int) $_GET['bookingID'];
                $verifySql = "SELECT 1 
                              FROM appeal a 
                              JOIN booking b ON a.bookingID = b.bookingID 
                              WHERE b.hikerID = ? AND a.bookingID = ? AND a.appealType = 'cancellation' AND a.status = 'pending' 
                              LIMIT 1";
                if ($stmt = $conn->prepare($verifySql)) {
                    $stmt->bind_param("ii", $hikerID, $bookingIDVerify);
                    $stmt->execute();
                    $stmt->store_result();
                    if ($stmt->num_rows > 0) {
                        $success = "Cancellation request submitted. Admin will review your request.";
                    }
                    $stmt->close();
                }
            } else {
                $verifySql = "SELECT 1 
                              FROM appeal a 
                              JOIN booking b ON a.bookingID = b.bookingID 
                              WHERE b.hikerID = ? AND a.appealType = 'cancellation' AND a.status = 'pending' 
                              LIMIT 1";
                if ($stmt = $conn->prepare($verifySql)) {
                    $stmt->bind_param("i", $hikerID);
                    $stmt->execute();
                    $stmt->store_result();
                    if ($stmt->num_rows > 0) {
                        $success = "Cancellation request submitted. Admin will review your request.";
                    }
                    $stmt->close();
                }
            }
            break;
        case 'refund':
            $success = "Refund request submitted successfully! Your booking has been automatically cancelled. Admin will process your refund within 1-3 working days.";
            break;
        case 'change':
            $success = "Change request submitted. Admin will assign a new guider and notify you via email.";
            break;
    }
}

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Debug logging
    error_log("POST data received: " . json_encode($_POST));
    
    if (isset($_POST['action'])) {
        $bookingID = intval($_POST['bookingID']);
        $action = $_POST['action'];
        
        error_log("Processing action: $action for bookingID: $bookingID");
        
        switch ($action) {
            case 'done':
                // Update booking status to completed and redirect to rate/review
                // First check if user is authorized (either owner or participant in open group)
                $authCheckQuery = "SELECT b.bookingID, b.groupType, b.hikerID as ownerID 
                                   FROM booking b 
                                   LEFT JOIN bookingparticipant bp ON bp.bookingID = b.bookingID AND bp.hikerID = ?
                                   WHERE b.bookingID = ? 
                                   AND (b.hikerID = ? OR (b.groupType = 'open' AND bp.hikerID IS NOT NULL))";
                $authStmt = $conn->prepare($authCheckQuery);
                $authStmt->bind_param("iii", $hikerID, $bookingID, $hikerID);
                $authStmt->execute();
                $authResult = $authStmt->get_result();
                
                if ($authResult->num_rows > 0) {
                    $bookingInfo = $authResult->fetch_assoc();
                    $authStmt->close();
                    
                    // Only the booking owner can mark as completed
                    // For open group, we still need the owner to mark it done
                    // But participants should be able to go to review page
                    if ($bookingInfo['ownerID'] == $hikerID) {
                        // User is the owner - can update status
                        $updateQuery = "UPDATE booking SET status = 'completed' WHERE bookingID = ?";
                        $updateStmt = $conn->prepare($updateQuery);
                        $updateStmt->bind_param("i", $bookingID);
                        if ($updateStmt->execute()) {
                            error_log("Booking $bookingID marked as completed by owner hikerID: $hikerID");
                            header("Location: HRateReview.php?bookingID=" . $bookingID);
                            exit;
                        }
                    } else {
                        // User is participant in open group - check if already completed
                        $statusCheck = $conn->prepare("SELECT status FROM booking WHERE bookingID = ?");
                        $statusCheck->bind_param("i", $bookingID);
                        $statusCheck->execute();
                        $statusResult = $statusCheck->get_result();
                        $currentStatus = $statusResult->fetch_assoc();
                        $statusCheck->close();
                        
                        if ($currentStatus && $currentStatus['status'] === 'completed') {
                            // Already completed, just redirect to review
                            header("Location: HRateReview.php?bookingID=" . $bookingID);
                            exit;
                        } else {
                            // Not completed yet - participant cannot mark as done, only owner can
                            $error = "Only the booking owner can mark this trip as done.";
                            error_log("Participant hikerID $hikerID tried to mark open group booking $bookingID as done - denied");
                        }
                    }
                } else {
                    $authStmt->close();
                    $error = "You are not authorized to mark this booking as done.";
                    error_log("Unauthorized mark as done attempt - bookingID: $bookingID, hikerID: $hikerID");
                }
                break;
                
            case 'cancel':
                // Store cancellation request in appeal table
                $reason = $_POST['reason'] ?? '';
                $details = $_POST['details'] ?? '';
                $fullReason = "Reason: " . $reason . "\nDetails: " . $details;
                
                $appealQuery = "INSERT INTO appeal (bookingID, hikerID, appealType, reason, status) 
                               VALUES (?, ?, 'cancellation', ?, 'pending')";
                $appealStmt = $conn->prepare($appealQuery);
                $appealStmt->bind_param("iis", $bookingID, $hikerID, $fullReason);
                if ($appealStmt->execute()) {
                    error_log("Cancellation appeal submitted - BookingID: $bookingID, HikerID: $hikerID");
                    header("Location: HYourGuider.php?success=cancellation&bookingID=" . $bookingID);
                    exit;
                } else {
                    $error = "Failed to submit cancellation request: " . $appealStmt->error;
                    error_log("Cancellation appeal failed - " . $appealStmt->error);
                }
                $appealStmt->close();
                break;
                
            case 'refund':
                // Start transaction for refund process (auto-cancel booking)
                $conn->begin_transaction();
                
                try {
                    // 1. Create appeal record for refund
                    $appealQuery = "INSERT INTO appeal (bookingID, hikerID, appealType, reason, status) 
                                   VALUES (?, ?, 'refund', 'User requested refund - booking automatically cancelled', 'pending')";
                    $appealStmt = $conn->prepare($appealQuery);
                    $appealStmt->bind_param("ii", $bookingID, $hikerID);
                    $appealStmt->execute();
                    $appealStmt->close();
                    
                    // 2. Automatically cancel the booking
                    $cancelQuery = "UPDATE booking SET status = 'cancelled' WHERE bookingID = ? AND hikerID = ?";
                    $cancelStmt = $conn->prepare($cancelQuery);
                    $cancelStmt->bind_param("ii", $bookingID, $hikerID);
                    $cancelStmt->execute();
                    $cancelStmt->close();
                    
                    // 3. Commit transaction
                    $conn->commit();
                    
                    error_log("Refund request submitted and booking automatically cancelled - BookingID: $bookingID, HikerID: $hikerID");
                    header("Location: HYourGuider.php?success=refund");
                    exit;
                    
                } catch (Exception $e) {
                    // Rollback transaction on error
                    $conn->rollback();
                    $error = "Failed to process refund request: " . $e->getMessage();
                    error_log("Refund process failed - " . $e->getMessage());
                }
                break;
                
            case 'change':
                // Store change request in appeal table
                $appealQuery = "INSERT INTO appeal (bookingID, hikerID, appealType, reason, status) 
                               VALUES (?, ?, 'change', 'User requested change of guider for booking', 'pending')";
                $appealStmt = $conn->prepare($appealQuery);
                $appealStmt->bind_param("ii", $bookingID, $hikerID);
                if ($appealStmt->execute()) {
                    // Send email notification to admin
                    $adminEmail = "hikingguidancesystem@gmail.com";
                    $subject = "New Guider Change Request - Booking ID: $bookingID";
                    $message = "A user has requested to change their guider for booking ID: $bookingID.\n\n";
                    $message .= "Please log in to the admin panel to review and assign a new guider.\n\n";
                    $message .= "Booking Details:\n";
                    $message .= "- Booking ID: $bookingID\n";
                    $message .= "- Hiker ID: $hikerID\n";
                    $message .= "- Request Type: Change Guider\n";
                    $message .= "- Status: Pending\n\n";
                    $message .= "Please process this request as soon as possible.";
                    
                    $headers = "From: noreply@hgs.com\r\n";
                    $headers .= "Reply-To: noreply@hgs.com\r\n";
                    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                    
                    try {
                        ini_set('SMTP', 'localhost');
                        ini_set('smtp_port', '25');
                        ini_set('sendmail_from', 'noreply@hgs.com');
                        
                        if (mail($adminEmail, $subject, $message, $headers)) {
                            error_log("Change request email sent to admin successfully");
                        } else {
                            error_log("Failed to send change request email to admin");
                        }
                    } catch (Exception $e) {
                        error_log("Email sending error for change request: " . $e->getMessage());
                    }
                    
                    error_log("Change appeal submitted - BookingID: $bookingID, HikerID: $hikerID");
                    header("Location: HYourGuider.php?success=change");
                    exit;
                } else {
                    $error = "Failed to submit change request: " . $appealStmt->error;
                    error_log("Change appeal failed - " . $appealStmt->error);
                }
                $appealStmt->close();
                break;
        }
    }
}

// Don't close connection here - it's needed later in the HTML output for getHikerDetails()
// $conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Your Guider - Hiking Guidance System</title>

  <!-- Bootstrap & FontAwesome -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.3.0/css/all.min.css" />
  <link rel="stylesheet" href="../css/style.css" />
  <style>
    /* Guider Blue Color Scheme - Matching HPayment */
    :root {
      --guider-blue: #1e40af;
      --guider-blue-light: #3b82f6;
      --guider-blue-dark: #1e3a8a;
      --guider-blue-accent: #60a5fa;
      --guider-blue-soft: #dbeafe;
      --primary: var(--guider-blue);
      --accent: var(--guider-blue-light);
      --soft-bg: #f8fafc;
      --card-white: #ffffff;
    }

    body {
      font-family: "Montserrat", sans-serif;
      background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
      min-height: 100vh;
    }

    .navbar {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue)) !important;
      box-shadow: 0 4px 20px rgba(30, 64, 175, 0.3);
    }

    .navbar-toggler-icon {
      background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='white' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
    }

    .logo {
      width: 45px;
      border-radius: 50%;
      transition: transform 0.3s ease;
    }

    .logo:hover {
      transform: scale(1.05);
    }

    .navbar-title {
      font-weight: 700;
      font-size: 1.2rem;
      margin: 0;
    }

    
    .main-content {
      padding: 2rem 0;
      min-height: 100vh;
    }

    .page-header {
      text-align: center;
      margin-bottom: 3rem;
    }

    .page-title {
      font-size: 2.5rem;
      font-weight: 700;
      color: var(--guider-blue-dark);
      margin-bottom: 0.5rem;
    }

    .page-subtitle {
      font-size: 1.1rem;
      color: #6b7280;
      font-weight: 400;
    }

    /* Section Title */
    .section-title {
      font-size: 1.5rem;
      font-weight: 600;
      color: var(--guider-blue-dark);
      margin-bottom: 1.5rem;
      display: flex;
      align-items: center;
    }

    /* Section Close Button */
    .section-close-btn {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      border: none;
      background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
      color: #6b7280;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: all 0.3s ease;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .section-close-btn:hover {
      background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
      color: #dc2626;
      transform: scale(1.1);
      box-shadow: 0 4px 8px rgba(220, 38, 38, 0.2);
    }

    .section-close-btn:active {
      transform: scale(0.95);
    }

    .section-close-btn i {
      font-size: 0.85rem;
    }

    /* Appeal Cards */
    .appeal-card {
      background: white;
      border-radius: 15px;
      padding: 1.25rem;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
      border: 1px solid #e5e7eb;
      transition: all 0.3s ease;
      height: 100%;
    }

    .appeal-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    }

    .appeal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1rem;
    }

    .appeal-type {
      padding: 0.5rem 0.75rem;
      border-radius: 20px;
      font-size: 0.85rem;
      font-weight: 600;
      color: white;
      display: inline-flex;
      align-items: center;
      white-space: nowrap;
      width: fit-content;
    }

    .appeal-type-cancellation {
      background: linear-gradient(135deg, #ef4444, #dc2626);
    }

    .appeal-type-refund {
      background: linear-gradient(135deg, #f59e0b, #d97706);
    }

    .appeal-type-change {
      background: linear-gradient(135deg, #8b5cf6, #7c3aed);
    }

    .appeal-status {
      background: #fef3c7;
      color: #92400e;
      padding: 0.25rem 0.75rem;
      border-radius: 12px;
      font-size: 0.8rem;
      font-weight: 600;
    }

    .appeal-content h6 {
      color: var(--guider-blue-dark);
      font-weight: 600;
      margin-bottom: 0.5rem;
    }

    .appeal-content p {
      margin-bottom: 0.5rem;
      color: #6b7280;
      font-size: 0.9rem;
    }

    .appeal-guider {
      font-weight: 500;
      color: #374151;
    }

    .appeal-submitted-by {
      margin-bottom: 0.5rem;
      font-size: 0.9rem;
    }

    .appeal-submitted-by .badge {
      font-size: 0.75rem;
      padding: 0.25rem 0.5rem;
    }


    .btn-book-trip {
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      border: none;
      border-radius: 12px;
      padding: 12px 30px;
      font-weight: 600;
      color: white;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(30, 64, 175, 0.3);
      text-decoration: none;
      display: inline-block;
      text-align: center;
      font-family: "Montserrat", sans-serif;
    }

    .btn-book-trip:hover {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(30, 64, 175, 0.4);
      color: white;
      text-decoration: none;
    }

    .btn-book-trip:active {
      transform: translateY(0);
      box-shadow: 0 2px 8px rgba(42, 82, 190, 0.3);
    }

    .btn-book-trip i {
      font-size: 1.2rem;
      font-weight: 600;
    }

    .appeal-dates {
      color: #059669;
      font-weight: 500;
    }

    .appeal-price {
      color: #dc2626;
      font-weight: 600;
      font-size: 1rem;
    }

    .appeal-reason {
      background: #f8fafc;
      padding: 0.75rem;
      border-radius: 8px;
      border-left: 3px solid #3b82f6;
      font-style: italic;
    }

    .appeal-date {
      color: #9ca3af;
      font-size: 0.8rem;
    }

    /* Processed Appeals */
    .processed-appeal {
      opacity: 0.9;
    }

    .appeal-status-approved {
      background: #dcfce7;
      color: #166534;
    }

    .appeal-status-rejected {
      background: #fef2f2;
      color: #dc2626;
    }

    .admin-response {
      background: #f8fafc;
      border: 1px solid #e5e7eb;
      border-radius: 8px;
      padding: 0.75rem;
      margin: 0.75rem 0;
    }

    .admin-response strong {
      color: #374151;
      font-size: 0.85rem;
    }

    .admin-response p {
      margin: 0.5rem 0 0 0;
      font-size: 0.9rem;
      color: #6b7280;
    }

    .refund-notice {
      background: #fef3c7;
      border: 1px solid #f59e0b;
      border-radius: 6px;
      padding: 0.5rem;
      margin: 0.5rem 0;
      font-size: 0.85rem;
      color: #92400e;
    }

    .refund-notice i {
      color: #f59e0b;
    }

    /* Enhanced Booking Cards */
    .booking-card {
      background: white;
      border-radius: 24px;
      padding: 0;
      margin-bottom: 2rem;
      box-shadow: 0 8px 32px rgba(30, 64, 175, 0.12);
      border: 1px solid rgba(30, 64, 175, 0.08);
      transition: all 0.4s ease;
      overflow: hidden;
      position: relative;
    }

    .booking-card:hover {
      transform: translateY(-8px);
      box-shadow: 0 20px 60px rgba(30, 64, 175, 0.2);
    }

    .booking-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
    }

    .booking-header {
      background: linear-gradient(135deg, #f8fafc, #e2e8f0);
      padding: 2rem;
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      border-bottom: 1px solid #e2e8f0;
    }

    .guider-info-header {
      display: flex;
      align-items: center;
      gap: 1.5rem;
      flex: 1;
    }

    .guider-avatar {
      position: relative;
    }

    .guider-avatar img {
      width: 100px;
      height: 100px;
      object-fit: cover;
      border: 4px solid white;
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    }

    .guider-avatar::after {
      content: '';
      position: absolute;
      bottom: 5px;
      right: 5px;
      width: 20px;
      height: 20px;
      background: #10b981;
      border: 3px solid white;
      border-radius: 50%;
    }

    .guider-basic-info {
      flex: 1;
    }

    .guider-name {
      font-size: 1.5rem;
      font-weight: 700;
      color: var(--guider-blue-dark);
      margin: 0 0 0.5rem 0;
    }

    .mountain-name {
      font-size: 1.1rem;
      color: #6b7280;
      margin: 0;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .booking-status {
      padding: 0.75rem 1.5rem;
      border-radius: 30px;
      font-size: 0.9rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .status-ongoing {
      background: linear-gradient(135deg, #10b981, #059669);
      color: white;
      box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
    }

    .status-unavailable {
      background: linear-gradient(135deg, #ef4444, #dc2626);
      color: white;
      box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
    }

    .status-past {
      background: linear-gradient(135deg, #6b7280, #4b5563);
      color: white;
      box-shadow: 0 4px 15px rgba(107, 114, 128, 0.3);
    }

    .status-completed {
      background: linear-gradient(135deg, #10b981, #059669);
      color: white;
      box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
    }

    .past-date-notification {
      background: linear-gradient(135deg, #fef3c7, #fde68a);
      border: 2px solid #f59e0b;
      border-radius: 8px;
      padding: 0.5rem 0.75rem;
      margin: 0.25rem 2rem 0.5rem auto;
      display: flex;
      align-items: center;
      gap: 0.5rem;
      max-width: 400px;
    }

    .past-date-notification-icon {
      width: 28px;
      height: 28px;
      background: #f59e0b;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 0.9rem;
      flex-shrink: 0;
    }

    .past-date-notification-content {
      flex: 1;
    }

    .past-date-notification-title {
      font-weight: 600;
      color: #92400e;
      font-size: 0.85rem;
      margin-bottom: 0.1rem;
    }

    .past-date-notification-text {
      color: #b45309;
      font-size: 0.8rem;
      margin: 0;
      line-height: 1.3;
    }


    .booking-content {
      padding: 2rem;
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 2rem;
    }

    .booking-details-grid {
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }

    .booking-detail {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 1rem;
      background: #f8fafc;
      border-radius: 12px;
      border-left: 4px solid var(--guider-blue-light);
      transition: background-color 0.3s ease;
    }

    .booking-detail:hover {
      background: #e2e8f0;
    }

    .booking-detail-label {
      font-weight: 600;
      color: #374151;
      font-size: 0.9rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .booking-detail-value {
      font-weight: 700;
      color: var(--guider-blue-dark);
      text-align: right;
      font-size: 1rem;
    }

    .guider-additional-info {
      background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
      padding: 1.5rem;
      border-radius: 16px;
      border: 1px solid #bae6fd;
    }

    .guider-info-title {
      font-size: 1.2rem;
      font-weight: 700;
      color: var(--guider-blue-dark);
      margin-bottom: 1rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .guider-info-item {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 0.75rem 0;
      border-bottom: 1px solid #e0f2fe;
    }

    .guider-info-item:last-child {
      border-bottom: none;
    }

    .guider-info-icon {
      width: 40px;
      height: 40px;
      background: var(--guider-blue-light);
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 1.1rem;
    }

    .guider-info-content {
      flex: 1;
    }

    .guider-info-label {
      font-size: 0.85rem;
      color: #6b7280;
      font-weight: 500;
    }

    .guider-info-value {
      font-size: 1rem;
      color: var(--guider-blue-dark);
      font-weight: 600;
    }

    .progress-section {
      margin: 1.5rem 0;
      padding: 1.5rem;
      background: #f8fafc;
      border-radius: 12px;
    }

    .progress-title {
      font-size: 1rem;
      font-weight: 600;
      color: var(--guider-blue-dark);
      margin-bottom: 1rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .progress-bar-container {
      background: #e2e8f0;
      height: 8px;
      border-radius: 4px;
      overflow: hidden;
      margin-bottom: 0.5rem;
    }

    .progress-bar {
      height: 100%;
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      border-radius: 4px;
      transition: width 0.3s ease;
    }

    .progress-text {
      font-size: 0.85rem;
      color: #6b7280;
      text-align: center;
    }

    .booking-actions {
      padding: 2rem;
      background: linear-gradient(135deg, #f8fafc, #e2e8f0);
      border-top: 1px solid #e2e8f0;
      display: flex;
      gap: 1rem;
      flex-wrap: wrap;
      justify-content: flex-end;
      align-items: center;
    }

    .contact-section {
      display: flex;
      gap: 1rem;
      margin-right: auto;
    }

    .btn-contact {
      padding: 0.75rem 1.25rem;
      border-radius: 12px;
      font-weight: 600;
      font-size: 0.9rem;
      transition: all 0.3s ease;
      border: none;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      background: linear-gradient(135deg, #6366f1, #4f46e5);
      color: white;
    }

    .btn-contact:hover {
      background: linear-gradient(135deg, #4f46e5, #4338ca);
      color: white;
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(99, 102, 241, 0.3);
    }

    .btn-action {
      padding: 0.75rem 1.5rem;
      border-radius: 12px;
      font-weight: 600;
      font-size: 0.9rem;
      transition: all 0.3s ease;
      border: none;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
    }

    .btn-done {
      background: linear-gradient(135deg, #10b981, #059669);
      color: white;
    }

    .btn-done:hover {
      background: linear-gradient(135deg, #059669, #047857);
      color: white;
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(16, 185, 129, 0.3);
    }

    .btn-cancel {
      background: linear-gradient(135deg, #f59e0b, #d97706);
      color: white;
    }

    .btn-cancel:hover {
      background: linear-gradient(135deg, #d97706, #b45309);
      color: white;
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(245, 158, 11, 0.3);
    }

    .btn-refund {
      background: linear-gradient(135deg, #3b82f6, #2563eb);
      color: white;
    }

    .btn-refund:hover {
      background: linear-gradient(135deg, #2563eb, #1d4ed8);
      color: white;
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(59, 130, 246, 0.3);
    }

    .btn-change {
      background: linear-gradient(135deg, #8b5cf6, #7c3aed);
      color: white;
    }

    .btn-change:hover {
      background: linear-gradient(135deg, #7c3aed, #6d28d9);
      color: white;
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(139, 92, 246, 0.3);
    }

    /* Empty State */
    .empty-state {
      text-align: center;
      padding: 4rem 2rem;
      background: white;
      border-radius: 20px;
      box-shadow: 0 4px 20px rgba(30, 64, 175, 0.08);
    }

    .empty-state-icon {
      font-size: 4rem;
      color: var(--guider-blue-accent);
      margin-bottom: 1.5rem;
    }

    .empty-state-title {
      font-size: 1.5rem;
      font-weight: 600;
      color: var(--guider-blue-dark);
      margin-bottom: 0.5rem;
    }

    .empty-state-text {
      color: #6b7280;
      font-size: 1rem;
      margin-bottom: 2rem;
    }

    /* Modal Styles */
    .modal-content {
      border-radius: 20px;
      border: none;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
    }

    .modal-header {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      color: white;
      border-radius: 20px 20px 0 0;
      border: none;
    }

    .modal-title {
      font-weight: 600;
    }

    /* Hiker Details Button */
    .btn-view-hiker-details {
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      color: white;
      border: none;
      border-radius: 10px;
      padding: 10px 20px;
      font-weight: 600;
      font-size: 0.95rem;
      transition: all 0.3s ease;
      box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .btn-view-hiker-details:hover {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(30, 64, 175, 0.4);
      color: white;
    }

    .btn-view-hiker-details:active {
      transform: translateY(0);
    }

    /* Hiker Details Modal */
    .hiker-detail-card {
      background: white;
      border-radius: 12px;
      padding: 1.5rem;
      margin-bottom: 1rem;
      border: 2px solid var(--guider-blue-soft);
      transition: all 0.3s ease;
    }

    .hiker-detail-card:hover {
      border-color: var(--guider-blue);
      box-shadow: 0 4px 12px rgba(30, 64, 175, 0.15);
    }

    .hiker-detail-header {
      display: flex;
      align-items: center;
      margin-bottom: 1rem;
      padding-bottom: 0.75rem;
      border-bottom: 2px solid var(--guider-blue-soft);
    }

    .hiker-detail-header h6 {
      margin: 0;
      color: var(--guider-blue-dark);
      font-weight: 700;
      font-size: 1.1rem;
    }

    .hiker-detail-badge {
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      color: white;
      padding: 0.25rem 0.75rem;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 600;
      margin-left: auto;
    }

    .hiker-info-item {
      display: flex;
      align-items: flex-start;
      margin-bottom: 0.75rem;
      padding: 0.5rem;
      background: #f8f9fa;
      border-radius: 8px;
    }

    .hiker-info-label {
      font-weight: 600;
      color: var(--guider-blue-dark);
      min-width: 160px;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .hiker-info-label i {
      color: var(--guider-blue);
      width: 20px;
    }

    .hiker-info-value {
      color: #374151;
      flex: 1;
    }

    .hiker-info-value a {
      color: var(--guider-blue);
      transition: color 0.2s ease;
    }

    .hiker-info-value a:hover {
      color: var(--guider-blue-dark);
      text-decoration: underline;
    }

    .hiker-info-grid {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
    }

    @media (max-width: 768px) {
      .hiker-info-item {
        flex-direction: column;
        gap: 0.5rem;
      }

      .hiker-info-label {
        min-width: auto;
      }

      .btn-view-hiker-details {
        width: 100%;
      }
    }


    /* Custom Popup Notifications */
    .custom-popup-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      display: flex;
      justify-content: center;
      align-items: center;
      z-index: 9999;
      animation: fadeIn 0.3s ease;
    }

    .custom-popup {
      background: white;
      border-radius: 20px;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
      max-width: 400px;
      width: 90%;
      animation: slideIn 0.3s ease;
    }

    .custom-popup-header {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      color: white;
      padding: 1.5rem;
      border-radius: 20px 20px 0 0;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .custom-popup-header h5 {
      margin: 0;
      font-weight: 600;
    }

    .btn-close-popup {
      background: none;
      border: none;
      color: white;
      font-size: 1.2rem;
      cursor: pointer;
      padding: 0;
      width: 30px;
      height: 30px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      transition: background-color 0.2s ease;
    }

    .btn-close-popup:hover {
      background-color: rgba(255, 255, 255, 0.2);
    }

    .custom-popup-body {
      padding: 1.5rem;
    }

    .custom-popup-body p {
      margin-bottom: 0.5rem;
      font-size: 1rem;
    }

    .custom-popup-footer {
      padding: 1rem 1.5rem 1.5rem;
      display: flex;
      gap: 1rem;
      justify-content: flex-end;
    }

    .btn-purple {
      background: linear-gradient(135deg, #8b5cf6, #7c3aed);
      color: white;
      border: none;
    }

    .btn-purple:hover {
      background: linear-gradient(135deg, #7c3aed, #6d28d9);
      color: white;
    }

    /* mini map styling */
    .mini-map-guider {
      width: 200px;
      height: 130px;
      border-radius: 12px;
      overflow: hidden;
      border: 2px solid var(--guider-blue);
      box-shadow: 0 8px 20px rgba(30, 64, 175, 0.15);
      margin-top: 8px;
    }

    .guider-basic-info {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
    }

    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    @keyframes slideIn {
      from { 
        opacity: 0;
        transform: translateY(-50px) scale(0.9);
      }
      to { 
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    /* Responsive */
    @media (max-width: 768px) {
      .booking-content {
        grid-template-columns: 1fr;
        gap: 1.5rem;
        padding: 1.5rem;
      }
      .mini-map-guider { width: 100%; height: 160px; }
      
      .booking-header {
        padding: 1.5rem;
        flex-direction: column;
        gap: 1rem;
        align-items: flex-start;
      }

      .guider-info-header {
        flex-direction: column;
        text-align: center;
        gap: 1rem;
      }

      .guider-avatar img {
        width: 80px;
        height: 80px;
      }

      .booking-actions {
        flex-direction: column;
        justify-content: center;
        padding: 1.5rem;
        gap: 1rem;
      }

      .contact-section {
        margin-right: 0;
        justify-content: center;
        width: 100%;
      }
      
      .btn-action, .btn-contact {
        width: 100%;
        justify-content: center;
      }

      .booking-detail {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
        text-align: left;
      }

      .booking-detail-value {
        text-align: left;
      }
    }
  </style>
</head>
<body>
  <!-- Header -->
  <header>
    <nav class="navbar">
      <div class="container d-flex align-items-center justify-content-between">
        <button class="navbar-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasNavbar">
          <span class="navbar-toggler-icon"></span>
        </button>
        <h1 class="navbar-title text-white mx-auto">HIKING GUIDANCE SYSTEM</h1>
        <a class="navbar-brand" href="../index.php">
          <img src="../img/logo.png" class="img-fluid logo" alt="HGS Logo">
        </a>
      </div>

      <div class="offcanvas offcanvas-start" id="offcanvasNavbar">
        <div class="offcanvas-header">
          <h5 class="offcanvas-title">Menu</h5>
          <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body">
          <ul class="navbar-nav">
            <li class="nav-item"><a class="nav-link" href="HHomePage.php">Home</a></li>
            <li class="nav-item"><a class="nav-link" href="HProfile.php">Profile</a></li>
            <li class="nav-item"><a class="nav-link" href="HBooking.php">Book Guider</a></li>
            <li class="nav-item"><a class="nav-link" href="HPayment.php">Payment</a></li>
            <li class="nav-item"><a class="nav-link active" href="HYourGuider.php">Your Guider</a></li>
            <li class="nav-item"><a class="nav-link" href="HRateReview.php">Rate and Review</a></li>
            <li class="nav-item"><a class="nav-link" href="HBookingHistory.php">Booking History</a></li>
          </ul>
          <form action="../shared/logout.php" method="POST" class="d-flex justify-content-center mt-5">
            <button type="submit" class="btn btn-danger">Logout</button>
          </form>
        </div>
      </div>
    </nav>
  </header>
<?php include_once '../shared/suspension_banner.php'; ?>

  <!-- Main Content -->
  <main class="main-content">
    <div class="container">
      <?php if (!empty($recentAppeals)): ?>
        <div class="mb-4" id="recentAppealsSection">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="section-title mb-0"><i class="bi bi-bell me-2"></i>Recent Appeal Results</h5>
            <button type="button" class="section-close-btn" onclick="this.closest('.mb-4').style.display='none'" title="Dismiss this section">
              <i class="fas fa-times"></i>
            </button>
          </div>
          <div class="row">
            <?php foreach ($recentAppeals as $appeal): ?>
              <div class="col-12 mb-3">
                <div class="appeal-card processed-appeal">
                  <div class="appeal-header">
                    <div class="d-flex flex-column">
                      <?php 
                        $typeIcons = [
                          'cancellation' => 'fas fa-times-circle',
                          'refund' => 'fas fa-money-bill-wave',
                          'change' => 'fas fa-exchange-alt'
                        ];
                        $typeLabels = [
                          'cancellation' => 'Cancellation',
                          'refund' => 'Refund',
                          'change' => 'Change Guider'
                        ];
                        $appealType = htmlspecialchars($appeal['appealType']);
                        $icon = isset($typeIcons[$appealType]) ? $typeIcons[$appealType] : 'bi bi-flag';
                        $label = isset($typeLabels[$appealType]) ? $typeLabels[$appealType] : ucfirst($appealType);
                      ?>
                      <span class="appeal-type <?= 'appeal-type-' . $appealType ?>">
                        <i class="<?= $icon ?> me-1"></i><?= $label ?>
                      </span>
                      <small class="appeal-date">Updated: <?= date('d M Y, h:i A', strtotime($appeal['updatedAt'])) ?></small>
                    </div>
                    <?php
                      $badgeClass = 'appeal-status';
                      $statusText = ucfirst(str_replace('_',' ', $appeal['status']));
                      $statusStyle = 'secondary';
                      if ($appeal['status'] === 'refunded') $statusStyle = 'warning';
                      if ($appeal['status'] === 'resolved') $statusStyle = 'secondary';
                      if ($appeal['status'] === 'rejected') $statusStyle = 'danger';
                      if ($appeal['status'] === 'cancelled') $statusStyle = 'danger';
                    ?>
                    <span class="badge bg-<?= $statusStyle ?>"><?= htmlspecialchars($statusText) ?></span>
                  </div>
                  <div class="appeal-content">
                    <h6><?= htmlspecialchars($appeal['mountainName']) ?></h6>
                    <p class="appeal-guider">Guider: <?= htmlspecialchars($appeal['guiderName']) ?></p>
                    <p class="appeal-dates"><?= date('d/m/Y', strtotime($appeal['startDate'])) ?> - <?= date('d/m/Y', strtotime($appeal['endDate'])) ?></p>
                    <div class="refund-notice" style="<?= $appeal['status'] === 'refunded' ? '' : 'display:none;' ?>">
                      <i class="bi bi-currency-dollar me-1"></i>
                      Refund will be processed within 3 working days.
                    </div>
                    <?php if (!empty($appeal['reason'])): ?>
                      <div class="admin-response"><strong>Reason</strong><p><?= nl2br(htmlspecialchars($appeal['reason'])) ?></p></div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
      <!-- Page Header -->
      <div class="page-header">
        <h1 class="page-title">
          <i class="fas fa-user-friends me-3"></i>Your Guider
        </h1>
        <p class="page-subtitle">Manage your accepted hiking bookings and guider interactions</p>
      </div>

      <?php if (!empty($awaitingAppeals)): ?>
        <div class="alert alert-info d-flex align-items-start gap-3" role="alert">
          <div>
            <div class="fw-bold mb-1"><i class="bi bi-chat-dots me-2"></i>Appeal awaiting your choice</div>
            <div>You have <?= count($awaitingAppeals) ?> appeal(s) waiting for you to choose: Refund or Change Guider.</div>
            <ul class="mt-2 mb-2">
              <?php foreach (array_slice($awaitingAppeals, 0, 2) as $ap): ?>
                <li>
                  Appeal #<?= (int)$ap['appealID'] ?> • <?= htmlspecialchars($ap['mountainName']) ?> • <?= date('d/m/Y', strtotime($ap['createdAt'])) ?>
                </li>
              <?php endforeach; ?>
            </ul>
            <a href="HAppealChoice.php" class="btn btn-primary">
              <i class="bi bi-check-circle me-1"></i>Make Your Choice
            </a>
          </div>
        </div>
      <?php endif; ?>

      <!-- Success Message -->
      <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <i class="fas fa-check-circle me-2"></i>
          <?php echo htmlspecialchars($success_message); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>
      
      <!-- Info Message -->
      <?php if (!empty($info_message)): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
          <i class="fas fa-info-circle me-2"></i>
          <?php echo htmlspecialchars($info_message); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>
      <?php if (isset($success)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <i class="fas fa-check-circle me-2"></i>
          <?php echo htmlspecialchars($success); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <!-- Error Message -->
      <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <i class="fas fa-exclamation-triangle me-2"></i>
          <?php echo htmlspecialchars($error); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <!-- Pending Appeals Section -->
      <?php if (!empty($pendingAppeals)): ?>
        <div class="pending-appeals-section mb-4" id="activeRequestsSection">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h3 class="section-title mb-0">
              <i class="fas fa-clock me-2"></i>Active Requests
            </h3>
            <button type="button" class="section-close-btn" onclick="this.closest('.pending-appeals-section').style.display='none'" title="Dismiss this section">
              <i class="fas fa-times"></i>
            </button>
          </div>
          <div class="row">
            <?php foreach ($pendingAppeals as $appeal): ?>
              <div class="col-md-6 col-lg-4 mb-3">
                <div class="appeal-card">
                  <div class="appeal-header">
                    <span class="appeal-type appeal-type-<?php echo $appeal['appealType']; ?>">
                      <?php 
                        $typeIcons = [
                          'cancellation' => 'fas fa-times-circle',
                          'refund' => 'fas fa-money-bill-wave',
                          'change' => 'fas fa-exchange-alt'
                        ];
                        $typeLabels = [
                          'cancellation' => 'Cancellation',
                          'refund' => 'Refund',
                          'change' => 'Change Guider'
                        ];
                      ?>
                      <i class="<?php echo $typeIcons[$appeal['appealType']] ?? 'fas fa-question-circle'; ?> me-1"></i>
                      <?php echo $typeLabels[$appeal['appealType']] ?? $appeal['appealType']; ?>
                    </span>
                    <?php 
                      $statusLabels = [
                        'pending' => 'Pending',
                        'pending_refund' => 'Refund Pending Approval',
                        'approved' => 'Approved',
                        'onhold' => 'On Hold'
                      ];
                      $statusClasses = [
                        'pending' => 'appeal-status',
                        'pending_refund' => 'appeal-status bg-warning text-dark',
                        'approved' => 'appeal-status bg-success',
                        'onhold' => 'appeal-status bg-info'
                      ];
                    ?>
                    <span class="<?php echo $statusClasses[$appeal['status']] ?? 'appeal-status'; ?>">
                      <?php echo $statusLabels[$appeal['status']] ?? ucfirst($appeal['status']); ?>
                    </span>
                  </div>
                  <div class="appeal-content">
                    <h6 class="appeal-booking"><?php echo htmlspecialchars($appeal['mountainName']); ?></h6>
                    <p class="appeal-guider">Guider: <?php echo htmlspecialchars($appeal['guiderName']); ?></p>
                    <?php if ($appeal['status'] === 'pending_refund'): ?>
                      <div class="alert alert-warning py-2 px-3 mb-2">
                        <i class="fas fa-clock me-1"></i>
                        <strong>Waiting for admin approval</strong> - Your refund request is being reviewed.
                      </div>
                    <?php endif; ?>
                    <p class="appeal-submitted-by">
                      <strong>Requested by:</strong> 
                      <?php if ($appeal['appealFrom'] === 'hiker'): ?>
                        <span class="badge bg-primary">
                          <i class="fas fa-user"></i> You (Hiker)
                        </span>
                      <?php elseif ($appeal['appealFrom'] === 'guider'): ?>
                        <span class="badge bg-info">
                          <i class="fas fa-user-tie"></i> Guider
                        </span>
                      <?php else: ?>
                        <span class="badge bg-secondary">Unknown</span>
                      <?php endif; ?>
                    </p>
                    <p class="appeal-dates">
                      <?php echo date('M j, Y', strtotime($appeal['startDate'])); ?> - 
                      <?php echo date('M j, Y', strtotime($appeal['endDate'])); ?>
                    </p>
                    <p class="appeal-price">
                      <strong>Price: RM <?php echo number_format($appeal['price'], 2); ?></strong>
                    </p>
                    <p class="appeal-reason"><?php echo htmlspecialchars(substr($appeal['reason'], 0, 100)) . (strlen($appeal['reason']) > 100 ? '...' : ''); ?></p>
                    <?php if ($appeal['appealType'] == 'refund'): ?>
                      <div class="refund-notice">
                        <i class="fas fa-info-circle me-1"></i>
                        <strong>Booking automatically cancelled</strong> - Refund will be processed within 1-3 working days
                      </div>
                    <?php endif; ?>
                    <small class="appeal-date">Submitted: <?php echo date('M j, Y g:i A', strtotime($appeal['createdAt'])); ?></small>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- Processed Appeals Section -->
      <?php if (!empty($processedAppeals)): ?>
        <div class="processed-appeals-section mb-4" id="recentRequestResultsSection">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h3 class="section-title mb-0">
              <i class="fas fa-check-circle me-2"></i>Recent Request Results
            </h3>
            <button type="button" class="section-close-btn" onclick="this.closest('.processed-appeals-section').style.display='none'" title="Dismiss this section">
              <i class="fas fa-times"></i>
            </button>
          </div>
          <div class="row">
            <?php foreach ($processedAppeals as $appeal): ?>
              <div class="col-md-6 col-lg-4 mb-3">
                <div class="appeal-card processed-appeal">
                  <div class="appeal-header">
                    <span class="appeal-type appeal-type-<?php echo $appeal['appealType']; ?>">
                      <?php 
                        $typeIcons = [
                          'cancellation' => 'fas fa-times-circle',
                          'refund' => 'fas fa-money-bill-wave',
                          'change' => 'fas fa-exchange-alt'
                        ];
                        $typeLabels = [
                          'cancellation' => 'Cancellation',
                          'refund' => 'Refund',
                          'change' => 'Change Guider'
                        ];
                      ?>
                      <i class="<?php echo $typeIcons[$appeal['appealType']]; ?> me-1"></i>
                      <?php echo $typeLabels[$appeal['appealType']]; ?>
                    </span>
                    <span class="appeal-status appeal-status-<?php echo $appeal['status']; ?>">
                      <?php 
                        $statusLabels = [
                          'approved' => 'Approved',
                          'rejected' => 'Rejected',
                          'refunded' => 'Refunded',
                          'resolved' => 'Resolved',
                          'pending_refund' => 'Refund Pending',
                          'refund_rejected' => 'Refund Rejected'
                        ];
                        echo $statusLabels[$appeal['status']] ?? ucfirst($appeal['status']); 
                      ?>
                    </span>
                  </div>
                  <div class="appeal-content">
                    <h6 class="appeal-booking"><?php echo htmlspecialchars($appeal['mountainName']); ?></h6>
                    <p class="appeal-guider">Guider: <?php echo htmlspecialchars($appeal['guiderName']); ?></p>
                    <p class="appeal-submitted-by">
                      <strong>Requested by:</strong> 
                      <?php if ($appeal['appealFrom'] === 'hiker'): ?>
                        <span class="badge bg-primary">
                          <i class="fas fa-user"></i> You (Hiker)
                        </span>
                      <?php elseif ($appeal['appealFrom'] === 'guider'): ?>
                        <span class="badge bg-info">
                          <i class="fas fa-user-tie"></i> Guider
                        </span>
                      <?php else: ?>
                        <span class="badge bg-secondary">Unknown</span>
                      <?php endif; ?>
                    </p>
                    <p class="appeal-dates">
                      <?php echo date('M j, Y', strtotime($appeal['startDate'])); ?> - 
                      <?php echo date('M j, Y', strtotime($appeal['endDate'])); ?>
                    </p>
                    <p class="appeal-price">
                      <strong>Price: RM <?php echo number_format($appeal['price'], 2); ?></strong>
                    </p>
                    <?php if (!empty($appeal['adminResponse'])): ?>
                      <div class="admin-response">
                        <strong>Admin Response:</strong>
                        <p><?php echo htmlspecialchars($appeal['adminResponse']); ?></p>
                      </div>
                    <?php endif; ?>
                    <?php if ($appeal['status'] === 'refunded'): ?>
                      <div class="refund-notice" style="background: #d4edda; border: 1px solid #c3e6cb; padding: 8px; border-radius: 5px; margin-top: 8px;">
                        <i class="fas fa-check-circle me-1 text-success"></i>
                        <strong>Refund Approved!</strong> Payment will be processed within 3 working days.
                      </div>
                    <?php elseif ($appeal['status'] === 'pending_refund'): ?>
                      <div class="refund-notice" style="background: #fff3cd; border: 1px solid #ffc107; padding: 8px; border-radius: 5px; margin-top: 8px;">
                        <i class="fas fa-clock me-1 text-warning"></i>
                        <strong>Waiting for Admin Approval</strong> - Your refund request is being reviewed.
                      </div>
                    <?php elseif ($appeal['status'] === 'refund_rejected'): ?>
                      <div class="refund-notice" style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 8px; border-radius: 5px; margin-top: 8px;">
                        <i class="fas fa-times-circle me-1 text-danger"></i>
                        <strong>Refund Rejected</strong> - Booking was cancelled without refund.
                      </div>
                    <?php endif; ?>
                    <small class="appeal-date">Processed: <?php echo date('M j, Y g:i A', strtotime($appeal['updatedAt'])); ?></small>
                    <?php if ($appeal['status'] === 'approved' && $appeal['appealType'] === 'cancellation'): ?>
                      <div class="d-flex gap-2 mt-2">
                        <button class="btn btn-sm btn-primary" onclick="chooseAppealAction(<?php echo (int)$appeal['appealID']; ?>, <?php echo (int)$appeal['bookingID']; ?>, 'refund')">
                          <i class="fas fa-money-bill-wave me-1"></i>Refund
                        </button>
                        <button class="btn btn-sm btn-purple" onclick="chooseAppealAction(<?php echo (int)$appeal['appealID']; ?>, <?php echo (int)$appeal['bookingID']; ?>, 'change')">
                          <i class="fas fa-exchange-alt me-1"></i>Change Guider
                        </button>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- Bookings List -->
      <div class="row">
        <div class="col-12">
          <?php if (empty($acceptedBookings)): ?>
            <!-- Empty State -->
            <div class="empty-state">
              <div class="empty-state-icon">
                <i class="fas fa-user-friends"></i>
              </div>
              <h3 class="empty-state-title">No Active Bookings</h3>
              <p class="empty-state-text">You don't have any accepted bookings at the moment. Start by booking a hiking trip!</p>
              <a href="HBooking.php" class="btn-book-trip">
                <i class="fas fa-plus me-2"></i>Book a Hiking Trip
              </a>
            </div>
          <?php else: ?>
            <!-- Accepted Bookings -->
            <?php foreach ($acceptedBookings as $booking): ?>
              <?php
              // Determine booking status
              $currentDate = date('Y-m-d');
              $startDate = date('Y-m-d', strtotime($booking['startDate']));
              $endDate = date('Y-m-d', strtotime($booking['endDate']));
              
              $isPast = $currentDate > $endDate;
              $isOngoing = ($currentDate >= $startDate && $currentDate <= $endDate);
              $isUpcoming = $currentDate < $startDate;
              $isCompleted = $booking['status'] === 'completed';
              
              if ($isCompleted) {
                $status = 'completed';
                $statusText = 'Completed';
                $statusClass = 'status-completed';
              } elseif ($isPast) {
                $status = 'past';
                $statusText = 'Trip Completed';
                $statusClass = 'status-past';
              } elseif ($isOngoing) {
                $status = 'ongoing';
                $statusText = 'Ongoing';
                $statusClass = 'status-ongoing';
              } else {
                $status = 'upcoming';
                $statusText = 'Upcoming';
                $statusClass = 'status-ongoing';
              }
              ?>
              
              <!-- dalam ni ade gmap punya setting  -->
              <div class="booking-card">
                <div class="booking-header">
                  <div class="guider-info-header">
                    <div class="guider-avatar">
                      <img src="<?php echo htmlspecialchars(strpos($booking['guiderPicture'], 'http') === 0 ? $booking['guiderPicture'] : '../' . $booking['guiderPicture']); ?>" alt="Guider Profile">
                    </div>
                    <div class="guider-basic-info">
                      <h5 class="guider-name"><?php echo htmlspecialchars($booking['guiderName']); ?></h5>
                      <p class="mountain-name">
                        <i class="fas fa-mountain"></i><?php echo htmlspecialchars($booking['mountainName']); ?>
                      </p>
                      <?php 
                        $lat = isset($booking['mountainLatitude']) ? (float)$booking['mountainLatitude'] : null;
                        $lng = isset($booking['mountainLongitude']) ? (float)$booking['mountainLongitude'] : null;
                      ?>
                      <div class="map-placeholder mini-map-guider"
                          data-latitude="<?php echo htmlspecialchars($lat ?? ''); ?>"
                          data-longitude="<?php echo htmlspecialchars($lng ?? ''); ?>">
                      </div>
                    </div>
                  </div>
                  <span class="booking-status <?php echo $statusClass; ?>">
                    <i class="fas fa-<?php 
                      if ($isCompleted) echo 'check-circle';
                      elseif ($isPast) echo 'exclamation-triangle';
                      elseif ($isOngoing) echo 'clock';
                      else echo 'calendar-alt';
                    ?>"></i><?php echo $statusText; ?>
                  </span>
                </div>
                
                <div class="booking-content">
                  <div class="booking-details-grid">
                    <div class="booking-detail">
                      <span class="booking-detail-label">
                        <i class="fas fa-calendar-alt"></i>Trip Dates
                      </span>
                      <span class="booking-detail-value">
                        <?php echo date('M j', strtotime($booking['startDate'])); ?> - <?php echo date('M j, Y', strtotime($booking['endDate'])); ?>
                      </span>
                    </div>
                    <div class="booking-detail">
                      <span class="booking-detail-label">
                        <i class="fas fa-users"></i>Group Size
                      </span>
                      <span class="booking-detail-value"><?php echo $booking['totalHiker']; ?> person(s)</span>
                    </div>
                    <?php 
                    $hikerDetails = getHikerDetails($conn, $booking['bookingID']);
                    if (!empty($hikerDetails)): 
                    ?>
                    <div class="booking-detail" style="grid-column: 1 / -1;">
                      <span class="booking-detail-label">
                        <i class="fas fa-user-friends"></i>Hiker Details
                      </span>
                      <div class="mt-2">
                        <button class="btn btn-view-hiker-details" type="button" data-bs-toggle="modal" data-bs-target="#hikerDetailsModal<?= $booking['bookingID'] ?>">
                          <i class="fas fa-users me-2"></i>View Hiker Details (<?= count($hikerDetails) ?>)
                        </button>
                      </div>
                    </div>
                    <?php endif; ?>
                    <div class="booking-detail">
                      <span class="booking-detail-label">
                        <i class="fas fa-money-bill-wave"></i>Total Amount
                      </span>
                      <span class="booking-detail-value">RM <?php echo number_format($booking['price'], 2); ?></span>
                    </div>
                    <div class="booking-detail">
                      <span class="booking-detail-label">
                        <i class="fas fa-clock"></i>Duration
                      </span>
                      <span class="booking-detail-value">
                        <?php 
                        $start = new DateTime($booking['startDate']);
                        $end = new DateTime($booking['endDate']);
                        $duration = $start->diff($end)->days + 1;
                        echo $duration . ' day' . ($duration > 1 ? 's' : '');
                        ?>
                      </span>
                    </div>
                    <?php if (!empty($booking['guiderPhone'])): ?>
                    <div class="booking-detail">
                      <span class="booking-detail-label">
                        <i class="fas fa-phone"></i>Contact
                      </span>
                      <span class="booking-detail-value"><?php echo htmlspecialchars($booking['guiderPhone']); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($booking['guiderExperience'])): ?>
                    <div class="booking-detail">
                      <span class="booking-detail-label">
                        <i class="fas fa-award"></i>Experience
                      </span>
                      <span class="booking-detail-value"><?php 
                        $experience = $booking['guiderExperience'];
                        if (empty($experience)) {
                          echo 'Experience not specified';
                        } else {
                          // If it's a number (old format), add "years"
                          if (is_numeric($experience)) {
                            echo htmlspecialchars($experience) . ' years';
                          } else {
                            // New text format - display as is
                            echo htmlspecialchars($experience);
                          }
                        }
                      ?></span>
                    </div>
                    <?php endif; ?>
                  </div>

                  <div class="guider-additional-info">
                    <h6 class="guider-info-title">
                      <i class="fas fa-user-circle"></i>Guider Information
                    </h6>
                    
                    <?php if (!empty($booking['guiderEmail'])): ?>
                    <div class="guider-info-item">
                      <div class="guider-info-icon">
                        <i class="fas fa-envelope"></i>
                      </div>
                      <div class="guider-info-content">
                        <div class="guider-info-label">Email</div>
                        <div class="guider-info-value"><?php echo htmlspecialchars($booking['guiderEmail']); ?></div>
                      </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($booking['guiderExperience'])): ?>
                    <div class="guider-info-item">
                      <div class="guider-info-icon">
                        <i class="fas fa-star"></i>
                      </div>
                      <div class="guider-info-content">
                        <div class="guider-info-label">Experience Level</div>
                        <div class="guider-info-value">
                          <?php 
                          $experience = $booking['guiderExperience'];
                          // Handle new text-based experience format
                          if (empty($experience)) {
                            echo 'Experience not specified';
                          } else {
                            // If it's a number (old format), convert to level
                            if (is_numeric($experience)) {
                              $expNum = intval($experience);
                              if ($expNum >= 10) echo 'Expert';
                              elseif ($expNum >= 5) echo 'Advanced';
                              elseif ($expNum >= 2) echo 'Intermediate';
                              else echo 'Beginner';
                              echo " ($expNum years)";
                            } else {
                              // New text format - display as is
                              echo htmlspecialchars($experience);
                            }
                          }
                          ?>
                        </div>
                      </div>
                    </div>
                    <?php endif; ?>

                    <div class="guider-info-item">
                      <div class="guider-info-icon">
                        <i class="fas fa-map-marker-alt"></i>
                      </div>
                      <div class="guider-info-content">
                        <div class="guider-info-label">Specialty</div>
                        <div class="guider-info-value"><?php echo htmlspecialchars($booking['mountainName']); ?> Hiking</div>
                      </div>
                    </div>

                    <div class="guider-info-item">
                      <div class="guider-info-icon">
                        <i class="fas fa-shield-alt"></i>
                      </div>
                      <div class="guider-info-content">
                        <div class="guider-info-label">Safety Rating</div>
                        <div class="guider-info-value">
                          <i class="fas fa-star text-warning"></i>
                          <i class="fas fa-star text-warning"></i>
                          <i class="fas fa-star text-warning"></i>
                          <i class="fas fa-star text-warning"></i>
                          <i class="fas fa-star text-warning"></i>
                          <span class="ms-2">5.0</span>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <?php if ($isPast): ?>
                <div class="past-date-notification">
                  <div class="past-date-notification-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                  </div>
                  <div class="past-date-notification-content">
                    <div class="past-date-notification-title">Trip Date Has Passed</div>
                    <p class="past-date-notification-text">
                      This trip ended on <?php echo date('M j, Y', strtotime($booking['endDate'])); ?>. 
                      Please mark it as done to complete your booking and proceed to rate and review.
                    </p>
                  </div>
                </div>
                <?php endif; ?>

                <?php if ($status === 'ongoing' || $status === 'upcoming'): ?>
                <div class="progress-section">
                  <div class="progress-title">
                    <i class="fas fa-route"></i>Trip Progress
                  </div>
                  <div class="progress-bar-container">
                    <div class="progress-bar" style="width: <?php 
                      $start = new DateTime($booking['startDate']);
                      $end = new DateTime($booking['endDate']);
                      $current = new DateTime();
                      $total = $start->diff($end)->days + 1;
                      $elapsed = $current >= $start ? min($start->diff($current)->days + 1, $total) : 0;
                      echo $total > 0 ? ($elapsed / $total) * 100 : 0;
                    ?>%"></div>
                  </div>
                  <div class="progress-text">
                    <?php 
                    $start = new DateTime($booking['startDate']);
                    $end = new DateTime($booking['endDate']);
                    $current = new DateTime();
                    $total = $start->diff($end)->days + 1;
                    $elapsed = $current >= $start ? min($start->diff($current)->days + 1, $total) : 0;
                    $remaining = max(0, $total - $elapsed);
                    echo "Day $elapsed of $total - $remaining days remaining";
                    ?>
                  </div>
                </div>
                <?php endif; ?>
                
                <div class="booking-actions">
                  <?php if ($isCompleted): ?>
                    <!-- Completed Status - No Actions -->
                    <div class="contact-section">
                      <span class="text-muted">
                        <i class="fas fa-check-circle me-2"></i>Trip completed successfully
                      </span>
                    </div>
                  <?php elseif (!$isPast): ?>
                  <div class="contact-section">
                    <?php if (!empty($booking['guiderPhone'])): ?>
                    <a href="tel:<?php echo htmlspecialchars($booking['guiderPhone']); ?>" class="btn-contact">
                      <i class="fas fa-phone"></i>Call
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($booking['guiderEmail'])): ?>
                    <a href="mailto:<?php echo htmlspecialchars($booking['guiderEmail']); ?>" class="btn-contact">
                      <i class="fas fa-envelope"></i>Email
                    </a>
                    <?php endif; ?>
                  </div>
                  <?php endif; ?>
                  
                  <?php if ($isPast && !$isCompleted): ?>
                    <!-- Past Date - Only Done Button -->
                    <button type="button" class="btn-action btn-done" onclick="markAsDone(<?php echo $booking['bookingID']; ?>)">
                      <i class="fas fa-check"></i>Mark as Done
                    </button>
                  <?php elseif ($status === 'ongoing' || $status === 'upcoming'): ?>
                    <!-- Ongoing/Upcoming Status Actions -->
                    <button type="button" class="btn-action btn-done" onclick="markAsDone(<?php echo $booking['bookingID']; ?>)">
                      <i class="fas fa-check"></i>Mark as Done
                    </button>
                    <button type="button" class="btn-action btn-cancel" onclick="showCancelModal(<?php echo $booking['bookingID']; ?>)">
                      <i class="fas fa-times"></i>Cancel Trip
                    </button>
                  <?php elseif (!$isCompleted): ?>
                    <!-- Unavailable Status Actions -->
                    <button type="button" class="btn-action btn-refund" onclick="requestRefund(<?php echo $booking['bookingID']; ?>)">
                      <i class="fas fa-money-bill-wave"></i>Request Refund
                    </button>
                    <button type="button" class="btn-action btn-change" onclick="requestChange(<?php echo $booking['bookingID']; ?>)">
                      <i class="fas fa-exchange-alt"></i>Change Guider
                    </button>
                  <?php endif; ?>
                </div>
              </div>
            <?php 
            // Create modal for hiker details for each booking
            $hikerDetailsForModal = getHikerDetails($conn, $booking['bookingID']);
            if (!empty($hikerDetailsForModal)): 
            ?>
            <!-- Hiker Details Modal for Booking <?= $booking['bookingID'] ?> -->
            <div class="modal fade" id="hikerDetailsModal<?= $booking['bookingID'] ?>" tabindex="-1" aria-labelledby="hikerDetailsModalLabel<?= $booking['bookingID'] ?>" aria-hidden="true">
              <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title" id="hikerDetailsModalLabel<?= $booking['bookingID'] ?>">
                      <i class="fas fa-users me-2"></i>Hiker Details - Booking #<?= $booking['bookingID'] ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                    <div class="mb-3">
                      <p class="text-muted mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        Total of <strong><?= count($hikerDetailsForModal) ?></strong> hiker(s) registered for this booking
                      </p>
                    </div>
                    <?php foreach ($hikerDetailsForModal as $index => $hiker): ?>
                    <div class="hiker-detail-card">
                      <div class="hiker-detail-header">
                        <h6>
                          <i class="fas fa-user me-2"></i>Hiker <?= $index + 1 ?>
                        </h6>
                        <span class="hiker-detail-badge"><?= htmlspecialchars($hiker['hikerName']) ?></span>
                      </div>
                      <div class="hiker-info-grid">
                        <div class="hiker-info-item">
                          <div class="hiker-info-label">
                            <i class="fas fa-id-card"></i>
                            <span>Full Name:</span>
                          </div>
                          <div class="hiker-info-value"><?= htmlspecialchars($hiker['hikerName']) ?></div>
                        </div>
                        <div class="hiker-info-item">
                          <div class="hiker-info-label">
                            <i class="fas fa-passport"></i>
                            <span>IC/Passport:</span>
                          </div>
                          <div class="hiker-info-value"><?= htmlspecialchars($hiker['identityCard']) ?></div>
                        </div>
                        <div class="hiker-info-item">
                          <div class="hiker-info-label">
                            <i class="fas fa-phone"></i>
                            <span>Phone Number:</span>
                          </div>
                          <div class="hiker-info-value">
                            <a href="tel:<?= htmlspecialchars($hiker['phoneNumber']) ?>" class="text-decoration-none">
                              <?= htmlspecialchars($hiker['phoneNumber']) ?>
                            </a>
                          </div>
                        </div>
                        <div class="hiker-info-item">
                          <div class="hiker-info-label">
                            <i class="fas fa-map-marker-alt"></i>
                            <span>Address:</span>
                          </div>
                          <div class="hiker-info-value"><?= nl2br(htmlspecialchars($hiker['address'])) ?></div>
                        </div>
                        <div class="hiker-info-item">
                          <div class="hiker-info-label">
                            <i class="fas fa-user-shield"></i>
                            <span>Emergency Contact:</span>
                          </div>
                          <div class="hiker-info-value"><?= htmlspecialchars($hiker['emergencyContactName']) ?></div>
                        </div>
                        <div class="hiker-info-item">
                          <div class="hiker-info-label">
                            <i class="fas fa-phone-alt"></i>
                            <span>Emergency Phone:</span>
                          </div>
                          <div class="hiker-info-value">
                            <a href="tel:<?= htmlspecialchars($hiker['emergencyContactNumber']) ?>" class="text-decoration-none">
                              <?= htmlspecialchars($hiker['emergencyContactNumber']) ?>
                            </a>
                          </div>
                        </div>
                      </div>
                    </div>
                    <?php endforeach; ?>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                      <i class="fas fa-times me-2"></i>Close
                    </button>
                  </div>
                </div>
              </div>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </main>

  <!-- Cancel Modal -->
  <div class="modal fade" id="cancelModal" tabindex="-1" aria-labelledby="cancelModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="cancelModalLabel">
            <i class="fas fa-times-circle me-2"></i>Cancel Booking
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST" id="cancelForm">
          <div class="modal-body">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="bookingID" id="cancelBookingID">
            
            <div class="mb-3">
              <label for="reason" class="form-label">Cancellation Reason</label>
              <select class="form-select" id="reason" name="reason" required>
                <option value="">Select a reason...</option>
                <option value="personal_emergency">Personal Emergency</option>
                <option value="weather_concerns">Weather Concerns</option>
                <option value="health_issues">Health Issues</option>
                <option value="schedule_conflict">Schedule Conflict</option>
                <option value="other">Other</option>
              </select>
            </div>
            
            <div class="mb-3">
              <label for="details" class="form-label">Additional Details</label>
              <textarea class="form-control" id="details" name="details" rows="4" 
                        placeholder="Please provide additional details about your cancellation..." required></textarea>
            </div>
            
            <div class="alert alert-info">
              <i class="fas fa-info-circle me-2"></i>
              <strong>Note:</strong> Your cancellation request will be reviewed by our admin team. You will be notified of the decision via email.
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            <button type="submit" class="btn btn-warning">
              <i class="fas fa-paper-plane me-2"></i>Submit Request
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <!-- Custom Notification System -->
  <div id="notificationContainer" style="position: fixed; top: 20px; right: 20px; z-index: 9999;"></div>

  <script>
    class NotificationSystem {
      constructor() {
        this.container = document.getElementById('notificationContainer');
      }

      show(type, title, message, duration = 5000) {
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        
        const icons = {
          success: 'fas fa-check',
          error: 'fas fa-times',
          warning: 'fas fa-exclamation-triangle',
          info: 'fas fa-info-circle'
        };

        notification.innerHTML = `
          <div style="display: flex; align-items: flex-start; gap: 12px;">
            <div class="notification-icon">
              <i class="${icons[type]}"></i>
            </div>
            <div class="notification-content">
              <div class="notification-title">${title}</div>
              <div class="notification-message">${message}</div>
            </div>
            <button class="notification-close" onclick="this.parentElement.parentElement.remove()">
              <i class="fas fa-times"></i>
            </button>
          </div>
          <div class="notification-progress"></div>
        `;

        this.container.appendChild(notification);

        // Animate in
        setTimeout(() => {
          notification.style.transform = 'translateX(0)';
          notification.style.opacity = '1';
        }, 100);

        // Auto remove
        if (duration > 0) {
          setTimeout(() => {
            notification.style.transform = 'translateX(100%)';
            notification.style.opacity = '0';
            setTimeout(() => {
              if (notification.parentNode) {
                notification.remove();
              }
            }, 400);
          }, duration);
        }

        return notification;
      }

      success(title, message, duration) {
        return this.show('success', title, message, duration);
      }

      error(title, message, duration) {
        return this.show('error', title, message, duration);
      }

      warning(title, message, duration) {
        return this.show('warning', title, message, duration);
      }

      info(title, message, duration) {
        return this.show('info', title, message, duration);
      }
    }

    // Initialize notification system
    const notificationSystem = new NotificationSystem();

    // Add CSS for notifications
    const style = document.createElement('style');
    style.textContent = `
      .notification {
        background: white;
        border-radius: 12px;
        padding: 1rem;
        margin-bottom: 1rem;
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
        border-left: 4px solid;
        max-width: 400px;
        transform: translateX(100%);
        opacity: 0;
        transition: all 0.4s ease;
        position: relative;
        overflow: hidden;
      }

      .notification.success { border-left-color: #10b981; }
      .notification.error { border-left-color: #ef4444; }
      .notification.warning { border-left-color: #f59e0b; }
      .notification.info { border-left-color: #3b82f6; }

      .notification-icon {
        width: 24px;
        height: 24px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
      }

      .notification.success .notification-icon { background: #10b981; color: white; }
      .notification.error .notification-icon { background: #ef4444; color: white; }
      .notification.warning .notification-icon { background: #f59e0b; color: white; }
      .notification.info .notification-icon { background: #3b82f6; color: white; }

      .notification-content {
        flex: 1;
      }

      .notification-title {
        font-weight: 600;
        font-size: 0.9rem;
        margin-bottom: 0.25rem;
        color: #1f2937;
      }

      .notification-message {
        font-size: 0.85rem;
        color: #6b7280;
        line-height: 1.4;
      }

      .notification-close {
        background: none;
        border: none;
        color: #9ca3af;
        cursor: pointer;
        padding: 0;
        width: 20px;
        height: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        transition: all 0.2s ease;
      }

      .notification-close:hover {
        background: #f3f4f6;
        color: #374151;
      }

      .notification-progress {
        position: absolute;
        bottom: 0;
        left: 0;
        height: 3px;
        background: rgba(0, 0, 0, 0.1);
        animation: progress 5s linear forwards;
      }

      @keyframes progress {
        from { width: 100%; }
        to { width: 0%; }
      }
    `;
    document.head.appendChild(style);

    // Action Functions
    function markAsDone(bookingID) {
      // Create custom confirmation popup
      const popup = document.createElement('div');
      popup.className = 'custom-popup-overlay';
      popup.innerHTML = `
        <div class="custom-popup">
          <div class="custom-popup-header">
            <h5><i class="fas fa-check-circle me-2"></i>Confirm Action</h5>
            <button type="button" class="btn-close-popup" onclick="closePopup(this)">
              <i class="fas fa-times"></i>
            </button>
          </div>
          <div class="custom-popup-body">
            <p>Are you sure you want to mark this booking as done?</p>
            <p class="text-muted">This will redirect you to rate and review.</p>
          </div>
          <div class="custom-popup-footer">
            <button type="button" class="btn btn-secondary" onclick="closePopup(this)">Cancel</button>
            <button type="button" class="btn btn-success" onclick="confirmDone(${bookingID})">Confirm</button>
          </div>
        </div>
      `;
      document.body.appendChild(popup);
    }

    function confirmDone(bookingID) {
      closePopup();
      const form = document.createElement('form');
      form.method = 'POST';
      form.innerHTML = `
        <input type="hidden" name="action" value="done">
        <input type="hidden" name="bookingID" value="${bookingID}">
      `;
      document.body.appendChild(form);
      form.submit();
    }

    function showCancelModal(bookingID) {
      document.getElementById('cancelBookingID').value = bookingID;
      const modal = new bootstrap.Modal(document.getElementById('cancelModal'));
      modal.show();
    }

    function requestRefund(bookingID) {
      // Create custom confirmation popup
      const popup = document.createElement('div');
      popup.className = 'custom-popup-overlay';
      popup.innerHTML = `
        <div class="custom-popup">
          <div class="custom-popup-header">
            <h5><i class="fas fa-money-bill-wave me-2"></i>Request Refund</h5>
            <button type="button" class="btn-close-popup" onclick="closePopup(this)">
              <i class="fas fa-times"></i>
            </button>
          </div>
          <div class="custom-popup-body">
            <p>Are you sure you want to request a refund for this booking?</p>
            <p class="text-muted">Admin will review your request and process the refund.</p>
          </div>
          <div class="custom-popup-footer">
            <button type="button" class="btn btn-secondary" onclick="closePopup(this)">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="confirmRefund(${bookingID})">Request Refund</button>
          </div>
        </div>
      `;
      document.body.appendChild(popup);
    }

    function confirmRefund(bookingID) {
      closePopup();
      const form = document.createElement('form');
      form.method = 'POST';
      form.innerHTML = `
        <input type="hidden" name="action" value="refund">
        <input type="hidden" name="bookingID" value="${bookingID}">
      `;
      document.body.appendChild(form);
      form.submit();
    }

    function requestChange(bookingID) {
      // Create custom confirmation popup
      const popup = document.createElement('div');
      popup.className = 'custom-popup-overlay';
      popup.innerHTML = `
        <div class="custom-popup">
          <div class="custom-popup-header">
            <h5><i class="fas fa-exchange-alt me-2"></i>Request Change</h5>
            <button type="button" class="btn-close-popup" onclick="closePopup(this)">
              <i class="fas fa-times"></i>
            </button>
          </div>
          <div class="custom-popup-body">
            <p>Are you sure you want to request a change of guider for this booking?</p>
            <p class="text-muted">Admin will assign a new guider and notify you via email.</p>
          </div>
          <div class="custom-popup-footer">
            <button type="button" class="btn btn-secondary" onclick="closePopup(this)">Cancel</button>
            <button type="button" class="btn btn-purple" onclick="confirmChange(${bookingID})">Request Change</button>
          </div>
        </div>
      `;
      document.body.appendChild(popup);
    }

    function confirmChange(bookingID) {
      closePopup();
      const form = document.createElement('form');
      form.method = 'POST';
      form.innerHTML = `
        <input type="hidden" name="action" value="change">
        <input type="hidden" name="bookingID" value="${bookingID}">
      `;
      document.body.appendChild(form);
      form.submit();
    }

    function closePopup() {
      const popup = document.querySelector('.custom-popup-overlay');
      if (popup) {
        popup.remove();
      }
    }
  </script>
  <script>
    function chooseAppealAction(appealId, bookingId, action) {
      if (!['refund', 'change'].includes(action)) return;
      const confirmText = action === 'refund' ?
        'Are you sure you want to choose Refund?' :
        'Are you sure you want to choose Change Guider?';
      if (!confirm(confirmText)) return;

      fetch('../admin/process_appeal.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: action === 'refund' ? 'hiker_chose_refund' : 'hiker_chose_change',
          appealId: appealId,
          bookingId: bookingId
        })
      })
      .then(r => r.json())
      .then(data => {
        if (data && data.success) {
          // Reload to reflect new state (admin will then process refund or change)
          window.location.reload();
        } else {
          alert((data && data.message) || 'Failed to submit your choice.');
        }
      })
      .catch(() => alert('Network error while submitting your choice.'));
    }
  </script>

  <script>
    // Fallback function jika Google Maps tak load
    function showMapFallback() {
      document.querySelectorAll('.map-placeholder').forEach(function(el){
        const lat = el.getAttribute('data-latitude');
        const lng = el.getAttribute('data-longitude');
        if (lat && lng && !isNaN(parseFloat(lat)) && !isNaN(parseFloat(lng))) {
          el.innerHTML = '<a href="https://www.google.com/maps?q=' + lat + ',' + lng + '" target="_blank" style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--guider-blue);text-decoration:none;font-size:14px;"><i class="fas fa-map-marker-alt me-2"></i>View on Maps</a>';
        } else {
          el.innerHTML = '<span style="display:flex;align-items:center;justify-content:center;height:100%;color:#999;font-size:12px;">No location</span>';
        }
      });
    }
    
    function renderRowMaps(){
      if(!(window.google && google.maps)) {
        console.log('Google Maps not loaded, showing fallback');
        showMapFallback();
        return;
      }
      document.querySelectorAll('.map-placeholder').forEach(function(el){
        const lat = parseFloat(el.getAttribute('data-latitude'));
        const lng = parseFloat(el.getAttribute('data-longitude'));
        if (isNaN(lat) || isNaN(lng)) {
          el.innerHTML = '<span style="display:flex;align-items:center;justify-content:center;height:100%;color:#999;font-size:12px;">No location</span>';
          return;
        }
        try {
          const m = new google.maps.Map(el, {
            center: { lat, lng },
            zoom: 10,
            disableDefaultUI: true,
            gestureHandling: 'none'
          });
          new google.maps.Marker({ position: { lat, lng }, map: m });
          el.style.cursor = 'pointer';
          el.title = 'Open in Google Maps';
          el.addEventListener('click', function(){
            window.open(`https://www.google.com/maps?q=${lat},${lng}`, '_blank', 'noopener');
          });
        } catch(e) {
          console.error('Map error:', e);
          el.innerHTML = '<a href="https://www.google.com/maps?q=' + lat + ',' + lng + '" target="_blank" style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--guider-blue);text-decoration:none;font-size:14px;"><i class="fas fa-map-marker-alt me-2"></i>View on Maps</a>';
        }
      });
    }
    window.initMap = function(){ try { renderRowMaps(); } catch(e) { console.error(e); showMapFallback(); } };
    
    // Fallback timer - jika Google Maps tak load dalam 5 saat, show fallback
    setTimeout(function() {
      if (!(window.google && google.maps)) {
        console.log('Google Maps timeout, showing fallback');
        showMapFallback();
      }
    }, 5000);
  </script>
  <script async src="https://maps.googleapis.com/maps/api/js?key=AIzaSyBQBKen6oHNUxX1Mg6lL5rZMVy_LReklqY&loading=async&callback=initMap"></script>

<?php include_once '../AIChatbox/chatbox_include.php'; ?>

</body>
</html>

<?php
// Close database connection at the very end after all queries are done
if (isset($conn)) {
    $conn->close();
}
?>
