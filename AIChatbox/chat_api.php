<?php
/**
 * AI Chatbox API Backend - Intelligent & Bilingual Version
 * Handles user messages with smart date detection, context-aware queries, and natural language formatting
 * Supports: English & Bahasa Melayu
 */

session_start();
header('Content-Type: application/json');

// Check if user is logged in as hiker
if (!isset($_SESSION['hikerID'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Please log in to use the chatbox.'
    ]);
    exit;
}

require_once '../shared/db_connection.php';

// Get the user's message and language preference from POST request
$input = json_decode(file_get_contents('php://input'), true);
$userMessage = trim($input['message'] ?? '');
$language = $input['language'] ?? 'en'; // Default: English

if (empty($userMessage)) {
    $msg = $language === 'ms' ? 'Sila masukkan mesej.' : 'Please enter a message.';
    echo json_encode([
        'success' => false,
        'message' => $msg
    ]);
    exit;
}

// Convert message to lowercase for keyword detection
$messageLower = strtolower($userMessage);

// Enhanced keyword detection with RELAXED matching (supports natural/casual language)
// MGP = Malim Gunung Perhutanan (alternative term for guider)
$guiderKeywords = ['guider', 'guide', 'pemandu', 'mgp', 'malim gunung perhutanan', 'malim gunung', 'malim', 'perhutanan', 'available', 'tersedia', 'book', 'tempah', 'booking', 'tempahan', 'price', 'harga', 'rating', 'penilaian', 'review', 'ulasan', 'tukang', 'orang'];
$mountainKeywords = ['mountain', 'gunung', 'peak', 'puncak', 'trail', 'laluan', 'location', 'lokasi', 'bukit', 'tinggi'];
$hikingKeywords = ['hiking', 'mendaki', 'hike', 'trail', 'laluan', 'mountain', 'gunung', 'safety', 'keselamatan', 'preparation', 'persediaan', 'equipment', 'peralatan', 'gear', 'packing', 'weather', 'cuaca', 'tips', 'nasihat', 'panduan', 'cara', 'bawa', 'perlu', 'checklist', 'jalan', 'trekking', 'camping', 'daki', 'naik'];

// RELAXED: Check if message has ANY hiking-related context
$isGuiderQuery = false;
$isMountainQuery = false;
$isHikingRelated = false;

// PRIORITY 1: Check for specific guider question patterns FIRST
// This ensures questions like "siapa guider" ALWAYS trigger database query
$guiderQuestionPatterns = [
    '/\b(siapa|who|siapakah)\s*(guider|pemandu|mgp|malim|guide)/i',
    '/\b(guider|pemandu|mgp|malim|guide)\s*(siapa|who|ada|yang|mana|tersedia|available|di|dalam|in)/i',
    '/\b(list|senarai|tunjuk|show|display)\s*(guider|pemandu|mgp|malim)/i',
    '/\b(guider|pemandu|mgp|malim)\s*(di|dalam|in)\s*(hgs|sistem)/i',
];

foreach ($guiderQuestionPatterns as $pattern) {
    if (preg_match($pattern, $messageLower)) {
        $isGuiderQuery = true;
        $isHikingRelated = true;
        break;
    }
}

// PRIORITY 2: ALWAYS check for guider keywords (regardless of hiking-related status)
// This ensures questions like "guider tersedia" trigger database query
if (!$isGuiderQuery) {
    foreach ($guiderKeywords as $keyword) {
        if (strpos($messageLower, $keyword) !== false) {
            $isGuiderQuery = true;
            $isHikingRelated = true;
            break;
        }
    }
}

// PRIORITY 3: Check for casual conversational patterns that indicate hiking interest
$casualPatterns = [
    '/\b(tips?|nasihat|panduan|guide|cara|macam\s*mana|how)\b.{0,30}\b(hiking|mendaki|daki|naik|gunung|mountain|trail)\b/i',
    '/\b(hiking|mendaki|daki|gunung|mountain)\b.{0,30}\b(tips?|nasihat|panduan|cara|apa|what|perlu|need)\b/i',
    '/\b(bagi|tolong|boleh|can|please|help)\b.{0,30}\b(tips?|info|maklumat|panduan|guide)\b.{0,30}\b(hiking|mendaki|gunung|mountain)/i',
    '/\b(nak|want|mahu)\b.{0,30}\b(hiking|mendaki|daki|gunung|mountain)/i',
    // MGP / Malim Gunung Perhutanan patterns
    '/\b(mgp|malim\s*gunung|perhutanan)\b/i',
    '/\b(pemandu|guide|guider|mgp|malim)\b/i',
];

if (!$isHikingRelated) {
    foreach ($casualPatterns as $pattern) {
        if (preg_match($pattern, $messageLower)) {
            $isHikingRelated = true;
            break;
        }
    }
}

// PRIORITY: Check for specific mountain question patterns FIRST
// This ensures questions like "gunung mana untuk pemula" trigger database query
$mountainQuestionPatterns = [
    '/\b(gunung|mountain)\s*(mana|which|apa|yang|mana|yang|paling)\b/i',
    '/\b(gunung|mountain)\s*(untuk|for|sesuai|baik|okey|ok)\s*(pemula|beginner|newbie)/i',
    '/\b(pemula|beginner|newbie)\s*(gunung|mountain)/i',
    '/\b(gunung|mountain)\s*(mudah|easy|senang|simple)/i',
    '/\b(list|senarai|tunjuk|show)\s*(gunung|mountain)/i',
    '/\b(gunung|mountain)\s*(di|dalam|in)\s*(hgs|sistem|malaysia)/i',
];

foreach ($mountainQuestionPatterns as $pattern) {
    if (preg_match($pattern, $messageLower)) {
        $isMountainQuery = true;
        $isHikingRelated = true;
        break;
    }
}

// Standard keyword check for mountain/hiking (only if not already detected)
if (!$isHikingRelated) {
    foreach ($mountainKeywords as $keyword) {
        if (strpos($messageLower, $keyword) !== false) {
            $isMountainQuery = true;
            $isHikingRelated = true;
            break;
        }
    }
}

if (!$isHikingRelated) {
    foreach ($hikingKeywords as $keyword) {
        if (strpos($messageLower, $keyword) !== false) {
            $isHikingRelated = true;
            break;
        }
    }
}

// VERY RELAXED: If short message mentioning gunung/hiking, assume related
if (!$isHikingRelated && str_word_count($messageLower) <= 10) {
    if (preg_match('/\b(gunung|mountain|hiking|mendaki|daki|trek|climb|naik)\b/i', $messageLower)) {
        $isHikingRelated = true;
    }
}

// Only refuse if CLEARLY not hiking-related (e.g., "what is your name?", "hello")
$clearlyNotHiking = [
    '/^(hi|hello|hai|hey|assalam|salam|halo)\s*$/i',
    '/^(apa\s*khabar|how\s*are\s*you|siapa\s*nama)/i',
    '/\b(weather\s*today|cuaca\s*hari\s*ini|berita|news|politik|sports|sukan)\b/i'
];

$definitelyNotHiking = false;
foreach ($clearlyNotHiking as $pattern) {
    if (preg_match($pattern, $messageLower)) {
        $definitelyNotHiking = true;
        break;
    }
}

// If clearly NOT hiking and NO hiking keywords, refuse
if ($definitelyNotHiking || (!$isHikingRelated && strlen($userMessage) > 5)) {
    // Do one final check - send to AI to classify if uncertain
    if (!$definitelyNotHiking && strlen($userMessage) > 3) {
        // Let AI decide if it's hiking-related (lenient mode)
        $isHikingRelated = true; // TRUST THE AI!
    }
}

// Only refuse obvious non-hiking
if ($definitelyNotHiking) {
    $msg = $language === 'ms' 
        ? "Saya adalah pembantu pendakian HGS. Sila tanya soalan berkaitan pendakian, gunung, atau pemandu! 🏔️"
        : "I'm HGS hiking assistant. Please ask about hiking, mountains, or guiders! 🏔️";
    
    echo json_encode([
        'success' => true,
        'message' => $msg,
        'source' => 'system'
    ]);
    exit;
}

$apiKey = 'AIzaSyAYXICSCAt6-htR3-qPsSatKhMGbUolCNM';

// Handle database queries for guiders
if ($isGuiderQuery) {
    try {
        $response = queryGuiderData($conn, $userMessage, $messageLower, $language);
        
        // Check if response is array (database result for Gemini formatting)
        if (is_array($response) && isset($response['type'])) {
            // Try Gemini formatting, fallback to simple if quota exceeded
            $formattedResponse = formatWithGemini($response, $apiKey, $language);
            
            // Check if formatting failed (has DEBUG info)
            if (strpos($formattedResponse, '⚠️ DEBUG') !== false) {
                // Gemini failed - use simple format with debug
                $source = 'database (AI failed - see debug)';
            } else if (strpos($formattedResponse, 'Berdasarkan pertanyaan') === 0 || strpos($formattedResponse, 'Based on your query') === 0) {
                // Got simple format response (fallback was used)
                $source = 'database';
            } else {
                // Got AI-enhanced response!
                $source = 'database + AI ✨';
            }
            
            echo json_encode([
                'success' => true,
                'message' => $formattedResponse,
                'source' => $source
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'message' => $response,
                'source' => 'database'
            ]);
        }
        exit;
    } catch (Exception $e) {
        error_log("Guider query error: " . $e->getMessage());
    }
}

// Handle database queries for mountains
if ($isMountainQuery) {
    try {
        $response = queryMountainData($conn, $userMessage, $messageLower, $language);
        
        if (is_array($response) && isset($response['type'])) {
            // Try Gemini formatting, fallback to simple if quota exceeded
            $formattedResponse = formatWithGemini($response, $apiKey, $language);
            
            // Check if formatting failed (has DEBUG info)
            if (strpos($formattedResponse, '⚠️ DEBUG') !== false) {
                // Gemini failed - use simple format
                $formattedResponse = formatSimpleResponse($response, $language);
                $source = 'database';
            } else if (strpos($formattedResponse, 'Berdasarkan pertanyaan') === 0 || strpos($formattedResponse, 'Based on your query') === 0) {
                // Got simple format response (fallback was used)
                $source = 'database';
            } else {
                // Got AI-enhanced response!
                $source = 'database + AI ✨';
            }
            
            echo json_encode([
                'success' => true,
                'message' => $formattedResponse,
                'source' => $source
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'message' => $response,
                'source' => 'database'
            ]);
        }
        exit;
    } catch (Exception $e) {
        error_log("Mountain query error: " . $e->getMessage());
        // Fallback to simple error message
        $msg = $language === 'ms'
            ? "Maaf, terdapat ralat semasa mencari maklumat gunung. Sila cuba lagi."
            : "Sorry, there was an error finding mountain information. Please try again.";
        echo json_encode([
            'success' => false,
            'message' => $msg
        ]);
        exit;
    }
}

// For general hiking questions, use Gemini API
$geminiResponse = callGeminiAPI($userMessage, $language);

if ($geminiResponse['success']) {
    echo json_encode([
        'success' => true,
        'message' => $geminiResponse['message'],
        'source' => 'gemini'
    ]);
} else {
    $msg = $language === 'ms' 
        ? 'Maaf, saya mengalami ralat. Sila cuba lagi.'
        : 'Sorry, I encountered an error. Please try again later.';
    echo json_encode([
        'success' => false,
        'message' => $msg
    ]);
}

/**
 * Extract date from user message
 * Supports: DD/MM/YYYY, DD-MM-YYYY, YYYY-MM-DD, and relative dates
 */
function extractDateFromMessage($message) {
    $messageLower = strtolower($message);
    
    // Check for relative dates first (English)
    if (preg_match('/\b(tomorrow|tmr)\b/i', $messageLower)) {
        return date('Y-m-d', strtotime('+1 day'));
    }
    if (preg_match('/\b(today)\b/i', $messageLower)) {
        return date('Y-m-d');
    }
    if (preg_match('/\bnext week\b/i', $messageLower)) {
        return date('Y-m-d', strtotime('+1 week'));
    }
    if (preg_match('/\bnext month\b/i', $messageLower)) {
        return date('Y-m-d', strtotime('+1 month'));
    }
    if (preg_match('/\bthis weekend\b/i', $messageLower)) {
        return date('Y-m-d', strtotime('next saturday'));
    }
    
    // Check for relative dates (Bahasa Melayu)
    if (preg_match('/\b(esok|hari esok)\b/i', $messageLower)) {
        return date('Y-m-d', strtotime('+1 day'));
    }
    if (preg_match('/\b(hari ini|harini)\b/i', $messageLower)) {
        return date('Y-m-d');
    }
    if (preg_match('/\b(minggu depan|minggu hadapan)\b/i', $messageLower)) {
        return date('Y-m-d', strtotime('+1 week'));
    }
    if (preg_match('/\b(bulan depan|bulan hadapan)\b/i', $messageLower)) {
        return date('Y-m-d', strtotime('+1 month'));
    }
    if (preg_match('/\b(hujung minggu|weekend)\b/i', $messageLower)) {
        return date('Y-m-d', strtotime('next saturday'));
    }
    
    // Match DD/MM/YYYY or DD-MM-YYYY
    if (preg_match('/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', $message, $matches)) {
        $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
        $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
        $year = $matches[3];
        return "$year-$month-$day";
    }
    
    // Match YYYY-MM-DD
    if (preg_match('/(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/', $message, $matches)) {
        $year = $matches[1];
        $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
        $day = str_pad($matches[3], 2, '0', STR_PAD_LEFT);
        return "$year-$month-$day";
    }
    
    return null;
}

/**
 * Extract number of hikers from message
 */
function extractHikerCount($message) {
    // English patterns
    if (preg_match('/(\d+)\s*(people|person|hikers?|pax|group)/i', $message, $matches)) {
        return (int)$matches[1];
    }
    if (preg_match('/(group|party)\s+of\s+(\d+)/i', $message, $matches)) {
        return (int)$matches[2];
    }
    
    // Malay patterns
    if (preg_match('/(\d+)\s*(orang|pendaki|kumpulan)/i', $message, $matches)) {
        return (int)$matches[1];
    }
    
    return null;
}

/**
 * Smart query for guider data with date filtering and context awareness
 */
function queryGuiderData($conn, $originalMessage, $messageLower, $language = 'en') {
    $requestedDate = extractDateFromMessage($originalMessage);
    $hikerCount = extractHikerCount($originalMessage);
    
    if ($requestedDate) {
        // Query guiders available on specific date
        $stmt = $conn->prepare("
            SELECT g.guiderID, g.username, g.price, g.skills, 
                   g.experience, g.average_rating, g.total_reviews, g.about
            FROM guider g
            WHERE g.status = 'active'
            AND g.guiderID NOT IN (
                SELECT guiderID FROM schedule WHERE offDate = ?
            )
            AND g.guiderID NOT IN (
                SELECT DISTINCT guiderID FROM booking
                WHERE ? BETWEEN startDate AND endDate
                AND status IN ('pending', 'accepted', 'paid')
                AND groupType = 'close'
            )
            ORDER BY g.average_rating DESC, g.total_reviews DESC
            LIMIT 10
        ");
        $stmt->bind_param("ss", $requestedDate, $requestedDate);
        $stmt->execute();
        $result = $stmt->get_result();
        $guiders = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        if (empty($guiders)) {
            return $language === 'ms'
                ? "Malangnya, tiada pemandu tersedia pada $requestedDate. Semua pemandu kami sama ada telah ditempah atau mempunyai hari cuti. Adakah anda ingin mencuba tarikh lain?"
                : "Unfortunately, no guiders are available on $requestedDate. All our guiders are either booked or have scheduled their off day. Would you like to try a different date?";
        }
        
        return [
            'type' => 'database_result',
            'query_type' => 'guider_availability',
            'date' => $requestedDate,
            'hiker_count' => $hikerCount,
            'data' => $guiders,
            'original_question' => $originalMessage
        ];
        
    } else {
        // General guider list
        $stmt = $conn->prepare("
            SELECT guiderID, username, price, skills, experience, about, average_rating, total_reviews 
            FROM guider 
            WHERE status = 'active' 
            ORDER BY average_rating DESC, total_reviews DESC 
            LIMIT 10
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        $guiders = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        if (empty($guiders)) {
            return $language === 'ms'
                ? "Pada masa ini, tiada pemandu aktif dalam sistem HGS. Sila semak semula kemudian!"
                : "Currently, there are no active guiders in the HGS system. Please check back later!";
        }
        
        return [
            'type' => 'database_result',
            'query_type' => 'guider_list',
            'data' => $guiders,
            'original_question' => $originalMessage
        ];
    }
}

/**
 * Query mountain data from MySQL database
 */
function queryMountainData($conn, $originalMessage, $messageLower, $language = 'en') {
    // Check for POPULARITY queries (most important - check first!)
    $popularityKeywords = [
        'popular', 'famous', 'ramai', 'banyak orang', 'paling banyak', 
        'terkenal', 'most people', 'most booked', 'favorite', 'favourite',
        'kegemaran', 'terpilih', 'trending', 'best', 'top',
        'banyak dipilih', 'banyak daki', 'orang pilih', 'orang daki',
        'banyak yang', 'ramai yang', 'most', 'paling ramai'
    ];
    $isPopularityQuery = false;
    
    // First, check specific popularity patterns (more accurate)
    $popularityPatterns = [
        '/paling\s+(banyak|ramai|popular|terkenal)/i',
        '/banyak\s+orang\s+(daki|pilih|pergi)/i',
        '/ramai\s+orang\s+(daki|pilih)/i',
        '/most\s+(popular|people|booked)/i',
        '/gunung\s+(popular|terkenal|famous)/i',
        '/popular\s+mountain/i'
    ];
    
    foreach ($popularityPatterns as $pattern) {
        if (preg_match($pattern, $messageLower)) {
            $isPopularityQuery = true;
            error_log("Popularity query detected by pattern: " . $pattern);
            break;
        }
    }
    
    // If not matched by pattern, try keyword matching
    if (!$isPopularityQuery) {
        foreach ($popularityKeywords as $keyword) {
            if (strpos($messageLower, $keyword) !== false) {
                $isPopularityQuery = true;
                error_log("Popularity query detected by keyword: " . $keyword);
                break;
            }
        }
    }
    
    // If asking about popularity, query with booking count
    if ($isPopularityQuery) {
        error_log("=== POPULARITY QUERY TRIGGERED ===");
        error_log("Original message: " . $originalMessage);
        
        $stmt = $conn->prepare("
            SELECT 
                m.name, 
                m.location, 
                m.description,
                COUNT(b.mountainID) as popularity_count
            FROM mountain m
            LEFT JOIN booking b ON m.mountainID = b.mountainID
            GROUP BY m.mountainID, m.name, m.location, m.description
            ORDER BY popularity_count DESC
            LIMIT 10
        ");
        if (!$stmt) {
            error_log("Popularity query prepare failed: " . $conn->error);
            return $language === 'ms' 
                ? "Maaf, terdapat masalah teknikal. Sila cuba lagi."
                : "Sorry, there's a technical issue. Please try again.";
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $mountains = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        error_log("Popularity query returned " . count($mountains) . " mountains");
        if (!empty($mountains)) {
            error_log("Top mountain: " . $mountains[0]['name'] . " with " . $mountains[0]['popularity_count'] . " bookings");
        }
        
        if (!empty($mountains)) {
            return [
                'type' => 'database_result',
                'query_type' => 'mountain_popularity',
                'data' => $mountains,
                'original_question' => $originalMessage
            ];
        }
    }
    
    // Check for beginner/easy difficulty queries
    $beginnerKeywords = ['pemula', 'beginner', 'newbie', 'mula', 'mudah', 'easy', 'senang', 'simple', 'basic'];
    $isBeginnerQuery = false;
    
    foreach ($beginnerKeywords as $keyword) {
        if (strpos($messageLower, $keyword) !== false) {
            $isBeginnerQuery = true;
            break;
        }
    }
    
    // Check for difficulty level queries
    $difficultyKeywords = ['susah', 'sukar', 'hard', 'difficult', 'advanced', 'expert', 'mahir', 'pro'];
    $isAdvancedQuery = false;
    
    foreach ($difficultyKeywords as $keyword) {
        if (strpos($messageLower, $keyword) !== false) {
            $isAdvancedQuery = true;
            break;
        }
    }
    
    $listKeywords = ['list', 'all', 'available', 'senarai', 'semua', 'tersedia'];
    $isList = false;
    
    foreach ($listKeywords as $keyword) {
        if (strpos($messageLower, $keyword) !== false) {
            $isList = true;
            break;
        }
    }
    
    // Query for beginner-friendly mountains
    if ($isBeginnerQuery) {
        $stmt = $conn->prepare("
            SELECT name, location, description
            FROM mountain 
            LIMIT 10
        ");
        if (!$stmt) {
            error_log("Beginner query prepare failed: " . $conn->error);
            return $language === 'ms' 
                ? "Maaf, terdapat masalah teknikal. Sila cuba lagi."
                : "Sorry, there's a technical issue. Please try again.";
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $mountains = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        if (!empty($mountains)) {
            return [
                'type' => 'database_result',
                'query_type' => 'mountain_beginner',
                'data' => $mountains,
                'original_question' => $originalMessage
            ];
        }
    }
    
    // Query for advanced mountains
    if ($isAdvancedQuery) {
        $stmt = $conn->prepare("
            SELECT name, location, description
            FROM mountain 
            LIMIT 10
        ");
        if (!$stmt) {
            error_log("Advanced query prepare failed: " . $conn->error);
            return $language === 'ms' 
                ? "Maaf, terdapat masalah teknikal. Sila cuba lagi."
                : "Sorry, there's a technical issue. Please try again.";
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $mountains = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        if (!empty($mountains)) {
            return [
                'type' => 'database_result',
                'query_type' => 'mountain_advanced',
                'data' => $mountains,
                'original_question' => $originalMessage
            ];
        }
    }
    
    if ($isList) {
        $stmt = $conn->prepare("
            SELECT name, location, description
            FROM mountain 
            ORDER BY name ASC
        ");
        if (!$stmt) {
            error_log("List query prepare failed: " . $conn->error);
            return $language === 'ms' 
                ? "Maaf, terdapat masalah teknikal. Sila cuba lagi."
                : "Sorry, there's a technical issue. Please try again.";
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $mountains = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        if (empty($mountains)) {
            return $language === 'ms'
                ? "Pada masa ini, tiada gunung disenaraikan dalam sistem HGS."
                : "Currently, there are no mountains listed in the HGS system.";
        }
        
        return [
            'type' => 'database_result',
            'query_type' => 'mountain_list',
            'data' => $mountains,
            'original_question' => $originalMessage
        ];
    }
    
    // Search specific mountain
    $stmt = $conn->prepare("
        SELECT name, location, description 
        FROM mountain 
        WHERE LOWER(name) LIKE ? OR LOWER(location) LIKE ?
        LIMIT 5
    ");
    if (!$stmt) {
        error_log("Search query prepare failed: " . $conn->error);
        // Fallback: return all mountains
        $stmt = $conn->prepare("SELECT name, location, description FROM mountain LIMIT 10");
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            $mountains = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            if (!empty($mountains)) {
                return [
                    'type' => 'database_result',
                    'query_type' => 'mountain_general',
                    'data' => $mountains,
                    'original_question' => $originalMessage
                ];
            }
        }
        return $language === 'ms' 
            ? "Maaf, terdapat masalah teknikal. Sila cuba lagi."
            : "Sorry, there's a technical issue. Please try again.";
    }
    $searchTerm = "%" . $originalMessage . "%";
    $stmt->bind_param("ss", $searchTerm, $searchTerm);
    $stmt->execute();
    $result = $stmt->get_result();
    $mountains = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    if (!empty($mountains)) {
        return [
            'type' => 'database_result',
            'query_type' => 'mountain_search',
            'data' => $mountains,
            'original_question' => $originalMessage
        ];
    }
    
    // If no specific mountains found, get all mountains for AI to describe
    $stmt = $conn->prepare("SELECT name, location, description FROM mountain LIMIT 20");
    if (!$stmt) {
        error_log("General query prepare failed: " . $conn->error);
        return $language === 'ms' 
            ? "Maaf, terdapat masalah dengan pangkalan data. Sila hubungi admin."
            : "Sorry, there's a database issue. Please contact admin.";
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $mountains = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    if (!empty($mountains)) {
        return [
            'type' => 'database_result',
            'query_type' => 'mountain_general',
            'data' => $mountains,
            'original_question' => $originalMessage
        ];
    }
    
    // Fallback if no mountains at all
    if ($language === 'ms') {
        return "Maaf, tiada maklumat gunung tersedia pada masa ini.";
    } else {
        return "Sorry, no mountain information is available at the moment.";
    }
}

/**
 * Use Gemini AI to format database results into natural conversational language
 */
function formatWithGemini($dbResult, $apiKey, $language = 'en') {
    if (!is_array($dbResult) || !isset($dbResult['type'])) {
        return $dbResult;
    }
    
    $languageInstruction = $language === 'ms' 
        ? "Respond in Bahasa Melayu (Malay language). Be friendly and conversational." 
        : "Respond in English. Be friendly and conversational.";
    
    $context = "You are a friendly hiking assistant for HGS (Hiking Guidance System). ";
    $context .= $languageInstruction . "\n\n";
    $context .= "A user asked (in casual/natural language): \"" . $dbResult['original_question'] . "\"\n\n";
    $context .= "Understand that users speak casually like friends chatting. Examples: 'bagi aku', 'tolong', 'nak tahu'.\n\n";
    $context .= "Here is the database query result:\n";
    $context .= json_encode($dbResult['data'], JSON_PRETTY_PRINT);
    
    $prompt = $context . "\n\n";
    $prompt .= "Format this as a natural, friendly response matching the user's casual tone. ";
    $prompt .= "Include specific details (names, prices, ratings). Use **markdown** for emphasis. ";
    $prompt .= "Be conversational and helpful like talking to a friend. Use 1-2 emojis. Keep under 200 words. ";
    
    // Note about terminology if user asked about guiders/MGP
    if ($dbResult['query_type'] === 'guider_search' || $dbResult['query_type'] === 'guider_availability') {
        $prompt .= "Note: In HGS system, guiders are also called 'MGP' (Malim Gunung Perhutanan). ";
        $prompt .= "You can use either term naturally in your response. ";
    }
    
    // Special instructions for beginner/advanced mountain queries
    if ($dbResult['query_type'] === 'mountain_beginner') {
        $prompt .= "The user specifically asked about mountains for BEGINNERS. ";
        $prompt .= "Emphasize that these mountains are suitable for new hikers, mention difficulty level, and give safety tips. ";
        $prompt .= "Be encouraging and reassuring. ";
    }
    
    if ($dbResult['query_type'] === 'mountain_advanced') {
        $prompt .= "The user asked about ADVANCED/CHALLENGING mountains. ";
        $prompt .= "Emphasize that these require experience, mention difficulty level, and include warnings about proper preparation. ";
    }
    
    // Special instructions for POPULARITY queries (most important!)
    if ($dbResult['query_type'] === 'mountain_popularity') {
        $prompt .= "The user asked about the MOST POPULAR mountains based on actual booking data. ";
        $prompt .= "The 'popularity_count' field shows how many people have booked each mountain. ";
        $prompt .= "Present this as a RANKING with clear numbers (1st, 2nd, 3rd place). ";
        $prompt .= "Highlight the TOP 3 most popular mountains and explain WHY they might be popular. ";
        $prompt .= "Use emojis like 🏆 👑 🥇 🥈 🥉 to make it engaging. ";
        $prompt .= "Mention the booking count naturally (e.g., '13 orang telah mendaki', '13 bookings so far'). ";
        $prompt .= "If some mountains have 0 bookings, focus only on those that have been booked. ";
        $prompt .= "Be enthusiastic and informative! ";
    }
    
    if (isset($dbResult['date'])) {
        $prompt .= "The user asked about availability on " . $dbResult['date'] . ". ";
        $prompt .= "Mention this date in your response naturally. ";
    }
    
    if (isset($dbResult['hiker_count']) && $dbResult['hiker_count']) {
        $prompt .= "The user mentioned " . $dbResult['hiker_count'] . " hikers. ";
        $prompt .= "Acknowledge the group size in your response. ";
    }
    
    // Removed - already specified above
    
    // Call Gemini (Using stable model for consistency)
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $apiKey;
    
    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.7,
            'topK' => 40,
            'topP' => 0.95,
            'maxOutputTokens' => 8192  // Increased from 1024 to 8192 (8x more!)
        ],
        'safetySettings' => [
            [
                'category' => 'HARM_CATEGORY_HARASSMENT',
                'threshold' => 'BLOCK_NONE'
            ],
            [
                'category' => 'HARM_CATEGORY_HATE_SPEECH',
                'threshold' => 'BLOCK_NONE'
            ],
            [
                'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                'threshold' => 'BLOCK_NONE'
            ],
            [
                'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                'threshold' => 'BLOCK_NONE'
            ]
        ]
    ];
    
    // Retry logic for transient errors (503, 500)
    $maxRetries = 2;
    $retryDelay = 2; // seconds
    $response = null;
    $httpCode = 0;
    $curlError = '';
    
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For localhost testing
        
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Success or non-retryable error
        if ($httpCode === 200 || ($httpCode !== 503 && $httpCode !== 500)) {
            break;
        }
        
        // Retry on 503/500 (server errors)
        if ($attempt < $maxRetries) {
            error_log("Gemini HTTP $httpCode on attempt $attempt, retrying in {$retryDelay}s...");
            sleep($retryDelay);
        }
    }
    
    // DEBUG: Log errors (you can check error_log or add to database)
    if ($curlError) {
        error_log("Gemini cURL Error: " . $curlError);
    }
    
    if ($httpCode !== 200) {
        error_log("Gemini HTTP Error: Code $httpCode, Response: " . $response);
    }
    
    if ($httpCode === 200 && $response) {
        $responseData = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Gemini JSON Parse Error: " . json_last_error_msg());
        }
        
        // Check for MAX_TOKENS finish reason (response cut off)
        if (isset($responseData['candidates'][0]['finishReason']) && 
            $responseData['candidates'][0]['finishReason'] === 'MAX_TOKENS') {
            error_log("Gemini: MAX_TOKENS reached - response truncated!");
        }
        
        if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
            $aiText = trim($responseData['candidates'][0]['content']['parts'][0]['text']);
            error_log("Gemini SUCCESS: Generated " . strlen($aiText) . " chars");
            return $aiText;
        } else {
            error_log("Gemini Response Structure Error: " . print_r($responseData, true));
            
            // Try alternate paths
            if (isset($responseData['candidates'][0]['text'])) {
                error_log("Gemini: Found text at alternate path");
                return trim($responseData['candidates'][0]['text']);
            }
            
            // Check if there's partial text even with MAX_TOKENS
            if (isset($responseData['candidates'][0]['content']['parts']) && 
                is_array($responseData['candidates'][0]['content']['parts']) &&
                !empty($responseData['candidates'][0]['content']['parts'])) {
                foreach ($responseData['candidates'][0]['content']['parts'] as $part) {
                    if (isset($part['text']) && !empty($part['text'])) {
                        error_log("Gemini: Found partial text in parts array");
                        return trim($part['text']);
                    }
                }
            }
        }
    }
    
    // Fallback if Gemini fails
    // Check if quota exceeded
    if ($httpCode === 429) {
        // Quota exceeded - use simple format
        error_log("Gemini quota exceeded - using simple format");
        return formatSimpleResponse($dbResult, $language);
    }
    
    // Check if service unavailable
    if ($httpCode === 503 || $httpCode === 500) {
        error_log("Gemini service unavailable (HTTP $httpCode) - using simple format");
        return formatSimpleResponse($dbResult, $language);
    }
    
    // Other errors - use simple format
    $fallbackResponse = formatSimpleResponse($dbResult, $language);
    
    // Add friendly error note for users
    if ($httpCode === 503 || $httpCode === 500) {
        $note = $language === 'ms' 
            ? "\n\n(Perkhidmatan AI sedang sibuk. Jawapan di atas adalah dari data sistem.)"
            : "\n\n(AI service is busy. Response above is from system data.)";
        $fallbackResponse .= $note;
    } elseif ($httpCode !== 200 && $httpCode !== 0) {
        $note = $language === 'ms'
            ? "\n\n(AI tidak tersedia. Jawapan di atas adalah dari data sistem.)"
            : "\n\n(AI unavailable. Response above is from system data.)";
        $fallbackResponse .= $note;
    }
    
    return $fallbackResponse;
}

/**
 * Fallback formatter if Gemini fails
 */
function formatSimpleResponse($dbResult, $language = 'en') {
    // Special handling for POPULARITY queries with ranking
    if (isset($dbResult['query_type']) && $dbResult['query_type'] === 'mountain_popularity') {
        $response = $language === 'ms' 
            ? "🏆 Gunung Paling Popular (berdasarkan data booking):\n\n" 
            : "🏆 Most Popular Mountains (based on booking data):\n\n";
        
        $rank = 1;
        $medals = ['🥇', '🥈', '🥉'];
        
        foreach ($dbResult['data'] as $item) {
            // Only show mountains that have been booked
            if (isset($item['popularity_count']) && $item['popularity_count'] > 0) {
                $medal = $rank <= 3 ? $medals[$rank - 1] . ' ' : "{$rank}. ";
                $response .= $medal . $item['name'] . "\n";
                
                if (isset($item['location'])) {
                    $response .= "  📍 " . $item['location'] . "\n";
                }
                
                $bookingText = $language === 'ms' 
                    ? "  ✅ {$item['popularity_count']} booking" . ($item['popularity_count'] > 1 ? 's' : '')
                    : "  ✅ {$item['popularity_count']} booking" . ($item['popularity_count'] > 1 ? 's' : '');
                $response .= $bookingText . "\n\n";
                
                $rank++;
                
                // Only show top 5
                if ($rank > 5) break;
            }
        }
        
        return $response;
    }
    
    // Standard formatting for other queries
    $response = $language === 'ms' ? "Berdasarkan pertanyaan anda:\n\n" : "Based on your query:\n\n";
    
    foreach ($dbResult['data'] as $item) {
        $response .= "• " . ($item['username'] ?? $item['name']) . "\n";
        
        if (isset($item['price'])) {
            $label = $language === 'ms' ? '  Harga' : '  Price';
            $response .= "$label: RM" . number_format($item['price'], 2) . "\n";
        }
        
        if (isset($item['average_rating']) && $item['average_rating'] > 0) {
            $label = $language === 'ms' ? '  Penilaian' : '  Rating';
            $response .= "$label: " . number_format($item['average_rating'], 1) . " ⭐\n";
        }
        
        if (isset($item['location'])) {
            $label = $language === 'ms' ? '  Lokasi' : '  Location';
            $response .= "$label: " . $item['location'] . "\n";
        }
        
        if (isset($item['difficultyLevel'])) {
            $label = $language === 'ms' ? '  Kesukaran' : '  Difficulty';
            $response .= "$label: " . $item['difficultyLevel'] . "\n";
        }
        
        if (isset($item['description']) && !empty($item['description'])) {
            $label = $language === 'ms' ? '  Penerangan' : '  Description';
            $desc = substr($item['description'], 0, 100);
            $response .= "$label: " . $desc . (strlen($item['description']) > 100 ? '...' : '') . "\n";
        }
        
        $response .= "\n";
    }
    
    return $response;
}

/**
 * Call Gemini API for general hiking questions
 * Returns null if quota exceeded
 */
function callGeminiAPI($userMessage, $language = 'en') {
    $apiKey = 'AIzaSyAYXICSCAt6-htR3-qPsSatKhMGbUolCNM';
    
    $languageInstruction = $language === 'ms'
        ? "Respond in Bahasa Melayu (Malay language) only."
        : "Respond in English only.";
    
    $systemPrompt = "You are a friendly hiking assistant for HGS (Hiking Guidance System). ";
    $systemPrompt .= $languageInstruction . " ";
    $systemPrompt .= "You understand casual, everyday language - users talk naturally like chatting with a friend. ";
    $systemPrompt .= "Examples: 'bagi aku tips', 'tolong explain', 'nak tahu cara', 'macam mana nak', 'boleh tak bagi'. ";
    $systemPrompt .= "Answer ALL hiking-related questions helpfully and conversationally. ";
    $systemPrompt .= "Note: 'Guider', 'pemandu', 'MGP', and 'Malim Gunung Perhutanan' all refer to hiking guides. ";
    $systemPrompt .= "Use markdown formatting (**bold**, *italic*, lists) to make responses clear and engaging. ";
    $systemPrompt .= "Focus on safety, preparation, and practical tips. Keep answers concise but complete (under 250 words). ";
    $systemPrompt .= "If clearly not about hiking (e.g., 'what is 2+2', 'who is the president'), politely say you only help with hiking.";
    
    $fullMessage = $systemPrompt . "\n\nUser asked: " . $userMessage;
    
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . urlencode($apiKey);
    
    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $fullMessage]
                ]
            ]
        ]
    ];
    
    // Retry logic for transient errors
    $maxRetries = 2;
    $retryDelay = 2;
    $response = null;
    $httpCode = 0;
    $curlError = '';
    
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        // Success or non-retryable error
        if ($httpCode === 200 || ($httpCode !== 503 && $httpCode !== 500)) {
            break;
        }
        
        // Retry on 503/500
        if ($attempt < $maxRetries) {
            error_log("Gemini general API HTTP $httpCode on attempt $attempt, retrying...");
            sleep($retryDelay);
        }
    }
    
    if ($curlError) {
        error_log("Gemini API cURL error: " . $curlError);
        $msg = $language === 'ms' 
            ? 'Maaf, saya mengalami ralat sambungan. Sila cuba lagi.'
            : 'Sorry, I encountered a connection error. Please try again later.';
        return [
            'success' => false,
            'message' => $msg
        ];
    }
    
    if ($httpCode !== 200) {
        error_log("Gemini API HTTP error: " . $httpCode . " - " . $response);
        
        // Check if quota exceeded
        if ($httpCode === 429) {
            $msg = $language === 'ms'
                ? 'Maaf, AI assistant sedang tidak tersedia kerana had penggunaan harian telah dicapai. Untuk soalan tentang pemandu atau gunung, sila tanya tentang maklumat sistem. Saya akan kembali esok! 😊'
                : 'Sorry, the AI assistant is temporarily unavailable as the daily usage limit has been reached. For questions about guiders or mountains, please ask about system information. I\'ll be back tomorrow! 😊';
            return [
                'success' => true,  // Return success so chat continues
                'message' => $msg
            ];
        }
        
        // Check if service unavailable (after retries)
        if ($httpCode === 503 || $httpCode === 500) {
            $msg = $language === 'ms'
                ? 'Maaf, perkhidmatan AI sedang sibuk. Sila cuba sebentar lagi. Untuk soalan tentang pemandu atau gunung, sila tanya tentang maklumat sistem. 😊'
                : 'Sorry, the AI service is currently busy. Please try again in a moment. For questions about guiders or mountains, please ask about system information. 😊';
            return [
                'success' => true,
                'message' => $msg
            ];
        }
        
        $msg = $language === 'ms'
            ? 'Maaf, saya mengalami ralat. Sila cuba lagi.'
            : 'Sorry, I encountered an error processing your request. Please try again later.';
        return [
            'success' => false,
            'message' => $msg
        ];
    }
    
    $responseData = json_decode($response, true);
    
    if (!isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        error_log("Gemini API unexpected response format: " . $response);
        $msg = $language === 'ms'
            ? 'Maaf, saya menerima respons yang tidak dijangka. Sila cuba lagi.'
            : 'Sorry, I received an unexpected response. Please try again.';
        return [
            'success' => false,
            'message' => $msg
        ];
    }
    
    $aiResponse = $responseData['candidates'][0]['content']['parts'][0]['text'];
    
    return [
        'success' => true,
        'message' => trim($aiResponse)
    ];
}

// Close database connection
if ($conn) {
    $conn->close();
}
?>
