<?php
// Test script to debug ToyyibPay status parameters
echo "<h2>ToyyibPay Status Debug Test</h2>";
echo "<p>This page will show you exactly what parameters ToyyibPay sends back.</p>";

echo "<h3>GET Parameters Received:</h3>";
echo "<table border='1' cellpadding='5' cellspacing='0'>";
echo "<tr><th>Parameter</th><th>Value</th><th>Type</th></tr>";

foreach ($_GET as $key => $value) {
    echo "<tr>";
    echo "<td><strong>$key</strong></td>";
    echo "<td>'$value'</td>";
    echo "<td>" . gettype($value) . "</td>";
    echo "</tr>";
}

echo "</table>";

echo "<h3>Raw Query String:</h3>";
echo "<p>" . $_SERVER['QUERY_STRING'] . "</p>";

echo "<h3>Status Analysis:</h3>";
$status = $_GET['status'] ?? 'NOT_SET';
echo "<p>Status value: <strong>'$status'</strong></p>";
echo "<p>Status type: <strong>" . gettype($status) . "</strong></p>";

if ($status == '1' || $status == 'success') {
    echo "<p style='color: green;'><strong>✅ This would be treated as SUCCESS</strong></p>";
} elseif ($status == '2' || $status == 'failed' || $status == '0' || $status == 'cancel' || $status == 'cancelled') {
    echo "<p style='color: red;'><strong>❌ This would be treated as FAILURE</strong></p>";
} else {
    echo "<p style='color: orange;'><strong>⚠️ This would be treated as UNKNOWN</strong></p>";
}

echo "<h3>Test Links:</h3>";
echo "<p><a href='?status=1&billcode=TEST123&order_id=HGS_123_456'>Test Success (status=1)</a></p>";
echo "<p><a href='?status=2&billcode=TEST123&order_id=HGS_123_456'>Test Failure (status=2)</a></p>";
echo "<p><a href='?status=0&billcode=TEST123&order_id=HGS_123_456'>Test Cancel (status=0)</a></p>";
echo "<p><a href='?status=failed&billcode=TEST123&order_id=HGS_123_456'>Test Failed (status=failed)</a></p>";
echo "<p><a href='?status=cancel&billcode=TEST123&order_id=HGS_123_456'>Test Cancel (status=cancel)</a></p>";

echo "<hr>";
echo "<p><a href='payment_success.php?debug=1&status=1&billcode=TEST123'>Test Payment Success Page with Debug</a></p>";
echo "<p><a href='payment_success.php?debug=1&status=2&billcode=TEST123'>Test Payment Success Page with Debug (Failure)</a></p>";
?>
