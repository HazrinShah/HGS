<?php
session_start();
if (!isset($_SESSION['guiderID'])) {
    header("Location: GLogin.html");
    exit();
}
$guiderID = $_SESSION['guiderID'];
?>

<?php
include '../shared/db_connection.php';

// retrieve booking data
$bookings = [];
$activeBookings = [];
$cancelledBookings = [];
$completedBookings = [];

if ($guiderID) {
    // Query for active bookings (excluding those with pending appeals)
    $stmt = $conn->prepare("
        SELECT 
            b.bookingID,
            b.totalHiker,
            b.groupType,
            b.price,
            b.status,
            b.startDate,
            b.endDate,
            h.username AS hikerName,
            m.name AS location
        FROM booking b
        JOIN hiker h ON b.hikerID = h.hikerID
        JOIN mountain m ON b.mountainID = m.mountainID
        WHERE b.guiderID = ?
        AND b.bookingID NOT IN (
            SELECT DISTINCT bookingID 
            FROM appeal 
            WHERE status = 'pending'
        )
        ORDER BY b.startDate DESC
    ");
    $stmt->bind_param("i", $guiderID);
    $stmt->execute();
    $result = $stmt->get_result();
    $bookings = $result->fetch_all(MYSQLI_ASSOC);
    
    // Split active bookings
    foreach ($bookings as $row) {
        if (strtoupper($row['status']) === 'CANCELLED') {
            $cancelledBookings[] = $row;
        } elseif (strtoupper($row['status']) === 'COMPLETED') {
            $completedBookings[] = $row;
        } else {
            $activeBookings[] = $row;
        }
    }
    
    // Separate query for ALL cancelled and completed bookings (for history section)
    $historyStmt = $conn->prepare("
        SELECT 
            b.bookingID,
            b.totalHiker,
            b.groupType,
            b.price,
            b.status,
            b.startDate,
            b.endDate,
            h.username AS hikerName,
            m.name AS location
        FROM booking b
        JOIN hiker h ON b.hikerID = h.hikerID
        JOIN mountain m ON b.mountainID = m.mountainID
        WHERE b.guiderID = ?
        AND b.status IN ('cancelled', 'completed')
        ORDER BY b.startDate DESC
    ");
    $historyStmt->bind_param("i", $guiderID);
    $historyStmt->execute();
    $historyResult = $historyStmt->get_result();
    $allHistoryBookings = $historyResult->fetch_all(MYSQLI_ASSOC);
    
    // Split history bookings
    $cancelledBookings = [];
    $completedBookings = [];
    foreach ($allHistoryBookings as $row) {
        if (strtoupper($row['status']) === 'CANCELLED') {
            $cancelledBookings[] = $row;
        } elseif (strtoupper($row['status']) === 'COMPLETED') {
            $completedBookings[] = $row;
        }
    }
}

// Fetch appeals related to this guider's bookings (both hiker and guider appeals)
$guiderAppeals = [];
if ($guiderID) {
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
            b.groupType,
            h.username AS hikerName,
            m.name AS location,
            CASE 
                WHEN a.hikerID IS NOT NULL AND a.guiderID IS NULL THEN 'hiker'
                WHEN a.guiderID IS NOT NULL AND a.hikerID IS NULL THEN 'guider'
                ELSE 'unknown'
            END AS appealFrom
        FROM appeal a
        JOIN booking b ON a.bookingID = b.bookingID
        JOIN hiker h ON b.hikerID = h.hikerID
        JOIN mountain m ON b.mountainID = m.mountainID
        WHERE b.guiderID = ?
        ORDER BY a.createdAt DESC
    ");
    $appealStmt->bind_param("i", $guiderID);
    $appealStmt->execute();
    $appealResult = $appealStmt->get_result();
    $guiderAppeals = $appealResult->fetch_all(MYSQLI_ASSOC);
}

// delete off day
if (isset($_POST['offDate']) && !isset($_POST['deleteOffDay'])) {
    $offDate = $_POST['offDate'];

    // First check if this date is already an off day
    $checkQuery = "SELECT * FROM schedule WHERE guiderID = ? AND offDate = ?";
    $stmt = $conn->prepare($checkQuery);
    $stmt->bind_param("is", $guiderID, $offDate);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 0) {
        // Check if there are any existing bookings on this date
        $bookingCheckQuery = "SELECT * FROM booking WHERE guiderID = ? AND startDate <= ? AND endDate >= ? AND status NOT IN ('cancelled', 'completed')";
        $stmt = $conn->prepare($bookingCheckQuery);
        $stmt->bind_param("iss", $guiderID, $offDate, $offDate);
        $stmt->execute();
        $bookingResult = $stmt->get_result();

        if ($bookingResult->num_rows == 0) {
            // No existing bookings, can set as off day
            $insertQuery = "INSERT INTO schedule (guiderID, offDate) VALUES (?, ?)";
            $stmt = $conn->prepare($insertQuery);
            $stmt->bind_param("is", $guiderID, $offDate);

            if ($stmt->execute()) {
                $_SESSION['offday_success'] = true;
            } else {
                $_SESSION['offday_error'] = $stmt->error;
            }
        } else {
            // There are existing bookings on this date
            $_SESSION['offday_booking_conflict'] = true;
        }
    } else {
        $_SESSION['offday_warning'] = true;
    }
}

if (isset($_POST['deleteOffDay'])) {
    $deleteDate = $_POST['deleteDate'];

    $deleteQuery = "DELETE FROM schedule WHERE guiderID = ? AND offDate = ?";
    $stmt = $conn->prepare($deleteQuery);
    $stmt->bind_param("is", $guiderID, $deleteDate);

    if ($stmt->execute()) {
        $_SESSION['offday_delete_success'] = true;
    } else {
        $_SESSION['offday_delete_error'] = true;
    }
}

$offDates = [];
if ($guiderID) {
    $query = $conn->prepare("SELECT offDate FROM schedule WHERE guiderID = ? ORDER BY offDate ASC");
    $query->bind_param("i", $guiderID);
    $query->execute();
    $result = $query->get_result();
    $offDates = $result->fetch_all(MYSQLI_ASSOC);
}


// Cancel Booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancelBooking'])) {
    $bookingID = $_POST['bookingID'];

    $cancelQuery = "UPDATE booking SET status = 'CANCELLED' WHERE bookingID = ? AND guiderID = ?";
    $stmt = $conn->prepare($cancelQuery);
    $stmt->bind_param("ii", $bookingID, $guiderID);

    if ($stmt->execute()) {
        $_SESSION['cancel_success'] = true;
        header("Location: GBooking.php");
        exit();
    } else {
        echo "<script>showNotification('Error!', 'Failed to cancel booking.', 'error');</script>";
    }
}

    // handle pop up success cancel
if (isset($_SESSION['cancel_success']) && $_SESSION['cancel_success']) {
    echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            showNotification('Cancelled!', 'Booking cancelled successfully.', 'success');
        });
    </script>";
    unset($_SESSION['cancel_success']);
}

// handle off day notifications
if (isset($_SESSION['offday_success']) && $_SESSION['offday_success']) {
    echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            showNotification('Success!', 'Off day saved successfully!', 'success');
        });
    </script>";
    unset($_SESSION['offday_success']);
}

if (isset($_SESSION['offday_error'])) {
    echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            showNotification('Error!', 'Error saving off day: " . $_SESSION['offday_error'] . "', 'error');
        });
    </script>";
    unset($_SESSION['offday_error']);
}

if (isset($_SESSION['offday_warning']) && $_SESSION['offday_warning']) {
    echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            showNotification('Warning!', 'This date is already set as an off day.', 'warning');
        });
    </script>";
    unset($_SESSION['offday_warning']);
}

if (isset($_SESSION['offday_delete_success']) && $_SESSION['offday_delete_success']) {
    echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            showNotification('Success!', 'Off day deleted successfully!', 'success');
        });
    </script>";
    unset($_SESSION['offday_delete_success']);
}

if (isset($_SESSION['offday_delete_error']) && $_SESSION['offday_delete_error']) {
    echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            showNotification('Error!', 'Failed to delete off day.', 'error');
        });
    </script>";
    unset($_SESSION['offday_delete_error']);
}

if (isset($_SESSION['offday_booking_conflict']) && $_SESSION['offday_booking_conflict']) {
    echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            showNotification('Cannot Set Off Day!', 'This date has existing bookings. You can only set off days on dates with no bookings.', 'error');
        });
    </script>";
    unset($_SESSION['offday_booking_conflict']);
}


?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Booking Management</title>

  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" rel="stylesheet" />

  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.3.0/css/all.min.css" />


  <!-- Google Font -->
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />

  <!-- Bootsrap Logo -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">


  <style>
    /* Guider Blue Color Scheme - Matching Hiker Pages */
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
      --success-color: #28a745;
      --warning-color: #ffc107;
      --danger-color: #dc3545;
      --dark-color: #343a40;
      --light-color: #f8f9fa;
    }

    body {
      background-color: var(--soft-bg);
      font-family: "Montserrat", sans-serif;
      margin: 0;
      padding: 0;
      min-height: 100vh;
    }

    /* Header */
    .navbar {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue)) !important;
      padding: 12px 0;
      box-shadow: 0 4px 20px rgba(30, 64, 175, 0.3);
    }
    .navbar-toggler {
      border: 1px solid rgba(255, 255, 255, 0.3);
    }
    .navbar-toggler-icon {
      background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba%28255, 255, 255, 1%29' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
    }

    .navbar-title {
      font-size: 22px;
      font-weight: bold;
      color: white;
      margin: 0 auto;
      text-shadow: 1px 1px 3px rgba(0,0,0,0.2);
    }

    .logo {
      width: 60px;
      height: 60px;
      object-fit: contain;
    }

    /* Offcanvas Menu */
    .offcanvas {
      background-color: var(--light-color);
    }

    .offcanvas-title {
      color: var(--primary-color);
      font-weight: 600;
    }

    .nav-link {
      color: var(--dark-color);
      font-weight: 500;
      padding: 10px 15px;
      border-radius: 8px;
      margin: 2px 0;
    }

    .nav-link:hover, .nav-link.active {
      background-color: var(--guider-blue-soft);
      color: var(--guider-blue-dark);
      border-color: var(--guider-blue);
    }

    /* Main Container */
    .main-container {
      padding: 1.5rem;
      max-width: 1400px;
      margin: 1rem auto;
      background: var(--soft-bg);
    }

    /* Tabs */
    .nav-tabs {
      border-bottom: 2px solid #dee2e6;
      margin-bottom: 15px;
    }

    .nav-tabs .nav-link {
      border: none;
      color:rgb(86, 87, 73);
      font-weight: 500;
      padding: 10px 20px;
      margin-right: 5px;
      background: transparent;
    }

    .nav-tabs .nav-link:hover {
      border-color: transparent;
      color: var(--primary-color);
    }

    .nav-tabs .nav-link.active {
      color: var(--primary-color);
      background-color: var(--guider-blue-soft);
      border-bottom: 3px solid var(--guider-blue);
      font-weight: 600;
      color: var(--guider-blue-dark);
    }

    /* Table Container */
    .table-container {
      background-color: var(--card-white);
      border-radius: 16px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.06);
      padding: 1.5rem;
      margin-bottom: 1rem;
      border: 1px solid #e2e8f0;
    }

    /* History container - smaller width */
    #history .table-container {
      max-width: 1200px;
      margin: 0 auto;
    }

    /* History specific column adjustments */
    #history .col-bookingid {
      flex: 0 0 12%;
    }
    
    /* Appeals specific styles */
    #appeals .table-container {
      max-width: 1200px;
      margin: 0 auto;
    }
    
    #appeals .badge {
      font-size: 0.75rem;
      padding: 0.375rem 0.75rem;
    }
    
    #appeals .btn-sm {
      padding: 0.25rem 0.5rem;
      font-size: 0.875rem;
    }
    

    /* Table Headers */
    .table-header {
      display: flex;
      padding: 12px 15px;
      background-color: #f1f5ff;
      border-radius: 8px;
      margin-bottom: 10px;
      font-weight: bold;
      color: #495057;
    }

    /* Table Rows */
    .table-row {
      display: flex;
      align-items: center;
      padding: 15px;
      background-color: white;
      border-radius: 8px;
      margin-bottom: 10px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.05);
      transition: all 0.2s ease;
    }

    .table-row:hover {
      box-shadow: 0 4px 12px rgba(0,0,0,0.1);
      transform: translateY(-2px);
    }

    /* Status Badges */
    .status-badge {
      padding: 5px 10px;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 600;
      display: inline-block;
    }

    .status-paid {
      background-color: #d4edda;
      color: #155724;
    }
    
    .status-pending {
      background-color: #fef3c7;
      color: #92400e;
    }
    
    .status-accepted {
      background-color: #d4edda;
      color: #155724;
    }
    
    .status-completed {
      background-color: #d4edda;
      color: #155724;
    }
    
    .status-cancelled {
      background-color: #f8d7da;
      color: #721c24;
    }

    /* Buttons */
    .btn-details {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      color: white;
      border: none;
      border-radius: 8px;
      padding: 0.5rem 1rem;
      font-size: 0.8rem;
      font-weight: 600;
      margin-right: 0.5rem;
      transition: all 0.3s ease;
      box-shadow: 0 2px 8px rgba(30, 64, 175, 0.3);
      white-space: nowrap;
    }

    .btn-details:hover {
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(30, 64, 175, 0.4);
      color: white;
    }

    .btn-cancel {
      background: linear-gradient(135deg, #dc2626, #ef4444);
      color: white;
      border: none;
      border-radius: 8px;
      padding: 0.5rem 1rem;
      font-size: 0.8rem;
      font-weight: 600;
      transition: all 0.3s ease;
      box-shadow: 0 2px 8px rgba(220, 38, 38, 0.3);
      white-space: nowrap;
    }

    .btn-cancel:hover {
      background: linear-gradient(135deg, #b91c1c, #dc2626);
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(220, 38, 38, 0.4);
      color: white;
    }

    /* Calendar Section - Matching Hiker Booking Design */
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

    .calendar-day.fully-booked {
      background: #fef3c7;
      color: #92400e;
      border-color: #f59e0b;
      cursor: pointer;
    }

    .calendar-day.fully-booked:hover {
      background: #fde68a;
      border-color: #d97706;
      transform: scale(1.05);
    }

    .calendar-day.open-group {
      background: #fef3c7;
      color: #92400e;
      border-color: #f59e0b;
      position: relative;
    }

    .calendar-day.open-group:hover {
      background: #fde68a;
      border-color: #d97706;
    }

    .quota-display {
      position: absolute;
      top: 2px;
      right: 2px;
      background: #f59e0b;
      color: white;
      font-size: 0.7rem;
      font-weight: 700;
      padding: 2px 4px;
      border-radius: 8px;
      min-width: 16px;
      text-align: center;
      line-height: 1;
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

    .calendar-day.today {
      background: #fef3c7;
      color: #92400e;
      border-color: #f59e0b;
      font-weight: 700;
    }

    .calendar-day.past {
      background: #f3f4f6 !important;
      color: rgb(99, 99, 99) !important;
      border-color: rgb(156, 163, 175) !important;
      cursor: not-allowed;
    }

    .calendar-day.past:hover {
      background: #f3f4f6;
      transform: none;
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

    .legend-booked {
      background: #fffbeb;
      border-color: #f59e0b;
    }

    .legend-open-group {
      background: #fef3c7;
      border-color: #f59e0b;
    }

    .legend-past {
      background: #f3f4f6;
      border-color: #9ca3af;
    }

    .fc-daygrid-event {
      cursor: pointer;
      font-size: 0.85rem;
    }

    /* New Notification System - Slide from Right */
    .notification-container {
      position: fixed;
      bottom: 20px;
      right: 20px;
      z-index: 9999;
      max-width: 350px;
    }

    .notification {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(20px);
      border-radius: 12px;
      padding: 1rem;
      margin-bottom: 0.75rem;
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
      border: 1px solid rgba(255, 255, 255, 0.2);
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

    .notification::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, #3b82f6, #1e40af);
    }

    .notification.success::before {
      background: linear-gradient(90deg, #10b981, #059669);
    }

    .notification.warning::before {
      background: linear-gradient(90deg, #f59e0b, #d97706);
    }

    .notification.error::before {
      background: linear-gradient(90deg, #ef4444, #dc2626);
    }

    .notification.info::before {
      background: linear-gradient(90deg, #3b82f6, #1e40af);
    }

    .notification-header {
      display: flex;
      align-items: center;
      margin-bottom: 0.5rem;
    }

    .notification-icon {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-right: 0.75rem;
      font-size: 1rem;
      color: white;
    }

    .notification.success .notification-icon {
      background: linear-gradient(135deg, #10b981, #059669);
    }

    .notification.warning .notification-icon {
      background: linear-gradient(135deg, #f59e0b, #d97706);
    }

    .notification.error .notification-icon {
      background: linear-gradient(135deg, #ef4444, #dc2626);
    }

    .notification.info .notification-icon {
      background: linear-gradient(135deg, #3b82f6, #1e40af);
    }

    .notification-title {
      font-size: 1rem;
      font-weight: 600;
      color: #1f2937;
      margin: 0;
    }

    .notification-message {
      font-size: 0.85rem;
      color: #6b7280;
      margin: 0;
      line-height: 1.3;
    }

    .notification-close {
      position: absolute;
      top: 0.75rem;
      right: 0.75rem;
      background: none;
      border: none;
      color: #9ca3af;
      cursor: pointer;
      font-size: 1rem;
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
      background: rgba(0, 0, 0, 0.1);
      color: #374151;
    }

    .fc-daygrid-day-frame {
      min-height: 60px;
    }

    .fc-daygrid-day-number {
      font-weight: 500;
    }

    .fc-toolbar-title {
      font-weight: 600;
      color: var(--primary-color);
    }

    .fc-button {
      background-color: var(--primary-color) !important;
      border: none !important;
    }

    /* Availability Controls */
    .availability-controls {
      background-color: var(--card-white);
      border-radius: 16px;
      padding: 2rem;
      margin-bottom: 2rem;
      box-shadow: 0 4px 16px rgba(0,0,0,0.06);
      border: 1px solid #e2e8f0;
    }
    
    .availability-title {
      font-size: 1.25rem;
      font-weight: 700;
      color: var(--guider-blue-dark);
      margin-bottom: 1.5rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    
    .availability-title i {
      color: var(--guider-blue);
    }

    .availability-controls h4 {
      color: var(--primary-color);
      margin-bottom: 15px;
    }

    .control-buttons {
      display: flex;
      gap: 10px;
      margin-bottom: 15px;
      flex-wrap: wrap;
    }

    .control-buttons .btn {
      flex: 1;
      min-width: 120px;
      font-weight: 600;
      border-radius: 12px;
      padding: 0.75rem 1.5rem;
      transition: all 0.3s ease;
    }
    
    .btn-primary {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      border: none;
      box-shadow: 0 4px 15px rgba(30, 64, 175, 0.3);
    }
    
    .btn-primary:hover {
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(30, 64, 175, 0.4);
    }
    
    .btn-outline-danger {
      border: 2px solid #ef4444;
      color: #ef4444;
      background: transparent;
    }
    
    .btn-outline-danger:hover {
      background: #ef4444;
      color: white;
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
    }

    .legend {
      display: flex;
      gap: 15px;
      margin-top: 10px;
      flex-wrap: wrap;
    }

    .legend-item {
      display: flex;
      align-items: center;
      gap: 5px;
      font-size: 0.9rem;
    }

    .legend-color {
      width: 15px;
      height: 15px;
      border-radius: 3px;
    }

    .available {
      background-color: var(--success-color);
    }

    .unavailable {
      background-color: var(--danger-color);
    }

    .booked {
      background-color: var(--dark-color);
    }

    .dayoff {
      background-color: var(--warning-color);
    }

    /* Footer */
    .footer {
      text-align: center;
      padding: 15px;
      color: #6c757d;
      font-size: 0.9rem;
    }

    /* Column widths */
    .col-bookingid {
      flex: 0 0 8%;
    }
    
    .col-name {
      flex: 0 0 15%;
    }
    
    .col-date {
      flex: 0 0 12%;
    }
    
    .col-location {
      flex: 0 0 20%;
    }
    
    .col-amount {
      flex: 0 0 10%;
    }
    
    .col-status {
      flex: 0 0 10%;
    }
    
    .col-action {
      flex: 0 0 15%;
      text-align: right;
    }

    /* Responsive adjustments */
    @media (max-width: 992px) {
      .table-header, .table-row {
        flex-wrap: wrap;
      }
      
      .col {
        flex: 0 0 50%;
        max-width: 50%;
        margin-bottom: 5px;
      }
      
      .col-action {
        flex: 0 0 100%;
        max-width: 100%;
        margin-top: 10px;
      }
    }

    @media (max-width: 576px) {
      .col {
        flex: 0 0 100%;
        max-width: 100%;
      }
      
      .navbar-title {
        font-size: 18px;
      }

      .control-buttons .btn {
        flex: 0 0 100%;
      }

      .legend {
        gap: 10px;
      }

    }
  </style>
</head>
<body>
  <!-- Notification Container -->
  <div class="notification-container" id="notificationContainer"></div>

<!-- Header -->
<header>
  <nav class="navbar">
    <div class="container d-flex align-items-center justify-content-between">
      <!-- hamburger button (left) -->
      <button class="navbar-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasNavbar" aria-controls="offcanvasNavbar">
        <span class="navbar-toggler-icon"></span>
      </button>

      <!-- title (center) -->
      <h1 class="navbar-title mx-auto">HIKING GUIDANCE SYSTEM</h1>

      <!-- logo (right) -->
      <a class="navbar-brand" href="../index.html">
        <img src="../img/logo.png" class="img-fluid logo" alt="HGS Logo">
      </a>
    </div>

    <!-- Offcanvas menu -->
    <div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasNavbar" aria-labelledby="offcanvasNavbarLabel">
      <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="offcanvasNavbarLabel">Menu</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
      </div>
      <div class="offcanvas-body">
        <ul class="navbar-nav justify-content-end flex-grow-1 pe-3">
          <li class="nav-item"><a class="nav-link active" href="GBooking.php">Booking</a></li>
          <li class="nav-item"><a class="nav-link" href="GHistory.php">History</a></li>
          <li class="nav-item"><a class="nav-link" href="GProfile.php">Profile</a></li>
          <li class="nav-item"><a class="nav-link" href="GEarning.php">Earn & Receive</a></li>
          <li class="nav-item"><a class="nav-link" href="GPerformance.php">Performance Review</a></li>
          <form action="../shared/logout.php" method="POST" class="d-flex justify-content-center mt-5" >
            <button type="submit" class="btn btn-outline-danger">Logout</button>
          </form>
        </ul>
      </div>
    </div>
  </nav>
</header>
<!-- End Header -->

<?php include_once '../shared/suspension_banner.php'; ?>


<div class="main-container">

  <!-- ðŸ”· Tabs -->
  <ul class="nav nav-tabs">
    <li class="nav-item">
      <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#booking">Booking Management</button>
    </li>
    <li class="nav-item">
      <button class="nav-link" data-bs-toggle="tab" data-bs-target="#schedule">Schedule</button>
    </li>
    <li class="nav-item">
      <button class="nav-link" data-bs-toggle="tab" data-bs-target="#appeals">Appeals & Requests</button>
    </li>
  </ul>

  <!-- ðŸ”½ Tab Content -->
  <div class="tab-content mt-2">

   <!-- Booking Management -->
    <div class="tab-pane fade show active" id="booking">
      <div class="table-container">
        <!-- Filter -->
        <div class="filter-container">
          <select id="filterStatus" class="form-select w-auto">
            <option value="all">All Status</option>
            <option value="PENDING">Pending</option>
            <option value="ACCEPTED">Accepted</option>
          </select>
        </div>

        <!-- Table Headers -->
        <div class="table-header mt-3">
          <div class="col col-bookingid">BookingID</div>
          <div class="col col-name">Hiker Name</div>
          <div class="col col-date">From</div>
          <div class="col col-date">To</div>
          <div class="col col-location">Location</div>
          <div class="col col-amount">Amount</div>
          <div class="col col-status">Status</div>
          <div class="col col-action d-flex justify-content-center align-items-center">Action</div>
        </div>

        <!-- Booking Rows -->
        <?php if (!empty($activeBookings)): ?>
          <?php foreach ($activeBookings as $row): ?>
            <div class="table-row" data-status="<?= strtoupper(htmlspecialchars($row['status'])) ?>">
              <div class="col col-bookingid"><?= htmlspecialchars($row['bookingID']) ?></div>
              <div class="col col-name"><?= htmlspecialchars($row['hikerName']) ?></div>
              <div class="col col-date"><?= date("d/m/Y", strtotime($row['startDate'])) ?></div>
              <div class="col col-date"><?= date("d/m/Y", strtotime($row['endDate'])) ?></div>
              <div class="col col-location"><?= htmlspecialchars($row['location']) ?></div>
              <div class="col col-amount">RM <?= number_format($row['price'], 2) ?></div>
              <div class="col col-status">
                  <span class="status-badge 
                    <?= strtoupper($row['status']) == 'PAID' ? 'status-paid' : 
                        (strtoupper($row['status']) == 'ACCEPTED' ? 'status-accepted' : 
                        (strtoupper($row['status']) == 'CANCELLED' ? 'status-cancelled' : 
                        (strtoupper($row['status']) == 'PENDING' ? 'status-pending' : ''))) ?>">
                    <?= strtoupper($row['status']) ?>
                  </span>
              </div>
              <div class="col col-action d-flex justify-content-center align-items-center">
                <!-- Buttons directly inside -->
                <button class="btn btn-details" data-bs-toggle="modal" data-bs-target="#detailsModal<?= $row['bookingID'] ?>">DETAILS</button>

                <?php if (strtoupper($row['status']) !== 'CANCELLED'): ?>
                  <button 
                    type="button" 
                    class="btn btn-cancel cancel-btn" 
                    data-bookingid="<?= $row['bookingID'] ?>"
                  >
                    CANCEL
                  </button>
                <?php endif; ?>
              </div>
                </div>

            <!-- Booking Details Modal -->
            <div class="modal fade" id="detailsModal<?= $row['bookingID'] ?>" tabindex="-1" aria-labelledby="detailsLabel<?= $row['bookingID'] ?>" aria-hidden="true">
              <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content border-0 shadow">
                  <div class="modal-header style="background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue)); color: white; border-radius: 20px 20px 0 0; border: none; padding: 1.5rem;"">
                    <h5 class="modal-title" id="detailsLabel<?= $row['bookingID'] ?>">Booking Details - #<?= $row['bookingID'] ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    <ul class="list-group list-group-flush">
                      <li class="list-group-item"><strong>Hiker Name:</strong> <?= htmlspecialchars($row['hikerName']) ?></li>
                      <li class="list-group-item"><strong>Location:</strong> <?= htmlspecialchars($row['location']) ?></li>
                      <li class="list-group-item"><strong>Total Hikers:</strong> <?= $row['totalHiker'] ?></li>
                      <li class="list-group-item"><strong>Start Date:</strong> <?= date("d M Y", strtotime($row['startDate'])) ?></li>
                      <li class="list-group-item"><strong>End Date:</strong> <?= date("d M Y", strtotime($row['endDate'])) ?></li>
                      <li class="list-group-item"><strong>Price:</strong> RM <?= number_format($row['price'], 2) ?></li>
                      <li class="list-group-item"><strong>Status:</strong> <?= strtoupper($row['status']) ?></li>
                    </ul>
                  </div>
                  <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                  </div>
                </div>
              </div>
            </div>


          <?php endforeach; ?>
        <?php else: ?>
          <p class="text-center text-muted mt-3">No bookings found for your account.</p>
        <?php endif; ?>
      </div>
    </div>

<!-- Schedule -->
<div class="tab-pane fade" id="schedule">
    <!-- Off Day Management -->
    <div class="availability-controls">
        <div class="availability-title">
            <i class="fas fa-calendar-times"></i>
            Manage Off Days
        </div>
        
        <form action="GBooking.php" method="POST" class="row g-3">
            <div class="col-md-6">
                <label for="offDate" class="form-label">Select your off day:</label>
                <input type="date" name="offDate" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="col-md-6 d-flex align-items-end">
                <input type="hidden" name="guiderID" value="<?php echo $_SESSION['guiderID']; ?>">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-plus me-2"></i>Set Off Day
                </button>
            </div>
                </form>
        
        <div class="mt-4">
            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#offDayModal">
                <i class="fas fa-list me-2"></i>View My Off Days
            </button>
            </div>

        <!-- Availability Calendar -->
        <div class="mt-5">
            <div class="availability-title">
                <i class="fas fa-calendar-alt"></i>
                Availability Calendar
            </div>
            
            <div class="calendar-legend">
                <div class="legend-item">
                    <div class="legend-color legend-available"></div>
                    <span>Available</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color legend-unavailable"></div>
                    <span>Off Day</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color legend-booked"></div>
                    <span>Booked</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color legend-open-group"></div>
                    <span>Open Group (spots left)</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color legend-past"></div>
                    <span>Past Day</span>
                </div>
            </div>
            
            <div class="calendar-container">
                <div class="calendar-header">
                    <div class="calendar-title" id="calendarTitle">January 2024</div>
                    <div class="calendar-nav">
                        <button class="calendar-nav-btn" id="prevMonth">
                            <i class="fas fa-chevron-left"></i>
                </button>
                        <button class="calendar-nav-btn" id="nextMonth">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
                
                <div class="calendar-grid" id="calendarGrid">
                    <!-- Calendar will be generated by JavaScript -->
                </div>
            </div>
        </div>
    </div>

                <!-- guider off day modal -->
                <div class="modal fade" id="offDayModal" tabindex="-1" aria-labelledby="offDayModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content border-0 shadow">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title" id="offDayModalLabel">Your Off Days</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <?php if (!empty($offDates)): ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($offDates as $row): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <?= date("l, d F Y", strtotime($row['offDate'])) ?>

                                <!-- Delete Form -->
                                <form method="POST" action="GBooking.php" style="margin: 0;">
                                <input type="hidden" name="deleteDate" value="<?= $row['offDate'] ?>">
                                <button type="submit" name="deleteOffDay" class="btn btn-sm btn-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                                </form>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php else: ?>
                        <div class="alert alert-warning mb-0" role="alert">
                            You have not selected any off day yet.
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                    </div>
                </div>
                </div>
        </div>
    </div> 
</div>

    <!-- Appeals -->
    <div class="tab-pane fade" id="appeals">
      <div class="table-container">
        <h4 class="mb-4">Appeals & Requests</h4>
        <p class="text-muted mb-4">View all appeals and requests related to your bookings - both from hikers and your own requests.</p>
        
        <?php if (empty($guiderAppeals)): ?>
          <div class="text-center py-5">
            <div class="mb-3">
              <i class="fas fa-clipboard-list" style="font-size: 3rem; color: #ccc;"></i>
            </div>
            <h5 class="text-muted">No Appeals or Requests</h5>
            <p class="text-muted">No appeals or requests have been submitted for your bookings yet.</p>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover">
              <thead>
                <tr>
                  <th>Appeal ID</th>
                  <th>Booking ID</th>
                  <th>Hiker</th>
                  <th>Location</th>
                  <th>From</th>
                  <th>Type</th>
                  <th>Status</th>
                  <th>Date Submitted</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($guiderAppeals as $appeal): ?>
                  <tr>
                    <td class="col-bookingid">#<?php echo htmlspecialchars($appeal['appealID']); ?></td>
                    <td class="col-name">#<?php echo htmlspecialchars($appeal['bookingID']); ?></td>
                    <td><?php echo htmlspecialchars($appeal['hikerName']); ?></td>
                    <td><?php echo htmlspecialchars($appeal['location']); ?></td>
                    <td>
                      <?php if ($appeal['appealFrom'] === 'hiker'): ?>
                        <span class="badge bg-primary">
                          <i class="fas fa-user"></i> Hiker
                        </span>
                      <?php elseif ($appeal['appealFrom'] === 'guider'): ?>
                        <span class="badge bg-info">
                          <i class="fas fa-user-tie"></i> You
                        </span>
                      <?php else: ?>
                        <span class="badge bg-secondary">Unknown</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="badge bg-info">
                        <?php echo ucfirst(htmlspecialchars($appeal['appealType'])); ?>
                      </span>
                    </td>
                    <td>
                      <?php
                      $statusClass = '';
                      switch(strtolower($appeal['status'])) {
                        case 'pending':
                          $statusClass = 'bg-warning';
                          break;
                        case 'approved':
                          $statusClass = 'bg-success';
                          break;
                        case 'rejected':
                          $statusClass = 'bg-danger';
                          break;
                        default:
                          $statusClass = 'bg-secondary';
                      }
                      ?>
                      <span class="badge <?php echo $statusClass; ?>">
                        <?php echo ucfirst(htmlspecialchars($appeal['status'])); ?>
                      </span>
                    </td>
                    <td><?php echo date('M d, Y', strtotime($appeal['createdAt'])); ?></td>
                    <td>
                      <button class="btn btn-sm btn-outline-primary" onclick="viewAppealDetails(<?php echo htmlspecialchars(json_encode($appeal)); ?>)">
                        <i class="fas fa-eye"></i> View
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
    </div> 
</div>

  </div>
</div>


<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js"></script>


<!-- Custom Calendar Script - Matching Hiker Booking Design -->
<script>
// New Notification System
function showNotification(title, message, type = 'info') {
    const container = document.getElementById('notificationContainer');
    
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    
    const iconMap = {
        success: 'fas fa-check',
        warning: 'fas fa-exclamation-triangle',
        error: 'fas fa-times',
        info: 'fas fa-info'
    };
    
    notification.innerHTML = `
        <button class="notification-close" onclick="this.parentElement.remove()">
            <i class="fas fa-times"></i>
        </button>
        <div class="notification-header">
            <div class="notification-icon">
                <i class="${iconMap[type]}"></i>
            </div>
            <h4 class="notification-title">${title}</h4>
        </div>
        <p class="notification-message">${message}</p>
    `;
    
    container.appendChild(notification);
    
    // Trigger animation
    setTimeout(() => {
        notification.classList.add('show');
    }, 100);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        notification.classList.remove('show');
        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 400);
    }, 5000);
}

document.addEventListener('DOMContentLoaded', function() {
    // Get off dates and booking data from PHP
    const offDates = <?php echo json_encode(array_column($offDates, 'offDate')); ?>;
    const bookings = <?php echo json_encode($bookings); ?>;
    
    let currentDate = new Date();
    let currentMonth = currentDate.getMonth();
    let currentYear = currentDate.getFullYear();
    
    const monthNames = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
    ];
    
    const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    
    function renderCalendar() {
        const calendarTitle = document.getElementById('calendarTitle');
        const calendarGrid = document.getElementById('calendarGrid');
        
        // Update title
        calendarTitle.textContent = `${monthNames[currentMonth]} ${currentYear}`;
        
        // Clear grid
        calendarGrid.innerHTML = '';
        
        // Add day headers
        dayNames.forEach(day => {
            const dayHeader = document.createElement('div');
            dayHeader.className = 'calendar-day-header';
            dayHeader.textContent = day;
            calendarGrid.appendChild(dayHeader);
        });
        
        // Get first day of month and number of days
        const firstDay = new Date(currentYear, currentMonth, 1);
        const lastDay = new Date(currentYear, currentMonth + 1, 0);
        const daysInMonth = lastDay.getDate();
        const startingDayOfWeek = firstDay.getDay();
        
        // Add empty cells for days before the first day of the month
        for (let i = 0; i < startingDayOfWeek; i++) {
            const emptyDay = document.createElement('div');
            emptyDay.className = 'calendar-day other-month';
            emptyDay.textContent = '';
            calendarGrid.appendChild(emptyDay);
        }
        
        // Add days of the month
        for (let day = 1; day <= daysInMonth; day++) {
            const dayElement = document.createElement('div');
            dayElement.className = 'calendar-day';
            dayElement.textContent = day;
            
            const dateStr = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const today = new Date();
            const currentDate = new Date(dateStr);
            const todayStart = new Date(today.getFullYear(), today.getMonth(), today.getDate());
            const isToday = currentYear === today.getFullYear() && 
                           currentMonth === today.getMonth() && 
                           day === today.getDate();
            const isPast = currentDate < todayStart;
            
            // Check if it's a past day
            if (isPast) {
                dayElement.classList.add('past');
                dayElement.title = 'Past Day';
                console.log(`Past day detected: ${dateStr}`);
            }
            // Check if it's an off day
            else if (offDates.includes(dateStr)) {
                dayElement.classList.add('unavailable');
                if (isToday) dayElement.classList.add('today');
                dayElement.title = 'Off Day - Not Available';
            }
            // Check if it's fully booked or has close group bookings
            else {
                const hasCloseGroupBooking = bookings.some(booking => {
                    const startDate = new Date(booking.startDate);
                    const endDate = new Date(booking.endDate);
                    const currentDate = new Date(dateStr);
                    return currentDate >= startDate && currentDate <= endDate && booking.groupType === 'close';
                });
                
                const isFullyBooked = bookings.some(booking => {
                    const startDate = new Date(booking.startDate);
                    const endDate = new Date(booking.endDate);
                    const currentDate = new Date(dateStr);
                    return currentDate >= startDate && currentDate <= endDate && booking.totalHiker >= 7;
                });
                
                // Calculate total hikers for open group bookings on this date
                const openGroupBookings = bookings.filter(booking => {
                    const startDate = new Date(booking.startDate);
                    const endDate = new Date(booking.endDate);
                    const currentDate = new Date(dateStr);
                    return currentDate >= startDate && currentDate <= endDate && booking.groupType === 'open';
                });
                
                const totalOpenHikers = openGroupBookings.reduce((sum, booking) => sum + booking.totalHiker, 0);
                const remainingQuota = Math.max(0, 7 - totalOpenHikers);
                
                if (hasCloseGroupBooking) {
                    dayElement.classList.add('fully-booked');
                    if (isToday) dayElement.classList.add('today');
                    dayElement.title = 'Close Group Booking - Booked';
                } else if (isFullyBooked) {
                    dayElement.classList.add('fully-booked');
                    if (isToday) dayElement.classList.add('today');
                    dayElement.title = 'Fully Booked (7 hikers)';
                } else if (totalOpenHikers > 0) {
                    // Open group booking with remaining quota (yellow)
                    dayElement.classList.add('open-group');
                    if (isToday) dayElement.classList.add('today');
                    dayElement.title = `Open Group - ${remainingQuota} spots left (${totalOpenHikers}/7 hikers)`;
                    
                    // Add quota display
                    const quotaSpan = document.createElement('span');
                    quotaSpan.className = 'quota-display';
                    quotaSpan.textContent = remainingQuota;
                    dayElement.appendChild(quotaSpan);
                } else {
                    // Available day (green)
                    dayElement.classList.add('available');
                    if (isToday) dayElement.classList.add('today');
                    dayElement.title = 'Available for bookings';
                }
            }
            
            // Add click event
            dayElement.addEventListener('click', function() {
                if (this.classList.contains('past')) {
                    showNotification('Past Day', 'This day has already passed.', 'info');
                } else if (this.classList.contains('unavailable')) {
                    showNotification('Off Day', 'This is your off day - you are not available for bookings.', 'warning');
                } else if (this.classList.contains('fully-booked')) {
                    if (this.title.includes('Close Group')) {
                        showNotification('Close Group Booking', 'This day has a close group booking - you are not available for other bookings.', 'warning');
                    } else {
                        showNotification('Fully Booked', 'This day is fully booked with 7 hikers - no more bookings available.', 'warning');
                    }
                } else if (this.classList.contains('open-group')) {
                    const quota = this.querySelector('.quota-display')?.textContent || '0';
                    showNotification('Open Group Booking', `This day has open group bookings with ${quota} spots remaining. You are still available for new bookings.`, 'info');
                } else if (this.classList.contains('available')) {
                    showNotification('Available', `You are available on ${dateStr} for new bookings.`, 'success');
                }
            });
            
            calendarGrid.appendChild(dayElement);
        }
    }
    
    // Navigation event listeners
    document.getElementById('prevMonth').addEventListener('click', function() {
        currentMonth--;
        if (currentMonth < 0) {
            currentMonth = 11;
            currentYear--;
        }
        renderCalendar();
    });
    
    document.getElementById('nextMonth').addEventListener('click', function() {
        currentMonth++;
        if (currentMonth > 11) {
            currentMonth = 0;
            currentYear++;
        }
        renderCalendar();
    });
    
    // Initial render
    renderCalendar();
    
    // Prevent selecting off days and booked dates in date input
    const offDateInput = document.querySelector('input[name="offDate"]');
    if (offDateInput) {
        // Function to check if a date has bookings
        function hasBookingsOnDate(dateStr) {
            return bookings.some(booking => {
                const startDate = new Date(booking.startDate);
                const endDate = new Date(booking.endDate);
                const currentDate = new Date(dateStr);
                return currentDate >= startDate && currentDate <= endDate && 
                       !['cancelled', 'completed'].includes(booking.status.toLowerCase());
            });
        }
        
        // Add event listener for date change
        offDateInput.addEventListener('change', function() {
            const selectedDate = this.value;
            if (offDates.includes(selectedDate)) {
                showNotification('Warning!', 'This date is already set as an off day. Please choose a different date.', 'warning');
                this.value = ''; // Clear the input
            } else if (hasBookingsOnDate(selectedDate)) {
                showNotification('Cannot Set Off Day!', 'This date has existing bookings. You can only set off days on dates with no bookings.', 'error');
                this.value = ''; // Clear the input
            }
        });
        
        // Add event listener for date input (when user types)
        offDateInput.addEventListener('input', function() {
            const selectedDate = this.value;
            if (offDates.includes(selectedDate)) {
                showNotification('Warning!', 'This date is already set as an off day. Please choose a different date.', 'warning');
                this.value = ''; // Clear the input
            } else if (hasBookingsOnDate(selectedDate)) {
                showNotification('Cannot Set Off Day!', 'This date has existing bookings. You can only set off days on dates with no bookings.', 'error');
                this.value = ''; // Clear the input
            }
        });
        
        // Add visual indicator for off days in date picker
        offDateInput.addEventListener('focus', function() {
            // Create a small tooltip or hint
            if (!this.nextElementSibling || !this.nextElementSibling.classList.contains('off-day-hint')) {
                const hint = document.createElement('small');
                hint.className = 'off-day-hint form-text text-muted mt-1';
                hint.innerHTML = '<i class="fas fa-info-circle me-1"></i>Cannot select dates that are already off days or have existing bookings';
                this.parentNode.appendChild(hint);
            }
        });
    }
});
</script>

<!-- Filter Script -->
<script>
  const filterSelect = document.getElementById('filterStatus');
  filterSelect.addEventListener('change', () => {
    const selected = filterSelect.value.toUpperCase();
    const rows = document.querySelectorAll('.table-row');

    rows.forEach(row => {
      const status = row.getAttribute('data-status');
      if (selected === 'ALL' || status === selected) {
        row.style.display = 'flex';
      } else {
        row.style.display = 'none';
      }
    });
  });

</script>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const cancelButtons = document.querySelectorAll('.cancel-btn');
  
  console.log('Found cancel buttons:', cancelButtons.length);

  cancelButtons.forEach(button => {
    button.addEventListener('click', () => {
      const bookingID = button.getAttribute('data-bookingid');
      console.log('Cancel button clicked for booking:', bookingID);

      Swal.fire({
        title: 'Submit Cancellation Request',
        html: `
          <div class="mb-3">
            <label for="appealType" class="form-label">Request Type:</label>
            <select id="appealType" class="form-select">
              <option value="cancellation">Cancellation Request</option>
              <option value="change">Change Request</option>
            </select>
          </div>
          <div class="mb-3">
            <label for="reason" class="form-label">Reason (Optional):</label>
            <textarea id="reason" class="form-control" rows="3" placeholder="Please provide a reason for your request..."></textarea>
          </div>
        `,
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Submit Request',
        cancelButtonText: 'Cancel',
        preConfirm: () => {
          const appealType = document.getElementById('appealType').value;
          const reason = document.getElementById('reason').value;
          
          if (!appealType) {
            Swal.showValidationMessage('Please select a request type');
            return false;
          }
          
          return { appealType, reason };
        }
      }).then((result) => {
        if (result.isConfirmed) {
          const { appealType, reason } = result.value;
          
          // Submit appeal request
          console.log('Submitting appeal:', { bookingID, appealType, reason });
          fetch('submit_guider_appeal.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `bookingID=${bookingID}&appealType=${appealType}&reason=${encodeURIComponent(reason)}`
          })
          .then(response => {
            console.log('Response status:', response.status);
            return response.json();
          })
          .then(data => {
            console.log('Response data:', data);
            if (data.success) {
              showNotification('Request Submitted!', data.message, 'success');
              // Reload the page to show updated data
              setTimeout(() => {
                window.location.reload();
              }, 2000);
            } else {
              showNotification('Error!', data.message, 'error');
            }
          })
          .catch(error => {
            console.error('Error:', error);
            showNotification('Error!', 'Failed to submit request. Please try again.', 'error');
          });
        }
    });
  });
});
});

// View Appeal Details Function
function viewAppealDetails(appeal) {
  const statusClass = appeal.status.toLowerCase() === 'pending' ? 'warning' : 
                     appeal.status.toLowerCase() === 'approved' ? 'success' : 
                     appeal.status.toLowerCase() === 'rejected' ? 'danger' : 'secondary';
  
  const submittedBy = appeal.appealFrom === 'hiker' ? 
    `<span class="badge bg-primary"><i class="fas fa-user"></i> Hiker (${appeal.hikerName})</span>` :
    `<span class="badge bg-info"><i class="fas fa-user-tie"></i> You (Guider)</span>`;
  
  Swal.fire({
    title: `Appeal #${appeal.appealID}`,
    html: `
      <div class="text-start">
        <div class="row mb-3">
          <div class="col-6"><strong>Booking ID:</strong></div>
          <div class="col-6">#${appeal.bookingID}</div>
        </div>
        <div class="row mb-3">
          <div class="col-6"><strong>Hiker:</strong></div>
          <div class="col-6">${appeal.hikerName}</div>
        </div>
        <div class="row mb-3">
          <div class="col-6"><strong>Location:</strong></div>
          <div class="col-6">${appeal.location}</div>
        </div>
        <div class="row mb-3">
          <div class="col-6"><strong>Trip Dates:</strong></div>
          <div class="col-6">${new Date(appeal.startDate).toLocaleDateString()} - ${new Date(appeal.endDate).toLocaleDateString()}</div>
        </div>
        <div class="row mb-3">
          <div class="col-6"><strong>Submitted By:</strong></div>
          <div class="col-6">${submittedBy}</div>
        </div>
        <div class="row mb-3">
          <div class="col-6"><strong>Appeal Type:</strong></div>
          <div class="col-6"><span class="badge bg-info">${appeal.appealType.charAt(0).toUpperCase() + appeal.appealType.slice(1)}</span></div>
        </div>
        <div class="row mb-3">
          <div class="col-6"><strong>Status:</strong></div>
          <div class="col-6"><span class="badge bg-${statusClass}">${appeal.status.charAt(0).toUpperCase() + appeal.status.slice(1)}</span></div>
        </div>
        <div class="row mb-3">
          <div class="col-6"><strong>Date Submitted:</strong></div>
          <div class="col-6">${new Date(appeal.createdAt).toLocaleDateString()}</div>
        </div>
        ${appeal.reason ? `
        <div class="row mb-3">
          <div class="col-12"><strong>Reason:</strong></div>
          <div class="col-12 mt-2 p-3 bg-light rounded">${appeal.reason}</div>
        </div>
        ` : ''}
      </div>
    `,
    width: '600px',
    showConfirmButton: true,
    confirmButtonText: 'Close',
    confirmButtonColor: '#6c757d'
  });
}
</script>



</body>
</html>
