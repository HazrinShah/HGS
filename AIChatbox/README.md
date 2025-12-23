# AI Chatbox Feature - Intelligent & Bilingual

## Overview
The AI Chatbox is an **intelligent, bilingual** hiking assistant integrated into the HGS (Hiking Guidance System) that helps hikers with:
- 🌐 **Bilingual Support**: Switch between English & Bahasa Melayu
- 📅 **Smart Date Detection**: Automatically detects dates in questions (e.g., "24/11/2026")
- 🤖 **Natural Language**: Uses Gemini AI to format responses conversationally
- 📊 **Context-Aware Queries**: Understands complex questions like "available guiders on 25/12/2024 for 3 people"
- 🏔️ **Real-Time Data**: Queries MySQL database for guider/mountain information
- 💬 **General Hiking Help**: AI-powered responses for hiking tips and safety

## Files Created

1. **`AIChatbox/chat_api.php`** - Backend API that handles:
   - MySQL database queries for guider/mountain data
   - Gemini API integration for general hiking questions
   - Message routing and response formatting

2. **`AIChatbox/chatbox.js`** - Frontend JavaScript that handles:
   - User interface interactions
   - Message sending and receiving
   - Chat history management
   - UI animations and state management
   - Language toggle (EN/MS)

3. **`AIChatbox/test_gemini.php`** - Diagnostic test script:
   - Tests Gemini API connectivity
   - Displays error messages
   - Helps troubleshoot API issues

4. **`AIChatbox/chatbox_include.php`** - Reusable include file with:
   - Chatbox HTML structure
   - CSS styling (Bootstrap-compatible)
   - JavaScript file reference

## Setup Instructions

### Step 1: Get Your Gemini API Key

1. Go to [Google AI Studio](https://makersuite.google.com/app/apikey)
2. Sign in with your Google account
3. Click "Create API Key"
4. Copy your API key

### Step 2: Configure the API Key

1. Open `AIChatbox/chat_api.php`
2. Find the line (around line 259):
   ```php
   $apiKey = 'YOUR_GEMINI_API_KEY_HERE';
   ```
3. Replace `YOUR_GEMINI_API_KEY_HERE` with your actual API key:
   ```php
   $apiKey = 'your-actual-api-key-here';
   ```

### Step 3: Verify Integration

The chatbox is already integrated into the following hiker pages:
- `hiker/HHomePage.php`
- `hiker/HBooking.php`
- `hiker/HProfile.php`
- `hiker/HPayment.php`
- `hiker/HYourGuider.php`
- `hiker/HRateReview.php`
- `hiker/HBookingHistory.php`

To add it to other pages, simply include this line before the closing `</body>` tag:
```php
<?php include_once '../AIChatbox/chatbox_include.php'; ?>
```

## How It Works

### Message Routing Logic

1. **Database Queries** (for guider/mountain info):
   - Keywords: "guider", "guide", "available", "book", "price", "rating", "mountain", "trail", "location"
   - Queries MySQL database and returns real-time data
   - Format: Structured responses with lists and details

2. **Gemini API** (for general hiking questions):
   - All other hiking-related questions
   - Uses Google Gemini Pro model
   - System prompt ensures AI only answers hiking-related questions

3. **Non-Hiking Questions**:
   - Politely refuses to answer
   - Redirects user to ask hiking-related questions

### Example Queries

**Intelligent Database Queries (English):**
- "Which guiders are available on 24/11/2026?"
- "Show me available guiders on 25-12-2024 for 3 people"
- "List cheapest guiders"
- "What mountains are in Johor?"

**Intelligent Database Queries (Bahasa Melayu):**
- "Pemandu mana yang tersedia pada 24/11/2026?"
- "Tunjukkan saya pemandu untuk 5 orang"
- "Senaraikan semua gunung"
- "Harga pemandu berapa?"

**AI-Powered General Questions (Both Languages):**
- "What should I pack for hiking?" / "Apa yang perlu saya bawa untuk mendaki?"
- "How to stay safe?" / "Bagaimana untuk kekal selamat?"
- "Best practices for beginners" / "Tips untuk pemula"

## Features

### 🎯 Intelligence Features
- ✅ **Smart Date Detection**: Auto-detect dates in formats like DD/MM/YYYY, DD-MM-YYYY, YYYY-MM-DD
- ✅ **Context-Aware**: Detects group size, preferences, and specific requirements
- ✅ **Natural Language**: Gemini AI formats database results conversationally
- ✅ **Hybrid Approach**: Combines database queries with AI formatting

### 🌐 Bilingual Support
- ✅ **English & Bahasa Melayu**: Full support for both languages
- ✅ **Easy Toggle**: Click language button (EN/MS) in header to switch
- ✅ **Persistent Preference**: Language choice saved to localStorage
- ✅ **Smart Detection**: Supports keywords in both languages

### 💬 User Experience
- ✅ Real-time database queries for guider/mountain data
- ✅ AI-powered conversational responses
- ✅ Mobile-responsive design
- ✅ Chat history persistence (localStorage)
- ✅ Typing indicators & smooth animations
- ✅ Bootstrap-compatible styling
- ✅ Only visible to logged-in hikers

## Future ML Integration Placeholder

In `AIChatbox/chat_api.php`, there's a placeholder function `getMLRecommendation()` (around line 385) for future machine learning features. This will connect to a Python/Flask API that can recommend the best guider or mountain based on user preferences.

## Troubleshooting

### Chatbox not appearing?
- Check that you're logged in as a hiker (session check)
- Verify the include file path is correct
- Check browser console for JavaScript errors

### Gemini API not working?
- **Run the test script first**: Access `http://localhost/HGS/AIChatbox/test_gemini.php` to diagnose the exact issue
- Verify your API key is correct
- Check PHP error logs for API errors
- Ensure your server can make outbound HTTPS requests
- Check API quota limits on Google AI Studio
- **Model updated**: System now uses `gemini-1.5-flash` (latest stable model)
- Common errors:
  - `404 Model Not Found`: Model name outdated (already fixed!)
  - `403 Forbidden`: API key invalid or billing not enabled
  - `429 Too Many Requests`: Quota exceeded, wait or upgrade
  - `SAFETY blocked`: Content filtered (safety settings configured)

### Database queries not working?
- Verify database connection in `shared/db_connection.php`
- Check that `guider` and `mountain` tables exist
- Review PHP error logs

## Security Notes

- The chatbox only appears for logged-in hikers (session check)
- API key is stored server-side (never exposed to client)
- All user input is sanitized before database queries
- SQL injection protection via prepared statements

## New Features Explained

### 📅 Smart Date Detection
The system automatically detects dates in user questions:
```
User: "Available guiders on 24/11/2026?"
System: Extracts "2026-11-24" → Queries database → Filters guiders by availability
Response: "Great news! I found 2 guiders available on November 24, 2026..."
```

### 🤖 Natural Language Formatting
Instead of robotic list output, Gemini AI formats results conversationally:

**Before (Old):**
```
Available guiders:
• Ahmad
  Price: RM150
  Rating: 4.8
```

**After (New with Gemini):**
```
Great news! I found 2 excellent guiders for your trip:

🌟 Ahmad is highly rated (4.8 stars) and offers affordable pricing at RM150. 
He's skilled in first aid and mountain navigation, making him perfect for your 
hiking adventure!
```

### 🌐 Language Switching
Click the language button (EN/MS) in the chatbox header to switch between:
- **English** - Full English responses
- **Bahasa Melayu** - Full Malay responses

Preference is saved automatically!

## Support

For issues or questions, check:
1. PHP error logs (`error_log`)
2. Browser console (F12)
3. Network tab (to see API requests)
4. Check Gemini API quota at Google AI Studio

