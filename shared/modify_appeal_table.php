<?php
// Script to modify appeal table to support both hiker and guider appeals
require_once 'db_connection.php';

echo "<h2>Modifying Appeal Table Structure</h2>";

try {
    // First, let's check the current structure
    echo "<h3>Current appeal table structure:</h3>";
    $checkQuery = "DESCRIBE appeal";
    $result = $conn->query($checkQuery);
    
    if ($result && $result->num_rows > 0) {
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
        
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['Field'] . "</td>";
            echo "<td>" . $row['Type'] . "</td>";
            echo "<td>" . $row['Null'] . "</td>";
            echo "<td>" . $row['Key'] . "</td>";
            echo "<td>" . $row['Default'] . "</td>";
            echo "<td>" . $row['Extra'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Check if guiderID column already exists
    $checkGuiderID = "SHOW COLUMNS FROM appeal LIKE 'guiderID'";
    $guiderIDResult = $conn->query($checkGuiderID);
    
    if ($guiderIDResult && $guiderIDResult->num_rows > 0) {
        echo "<p style='color: blue;'>ℹ️ guiderID column already exists in appeal table.</p>";
    } else {
        echo "<h3>Adding guiderID column to appeal table...</h3>";
        
        // Add guiderID column
        $addGuiderIDQuery = "ALTER TABLE appeal ADD COLUMN guiderID INT NULL AFTER hikerID";
        
        if ($conn->query($addGuiderIDQuery)) {
            echo "<p style='color: green;'>✅ Successfully added guiderID column to appeal table.</p>";
            
            // Add foreign key constraint for guiderID
            $addForeignKeyQuery = "ALTER TABLE appeal ADD CONSTRAINT fk_appeal_guider FOREIGN KEY (guiderID) REFERENCES guider(guiderID) ON DELETE CASCADE";
            
            if ($conn->query($addForeignKeyQuery)) {
                echo "<p style='color: green;'>✅ Successfully added foreign key constraint for guiderID.</p>";
            } else {
                echo "<p style='color: orange;'>⚠️ Could not add foreign key constraint: " . $conn->error . "</p>";
            }
            
        } else {
            echo "<p style='color: red;'>❌ Failed to add guiderID column: " . $conn->error . "</p>";
        }
    }
    
    // Show updated structure
    echo "<h3>Updated appeal table structure:</h3>";
    $updatedResult = $conn->query($checkQuery);
    
    if ($updatedResult && $updatedResult->num_rows > 0) {
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
        
        while ($row = $updatedResult->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['Field'] . "</td>";
            echo "<td>" . $row['Type'] . "</td>";
            echo "<td>" . $row['Null'] . "</td>";
            echo "<td>" . $row['Key'] . "</td>";
            echo "<td>" . $row['Default'] . "</td>";
            echo "<td>" . $row['Extra'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    echo "<h3>Appeal Table Usage:</h3>";
    echo "<ul>";
    echo "<li><strong>Hiker Appeals:</strong> hikerID is set, guiderID remains NULL</li>";
    echo "<li><strong>Guider Appeals:</strong> guiderID is set, hikerID remains NULL</li>";
    echo "<li><strong>Both columns:</strong> Can be NULL, but at least one must be set</li>";
    echo "</ul>";
    
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
