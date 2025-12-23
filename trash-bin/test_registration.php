<?php
// Test registration script to debug issues
include '../shared/db_connection.php';
include '../shared/email_validation.php';

echo "<h2>Registration Debug Test</h2>";

// Test database connection
echo "<h3>1. Database Connection Test</h3>";
if ($conn->connect_error) {
    echo "❌ Database connection failed: " . $conn->connect_error;
} else {
    echo "✅ Database connection successful";
}

// Test email validation
echo "<h3>2. Email Validation Test</h3>";
$testEmails = [
    'test@gmail.com',
    'user@yahoo.com',
    'invalid-email',
    'test@10minutemail.com'
];

foreach ($testEmails as $email) {
    $result = validateEmailForRegistration($email);
    $status = $result['success'] ? '✅' : '❌';
    echo "$status $email: " . $result['error'] . "<br>";
}

// Test database table structure
echo "<h3>3. Database Table Check</h3>";
$tables = ['hiker', 'payment_methods'];
foreach ($tables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result->num_rows > 0) {
        echo "✅ Table '$table' exists<br>";
        
        // Show table structure
        $structure = $conn->query("DESCRIBE $table");
        echo "<strong>Structure of $table:</strong><br>";
        while ($row = $structure->fetch_assoc()) {
            echo "- " . $row['Field'] . " (" . $row['Type'] . ")<br>";
        }
    } else {
        echo "❌ Table '$table' does not exist<br>";
    }
}

// Test sample insert
echo "<h3>4. Sample Insert Test</h3>";
$testQuery = "INSERT INTO hiker (username, email, password, gender, phone_number) 
              VALUES ('testuser', 'test@example.com', 'testpass', 'Male', 1234567890)";
              
if ($conn->query($testQuery) === TRUE) {
    echo "✅ Sample insert successful<br>";
    
    // Get the inserted ID
    $hikerID = $conn->insert_id;
    echo "Inserted hiker ID: $hikerID<br>";
    
    // Test FPX payment method insert
    $fpxQuery = "INSERT INTO payment_methods (hikerID, methodType, cardName, cardNumber, expiryDate, createdAt) 
                 VALUES ($hikerID, 'FPX', '', '', '', NOW())";
    if ($conn->query($fpxQuery) === TRUE) {
        echo "✅ FPX payment method insert successful<br>";
    } else {
        echo "❌ FPX payment method insert failed: " . $conn->error . "<br>";
    }
    
    // Clean up test data
    $conn->query("DELETE FROM payment_methods WHERE hikerID = $hikerID");
    $conn->query("DELETE FROM hiker WHERE hikerID = $hikerID");
    echo "🧹 Test data cleaned up<br>";
} else {
    echo "❌ Sample insert failed: " . $conn->error . "<br>";
}

$conn->close();
?>
