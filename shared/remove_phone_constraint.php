<?php
// Script to remove unique constraint from phone_number field in hiker table
require_once 'db_connection.php';

echo "<h2>Removing Phone Number Unique Constraint</h2>";

try {
    // First, let's check the current constraints
    echo "<h3>Current constraints on phone_number:</h3>";
    $checkQuery = "SHOW INDEX FROM hiker WHERE Column_name = 'phone_number'";
    $result = $conn->query($checkQuery);
    
    if ($result && $result->num_rows > 0) {
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>Table</th><th>Non_unique</th><th>Key_name</th><th>Seq_in_index</th><th>Column_name</th><th>Index_type</th></tr>";
        
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['Table'] . "</td>";
            echo "<td>" . $row['Non_unique'] . "</td>";
            echo "<td>" . $row['Key_name'] . "</td>";
            echo "<td>" . $row['Seq_in_index'] . "</td>";
            echo "<td>" . $row['Column_name'] . "</td>";
            echo "<td>" . $row['Index_type'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Try to remove the unique constraint
        echo "<h3>Attempting to remove unique constraint...</h3>";
        
        // Try different approaches to remove the constraint
        $dropQueries = [
            "ALTER TABLE hiker DROP INDEX phone_number",
            "ALTER TABLE hiker DROP INDEX `phone_number`",
            "ALTER TABLE hiker DROP KEY phone_number",
            "ALTER TABLE hiker DROP KEY `phone_number`"
        ];
        
        $success = false;
        foreach ($dropQueries as $query) {
            echo "<p>Trying: <code>$query</code></p>";
            if ($conn->query($query)) {
                echo "<p style='color: green;'>✅ Success! Constraint removed.</p>";
                $success = true;
                break;
            } else {
                echo "<p style='color: orange;'>⚠️ Failed: " . $conn->error . "</p>";
            }
        }
        
        if (!$success) {
            echo "<p style='color: red;'>❌ Could not remove constraint automatically. You may need to do this manually in phpMyAdmin.</p>";
        }
        
    } else {
        echo "<p style='color: blue;'>ℹ️ No unique constraints found on phone_number field.</p>";
    }
    
    // Verify the constraint is removed
    echo "<h3>Verifying constraint removal:</h3>";
    $verifyResult = $conn->query($checkQuery);
    
    if ($verifyResult && $verifyResult->num_rows > 0) {
        echo "<p style='color: orange;'>⚠️ Constraints still exist:</p>";
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>Table</th><th>Non_unique</th><th>Key_name</th><th>Seq_in_index</th><th>Column_name</th><th>Index_type</th></tr>";
        
        while ($row = $verifyResult->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['Table'] . "</td>";
            echo "<td>" . $row['Non_unique'] . "</td>";
            echo "<td>" . $row['Key_name'] . "</td>";
            echo "<td>" . $row['Seq_in_index'] . "</td>";
            echo "<td>" . $row['Column_name'] . "</td>";
            echo "<td>" . $row['Index_type'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: green;'>✅ No unique constraints found on phone_number field. Duplicate phone numbers are now allowed!</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}

$conn->close();
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
table { border-collapse: collapse; margin: 10px 0; }
th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
th { background-color: #f2f2f2; }
code { background-color: #f4f4f4; padding: 2px 4px; border-radius: 3px; }
</style>
