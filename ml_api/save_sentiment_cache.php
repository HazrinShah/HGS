<?php
/**
 * Save Sentiment Analysis Results to Cache
 * 
 * This function saves sentiment analysis results to database for fast loading
 */

function saveSentimentCache($guiderID, $sentimentData, $conn) {
    // Check if cache table exists
    $checkTable = $conn->query("SHOW TABLES LIKE 'sentiment_cache'");
    if (!$checkTable || $checkTable->num_rows == 0) {
        error_log("Sentiment cache table does not exist. Run sentiment_cache_table.sql");
        return false;
    }
    
    if (!$sentimentData || !isset($sentimentData['sentiment_breakdown'])) {
        return false;
    }
    
    $breakdown = $sentimentData['sentiment_breakdown'];
    $topThemes = isset($sentimentData['top_themes']) ? json_encode($sentimentData['top_themes']) : '[]';
    $totalAnalyzed = isset($sentimentData['analyzed_reviews']) ? $sentimentData['analyzed_reviews'] : 0;
    
    // Insert or update cache
    $sql = "INSERT INTO sentiment_cache 
            (guiderID, positive_count, negative_count, neutral_count, positive_percentage, top_themes, total_reviews_analyzed)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            positive_count = VALUES(positive_count),
            negative_count = VALUES(negative_count),
            neutral_count = VALUES(neutral_count),
            positive_percentage = VALUES(positive_percentage),
            top_themes = VALUES(top_themes),
            total_reviews_analyzed = VALUES(total_reviews_analyzed),
            last_updated = CURRENT_TIMESTAMP";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Failed to prepare cache insert: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("iiiddsi", 
        $guiderID,
        $breakdown['positive'],
        $breakdown['negative'],
        $breakdown['neutral'],
        $breakdown['positive_percentage'],
        $topThemes,
        $totalAnalyzed
    );
    
    $result = $stmt->execute();
    
    if ($result) {
        error_log("Sentiment cache saved for guider ID: $guiderID");
    } else {
        error_log("Failed to save sentiment cache: " . $stmt->error);
    }
    
    return $result;
}
?>

