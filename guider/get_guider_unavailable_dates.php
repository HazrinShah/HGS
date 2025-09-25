<?php
header('Content-Type: application/json');

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "hgs";

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    // Get guiderID from request
    $guiderID = $_GET['guiderID'] ?? null;
    
    if (!$guiderID) {
        throw new Exception("Guider ID is required");
    }
    
    // Get unavailable dates for this specific guider
    $query = "SELECT DISTINCT offDate FROM schedule WHERE guiderID = ? AND offDate IS NOT NULL ORDER BY offDate";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $guiderID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $unavailableDates = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $unavailableDates[] = $row['offDate'];
        }
    }
    
    // Also add past dates as unavailable
    $today = date('Y-m-d');
    $pastDates = [];
    
    // Add dates from 1 year ago to yesterday as unavailable
    $startDate = date('Y-m-d', strtotime('-1 year'));
    $currentDate = $startDate;
    
    while ($currentDate < $today) {
        $pastDates[] = $currentDate;
        $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
    }
    
    // Combine guider unavailable dates and past dates
    $allUnavailableDates = array_unique(array_merge($unavailableDates, $pastDates));
    sort($allUnavailableDates);
    
    echo json_encode([
        'success' => true,
        'dates' => $allUnavailableDates,
        'count' => count($allUnavailableDates),
        'guiderID' => $guiderID,
        'message' => 'Guider-specific availability loaded'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'dates' => []
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
