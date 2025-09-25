<?php
// file ni untuk delete booking auto kalau tak buat payment dalam 5 jam
// This should be run via cron job every hour or so

// Database connection
include 'db_connection.php';

// Function to send booking expired email notification
function sendBookingExpiredEmail($bookingData) {
    $to = $bookingData['email'];
    $username = $bookingData['username'];
    $mountainName = $bookingData['mountainName'];
    $guiderName = $bookingData['guiderName'];
    $startDate = date('M j, Y', strtotime($bookingData['startDate']));
    $endDate = date('M j, Y', strtotime($bookingData['endDate']));
    $price = number_format($bookingData['price'], 2);
    $totalHikers = $bookingData['totalHiker'];
    $bookingID = $bookingData['bookingID'];
    
    $subject = "Booking Expired - Hiking Guidance System";
    
    $message = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #1e3a8a, #1e40af); color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f8fafc; padding: 30px; border-radius: 0 0 10px 10px; }
            .booking-details { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ef4444; }
            .detail-row { display: flex; justify-content: space-between; margin: 10px 0; padding: 8px 0; border-bottom: 1px solid #e5e7eb; }
            .detail-label { font-weight: bold; color: #374151; }
            .detail-value { color: #1e40af; }
            .warning { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 15px; border-radius: 8px; margin: 20px 0; }
            .footer { text-align: center; margin-top: 30px; color: #6b7280; font-size: 14px; }
            .btn { display: inline-block; background: #1e40af; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; margin: 10px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🏔️ Hiking Guidance System</h1>
                <p>Booking Expiration Notice</p>
            </div>
            
            <div class='content'>
                <h2>Dear $username,</h2>
                
                <p>We regret to inform you that your hiking booking has been automatically cancelled due to non-payment within the 5-hour deadline.</p>
                
                <div class='booking-details'>
                    <h3>📋 Cancelled Booking Details</h3>
                    <div class='detail-row'>
                        <span class='detail-label'>Booking ID:</span>
                        <span class='detail-value'>#$bookingID</span>
                    </div>
                    <div class='detail-row'>
                        <span class='detail-label'>Mountain:</span>
                        <span class='detail-value'>$mountainName</span>
                    </div>
                    <div class='detail-row'>
                        <span class='detail-label'>Guider:</span>
                        <span class='detail-value'>$guiderName</span>
                    </div>
                    <div class='detail-row'>
                        <span class='detail-label'>Start Date:</span>
                        <span class='detail-value'>$startDate</span>
                    </div>
                    <div class='detail-row'>
                        <span class='detail-label'>End Date:</span>
                        <span class='detail-value'>$endDate</span>
                    </div>
                    <div class='detail-row'>
                        <span class='detail-label'>Number of Hikers:</span>
                        <span class='detail-value'>$totalHikers person(s)</span>
                    </div>
                    <div class='detail-row'>
                        <span class='detail-label'>Total Amount:</span>
                        <span class='detail-value'>RM $price</span>
                    </div>
                </div>
                
                <div class='warning'>
                    <strong>⚠️ Important Notice:</strong><br>
                    Your booking was automatically cancelled because payment was not completed within 5 hours of booking. This is our standard policy to ensure fair access to hiking slots for all users.
                </div>
                
                <p>If you would like to book this hiking trip again, please visit our website and make a new booking. Remember to complete payment within 5 hours to secure your spot.</p>
                
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='http://localhost/HGS/hiker/HBooking.php' class='btn'>Book New Trip</a>
                </div>
                
                <p>Thank you for your understanding.</p>
                
                <div class='footer'>
                    <p>Best regards,<br>
                    <strong>Hiking Guidance System Team</strong></p>
                    <p>This is an automated message. Please do not reply to this email.</p>
                </div>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: Hiking Guidance System <noreply@hgs.com>" . "\r\n";
    $headers .= "Reply-To: noreply@hgs.com" . "\r\n";
    
    // Try to send email with error handling
    try {
        // Configure SMTP settings for XAMPP
        ini_set('SMTP', 'localhost');
        ini_set('smtp_port', '25');
        ini_set('sendmail_from', 'noreply@hgs.com');
        
        if (mail($to, $subject, $message, $headers)) {
            error_log("Booking expired email sent successfully to: $to");
            return true;
        } else {
            error_log("Failed to send booking expired email to: $to - mail() function returned false");
            return false;
        }
    } catch (Exception $e) {
        error_log("Email sending error: " . $e->getMessage());
        return false;
    }
}

try {
    // Calculate timestamp for 5 hours ago
    // tuko je kat sini kalau nak tukar masa dia
    $fiveHoursAgo = date('Y-m-d H:i:s', strtotime('-5 hours'));
    
    // Find bookings that are older than 5 hours and still pending with user details
    $query = "SELECT b.bookingID, b.hikerID, b.startDate, b.endDate, b.price, b.totalHiker,
                     h.username, h.email,
                     g.username as guiderName,
                     m.name as mountainName
              FROM booking b 
              JOIN hiker h ON b.hikerID = h.hikerID 
              JOIN guider g ON b.guiderID = g.guiderID 
              JOIN mountain m ON b.mountainID = m.mountainID 
              WHERE b.status = 'pending' AND b.created_at < ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $fiveHoursAgo);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $deletedCount = 0;
    
    while ($row = $result->fetch_assoc()) {
        // Send email notification before deletion
        $emailSent = sendBookingExpiredEmail($row);
        
        // Delete the booking
        $deleteQuery = "DELETE FROM booking WHERE bookingID = ?";
        $deleteStmt = $conn->prepare($deleteQuery);
        $deleteStmt->bind_param("i", $row['bookingID']);
        
        if ($deleteStmt->execute()) {
            $deletedCount++;
            
            // Log the deletion with email status
            $emailStatus = $emailSent ? "Email sent" : "Email failed";
            error_log("Auto-deleted expired booking ID: " . $row['bookingID'] . " for hiker ID: " . $row['hikerID'] . " - " . $emailStatus);
        }
        
        $deleteStmt->close();
    }
    
    $stmt->close();
    
    // Log summary
    if ($deletedCount > 0) {
        error_log("Cleanup completed: Deleted $deletedCount expired bookings");
    }
    
    echo "Cleanup completed: Deleted $deletedCount expired bookings\n";
    
} catch (Exception $e) {
    error_log("Error in cleanup script: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
}

$conn->close();
?>
