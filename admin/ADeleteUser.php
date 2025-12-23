<?php
require_once '../shared/db_connection.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['userID']) || !isset($input['userType'])) {
        throw new Exception('Missing required parameters');
    }

    $userID = intval($input['userID']);
    $userType = $input['userType']; // 'hiker' or 'guider'

    if (!in_array($userType, ['hiker', 'guider'])) {
        throw new Exception('Invalid user type');
    }

    $table = $userType;
    $idColumn = $userType . 'ID';

    // Start transaction for safe deletion
    $conn->begin_transaction();

    try {
        // Delete all related records first to avoid foreign key constraints
        
        // Delete reviews
        $conn->query("DELETE FROM review WHERE {$idColumn} = $userID");
        
        // Delete appeals
        $conn->query("DELETE FROM appeal WHERE {$idColumn} = $userID");
        
        // Delete bookings
        $conn->query("DELETE FROM booking WHERE {$idColumn} = $userID");
        
        // Now delete the user
        $stmt = $conn->prepare("DELETE FROM {$table} WHERE {$idColumn} = ?");
        $stmt->bind_param('i', $userID);

        if (!$stmt->execute()) {
            throw new Exception('Database delete failed');
        }

        if ($stmt->affected_rows === 0) {
            throw new Exception('User not found');
        }

        // Commit transaction
        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => ucfirst($userType) . ' and all related data deleted successfully'
        ]);

    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
