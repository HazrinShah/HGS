<?php
session_start();

// Check if admin is logged in
if (!isset($_SESSION['email'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

require_once '../shared/db_connection.php';

// Verify the email belongs to an admin
$email = $_SESSION['email'];
$stmt = $conn->prepare("SELECT * FROM admin WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['appealId'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

$appealId = $input['appealId'];

try {
    // First, let's check what columns exist in the appeal table
    $debugQuery = "DESCRIBE appeal";
    $debugResult = $conn->query($debugQuery);
    $columns = [];
    while ($row = $debugResult->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
    
    // Fetch detailed appeal information - use only columns that exist
    $query = "SELECT a.*, 
                     h.username as hiker_name, h.email as hiker_email, h.phone_number as hiker_phone,
                     g.username as guider_name, g.email as guider_email, g.phone_number as guider_phone,
                     b.startDate, b.endDate, b.status as booking_status, b.price,
                     m.name as mountain_name
              FROM appeal a
              LEFT JOIN hiker h ON a.hikerID = h.hikerID
              LEFT JOIN guider g ON a.guiderID = g.guiderID
              LEFT JOIN booking b ON a.bookingID = b.bookingID
              LEFT JOIN mountain m ON b.mountainID = m.mountainID
              WHERE a.appealID = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $appealId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Appeal not found']);
        exit();
    }
    
    $appeal = $result->fetch_assoc();
    
    // Format dates for display - only if columns exist
    if (isset($appeal['createdAt']) && $appeal['createdAt']) {
        $appeal['createdAt'] = date('Y-m-d H:i:s', strtotime($appeal['createdAt']));
    }
    if (isset($appeal['updatedAt']) && $appeal['updatedAt']) {
        $appeal['updatedAt'] = date('Y-m-d H:i:s', strtotime($appeal['updatedAt']));
    }
    
    // Add debug info
    $appeal['debug_columns'] = $columns;
    
    echo json_encode(['success' => true, 'appeal' => $appeal]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
