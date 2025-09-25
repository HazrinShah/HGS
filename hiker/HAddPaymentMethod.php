<?php
include '../shared/db_connection.php';

/**
 * Validates a credit card number using the Luhn algorithm.
 * @param string $number The credit card number to validate.
 * @return bool True if the number is valid, false otherwise.
 */
function isValidLuhn(string $number): bool {
    $number = preg_replace('/\D/', '', $number);
    $sum = 0;
    $numDigits = strlen($number);
    $parity = $numDigits % 2;

    for ($i = 0; $i < $numDigits; $i++) {
        $digit = $number[$i];
        if ($i % 2 === $parity) {
            $digit *= 2;
            if ($digit > 9) {
                $digit -= 9;
            }
        }
        $sum += $digit;
    }

    return ($sum % 10) === 0;
}

/**
 * Detects the card type from a card number.
 * @param string $number The card number.
 * @return string The card type or 'Unknown'.
 */
function getCardType(string $number): string {
    $number = preg_replace('/\D/', '', $number);
    if (preg_match('/^4[0-9]{12}(?:[0-9]{3})?$/', $number)) { return 'Visa'; }
    if (preg_match('/^5[1-5][0-9]{14}$/', $number)) { return 'Mastercard'; }
    if (preg_match('/^3[47][0-9]{13}$/', $number)) { return 'American Express'; }
    return 'Unknown';
}

session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['hikerID'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in.']);
    exit();
}

$hikerID = $_SESSION['hikerID'];
$data = json_decode(file_get_contents('php://input'), true);

$methodType = $data['methodType'] ?? null;

if (!$methodType) {
    echo json_encode(['success' => false, 'message' => 'Payment method type is required.']);
    exit();
}

$cardName = null;
$cardNumber = null;
$expiryDate = null;

if ($methodType === 'Debit Card') {
    $cardName = $data['cardName'] ?? null;
    $rawCardNumber = $data['cardNumber'] ?? '';
    $expiryDate = $data['expiryDate'] ?? null;

    // Validate required fields for debit card
    if (empty($cardName) || empty($rawCardNumber) || empty($expiryDate)) {
        echo json_encode(['success' => false, 'message' => 'Please fill in all required card details.']);
        exit();
    }

    if (!isValidLuhn($rawCardNumber)) {
        echo json_encode(['success' => false, 'message' => 'The card number entered is not valid.']);
        exit();
    }
    $cardType = getCardType($rawCardNumber);
    $cardNumber = $cardType . ' **** ' . substr($rawCardNumber, -4);
}

$stmt = $conn->prepare("INSERT INTO payment_methods (hikerID, methodType, cardName, cardNumber, expiryDate) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("issss", $hikerID, $methodType, $cardName, $cardNumber, $expiryDate);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Payment method added successfully!']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to add payment method.']);
}

$stmt->close();
$conn->close();
?>