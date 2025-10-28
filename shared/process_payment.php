<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['hikerID'])) {
    header("Location: HLogin.html");
    exit;
}

$hikerID = $_SESSION['hikerID'];
$bookingID = $_GET['bookingID'] ?? null;
$paymentMethodID = $_GET['paymentMethodID'] ?? null;

if (!$bookingID || !$paymentMethodID) {
    header("Location: HPayment.php");
    exit;
}

// Database connection
include 'db_connection.php';

// Fetch booking details
$bookingQuery = "SELECT b.*, g.username as guiderName, g.price as guiderPrice, m.name as mountainName, m.picture
                 FROM booking b 
                 JOIN guider g ON b.guiderID = g.guiderID 
                 JOIN mountain m ON b.mountainID = m.mountainID 
                 WHERE b.bookingID = ? AND b.hikerID = ? AND b.status = 'pending'";
$stmt = $conn->prepare($bookingQuery);
$stmt->bind_param("ii", $bookingID, $hikerID);
$stmt->execute();
$result = $stmt->get_result();
$booking = $result->fetch_assoc();
$stmt->close();

if (!$booking) {
    header("Location: HPayment.php?error=invalid_booking");
    exit;
}

// If open group, recalculate final amount based on final group size at closure
if ($booking['groupType'] === 'open') {
    // Determine group closure and final size: include pending/accepted/paid to lock seats
    $stmtGrp = $conn->prepare("\n        SELECT COALESCE(SUM(b2.totalHiker),0) AS finalSize, MIN(b2.created_at) AS groupStart\n        FROM booking b2\n        WHERE b2.mountainID = ?\n          AND b2.guiderID = ?\n          AND b2.groupType = 'open'\n          AND b2.startDate <= ?\n          AND b2.endDate >= ?\n          AND b2.status IN ('pending','accepted','paid')\n    ");
    $stmtGrp->bind_param('iiss', $booking['mountainID'], $booking['guiderID'], $booking['endDate'], $booking['startDate']);
    $stmtGrp->execute();
    $grp = $stmtGrp->get_result()->fetch_assoc();
    $stmtGrp->close();

    $finalSize = max(1, (int)($grp['finalSize'] ?? 1));
    $groupStartTs = isset($grp['groupStart']) && $grp['groupStart'] ? strtotime($grp['groupStart']) : time();
    $recruitDeadlineTs = $groupStartTs + (3 * 60);
    $isClosed = ($finalSize >= 7) || (time() >= $recruitDeadlineTs);
    $closureTs = ($finalSize >= 7) ? time() : $recruitDeadlineTs;
    $paymentDeadlineTs = $closureTs + (5 * 60);

    if (!$isClosed) {
        // Safety: do not allow payment if group not closed
        header("Location: ../hiker/HPayment.php?error=group_not_closed");
        exit;
    }

    // Enforce payment window (5 minutes after closure)
    if (time() > $paymentDeadlineTs) {
        header("Location: ../hiker/HPayment.php?error=payment_window_expired");
        exit;
    }

    // Final price per this booking: base price divided by final group size, times this booking's hikers
    $perPerson = (float)$booking['guiderPrice'] / $finalSize;
    $finalAmount = $perPerson * (int)$booking['totalHiker'];

    // Persist recalculated amount back to booking to keep consistency across UI and transactions
    if ($upd = $conn->prepare("UPDATE booking SET price = ? WHERE bookingID = ? AND status = 'pending'")) {
        $upd->bind_param('di', $finalAmount, $bookingID);
        $upd->execute();
        $upd->close();
        // Reflect in current object
        $booking['price'] = $finalAmount;
    }
}

// Get or create FPX payment method for this user
$paymentQuery = "SELECT * FROM payment_methods WHERE hikerID = ? AND methodType = 'FPX' LIMIT 1";
$stmt = $conn->prepare($paymentQuery);
$stmt->bind_param("i", $hikerID);
$stmt->execute();
$result = $stmt->get_result();
$paymentMethod = $result->fetch_assoc();
$stmt->close();

// If no FPX method exists, create one
if (!$paymentMethod) {
    $insertQuery = "INSERT INTO payment_methods (hikerID, methodType, createdAt) VALUES (?, 'FPX', NOW())";
    $stmt = $conn->prepare($insertQuery);
    $stmt->bind_param("i", $hikerID);
    $stmt->execute();
    $paymentMethodID = $conn->insert_id;
    $stmt->close();
    
    // Create payment method object
    $paymentMethod = [
        'paymentID' => $paymentMethodID,
        'methodType' => 'FPX',
        'hikerID' => $hikerID
    ];
}

// ToyyibPay Configuration (Sandbox Mode)
$toyyibpay_secret_key = 'hizp30ly-ke6q-5x6k-4udy-jmshp6zegh30';
$toyyibpay_category_code = '7id2aq5t';
$toyyibpay_url = 'https://dev.toyyibpay.com/index.php/api/createBill';

// Generate unique order ID
$orderID = 'HGS_' . $bookingID . '_' . time();

// Prepare payment data
$billName = substr('Hiking - ' . $booking['mountainName'], 0, 30);
$billDescription = 'Payment for hiking booking with ' . $booking['guiderName'] . ' at ' . $booking['mountainName'] . 
                   ' from ' . date('M j, Y', strtotime($booking['startDate'])) . 
                   ' to ' . date('M j, Y', strtotime($booking['endDate']));

$billAmount = (float)$booking['price'] * 100; // dalam sen
$billReturnUrl = 'http://localhost/HGS/shared/payment_success.php';
$billCallbackUrl = 'http://localhost/HGS/shared/payment_callback.php';

// Fetch user details from database
$userQuery = "SELECT username, email, phone_number FROM hiker WHERE hikerID = ?";
$stmt = $conn->prepare($userQuery);
$stmt->bind_param("i", $hikerID);
$stmt->execute();
$result = $stmt->get_result();
$userData = $result->fetch_assoc();
$stmt->close();

$billTo = $userData['username'] ?? 'Hiker';
$billEmail = $userData['email'] ?? 'hiker@example.com';
$billPhone = $userData['phone_number'] ?? '0123456789';

// Debug log (Sandbox Mode)
error_log("ToyyibPay Sandbox - BookingID: $bookingID, Amount: $billAmount");

// Data untuk ToyyibPay
$data = [
    'userSecretKey' => $toyyibpay_secret_key,
    'categoryCode' => $toyyibpay_category_code,
    'billName' => $billName,
    'billDescription' => $billDescription,
    'billPriceSetting' => 1,
    'billPayorInfo' => 1,
    'billAmount' => $billAmount,
    'billReturnUrl' => $billReturnUrl,
    'billCallbackUrl' => $billCallbackUrl,
    'billExternalReferenceNo' => $orderID,
    'billTo' => $billTo,
    'billEmail' => $billEmail,
    'billPhone' => $billPhone,
    'billPaymentChannel' => '0'
];

// Check existing payment
$checkQuery = "SELECT paymentTransactionID FROM payment_transactions WHERE bookingID = ? AND status = 'pending'";
$stmt = $conn->prepare($checkQuery);
$stmt->bind_param("i", $bookingID);
$stmt->execute();
$result = $stmt->get_result();
$existingPayment = $result->fetch_assoc();
$stmt->close();

if ($existingPayment) {
    $paymentTransactionID = $existingPayment['paymentTransactionID'];
} else {
    $paymentRecordQuery = "INSERT INTO payment_transactions (bookingID, paymentMethodID, orderID, amount, status, createdAt) 
                           VALUES (?, ?, ?, ?, 'pending', NOW())";
    $stmt = $conn->prepare($paymentRecordQuery);
    $stmt->bind_param("iisd", $bookingID, $paymentMethod['paymentID'], $orderID, $booking['price']);
    $stmt->execute();
    $paymentTransactionID = $conn->insert_id;
    $stmt->close();
}

$_SESSION['payment_transaction_id'] = $paymentTransactionID;
$_SESSION['current_booking_id'] = $bookingID;

// Call ToyyibPay API
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $toyyibpay_url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Log response
error_log("ToyyibPay Sandbox HTTP Code: $httpCode");
error_log("ToyyibPay Sandbox Raw Response: " . $response);
if ($curlError) {
    error_log("cURL Error: " . $curlError);
}

if ($httpCode == 200 && $response) {
    $responseData = json_decode($response, true);

    if (isset($responseData[0]['BillCode'])) {
        $billCode = $responseData[0]['BillCode'];

        // Update DB dengan billCode
        $updateQuery = "UPDATE payment_transactions SET billCode = ? WHERE paymentTransactionID = ?";
        $stmt = $conn->prepare($updateQuery);
        $stmt->bind_param("si", $billCode, $paymentTransactionID);
        $stmt->execute();
        $stmt->close();

        // Redirect to ToyyibPay payment page (Sandbox)
        $paymentUrl = "https://dev.toyyibpay.com/" . $billCode;
        header("Location: " . $paymentUrl);
        exit;
    } else {
        echo "<h3>Payment Failed - No BillCode</h3>";
        echo "<pre>" . htmlspecialchars($response) . "</pre>";
        exit;
    }
} else {
    echo "<h3>Payment Failed - API Error</h3>";
    echo "HTTP Code: $httpCode<br>";
    echo "Response: <pre>" . htmlspecialchars($response) . "</pre>";
    echo "cURL Error: " . htmlspecialchars($curlError);
    exit;
}

$conn->close();
?>
