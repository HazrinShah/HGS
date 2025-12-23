<?php
/**
 * Chatbox Include File
 * Add this to all hiker pages to enable the AI chatbox
 * 
 * Usage: <?php include_once '../AIChatbox/chatbox_include.php'; ?>
 * 
 * This file should be included before the closing </body> tag
 */

// Only show chatbox for logged-in hikers
if (isset($_SESSION['hikerID'])) {
    ?>
    <!-- AI Chatbox Styles -->
    <style>
        /* Define CSS variables if not already defined */
        :root {
            --guider-blue: #1e40af;
            --guider-blue-light: #3b82f6;
            --guider-blue-dark: #1e3a8a;
            --guider-blue-accent: #60a5fa;
            --guider-blue-soft: #dbeafe;
        }
        
        /* Chatbox Container */
        .chatbox-container {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 10000;
            font-family: "Montserrat", sans-serif;
        }
        
        /* Toggle Button (Robot Icon) */
        .chatbox-toggle {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--guider-blue), var(--guider-blue-light));
            border: none;
            color: white;
            font-size: 32px;
            cursor: pointer;
            box-shadow: 0 6px 25px rgba(30, 64, 175, 0.5);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            animation: floatRobot 3s ease-in-out infinite;
        }
        
        .chatbox-toggle:hover {
            animation: none;
            transform: scale(1.15) rotate(5deg);
            box-shadow: 0 8px 30px rgba(30, 64, 175, 0.7);
        }
        
        .chatbox-toggle.active {
            background: linear-gradient(135deg, var(--guider-blue-dark), var(--guider-blue));
            animation: none;
        }
        
        .chatbox-toggle i {
            animation: robotWave 2s ease-in-out infinite;
        }
        
        .chatbox-toggle:hover i {
            animation: none;
        }
        
        /* Floating Animation */
        @keyframes floatRobot {
            0%, 100% {
                transform: translateY(0px);
                box-shadow: 0 6px 25px rgba(30, 64, 175, 0.5);
            }
            50% {
                transform: translateY(-10px);
                box-shadow: 0 10px 35px rgba(30, 64, 175, 0.6);
            }
        }
        
        /* Robot Wave Animation */
        @keyframes robotWave {
            0%, 100% {
                transform: rotate(0deg);
            }
            25% {
                transform: rotate(-10deg);
            }
            75% {
                transform: rotate(10deg);
            }
        }
        
        /* Notification pulse effect on robot */
        .chatbox-toggle::before {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: transparent;
            border: 3px solid var(--guider-blue-light);
            opacity: 0;
            animation: robotPulse 2s ease-out infinite;
        }
        
        @keyframes robotPulse {
            0% {
                transform: scale(1);
                opacity: 0.8;
            }
            100% {
                transform: scale(1.3);
                opacity: 0;
            }
        }
        
        /* Chatbox Window */
        .chatbox-window {
            position: absolute;
            bottom: 80px;
            right: 0;
            width: 380px;
            max-width: calc(100vw - 40px);
            height: 600px;
            max-height: calc(100vh - 120px);
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            display: flex;
            flex-direction: column;
            opacity: 0;
            transform: translateY(20px) scale(0.95);
            pointer-events: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
        }
        
        .chatbox-window.open {
            opacity: 1;
            transform: translateY(0) scale(1);
            pointer-events: all;
        }
        
        /* Chatbox Header */
        .chatbox-header {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 50%, #60a5fa 100%);
            color: white;
            padding: 1.5rem 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-radius: 20px 20px 0 0;
            position: relative;
            overflow: hidden;
        }
        
        /* Decorative background pattern */
        .chatbox-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: headerShine 8s linear infinite;
        }
        
        @keyframes headerShine {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .chatbox-header-content {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
            z-index: 1;
        }
        
        /* Bot Avatar Circle */
        .chatbox-header-content i {
            font-size: 28px;
            background: white;
            color: var(--guider-blue);
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            animation: botBounce 2s ease-in-out infinite;
        }
        
        @keyframes botBounce {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-3px); }
        }
        
        .chatbox-header-content h6 {
            font-weight: 700;
            margin: 0;
            font-size: 1.1rem;
            letter-spacing: 0.5px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .chatbox-header-content small {
            opacity: 0.95;
            font-size: 0.75rem;
            font-weight: 400;
        }
        
        .chatbox-header-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            position: relative;
            z-index: 1;
        }
        
        .chatbox-lang-btn {
            background: rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.4);
            color: white;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            padding: 0.4rem 0.7rem;
            border-radius: 8px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            letter-spacing: 0.5px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .chatbox-lang-btn:hover {
            background: rgba(255, 255, 255, 0.35);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .chatbox-lang-btn:active {
            transform: translateY(0px);
        }
        
        .chatbox-close {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            color: white;
            font-size: 18px;
            cursor: pointer;
            padding: 0.4rem 0.5rem;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0.9;
            transition: all 0.3s;
        }
        
        .chatbox-close:hover {
            opacity: 1;
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }
        
        /* Chatbox Messages Area */
        .chatbox-messages {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem;
            background: linear-gradient(180deg, #f1f5f9 0%, #ffffff 100%);
            display: flex;
            flex-direction: column;
            gap: 1rem;
            position: relative;
        }
        
        /* Decorative background dots */
        .chatbox-messages::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: radial-gradient(circle, rgba(59, 130, 246, 0.05) 1px, transparent 1px);
            background-size: 20px 20px;
            pointer-events: none;
        }
        
        .chatbox-messages::-webkit-scrollbar {
            width: 6px;
        }
        
        .chatbox-messages::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        
        .chatbox-messages::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }
        
        .chatbox-messages::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        
        /* Message Styles */
        .chatbox-message {
            display: flex;
            gap: 0.75rem;
            animation: fadeInMessage 0.3s ease;
        }
        
        @keyframes fadeInMessage {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .chatbox-message.user-message {
            flex-direction: row-reverse;
        }
        
        .message-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 18px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.15);
            position: relative;
        }
        
        .user-message .message-avatar {
            background: linear-gradient(135deg, #3b82f6, #60a5fa);
            color: white;
            border: 3px solid #dbeafe;
        }
        
        .bot-message .message-avatar {
            background: linear-gradient(135deg, #10b981, #34d399);
            color: white;
            border: 3px solid #d1fae5;
            animation: botAvatarPulse 2s ease-in-out infinite;
        }
        
        @keyframes botAvatarPulse {
            0%, 100% { box-shadow: 0 3px 12px rgba(16, 185, 129, 0.3); }
            50% { box-shadow: 0 3px 20px rgba(16, 185, 129, 0.5); }
        }
        
        .message-content {
            flex: 1;
            max-width: 75%;
        }
        
        .user-message .message-content {
            text-align: right;
        }
        
        .message-source-badge {
            display: inline-block;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.15), rgba(96, 165, 250, 0.15));
            color: var(--guider-blue-dark);
            font-size: 0.7rem;
            padding: 0.25rem 0.6rem;
            border-radius: 12px;
            margin-bottom: 0.35rem;
            font-weight: 700;
            border: 1px solid rgba(59, 130, 246, 0.2);
            backdrop-filter: blur(5px);
        }
        
        .message-text {
            background: white;
            padding: 0.9rem 1.2rem;
            border-radius: 20px;
            box-shadow: 0 3px 15px rgba(0, 0, 0, 0.08);
            word-wrap: break-word;
            line-height: 1.6;
            border: 1px solid rgba(226, 232, 240, 0.8);
            transition: all 0.2s ease;
        }
        
        .message-text:hover {
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.12);
            transform: translateY(-1px);
        }
        
        .user-message .message-text {
            background: linear-gradient(135deg, #3b82f6 0%, #60a5fa 100%);
            color: white;
            border-radius: 20px 20px 5px 20px;
            border: none;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
        }
        
        .user-message .message-text:hover {
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
        }
        
        .bot-message .message-text {
            background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
            color: #1f2937;
            border-radius: 20px 20px 20px 5px;
        }
        
        .message-text ul {
            margin: 0.5rem 0;
            padding-left: 1.25rem;
        }
        
        .message-text li {
            margin: 0.25rem 0;
        }
        
        .message-text a {
            color: var(--guider-blue);
            text-decoration: underline;
        }
        
        /* Markdown formatting support */
        .message-text strong {
            font-weight: 700;
            color: inherit;
        }
        
        .message-text em {
            font-style: italic;
        }
        
        .message-text code {
            background: rgba(0, 0, 0, 0.05);
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
        }
        
        .user-message .message-text code {
            background: rgba(255, 255, 255, 0.2);
        }
        
        .message-time {
            font-size: 0.7rem;
            color: #94a3b8;
            margin-top: 0.25rem;
            padding: 0 0.5rem;
        }
        
        /* Typing Indicator */
        .typing-indicator {
            opacity: 0.7;
        }
        
        .typing-dots {
            display: inline-flex;
            gap: 4px;
        }
        
        .typing-dots span {
            width: 8px;
            height: 8px;
            background: #94a3b8;
            border-radius: 50%;
            animation: typingDot 1.4s infinite;
        }
        
        .typing-dots span:nth-child(2) {
            animation-delay: 0.2s;
        }
        
        .typing-dots span:nth-child(3) {
            animation-delay: 0.4s;
        }
        
        @keyframes typingDot {
            0%, 60%, 100% {
                transform: translateY(0);
                opacity: 0.7;
            }
            30% {
                transform: translateY(-8px);
                opacity: 1;
            }
        }
        
        /* Chatbox Input Area */
        .chatbox-input-area {
            padding: 1.25rem;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            border-top: 1px solid #e2e8f0;
            box-shadow: 0 -4px 12px rgba(0,0,0,0.03);
        }
        
        .chatbox-form {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }
        
        .chatbox-input {
            flex: 1;
            border: 2px solid #e2e8f0;
            border-radius: 25px;
            padding: 0.85rem 1.2rem;
            font-size: 0.95rem;
            outline: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        
        .chatbox-input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15), 0 4px 12px rgba(0,0,0,0.08);
            transform: translateY(-1px);
        }
        
        .chatbox-input::placeholder {
            color: #94a3b8;
        }
        
        .chatbox-send {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3b82f6 0%, #60a5fa 100%);
            border: none;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            flex-shrink: 0;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
            font-size: 18px;
        }
        
        .chatbox-send:hover {
            transform: scale(1.1) rotate(15deg);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.5);
        }
        
        .chatbox-send:active {
            transform: scale(0.95) rotate(0deg);
        }
        
        .chatbox-send:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .chatbox-container {
                bottom: 15px;
                right: 15px;
            }
            
            .chatbox-toggle {
                width: 64px;
                height: 64px;
                font-size: 28px;
            }
            
            .chatbox-window {
                width: calc(100vw - 30px);
                height: calc(100vh - 100px);
                bottom: 76px;
                right: 0;
                border-radius: 20px 20px 0 0;
            }
            
            .chatbox-messages {
                padding: 1rem;
            }
            
            .message-content {
                max-width: 85%;
            }
        }
        
        @media (max-width: 480px) {
            .chatbox-window {
                width: 100vw;
                height: calc(100vh - 70px);
                right: 0;
                border-radius: 0;
            }
        }
    </style>
    
    <!-- Chatbox JavaScript -->
    <script src="../AIChatbox/chatbox.js"></script>
    <?php
}
?>

