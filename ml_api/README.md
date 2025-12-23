# 🤖 HGS Machine Learning API - Sentiment Analysis

**Feature #3: Sentiment Analysis on Reviews**

AI-powered sentiment analysis untuk analyze review comments dari hikers tentang guiders.

---

## 📁 Folder Structure

```
ml_api/
├── app.py                          # Flask API server (main)
├── config.php                      # PHP helper functions
├── sentiment_guider_view.php       # Guider dashboard view
├── sentiment_admin_view.php        # Admin overview page
├── requirements.txt                # Python dependencies
└── README.md                       # This file
```

---

## 🚀 Quick Start Guide

### Step 1: Install Python Dependencies

```bash
# Navigate to ml_api folder
cd C:\xampp\htdocs\HGS\ml_api

# Install required packages
pip install -r requirements.txt
```

### Step 2: Configure Gemini API Key

Edit `app.py` line 16:

```python
GEMINI_API_KEY = "YOUR_ACTUAL_GEMINI_API_KEY_HERE"
```

**How to get Gemini API key:**
1. Go to: https://makersuite.google.com/app/apikey
2. Click "Create API Key"
3. Copy the key and paste in `app.py`

### Step 3: Start ML API Server

```bash
# Run the Flask server
python app.py
```

You should see:

```
============================================================
🚀 HGS ML API Server Starting...
============================================================
📊 Available Endpoints:
  - GET  /api/health
  - POST /api/analyze-sentiment
  - POST /api/analyze-guider-reviews
============================================================
✅ Server running on http://127.0.0.1:5000
============================================================
```

**Keep this terminal window open!** The API must be running for sentiment analysis to work.

### Step 4: Test API

Open browser and visit: http://127.0.0.1:5000/api/health

You should see:

```json
{
  "status": "healthy",
  "service": "HGS ML API",
  "version": "1.0",
  "features": ["sentiment_analysis"]
}
```

✅ API is ready!

---

## 🔧 Integration Guide

### For Guider Dashboard (GPerformance.php)

Add this code to `guider/GPerformance.php`:

```php
<?php
// Include ML API helpers
require_once '../ml_api/config.php';
require_once '../ml_api/sentiment_guider_view.php';

// Inside your HTML content, add:
?>

<section class="sentiment-section">
    <h2>📊 Review Sentiment Analysis</h2>
    <?php
    // Display sentiment analysis for this guider
    displayGuiderSentimentAnalysis($_SESSION['uid'], $con);
    ?>
</section>
```

### For Admin Dashboard

Already complete! Just copy the file:

```bash
# Copy admin sentiment view to admin folder
copy ml_api\sentiment_admin_view.php admin\ASentimentReport.php
```

Then add link in `admin/Ahome.php`:

```php
<a href="ASentimentReport.php" class="dashboard-link">
    <i class="fas fa-chart-line"></i>
    Sentiment Analysis Report
</a>
```

---

## 📊 API Documentation

### 1. Health Check

**Endpoint:** `GET /api/health`

**Response:**
```json
{
  "status": "healthy",
  "service": "HGS ML API",
  "version": "1.0",
  "features": ["sentiment_analysis"]
}
```

### 2. Analyze Single Review

**Endpoint:** `POST /api/analyze-sentiment`

**Request Body:**
```json
{
  "text": "Ahmad was very knowledgeable and friendly!"
}
```

**Response:**
```json
{
  "success": true,
  "analysis": {
    "sentiment": "positive",
    "score": 95,
    "confidence": 0.98,
    "emotion": "happy",
    "themes": ["knowledge", "friendliness"],
    "key_phrases": ["very knowledgeable", "friendly"],
    "summary": "Positive feedback about expertise and demeanor"
  }
}
```

### 3. Analyze Guider Reviews (Batch)

**Endpoint:** `POST /api/analyze-guider-reviews`

**Request Body:**
```json
{
  "reviews": [
    {"reviewID": 1, "comment": "Great guide!"},
    {"reviewID": 2, "comment": "Very helpful and safe"}
  ]
}
```

**Response:**
```json
{
  "success": true,
  "total_reviews": 2,
  "analyzed_reviews": 2,
  "sentiment_breakdown": {
    "positive": 2,
    "negative": 0,
    "neutral": 0,
    "positive_percentage": 100.0
  },
  "top_themes": [
    {"theme": "safety", "count": 1, "sentiment": "positive"},
    {"theme": "helpfulness", "count": 1, "sentiment": "positive"}
  ],
  "emotion_distribution": {
    "happy": 1,
    "satisfied": 1,
    "neutral": 0,
    "disappointed": 0,
    "angry": 0
  },
  "reviews": [...]
}
```

---

## 🎯 Features Overview

### For Guiders:
- ✅ See overall sentiment percentage (e.g., "92% Positive")
- ✅ View sentiment breakdown (Positive/Negative/Neutral)
- ✅ Identify top strengths (most mentioned positive themes)
- ✅ Spot areas for improvement (negative themes)
- ✅ Track emotion distribution (Happy, Satisfied, etc.)
- ✅ Analyze individual reviews with AI insights

### For Admin:
- ✅ Overview of all guiders' sentiment
- ✅ Compare sentiment scores across guiders
- ✅ Identify guiders with negative trends
- ✅ Monitor overall system quality
- ✅ Export sentiment data (future feature)

---

## 🔍 Troubleshooting

### Problem: ML API not connecting

**Solution:**
1. Make sure Python Flask server is running
2. Check if port 5000 is not blocked
3. Verify XAMPP Apache is running (for PHP side)

Test command:
```bash
curl http://127.0.0.1:5000/api/health
```

### Problem: "ML Service Offline" message

**Solution:**
- Start the Flask server: `python app.py`
- Check firewall settings
- Verify Python is installed: `python --version`

### Problem: Invalid API Key error

**Solution:**
1. Get new Gemini API key from: https://makersuite.google.com/app/apikey
2. Replace in `app.py` line 16
3. Restart Flask server

### Problem: Slow sentiment analysis

**Expected:** 2-3 seconds per review (using Gemini API)

**Solutions:**
- This is normal for Gemini API
- For faster analysis, upgrade to Option B (BERT model) in MachineLearningIdea.md
- Consider caching analyzed reviews in database

---

## 🚀 Auto-Start on Windows (Optional)

### Create Batch File

Create `start_ml_api.bat`:

```batch
@echo off
cd C:\xampp\htdocs\HGS\ml_api
python app.py
pause
```

Double-click this file to start the API server.

### Run on System Startup

1. Press `Win + R`
2. Type: `shell:startup`
3. Copy `start_ml_api.bat` to this folder
4. ML API will start automatically when Windows starts

---

## 📈 Usage Statistics

Track usage in your database (optional):

```sql
CREATE TABLE ml_api_logs (
    logID INT AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(100),
    guiderID INT,
    reviewCount INT,
    responseTime FLOAT,
    success BOOLEAN,
    logDate DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

---

## 🔒 Security Notes

1. **API Key Protection:**
   - Never commit `app.py` with real API key to Git
   - Use environment variables in production

2. **Access Control:**
   - ML API runs on localhost only (127.0.0.1)
   - Only accessible from same server
   - For production, add authentication

3. **Rate Limiting:**
   - Gemini API has free tier limits
   - Monitor usage at: https://makersuite.google.com/app/usage

---

## 📞 Support

**Issues?** Check:
1. Python installed: `python --version`
2. Dependencies installed: `pip list`
3. Flask server running: Check terminal
4. Port 5000 free: `netstat -an | findstr :5000`

**Error Logs:**
- Flask logs: Check terminal output
- PHP logs: `C:\xampp\apache\logs\error.log`

---

## 🎉 Success Checklist

- [ ] Python installed
- [ ] Dependencies installed (`pip install -r requirements.txt`)
- [ ] Gemini API key configured
- [ ] Flask server running (`python app.py`)
- [ ] Health check passing (http://127.0.0.1:5000/api/health)
- [ ] Guider dashboard shows sentiment
- [ ] Admin report accessible

**All checked? You're ready to use sentiment analysis! 🚀**

---

**Version:** 1.0  
**Last Updated:** November 2025  
**Author:** AI-Assisted Development

