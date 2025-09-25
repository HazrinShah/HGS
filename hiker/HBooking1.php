<?php
include '../shared/db_connection.php';
session_start();

if (!isset($_SESSION['hikerID'])) {
    // Debug: Log session information
    error_log("HBooking1.php - Session hikerID not set. Session data: " . print_r($_SESSION, true));
    echo "Session hikerID not set! Please log in again.";
    exit;
}
$hikerID = $_SESSION['hikerID'];

// Debug: Log successful session
error_log("HBooking1.php - User logged in with hikerID: $hikerID");
$guiderID = $_GET['guiderID'] ?? null;
$startDate = $_POST['startDate'] ?? $_GET['start'] ?? null;
$endDate = $_POST['endDate'] ?? $_GET['end'] ?? null;
$preSelectedMountainID = $_GET['mountainID'] ?? null;


// Fetch guider info for pricing
$guider = $conn->prepare("SELECT price FROM guider WHERE guiderID = ?");
$guider->bind_param("i", $guiderID);
$guider->execute();
$guiderResult = $guider->get_result();
$guiderData = $guiderResult->fetch_assoc();
$price = $guiderData['price'] ?? 0;

// Check if this is an open group booking (mountain pre-selected)
$isOpenGroupBooking = false;
$openGroupMountain = null;
if ($preSelectedMountainID && $startDate && $endDate) {
    $openGroupStmt = $conn->prepare("
        SELECT 
            m.mountainID, 
            m.name, 
            m.location, 
            m.picture,
            COALESCE(SUM(b.totalHiker), 0) as existingHikers
        FROM mountain m 
        LEFT JOIN booking b ON m.mountainID = b.mountainID 
            AND b.guiderID = ? 
            AND b.groupType = 'open' 
            AND b.startDate <= ? 
            AND b.endDate >= ? 
            AND b.status IN ('accepted', 'paid')
        WHERE m.mountainID = ?
        GROUP BY m.mountainID, m.name, m.location, m.picture
    ");
    $openGroupStmt->bind_param("isss", $guiderID, $endDate, $startDate, $preSelectedMountainID);
    $openGroupStmt->execute();
    $openGroupResult = $openGroupStmt->get_result();
    $openGroupMountain = $openGroupResult->fetch_assoc();
    
    if ($openGroupMountain) {
        $isOpenGroupBooking = true;
    }
}

if (isset($_POST['book'])) {
    // Debug: Log all POST data
    error_log("HBooking1.php - POST data: " . print_r($_POST, true));
    error_log("HBooking1.php - GET data: " . print_r($_GET, true));
    
    // For open group bookings, use the pre-selected mountain
    $mountainID = $isOpenGroupBooking ? $preSelectedMountainID : ($_POST['mountainID'] ?? null);
    $totalHiker = $_POST['totalHiker'] ?? null;
    $groupType = $isOpenGroupBooking ? 'open' : ($_POST['groupType'] ?? 'close'); // Force open for open group bookings
    $startDate = $_POST['startDate'] ?? date('Y-m-d');
    $endDate = $_POST['endDate'] ?? date('Y-m-d');
    $status = "pending";

    // Debug: Log the mountainID value
    error_log("HBooking1.php - MountainID: " . ($mountainID ?? 'NULL') . ", isOpenGroupBooking: " . ($isOpenGroupBooking ? 'true' : 'false') . ", preSelectedMountainID: " . ($preSelectedMountainID ?? 'NULL') . ", POST mountainID: " . ($_POST['mountainID'] ?? 'NULL'));

    // Validate required fields
    if (empty($mountainID)) {
        error_log("HBooking1.php - ERROR: MountainID is empty or NULL!");
        echo "<div style='background: #fee; border: 1px solid #fcc; padding: 20px; margin: 20px; border-radius: 5px;'>";
        echo "<h3 style='color: #c33;'>Booking Error</h3>";
        echo "<p>Please select a mountain for your booking. Go back and choose a hiking location.</p>";
        echo "<a href='javascript:history.back()' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Go Back</a>";
        echo "</div>";
        exit();
    }
    
    if (empty($totalHiker) || $totalHiker < 1 || $totalHiker > 7) {
        error_log("HBooking1.php - ERROR: Invalid totalHiker value: " . ($totalHiker ?? 'NULL'));
        echo "<div style='background: #fee; border: 1px solid #fcc; padding: 20px; margin: 20px; border-radius: 5px;'>";
        echo "<h3 style='color: #c33;'>Booking Error</h3>";
        echo "<p>Please enter a valid number of hikers (1-7).</p>";
        echo "<a href='javascript:history.back()' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Go Back</a>";
        echo "</div>";
        exit();
    }

    $mountainInfo = $conn->prepare("SELECT name, location FROM mountain WHERE mountainID = ?");
    $mountainInfo->bind_param("i", $mountainID);
    $mountainInfo->execute();
    $mountainResult = $mountainInfo->get_result()->fetch_assoc();
    
    if (!$mountainResult) {
        error_log("HBooking1.php - ERROR: Mountain not found with ID: " . $mountainID);
        echo "Error: Selected mountain not found.";
        exit();
    }
    
    $location = $mountainResult['name'] . ", " . $mountainResult['location'];

    $totalPrice = $price * $totalHiker;

    $insert = $conn->prepare("INSERT INTO booking (startDate, endDate, totalHiker, groupType, location, price, status, hikerID, guiderID, mountainID)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $insert->bind_param("ssissdsiii", $startDate, $endDate, $totalHiker, $groupType, $location, $totalPrice, $status, $hikerID, $guiderID, $mountainID);

    if ($insert->execute()) {
        $bookingID = $conn->insert_id;
        
        // Debug: Log successful booking creation
        error_log("HBooking1.php - Booking created successfully. BookingID: $bookingID, HikerID: $hikerID, GuiderID: $guiderID, MountainID: $mountainID, Status: pending");
        
        $_SESSION['booking_success'] = true;
        $_SESSION['booking_summary'] = [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'location' => $location,
            'totalHiker' => $totalHiker,
            'groupType' => $groupType,
            'totalPrice' => $totalPrice
        ];
        header("Location: HBooking1.php?guiderID=".$guiderID."&success=1");
        exit();
    } else {
        // Debug: Log the error
        error_log("HBooking1.php - Booking insertion failed: " . $insert->error);
        echo "Booking failed: " . $insert->error;
        exit();
    }

}

// Fetch all mountains
$mountainQuery = "SELECT * FROM mountain";
$mountainResult = $conn->query($mountainQuery);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hiker Booking</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.3.0/css/all.min.css" />
    <link rel="stylesheet" href="../css/style.css" />

    <style>
    body {
      font-family: "Montserrat", sans-serif;
      background-color: #f8fafc;
    }
    
    /* Guider Blue Color Scheme - Matching HBooking */
    :root {
      --guider-blue: #1e40af;
      --guider-blue-light: #3b82f6;
      --guider-blue-dark: #1e3a8a;
      --guider-blue-accent: #60a5fa;
      --guider-blue-soft: #dbeafe;
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

    /* Main Content Container - Matching HBooking style */
    .main-content {
      padding: 2rem 0;
      min-height: 100vh;
    }

    /* Section Header - Matching HBooking style */
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

    /* Checkout Container - Matching HBooking card style */
    .checkout-container {
      background: white;
      border-radius: 20px;
      padding: 2rem;
      box-shadow: 0 10px 30px rgba(30, 64, 175, 0.1);
      border: 1px solid rgba(30, 64, 175, 0.1);
      margin-bottom: 2rem;
    }

    .checkout-title {
      color: var(--guider-blue-dark);
      font-weight: 700;
      margin-bottom: 1.5rem;
      font-size: 1.5rem;
    }

    .scroll-mountain-container {
      max-height: 400px;
      overflow-y: auto;
      padding-right: 10px;
    }

    /* Mountain Cards - Matching HBooking card style */
    .mountain-card {
      background: white;
      border: 2px solid var(--guider-blue-soft);
      border-radius: 15px;
      padding: 1.5rem;
      transition: all 0.3s ease;
      cursor: pointer;
      margin-bottom: 1rem;
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .mountain-card:hover {
      border-color: var(--guider-blue);
      background: var(--guider-blue-soft);
      transform: translateY(-2px);
      box-shadow: 0 5px 15px rgba(30, 64, 175, 0.2);
    }

    .mountain-card.selected {
      border-color: var(--guider-blue);
      background: var(--guider-blue-soft);
    }

    .mountain-img {
      width: 80px;
      height: 80px;
      object-fit: cover;
      border-radius: 12px;
      border: 3px solid var(--guider-blue-soft);
      transition: all 0.3s ease;
    }

    .mountain-card:hover .mountain-img {
      border-color: var(--guider-blue);
      transform: scale(1.05);
    }

    .mountain-info h6 {
      color: var(--guider-blue-dark);
      font-weight: 600;
      margin-bottom: 0.25rem;
      font-size: 1.1rem;
    }

    .mountain-info small {
      color: #64748b;
      font-size: 0.9rem;
    }

    /* Form Styling - Matching HBooking */
    .form-label {
      color: var(--guider-blue-dark);
      font-weight: 600;
      margin-bottom: 0.75rem;
    }

    .form-control {
      border: 2px solid var(--guider-blue-soft);
      border-radius: 12px;
      padding: 12px 16px;
      transition: all 0.3s ease;
    }

    .form-control:focus {
      border-color: var(--guider-blue);
      box-shadow: 0 0 0 0.2rem rgba(30, 64, 175, 0.25);
    }

    /* Radio Button Styling */
    input[type="radio"] {
      transform: scale(1.5);
      accent-color: var(--guider-blue);
    }

    /* Book Button - Matching HBooking button style */
    .book-btn {
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      border: none;
      border-radius: 12px;
      padding: 12px 30px;
      font-weight: 600;
      color: white;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(30, 64, 175, 0.3);
    }

    .book-btn:hover {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(30, 64, 175, 0.4);
      color: white;
    }

        /* Responsive Design */
        @media (max-width: 768px) {
          .section-title {
            font-size: 1.75rem;
          }
          
          .checkout-container {
            padding: 1.5rem;
          }
          
          .mountain-card {
            flex-direction: column;
            text-align: center;
            gap: 0.75rem;
          }
          
          .mountain-img {
            width: 60px;
            height: 60px;
          }
        }

        /* Custom Notification System */
        .notification-container {
          position: fixed;
          bottom: 20px;
          right: 20px;
          z-index: 9999;
          max-width: 400px;
        }

        .notification {
          background: white;
          border-radius: 12px;
          box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
          margin-bottom: 12px;
          padding: 16px 20px;
          display: flex;
          align-items: center;
          gap: 12px;
          border-left: 4px solid;
          transform: translateX(100%);
          opacity: 0;
          transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
          position: relative;
          overflow: hidden;
        }

        .notification.show {
          transform: translateX(0);
          opacity: 1;
        }

        .notification.hide {
          transform: translateX(100%);
          opacity: 0;
        }

        .notification.success {
          border-left-color: #10b981;
          background: linear-gradient(135deg, #f0fdf4, #ecfdf5);
        }

        .notification.error {
          border-left-color: #ef4444;
          background: linear-gradient(135deg, #fef2f2, #fee2e2);
        }

        .notification.warning {
          border-left-color: #f59e0b;
          background: linear-gradient(135deg, #fffbeb, #fef3c7);
        }

        .notification.info {
          border-left-color: var(--guider-blue);
          background: linear-gradient(135deg, var(--guider-blue-soft), #e0f2fe);
        }

        .notification-icon {
          width: 24px;
          height: 24px;
          display: flex;
          align-items: center;
          justify-content: center;
          border-radius: 50%;
          flex-shrink: 0;
        }

        .notification.success .notification-icon {
          background: #10b981;
          color: white;
        }

        .notification.error .notification-icon {
          background: #ef4444;
          color: white;
        }

        .notification.warning .notification-icon {
          background: #f59e0b;
          color: white;
        }

        .notification.info .notification-icon {
          background: var(--guider-blue);
          color: white;
        }

        .notification-content {
          flex: 1;
          min-width: 0;
        }

        .notification-title {
          font-weight: 600;
          font-size: 14px;
          margin: 0 0 4px 0;
          color: #1f2937;
        }

        .notification-message {
          font-size: 13px;
          margin: 0;
          color: #6b7280;
          line-height: 1.4;
        }

        .notification-close {
          background: none;
          border: none;
          color: #9ca3af;
          cursor: pointer;
          padding: 4px;
          border-radius: 4px;
          transition: all 0.2s ease;
          flex-shrink: 0;
        }

        .notification-close:hover {
          background: rgba(0, 0, 0, 0.1);
          color: #374151;
        }

        .notification-progress {
          position: absolute;
          bottom: 0;
          left: 0;
          height: 3px;
          background: rgba(0, 0, 0, 0.1);
          border-radius: 0 0 12px 12px;
          transition: width linear;
        }

        .notification.success .notification-progress {
          background: #10b981;
        }

        .notification.error .notification-progress {
          background: #ef4444;
        }

        .notification.warning .notification-progress {
          background: #f59e0b;
        }

        .notification.info .notification-progress {
          background: var(--guider-blue);
        }

        /* Mobile Responsive for Notifications */
        @media (max-width: 768px) {
          .notification-container {
            bottom: 10px;
            right: 10px;
            left: 10px;
            max-width: none;
          }
          
          .notification {
            margin-bottom: 8px;
            padding: 12px 16px;
          }
        }

        /* Date Summary Styles */
        .date-summary-container {
          background: white;
          border-radius: 15px;
          padding: 1.5rem;
          margin-bottom: 2rem;
          box-shadow: 0 5px 15px rgba(30, 64, 175, 0.1);
          border: 1px solid var(--guider-blue-soft);
        }

        .date-summary-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-bottom: 1rem;
          padding-bottom: 0.75rem;
          border-bottom: 2px solid var(--guider-blue-soft);
        }

        .date-summary-title {
          color: var(--guider-blue-dark);
          font-weight: 700;
          font-size: 1.25rem;
          margin: 0;
        }


        .date-summary-content {
          display: grid;
          grid-template-columns: 1fr 1fr;
          gap: 1rem;
        }

        .date-item {
          background: var(--guider-blue-soft);
          padding: 1rem;
          border-radius: 10px;
          border-left: 4px solid var(--guider-blue);
        }

        .date-label {
          font-size: 0.875rem;
          color: var(--guider-blue-dark);
          font-weight: 600;
          margin-bottom: 0.5rem;
          display: flex;
          align-items: center;
          gap: 0.5rem;
        }

        .date-value {
          font-size: 1.125rem;
          color: #1f2937;
          font-weight: 700;
        }

        /* Visual Calendar Styles */
        .calendar-container {
          background: white;
          border-radius: 15px;
          padding: 1.5rem;
          box-shadow: 0 5px 15px rgba(30, 64, 175, 0.1);
          border: 1px solid var(--guider-blue-soft);
        }

        .calendar-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-bottom: 1rem;
          padding-bottom: 0.75rem;
          border-bottom: 2px solid var(--guider-blue-soft);
        }

        .calendar-title {
          color: var(--guider-blue-dark);
          font-weight: 700;
          font-size: 1.25rem;
          margin: 0;
        }

        .calendar-nav {
          display: flex;
          gap: 0.5rem;
        }

        .calendar-nav-btn {
          background: var(--guider-blue);
          border: none;
          color: white;
          width: 35px;
          height: 35px;
          border-radius: 8px;
          display: flex;
          align-items: center;
          justify-content: center;
          cursor: pointer;
          transition: all 0.3s ease;
        }

        .calendar-nav-btn:hover {
          background: var(--guider-blue-dark);
          transform: scale(1.05);
        }

        .calendar-nav-btn:disabled {
          background: #e5e7eb;
          color: #9ca3af;
          cursor: not-allowed;
          transform: none;
        }

        .calendar-grid {
          display: grid;
          grid-template-columns: repeat(7, 1fr);
          gap: 2px;
          margin-bottom: 1rem;
        }

        .calendar-day-header {
          background: var(--guider-blue-soft);
          color: var(--guider-blue-dark);
          font-weight: 600;
          font-size: 0.875rem;
          padding: 0.75rem 0.5rem;
          text-align: center;
          border-radius: 8px;
        }

        .calendar-day {
          background: #f8fafc;
          border: 2px solid transparent;
          color: #374151;
          font-weight: 500;
          padding: 0.75rem 0.5rem;
          text-align: center;
          cursor: pointer;
          transition: all 0.3s ease;
          border-radius: 8px;
          position: relative;
          min-height: 45px;
          display: flex;
          align-items: center;
          justify-content: center;
        }

        .calendar-day:hover {
          background: var(--guider-blue-soft);
          border-color: var(--guider-blue);
          transform: scale(1.05);
        }

        .calendar-day.available {
          background: #dcfce7;
          color: #166534;
          border-color: #22c55e;
        }

        .calendar-day.available:hover {
          background: #bbf7d0;
          border-color: #16a34a;
        }

        .calendar-day.unavailable {
          background: #fef2f2;
          color: #dc2626;
          border-color: #ef4444;
          cursor: not-allowed;
        }

        .calendar-day.unavailable:hover {
          background: #fee2e2;
          transform: none;
        }

        .calendar-day.other-month {
          background: #f3f4f6;
          color: #9ca3af;
          cursor: not-allowed;
        }

        .calendar-day.other-month:hover {
          background: #f3f4f6;
          transform: none;
          border-color: transparent;
        }

        .calendar-day.selected {
          background: var(--guider-blue);
          color: white;
          border-color: var(--guider-blue-dark);
          font-weight: 700;
        }

        .calendar-day.selected:hover {
          background: var(--guider-blue-dark);
        }

        .calendar-day.today {
          background: #fef3c7;
          color: #92400e;
          border-color: #f59e0b;
          font-weight: 700;
        }

        .calendar-day.today.available {
          background: #dcfce7;
          color: #166534;
          border-color: #22c55e;
        }

        .calendar-day.today.unavailable {
          background: #fef2f2;
          color: #dc2626;
          border-color: #ef4444;
        }

        .calendar-legend {
          display: flex;
          justify-content: center;
          gap: 1.5rem;
          margin-top: 1rem;
          padding-top: 1rem;
          border-top: 2px solid var(--guider-blue-soft);
        }

        .legend-item {
          display: flex;
          align-items: center;
          gap: 0.5rem;
          font-size: 0.875rem;
          font-weight: 500;
        }

        .legend-color {
          width: 16px;
          height: 16px;
          border-radius: 4px;
          border: 2px solid;
        }

        .legend-available {
          background: #dcfce7;
          border-color: #22c55e;
        }

        .legend-unavailable {
          background: #fef2f2;
          border-color: #ef4444;
        }

        .legend-selected {
          background: var(--guider-blue);
          border-color: var(--guider-blue-dark);
        }

        .calendar-loading {
          display: flex;
          justify-content: center;
          align-items: center;
          padding: 2rem;
          color: var(--guider-blue);
        }

        .calendar-loading i {
          animation: spin 1s linear infinite;
          font-size: 1.5rem;
        }

        /* Mobile Responsive Calendar */
        @media (max-width: 768px) {
          .date-summary-container {
            padding: 1rem;
          }
          
          .date-summary-content {
            grid-template-columns: 1fr;
            gap: 0.75rem;
          }
          
          .calendar-container {
            padding: 1rem;
          }
          
          .calendar-day {
            padding: 0.5rem 0.25rem;
            min-height: 40px;
            font-size: 0.875rem;
          }
          
          .calendar-legend {
            flex-direction: column;
            gap: 0.75rem;
            align-items: center;
          }
        }

        /* Booking Success Notification */
        .booking-success-notification {
          position: fixed;
          top: 20px;
          right: 20px;
          background: white;
          border-radius: 15px;
          box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
          padding: 1.5rem;
          max-width: 400px;
          z-index: 9999;
          border-left: 5px solid #10b981;
          transform: translateX(100%);
          opacity: 0;
          transition: all 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }

        .booking-success-notification.show {
          transform: translateX(0);
          opacity: 1;
        }

        .booking-success-header {
          display: flex;
          align-items: center;
          margin-bottom: 1rem;
        }

        .booking-success-icon {
          width: 40px;
          height: 40px;
          background: linear-gradient(135deg, #10b981, #059669);
          border-radius: 50%;
          display: flex;
          align-items: center;
          justify-content: center;
          margin-right: 12px;
          box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }

        .booking-success-icon i {
          color: white;
          font-size: 1.2rem;
        }

        .booking-success-title {
          font-weight: 700;
          color: #059669;
          font-size: 1.1rem;
          margin: 0;
        }

        .booking-summary {
          background: #f0fdf4;
          border-radius: 10px;
          padding: 1rem;
          margin-bottom: 1rem;
        }

        .booking-summary h6 {
          color: var(--guider-blue-dark);
          font-weight: 600;
          margin-bottom: 0.5rem;
          font-size: 0.9rem;
        }

        .booking-details {
          display: grid;
          grid-template-columns: 1fr 1fr;
          gap: 0.5rem;
          font-size: 0.85rem;
        }

        .booking-detail {
          color: #374151;
        }

        .booking-detail strong {
          color: var(--guider-blue-dark);
        }

        .booking-actions {
          display: flex;
          gap: 0.75rem;
        }

        .booking-btn {
          flex: 1;
          padding: 0.75rem 1rem;
          border-radius: 10px;
          font-weight: 600;
          font-size: 0.9rem;
          text-decoration: none;
          text-align: center;
          transition: all 0.3s ease;
          border: none;
          cursor: pointer;
        }

        .booking-btn-primary {
          background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
          color: white;
          box-shadow: 0 4px 15px rgba(30, 64, 175, 0.3);
        }

        .booking-btn-primary:hover {
          background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
          transform: translateY(-2px);
          box-shadow: 0 6px 20px rgba(30, 64, 175, 0.4);
          color: white;
        }

        .booking-btn-secondary {
          background: linear-gradient(135deg, #f8fafc, #e2e8f0);
          color: var(--guider-blue-dark);
          border: 2px solid var(--guider-blue-soft);
        }

        .booking-btn-secondary:hover {
          background: linear-gradient(135deg, var(--guider-blue-soft), #dbeafe);
          transform: translateY(-2px);
          color: var(--guider-blue-dark);
        }

        .booking-close {
          position: absolute;
          top: 10px;
          right: 10px;
          background: none;
          border: none;
          color: #9ca3af;
          cursor: pointer;
          font-size: 1.2rem;
          padding: 0;
          width: 24px;
          height: 24px;
          display: flex;
          align-items: center;
          justify-content: center;
          border-radius: 50%;
          transition: all 0.2s ease;
        }

        .booking-close:hover {
          background: rgba(0, 0, 0, 0.1);
          color: #374151;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
          .booking-success-notification {
            top: 10px;
            right: 10px;
            left: 10px;
            max-width: none;
          }
          
          .booking-details {
            grid-template-columns: 1fr;
          }
          
          .booking-actions {
            flex-direction: column;
          }
        }

        /* Success Page Styles */
        .success-icon-large {
          animation: successPulse 2s ease-in-out infinite;
        }

        @keyframes successPulse {
          0% { transform: scale(1); }
          50% { transform: scale(1.05); }
          100% { transform: scale(1); }
        }

        .btn-primary:hover {
          background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue)) !important;
          transform: translateY(-3px) !important;
          box-shadow: 0 12px 35px rgba(30, 64, 175, 0.4) !important;
        }

        .btn-outline-primary:hover {
          background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light)) !important;
          color: white !important;
          border-color: var(--guider-blue) !important;
          transform: translateY(-3px) !important;
          box-shadow: 0 12px 35px rgba(30, 64, 175, 0.4) !important;
        }

        .booking-detail-item {
          padding: 0.5rem 0;
        }

        .booking-detail-item strong {
          display: block;
          margin-bottom: 0.25rem;
        }

        /* Group Type Selection Styles */
        .group-type-container {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
        }

        .group-type-option {
            flex: 1;
        }

        .group-type-option input[type="radio"] {
            display: none;
        }

        .group-type-label {
            cursor: pointer;
            display: block;
        }

        .group-type-card {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            transition: all 0.3s ease;
            height: 100%;
        }

        .group-type-card i {
            font-size: 2rem;
            color: #64748b;
            margin-bottom: 0.75rem;
            display: block;
        }

        .group-type-card h6 {
            color: var(--guider-blue-dark);
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .group-type-card p {
            color: #64748b;
            font-size: 0.9rem;
            margin: 0;
            line-height: 1.4;
        }

        .group-type-option input[type="radio"]:checked + .group-type-label .group-type-card {
            border-color: var(--guider-blue);
            background: var(--guider-blue-soft);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(30, 64, 175, 0.2);
        }

        .group-type-option input[type="radio"]:checked + .group-type-label .group-type-card i {
            color: var(--guider-blue);
        }

        .group-type-option input[type="radio"]:checked + .group-type-label .group-type-card h6 {
            color: var(--guider-blue-dark);
        }

        .group-type-card:hover {
            border-color: var(--guider-blue-accent);
            transform: translateY(-1px);
        }

        /* Open Group Booking Styles */
        .open-group-info {
            margin-bottom: 2rem;
        }

        .selected-mountain-card {
            background: white;
            border: 2px solid var(--guider-blue);
            border-radius: 15px;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            position: relative;
            margin-top: 1rem;
        }

        .mountain-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .mountain-badge i {
            font-size: 0.7rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .group-type-container {
                flex-direction: column;
                gap: 0.75rem;
            }
            
            .group-type-card {
                padding: 1rem;
            }
            
            .group-type-card i {
                font-size: 1.5rem;
            }

            .selected-mountain-card {
                flex-direction: column;
                text-align: center;
                gap: 0.75rem;
            }
        }
    </style>
</head>
<body>
<!-- Custom Notification Container -->
<div class="notification-container" id="notificationContainer"></div>


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
          <li class="nav-item"><a class="nav-link" href="HHomePage.php">Home</a></li>
          <li class="nav-item"><a class="nav-link" href="HProfile.php">Profile</a></li>
          <li class="nav-item"><a class="nav-link active" href="HBooking.php">Book Guider</a></li>
          <li class="nav-item"><a class="nav-link" href="HPayment.php">Payment</a></li>
          <li class="nav-item"><a class="nav-link" href="HYourGuider.php">Your Guider</a></li>
          <li class="nav-item"><a class="nav-link" href="HRateReview.php">Rate and Review</a></li>
          <li class="nav-item"><a class="nav-link" href="HBookingHistory.html">Booking History</a></li>
        </ul>
        <form action="../shared/logout.php" method="POST" class="d-flex justify-content-center mt-5">
          <button type="submit" class="btn btn-danger">Logout</button>
        </form>
      </div>
    </div>
  </nav>
</header>

<!-- Main Content -->
<main class="main-content">
  <div class="container">
    <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
      <!-- Success Page Content -->
      <div class="section-header">
        <h1 class="section-title">
          <i class="fas fa-check-circle me-3 text-success"></i>Booking Confirmed!
        </h1>
        <p class="section-subtitle">Your hiking booking has been successfully created</p>
      </div>
      
      <!-- Success Content Card -->
      <div class="checkout-container">
        <div class="text-center">
          <div class="mb-4">
            <div class="success-icon-large mx-auto mb-3" style="width: 100px; height: 100px; background: linear-gradient(135deg, #10b981, #059669); border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 15px 40px rgba(16, 185, 129, 0.3);">
              <i class="fas fa-check text-white" style="font-size: 3rem;"></i>
            </div>
          </div>
          
          <h3 class="text-success mb-4 fw-bold">Booking Successfully Created!</h3>
          <p class="text-muted mb-4 fs-5">Your hiking booking has been confirmed. You can now proceed with payment or make another booking.</p>
          
          <!-- Booking Summary -->
          <div class="booking-summary-card" style="background: #f0fdf4; border-radius: 15px; padding: 1.5rem; margin: 2rem 0; border: 2px solid #10b981;">
            <h5 class="text-success mb-3 fw-bold">
              <i class="fas fa-info-circle me-2"></i>Booking Summary
            </h5>
            <div class="row g-3">
              <div class="col-md-6">
                <div class="booking-detail-item">
                  <strong class="text-success">Location:</strong>
                  <p class="mb-0" id="summaryLocation"><?php echo isset($_SESSION['booking_summary']['location']) ? htmlspecialchars($_SESSION['booking_summary']['location']) : ''; ?></p>
                </div>
              </div>
              <div class="col-md-6">
                <div class="booking-detail-item">
                  <strong class="text-success">Duration:</strong>
                  <p class="mb-0" id="summaryDuration">
                    <?php 
                    if (isset($_SESSION['booking_summary']['startDate']) && isset($_SESSION['booking_summary']['endDate'])) {
                      $startDate = new DateTime($_SESSION['booking_summary']['startDate']);
                      $endDate = new DateTime($_SESSION['booking_summary']['endDate']);
                      echo $startDate->format('M j, Y') . ' - ' . $endDate->format('M j, Y');
                    }
                    ?>
                  </p>
                </div>
              </div>
              <div class="col-md-6">
                <div class="booking-detail-item">
                  <strong class="text-success">Number of Hikers:</strong>
                  <p class="mb-0" id="summaryHikers"><?php echo isset($_SESSION['booking_summary']['totalHiker']) ? $_SESSION['booking_summary']['totalHiker'] . ' person(s)' : ''; ?></p>
                </div>
              </div>
              <div class="col-md-6">
                <div class="booking-detail-item">
                  <strong class="text-success">Group Type:</strong>
                  <p class="mb-0" id="summaryGroupType">
                    <?php 
                    if (isset($_SESSION['booking_summary']['groupType'])) {
                        $groupType = $_SESSION['booking_summary']['groupType'];
                        $icon = $groupType === 'close' ? 'fas fa-lock' : 'fas fa-unlock';
                        $text = $groupType === 'close' ? 'Close Group' : 'Open Group';
                        echo '<i class="' . $icon . ' me-1"></i>' . $text;
                    }
                    ?>
                  </p>
                </div>
              </div>
              <div class="col-md-6">
                <div class="booking-detail-item">
                  <strong class="text-success">Total Price:</strong>
                  <p class="mb-0" id="summaryPrice">RM <?php echo isset($_SESSION['booking_summary']['totalPrice']) ? number_format($_SESSION['booking_summary']['totalPrice'], 2) : ''; ?></p>
                </div>
              </div>
            </div>
          </div>
          
          <div class="d-flex gap-3 justify-content-center">
            <a href="HPayment.php" class="btn btn-primary btn-lg px-4 py-3" style="background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light)); border: none; border-radius: 12px; font-weight: 600; box-shadow: 0 8px 25px rgba(30, 64, 175, 0.3); transition: all 0.3s ease;">
              <i class="fas fa-credit-card me-2"></i>Make Payment
            </a>
            <a href="HBooking.php" class="btn btn-outline-primary btn-lg px-4 py-3" style="border: 2px solid var(--guider-blue); color: var(--guider-blue); border-radius: 12px; font-weight: 600; transition: all 0.3s ease; background: linear-gradient(135deg, rgba(30, 64, 175, 0.05), rgba(59, 130, 246, 0.05));">
              <i class="fas fa-plus-circle me-2"></i>Book Another
            </a>
          </div>
        </div>
      </div>
      
    <?php else: ?>
      <!-- Normal Booking Page Content -->
      <div class="section-header">
        <h1 class="section-title">SELECT HIKING LOCATION</h1>
        <p class="section-subtitle">Choose your preferred mountain destination for the hiking</p>
      </div>

    <!-- Date Summary -->
    <div class="date-summary-container">
      <div class="date-summary-header">
        <h5 class="date-summary-title">
          <i class="fas fa-calendar-alt me-2"></i>Selected Hiking Dates
        </h5>
      </div>
      <div class="date-summary-content">
        <div class="date-item">
          <div class="date-label">
            <i class="fas fa-play-circle"></i>
            Start Date
          </div>
          <div class="date-value" id="startDateDisplay">
            <?php 
            if ($startDate) {
              echo date('l, F j, Y', strtotime($startDate));
            } else {
              echo 'Not selected';
            }
            ?>
          </div>
        </div>
        <div class="date-item">
          <div class="date-label">
            <i class="fas fa-stop-circle"></i>
            End Date
          </div>
          <div class="date-value" id="endDateDisplay">
            <?php 
            if ($endDate) {
              echo date('l, F j, Y', strtotime($endDate));
            } else {
              echo 'Not selected';
            }
            ?>
          </div>
        </div>
      </div>
    </div>

<form method="POST" action="HBooking1.php?guiderID=<?= $guiderID ?>">
    <input type="hidden" name="startDate" value="<?= htmlspecialchars($_GET['start'] ?? '') ?>">
    <input type="hidden" name="endDate" value="<?= htmlspecialchars($_GET['end'] ?? '') ?>">
    <input type="hidden" id="guiderID" value="<?= htmlspecialchars($guiderID) ?>">
    <?php if ($isOpenGroupBooking): ?>
    <input type="hidden" id="existingHikers" value="<?= htmlspecialchars($openGroupMountain['existingHikers']) ?>">
    <input type="hidden" name="mountainID" value="<?= htmlspecialchars($preSelectedMountainID) ?>">
    <?php endif; ?>
      
      <!-- Checkout Container -->
<div class="checkout-container">
        <?php if ($isOpenGroupBooking): ?>
        <!-- Open Group Booking - Pre-selected Mountain -->
        <h4 class="checkout-title">
          <i class="fas fa-users me-2"></i>Joining Open Group
        </h4>
        
        <div class="open-group-info">
          <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            <strong>You're joining an existing open group!</strong> The mountain has already been chosen by the first group.
          </div>
          
          <div class="selected-mountain-card">
            <img src="<?= !empty($openGroupMountain['picture']) ? (strpos($openGroupMountain['picture'], 'http') === 0 ? $openGroupMountain['picture'] : '../' . $openGroupMountain['picture']) : 'https://via.placeholder.com/100' ?>" class="mountain-img" alt="Mountain Image">
            <div class="mountain-info">
              <h6><?= htmlspecialchars($openGroupMountain['name']) ?></h6>
              <small><?= htmlspecialchars($openGroupMountain['location']) ?></small>
            </div>
            <div class="mountain-badge">
              <i class="fas fa-lock-open"></i>
              <span>Pre-selected</span>
            </div>
          </div>
        </div>
        
        <?php else: ?>
        <!-- Regular Booking - Choose Mountain -->
        <h4 class="checkout-title">
          <i class="fas fa-mountain me-2"></i>Choose Your Hiking Location
        </h4>

        <!-- Scrollable Mountain Container -->
        <div class="scroll-mountain-container">
          <div class="row g-3">
            <?php while ($row = $mountainResult->fetch_assoc()): 
              $mountainID = $row['mountainID'];
              $name = htmlspecialchars($row['name']);
              $location = htmlspecialchars($row['location']);
              $picture = !empty($row['picture']) ? (strpos($row['picture'], 'http') === 0 ? $row['picture'] : '../' . $row['picture']) : 'https://via.placeholder.com/100';
            ?>
            <label class="col-12 mountain-card">
              <input type="radio" name="mountainID" value="<?= $mountainID ?>" required>
              <img src="<?= $picture ?>" class="mountain-img" alt="Mountain Image">
              <div class="mountain-info">
                <h6><?= $name ?></h6>
                <small><?= $location ?></small>
              </div>
            </label>
            <?php endwhile; ?>
          </div>
        </div>
        <?php endif; ?>

    <!-- Total Hiker Input -->
    <div class="mt-4">
          <label for="totalHiker" class="form-label">
            <i class="fas fa-users me-2"></i>Total Hikers (1–7)
            <?php if ($isOpenGroupBooking): ?>
            <small class="text-muted d-block">Existing group has <?= $openGroupMountain['existingHikers'] ?> hikers</small>
            <?php endif; ?>
          </label>
          <input type="number" name="totalHiker" min="1" max="7" required class="form-control" style="max-width: 200px;" id="totalHikerInput">
          <?php if ($isOpenGroupBooking): ?>
          <div id="hikerValidation" class="text-danger mt-2" style="display: none;">
            <i class="fas fa-exclamation-triangle me-1"></i>
            <span id="validationMessage"></span>
          </div>
          <?php endif; ?>
    </div>

    <!-- Group Type Selection -->
    <?php if (!$isOpenGroupBooking): ?>
    <div class="mt-4">
        <label class="form-label">
            <i class="fas fa-users-cog me-2"></i>Group Type
        </label>
        <div class="group-type-container">
            <div class="group-type-option">
                <input type="radio" name="groupType" value="close" id="closeGroup" checked>
                <label for="closeGroup" class="group-type-label">
                    <div class="group-type-card">
                        <i class="fas fa-lock"></i>
                        <h6>Close Group</h6>
                        <p>Private group - Guider will be unavailable for other bookings on this date</p>
                    </div>
                </label>
            </div>
            <div class="group-type-option">
                <input type="radio" name="groupType" value="open" id="openGroup">
                <label for="openGroup" class="group-type-label">
                    <div class="group-type-card">
                        <i class="fas fa-unlock"></i>
                        <h6>Open Group</h6>
                        <p>Public group - Others can join your trip, guider remains available</p>
                    </div>
                </label>
            </div>
        </div>
    </div>
    <?php else: ?>
    <!-- Open Group Info -->
    <div class="mt-4">
        <div class="alert alert-success">
            <i class="fas fa-users me-2"></i>
            <strong>Open Group Booking</strong> - You're joining an existing group. The guider will remain available for other bookings.
        </div>
    </div>
    <?php endif; ?>

    <!-- Submit Button -->
    <div class="text-end mt-4">
          <button type="submit" name="book" class="book-btn">
            <i class="fas fa-calendar-check me-2"></i>CONFIRM BOOKING
          </button>
    </div>
  </div>
</form>
  </div>
    <?php endif; ?>
  </div>
</main>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet" />

<!-- Custom Notification System -->
<script>
class NotificationSystem {
    constructor() {
        this.container = document.getElementById('notificationContainer');
        this.notifications = new Map();
        this.notificationId = 0;
    }

    show(type, title, message, duration = 5000) {
        const id = ++this.notificationId;
        const notification = this.createNotification(id, type, title, message, duration);
        
        this.container.appendChild(notification);
        this.notifications.set(id, notification);

        // Trigger animation
        setTimeout(() => {
            notification.classList.add('show');
        }, 10);

        // Auto remove
        if (duration > 0) {
            setTimeout(() => {
                this.remove(id);
            }, duration);
        }

        return id;
    }

    createNotification(id, type, title, message, duration) {
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        notification.dataset.id = id;

        const icons = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-triangle',
            warning: 'fa-exclamation-circle',
            info: 'fa-info-circle'
        };

        notification.innerHTML = `
            <div class="notification-icon">
                <i class="fa-solid ${icons[type]}"></i>
            </div>
            <div class="notification-content">
                <div class="notification-title">${title}</div>
                <div class="notification-message">${message}</div>
            </div>
            <button class="notification-close" onclick="notificationSystem.remove(${id})">
                <i class="fa-solid fa-times"></i>
            </button>
            <div class="notification-progress" style="width: 100%; transition-duration: ${duration}ms;"></div>
        `;

        // Start progress bar
        setTimeout(() => {
            const progressBar = notification.querySelector('.notification-progress');
            if (progressBar) {
                progressBar.style.width = '0%';
            }
        }, 10);

        return notification;
    }

    remove(id) {
        const notification = this.notifications.get(id);
        if (notification) {
            notification.classList.add('hide');
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
                this.notifications.delete(id);
            }, 400);
        }
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

// Show booking success notification
function showBookingSuccessNotification() {
    const notification = document.getElementById('bookingSuccessNotification');
    const bookingDetails = document.getElementById('bookingDetails');
    
    // Get booking summary from session (passed via PHP)
    const bookingSummary = <?php echo isset($_SESSION['booking_summary']) ? json_encode($_SESSION['booking_summary']) : 'null'; ?>;
    
    if (bookingSummary) {
        // Format dates
        const startDate = new Date(bookingSummary.startDate).toLocaleDateString('en-US', {
            weekday: 'short',
            month: 'short',
            day: 'numeric'
        });
        
        const endDate = new Date(bookingSummary.endDate).toLocaleDateString('en-US', {
            weekday: 'short',
            month: 'short',
            day: 'numeric'
        });
        
        // Populate booking details
        bookingDetails.innerHTML = `
            <div class="booking-detail">
                <strong>Location:</strong><br>
                ${bookingSummary.location}
            </div>
            <div class="booking-detail">
                <strong>Duration:</strong><br>
                ${startDate} - ${endDate}
            </div>
            <div class="booking-detail">
                <strong>Hikers:</strong><br>
                ${bookingSummary.totalHiker} person(s)
            </div>
            <div class="booking-detail">
                <strong>Group Type:</strong><br>
                <i class="${bookingSummary.groupType === 'close' ? 'fas fa-lock' : 'fas fa-unlock'}"></i>
                ${bookingSummary.groupType === 'close' ? 'Close Group' : 'Open Group'}
            </div>
            <div class="booking-detail">
                <strong>Total Price:</strong><br>
                RM ${parseFloat(bookingSummary.totalPrice).toFixed(2)}
            </div>
        `;
        
        // Show notification
        notification.style.display = 'block';
        setTimeout(() => {
            notification.classList.add('show');
        }, 100);
        
        // Auto hide after 10 seconds
        setTimeout(() => {
            closeBookingNotification();
        }, 10000);
    }
}

// Close booking notification
function closeBookingNotification() {
    const notification = document.getElementById('bookingSuccessNotification');
    notification.classList.remove('show');
    setTimeout(() => {
        notification.style.display = 'none';
    }, 400);
}


// Edit Calendar System removed - keeping only date summary





    renderCalendar() {
        console.log('Rendering calendar...');
        const calendarContent = document.getElementById('editCalendarContent');
        const calendarTitle = document.getElementById('editCalendarTitle');
        
        if (!calendarContent) {
            console.error('Calendar content element not found');
            return;
        }
        
        const monthNames = [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December'
        ];
        
        calendarTitle.textContent = `${monthNames[this.currentMonth]} ${this.currentYear}`;
        
        const firstDay = new Date(this.currentYear, this.currentMonth, 1);
        const lastDay = new Date(this.currentYear, this.currentMonth + 1, 0);
        const daysInMonth = lastDay.getDate();
        const startingDayOfWeek = firstDay.getDay();
        
        let calendarHTML = `
            <div class="calendar-grid">
                <div class="calendar-day-header">Sun</div>
                <div class="calendar-day-header">Mon</div>
                <div class="calendar-day-header">Tue</div>
                <div class="calendar-day-header">Wed</div>
                <div class="calendar-day-header">Thu</div>
                <div class="calendar-day-header">Fri</div>
                <div class="calendar-day-header">Sat</div>
        `;
        
        // Add empty cells for days before the first day of the month
        for (let i = 0; i < startingDayOfWeek; i++) {
            calendarHTML += `<div class="calendar-day other-month"></div>`;
        }
        
        // Add days of the month
        for (let day = 1; day <= daysInMonth; day++) {
            const dateString = this.formatDate(this.currentYear, this.currentMonth, day);
            const isToday = this.isToday(this.currentYear, this.currentMonth, day);
            const isPastDate = this.isPastDate(this.currentYear, this.currentMonth, day);
            const isUnavailable = this.unavailableDates.has(dateString) || isPastDate;
            const isSelected = this.isDateSelected(dateString);
            
            let dayClasses = 'calendar-day';
            if (isToday) dayClasses += ' today';
            if (isUnavailable) dayClasses += ' unavailable';
            else if (!isUnavailable) dayClasses += ' available';
            if (isSelected) dayClasses += ' selected';
            
            const clickHandler = isUnavailable ? '' : `onclick="editCalendar.selectDate('${dateString}')"`;
            
            calendarHTML += `
                <div class="${dayClasses}" ${clickHandler}>
                    ${day}
                </div>
            `;
        }
        
        calendarHTML += '</div>';
        calendarContent.innerHTML = calendarHTML;
        console.log('Calendar rendered successfully');
    }

    selectDate(dateString) {
        // Check if date is unavailable (from server) or is a past date
        const date = new Date(dateString);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        date.setHours(0, 0, 0, 0);
        
        if (this.unavailableDates.has(dateString) || date < today) {
            return;
        }
        
        if (!this.selectedStartDate || (this.selectedStartDate && this.selectedEndDate)) {
            // Start new selection
            this.selectedStartDate = dateString;
            this.selectedEndDate = null;
        } else if (this.selectedStartDate && !this.selectedEndDate) {
            // Complete selection
            if (new Date(dateString) >= new Date(this.selectedStartDate)) {
                this.selectedEndDate = dateString;
            } else {
                // If end date is before start date, swap them
                this.selectedEndDate = this.selectedStartDate;
                this.selectedStartDate = dateString;
            }
        }
        
        this.updateDateDisplays();
        this.renderCalendar();
        this.updateSubmitButton();
    }

    updateDateDisplays() {
        const startDisplay = document.getElementById('editStartDateDisplay');
        const endDisplay = document.getElementById('editEndDateDisplay');
        
        if (this.selectedStartDate) {
            const startFormatted = this.formatDateForDisplay(this.selectedStartDate);
            startDisplay.innerHTML = `<i class="fas fa-calendar-check me-2 text-success"></i>${startFormatted}`;
        } else {
            startDisplay.innerHTML = '<span class="text-muted">Select start date from calendar</span>';
        }
        
        if (this.selectedEndDate) {
            const endFormatted = this.formatDateForDisplay(this.selectedEndDate);
            endDisplay.innerHTML = `<i class="fas fa-calendar-check me-2 text-success"></i>${endFormatted}`;
        } else {
            endDisplay.innerHTML = '<span class="text-muted">Select end date from calendar</span>';
        }
    }

    updateSubmitButton() {
        const submitBtn = document.getElementById('updateDatesBtn');
        if (this.selectedStartDate && this.selectedEndDate) {
            submitBtn.disabled = false;
            submitBtn.classList.remove('btn-secondary');
            submitBtn.classList.add('btn-warning');
        } else {
            submitBtn.disabled = true;
            submitBtn.classList.remove('btn-warning');
            submitBtn.classList.add('btn-secondary');
        }
    }

    updateDates() {
        if (!this.selectedStartDate || !this.selectedEndDate) {
            notificationSystem.warning('Selection Required', 'Please select both start and end dates');
            return;
        }
        
        // Update the hidden form inputs
        const startInput = document.querySelector('input[name="startDate"]');
        const endInput = document.querySelector('input[name="endDate"]');
        
        if (startInput) startInput.value = this.selectedStartDate;
        if (endInput) endInput.value = this.selectedEndDate;
        
        // Update the display
        const startDisplay = document.getElementById('startDateDisplay');
        const endDisplay = document.getElementById('endDateDisplay');
        
        startDisplay.textContent = this.formatDateForDisplay(this.selectedStartDate);
        endDisplay.textContent = this.formatDateForDisplay(this.selectedEndDate);
        
        // Close modal
        const modal = bootstrap.Modal.getInstance(document.getElementById('editDateModal'));
        modal.hide();
        
        notificationSystem.success('Dates Updated', 'Your hiking dates have been updated successfully');
    }

    isDateSelected(dateString) {
        if (!this.selectedStartDate) return false;
        if (!this.selectedEndDate) return dateString === this.selectedStartDate;
        
        const date = new Date(dateString);
        const start = new Date(this.selectedStartDate);
        const end = new Date(this.selectedEndDate);
        
        return date >= start && date <= end;
    }

    isToday(year, month, day) {
        const today = new Date();
        return year === today.getFullYear() && 
               month === today.getMonth() && 
               day === today.getDate();
    }

    isPastDate(year, month, day) {
        const today = new Date();
        const dateToCheck = new Date(year, month, day);
        
        // Set time to start of day for accurate comparison
        today.setHours(0, 0, 0, 0);
        dateToCheck.setHours(0, 0, 0, 0);
        
        return dateToCheck < today;
    }

    formatDate(year, month, day) {
        return `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    }

    formatDateForDisplay(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { 
            weekday: 'short', 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric' 
        });
    }
}

</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
  // Add visual feedback for mountain card selection
  const mountainCards = document.querySelectorAll('.mountain-card');
  const radioButtons = document.querySelectorAll('input[name="mountainID"]');
  
  radioButtons.forEach(radio => {
    radio.addEventListener('change', function() {
      // Remove selected class from all cards
      mountainCards.forEach(card => card.classList.remove('selected'));
      
      // Add selected class to the clicked card
      if (this.checked) {
        this.closest('.mountain-card').classList.add('selected');
      }
    });
  });
  
  // Add click handler to mountain cards
  mountainCards.forEach(card => {
    card.addEventListener('click', function(e) {
      if (e.target.type !== 'radio') {
        const radio = this.querySelector('input[type="radio"]');
        radio.checked = true;
        radio.dispatchEvent(new Event('change'));
      }
    });
  });

  // Hiker validation for open group bookings
  const existingHikersInput = document.getElementById('existingHikers');
  const totalHikerInput = document.getElementById('totalHikerInput');
  const validationDiv = document.getElementById('hikerValidation');
  const validationMessage = document.getElementById('validationMessage');
  const submitButton = document.querySelector('button[name="book"]');

  if (existingHikersInput && totalHikerInput && validationDiv) {
    const existingHikers = parseInt(existingHikersInput.value);
    const maxAllowed = 7 - existingHikers;

    function validateHikerCount() {
      const newHikers = parseInt(totalHikerInput.value) || 0;
      const totalHikers = existingHikers + newHikers;

      if (newHikers > 0 && totalHikers > 7) {
        validationDiv.style.display = 'block';
        validationMessage.textContent = `Total hikers would be ${totalHikers} (${existingHikers} existing + ${newHikers} new). Maximum allowed is 7. You can add maximum ${maxAllowed} hikers.`;
        totalHikerInput.setCustomValidity('Total hikers cannot exceed 7');
        submitButton.disabled = true;
        return false;
      } else {
        validationDiv.style.display = 'none';
        totalHikerInput.setCustomValidity('');
        submitButton.disabled = false;
        return true;
      }
    }

    // Update max attribute based on existing hikers
    totalHikerInput.setAttribute('max', maxAllowed);

    // Add validation on input change
    totalHikerInput.addEventListener('input', validateHikerCount);
    totalHikerInput.addEventListener('blur', validateHikerCount);

    // Initial validation
    validateHikerCount();
  }
});
</script>



</body>
</html>

<?php
// Clear booking success session data after displaying
if (isset($_SESSION['booking_success'])) {
    unset($_SESSION['booking_success']);
}
if (isset($_SESSION['booking_summary'])) {
    unset($_SESSION['booking_summary']);
}
if (isset($_SESSION['form_data'])) {
    unset($_SESSION['form_data']);
}
?>