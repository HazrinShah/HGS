@echo off
title HGS ML API Server
color 0A

echo ============================================================
echo   HGS Machine Learning API Server
echo ============================================================
echo.

cd /d %~dp0

echo [1] Checking Python installation...
python --version
if errorlevel 1 (
    echo ERROR: Python is not installed or not in PATH
    echo Please install Python from https://www.python.org/downloads/
    pause
    exit
)
echo.

echo [2] Checking dependencies...
pip show flask >nul 2>&1
if errorlevel 1 (
    echo Installing dependencies...
    pip install -r requirements.txt
) else (
    echo Dependencies OK
)
echo.

echo [3] Starting Flask server...
echo.
echo ============================================================
echo Server will start on: http://127.0.0.1:5000
echo.
echo Keep this window open while using sentiment analysis
echo Press Ctrl+C to stop the server
echo ============================================================
echo.

python app.py

pause

