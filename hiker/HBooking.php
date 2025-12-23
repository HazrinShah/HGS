<?php 
include '../shared/db_connection.php';
session_start();

if (!isset($_SESSION['hikerID'])) {
    // Debug: Log session information
    error_log("HBooking.php - Session hikerID not set. Session data: " . print_r($_SESSION, true));
    header("Location: HLogin.html?error=session_expired");
    exit;
}
$hikerID = $_SESSION['hikerID'];

// Debug: Log successful session
error_log("HBooking.php - User logged in with hikerID: $hikerID");

// retrieve guider who is available
$startDate = $_GET['start'] ?? null;
$endDate = $_GET['end'] ?? null;
$availableGuiderIDs = [];

if ($startDate && $endDate) {
    // Only consider active guiders
    $guiderQuery = "SELECT guiderID FROM guider WHERE status = 'active'";
    $guiderResult = $conn->query($guiderQuery);

    while ($g = $guiderResult->fetch_assoc()) {
        $guiderID = $g['guiderID'];

        // Check for off days
        $stmt = $conn->prepare("SELECT * FROM schedule WHERE guiderID = ? AND offDate BETWEEN ? AND ?");
        $stmt->bind_param("iss", $guiderID, $startDate, $endDate);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            // 1) Close group overlap blocks availability entirely
            $closeStmt = $conn->prepare("SELECT COUNT(*) AS c FROM booking WHERE guiderID = ? AND groupType = 'close' AND startDate <= ? AND endDate >= ? AND status IN ('pending','accepted','paid')");
            $closeStmt->bind_param("iss", $guiderID, $endDate, $startDate);
            $closeStmt->execute();
            $closeCount = ($closeStmt->get_result()->fetch_assoc()['c'] ?? 0);
            $closeStmt->close();

            if ((int)$closeCount === 0) {
                // 2) Open group overlap: allow joining only during 3-minute forming window and if not full (7)
                $openStmt = $conn->prepare("\n                    SELECT COALESCE(SUM(b.totalHiker),0) AS totalHikers, MIN(b.created_at) AS groupStart\n                    FROM booking b\n                    WHERE b.guiderID = ?\n                      AND b.groupType = 'open'\n                      AND b.startDate <= ?\n                      AND b.endDate >= ?\n                      AND b.status IN ('pending','accepted','paid')\n                ");
                $openStmt->bind_param("iss", $guiderID, $endDate, $startDate);
                $openStmt->execute();
                $openRes = $openStmt->get_result()->fetch_assoc();
                $openStmt->close();

                $totalHikers = (int)($openRes['totalHikers'] ?? 0);
                $groupStart = isset($openRes['groupStart']) && $openRes['groupStart'] ? strtotime($openRes['groupStart']) : null;

                if ($groupStart) {
                    $recruitDeadline = $groupStart + (3 * 60);
                    $formingOpen = (time() < $recruitDeadline) && ($totalHikers < 7);
                    if ($formingOpen) {
                        $availableGuiderIDs[] = $guiderID; // can join existing open group
                    }
                } else {
                    // No open-group overlap, guider is available for new bookings
                    $availableGuiderIDs[] = $guiderID;
                }
            }
        }
    }

    if (!empty($availableGuiderIDs)) {
        $guiderFilter = implode(",", $availableGuiderIDs);
        $sql = "SELECT guiderID, username, price, profile_picture, skills, experience, about, mountains, average_rating, total_reviews FROM guider WHERE status = 'active' AND guiderID IN ($guiderFilter)";
    } else {
        $sql = null;
    }
} else {
    // Default list only active guiders
    $sql = "SELECT guiderID, username, price, profile_picture, skills, experience, about, mountains, average_rating, total_reviews FROM guider WHERE status = 'active'";
}

$result = ($sql) ? $conn->query($sql) : null;

// Function to calculate remaining quota for a guider on specific dates
function getRemainingQuota($conn, $guiderID, $startDate, $endDate) {
    if (!$startDate || !$endDate) return null;
    
    $quotaStmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(b.totalHiker), 0) as totalBooked,
            m.name as mountainName,
            m.mountainID as mountainID
        FROM booking b
        JOIN mountain m ON b.mountainID = m.mountainID
        WHERE b.guiderID = ? 
        AND b.groupType = 'open' 
        AND b.startDate <= ? 
        AND b.endDate >= ? 
        AND b.status IN ('pending','accepted','paid')
        GROUP BY m.mountainID, m.name
        LIMIT 1
    ");
    $quotaStmt->bind_param("iss", $guiderID, $endDate, $startDate);
    $quotaStmt->execute();
    $quotaResult = $quotaStmt->get_result()->fetch_assoc();
    
    if (!$quotaResult) return null;
    
    $totalBooked = $quotaResult['totalBooked'];
    $remaining = max(0, 7 - $totalBooked);
    
    return $remaining > 0 ? [
        'remaining' => $remaining,
        'mountainName' => $quotaResult['mountainName'],
        'mountainID' => $quotaResult['mountainID']
    ] : null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
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
    
    /* Guider Blue Color Scheme - Matching HProfile and HHomePage */
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

    /* Main Content Container - Matching HProfile style */
    .main-content {
      padding: 2rem 0;
      min-height: 100vh;
    }

    /* Section Header - Matching HProfile style */
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

    /* Search and Filter Card - Matching HProfile card style */
    .search-card {
      background: white;
      border-radius: 20px;
      padding: 2rem;
      box-shadow: 0 10px 30px rgba(30, 64, 175, 0.1);
      border: 1px solid rgba(30, 64, 175, 0.1);
      margin-bottom: 2rem;
    }

    /* Red buttons for cancel/close */
    .btn-cancel, .btn-close-red {
      background: linear-gradient(135deg, #dc3545, #c82333);
      border: none;
      border-radius: 12px;
      padding: 12px 20px;
      font-weight: 600;
      color: white;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
    }

    .btn-cancel:hover, .btn-close-red:hover {
      background: linear-gradient(135deg, #c82333, #bd2130);
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(220, 53, 69, 0.4);
      color: white;
    }

    .search-filter-group .form-control {
      border-radius: 12px;
      border: 2px solid var(--guider-blue-soft);
      padding: 12px 16px;
      transition: all 0.3s ease;
    }

    .search-filter-group .form-control:focus {
      border-color: var(--guider-blue);
      box-shadow: 0 0 0 0.2rem rgba(30, 64, 175, 0.25);
    }

    .search-filter-group .btn {
      border-radius: 12px;
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      border: none;
      padding: 12px 20px;
      transition: all 0.3s ease;
      color: white;
    }

    .search-filter-group .btn:hover {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      transform: translateY(-2px);
      color: white;
    }

    .search-filter-group .btn i {
      color: white;
    }

    .btn-filter {
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      border: none;
      border-radius: 12px;
      padding: 12px 30px;
      font-weight: 600;
      color: white;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(30, 64, 175, 0.3);
    }

    .btn-filter:hover {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(30, 64, 175, 0.4);
      color: white;
    }

    /* Guider Cards - Matching HProfile card style */
    .guider-card {
      background: white;
      border-radius: 20px;
      padding: 2rem;
      text-align: center;
      box-shadow: 0 10px 30px rgba(30, 64, 175, 0.1);
      border: 1px solid rgba(30, 64, 175, 0.1);
      transition: all 0.3s ease;
      height: 100%;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }

    .guider-card:hover {
      transform: translateY(-10px);
      box-shadow: 0 20px 40px rgba(30, 64, 175, 0.2);
    }

    .guider-card img {
      width: 80px;
      height: 80px;
      margin: 0 auto 1rem;
      border: 4px solid var(--guider-blue-soft);
      transition: all 0.3s ease;
    }

    .guider-card:hover img {
      border-color: var(--guider-blue);
      transform: scale(1.05);
    }

    .guider-name {
      color: var(--guider-blue-dark);
      font-weight: 600;
      margin-bottom: 0.5rem;
      font-size: 1.25rem;
    }

    .guider-price {
      color: var(--guider-blue);
      font-weight: 700;
      margin-bottom: 0.5rem;
      font-size: 1.1rem;
    }

    /* Quota Info Styling */
    .quota-info {
      background: linear-gradient(135deg, #fbbf24, #f59e0b);
      color: white;
      padding: 0.5rem 1rem;
      border-radius: 20px;
      font-size: 0.9rem;
      font-weight: 600;
      margin-bottom: 1rem;
      display: inline-flex;
      align-items: center;
      box-shadow: 0 2px 8px rgba(251, 191, 36, 0.3);
    }

    .quota-info i {
      font-size: 0.8rem;
    }

    .quota-text {
      font-size: 0.85rem;
    }

    /* Mountain Info Styling */
    .mountain-info {
      background: linear-gradient(135deg, #3b82f6, #1e40af);
      color: white;
      padding: 0.5rem 1rem;
      border-radius: 20px;
      font-size: 0.9rem;
      font-weight: 600;
      margin-bottom: 1rem;
      display: inline-flex;
      align-items: center;
      box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
    }

    .mountain-info i {
      font-size: 0.8rem;
    }

    .mountain-text {
      font-size: 0.85rem;
    }

    .guider-profile-img {
      transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    
    .guider-profile-img:hover {
      transform: scale(1.1);
      box-shadow: 0 8px 25px rgba(30, 64, 175, 0.4) !important;
    }

    .guider-rating {
      color: #fbbf24;
      font-size: 0.9rem;
      margin-bottom: 1.5rem;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.25rem;
    }

    .guider-rating i {
      color: #fbbf24;
    }

    .guider-rating.na {
      color: #6b7280;
    }

    .guider-rating.na i {
      color: #d1d5db;
    }

    .guider-rating .fa-star-half-alt {
      color: #fbbf24;
    }

    .guider-rating .far.fa-star {
      color: #d1d5db;
    }

    /* Buttons - Matching HProfile button style */
    .btn-book {
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      border: none;
      border-radius: 12px;
      padding: 12px 20px;
      font-weight: 600;
      color: white;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(30, 64, 175, 0.3);
      flex: 1;
    }

    .btn-book:hover {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(30, 64, 175, 0.4);
      color: white;
    }

    .btn-view {
      background: linear-gradient(135deg, #fbbf24, #f59e0b);
      border: none;
      border-radius: 12px;
      padding: 12px 20px;
      font-weight: 600;
      color: white;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(251, 191, 36, 0.3);
      flex: 1;
    }

    .btn-view:hover {
      background: linear-gradient(135deg, #f59e0b, #d97706);
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(251, 191, 36, 0.4);
      color: white;
    }

    .guider-actions {
      display: flex;
      gap: 0.75rem;
      margin-top: auto;
    }

    /* Modal Styles - Matching HProfile modal style */
    .modal-content {
      border-radius: 20px;
      border: none;
      box-shadow: 0 20px 60px rgba(30, 64, 175, 0.3);
    }

    .modal-header {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      color: white;
      border-radius: 20px 20px 0 0;
      border: none;
    }

    .modal-body {
      padding: 2rem;
    }

    .modal-footer {
      border: none;
      padding: 1.5rem 2rem;
      background: var(--guider-blue-soft);
    }

    /* Date Modal */
    .date-modal .modal-content {
      border-radius: 20px;
    }

    .date-modal .modal-body {
      padding: 2rem;
    }

    .date-input {
      border: 2px solid var(--guider-blue-soft);
      border-radius: 12px;
      padding: 12px 16px;
      transition: all 0.3s ease;
    }

    .date-input:focus {
      border-color: var(--guider-blue);
      box-shadow: 0 0 0 0.2rem rgba(30, 64, 175, 0.25);
    }

    .btn-confirm {
      background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
      border: none;
      border-radius: 12px;
      padding: 12px 30px;
      font-weight: 600;
      color: white;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(30, 64, 175, 0.3);
    }

    .btn-confirm:hover {
      background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(30, 64, 175, 0.4);
      color: white;
    }

    /* Empty State */
    .empty-state {
      text-align: center;
      padding: 3rem 2rem;
      color: #64748b;
    }

    .empty-state i {
      font-size: 4rem;
      color: var(--guider-blue-soft);
      margin-bottom: 1rem;
    }

    .empty-state h4 {
      color: var(--guider-blue-dark);
      margin-bottom: 0.5rem;
    }

    /* Loading States */
    .loading {
      opacity: 0.7;
      pointer-events: none;
    }

    .loading::after {
      content: '';
      position: absolute;
      top: 50%;
      left: 50%;
      width: 16px;
      height: 16px;
      margin: -8px 0 0 -8px;
      border: 2px solid var(--guider-blue);
      border-top: 2px solid transparent;
      border-radius: 50%;
      animation: spin 1s linear infinite;
    }

    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }

    /* Responsive Design */
    @media (max-width: 768px) {
      .section-title {
        font-size: 1.75rem;
      }
      
      .guider-card {
        margin-bottom: 1.5rem;
      }
      
      .guider-actions {
        flex-direction: column;
      }
      
      .search-card {
        padding: 1.5rem;
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
  </style>
</head>
<body>
<!-- Custom Notification Container -->
<div class="notification-container" id="notificationContainer"></div>

<!-- Header -->
<?php $isSuspended = (($_SESSION['hiker_status'] ?? '') === 'suspended'); ?>
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
          <li class="nav-item"><a class="nav-link active" href="HBooking.php">Book Guider</a></li>
          <li class="nav-item"><a class="nav-link" href="HPayment.php">Payment</a></li>
          <li class="nav-item"><a class="nav-link" href="HYourGuider.php">Your Guider</a></li>
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

<!-- Suspension Banner (for suspended hikers) -->
<?php if ($isSuspended): ?>
  <div style="position:sticky;top:0;z-index:1001;">
    <div style="background:linear-gradient(135deg,#fee2e2,#fecaca);border:1px solid #ef4444;color:#7f1d1d;padding:12px 16px;text-align:center;font-weight:700;">
      Your account is currently suspended. You cannot make any bookings until it is unsuspended.
    </div>
  </div>
  <script>
    // Disable booking interactions within main content when suspended
    document.addEventListener('DOMContentLoaded', function(){
      var container = document.querySelector('.main-content');
      if(!container) return;
      container.querySelectorAll('button, a.btn, input[type="submit"], [data-bs-target="#dateModal"]').forEach(function(el){
        el.classList.add('disabled');
        el.setAttribute('aria-disabled','true');
        if (el.tagName === 'A') {
          el.addEventListener('click', function(e){ e.preventDefault(); });
        } else {
          el.disabled = true;
        }
        el.title = 'Booking actions are disabled while your account is suspended';
      });
    });
  </script>
<?php endif; ?>

<!-- Main Content -->
<main class="main-content">
  <div class="container">
    
    <!-- Section Header -->
    <div class="section-header">
      <h1 class="section-title">BOOK YOUR GUIDER</h1>
      <p class="section-subtitle">Find experienced hiking guides for your next adventure</p>
    </div>
    
    <!-- Permit Notice -->
    <div class="permit-notice" style="background: linear-gradient(135deg, #fef3c7, #fde68a); border: 2px solid #f59e0b; border-radius: 12px; padding: 1rem 1.5rem; margin-bottom: 1.5rem; display: flex; align-items: flex-start; gap: 1rem;">
      <i class="fas fa-exclamation-triangle" style="color: #d97706; font-size: 1.5rem; margin-top: 0.25rem;"></i>
      <div>
        <strong style="color: #92400e; font-size: 1rem;">Important: Permit Fee Not Included</strong>
        <p style="margin: 0.5rem 0 0 0; color: #78350f; font-size: 0.9rem; line-height: 1.5;">
          Your booking payment covers the guider service only. A <strong>permit fee of RM10 per person</strong> is required and must be paid directly at guider on the day of your hike. Please bring exact change for a smoother experience.
        </p>
      </div>
    </div>

    <!-- Search and Filter Card -->
    <div class="search-card">
      <div class="row align-items-center">
        <div class="col-md-7">
          <form id="searchForm" class="input-group search-filter-group" onsubmit="return false;">
            <input type="text" id="searchInput" class="form-control" placeholder="Search for guides by name...">
            <button class="btn btn-outline-secondary" type="submit" id="searchBtn">
              <i class="fas fa-search"></i>
            </button>
          </form>
        </div>
        <div class="col-md-5 text-md-end mt-2 mt-md-0">
          <button class="btn btn-filter" data-bs-toggle="modal" data-bs-target="#dateModal">
            <i class="fas fa-calendar-alt me-2"></i>Choose Dates
          </button>
        </div>
      </div>
  </div>

  <!-- Guider Cards -->
  <div class="row g-4">
    <?php
      if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
          $guiderID = htmlspecialchars($row['guiderID']);
          $name = htmlspecialchars($row['username']);
          $price = htmlspecialchars($row['price']);
          $image = !empty($row['profile_picture']) ? (strpos($row['profile_picture'], 'http') === 0 ? htmlspecialchars($row['profile_picture']) : htmlspecialchars('../' . $row['profile_picture'])) : "https://via.placeholder.com/60";

          // Get actual data from database
          $experience = htmlspecialchars($row['experience'] ?? 'Experience not specified');
          $skills = htmlspecialchars($row['skills'] ?? 'Skills not specified');
          $about = htmlspecialchars($row['about'] ?? 'No additional information provided');
          $mountains = htmlspecialchars($row['mountains'] ?? '');
          
          // Get rating data
          $averageRating = $row['average_rating'] ?? 0;
          $totalReviews = $row['total_reviews'] ?? 0;
          
          // Generate star rating display
          $ratingDisplay = '';
          if ($totalReviews > 0 && $averageRating > 0) {
            $ratingDisplay = '<div class="guider-rating">';
            for ($i = 1; $i <= 5; $i++) {
              if ($i <= $averageRating) {
                $ratingDisplay .= '<i class="fas fa-star"></i>';
              } elseif ($i - 0.5 <= $averageRating) {
                $ratingDisplay .= '<i class="fas fa-star-half-alt"></i>';
              } else {
                $ratingDisplay .= '<i class="far fa-star"></i>';
              }
            }
            $ratingDisplay .= '<span>(' . number_format($averageRating, 1) . ' - ' . $totalReviews . ' reviews)</span>';
            $ratingDisplay .= '</div>';
          } else {
            $ratingDisplay = '<div class="guider-rating na">
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <span>No reviews yet</span>
                              </div>';
          }

          // Get remaining quota for this guider on the selected dates
          $quotaData = getRemainingQuota($conn, $guiderID, $startDate, $endDate);
          
          // Fetch reviews for this guider
          $reviewsData = [];
          $reviewStmt = $conn->prepare("
              SELECT r.rating, r.comment, r.createdAt, h.username as hikerName, m.name as mountainName
              FROM review r
              JOIN booking b ON r.bookingID = b.bookingID
              JOIN hiker h ON r.hikerID = h.hikerID
              JOIN mountain m ON b.mountainID = m.mountainID
              WHERE r.guiderID = ?
              ORDER BY r.createdAt DESC
              LIMIT 10
          ");
          $reviewStmt->bind_param('i', $guiderID);
          $reviewStmt->execute();
          $reviewResult = $reviewStmt->get_result();
          while ($reviewRow = $reviewResult->fetch_assoc()) {
              $reviewsData[] = $reviewRow;
          }
          $reviewStmt->close();
          
          // Fetch schedule data: off days + bookings for this guider (next 60 days)
          $scheduleData = [];
          
          // Get off days
          $offDaysStmt = $conn->prepare("
              SELECT offDate, 'offday' as type, NULL as slotsLeft, NULL as mountainName
              FROM schedule 
              WHERE guiderID = ? AND offDate >= CURDATE() AND offDate <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
              ORDER BY offDate ASC
          ");
          $offDaysStmt->bind_param('i', $guiderID);
          $offDaysStmt->execute();
          $offDaysResult = $offDaysStmt->get_result();
          while ($row = $offDaysResult->fetch_assoc()) {
              $scheduleData[$row['offDate']] = [
                  'type' => 'offday',
                  'reason' => 'Off Day',
                  'slotsLeft' => null,
                  'mountainName' => null
              ];
          }
          $offDaysStmt->close();
          
          // Get bookings (close group = fully booked, open group = show slots)
          $bookingStmt = $conn->prepare("
              SELECT b.startDate, b.endDate, b.groupType, b.totalHiker, m.name as mountainName
              FROM booking b
              LEFT JOIN mountain m ON b.mountainID = m.mountainID
              WHERE b.guiderID = ? 
              AND b.endDate >= CURDATE() 
              AND b.startDate <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
              AND b.status IN ('pending', 'accepted', 'paid')
              ORDER BY b.startDate ASC
          ");
          $bookingStmt->bind_param('i', $guiderID);
          $bookingStmt->execute();
          $bookingResult = $bookingStmt->get_result();
          
          while ($booking = $bookingResult->fetch_assoc()) {
              $start = new DateTime($booking['startDate']);
              $end = new DateTime($booking['endDate']);
              $end->modify('+1 day'); // Include end date
              
              $interval = new DateInterval('P1D');
              $period = new DatePeriod($start, $interval, $end);
              
              foreach ($period as $date) {
                  $dateStr = $date->format('Y-m-d');
                  if ($dateStr < date('Y-m-d')) continue; // Skip past dates
                  
                  if ($booking['groupType'] === 'close') {
                      // Close group = fully booked
                      $scheduleData[$dateStr] = [
                          'type' => 'booked',
                          'reason' => 'Booked (Private)',
                          'slotsLeft' => 0,
                          'mountainName' => $booking['mountainName']
                      ];
                  } else {
                      // Open group = show available slots
                      $slotsLeft = 7 - (int)$booking['totalHiker'];
                      if (!isset($scheduleData[$dateStr]) || $scheduleData[$dateStr]['type'] !== 'booked') {
                          $scheduleData[$dateStr] = [
                              'type' => 'open',
                              'reason' => 'Open Group',
                              'slotsLeft' => max(0, $slotsLeft),
                              'mountainName' => $booking['mountainName']
                          ];
                      }
                  }
              }
          }
          $bookingStmt->close();
          
          // Sort by date
          ksort($scheduleData);
          
          $quotaDisplay = '';
          $mountainDisplay = '';
          // Determine if user can join an existing open group (forming window + not full)
          $canJoinOpen = false;
          if ($startDate && $endDate) {
            if ($stmtJoin = $conn->prepare("\n              SELECT COALESCE(SUM(b.totalHiker),0) AS totalHikers, MIN(b.created_at) AS groupStart\n              FROM booking b\n              WHERE b.guiderID = ?\n                AND b.groupType = 'open'\n                AND b.startDate <= ?\n                AND b.endDate >= ?\n                AND b.status IN ('pending','accepted','paid')\n            ")) {
              $stmtJoin->bind_param('iss', $guiderID, $endDate, $startDate);
              $stmtJoin->execute();
              $jr = $stmtJoin->get_result()->fetch_assoc();
              $stmtJoin->close();
              $totalHikersJ = (int)($jr['totalHikers'] ?? 0);
              $groupStartJ = isset($jr['groupStart']) && $jr['groupStart'] ? strtotime($jr['groupStart']) : null;
              if ($groupStartJ) {
                $recruitDeadlineJ = $groupStartJ + (3 * 60);
                $canJoinOpen = (time() < $recruitDeadlineJ) && ($totalHikersJ < 7);
              }
            }
          }
          
          if ($quotaData !== null) {
              $quotaDisplay = '<div class="quota-info">
                  <i class="fas fa-users me-1"></i>
                  <span class="quota-text">' . $quotaData['remaining'] . ' spots left</span>
              </div>';
              
              $mountainDisplay = '<div class="mountain-info">
                  <i class="fas fa-mountain me-1"></i>
                  <span class="mountain-text">' . htmlspecialchars($quotaData['mountainName']) . '</span>
              </div>';
          }

         echo '
            <div class="col-lg-4 col-md-6 guider-wrapper">
                <div class="guider-card">
                    <img src="' . $image . '" class="rounded-circle" style="width:80px;height:80px;object-fit:cover" alt="' . $name . '">
                    <h5 class="guider-name">' . $name . '</h5>
                    <p class="guider-price">RM ' . $price . ' / trip</p>
                    ' . $quotaDisplay . '
                    ' . $mountainDisplay . '
                    ' . $ratingDisplay . '
                    <div class="guider-actions">
                        <form action="HBooking1.php" method="GET" class="flex-grow-1">
                          <input type="hidden" name="guiderID" value="' . $guiderID . '">
                          <input type="hidden" name="start" value="' . $startDate . '">
                          <input type="hidden" name="end" value="' . $endDate . '">' . 
                          ($canJoinOpen && $quotaData !== null ? '<input type="hidden" name="mountainID" value="' . $quotaData['mountainID'] . '"><input type="hidden" name="joinOpen" value="1">' : '') . '
                          <button type="submit" class="btn btn-book w-100">
                            <i class="fas fa-calendar-check me-2"></i>' . (($canJoinOpen && $quotaData !== null) ? 'Join Open Group' : 'BOOK') . '
                          </button>
                        </form>

                        <button type="button" class="btn btn-view flex-grow-1" 
                                data-bs-toggle="modal" data-bs-target="#guiderModal'.$guiderID.'">
                            <i class="fas fa-eye me-2"></i>VIEW
                        </button>
                    </div>
                </div>
            </div>

            <!-- Modal for this guider -->
            <div class="modal fade" id="guiderModal'.$guiderID.'" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-user-circle me-2"></i>Guide Profile
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-4 text-center">
                                    <img src="' . $image . '" class="rounded-circle mb-3 guider-profile-img" style="width:120px;height:120px;object-fit:cover;border:4px solid var(--guider-blue-soft); cursor: pointer;" onclick="openImageModal(\'' . $image . '\', \'' . addslashes($name) . '\')" title="Click to view full image">
                                    <h4 style="color: var(--guider-blue-dark);">' . $name . '</h4>
                                    <h5 style="color: var(--guider-blue);">RM ' . $price . ' / group</h5>
                                </div>
                                <div class="col-md-8">
                                    <h5 style="color: var(--guider-blue-dark); margin-bottom: 1rem;">
                                        <i class="fas fa-info-circle me-2"></i>About
                                    </h5>
                                    <p style="color: #64748b; margin-bottom: 1.5rem;">' . $about . '</p>
                                    
                                    <h5 style="color: var(--guider-blue-dark); margin-bottom: 1rem;">
                                        <i class="fas fa-mountain me-2"></i>Experience
                                    </h5>
                                    <p style="color: #64748b; margin-bottom: 1.5rem;">' . $experience . '</p>
                                    
                                    <h5 style="color: var(--guider-blue-dark); margin-bottom: 1rem;">
                                        <i class="fas fa-tools me-2"></i>Skills
                                    </h5>
                                    <div class="d-flex flex-wrap gap-2 mb-3">
                                        ' . (!empty($skills) && $skills !== 'Skills not specified' ? 
                                            implode('', array_map(function($skill) {
                                                return '<span class="badge" style="background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light)); color: white; padding: 0.5rem 1rem; border-radius: 20px;">' . trim($skill) . '</span>';
                                            }, explode(',', $skills))) : 
                                            '<span class="badge" style="background: linear-gradient(135deg, #6b7280, #9ca3af); color: white; padding: 0.5rem 1rem; border-radius: 20px;">No skills specified</span>') . '
                                    </div>
                                    
                                    <h5 style="color: var(--guider-blue-dark); margin-bottom: 1rem;">
                                        <i class="fas fa-mountain me-2"></i>Mountains Conquered
                                    </h5>
                                    <div class="d-flex flex-wrap gap-2 mb-3">
                                        ' . (!empty($mountains) ? 
                                            implode('', array_map(function($mountain) {
                                                return '<span class="badge" style="background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light)); color: white; padding: 0.5rem 1rem; border-radius: 20px;"><i class="fas fa-mountain me-1"></i>' . trim($mountain) . '</span>';
                                            }, explode(',', $mountains))) : 
                                            '<span class="badge" style="background: linear-gradient(135deg, #6b7280, #9ca3af); color: white; padding: 0.5rem 1rem; border-radius: 20px;">No mountains specified</span>') . '
                                    </div>
                                    
                                    <!-- Reviews Section -->
                                    <h5 style="color: var(--guider-blue-dark); margin-bottom: 1rem; margin-top: 1.5rem;">
                                        <i class="fas fa-comments me-2"></i>Reviews & Comments
                                    </h5>
                                    <div class="reviews-preview" style="background: #f8fafc; border-radius: 12px; padding: 1rem;">
                                        ' . (count($reviewsData) > 0 ? '
                                        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                                            <div style="font-size: 2rem; font-weight: 700; color: var(--guider-blue-dark);">' . number_format($averageRating, 1) . '</div>
                                            <div>
                                                <div class="guider-rating" style="margin-bottom: 0.25rem;">
                                                    ' . (function() use ($averageRating) {
                                                        $stars = "";
                                                        for ($i = 1; $i <= 5; $i++) {
                                                            if ($i <= $averageRating) {
                                                                $stars .= "<i class=\"fas fa-star\"></i>";
                                                            } elseif ($i - 0.5 <= $averageRating) {
                                                                $stars .= "<i class=\"fas fa-star-half-alt\"></i>";
                                                            } else {
                                                                $stars .= "<i class=\"far fa-star\"></i>";
                                                            }
                                                        }
                                                        return $stars;
                                                    })() . '
                                                </div>
                                                <div style="font-size: 0.85rem; color: #64748b;">' . $totalReviews . ' reviews</div>
                                            </div>
                                        </div>
                                        <button type="button" class="btn w-100" style="background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light)); color: white; border: none; border-radius: 10px; padding: 0.75rem;" data-bs-toggle="modal" data-bs-target="#reviewsModal' . $guiderID . '" data-bs-dismiss="modal">
                                            <i class="fas fa-comments me-2"></i>View All Reviews (' . $totalReviews . ')
                                        </button>
                                        ' : '
                                        <div style="text-align: center; padding: 1rem; color: #64748b;">
                                            <i class="fas fa-comment-slash" style="font-size: 2rem; margin-bottom: 0.5rem; opacity: 0.5;"></i>
                                            <p style="margin: 0;">No reviews yet</p>
                                        </div>
                                        ') . '
                                    </div>
                                    
                                    <!-- Schedule Section -->
                                    <h5 style="color: var(--guider-blue-dark); margin-bottom: 1rem; margin-top: 1.5rem;">
                                        <i class="fas fa-calendar-alt me-2"></i>Schedule (Next 60 Days)
                                    </h5>
                                    <div class="schedule-preview" style="background: linear-gradient(135deg, #f0f9ff, #e0f2fe); border-radius: 12px; padding: 1rem;">
                                        ' . (count($scheduleData) > 0 ? '
                                        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                                            <div style="font-size: 2rem; color: var(--guider-blue-dark);"><i class="fas fa-calendar-check"></i></div>
                                            <div>
                                                <div style="font-weight: 600; color: var(--guider-blue-dark);">' . count($scheduleData) . ' day' . (count($scheduleData) > 1 ? 's' : '') . ' with activity</div>
                                                <div style="font-size: 0.85rem; color: #64748b;">View availability and booking status</div>
                                            </div>
                                        </div>
                                        <button type="button" class="btn w-100" style="background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light)); color: white; border: none; border-radius: 10px; padding: 0.75rem;" data-bs-toggle="modal" data-bs-target="#scheduleModal' . $guiderID . '" data-bs-dismiss="modal">
                                            <i class="fas fa-calendar-alt me-2"></i>View Full Schedule
                                        </button>
                                        ' : '
                                        <div style="text-align: center; padding: 1rem; color: #16a34a;">
                                            <i class="fas fa-calendar-check" style="font-size: 2rem; margin-bottom: 0.5rem; opacity: 0.7;"></i>
                                            <p style="margin: 0; font-weight: 500;">Fully Available</p>
                                            <p style="margin: 0; font-size: 0.85rem; color: #22c55e;">No bookings or off days scheduled</p>
                                        </div>
                                        ') . '
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-close-red" data-bs-dismiss="modal">
                                <i class="fas fa-times me-2"></i>Close
                            </button>
                            <form action="HBooking1.php" method="GET" class="flex-grow-1">
                              <input type="hidden" name="guiderID" value="' . $guiderID . '">
                              <input type="hidden" name="start" value="' . $startDate . '">
                              <input type="hidden" name="end" value="' . $endDate . '">
                              ' . (($canJoinOpen && $quotaData !== null) ? '<input type="hidden" name="mountainID" value="' . $quotaData['mountainID'] . '"><input type="hidden" name="joinOpen" value="1">' : '') . '
                              <button type="submit" class="btn btn-book w-100">
                                <i class="fas fa-calendar-check me-2"></i>' . (($canJoinOpen && $quotaData !== null) ? 'Join Open Group' : 'Book This Guide') . '
                              </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Reviews Modal for this guider -->
            <div class="modal fade" id="reviewsModal' . $guiderID . '" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header" style="background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue)); color: white;">
                            <h5 class="modal-title">
                                <i class="fas fa-comments me-2"></i>Reviews for ' . $name . '
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" style="max-height: 60vh; overflow-y: auto;">
                            ' . (count($reviewsData) > 0 ? '
                            <div class="reviews-summary mb-4 p-3" style="background: linear-gradient(135deg, #f0f9ff, #e0f2fe); border-radius: 12px; text-align: center;">
                                <div style="font-size: 3rem; font-weight: 700; color: var(--guider-blue-dark);">' . number_format($averageRating, 1) . '</div>
                                <div class="guider-rating mb-2" style="font-size: 1.5rem;">
                                    ' . (function() use ($averageRating) {
                                        $stars = "";
                                        for ($i = 1; $i <= 5; $i++) {
                                            if ($i <= $averageRating) {
                                                $stars .= "<i class=\"fas fa-star\"></i>";
                                            } elseif ($i - 0.5 <= $averageRating) {
                                                $stars .= "<i class=\"fas fa-star-half-alt\"></i>";
                                            } else {
                                                $stars .= "<i class=\"far fa-star\"></i>";
                                            }
                                        }
                                        return $stars;
                                    })() . '
                                </div>
                                <div style="color: #64748b;">Based on ' . $totalReviews . ' reviews</div>
                            </div>
                            <div class="reviews-list">
                                ' . implode("", array_map(function($review) {
                                    $reviewStars = "";
                                    for ($i = 1; $i <= 5; $i++) {
                                        $reviewStars .= $i <= $review["rating"] ? "<i class=\"fas fa-star\" style=\"color: #f59e0b;\"></i>" : "<i class=\"far fa-star\" style=\"color: #d1d5db;\"></i>";
                                    }
                                    $comment = !empty($review["comment"]) ? htmlspecialchars($review["comment"]) : "<em style=\"color: #9ca3af;\">No comment provided</em>";
                                    $date = date("M j, Y", strtotime($review["createdAt"]));
                                    return "
                                    <div class=\"review-item\" style=\"background: #f8fafc; border-radius: 12px; padding: 1rem; margin-bottom: 1rem; border-left: 4px solid var(--guider-blue);\">
                                        <div style=\"display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;\">
                                            <div style=\"font-weight: 600; color: var(--guider-blue-dark);\">
                                                <i class=\"fas fa-user-circle me-2\"></i>" . htmlspecialchars($review["hikerName"]) . "
                                            </div>
                                            <div style=\"font-size: 0.85rem; color: #64748b;\">" . $date . "</div>
                                        </div>
                                        <div style=\"margin-bottom: 0.5rem;\">" . $reviewStars . "</div>
                                        <div style=\"color: #374151; line-height: 1.6;\">" . $comment . "</div>
                                        <div style=\"font-size: 0.8rem; color: #9ca3af; margin-top: 0.5rem;\">
                                            <i class=\"fas fa-mountain me-1\"></i>" . htmlspecialchars($review["mountainName"]) . "
                                        </div>
                                    </div>";
                                }, $reviewsData)) . '
                            </div>
                            ' : '
                            <div style="text-align: center; padding: 3rem; color: #64748b;">
                                <i class="fas fa-comment-slash" style="font-size: 4rem; margin-bottom: 1rem; opacity: 0.3;"></i>
                                <h5>No Reviews Yet</h5>
                                <p>This guide hasn\'t received any reviews yet. Be the first to book and leave a review!</p>
                            </div>
                            ') . '
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn" style="background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light)); color: white; border: none; border-radius: 10px; padding: 0.75rem 2rem;" data-bs-toggle="modal" data-bs-target="#guiderModal' . $guiderID . '" data-bs-dismiss="modal">
                                <i class="fas fa-arrow-left me-2"></i>Back to Profile
                            </button>
                            <button type="button" class="btn btn-close-red" data-bs-dismiss="modal">
                                <i class="fas fa-times me-2"></i>Close
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Schedule Modal for this guider -->
            <div class="modal fade" id="scheduleModal' . $guiderID . '" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header" style="background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue)); color: white;">
                            <h5 class="modal-title">
                                <i class="fas fa-calendar-alt me-2"></i>Schedule - ' . $name . '
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" style="max-height: 60vh; overflow-y: auto;">
                            <!-- Legend -->
                            <div class="schedule-legend mb-3 p-2" style="background: #f8fafc; border-radius: 8px; display: flex; flex-wrap: wrap; gap: 1rem; font-size: 0.85rem;">
                                <div><span style="display: inline-block; width: 12px; height: 12px; background: #dc2626; border-radius: 3px; margin-right: 5px;"></span>Off Day</div>
                                <div><span style="display: inline-block; width: 12px; height: 12px; background: #7c3aed; border-radius: 3px; margin-right: 5px;"></span>Booked (Private)</div>
                                <div><span style="display: inline-block; width: 12px; height: 12px; background: #059669; border-radius: 3px; margin-right: 5px;"></span>Open Group</div>
                            </div>
                            ' . (count($scheduleData) > 0 ? '
                            <div class="schedule-list">
                                ' . implode("", array_map(function($date, $info) {
                                    $formattedDate = date("l, d M Y", strtotime($date));
                                    $daysUntil = (strtotime($date) - strtotime(date("Y-m-d"))) / 86400;
                                    $daysText = "";
                                    if ($daysUntil == 0) {
                                        $daysText = "Today";
                                    } elseif ($daysUntil == 1) {
                                        $daysText = "Tomorrow";
                                    } else {
                                        $daysText = "In " . (int)$daysUntil . " days";
                                    }
                                    
                                    // Set colors and icons based on type
                                    $bgColor = "#fef2f2"; $borderColor = "#dc2626"; $textColor = "#991b1b"; $icon = "fa-calendar-times";
                                    $statusBadge = "<span style=\"background: #dc2626; color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600;\">Off Day</span>";
                                    
                                    if ($info["type"] === "booked") {
                                        $bgColor = "#f5f3ff"; $borderColor = "#7c3aed"; $textColor = "#5b21b6"; $icon = "fa-lock";
                                        $mountainText = $info["mountainName"] ? " - " . htmlspecialchars($info["mountainName"]) : "";
                                        $statusBadge = "<span style=\"background: #7c3aed; color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600;\">Booked" . $mountainText . "</span>";
                                    } elseif ($info["type"] === "open") {
                                        $bgColor = "#ecfdf5"; $borderColor = "#059669"; $textColor = "#065f46"; $icon = "fa-users";
                                        $slots = (int)$info["slotsLeft"];
                                        $mountainText = $info["mountainName"] ? " @ " . htmlspecialchars($info["mountainName"]) : "";
                                        $statusBadge = "<span style=\"background: #059669; color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600;\">Open Group" . $mountainText . " - " . $slots . " slot" . ($slots != 1 ? "s" : "") . " left</span>";
                                    }
                                    
                                    return "
                                    <div class=\"schedule-item\" style=\"background: " . $bgColor . "; border-radius: 12px; padding: 1rem; margin-bottom: 0.75rem; border-left: 4px solid " . $borderColor . ";\">
                                        <div style=\"display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 0.5rem;\">
                                            <div>
                                                <div style=\"font-weight: 600; color: " . $textColor . "; margin-bottom: 0.25rem;\">
                                                    <i class=\"fas " . $icon . " me-2\"></i>" . $formattedDate . "
                                                </div>
                                                <div style=\"font-size: 0.8rem; color: #64748b;\">" . $daysText . "</div>
                                            </div>
                                            <div>" . $statusBadge . "</div>
                                        </div>
                                    </div>";
                                }, array_keys($scheduleData), array_values($scheduleData))) . '
                            </div>
                            ' : '
                            <div style="text-align: center; padding: 3rem; color: #16a34a;">
                                <i class="fas fa-calendar-check" style="font-size: 4rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <h5 style="color: #166534;">Fully Available!</h5>
                                <p>This guide has no bookings or off days scheduled for the next 60 days.</p>
                            </div>
                            ') . '
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn" style="background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light)); color: white; border: none; border-radius: 10px; padding: 0.75rem 2rem;" data-bs-toggle="modal" data-bs-target="#guiderModal' . $guiderID . '" data-bs-dismiss="modal">
                                <i class="fas fa-arrow-left me-2"></i>Back to Profile
                            </button>
                            <button type="button" class="btn btn-close-red" data-bs-dismiss="modal">
                                <i class="fas fa-times me-2"></i>Close
                            </button>
                        </div>
                    </div>
                </div>
            </div>';
        }
    } else {
        echo '
        <div class="col-12">
            <div class="empty-state">
                <i class="fas fa-search"></i>
                <h4>No Guides Available</h4>
                <p>No guides are available for the selected date range. Please try selecting different dates or check back later.</p>
                <button class="btn btn-filter" data-bs-toggle="modal" data-bs-target="#dateModal">
                    <i class="fas fa-calendar-alt me-2"></i>Choose Different Dates
                </button>
            </div>
        </div>';
    }
    ?>
</div>
  </div>
</main>

<!-- Date Modal -->
<div class="modal fade date-modal" id="dateModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <form method="GET" action="HBooking.php">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">
            <i class="fas fa-calendar-alt me-2"></i>Select Your Adventure Dates
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
        <div class="modal-body">
          <!-- Visual Calendar -->
          <div class="calendar-container">
            <div class="calendar-header">
              <h6 class="calendar-title" id="calendarTitle">Loading...</h6>
              <div class="calendar-nav">
                <button type="button" class="calendar-nav-btn" id="prevMonth">
                  <i class="fas fa-chevron-left"></i>
                </button>
                <button type="button" class="calendar-nav-btn" id="nextMonth">
                  <i class="fas fa-chevron-right"></i>
                </button>
          </div>
        </div>
            
            <div id="calendarContent">
              <div class="calendar-loading">
                <i class="fas fa-spinner"></i>
                <span class="ms-2">Loading calendar...</span>
              </div>
            </div>
            
            <div class="calendar-legend">
              <div class="legend-item">
                <div class="legend-color legend-available"></div>
                <span>Available (Future)</span>
              </div>
              <div class="legend-item">
                <div class="legend-color legend-unavailable"></div>
                <span>Past Dates</span>
              </div>
              <div class="legend-item">
                <div class="legend-color legend-selected"></div>
                <span>Selected</span>
              </div>
            </div>
          </div>
          
          <!-- Hidden inputs for form submission -->
          <input type="hidden" id="startDate" name="start" required>
          <input type="hidden" id="endDate" name="end" required>
          
          <div class="mt-3">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" style="color: var(--guider-blue-dark); font-weight: 600;">
                  <i class="fas fa-play-circle me-2"></i>Start Date
                </label>
                <div class="form-control" id="startDateDisplay" style="background: #f8fafc; border: 2px solid var(--guider-blue-soft);">
                  <span class="text-muted">Select start date from calendar</span>
                </div>
              </div>
              <div class="col-md-6">
                <label class="form-label" style="color: var(--guider-blue-dark); font-weight: 600;">
                  <i class="fas fa-stop-circle me-2"></i>End Date
                </label>
                <div class="form-control" id="endDateDisplay" style="background: #f8fafc; border: 2px solid var(--guider-blue-soft);">
                  <span class="text-muted">Select end date from calendar</span>
                </div>
              </div>
            </div>
          </div>
          
          <div class="mt-3">
            <small style="color: #64748b;">
              <i class="fas fa-info-circle me-1"></i>
              Click on available dates (green) to select your adventure period. Red dates are past dates and cannot be selected.
            </small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-cancel" data-bs-dismiss="modal">
            <i class="fas fa-times me-2"></i>Cancel
          </button>
          <button type="submit" class="btn btn-confirm" id="findGuidersBtn" disabled>
            <i class="fas fa-check me-2"></i>Find Available Guides
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

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

// Visual Calendar System
class VisualCalendar {
    constructor() {
        this.currentDate = new Date();
        this.selectedStartDate = null;
        this.selectedEndDate = null;
        this.unavailableDates = new Set();
        this.currentMonth = this.currentDate.getMonth();
        this.currentYear = this.currentDate.getFullYear();
        
        this.init();
    }

    async init() {
        await this.loadUnavailableDates();
        this.setupEventListeners();
        this.renderCalendar();
    }

    async loadUnavailableDates() {
        try {
            const response = await fetch('get_unavailable_dates.php', { cache: 'no-store', headers: { 'Accept': 'application/json' } });
            if (!response.ok) {
                console.warn('Unavailable dates endpoint returned non-OK:', response.status);
                // Non-blocking: proceed with empty unavailable dates
                return;
            }
            let data = null;
            try {
                data = await response.json();
            } catch (parseErr) {
                console.warn('Unavailable dates response is not valid JSON');
                return; // Non-blocking
            }
            if (data && data.success && Array.isArray(data.dates)) {
                // Only past dates are blocked - all future dates are available
                // Guider availability filtering happens after date selection
                this.unavailableDates = new Set(data.dates);
            }
        } catch (error) {
            console.error('Error loading unavailable dates:', error);
            // Silent fail: keep calendar usable instead of showing an error toast
        }
    }

    setupEventListeners() {
        document.getElementById('prevMonth').addEventListener('click', () => {
            this.currentMonth--;
            if (this.currentMonth < 0) {
                this.currentMonth = 11;
                this.currentYear--;
            }
            this.renderCalendar();
        });

        document.getElementById('nextMonth').addEventListener('click', () => {
            this.currentMonth++;
            if (this.currentMonth > 11) {
                this.currentMonth = 0;
                this.currentYear++;
            }
            this.renderCalendar();
        });
    }

    renderCalendar() {
        const calendarContent = document.getElementById('calendarContent');
        const calendarTitle = document.getElementById('calendarTitle');
        
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
            
            const clickHandler = isUnavailable ? '' : `onclick="calendar.selectDate('${dateString}')"`;
            
            calendarHTML += `
                <div class="${dayClasses}" ${clickHandler}>
                    ${day}
                </div>
            `;
        }
        
        calendarHTML += '</div>';
        calendarContent.innerHTML = calendarHTML;
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
        const startDisplay = document.getElementById('startDateDisplay');
        const endDisplay = document.getElementById('endDateDisplay');
        const startInput = document.getElementById('startDate');
        const endInput = document.getElementById('endDate');
        
        if (this.selectedStartDate) {
            const startFormatted = this.formatDateForDisplay(this.selectedStartDate);
            startDisplay.innerHTML = `<i class="fas fa-calendar-check me-2 text-success"></i>${startFormatted}`;
            startInput.value = this.selectedStartDate;
        } else {
            startDisplay.innerHTML = '<span class="text-muted">Select start date from calendar</span>';
            startInput.value = '';
        }
        
        if (this.selectedEndDate) {
            const endFormatted = this.formatDateForDisplay(this.selectedEndDate);
            endDisplay.innerHTML = `<i class="fas fa-calendar-check me-2 text-success"></i>${endFormatted}`;
            endInput.value = this.selectedEndDate;
        } else {
            endDisplay.innerHTML = '<span class="text-muted">Select end date from calendar</span>';
            endInput.value = '';
        }
    }

    updateSubmitButton() {
        const submitBtn = document.getElementById('findGuidersBtn');
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

// Initialize calendar when modal is shown
document.getElementById('dateModal').addEventListener('shown.bs.modal', function () {
    if (typeof calendar === 'undefined') {
        window.calendar = new VisualCalendar();
    } else {
        calendar.renderCalendar();
    }
});
    </script>
    <script>
      document.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const start = urlParams.get('start');
        const end = urlParams.get('end');

        if (!start || !end) {
          // Disable only the BOOK buttons, not the Choose Date button
          document.querySelectorAll('form[action^="HBooking1.php"] button[type="submit"]').forEach(btn => {
            btn.disabled = true;
            btn.title = "Please choose a date first";
          });

          notificationSystem.warning('Date Required', 'Please select a start and end date before booking a guider.');
        }
      });
    </script>


<script>
  document.addEventListener('DOMContentLoaded', () => {
    const today = new Date();
    const yyyy = today.getFullYear();
    const mm = String(today.getMonth() + 1).padStart(2, '0');
    const dd = String(today.getDate()).padStart(2, '0');
    const formattedToday = `${yyyy}-${mm}-${dd}`;

    const startInputs = document.querySelectorAll('input[name="start"]');
    const endInputs = document.querySelectorAll('input[name="end"]');

    startInputs.forEach(startInput => {
      startInput.setAttribute('min', formattedToday);
    });

    endInputs.forEach(endInput => {
      endInput.setAttribute('min', formattedToday);
    });

    // Ensure end date respects start date selection
    startInputs.forEach((startInput, i) => {
      startInput.addEventListener('change', () => {
        const endInput = endInputs[i];
        const selectedStart = startInput.value;
        endInput.min = selectedStart;

        if (endInput.value && endInput.value < selectedStart) {
          endInput.value = "";
        }
      });
    });
  });
</script>




<script>
  function filterGuiders() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase().trim();
    const guiderCards = document.querySelectorAll('.guider-wrapper');
    let visibleCount = 0;

    guiderCards.forEach(card => {
      const name = card.querySelector('.guider-name')?.textContent.toLowerCase();
      const isVisible = !searchTerm || (name && name.includes(searchTerm));
      
      if (isVisible) {
        card.style.display = 'block';
        visibleCount++;
      } else {
        card.style.display = 'none';
      }
    });

    // Show message if no results found
    if (searchTerm && visibleCount === 0) {
      const container = document.querySelector('.row.g-4');
      const existingMessage = container.querySelector('.no-results-message');
      if (!existingMessage) {
        const noResultsDiv = document.createElement('div');
        noResultsDiv.className = 'col-12 no-results-message';
        noResultsDiv.innerHTML = `
          <div class="empty-state">
            <i class="fas fa-search"></i>
            <h4>No Guides Found</h4>
            <p>No guides match your search for "${searchTerm}". Try a different search term.</p>
            <button class="btn btn-filter" onclick="clearSearch()">
              <i class="fas fa-times me-2"></i>Clear Search
            </button>
          </div>
        `;
        container.appendChild(noResultsDiv);
      }
    } else {
      // Remove no results message if it exists
      const existingMessage = document.querySelector('.no-results-message');
      if (existingMessage) {
        existingMessage.remove();
      }
    }
  }

  function clearSearch() {
    document.getElementById('searchInput').value = '';
    filterGuiders();
  }

  // Real-time search as user types
  document.getElementById('searchInput').addEventListener('input', function() {
    filterGuiders();
  });

  document.getElementById('searchForm').addEventListener('submit', function(e) {
    e.preventDefault();
    filterGuiders();
  });

  document.getElementById('searchBtn').addEventListener('click', function(e) {
    e.preventDefault();
    filterGuiders();
  });

  document.getElementById('searchInput').addEventListener('keyup', function(e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      filterGuiders();
    }
  });
</script>

<!-- Image Lightbox Modal -->
<div class="modal fade" id="imageViewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width: 90vw;">
    <div class="modal-content" style="background: transparent; border: none; box-shadow: none;">
      <div class="modal-body text-center p-0 position-relative">
        <button type="button" class="btn-close btn-close-white position-absolute" style="top: -40px; right: 0; font-size: 1.5rem; opacity: 1;" data-bs-dismiss="modal" aria-label="Close"></button>
        <img id="imageViewModalImg" src="" alt="" style="max-width: 100%; max-height: 80vh; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.5);">
        <div id="imageViewModalCaption" style="color: white; margin-top: 1rem; font-size: 1.2rem; font-weight: 600; text-shadow: 0 2px 4px rgba(0,0,0,0.5);"></div>
      </div>
    </div>
  </div>
</div>

<script>
function openImageModal(imageSrc, guiderName) {
  document.getElementById('imageViewModalImg').src = imageSrc;
  document.getElementById('imageViewModalCaption').textContent = guiderName;
  var imageModal = new bootstrap.Modal(document.getElementById('imageViewModal'));
  imageModal.show();
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet" />

<?php include_once '../AIChatbox/chatbox_include.php'; ?>

</body>
</html>
