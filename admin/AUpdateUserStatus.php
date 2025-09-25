<?php
require_once '../shared/db_connection.php';

header('Content-Type: application/json');

try {
    // Skip session validation for now (for debugging)
    // session_start();
    // if (!isset($_SESSION['admin_id'])) {
    //     throw new Exception('Admin not logged in');
    // }
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['userID']) || !isset($input['action']) || !isset($input['userType'])) {
        throw new Exception('Missing required parameters');
    }
    
    $userID = intval($input['userID']);
    $action = $input['action']; // 'suspend', 'unsuspend', 'ban'
    $userType = $input['userType']; // 'hiker' or 'guider'
    
    // Validate action
    if (!in_array($action, ['suspend', 'unsuspend', 'ban', 'approve', 'reject'])) {
        throw new Exception('Invalid action');
    }
    
    // Validate user type
    if (!in_array($userType, ['hiker', 'guider'])) {
        throw new Exception('Invalid user type');
    }
    
    // Determine new status
    $newStatus = 'active';
    if ($action === 'suspend') {
        $newStatus = 'suspended';
    } elseif ($action === 'ban' || $action === 'reject') {
        $newStatus = 'banned';
    } elseif ($action === 'approve') {
        $newStatus = 'active';
    }
    
    // Update the appropriate table
    $table = $userType;
    $idColumn = $userType . 'ID';
    
    $stmt = $conn->prepare("UPDATE {$table} SET status = ? WHERE {$idColumn} = ?");
    $stmt->bind_param('si', $newStatus, $userID);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $message = '';
            if ($action === 'approve') {
                $message = ucfirst($userType) . ' approved successfully';
            } elseif ($action === 'reject') {
                $message = ucfirst($userType) . ' rejected and banned';
            } else {
                $message = ucfirst($userType) . ' ' . $action . 'ed successfully';
            }
            
            echo json_encode([
                'success' => true,
                'message' => $message,
                'newStatus' => $newStatus
            ]);
        } else {
            throw new Exception('User not found or no changes made');
        }
    } else {
        throw new Exception('Database update failed');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
