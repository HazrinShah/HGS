"""
Flask API for HGS Machine Learning Features
Feature #3: Sentiment Analysis on Reviews

Author: AI-Assisted Development
Date: November 2025
"""

from flask import Flask, request, jsonify
from flask_cors import CORS
import requests
import json
import re
import os

app = Flask(__name__)
CORS(app)  # Allow PHP to call this API

# Configuration - Use environment variable for API key (safer!)
GEMINI_API_KEY = os.environ.get('GEMINI_API_KEY', 'your-api-key-here')
GEMINI_API_URL = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent"


def analyze_sentiment_with_gemini(review_text):
    """
    
    Analyze sentiment using Gemini API
    
    Args:
        review_text (str): The review comment to analyze
        
    Returns:
        dict: Sentiment analysis results
    """
    prompt = f"""Analyze this hiking guide review and return ONLY a JSON object (no markdown, no explanation):

Review: "{review_text}"

Return this exact JSON structure:
{{
  "sentiment": "positive" or "negative" or "neutral",
  "score": 0-100,
  "confidence": 0.0-1.0,
  "emotion": "happy" or "satisfied" or "neutral" or "disappointed" or "angry",
  "themes": ["theme1", "theme2"],
  "key_phrases": ["phrase1", "phrase2"],
  "summary": "One sentence summary"
}}

Themes can be: safety, knowledge, friendliness, communication, punctuality, equipment, professionalism"""

    headers = {
        "Content-Type": "application/json"
    }
    
    payload = {
        "contents": [{
            "parts": [{
                "text": prompt
            }]
        }]
    }
    
    try:
        response = requests.post(
            f"{GEMINI_API_URL}?key={GEMINI_API_KEY}",
            headers=headers,
            json=payload,
            timeout=30
        )
        
        if response.status_code == 200:
            result = response.json()
            
            # Extract text from Gemini response
            text_response = result['candidates'][0]['content']['parts'][0]['text']
            
            # Clean up markdown code blocks if present
            text_response = re.sub(r'```json\n?', '', text_response)
            text_response = re.sub(r'```\n?', '', text_response)
            text_response = text_response.strip()
            
            # Parse JSON
            analysis = json.loads(text_response)
            
            return {
                "success": True,
                "analysis": analysis
            }
        else:
            return {
                "success": False,
                "error": f"API Error: {response.status_code}",
                "message": response.text
            }
            
    except json.JSONDecodeError as e:
        return {
            "success": False,
            "error": "JSON parsing error",
            "message": str(e)
        }
    except Exception as e:
        return {
            "success": False,
            "error": "Unexpected error",
            "message": str(e)
        }


@app.route('/api/health', methods=['GET'])
def health_check():
    """Health check endpoint"""
    return jsonify({
        "status": "healthy",
        "service": "HGS ML API",
        "version": "1.1",
        "features": ["sentiment_analysis"],
        "model": GEMINI_API_URL.split("/")[-1].replace(":generateContent", ""),
        "api_key_set": bool(GEMINI_API_KEY and GEMINI_API_KEY != 'your-api-key-here')
    })


@app.route('/api/analyze-sentiment', methods=['POST'])
def analyze_sentiment():
    """
    Analyze sentiment of a single review
    
    Request JSON:
    {
        "text": "Review comment here"
    }
    
    Response JSON:
    {
        "success": true,
        "analysis": {
            "sentiment": "positive",
            "score": 95,
            "confidence": 0.98,
            "emotion": "happy",
            "themes": ["friendliness", "knowledge"],
            "key_phrases": ["very helpful", "knowledgeable"],
            "summary": "Positive feedback about friendliness and expertise"
        }
    }
    """
    try:
        data = request.get_json()
        
        if not data or 'text' not in data:
            return jsonify({
                "success": False,
                "error": "Missing 'text' parameter"
            }), 400
        
        review_text = data['text'].strip()
        
        if not review_text:
            return jsonify({
                "success": False,
                "error": "Empty review text"
            }), 400
        
        # Analyze sentiment
        result = analyze_sentiment_with_gemini(review_text)
        
        return jsonify(result)
        
    except Exception as e:
        return jsonify({
            "success": False,
            "error": "Server error",
            "message": str(e)
        }), 500


@app.route('/api/analyze-guider-reviews', methods=['POST'])
def analyze_guider_reviews():
    """
    Analyze all reviews for a specific guider (batch processing)
    
    Request JSON:
    {
        "reviews": [
            {"reviewID": 1, "comment": "Great guide!"},
            {"reviewID": 2, "comment": "Very helpful"}
        ]
    }
    
    Response JSON:
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
            {"theme": "friendliness", "count": 5, "sentiment": "positive"},
            {"theme": "knowledge", "count": 3, "sentiment": "positive"}
        ],
        "emotion_distribution": {
            "happy": 10,
            "satisfied": 5,
            "neutral": 2,
            "disappointed": 0,
            "angry": 0
        },
        "reviews": [...]
    }
    """
    try:
        data = request.get_json()
        
        if not data or 'reviews' not in data:
            return jsonify({
                "success": False,
                "error": "Missing 'reviews' parameter"
            }), 400
        
        reviews = data['reviews']
        analyzed_reviews = []
        sentiment_counts = {"positive": 0, "negative": 0, "neutral": 0}
        theme_counts = {}
        emotion_counts = {"happy": 0, "satisfied": 0, "neutral": 0, "disappointed": 0, "angry": 0}
        
        # Analyze each review
        for review in reviews:
            if 'comment' not in review or not review['comment'].strip():
                continue
                
            result = analyze_sentiment_with_gemini(review['comment'])
            
            if result['success']:
                analysis = result['analysis']
                
                # Add review ID to result
                analysis['reviewID'] = review.get('reviewID', None)
                analysis['comment'] = review['comment']
                analyzed_reviews.append(analysis)
                
                # Count sentiments
                sentiment = analysis.get('sentiment', 'neutral')
                sentiment_counts[sentiment] = sentiment_counts.get(sentiment, 0) + 1
                
                # Count themes
                for theme in analysis.get('themes', []):
                    if theme not in theme_counts:
                        theme_counts[theme] = {"positive": 0, "negative": 0, "neutral": 0}
                    theme_counts[theme][sentiment] += 1
                
                # Count emotions
                emotion = analysis.get('emotion', 'neutral')
                emotion_counts[emotion] = emotion_counts.get(emotion, 0) + 1
        
        # Calculate statistics
        total_analyzed = len(analyzed_reviews)
        positive_pct = (sentiment_counts['positive'] / total_analyzed * 100) if total_analyzed > 0 else 0
        
        # Format top themes
        top_themes = []
        for theme, counts in theme_counts.items():
            total_count = sum(counts.values())
            dominant_sentiment = max(counts, key=counts.get)
            top_themes.append({
                "theme": theme,
                "count": total_count,
                "sentiment": dominant_sentiment,
                "positive": counts["positive"],
                "negative": counts["negative"],
                "neutral": counts["neutral"]
            })
        
        # Sort by count
        top_themes.sort(key=lambda x: x['count'], reverse=True)
        
        return jsonify({
            "success": True,
            "total_reviews": len(reviews),
            "analyzed_reviews": total_analyzed,
            "sentiment_breakdown": {
                "positive": sentiment_counts['positive'],
                "negative": sentiment_counts['negative'],
                "neutral": sentiment_counts['neutral'],
                "positive_percentage": round(positive_pct, 1)
            },
            "top_themes": top_themes,
            "emotion_distribution": emotion_counts,
            "reviews": analyzed_reviews
        })
        
    except Exception as e:
        return jsonify({
            "success": False,
            "error": "Server error",
            "message": str(e)
        }), 500


if __name__ == '__main__':
    print("=" * 60)
    print("🚀 HGS ML API Server Starting...")
    print("=" * 60)
    print("📊 Available Endpoints:")
    print("  - GET  /api/health")
    print("  - POST /api/analyze-sentiment")
    print("  - POST /api/analyze-guider-reviews")
    print("=" * 60)
    print("✅ Server running on http://127.0.0.1:5000")
    print("=" * 60)
    
    app.run(debug=True, host='127.0.0.1', port=5000)

