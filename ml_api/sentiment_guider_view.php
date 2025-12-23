<?php
/**
 * Sentiment Analysis View for Guiders
 * 
 * This page shows sentiment analysis results for a guider's reviews
 * To integrate: Include this in guider/GPerformance.php
 */

// Include ML API config
require_once __DIR__ . '/config.php';

/**
 * Display sentiment analysis for a guider
 * 
 * @param int $guiderID Guider ID
 * @param mysqli $con Database connection
 */
function displayGuiderSentimentAnalysis($guiderID, $con) {
    // Check if ML API is available
    if (!checkMLAPIHealth()) {
        echo '<div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 16px; margin: 20px 0; border-radius: 4px;">';
        echo '<strong>⚠️ ML Service Offline</strong><br>';
        echo 'Sentiment analysis is temporarily unavailable. Showing standard reviews only.';
        echo '</div>';
        return;
    }
    
    // Fetch all reviews with comments for this guider
    $sql = "SELECT r.reviewID, r.rating, r.comment, r.createdAt, 
                   h.username as hikerName, m.name as mountainName
            FROM review r
            JOIN booking b ON r.bookingID = b.bookingID
            JOIN hiker h ON b.hikerID = h.hikerID
            JOIN mountain m ON b.mountainID = m.mountainID
            WHERE b.guiderID = ?
            AND r.comment IS NOT NULL
            AND r.comment != ''
            ORDER BY r.createdAt DESC";
    
    $stmt = $con->prepare($sql);
    $stmt->bind_param("i", $guiderID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $reviews = [];
    while ($row = $result->fetch_assoc()) {
        $reviews[] = $row;
    }
    
    if (empty($reviews)) {
        echo '<div style="padding: 40px; text-align: center; color: #6b7280;">';
        echo '<p style="font-size: 16px;">📝 No reviews with comments yet.</p>';
        echo '<p style="font-size: 14px;">Reviews will appear here once hikers leave feedback.</p>';
        echo '</div>';
        return;
    }
    
    // Prepare reviews for batch analysis
    $reviewsForAnalysis = [];
    foreach ($reviews as $review) {
        $reviewsForAnalysis[] = [
            'reviewID' => $review['reviewID'],
            'comment' => $review['comment']
        ];
    }
    
    // Call ML API for batch analysis
    $analysisResult = analyzeGuiderReviews($reviewsForAnalysis);
    
    if (!$analysisResult['success']) {
        echo '<div style="background: #fee2e2; border-left: 4px solid #ef4444; padding: 16px; margin: 20px 0; border-radius: 4px;">';
        echo '<strong>❌ Analysis Error</strong><br>';
        echo 'Could not analyze reviews. Please try again later.';
        echo '</div>';
        return;
    }
    
    // SAVE TO CACHE for ultra-fast admin page loading!
    require_once(__DIR__ . '/save_sentiment_cache.php');
    saveSentimentCache($guiderID, $analysisResult, $con);
    
    $sentimentData = $analysisResult['sentiment_breakdown'];
    $topThemes = $analysisResult['top_themes'];
    $emotions = $analysisResult['emotion_distribution'];
    $analyzedReviews = $analysisResult['reviews'];
    
    // Create a map of reviewID to analysis
    $analysisMap = [];
    foreach ($analyzedReviews as $analysis) {
        $analysisMap[$analysis['reviewID']] = $analysis;
    }
    
    ?>
    
    <style>
        /* Sentiment Dashboard - Matching GPerformance Style */
        .sentiment-dashboard {
            background: #ffffff;
            border-radius: 20px;
            padding: 2rem;
            margin: 0;
            box-shadow: 0 10px 30px rgba(30, 64, 175, 0.1);
            border: 1px solid #dbeafe;
            transition: all 0.3s ease;
        }
        
        .sentiment-dashboard:hover {
            box-shadow: 0 20px 40px rgba(30, 64, 175, 0.15);
        }
        
        .sentiment-header {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e3a8a;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #dbeafe;
        }
        
        .sentiment-header i {
            color: #1e40af;
        }
        
        .sentiment-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .sentiment-card {
            background: linear-gradient(135deg, #dbeafe 0%, #f8fafc 100%);
            padding: 1.5rem;
            border-radius: 15px;
            border: 1px solid #dbeafe;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .sentiment-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: #1e40af;
        }
        
        .sentiment-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(30, 64, 175, 0.15);
        }
        
        .sentiment-card-title {
            font-size: 0.75rem;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.5px;
            margin-bottom: 0.75rem;
        }
        
        .sentiment-card-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: #1e40af;
            line-height: 1;
        }
        
        .sentiment-card-subtitle {
            font-size: 0.875rem;
            color: #64748b;
            margin-top: 0.5rem;
            font-weight: 500;
        }
        
        /* Themes Section */
        .themes-section {
            margin: 2rem 0;
            padding: 1.5rem;
            background: #f8fafc;
            border-radius: 15px;
            border: 1px solid #dbeafe;
        }
        
        .themes-section h3 {
            font-size: 1.125rem;
            font-weight: 700;
            color: #1e3a8a;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .theme-item {
            display: flex;
            align-items: center;
            padding: 0.875rem 1rem;
            background: #ffffff;
            border-radius: 12px;
            margin-bottom: 0.75rem;
            border: 1px solid #dbeafe;
            transition: all 0.2s ease;
        }
        
        .theme-item:hover {
            transform: translateX(5px);
            border-color: #3b82f6;
            box-shadow: 0 4px 12px rgba(30, 64, 175, 0.1);
        }
        
        .theme-name {
            flex: 1;
            font-weight: 600;
            color: #1e3a8a;
            font-size: 0.95rem;
        }
        
        .theme-count {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            color: white;
            padding: 0.375rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
            margin-right: 0.75rem;
            box-shadow: 0 2px 8px rgba(30, 64, 175, 0.3);
        }
        
        .theme-sentiment-positive {
            color: #10b981;
            font-size: 1.25rem;
            font-weight: bold;
        }
        
        .theme-sentiment-negative {
            color: #ef4444;
            font-size: 1.25rem;
            font-weight: bold;
        }
        
        /* Reviews List */
        .reviews-list {
            margin-top: 2rem;
        }
        
        .reviews-list > h3 {
            font-size: 1.125rem;
            font-weight: 700;
            color: #1e3a8a;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .sentiment-review-item {
            border: 1px solid #dbeafe;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1.25rem;
            background: #ffffff;
            transition: all 0.3s ease;
            border-left: 4px solid #1e40af;
        }
        
        .sentiment-review-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(30, 64, 175, 0.12);
        }
        
        .review-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 1rem;
        }
        
        .review-meta {
            flex: 1;
        }
        
        .review-hiker {
            font-weight: 700;
            color: #1e3a8a;
            margin-bottom: 0.375rem;
            font-size: 1rem;
        }
        
        .review-date {
            font-size: 0.8125rem;
            color: #64748b;
            font-weight: 500;
        }
        
        .review-comment {
            color: #475569;
            line-height: 1.7;
            margin: 1rem 0;
            padding: 1rem;
            background: #dbeafe;
            border-radius: 12px;
            font-style: italic;
            border-left: 3px solid #3b82f6;
        }
        
        .review-analysis {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 2px solid #dbeafe;
        }
        
        .analysis-row {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            font-size: 0.875rem;
        }
        
        .analysis-row strong {
            color: #1e3a8a;
        }
        
        /* Badge Container */
        .badge-container {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
        }
        
        /* Loading State */
        .sentiment-loading {
            text-align: center;
            padding: 3rem;
            color: #64748b;
        }
        
        .sentiment-loading i {
            font-size: 3rem;
            color: #1e40af;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .sentiment-summary {
                grid-template-columns: 1fr;
            }
            
            .sentiment-card-value {
                font-size: 2rem;
            }
            
            .review-header {
                flex-direction: column;
                gap: 0.75rem;
            }
            
            .badge-container {
                width: 100%;
            }
        }
    </style>
    
    <div class="sentiment-dashboard">
        <div class="sentiment-header">
            <i class="fas fa-brain"></i> AI Sentiment Analysis
        </div>
        
        <!-- Summary Cards -->
        <div class="sentiment-summary">
            <div class="sentiment-card">
                <div class="sentiment-card-title">Overall Sentiment</div>
                <div class="sentiment-card-value" style="color: #10b981;">
                    <?php echo $sentimentData['positive_percentage']; ?>%
                </div>
                <div class="sentiment-card-subtitle">
                    <?php echo $sentimentData['positive']; ?> positive reviews
                </div>
            </div>
            
            <div class="sentiment-card">
                <div class="sentiment-card-title">Total Analyzed</div>
                <div class="sentiment-card-value">
                    <?php echo $analysisResult['analyzed_reviews']; ?>
                </div>
                <div class="sentiment-card-subtitle">
                    reviews with comments
                </div>
            </div>
            
            <div class="sentiment-card">
                <div class="sentiment-card-title">Sentiment Breakdown</div>
                <div style="margin-top: 0.75rem; display: flex; flex-direction: column; gap: 0.5rem;">
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <span style="color: #10b981; font-size: 1.25rem;">😊</span>
                        <span style="color: #10b981; font-weight: 700; font-size: 1rem;">
                            Positive: <?php echo $sentimentData['positive']; ?>
                        </span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <span style="color: #64748b; font-size: 1.25rem;">😐</span>
                        <span style="color: #64748b; font-weight: 700; font-size: 1rem;">
                            Neutral: <?php echo $sentimentData['neutral']; ?>
                        </span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <span style="color: #ef4444; font-size: 1.25rem;">😞</span>
                        <span style="color: #ef4444; font-weight: 700; font-size: 1rem;">
                            Negative: <?php echo $sentimentData['negative']; ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <div class="sentiment-card">
                <div class="sentiment-card-title">Dominant Emotion</div>
                <div class="sentiment-card-value" style="font-size: 48px;">
                    <?php 
                    $maxEmotion = array_keys($emotions, max($emotions))[0];
                    $emotionIcons = [
                        'happy' => '😄',
                        'satisfied' => '😊',
                        'neutral' => '😐',
                        'disappointed' => '😔',
                        'angry' => '😠'
                    ];
                    echo $emotionIcons[$maxEmotion];
                    ?>
                </div>
                <div class="sentiment-card-subtitle">
                    <?php echo ucfirst($maxEmotion); ?> (<?php echo $emotions[$maxEmotion]; ?> reviews)
                </div>
            </div>
        </div>
        
        <!-- Top Themes -->
        <?php if (!empty($topThemes)): ?>
        <div class="themes-section">
            <h3>
                <i class="fas fa-trophy"></i> Top Themes Mentioned
            </h3>
            <?php foreach (array_slice($topThemes, 0, 5) as $theme): ?>
                <div class="theme-item">
                    <span class="theme-name">
                        <?php echo ucfirst($theme['theme']); ?>
                    </span>
                    <span class="theme-count">
                        <?php echo $theme['count']; ?> mentions
                    </span>
                    <span class="<?php echo $theme['sentiment'] === 'positive' ? 'theme-sentiment-positive' : 'theme-sentiment-negative'; ?>">
                        <?php echo $theme['sentiment'] === 'positive' ? '✓' : '✗'; ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <!-- Individual Reviews -->
        <div class="reviews-list">
            <h3>
                <i class="fas fa-clipboard-list"></i> Individual Reviews Analysis
            </h3>
            
            <?php foreach ($reviews as $review): ?>
                <?php 
                $analysis = $analysisMap[$review['reviewID']] ?? null;
                if (!$analysis) continue;
                ?>
                
                <div class="sentiment-review-item">
                    <div class="review-header">
                        <div class="review-meta">
                            <div class="review-hiker">
                                <i class="fas fa-user-circle" style="color: #3b82f6; margin-right: 0.5rem;"></i>
                                <?php echo htmlspecialchars($review['hikerName']); ?>
                            </div>
                            <div class="review-date">
                                <i class="far fa-calendar" style="color: #64748b; margin-right: 0.25rem;"></i>
                                <?php echo date('d M Y', strtotime($review['createdAt'])); ?> • 
                                <i class="fas fa-mountain" style="color: #64748b; margin-left: 0.5rem; margin-right: 0.25rem;"></i>
                                <?php echo htmlspecialchars($review['mountainName']); ?> •
                                <i class="fas fa-star" style="color: #fbbf24; margin-left: 0.5rem; margin-right: 0.25rem;"></i>
                                <?php echo $review['rating']; ?>/5
                            </div>
                        </div>
                        <div class="badge-container">
                            <?php echo getSentimentBadge($analysis['sentiment'], $analysis['score']); ?>
                            <?php echo getEmotionBadge($analysis['emotion']); ?>
                        </div>
                    </div>
                    
                    <div class="review-comment">
                        "<?php echo htmlspecialchars($review['comment']); ?>"
                    </div>
                    
                    <div class="review-analysis">
                        <div class="analysis-row">
                            <div>
                                <strong>Themes:</strong>
                                <?php echo getThemeBadges($analysis['themes']); ?>
                            </div>
                        </div>
                        <?php if (!empty($analysis['summary'])): ?>
                        <div style="margin-top: 1rem; padding: 0.75rem; background: #f8fafc; border-radius: 8px; border-left: 3px solid #3b82f6;">
                            <strong style="color: #1e40af; display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                                <i class="fas fa-lightbulb"></i> AI Summary:
                            </strong>
                            <span style="color: #475569; font-size: 0.875rem; line-height: 1.6;">
                                <?php echo htmlspecialchars($analysis['summary']); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <?php
}
?>

