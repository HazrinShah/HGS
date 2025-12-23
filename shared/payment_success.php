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
$status = $_GET['status'] ?? $_GET['status_id'] ?? $_GET['payment_status'] ?? $_GET['result'] ?? '';
$billcode = $_GET['billcode'] ?? $_GET['bill_code'] ?? $_GET['billCode'] ?? '';
$order_id = $_GET['order_id'] ?? $_GET['orderid'] ?? $_GET['refno'] ?? '';
$bookingID = $_GET['bookingID'] ?? null;

// check untuk info messages
$info_message = '';
if (isset($_GET['info'])) {
    switch ($_GET['info']) {
        case 'payment_already_processed':
            $info_message = 'Payment has already been processed for this booking.';
            break;
    }
}


// connect database
include 'db_connection.php';

// Include PHPMailer configuration
require_once __DIR__ . '/email_config.php';


// check payment successful ke tak (ToyyibPay return status_id=1 untuk success, status_id=3 untuk failed)
// check dulu kalau ni failed payment dan redirect terus
if ($status == '2' || $status == '3' || $status == 'failed' || $status == '0' || $status == 'cancel' || $status == 'cancelled') {
    // payment failed - redirect ke failure page
    header("Location: payment_failed.php?status=" . urlencode($status) . "&billcode=" . urlencode($billcode) . "&order_id=" . urlencode($order_id));
    exit;
}

if (($status == '1' || $status == 'success') && !empty($billcode)) {
    // update payment transaction status
    $updatePaymentQuery = "UPDATE payment_transactions SET status = 'completed', completedAt = NOW() WHERE billCode = ?";
    $stmt = $conn->prepare($updatePaymentQuery);
    $stmt->bind_param("s", $billcode);
    $stmt->execute();
    $stmt->close();
    
    // dapat booking ID dari payment transaction
    $getBookingQuery = "SELECT bookingID FROM payment_transactions WHERE billCode = ?";
    $stmt = $conn->prepare($getBookingQuery);
    $stmt->bind_param("s", $billcode);
    $stmt->execute();
    $result = $stmt->get_result();
    $paymentData = $result->fetch_assoc();
    $stmt->close();
    
    if ($paymentData) {
        $bookingID = $paymentData['bookingID'];
        
        // check kalau ni open group booking
        $checkGroupQuery = "SELECT groupType FROM booking WHERE bookingID = ?";
        $stmt = $conn->prepare($checkGroupQuery);
        $stmt->bind_param("i", $bookingID);
        $stmt->execute();
        $result = $stmt->get_result();
        $bookingInfo = $result->fetch_assoc();
        $stmt->close();
        
        // update booking status jadi accepted (payment completed)
        // untuk open groups, jangan check hikerID sebab participants bukan owner
        if ($bookingInfo && $bookingInfo['groupType'] === 'open') {
            // untuk open groups, update booking status je (participants track separately)
            $updateBookingQuery = "UPDATE booking SET status = 'accepted' WHERE bookingID = ?";
            $stmt = $conn->prepare($updateBookingQuery);
            $stmt->bind_param("i", $bookingID);
        } else {
            // untuk close groups, verify ownership
            $updateBookingQuery = "UPDATE booking SET status = 'accepted' WHERE bookingID = ? AND hikerID = ?";
            $stmt = $conn->prepare($updateBookingQuery);
            $stmt->bind_param("ii", $bookingID, $hikerID);
        }
        $stmt->execute();
        $stmt->close();
        
        // hantar payment receipt email to hiker
        $userQuery = "SELECT username, email FROM hiker WHERE hikerID = ?";
        $stmt = $conn->prepare($userQuery);
        $stmt->bind_param("i", $hikerID);
        $stmt->execute();
        $result = $stmt->get_result();
        $userData = $result->fetch_assoc();
        $stmt->close();
        
        if ($userData && !empty($userData['email'])) {
            sendPaymentReceiptEmail($userData['email'], $userData['username'], $bookingID, $billcode, $conn);
        }
        
        // Send booking notification email to guider
        sendGuiderBookingNotificationEmail($bookingID, $conn);
        
        // ambil booking details untuk display
        $bookingQuery = "SELECT b.*, g.username, m.name 
                         FROM booking b 
                         JOIN guider g ON b.guiderID = g.guiderID 
                         JOIN mountain m ON b.mountainID = m.mountainID 
                         WHERE b.bookingID = ? AND b.hikerID = ?";
        $stmt = $conn->prepare($bookingQuery);
        $stmt->bind_param("ii", $bookingID, $hikerID);
        $stmt->execute();
        $result = $stmt->get_result();
        $booking = $result->fetch_assoc();
        $stmt->close();
        
        $success = true;
        $bookingData = $booking;
    } else {
        $success = false;
        $error = "Payment record not found";
    }
} else {
    // If status check failed, try to find the payment by billcode anyway
    if (!empty($billcode)) {
        $checkPaymentQuery = "SELECT pt.*, b.*, g.username, m.name 
                             FROM payment_transactions pt 
                             JOIN booking b ON pt.bookingID = b.bookingID 
                             JOIN guider g ON b.guiderID = g.guiderID 
                             JOIN mountain m ON b.mountainID = m.mountainID 
                             WHERE pt.billCode = ? AND b.hikerID = ?";
        $stmt = $conn->prepare($checkPaymentQuery);
        $stmt->bind_param("si", $billcode, $hikerID);
        $stmt->execute();
        $result = $stmt->get_result();
        $paymentData = $result->fetch_assoc();
        $stmt->close();
        
        if ($paymentData) {
            // Payment found, update status and show success
            $updatePaymentQuery = "UPDATE payment_transactions SET status = 'completed', completedAt = NOW() WHERE billCode = ?";
            $stmt = $conn->prepare($updatePaymentQuery);
            $stmt->bind_param("s", $billcode);
            $stmt->execute();
            $stmt->close();
            
            // Check if this is an open group booking
            $checkGroupQuery = "SELECT groupType FROM booking WHERE bookingID = ?";
            $stmt = $conn->prepare($checkGroupQuery);
            $stmt->bind_param("i", $paymentData['bookingID']);
            $stmt->execute();
            $result = $stmt->get_result();
            $bookingInfo = $result->fetch_assoc();
            $stmt->close();
            
            // Update booking status
            // For open groups, don't check hikerID since participants aren't the owner
            if ($bookingInfo && $bookingInfo['groupType'] === 'open') {
                $updateBookingQuery = "UPDATE booking SET status = 'accepted' WHERE bookingID = ?";
                $stmt = $conn->prepare($updateBookingQuery);
                $stmt->bind_param("i", $paymentData['bookingID']);
            } else {
                $updateBookingQuery = "UPDATE booking SET status = 'accepted' WHERE bookingID = ? AND hikerID = ?";
                $stmt = $conn->prepare($updateBookingQuery);
                $stmt->bind_param("ii", $paymentData['bookingID'], $hikerID);
            }
            $stmt->execute();
            $stmt->close();
            
            // Send email to hiker
            $userQuery = "SELECT username, email FROM hiker WHERE hikerID = ?";
            $stmt = $conn->prepare($userQuery);
            $stmt->bind_param("i", $hikerID);
            $stmt->execute();
            $result = $stmt->get_result();
            $userData = $result->fetch_assoc();
            $stmt->close();
            
            if ($userData && !empty($userData['email'])) {
                sendPaymentReceiptEmail($userData['email'], $userData['username'], $paymentData['bookingID'], $billcode, $conn);
            }
            
            // Send booking notification email to guider
            sendGuiderBookingNotificationEmail($paymentData['bookingID'], $conn);
            
            $success = true;
            $bookingData = $paymentData;
        } else {
            $success = false;
            $error = "Payment not found or access denied";
        }
    } else {
        $success = false;
        $error = "Payment was not successful - No billcode provided";
    }
}

$conn->close();

// Function to send payment receipt email
function sendPaymentReceiptEmail($email, $username, $bookingID, $billcode, $conn) {
    // Get booking details for email with guider contact info
    $bookingQuery = "SELECT b.*, g.username as guiderName, g.phone_number as guiderPhone, g.email as guiderEmail, m.name as mountainName, m.location as mountainLocation
                     FROM booking b 
                     JOIN guider g ON b.guiderID = g.guiderID 
                     JOIN mountain m ON b.mountainID = m.mountainID 
                     WHERE b.bookingID = ?";
    $stmt = $conn->prepare($bookingQuery);
    $stmt->bind_param("i", $bookingID);
    $stmt->execute();
    $result = $stmt->get_result();
    $booking = $result->fetch_assoc();
    $stmt->close();
    
    if (!$booking) return;
    
    // Calculate trip duration
    $startDate = new DateTime($booking['startDate']);
    $endDate = new DateTime($booking['endDate']);
    $tripDuration = $startDate->diff($endDate)->days + 1;
    $tripDurationText = $tripDuration == 1 ? '1 Day' : $tripDuration . ' Days';
    
    // Format dates nicely
    $formattedStartDate = date('l, F j, Y', strtotime($booking['startDate']));
    $formattedEndDate = date('l, F j, Y', strtotime($booking['endDate']));
    $paymentDate = date('F j, Y \a\t g:i A');
    
    // Email content
    $subject = "✅ Booking Confirmed - " . htmlspecialchars($booking['mountainName']) . " | Booking #$bookingID";
    
    $message = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Booking Confirmation - Hiking Guidance System</title>
    </head>
    <body style='font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f5f5f5;'>
        <table width='100%' cellpadding='0' cellspacing='0' style='background-color: #f5f5f5; padding: 20px 0;'>
            <tr>
                <td align='center'>
                    <table width='600' cellpadding='0' cellspacing='0' style='background-color: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1);'>
                        
                        <!-- Header -->
                        <tr>
                            <td style='background: linear-gradient(135deg, #1e40af, #3b82f6); color: white; padding: 30px; text-align: center;'>
                                <h1 style='margin: 0; font-size: 26px;'>🏔️ Booking Confirmed!</h1>
                                <p style='margin: 10px 0 0 0; font-size: 16px; opacity: 0.9;'>Your hiking adventure awaits</p>
                            </td>
                        </tr>
                        
                        <!-- Main Content -->
                        <tr>
                            <td style='padding: 30px;'>
                                
                                <!-- Success Banner -->
                                <table width='100%' cellpadding='15' style='background-color: #f0fdf4; border: 2px solid #22c55e; border-radius: 10px; margin-bottom: 25px;'>
                                    <tr>
                                        <td style='text-align: center;'>
                                            <h2 style='color: #059669; margin: 0 0 10px 0; font-size: 22px;'>🎉 Thank You, $username!</h2>
                                            <p style='margin: 0; font-size: 15px; color: #047857;'>
                                                Your payment has been successfully processed and your hiking trip is now confirmed!
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                                
                                <!-- Booking Summary Box -->
                                <table width='100%' cellpadding='0' cellspacing='0' style='background-color: #f8fafc; border-radius: 10px; margin-bottom: 25px; border: 1px solid #e2e8f0;'>
                                    <tr>
                                        <td style='padding: 20px;'>
                                            <h3 style='color: #1e40af; margin: 0 0 20px 0; font-size: 18px; border-bottom: 2px solid #3b82f6; padding-bottom: 10px;'>📋 BOOKING DETAILS</h3>
                                            
                                            <table width='100%' cellpadding='8' cellspacing='0'>
                                                <tr>
                                                    <td style='color: #6b7280; font-weight: 600; width: 40%;'>Booking Reference:</td>
                                                    <td style='color: #1e40af; font-weight: 700; font-size: 16px;'>#$bookingID</td>
                                                </tr>
                                                <tr style='background-color: #ffffff;'>
                                                    <td style='color: #6b7280; font-weight: 600;'>Mountain:</td>
                                                    <td style='color: #111827; font-weight: 600;'>" . htmlspecialchars($booking['mountainName']) . "</td>
                                                </tr>
                                                <tr>
                                                    <td style='color: #6b7280; font-weight: 600;'>Location:</td>
                                                    <td style='color: #111827;'>" . htmlspecialchars($booking['mountainLocation']) . "</td>
                                                </tr>
                                                <tr style='background-color: #ffffff;'>
                                                    <td style='color: #6b7280; font-weight: 600;'>Trip Duration:</td>
                                                    <td style='color: #111827; font-weight: 600;'>$tripDurationText</td>
                                                </tr>
                                                <tr>
                                                    <td style='color: #6b7280; font-weight: 600;'>Start Date:</td>
                                                    <td style='color: #111827;'>$formattedStartDate</td>
                                                </tr>
                                                <tr style='background-color: #ffffff;'>
                                                    <td style='color: #6b7280; font-weight: 600;'>End Date:</td>
                                                    <td style='color: #111827;'>$formattedEndDate</td>
                                                </tr>
                                                <tr>
                                                    <td style='color: #6b7280; font-weight: 600;'>Number of Hikers:</td>
                                                    <td style='color: #111827;'>" . $booking['totalHiker'] . " person(s)</td>
                                                </tr>
                                                <tr style='background-color: #ffffff;'>
                                                    <td style='color: #6b7280; font-weight: 600;'>Group Type:</td>
                                                    <td style='color: #111827;'>" . ucfirst($booking['groupType']) . " Group</td>
                                                </tr>
                                                <tr>
                                                    <td style='color: #6b7280; font-weight: 600;'>Meeting Point:</td>
                                                    <td style='color: #111827;'>" . htmlspecialchars($booking['location']) . "</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                </table>
                                
                                <!-- Guider Info Box -->
                                <table width='100%' cellpadding='0' cellspacing='0' style='background-color: #eff6ff; border-radius: 10px; margin-bottom: 25px; border: 1px solid #bfdbfe;'>
                                    <tr>
                                        <td style='padding: 20px;'>
                                            <h3 style='color: #1e40af; margin: 0 0 15px 0; font-size: 18px;'>👤 YOUR GUIDER</h3>
                                            
                                            <table width='100%' cellpadding='8' cellspacing='0'>
                                                <tr>
                                                    <td style='color: #6b7280; font-weight: 600; width: 40%;'>Name:</td>
                                                    <td style='color: #111827; font-weight: 600;'>" . htmlspecialchars($booking['guiderName']) . "</td>
                                                </tr>
                                                <tr>
                                                    <td style='color: #6b7280; font-weight: 600;'>Phone Number:</td>
                                                    <td style='color: #1e40af; font-weight: 600;'>" . htmlspecialchars($booking['guiderPhone'] ?? 'Will be provided') . "</td>
                                                </tr>
                                                <tr>
                                                    <td style='color: #6b7280; font-weight: 600;'>Email:</td>
                                                    <td style='color: #1e40af;'>" . htmlspecialchars($booking['guiderEmail']) . "</td>
                                                </tr>
                                            </table>
                                            
                                            <p style='margin: 15px 0 0 0; font-size: 13px; color: #4b5563; font-style: italic;'>
                                                💡 Your guider will contact you within 24 hours to discuss trip details.
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                                
                                <!-- Payment Summary -->
                                <table width='100%' cellpadding='0' cellspacing='0' style='background: linear-gradient(135deg, #059669, #10b981); border-radius: 10px; margin-bottom: 25px;'>
                                    <tr>
                                        <td style='padding: 20px; text-align: center; color: white;'>
                                            <h3 style='margin: 0 0 10px 0; font-size: 16px; opacity: 0.9;'>💳 PAYMENT SUMMARY</h3>
                                            <p style='margin: 0; font-size: 28px; font-weight: 700;'>RM " . number_format($booking['price'], 2) . "</p>
                                            <p style='margin: 10px 0 0 0; font-size: 13px; opacity: 0.8;'>
                                                Reference: $billcode<br>
                                                Paid on: $paymentDate
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                                
                                <!-- CTA Buttons -->
                                <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom: 25px;'>
                                    <tr>
                                        <td align='center'>
                                            <a href='http://localhost/HGS/hiker/HYourGuider.php' style='display: inline-block; background-color: #1e40af; color: white; padding: 14px 28px; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 15px; margin: 5px;'>View My Booking</a>
                                            <a href='http://localhost/HGS/hiker/HBooking.php' style='display: inline-block; background-color: #059669; color: white; padding: 14px 28px; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 15px; margin: 5px;'>Book Another Trip</a>
                                        </td>
                                    </tr>
                                </table>
                                
                                <p style='font-size: 15px; color: #374151; text-align: center; margin: 0;'>
                                    We're excited to be part of your hiking adventure!<br>
                                    <strong>See you on the mountain! 🥾</strong>
                                </p>
                                
                            </td>
                        </tr>
                        
                        <!-- Footer -->
                        <tr>
                            <td style='background-color: #f8fafc; padding: 25px; text-align: center; border-top: 1px solid #e2e8f0;'>
                                <p style='margin: 0 0 10px 0; color: #374151; font-weight: 600;'>Hiking Guidance System</p>
                                <p style='margin: 0; font-size: 12px; color: #6b7280;'>
                                    This is an automated email. Please save this receipt for your records.<br>
                                    © " . date('Y') . " Hiking Guidance System. All rights reserved.
                                </p>
                            </td>
                        </tr>
                        
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>
    ";
    
    // Send email using PHPMailer
    $mailSent = sendEmail($email, $subject, $message, $username);
    
    if ($mailSent) {
        error_log("Booking confirmation email sent successfully to: $email for booking #$bookingID");
        return true;
    } else {
        error_log("Failed to send booking confirmation email to: $email for booking #$bookingID");
        return false;
    }
}

// Function to send booking notification email to guider
function sendGuiderBookingNotificationEmail($bookingID, $conn) {
    // Get booking details with hiker and guider information including phone numbers
    $bookingQuery = "SELECT b.*, g.username as guiderUsername, g.email as guiderEmail, 
                            h.username as hikerUsername, h.email as hikerEmail, h.phone_number as hikerPhone,
                            m.name as mountainName, m.location as mountainLocation
                     FROM booking b 
                     JOIN guider g ON b.guiderID = g.guiderID 
                     JOIN hiker h ON b.hikerID = h.hikerID
                     JOIN mountain m ON b.mountainID = m.mountainID 
                     WHERE b.bookingID = ?";
    $stmt = $conn->prepare($bookingQuery);
    $stmt->bind_param("i", $bookingID);
    $stmt->execute();
    $result = $stmt->get_result();
    $booking = $result->fetch_assoc();
    $stmt->close();
    
    if (!$booking || empty($booking['guiderEmail'])) {
        error_log("Failed to get guider email for booking #$bookingID - booking or guider email not found");
        return false;
    }
    
    // Get hiker details from bookinghikerdetails table (emergency contact info)
    $hikerDetailsQuery = "SELECT hikerName, phoneNumber, emergencyContactName, emergencyContactNumber 
                          FROM bookinghikerdetails WHERE bookingID = ? LIMIT 1";
    $stmt = $conn->prepare($hikerDetailsQuery);
    $stmt->bind_param("i", $bookingID);
    $stmt->execute();
    $result = $stmt->get_result();
    $hikerDetails = $result->fetch_assoc();
    $stmt->close();
    
    $guiderEmail = $booking['guiderEmail'];
    $guiderUsername = $booking['guiderUsername'];
    $hikerUsername = $booking['hikerUsername'];
    $mountainName = $booking['mountainName'];
    
    // Calculate trip duration
    $startDate = new DateTime($booking['startDate']);
    $endDate = new DateTime($booking['endDate']);
    $tripDuration = $startDate->diff($endDate)->days + 1;
    $tripDurationText = $tripDuration == 1 ? '1 Day' : $tripDuration . ' Days';
    
    // Format dates
    $formattedStartDate = date('l, F j, Y', strtotime($booking['startDate']));
    $formattedEndDate = date('l, F j, Y', strtotime($booking['endDate']));
    $bookingDate = date('F j, Y \a\t g:i A');
    
    // Email content for guider
    $subject = "🎉 New Booking Received - " . htmlspecialchars($mountainName) . " | #$bookingID";
    
    $message = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>New Booking Notification - Hiking Guidance System</title>
    </head>
    <body style='font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f5f5f5;'>
        <table width='100%' cellpadding='0' cellspacing='0' style='background-color: #f5f5f5; padding: 20px 0;'>
            <tr>
                <td align='center'>
                    <table width='600' cellpadding='0' cellspacing='0' style='background-color: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1);'>
                        
                        <!-- Header -->
                        <tr>
                            <td style='background: linear-gradient(135deg, #059669, #10b981); color: white; padding: 30px; text-align: center;'>
                                <h1 style='margin: 0; font-size: 26px;'>🎉 New Booking Received!</h1>
                                <p style='margin: 10px 0 0 0; font-size: 16px; opacity: 0.9;'>You have a new hiking trip assignment</p>
                            </td>
                        </tr>
                        
                        <!-- Main Content -->
                        <tr>
                            <td style='padding: 30px;'>
                                
                                <!-- Notification Banner -->
                                <table width='100%' cellpadding='15' style='background-color: #eff6ff; border: 2px solid #3b82f6; border-radius: 10px; margin-bottom: 25px;'>
                                    <tr>
                                        <td style='text-align: center;'>
                                            <h2 style='color: #1e40af; margin: 0 0 10px 0; font-size: 22px;'>Hello, $guiderUsername!</h2>
                                            <p style='margin: 0; font-size: 15px; color: #1e3a8a;'>
                                                Great news! A hiker has booked your services and payment has been confirmed.<br>
                                                Please review the details below and prepare for the adventure!
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                                
                                <!-- Trip Summary Box -->
                                <table width='100%' cellpadding='0' cellspacing='0' style='background-color: #f8fafc; border-radius: 10px; margin-bottom: 25px; border: 1px solid #e2e8f0;'>
                                    <tr>
                                        <td style='padding: 20px;'>
                                            <h3 style='color: #059669; margin: 0 0 20px 0; font-size: 18px; border-bottom: 2px solid #10b981; padding-bottom: 10px;'>📋 TRIP DETAILS</h3>
                                            
                                            <table width='100%' cellpadding='8' cellspacing='0'>
                                                <tr>
                                                    <td style='color: #6b7280; font-weight: 600; width: 40%;'>Booking Reference:</td>
                                                    <td style='color: #059669; font-weight: 700; font-size: 16px;'>#$bookingID</td>
                                                </tr>
                                                <tr style='background-color: #ffffff;'>
                                                    <td style='color: #6b7280; font-weight: 600;'>Mountain:</td>
                                                    <td style='color: #111827; font-weight: 600;'>" . htmlspecialchars($mountainName) . "</td>
                                                </tr>
                                                <tr>
                                                    <td style='color: #6b7280; font-weight: 600;'>Location:</td>
                                                    <td style='color: #111827;'>" . htmlspecialchars($booking['mountainLocation']) . "</td>
                                                </tr>
                                                <tr style='background-color: #ffffff;'>
                                                    <td style='color: #6b7280; font-weight: 600;'>Trip Duration:</td>
                                                    <td style='color: #111827; font-weight: 600;'>$tripDurationText</td>
                                                </tr>
                                                <tr>
                                                    <td style='color: #6b7280; font-weight: 600;'>Start Date:</td>
                                                    <td style='color: #111827;'>$formattedStartDate</td>
                                                </tr>
                                                <tr style='background-color: #ffffff;'>
                                                    <td style='color: #6b7280; font-weight: 600;'>End Date:</td>
                                                    <td style='color: #111827;'>$formattedEndDate</td>
                                                </tr>
                                                <tr>
                                                    <td style='color: #6b7280; font-weight: 600;'>Number of Hikers:</td>
                                                    <td style='color: #111827;'>" . $booking['totalHiker'] . " person(s)</td>
                                                </tr>
                                                <tr style='background-color: #ffffff;'>
                                                    <td style='color: #6b7280; font-weight: 600;'>Group Type:</td>
                                                    <td style='color: #111827;'>" . ucfirst($booking['groupType']) . " Group</td>
                                                </tr>
                                                <tr>
                                                    <td style='color: #6b7280; font-weight: 600;'>Meeting Point:</td>
                                                    <td style='color: #111827;'>" . htmlspecialchars($booking['location']) . "</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                </table>
                                
                                <!-- Hiker Information Box -->
                                <table width='100%' cellpadding='0' cellspacing='0' style='background-color: #fef3c7; border-radius: 10px; margin-bottom: 25px; border: 1px solid #fcd34d;'>
                                    <tr>
                                        <td style='padding: 20px;'>
                                            <h3 style='color: #92400e; margin: 0 0 15px 0; font-size: 18px;'>👤 HIKER INFORMATION</h3>
                                            
                                            <table width='100%' cellpadding='8' cellspacing='0'>
                                                <tr>
                                                    <td style='color: #78350f; font-weight: 600; width: 40%;'>Name:</td>
                                                    <td style='color: #111827; font-weight: 600;'>" . htmlspecialchars($hikerDetails['hikerName'] ?? $hikerUsername) . "</td>
                                                </tr>
                                                <tr>
                                                    <td style='color: #78350f; font-weight: 600;'>Email:</td>
                                                    <td style='color: #1e40af;'>" . htmlspecialchars($booking['hikerEmail']) . "</td>
                                                </tr>
                                                <tr>
                                                    <td style='color: #78350f; font-weight: 600;'>Phone Number:</td>
                                                    <td style='color: #059669; font-weight: 600;'>" . htmlspecialchars($hikerDetails['phoneNumber'] ?? $booking['hikerPhone'] ?? 'Not provided') . "</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                </table>
                                
                                <!-- Emergency Contact Box -->
                                <table width='100%' cellpadding='0' cellspacing='0' style='background-color: #fee2e2; border-radius: 10px; margin-bottom: 25px; border: 1px solid #fca5a5;'>
                                    <tr>
                                        <td style='padding: 20px;'>
                                            <h3 style='color: #991b1b; margin: 0 0 15px 0; font-size: 18px;'>🆘 EMERGENCY CONTACT</h3>
                                            
                                            <table width='100%' cellpadding='8' cellspacing='0'>
                                                <tr>
                                                    <td style='color: #7f1d1d; font-weight: 600; width: 40%;'>Contact Name:</td>
                                                    <td style='color: #111827; font-weight: 600;'>" . htmlspecialchars($hikerDetails['emergencyContactName'] ?? 'Not provided') . "</td>
                                                </tr>
                                                <tr>
                                                    <td style='color: #7f1d1d; font-weight: 600;'>Contact Number:</td>
                                                    <td style='color: #dc2626; font-weight: 600;'>" . htmlspecialchars($hikerDetails['emergencyContactNumber'] ?? 'Not provided') . "</td>
                                                </tr>
                                            </table>
                                            
                                            <p style='margin: 15px 0 0 0; font-size: 12px; color: #991b1b; font-style: italic;'>
                                                ⚠️ Please save this emergency contact information for safety purposes.
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                                
                                <!-- Earnings Summary -->
                                <table width='100%' cellpadding='0' cellspacing='0' style='background: linear-gradient(135deg, #059669, #10b981); border-radius: 10px; margin-bottom: 25px;'>
                                    <tr>
                                        <td style='padding: 20px; text-align: center; color: white;'>
                                            <h3 style='margin: 0 0 10px 0; font-size: 16px; opacity: 0.9;'>💰 YOUR EARNINGS</h3>
                                            <p style='margin: 0; font-size: 28px; font-weight: 700;'>RM " . number_format($booking['price'], 2) . "</p>
                                            <p style='margin: 10px 0 0 0; font-size: 13px; opacity: 0.8;'>
                                                Booking confirmed on: $bookingDate
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                                
                                <!-- Action Required Box -->
                                <table width='100%' cellpadding='15' style='background-color: #eff6ff; border-left: 4px solid #3b82f6; border-radius: 0 10px 10px 0; margin-bottom: 25px;'>
                                    <tr>
                                        <td>
                                            <h4 style='color: #1e40af; margin: 0 0 12px 0; font-size: 16px;'>✅ ACTION REQUIRED</h4>
                                            <ul style='margin: 0; padding-left: 20px; color: #1e3a8a; font-size: 14px;'>
                                                <li style='margin-bottom: 8px;'><strong>Contact the hiker within 24 hours</strong> to introduce yourself</li>
                                                <li style='margin-bottom: 8px;'><strong>Confirm meeting time and exact location</strong> for the trip day</li>
                                                <li style='margin-bottom: 8px;'><strong>Share your contact number</strong> with the hiker</li>
                                                <li style='margin-bottom: 8px;'><strong>Discuss any special requirements</strong> or health conditions</li>
                                                <li><strong>Prepare itinerary and safety briefing</strong> materials</li>
                                            </ul>
                                        </td>
                                    </tr>
                                </table>
                                
                                <!-- CTA Buttons -->
                                <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom: 25px;'>
                                    <tr>
                                        <td align='center'>
                                            <a href='http://localhost/HGS/guider/GBooking.php' style='display: inline-block; background-color: #059669; color: white; padding: 14px 28px; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 15px; margin: 5px;'>View Booking Details</a>
                                            <a href='http://localhost/HGS/guider/GHistory.php' style='display: inline-block; background-color: #1e40af; color: white; padding: 14px 28px; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 15px; margin: 5px;'>My Booking History</a>
                                        </td>
                                    </tr>
                                </table>
                                
                                <p style='font-size: 15px; color: #374151; text-align: center; margin: 0;'>
                                    Thank you for being an amazing guide!<br>
                                    <strong>Let's make this adventure unforgettable! 🏔️</strong>
                                </p>
                                
                            </td>
                        </tr>
                        
                        <!-- Footer -->
                        <tr>
                            <td style='background-color: #f8fafc; padding: 25px; text-align: center; border-top: 1px solid #e2e8f0;'>
                                <p style='margin: 0 0 10px 0; color: #374151; font-weight: 600;'>Hiking Guidance System</p>
                                <p style='margin: 0; font-size: 12px; color: #6b7280;'>
                                    This is an automated notification. Log in to your account for more details.<br>
                                    © " . date('Y') . " Hiking Guidance System. All rights reserved.
                                </p>
                            </td>
                        </tr>
                        
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>
    ";
    
    // Send email using PHPMailer
    $mailSent = sendEmail($guiderEmail, $subject, $message, $guiderUsername);
    
    if ($mailSent) {
        error_log("Booking notification email sent successfully to guider: $guiderEmail for booking #$bookingID");
        return true;
    } else {
        error_log("Failed to send booking notification email to guider: $guiderEmail for booking #$bookingID");
        return false;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Payment Result – Hiking Guidance System</title>
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

    /* Main Content Container */
    .main-content {
      padding: 2rem 0;
      min-height: 100vh;
    }

    /* Section Header */
    .section-header {
      background: white;
      border-radius: 20px;
      padding: 2rem;
      box-shadow: 0 10px 30px rgba(30, 64, 175, 0.1);
      border: 1px solid rgba(30, 64, 175, 0.1);
      margin-bottom: 2rem;
      text-align: center;
    }

    .section-title {
      color: var(--guider-blue-dark);
      font-weight: 700;
      margin-bottom: 1rem;
      font-size: 2rem;
    }

    .section-subtitle {
      color: #64748b;
      font-size: 1.1rem;
      margin-bottom: 0;
    }

    /* Payment Result Card */
    .payment-result-card {
      background: white;
      border-radius: 20px;
      padding: 3rem 2rem;
      box-shadow: 0 10px 30px rgba(30, 64, 175, 0.1);
      border: 1px solid rgba(30, 64, 175, 0.1);
      text-align: center;
      max-width: 600px;
      margin: 0 auto;
    }

    .success-icon {
      width: 80px;
      height: 80px;
      background: linear-gradient(135deg, #10b981, #059669);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 2rem;
      animation: successPulse 2s infinite;
    }

    .error-icon {
      width: 80px;
      height: 80px;
      background: linear-gradient(135deg, #ef4444, #dc2626);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 2rem;
    }

    @keyframes successPulse {
      0% { transform: scale(1); }
      50% { transform: scale(1.05); }
      100% { transform: scale(1); }
    }

    .result-title {
      color: var(--guider-blue-dark);
      font-weight: 700;
      font-size: 1.75rem;
      margin-bottom: 1rem;
    }

    .result-message {
      color: #64748b;
      font-size: 1.1rem;
      margin-bottom: 2rem;
      line-height: 1.6;
    }

    /* Booking Summary */
    .booking-summary {
      background: #f0fdf4;
      border-radius: 15px;
      padding: 1.5rem;
      margin: 2rem 0;
      border: 2px solid #10b981;
      text-align: left;
    }

    .booking-summary-title {
      color: #059669;
      font-weight: 700;
      font-size: 1.25rem;
      margin-bottom: 1rem;
      text-align: center;
    }

    .booking-details {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1rem;
    }

    .booking-detail {
      display: flex;
      flex-direction: column;
    }

    .booking-detail-label {
      color: #64748b;
      font-size: 0.85rem;
      font-weight: 600;
      margin-bottom: 0.25rem;
    }

    .booking-detail-value {
      color: #059669;
      font-weight: 600;
      font-size: 1rem;
    }

    /* Action Buttons */
    .action-buttons {
      display: flex;
      gap: 1rem;
      justify-content: center;
      margin-top: 2rem;
    }

    .action-btn {
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
    }

    .action-btn:hover {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(30, 64, 175, 0.4);
      color: white;
    }

    .secondary-btn {
      background: linear-gradient(135deg, #f8fafc, #e2e8f0);
      color: var(--guider-blue-dark);
      border: 2px solid var(--guider-blue-soft);
    }

    .secondary-btn:hover {
      background: linear-gradient(135deg, var(--guider-blue-soft), #dbeafe);
      color: var(--guider-blue-dark);
    }

    /* Mobile Responsive */
    @media (max-width: 768px) {
      .section-title {
        font-size: 1.75rem;
      }
      
      .booking-details {
        grid-template-columns: 1fr;
      }
      
      .action-buttons {
        flex-direction: column;
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
        <a class="navbar-brand" href="../index.html">
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
            <li class="nav-item"><a class="nav-link" href="../hiker/HHomePage.php">Home</a></li>
            <li class="nav-item"><a class="nav-link" href="../hiker/HProfile.php">Profile</a></li>
            <li class="nav-item"><a class="nav-link" href="../hiker/HBooking.php">Book Guider</a></li>
            <li class="nav-item"><a class="nav-link active" href="../hiker/HPayment.php">Payment</a></li>
            <li class="nav-item"><a class="nav-link" href="../hiker/HYourGuider.php">Your Guider</a></li>
            <li class="nav-item"><a class="nav-link" href="../hiker/HRateReview.php">Rate and Review</a></li>
            <li class="nav-item"><a class="nav-link" href="../hiker/HBookingHistory.php">Booking History</a></li>
          </ul>
          <form action="logout.php" method="POST" class="d-flex justify-content-center mt-5">
            <button type="submit" class="btn btn-danger">Logout</button>
          </form>
        </div>
      </div>
    </nav>
  </header>

  <!-- Main Content -->
  <main class="main-content">
    <div class="container">
      <!-- Section Header -->
      <div class="section-header">
        <h1 class="section-title">
          <i class="fas fa-<?php echo $success ? 'check-circle' : 'exclamation-triangle'; ?> me-3"></i>
          Payment Result
        </h1>
        <p class="section-subtitle">
          <?php echo $success ? 'Your payment has been processed successfully' : 'There was an issue with your payment'; ?>
        </p>
      </div>

      <!-- Payment Result Card -->
      <div class="payment-result-card">
        <?php if ($success): ?>
          <div class="success-icon">
            <i class="fas fa-check text-white" style="font-size: 2rem;"></i>
          </div>
          <h2 class="result-title">Payment Successful!</h2>
          <p class="result-message">
            Thank you for your payment. Your hiking booking has been confirmed and you will receive a confirmation email shortly.
            <br>
          </p>
          
          <?php if (!empty($info_message)): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert" style="margin: 1rem 0;">
              <i class="fas fa-info-circle me-2"></i>
              <?php echo htmlspecialchars($info_message); ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
          <?php endif; ?>
          
          <?php if ($bookingData): ?>
            <div class="booking-summary">
              <h5 class="booking-summary-title">
                <i class="fas fa-receipt me-2"></i>Booking Confirmation
              </h5>
              <div class="booking-details">
                <div class="booking-detail">
                  <span class="booking-detail-label">Mountain</span>
                  <span class="booking-detail-value"><?php echo htmlspecialchars($bookingData['name']); ?></span>
                </div>
                <div class="booking-detail">
                  <span class="booking-detail-label">Guider</span>
                  <span class="booking-detail-value"><?php echo htmlspecialchars($bookingData['username']); ?></span>
                </div>
                <div class="booking-detail">
                  <span class="booking-detail-label">Start Date</span>
                  <span class="booking-detail-value"><?php echo date('M j, Y', strtotime($bookingData['startDate'])); ?></span>
                </div>
                <div class="booking-detail">
                  <span class="booking-detail-label">End Date</span>
                  <span class="booking-detail-value"><?php echo date('M j, Y', strtotime($bookingData['endDate'])); ?></span>
                </div>
                <div class="booking-detail">
                  <span class="booking-detail-label">Number of Hikers</span>
                  <span class="booking-detail-value"><?php echo $bookingData['totalHiker']; ?> person(s)</span>
                </div>
                <div class="booking-detail">
                  <span class="booking-detail-label">Booking ID</span>
                  <span class="booking-detail-value">#<?php echo $bookingData['bookingID']; ?></span>
                </div>
              </div>
            </div>
          <?php endif; ?>
          
          <div class="action-buttons">
            <a href="../hiker/HYourGuider.php" class="action-btn">
              <i class="fas fa-user-friends me-3 "></i>View Your Guider
            </a>
            <a href="../hiker/HBooking.php" class="action-btn secondary-btn">
              <i class="fas fa-plus me-2"></i>Book Another Trip
            </a>
          </div>
        <?php else: ?>
          <div class="error-icon">
            <i class="fas fa-times text-white" style="font-size: 2rem;"></i>
          </div>
          <h2 class="result-title">Payment Failed</h2>
          <p class="result-message">
            <?php echo isset($error) ? htmlspecialchars($error) : 'There was an issue processing your payment. Please try again or contact support.'; ?>
          </p>
          
          <div class="action-buttons">
            <a href="../hiker/HPayment.php" class="action-btn">
              <i class="fas fa-arrow-left me-2"></i>Back to Payments
            </a>
            <a href="../hiker/HBooking.php" class="action-btn secondary-btn">
              <i class="fas fa-home me-2"></i>Back to Home
            </a>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </main>

  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
