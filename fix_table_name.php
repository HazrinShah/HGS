<?php
// ONE-TIME SCRIPT: Fix table names for case-sensitivity on Linux
// DELETE THIS FILE AFTER RUNNING

error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'shared/db_connection.php';

echo "<h2>Fix Table Name Case Sensitivity</h2>";

// Tables to fix: oldName => newName (lowercase)
$tablesToFix = [
    'bookingParticipant' => 'bookingparticipant',
    'bookingHikerDetails' => 'bookinghikerdetails'
];

foreach ($tablesToFix as $oldName => $newName) {
    echo "<h3>Checking: $oldName → $newName</h3>";
    
    // Check if old table exists
    $result = $conn->query("SHOW TABLES LIKE '$oldName'");
    $oldTableExists = $result->num_rows > 0;
    
    $result2 = $conn->query("SHOW TABLES LIKE '$newName'");
    $newTableExists = $result2->num_rows > 0;
    
    echo "<p>Old table '$oldName' exists: " . ($oldTableExists ? 'YES' : 'NO') . "</p>";
    echo "<p>New table '$newName' exists: " . ($newTableExists ? 'YES' : 'NO') . "</p>";
    
    if ($oldTableExists && !$newTableExists) {
        // Rename the table
        if ($conn->query("RENAME TABLE `$oldName` TO `$newName`")) {
            echo "<p style='color:green;'>✅ Successfully renamed table to '$newName'</p>";
        } else {
            echo "<p style='color:red;'>❌ Failed to rename: " . $conn->error . "</p>";
        }
    } elseif (!$oldTableExists && !$newTableExists) {
        echo "<p style='color:orange;'>⚠️ Neither table exists. It will be created when needed.</p>";
    } elseif ($oldTableExists && $newTableExists) {
        echo "<p style='color:orange;'>⚠️ Both tables exist!</p>";
        
        // Show row counts
        $r1 = $conn->query("SELECT COUNT(*) as cnt FROM `$oldName`");
        $r2 = $conn->query("SELECT COUNT(*) as cnt FROM `$newName`");
        $count1 = $r1->fetch_assoc()['cnt'];
        $count2 = $r2->fetch_assoc()['cnt'];
        echo "<p>$oldName rows: $count1</p>";
        echo "<p>$newName rows: $count2</p>";
        
        if ($count1 > 0 && $count2 == 0) {
            // Copy data from old to new, then drop old
            echo "<p>Copying data from $oldName to $newName...</p>";
            if ($conn->query("INSERT INTO `$newName` SELECT * FROM `$oldName`")) {
                echo "<p style='color:green;'>✅ Data copied successfully</p>";
                if ($conn->query("DROP TABLE `$oldName`")) {
                    echo "<p style='color:green;'>✅ Old table dropped</p>";
                }
            } else {
                echo "<p style='color:red;'>❌ Failed to copy: " . $conn->error . "</p>";
            }
        } elseif ($count1 == 0) {
            // Just drop the old empty table
            if ($conn->query("DROP TABLE `$oldName`")) {
                echo "<p style='color:green;'>✅ Dropped empty old table</p>";
            }
        }
    } else {
        echo "<p style='color:green;'>✅ Table '$newName' already exists correctly.</p>";
    }
}

// Show current tables
echo "<h3>Current Tables:</h3>";
$result = $conn->query("SHOW TABLES");
echo "<ul>";
while ($row = $result->fetch_array()) {
    echo "<li>" . $row[0] . "</li>";
}
echo "</ul>";

echo "<p><strong style='color:red;'>DELETE THIS FILE AFTER USE!</strong></p>";
?>
