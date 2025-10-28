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
    
    // Determine new status (default path)
    $newStatus = 'active';
    if ($action === 'suspend') {
        $newStatus = 'suspended';
    } elseif ($action === 'ban') {
        $newStatus = 'banned';
    } elseif ($action === 'approve') {
        $newStatus = 'active';
    } elseif ($action === 'reject') {
        // Guider rejection is handled specially below (deletion). For hikers, treat as banned.
        $newStatus = ($userType === 'hiker') ? 'banned' : 'pending';
    }
    
    // Update the appropriate table
    $table = $userType;
    $idColumn = $userType . 'ID';

    // Special case: reject guider -> send rejection email and delete the account
    if ($action === 'reject' && $userType === 'guider') {
        // Fetch user data first (email/username and certificate if needed)
        $infoStmt = $conn->prepare("SELECT email, username FROM {$table} WHERE {$idColumn} = ? LIMIT 1");
        $infoStmt->bind_param('i', $userID);
        if ($infoStmt->execute()) {
            $res = $infoStmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $email = $row['email'];
                $username = $row['username'];
                // Send rejection email (best-effort)
                $subject = 'Your HGS guider application was rejected';
                $body = "Hello {$username},\n\nYour guider application has been reviewed and was not approved. Your account has been removed and will not appear to hikers.\n\nIf you believe this is a mistake, please contact support.\n\nRegards,\nHiking Guidance System";
                $headers = "From: noreply@hgs.com\r\n" .
                           "Reply-To: noreply@hgs.com\r\n" .
                           "Content-Type: text/plain; charset=UTF-8\r\n";
                try {
                    ini_set('SMTP', ini_get('SMTP') ?: 'localhost');
                    ini_set('smtp_port', ini_get('smtp_port') ?: '25');
                    ini_set('sendmail_from', 'noreply@hgs.com');
                    @mail($email, $subject, $body, $headers);
                } catch (Exception $ex) {
                    error_log('[AUpdateUserStatus] Exception sending rejection mail: ' . $ex->getMessage());
                }
            }
        }
        $infoStmt->close();

        // Delete the guider account
        $del = $conn->prepare("DELETE FROM {$table} WHERE {$idColumn} = ?");
        $del->bind_param('i', $userID);
        if ($del->execute() && $del->affected_rows > 0) {
            echo json_encode([
                'success' => true,
                'message' => 'Guider rejected and deleted',
                'deleted' => true
            ]);
            exit;
        } else {
            throw new Exception('Failed to delete guider');
        }
    }
    
    $stmt = $conn->prepare("UPDATE {$table} SET status = ? WHERE {$idColumn} = ?");
    $stmt->bind_param('si', $newStatus, $userID);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $message = '';
            if ($action === 'approve') {
                $message = ucfirst($userType) . ' approved successfully';
            } elseif ($action === 'reject') {
                // Hiker reject -> banned (since guider reject is handled above)
                $message = ucfirst($userType) . ' rejected and banned';
            } else {
                $message = ucfirst($userType) . ' ' . $action . 'ed successfully';
            }
            
            // Send email notification on suspend/ban/reject (hiker reject only; guider handled above)
            if (in_array($action, ['suspend', 'ban']) || ($action === 'reject' && $userType === 'hiker')) {
                // Fetch user email and username
                $infoStmt = $conn->prepare("SELECT email, username FROM {$table} WHERE {$idColumn} = ? LIMIT 1");
                $infoStmt->bind_param('i', $userID);
                if ($infoStmt->execute()) {
                    $res = $infoStmt->get_result();
                    if ($row = $res->fetch_assoc()) {
                        $email = $row['email'];
                        $username = $row['username'];
                        $subject = '';
                        $body = '';
                        if ($action === 'suspend') {
                            $subject = 'Your HGS account has been suspended';
                            $body = "Hello {$username},\n\nYour account has been suspended by the admin. You cannot make bookings (if hiker) or appear in guider search (if guider) until the suspension is lifted.\n\nIf you believe this is a mistake, please contact support.\n\nRegards,\nHiking Guidance System";
                        } else { // ban or reject -> banned
                            $subject = 'Your HGS account has been banned';
                            $body = "Hello {$username},\n\nYour account has been banned by the admin. You can no longer access the system.\n\nIf you believe this is a mistake, please contact support.\n\nRegards,\nHiking Guidance System";
                        }
                        $headers = "From: noreply@hgs.com\r\n" .
                                   "Reply-To: noreply@hgs.com\r\n" .
                                   "Content-Type: text/plain; charset=UTF-8\r\n";
                        // Best-effort mail with basic logging
                        try {
                            // Note: On Windows with XAMPP, php.ini SMTP settings must point to a real SMTP server.
                            // These ini_set values can be overridden by php.ini; adjust php.ini for production.
                            ini_set('SMTP', ini_get('SMTP') ?: 'localhost');
                            ini_set('smtp_port', ini_get('smtp_port') ?: '25');
                            ini_set('sendmail_from', 'noreply@hgs.com');

                            $mailOk = mail($email, $subject, $body, $headers);
                            if (!$mailOk) {
                                error_log("[AUpdateUserStatus] mail() failed for {$email} action={$action} userType={$userType}");
                            } else {
                                error_log("[AUpdateUserStatus] mail() sent to {$email} action={$action} userType={$userType}");
                            }
                        } catch (Exception $ex) {
                            // Log but do not break API
                            error_log('[AUpdateUserStatus] Exception sending mail: ' . $ex->getMessage());
                        }
                    }
                }
                $infoStmt->close();
            }
            
            echo json_encode([
                'success' => true,
                'message' => $message,
                'newStatus' => $newStatus
            ]);
        } else {
            // No rows affected: either user not found or status already set to desired value
            $checkStmt = $conn->prepare("SELECT status FROM {$table} WHERE {$idColumn} = ? LIMIT 1");
            $checkStmt->bind_param('i', $userID);
            if ($checkStmt->execute()) {
                $res = $checkStmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $current = $row['status'];
                    if (strtolower($current) === strtolower($newStatus)) {
                        echo json_encode([
                            'success' => true,
                            'message' => ucfirst($userType) . ' already ' . ($action === 'approve' ? 'active' : ($action === 'unsuspend' ? 'active' : $newStatus)) . ', no changes made',
                            'newStatus' => $current
                        ]);
                    } else {
                        // Status different but DB did not change -> treat as error
                        throw new Exception('Failed to update status');
                    }
                } else {
                    throw new Exception('User not found');
                }
            } else {
                throw new Exception('Failed to verify update');
            }
        }
    } else {
        throw new Exception('Database update failed');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
