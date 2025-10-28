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

    // Delete user from table
    $stmt = $conn->prepare("DELETE FROM {$table} WHERE {$idColumn} = ?");
    $stmt->bind_param('i', $userID);

    if (!$stmt->execute()) {
        throw new Exception('Database delete failed');
    }

    if ($stmt->affected_rows === 0) {
        throw new Exception('User not found');
    }

    echo json_encode([
        'success' => true,
        'message' => ucfirst($userType) . ' deleted successfully'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
