<?php
/**
 * ML API Configuration for HGS
 * 
 * This file contains configuration settings and helper functions
 * to integrate with the Python ML API
 */

// ML API Configuration
define('ML_API_BASE_URL', 'http://127.0.0.1:5000/api');
define('ML_API_TIMEOUT', 60); // seconds (increased for batch processing)

/**
 * Call ML API endpoint
 * 
 * @param string $endpoint API endpoint (e.g., 'analyze-sentiment')
 * @param array $data POST data
 * @return array Response data or error
 */
function callMLAPI($endpoint, $data = []) {
    $url = ML_API_BASE_URL . '/' . $endpoint;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, ML_API_TIMEOUT);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("ML API Error: $error");
        return [
            'success' => false,
            'error' => 'API connection failed',
            'message' => $error
        ];
    }
    
    if ($httpCode !== 200) {
        error_log("ML API HTTP Error: $httpCode");
        return [
            'success' => false,
            'error' => 'API returned error',
            'http_code' => $httpCode
        ];
    }
    
    $result = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("ML API JSON Error: " . json_last_error_msg());
        return [
            'success' => false,
            'error' => 'Invalid JSON response'
        ];
    }
    
    return $result;
}

/**
 * Analyze sentiment of a single review
 * 
 * @param string $reviewText Review comment
 * @return array Sentiment analysis result
 */
function analyzeSentiment($reviewText) {
    return callMLAPI('analyze-sentiment', [
        'text' => $reviewText
    ]);
}

/**
 * Analyze all reviews for a guider (batch)
 * 
 * @param array $reviews Array of reviews [['reviewID' => 1, 'comment' => '...']]
 * @return array Aggregated sentiment analysis
 */
function analyzeGuiderReviews($reviews) {
    return callMLAPI('analyze-guider-reviews', [
        'reviews' => $reviews
    ]);
}

/**
 * Check if ML API is available
 * 
 * @return bool True if API is healthy
 */
function checkMLAPIHealth() {
    $ch = curl_init(ML_API_BASE_URL . '/health');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode === 200;
}

/**
 * Get sentiment badge HTML
 * 
 * @param string $sentiment 'positive', 'negative', or 'neutral'
 * @param int $score Score 0-100
 * @return string HTML badge
 */
function getSentimentBadge($sentiment, $score) {
    $badges = [
        'positive' => [
            'icon' => '😊',
            'gradient' => 'linear-gradient(135deg, #10b981 0%, #059669 100%)',
            'shadow' => '0 2px 8px rgba(16, 185, 129, 0.3)',
            'label' => 'Positive'
        ],
        'negative' => [
            'icon' => '😞',
            'gradient' => 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)',
            'shadow' => '0 2px 8px rgba(239, 68, 68, 0.3)',
            'label' => 'Negative'
        ],
        'neutral' => [
            'icon' => '😐',
            'gradient' => 'linear-gradient(135deg, #6b7280 0%, #4b5563 100%)',
            'shadow' => '0 2px 8px rgba(107, 114, 128, 0.3)',
            'label' => 'Neutral'
        ]
    ];
    
    $badge = $badges[$sentiment] ?? $badges['neutral'];
    
    return '<span style="background: ' . $badge['gradient'] . '; color: white; padding: 6px 14px; border-radius: 20px; font-size: 0.8rem; font-weight: 700; box-shadow: ' . $badge['shadow'] . '; display: inline-flex; align-items: center; gap: 4px;">
            <span style="font-size: 1.1rem;">' . $badge['icon'] . '</span> ' . $badge['label'] . ' <span style="opacity: 0.9;">(' . $score . '%)</span>
        </span>';
}

/**
 * Get emotion badge HTML
 * 
 * @param string $emotion Emotion name
 * @return string HTML badge
 */
function getEmotionBadge($emotion) {
    $emotions = [
        'happy' => ['icon' => '😄', 'color' => '#10b981', 'label' => 'Happy'],
        'satisfied' => ['icon' => '😊', 'color' => '#3b82f6', 'label' => 'Satisfied'],
        'neutral' => ['icon' => '😐', 'color' => '#6b7280', 'label' => 'Neutral'],
        'disappointed' => ['icon' => '😔', 'color' => '#f59e0b', 'label' => 'Disappointed'],
        'angry' => ['icon' => '😠', 'color' => '#ef4444', 'label' => 'Angry']
    ];
    
    $emotionData = $emotions[$emotion] ?? $emotions['neutral'];
    
    return '<span style="background: ' . $emotionData['color'] . '20; color: ' . $emotionData['color'] . '; padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; border: 1px solid ' . $emotionData['color'] . '40;" title="' . $emotionData['label'] . '">
            <span style="font-size: 1rem;">' . $emotionData['icon'] . '</span> ' . $emotionData['label'] . '
        </span>';
}

/**
 * Format themes as badges
 * 
 * @param array $themes Array of theme strings
 * @return string HTML badges
 */
function getThemeBadges($themes) {
    $html = '';
    foreach ($themes as $theme) {
        $themeName = ucfirst($theme);
        $html .= '<span style="background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); color: white; padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; margin-right: 6px; margin-bottom: 4px; display: inline-block; box-shadow: 0 2px 6px rgba(30, 64, 175, 0.25);">' . $themeName . '</span>';
    }
    return $html;
}
?>

