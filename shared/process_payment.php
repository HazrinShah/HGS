<?php
// TEMPORARY DEBUG 
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// check user dah login ke tak
if (!isset($_SESSION['hikerID'])) {
    header("Location: ../hiker/HLogin.html");
    exit;
}

$hikerID = $_SESSION['hikerID'];
$bookingID = $_GET['bookingID'] ?? null;
$paymentMethodID = $_GET['paymentMethodID'] ?? null;

if (!$bookingID || !$paymentMethodID) {
    header("Location: ../hiker/HPayment.php");
    exit;
}

// connect database
include 'db_connection.php';

// ambil detail booking
// untuk open groups, check bookingparticipant table; untuk close groups, check booking.hikerID
$bookingQuery = "SELECT b.*, g.username as guiderName, g.price as guiderPrice, m.name as mountainName, m.picture,
                 bp.qty AS participantQty
                 FROM booking b 
                 JOIN guider g ON b.guiderID = g.guiderID 
                 JOIN mountain m ON b.mountainID = m.mountainID 
                 LEFT JOIN bookingparticipant bp ON bp.bookingID = b.bookingID AND bp.hikerID = ?
                 WHERE b.bookingID = ? 
                   AND b.status = 'pending'
                   AND (
                     (b.groupType = 'open' AND bp.hikerID IS NOT NULL)
                     OR
                     (b.groupType <> 'open' AND b.hikerID = ?)
                   )";
$stmt = $conn->prepare($bookingQuery);
$stmt->bind_param("iii", $hikerID, $bookingID, $hikerID);
$stmt->execute();
$result = $stmt->get_result();
$booking = $result->fetch_assoc();
$stmt->close();

if (!$booking) {
    header("Location: ../hiker/HPayment.php?error=invalid_booking");
    exit;
}

// If open group, recalculate final amount based on final group size at closure
if ($booking['groupType'] === 'open') {
    // Ensure persistent closure table exists (same as HPayment.php)
    $conn->query("CREATE TABLE IF NOT EXISTS open_group_closure (
        id INT AUTO_INCREMENT PRIMARY KEY,
        guiderID INT NOT NULL,
        mountainID INT NOT NULL,
        startDate DATE NOT NULL,
        endDate DATE NOT NULL,
        closed_at INT NOT NULL,
        UNIQUE KEY uniq_group (guiderID, mountainID, startDate, endDate)
    ) ENGINE=InnoDB");

    // tentukan group closure dan final size: include pending/accepted/paid untuk lock seats
    $stmtGrp = $conn->prepare("SELECT COALESCE(SUM(b2.totalHiker),0) AS existingHikers, UNIX_TIMESTAMP(MIN(b2.created_at)) AS groupStartTs
                                FROM booking b2
                                WHERE b2.mountainID = ?
                                  AND b2.guiderID = ?
                                  AND b2.groupType = 'open'
                                  AND b2.startDate <= ?
                                  AND b2.endDate >= ?
                                  AND b2.status IN ('pending','accepted','paid')");
    $stmtGrp->bind_param('iiss', $booking['mountainID'], $booking['guiderID'], $booking['endDate'], $booking['startDate']);
    $stmtGrp->execute();
    $grp = $stmtGrp->get_result()->fetch_assoc();
    $stmtGrp->close();

    $existingHikers = (int)($grp['existingHikers'] ?? 0);
    $groupStartTs = isset($grp['groupStartTs']) && $grp['groupStartTs'] ? (int)$grp['groupStartTs'] : time();
    $recruitDeadlineTs = $groupStartTs + (3 * 60);
    $isClosed = ($existingHikers >= 7) || (time() >= $recruitDeadlineTs);

    // Read persistent closure time if available
    $closureTs = null;
    if ($qclose = $conn->prepare("SELECT closed_at FROM open_group_closure WHERE guiderID = ? AND mountainID = ? AND startDate = ? AND endDate = ? LIMIT 1")) {
        $qclose->bind_param('iiss', $booking['guiderID'], $booking['mountainID'], $booking['startDate'], $booking['endDate']);
        $qclose->execute();
        $cres = $qclose->get_result()->fetch_assoc();
        $qclose->close();
        if ($cres && isset($cres['closed_at'])) { 
            $closureTs = (int)$cres['closed_at']; 
        }
    }

    // If group is closed and no record yet, persist the first close moment
    if ($isClosed && empty($closureTs)) {
        $firstCloseTs = ($existingHikers >= 7) ? time() : $recruitDeadlineTs;
        if ($insc = $conn->prepare("INSERT IGNORE INTO open_group_closure (guiderID, mountainID, startDate, endDate, closed_at) VALUES (?,?,?,?,?)")) {
            $insc->bind_param('iissi', $booking['guiderID'], $booking['mountainID'], $booking['startDate'], $booking['endDate'], $firstCloseTs);
            $insc->execute();
            $insc->close();
            $closureTs = $firstCloseTs;
        }
        // In case of race and record exists, read again
        if (empty($closureTs)) {
            if ($q2 = $conn->prepare("SELECT closed_at FROM open_group_closure WHERE guiderID = ? AND mountainID = ? AND startDate = ? AND endDate = ? LIMIT 1")) {
                $q2->bind_param('iiss', $booking['guiderID'], $booking['mountainID'], $booking['startDate'], $booking['endDate']);
                $q2->execute();
                $cres2 = $q2->get_result()->fetch_assoc();
                $q2->close();
                if ($cres2 && isset($cres2['closed_at'])) { 
                    $closureTs = (int)$cres2['closed_at']; 
                }
            }
        }
    }

    // kalau still tak closed, redirect balik
    if (!$isClosed || empty($closureTs)) {
        error_log("Open group payment rejected - isClosed: " . ($isClosed ? 'true' : 'false') . ", closureTs: " . ($closureTs ?? 'null') . ", existingHikers: $existingHikers, groupStartTs: $groupStartTs");
        header("Location: ../hiker/HPayment.php?error=group_not_closed");
        exit;
    }

    // Calculate payment deadline
    $paymentDeadlineTs = $closureTs + (5 * 60);
    $currentTime = time();
    $timeRemaining = $paymentDeadlineTs - $currentTime;

    // Enforce payment window (5 minutes after closure)
    if ($currentTime > $paymentDeadlineTs) {
        error_log("Open group payment rejected - payment window expired. Current: $currentTime, Deadline: $paymentDeadlineTs, Closure: $closureTs");
        header("Location: ../hiker/HPayment.php?error=payment_window_expired");
        exit;
    }

    error_log("Open group payment proceeding - closureTs: $closureTs, paymentDeadlineTs: $paymentDeadlineTs, timeRemaining: $timeRemaining seconds, finalSize: $finalSize, userQty: $userQty");

    // Final price per this user: base price divided by final group size, times user's participant qty
    $finalSize = max(1, $existingHikers); // Use existing hikers as final size
    $userQty = (int)($booking['participantQty'] ?? 0);
    $perPerson = (float)$booking['guiderPrice'] / $finalSize;
    $finalAmount = $perPerson * $userQty;

    // For open groups, use the calculated per-person amount for this user
    // jangan update booking.price sebab dia represent total group price
    // kita guna $finalAmount untuk payment processing
} else {
    // For close groups, use the booking price as is
    $finalAmount = (float)$booking['price'];
}

// dapat atau create FPX payment method untuk user ni
$paymentQuery = "SELECT * FROM payment_methods WHERE hikerID = ? AND methodType = 'FPX' LIMIT 1";
$stmt = $conn->prepare($paymentQuery);
$stmt->bind_param("i", $hikerID);
$stmt->execute();
$result = $stmt->get_result();
$paymentMethod = $result->fetch_assoc();
$stmt->close();

// kalau tak ada FPX method, create satu
if (!$paymentMethod) {
    $insertQuery = "INSERT INTO payment_methods (hikerID, methodType, createdAt) VALUES (?, 'FPX', NOW())";
    $stmt = $conn->prepare($insertQuery);
    $stmt->bind_param("i", $hikerID);
    $stmt->execute();
    $paymentMethodID = $conn->insert_id;
    $stmt->close();
    
    // create payment method object
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

// Use the calculated finalAmount (per-person for open groups, full price for close groups)
$paymentAmount = isset($finalAmount) ? $finalAmount : (float)$booking['price'];
$billAmount = $paymentAmount * 100; // dalam sen
$billReturnUrl = 'http://hazrinverse.click/shared/payment_success.php';
$billCallbackUrl = 'http://hazrinverse.click/shared/payment_callback.php';

// ambil user details dari database
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

// debug log (Sandbox Mode)
error_log("ToyyibPay Sandbox - BookingID: $bookingID, Amount: $billAmount");

// data untuk ToyyibPay
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

// check payment yang existing
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
    $stmt->bind_param("iisd", $bookingID, $paymentMethod['paymentID'], $orderID, $paymentAmount);
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
// Harden TLS and connection to avoid 526/handshake quirks on local Windows
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // keep off for sandbox/dev on some hosts
curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
curl_setopt($ch, CURLOPT_USERAGENT, 'HGS/1.0 (+https://localhost)');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlInfo = curl_getinfo($ch);
$curlError = curl_error($ch);
curl_close($ch);

// Log response
error_log("ToyyibPay Sandbox HTTP Code: $httpCode");
error_log("ToyyibPay Sandbox Raw Response: " . $response);
error_log('ToyyibPay cURL Info: ' . print_r($curlInfo, true));
if ($curlError) {
    error_log("cURL Error: " . $curlError);
}

if ($httpCode == 200 && $response) {
    $responseData = json_decode($response, true);

    if (isset($responseData[0]['BillCode'])) {
        $billCode = $responseData[0]['BillCode'];

        // update DB dengan billCode
        $updateQuery = "UPDATE payment_transactions SET billCode = ? WHERE paymentTransactionID = ?";
        $stmt = $conn->prepare($updateQuery);
        $stmt->bind_param("si", $billCode, $paymentTransactionID);
        $stmt->execute();
        $stmt->close();

        // redirect ke ToyyibPay payment page (Sandbox)
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
