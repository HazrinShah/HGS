<?php
require_once '../shared/db_connection.php';

// For now, skip session validation to test the user management
// TODO: Add proper admin session validation later

try {
    $data = [];
    
    // Get filter parameters
    $user_type = isset($_GET['user_type']) ? $_GET['user_type'] : '';
    $search_term = isset($_GET['search']) ? $_GET['search'] : '';
    
    // Build the query based on filters
    $where_conditions = [];
    $params = [];
    $param_types = '';
    
    // User type filter
    if ($user_type === 'guider') {
        $where_conditions[] = "u.user_type = 'guider'";
    } elseif ($user_type === 'hiker') {
        $where_conditions[] = "u.user_type = 'hiker'";
    }
    
    // Search filter
    if (!empty($search_term)) {
        $where_conditions[] = "(u.username LIKE ? OR u.email LIKE ?)";
        $search_param = '%' . $search_term . '%';
        $params[] = $search_param;
        $params[] = $search_param;
        $param_types .= 'ss';
    }
    
    // Build the main query
    $query = "
        SELECT 
            u.userID,
            u.username,
            u.email,
            u.user_type,
            u.account_status,
            CASE 
                WHEN u.user_type = 'guider' THEN g.average_rating
                ELSE NULL
            END as average_rating,
            CASE 
                WHEN u.user_type = 'guider' THEN g.total_reviews
                ELSE NULL
            END as total_reviews,
            CASE 
                WHEN u.account_status = 'suspended' THEN 'Suspended'
                WHEN u.account_status = 'banned' THEN 'Banned'
                WHEN u.account_status = 'pending' THEN 'Pending'
                WHEN u.user_type = 'guider' THEN 
                    CASE 
                        WHEN g.average_rating > 0 THEN 'Active Guider'
                        ELSE 'New Guider'
                    END
                ELSE 
                    CASE 
                        WHEN EXISTS (SELECT 1 FROM booking WHERE hikerID = u.userID AND startDate >= DATE_SUB(NOW(), INTERVAL 30 DAY)) THEN 'Active'
                        WHEN EXISTS (SELECT 1 FROM booking WHERE hikerID = u.userID) THEN 'Inactive'
                        ELSE 'New User'
                    END
            END as status
        FROM (
            SELECT hikerID as userID, username, email, 'hiker' as user_type, status as account_status FROM hiker
            UNION ALL
            SELECT guiderID as userID, username, email, 'guider' as user_type, status as account_status FROM guider
        ) u
        LEFT JOIN guider g ON u.userID = g.guiderID AND u.user_type = 'guider'
    ";
    
    if (!empty($where_conditions)) {
        $query .= " WHERE " . implode(' AND ', $where_conditions);
    }
    
    $query .= " ORDER BY u.user_type, u.username";
    
    // Prepare and execute the query
    if (!empty($params)) {
        $stmt = $conn->prepare($query);
        $stmt->bind_param($param_types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($query);
    }
    
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    
    // Get user counts by type
    $count_query = "
        SELECT 
            'hiker' as user_type, COUNT(*) as count FROM hiker
            UNION ALL
            SELECT 'guider' as user_type, COUNT(*) as count FROM guider
    ";
    
    $count_result = $conn->query($count_query);
    $user_counts = [];
    while ($row = $count_result->fetch_assoc()) {
        $user_counts[$row['user_type']] = (int)$row['count'];
    }
    
    // Compile response data
    $data = [
        'users' => $users,
        'user_counts' => $user_counts,
        'total_users' => count($users)
    ];
    
    header('Content-Type: application/json');
    $json_output = json_encode($data);
    
    // Check for JSON encoding errors
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(500);
        echo json_encode(['error' => 'JSON encoding error: ' . json_last_error_msg()]);
        exit;
    }
    
    echo $json_output;
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

$conn->close();
?>
