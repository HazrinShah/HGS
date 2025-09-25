<?php
require_once '../shared/db_connection.php';

// For now, skip session validation to test the dashboard
// TODO: Add proper admin session validation later

try {
    $data = [];

    // 1. Financial Report Data - Monthly Revenue
    $year_filter = isset($_GET['year']) ? $_GET['year'] : '';
    $month_filter = isset($_GET['month']) ? $_GET['month'] : '';
    
    $financial_query = "
        SELECT 
            DATE_FORMAT(startDate, '%Y-%m') as month,
            SUM(price) as total_revenue,
            COUNT(*) as total_bookings
        FROM booking 
        WHERE status = 'completed'
    ";
    
    // Add year and month filters if provided
    if ($year_filter) {
        $financial_query .= " AND YEAR(startDate) = '" . $conn->real_escape_string($year_filter) . "'";
    }
    if ($month_filter) {
        $financial_query .= " AND MONTH(startDate) = '" . $conn->real_escape_string($month_filter) . "'";
    }
    
    // If no filters, show last 6 months
    if (!$year_filter && !$month_filter) {
        $financial_query .= " AND startDate >= DATE_SUB(NOW(), INTERVAL 6 MONTH)";
    }
    
    $financial_query .= "
        GROUP BY DATE_FORMAT(startDate, '%Y-%m')
        ORDER BY month
    ";
    
    $result = $conn->query($financial_query);
    $financial_data = [];
    $monthly_revenue = [];
    $monthly_bookings = [];
    
    while ($row = $result->fetch_assoc()) {
        $financial_data[] = $row;
        $monthly_revenue[] = (float)$row['total_revenue'];
        $monthly_bookings[] = (int)$row['total_bookings'];
    }
    
    // Get total revenue (respecting filters)
    $total_revenue_query = "SELECT SUM(price) as total FROM booking WHERE status = 'completed'";
    if ($year_filter) {
        $total_revenue_query .= " AND YEAR(startDate) = '" . $conn->real_escape_string($year_filter) . "'";
    }
    if ($month_filter) {
        $total_revenue_query .= " AND MONTH(startDate) = '" . $conn->real_escape_string($month_filter) . "'";
    }
    $total_revenue_result = $conn->query($total_revenue_query);
    $total_revenue = $total_revenue_result->fetch_assoc()['total'] ?? 0;

    // 2. Guider Performance Data
    $performance_query = "
        SELECT 
            g.guiderID,
            g.username,
            COALESCE(g.average_rating, 0) as average_rating,
            COALESCE(g.total_reviews, 0) as total_reviews,
            COUNT(b.bookingID) as total_bookings,
            SUM(CASE WHEN b.status = 'completed' THEN 1 ELSE 0 END) as completed_bookings
        FROM guider g
        LEFT JOIN booking b ON g.guiderID = b.guiderID
        GROUP BY g.guiderID, g.username, g.average_rating, g.total_reviews
        HAVING total_bookings > 0
        ORDER BY average_rating DESC, total_bookings DESC
        LIMIT 10
    ";
    
    $result = $conn->query($performance_query);
    $performance_data = [];
    $guider_names = [];
    $guider_ratings = [];
    $guider_bookings = [];
    
    while ($row = $result->fetch_assoc()) {
        $performance_data[] = $row;
        $guider_names[] = $row['username'];
        $guider_ratings[] = (float)$row['average_rating'];
        $guider_bookings[] = (int)$row['total_bookings'];
    }

    // 3. Booking Status Data
    $booking_status_query = "
        SELECT 
            status,
            COUNT(*) as count
        FROM booking 
        GROUP BY status
    ";
    
    $result = $conn->query($booking_status_query);
    $booking_status_data = [];
    $status_labels = [];
    $status_counts = [];
    
    while ($row = $result->fetch_assoc()) {
        $booking_status_data[] = $row;
        $status_labels[] = ucfirst($row['status']);
        $status_counts[] = (int)$row['count'];
    }

    // 4. User Analytics Data
    $user_query = "
        SELECT 
            'Hikers' as user_type,
            COUNT(*) as count
        FROM hiker
        UNION ALL
        SELECT 
            'Guiders' as user_type,
            COUNT(*) as count
        FROM guider
        UNION ALL
        SELECT 
            'Admins' as user_type,
            COUNT(*) as count
        FROM admin
    ";
    
    $result = $conn->query($user_query);
    $user_data = [];
    $user_types = [];
    $user_counts = [];
    
    while ($row = $result->fetch_assoc()) {
        $user_data[] = $row;
        $user_types[] = $row['user_type'];
        $user_counts[] = (int)$row['count'];
    }

    // 5. User Growth Data (Monthly user activity based on bookings)
    $user_growth_query = "
        SELECT 
            DATE_FORMAT(b.startDate, '%Y-%m') as month,
            COUNT(DISTINCT b.hikerID) as active_hikers,
            COUNT(DISTINCT b.guiderID) as active_guiders
        FROM booking b
        WHERE b.startDate >= DATE_SUB(NOW(), INTERVAL 4 MONTH)
        GROUP BY DATE_FORMAT(b.startDate, '%Y-%m')
        ORDER BY month DESC
        LIMIT 4
    ";
    
    $result = $conn->query($user_growth_query);
    $user_growth_data = [];
    $month_labels = [];
    $month_counts = [];
    
    while ($row = $result->fetch_assoc()) {
        $user_growth_data[] = $row;
        $month_labels[] = date('M Y', strtotime($row['month'] . '-01'));
        $month_counts[] = (int)$row['active_hikers'] + (int)$row['active_guiders'];
    }

    // 6. Recent Activity Data
    $recent_bookings_query = "
        SELECT 
            b.bookingID,
            h.username as hiker_name,
            g.username as guider_name,
            m.name as mountain_name,
            b.startDate,
            b.price,
            b.status
        FROM booking b
        JOIN hiker h ON b.hikerID = h.hikerID
        JOIN guider g ON b.guiderID = g.guiderID
        JOIN mountain m ON b.mountainID = m.mountainID
        ORDER BY b.startDate DESC
        LIMIT 5
    ";
    
    $result = $conn->query($recent_bookings_query);
    $recent_bookings = [];
    
    while ($row = $result->fetch_assoc()) {
        $recent_bookings[] = $row;
    }

    // Compile all data
    $data = [
        'financial' => [
            'monthly_revenue' => $monthly_revenue,
            'monthly_bookings' => $monthly_bookings,
            'total_revenue' => (float)$total_revenue,
            'labels' => array_column($financial_data, 'month')
        ],
        'performance' => [
            'guider_names' => $guider_names,
            'guider_ratings' => $guider_ratings,
            'guider_bookings' => $guider_bookings
        ],
        'bookings' => [
            'status_labels' => $status_labels,
            'status_counts' => $status_counts
        ],
        'users' => [
            'user_types' => $user_types,
            'user_counts' => $user_counts
        ],
        'user_growth' => [
            'month_labels' => $month_labels,
            'month_counts' => $month_counts
        ],
        'recent_bookings' => $recent_bookings
    ];

    header('Content-Type: application/json');
    echo json_encode($data);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

$conn->close();
?>
