<?php
header('Content-Type: application/json');

try {
    // Only block past dates - all future dates are available
    $today = date('Y-m-d');
    $pastDates = [];
    
    // Add dates from 1 year ago to yesterday as unavailable
    $startDate = date('Y-m-d', strtotime('-1 year'));
    $currentDate = $startDate;
    
    while ($currentDate < $today) {
        $pastDates[] = $currentDate;
        $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
    }
    
    sort($pastDates);
    
    echo json_encode([
        'success' => true,
        'dates' => $pastDates,
        'count' => count($pastDates),
        'message' => 'Only past dates are blocked. All future dates are available.'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'dates' => []
    ]);
}
?>
