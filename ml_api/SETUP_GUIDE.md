# 🚀 Setup Guide - Sentiment Analysis Feature

**Quick Start**: Follow these steps to get sentiment analysis working in 15 minutes!

---

## ✅ What Has Been Created

Dalam folder `ml_api/` ada files berikut:

### Core Files (Required)
1. **`app.py`** - Flask API server (Python)
2. **`config.php`** - PHP helper functions untuk call API
3. **`requirements.txt`** - Python dependencies
4. **`sentiment_guider_view.php`** - Dashboard view untuk guiders
5. **`sentiment_admin_view.php`** - Overview page untuk admin

### Helper Files (Optional)
6. **`README.md`** - Complete documentation
7. **`INTEGRATION_EXAMPLE_GUIDER.php`** - Example code untuk integration
8. **`test_api.php`** - Test script untuk verify setup
9. **`start_ml_api.bat`** - Windows batch file untuk start server
10. **`SETUP_GUIDE.md`** - This file

---

## 📋 Prerequisites Checklist

Before starting, make sure you have:

- [x] XAMPP installed and running
- [x] Python 3.8+ installed
- [ ] Gemini API key (free from Google)
- [ ] At least 10 reviews with comments in database (for testing)

---

## 🎯 Step-by-Step Setup (15 Minutes)

### Step 1: Install Python (if not installed)

**Check if Python is installed:**
```bash
python --version
```

**If not installed:**
1. Download from: https://www.python.org/downloads/
2. ✅ **IMPORTANT**: Check "Add Python to PATH" during installation
3. Restart terminal after installation

### Step 2: Install Python Dependencies

```bash
# Open Command Prompt or Terminal
cd C:\xampp\htdocs\HGS\ml_api

# Install required packages
pip install -r requirements.txt
```

Expected output:
```
Successfully installed flask-2.3.0 flask-cors-4.0.0 requests-2.31.0
```

### Step 3: Get Gemini API Key

1. Go to: **https://makersuite.google.com/app/apikey**
2. Sign in with Google account
3. Click **"Create API Key"**
4. Copy the key (looks like: `AIzaSyA...`)

### Step 4: Configure API Key

Open `ml_api/app.py` and edit line 16:

```python
# Change this:
GEMINI_API_KEY = "YOUR_GEMINI_API_KEY_HERE"

# To (your actual key):
GEMINI_API_KEY = "AIzaSyA1b2c3d4e5f6g7h8i9..."
```

Save the file.

### Step 5: Start ML API Server

**Option A: Double-click (Easy)**
```
Double-click: ml_api/start_ml_api.bat
```

**Option B: Command Line**
```bash
cd C:\xampp\htdocs\HGS\ml_api
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

✅ **Keep this window open!** Close = API stops working.

### Step 6: Test the API

Open browser and visit:
```
http://localhost/HGS/ml_api/test_api.php
```

You should see:
- ✓ Test 1: Health Check - **PASSED**
- ✓ Test 2: Single Review Analysis - **SUCCESS**
- ✓ Test 3: Batch Analysis - **SUCCESS**
- ✓ Test 4: Helper Functions - **ALL WORKING**

If all tests pass → **API is ready!** 🎉

---

## 🔧 Integration Steps

### For Guider Dashboard

1. Open `guider/GPerformance.php`

2. Add these lines **at the top** (after session_start):
```php
require_once '../ml_api/config.php';
require_once '../ml_api/sentiment_guider_view.php';
```

3. Add this section **where you want sentiment analysis to appear**:
```php
<section class="sentiment-section">
    <h2>📊 Review Sentiment Analysis</h2>
    <?php
    displayGuiderSentimentAnalysis($_SESSION['uid'], $con);
    ?>
</section>
```

4. **Test**: Login as guider → Open Performance page → Should see sentiment dashboard

### For Admin Dashboard

**Option 1: Standalone Page (Recommended)**

1. Copy file to admin folder:
```bash
copy ml_api\sentiment_admin_view.php admin\ASentimentReport.php
```

2. Add link in `admin/Ahome.php`:
```php
<a href="ASentimentReport.php" class="menu-link">
    <i class="fas fa-chart-line"></i>
    Sentiment Analysis Report
</a>
```

3. **Test**: Login as admin → Click link → Should see all guiders' sentiment

**Option 2: Integrate into Existing Page**

Use the code from `sentiment_admin_view.php` and copy relevant sections.

---

## 📊 How to Use

### For Guiders:

1. Login to guider account
2. Go to Performance Dashboard
3. Scroll to **"Review Sentiment Analysis"** section
4. View:
   - Overall sentiment percentage
   - Sentiment breakdown (Positive/Negative/Neutral)
   - Top themes mentioned
   - Individual review analysis

**Example Output:**
```
💬 Sentiment Analysis Dashboard
━━━━━━━━━━━━━━━━━━━━━━━━━━━━

📊 Overall Sentiment: 92% Positive

🏆 Top Themes Mentioned:
  • Safety (18 mentions) ✓
  • Knowledge (15 mentions) ✓
  • Friendliness (20 mentions) ✓

⚠️ Areas for Improvement:
  • Punctuality (3 negative mentions)
```

### For Admin:

1. Login to admin account
2. Click **"Sentiment Analysis Report"**
3. View:
   - Overall system statistics
   - All guiders comparison
   - Sentiment percentages
   - Top themes for each guider

**Example Output:**
```
Overall Positive Rate: 87%
Total Reviews Analyzed: 156

Guider Comparison:
━━━━━━━━━━━━━━━━━━━━━━━━━━━
Ahmad    | 95% Positive | Safety, Knowledge
Ali      | 88% Positive | Friendliness, Help
Siti     | 92% Positive | Communication, Safe
```

---

## 🔍 Troubleshooting

### Problem: "ML Service Offline" message

**Symptoms:**
- Yellow warning banner on page
- No sentiment analysis shown

**Solution:**
```bash
# Start the ML API server
cd C:\xampp\htdocs\HGS\ml_api
python app.py
```

Or double-click: `start_ml_api.bat`

### Problem: "Module not found" error

**Symptoms:**
```
ModuleNotFoundError: No module named 'flask'
```

**Solution:**
```bash
cd C:\xampp\htdocs\HGS\ml_api
pip install -r requirements.txt
```

### Problem: Slow analysis (30+ seconds)

**Cause:** Gemini API can be slow sometimes

**Solutions:**
1. **Normal**: 2-5 seconds per review is expected
2. **Very slow?**: Check internet connection
3. **Want faster?**: Upgrade to BERT model (see MachineLearningIdea.md Option B)

### Problem: "Invalid API Key" error

**Solution:**
1. Get new key: https://makersuite.google.com/app/apikey
2. Update in `app.py` line 16
3. Restart Flask server

### Problem: Port 5000 already in use

**Solution:**
```bash
# Option 1: Use different port
# Edit app.py line 349:
app.run(debug=True, host='127.0.0.1', port=5001)

# Also update config.php:
define('ML_API_BASE_URL', 'http://127.0.0.1:5001/api');
```

### Problem: No reviews showing

**Cause:** No reviews with comments in database

**Solution:**
1. Add sample reviews with comments
2. Or test with existing reviews
3. Check database: `SELECT * FROM review WHERE comment IS NOT NULL`

---

## 🎯 Testing Checklist

Use this checklist to verify everything works:

### Basic Tests
- [ ] Python installed and working (`python --version`)
- [ ] Dependencies installed (`pip list | findstr flask`)
- [ ] API key configured in `app.py`
- [ ] Flask server starts without errors
- [ ] Health check returns "healthy" (http://127.0.0.1:5000/api/health)
- [ ] Test page shows all passed (http://localhost/HGS/ml_api/test_api.php)

### Integration Tests (Guider)
- [ ] Can login as guider
- [ ] GPerformance.php loads without errors
- [ ] Sentiment section appears
- [ ] Shows "ML Service Online" or "Offline" banner
- [ ] If online, displays sentiment analysis
- [ ] Individual reviews show sentiment badges
- [ ] Themes are displayed correctly

### Integration Tests (Admin)
- [ ] Can login as admin
- [ ] ASentimentReport.php loads
- [ ] Shows all guiders list
- [ ] Displays sentiment percentages
- [ ] Shows top themes for each guider
- [ ] Overall statistics are correct

### Performance Tests
- [ ] Single review analysis < 5 seconds
- [ ] Batch analysis (10 reviews) < 30 seconds
- [ ] Page loads without timeout
- [ ] No PHP errors in log

---

## 📈 Success Metrics

After setup, you should see:

**For Guiders:**
- ✅ Sentiment dashboard visible
- ✅ Can see overall positive percentage
- ✅ Can identify strengths and weaknesses
- ✅ Individual reviews have AI insights

**For Admin:**
- ✅ Can compare all guiders
- ✅ Can spot negative trends
- ✅ Overall system quality visible
- ✅ Export capability (future)

---

## 🚀 Next Steps

After successful setup:

1. **Monitor Usage**
   - Check Flask terminal for API calls
   - Verify sentiment accuracy with real reviews
   - Gather feedback from guiders

2. **Optimize**
   - Consider upgrading to BERT (faster)
   - Add caching for analyzed reviews
   - Create sentiment trend graphs

3. **Expand**
   - Add email alerts for negative reviews
   - Create monthly sentiment reports
   - Implement automated response suggestions

---

## 📞 Need Help?

**Common Issues:**
- API not starting → Check Python installation
- Connection refused → Make sure Flask is running
- Slow analysis → Normal for Gemini API
- No data showing → Check database has reviews

**Check Logs:**
- Flask logs: Terminal output
- PHP logs: `C:\xampp\apache\logs\error.log`
- Browser console: F12 → Console tab

**Still stuck?**
- Re-read README.md
- Check INTEGRATION_EXAMPLE_GUIDER.php
- Run test_api.php to identify issue

---

## ✅ Final Checklist

Before considering setup complete:

- [ ] ML API starts successfully
- [ ] All tests pass in test_api.php
- [ ] Guider dashboard shows sentiment
- [ ] Admin report accessible
- [ ] At least 1 real review analyzed successfully
- [ ] Documented any custom changes
- [ ] Team trained on how to use

**All checked?** 

🎉 **Congratulations! Sentiment Analysis is live!** 🎉

---

**Setup Time:** ~15 minutes  
**Difficulty:** ⭐⭐☆☆☆ (Easy-Medium)  
**Version:** 1.0  
**Last Updated:** November 2025

