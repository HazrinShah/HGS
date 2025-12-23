/**
 * AI Chatbox Frontend JavaScript - Bilingual Version
 * Handles user interactions, message sending, and response display
 * Supports: English & Bahasa Melayu
 * 
 * For HGS (Hiking Guidance System)
 */

class Chatbox {
    constructor() {
        this.isOpen = false;
        this.chatHistory = [];
        this.apiEndpoint = '../AIChatbox/chat_api.php';
        this.language = localStorage.getItem('hgs_chatbox_language') || 'en'; // Default: English
        
        this.init();
    }
    
    /**
     * Initialize the chatbox
     */
    init() {
        this.createChatboxHTML();
        this.attachEventListeners();
        this.loadChatHistory();
        this.updateLanguageUI();
    }
    
    /**
     * Get localized text
     */
    getLocalizedText() {
        return {
            en: {
                title: 'HGSbot',
                subtitle: 'AI Hiking Assistant',
                welcomeMsg: `Hello! I'm HGSbot, your hiking companion. I can help you with:
                    <ul class="mt-2 mb-0">
                        <li>Hiking tips and safety</li>
                        <li>Mountain information</li>
                        <li>Available guiders</li>
                    </ul>
                    Ask me anything about hiking!`,
                placeholder: 'Ask about hiking, mountains, or guiders...',
                time: 'Just now'
            },
            ms: {
                title: 'HGSbot',
                subtitle: 'Pembantu Pendakian AI',
                welcomeMsg: `Helo! Saya HGSbot, rakan pendakian anda. Saya boleh membantu dengan:
                    <ul class="mt-2 mb-0">
                        <li>Tips dan keselamatan pendakian</li>
                        <li>Maklumat gunung</li>
                        <li>MGP yang tersedia</li>
                    </ul>
                    Tanya saya apa sahaja tentang pendakian!`,
                placeholder: 'Tanya tentang pendakian, gunung, atau pemandu...',
                time: 'Baru sahaja'
            }
        };
    }
    
    /**
     * Create the chatbox HTML structure
     */
    createChatboxHTML() {
        const texts = this.getLocalizedText()[this.language];
        
        // Create chatbox container
        const chatboxContainer = document.createElement('div');
        chatboxContainer.id = 'chatbox-container';
        chatboxContainer.className = 'chatbox-container';
        
        chatboxContainer.innerHTML = `
            <!-- Chatbox Toggle Button (Robot) -->
            <button id="chatbox-toggle" class="chatbox-toggle" aria-label="Open chatbox">
                <i class="fas fa-robot"></i>
            </button>
            
            <!-- Chatbox Window -->
            <div id="chatbox-window" class="chatbox-window">
                <!-- Chatbox Header -->
                <div class="chatbox-header">
                    <div class="chatbox-header-content">
                        <i class="fas fa-robot me-2"></i>
                        <div>
                            <h6 class="mb-0" id="chatbox-title">${texts.title}</h6>
                            <small class="text-muted" id="chatbox-subtitle">${texts.subtitle}</small>
                        </div>
                    </div>
                    <div class="chatbox-header-actions">
                        <!-- Language Toggle -->
                        <button id="chatbox-lang-toggle" class="chatbox-lang-btn" aria-label="Toggle language" title="Switch language">
                            <span id="lang-display">${this.language.toUpperCase()}</span>
                        </button>
                        <button id="chatbox-close" class="chatbox-close" aria-label="Close chatbox">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Chatbox Messages Area -->
                <div id="chatbox-messages" class="chatbox-messages">
                    <div class="chatbox-message bot-message">
                        <div class="message-avatar">
                            <i class="fas fa-mountain"></i>
                        </div>
                        <div class="message-content">
                            <div class="message-text" id="welcome-message">
                                ${texts.welcomeMsg}
                            </div>
                            <div class="message-time">${texts.time}</div>
                        </div>
                    </div>
                </div>
                
                <!-- Chatbox Input Area -->
                <div class="chatbox-input-area">
                    <form id="chatbox-form" class="chatbox-form">
                        <input 
                            type="text" 
                            id="chatbox-input" 
                            class="chatbox-input" 
                            placeholder="${texts.placeholder}"
                            autocomplete="off"
                        />
                        <button type="submit" id="chatbox-send" class="chatbox-send" aria-label="Send message">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>
                </div>
            </div>
        `;
        
        // Append to body
        document.body.appendChild(chatboxContainer);
        
        // Store references
        this.toggleBtn = document.getElementById('chatbox-toggle');
        this.chatboxWindow = document.getElementById('chatbox-window');
        this.chatboxMessages = document.getElementById('chatbox-messages');
        this.chatboxForm = document.getElementById('chatbox-form');
        this.chatboxInput = document.getElementById('chatbox-input');
        this.closeBtn = document.getElementById('chatbox-close');
        this.langToggleBtn = document.getElementById('chatbox-lang-toggle');
        this.badge = document.getElementById('chatbox-badge');
    }
    
    /**
     * Attach event listeners
     */
    attachEventListeners() {
        // Toggle chatbox
        this.toggleBtn.addEventListener('click', () => {
            this.toggle();
        });
        
        // Close chatbox
        this.closeBtn.addEventListener('click', () => {
            this.close();
        });
        
        // Language toggle
        this.langToggleBtn.addEventListener('click', () => {
            this.toggleLanguage();
        });
        
        // Form submission
        this.chatboxForm.addEventListener('submit', (e) => {
            e.preventDefault();
            this.sendMessage();
        });
        
        // Enter key to send
        this.chatboxInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.sendMessage();
            }
        });
        
        // Close on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.isOpen) {
                this.close();
            }
        });
    }
    
    /**
     * Toggle language between English and Malay
     */
    toggleLanguage() {
        this.language = this.language === 'en' ? 'ms' : 'en';
        localStorage.setItem('hgs_chatbox_language', this.language);
        this.updateLanguageUI();
    }
    
    /**
     * Update UI text based on current language
     */
    updateLanguageUI() {
        const texts = this.getLocalizedText()[this.language];
        
        // Update header
        document.getElementById('chatbox-title').textContent = texts.title;
        document.getElementById('chatbox-subtitle').textContent = texts.subtitle;
        document.getElementById('lang-display').textContent = this.language.toUpperCase();
        
        // Update placeholder
        this.chatboxInput.placeholder = texts.placeholder;
        
        // Update welcome message
        document.getElementById('welcome-message').innerHTML = texts.welcomeMsg;
    }
    
    /**
     * Toggle chatbox open/close
     */
    toggle() {
        this.isOpen = !this.isOpen;
        if (this.isOpen) {
            this.open();
        } else {
            this.close();
        }
    }
    
    /**
     * Open chatbox
     */
    open() {
        this.chatboxWindow.classList.add('open');
        this.toggleBtn.classList.add('active');
        this.badge.style.display = 'none';
        this.chatboxInput.focus();
        
        // Scroll to bottom
        this.scrollToBottom();
    }
    
    /**
     * Close chatbox
     */
    close() {
        this.chatboxWindow.classList.remove('open');
        this.toggleBtn.classList.remove('active');
    }
    
    /**
     * Send message to backend
     */
    async sendMessage() {
        const message = this.chatboxInput.value.trim();
        
        if (!message) {
            return;
        }
        
        // Clear input
        this.chatboxInput.value = '';
        
        // Add user message to chat
        this.addMessage(message, 'user');
        
        // Show typing indicator
        const typingId = this.addTypingIndicator();
        
        try {
            // Send to backend API with language parameter
            const response = await fetch(this.apiEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    message: message,
                    language: this.language
                })
            });
            
            const data = await response.json();
            
            // Remove typing indicator
            this.removeTypingIndicator(typingId);
            
            if (data.success) {
                // Add bot response
                this.addMessage(data.message, 'bot', data.source || 'system');
            } else {
                // Show error message
                this.addMessage(
                    data.message || (this.language === 'ms' 
                        ? 'Maaf, ralat berlaku. Sila cuba lagi.' 
                        : 'Sorry, an error occurred. Please try again.'),
                    'bot',
                    'error'
                );
            }
        } catch (error) {
            // Remove typing indicator
            this.removeTypingIndicator(typingId);
            
            // Show error message
            const errorMsg = this.language === 'ms'
                ? 'Maaf, ralat sambungan berlaku. Sila semak internet anda dan cuba lagi.'
                : 'Sorry, a connection error occurred. Please check your internet and try again.';
            
            this.addMessage(errorMsg, 'bot', 'error');
            
            console.error('Chatbox error:', error);
        }
    }
    
    /**
     * Add message to chat
     */
    addMessage(text, sender, source = 'system') {
        const messageDiv = document.createElement('div');
        messageDiv.className = `chatbox-message ${sender}-message`;
        
        const avatarIcon = sender === 'user' ? 'fa-user' : 'fa-mountain';
        const sourceBadge = source !== 'system' && source !== 'error' 
            ? `<span class="message-source-badge">${source}</span>` 
            : '';
        
        messageDiv.innerHTML = `
            <div class="message-avatar">
                <i class="fas ${avatarIcon}"></i>
            </div>
            <div class="message-content">
                ${sourceBadge}
                <div class="message-text">${this.formatMessage(text)}</div>
                <div class="message-time">${this.getCurrentTime()}</div>
            </div>
        `;
        
        this.chatboxMessages.appendChild(messageDiv);
        
        // Scroll to bottom
        this.scrollToBottom();
        
        // Save to history
        this.chatHistory.push({
            text: text,
            sender: sender,
            source: source,
            timestamp: new Date().toISOString()
        });
        
        // Save to localStorage
        this.saveChatHistory();
    }
    
    /**
     * Format message text (handle line breaks, links, etc.)
     */
    formatMessage(text) {
        // Escape HTML first
        let formatted = text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
        
        // Convert Markdown to HTML (before line breaks!)
        
        // Bold: **text** or __text__
        formatted = formatted.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        formatted = formatted.replace(/__(.+?)__/g, '<strong>$1</strong>');
        
        // Italic: *text* or _text_
        formatted = formatted.replace(/\*(.+?)\*/g, '<em>$1</em>');
        formatted = formatted.replace(/_(.+?)_/g, '<em>$1</em>');
        
        // Code: `code`
        formatted = formatted.replace(/`(.+?)`/g, '<code>$1</code>');
        
        // Bullet lists: - item or • item
        formatted = formatted.replace(/^[-•]\s+(.+)$/gm, '<li>$1</li>');
        formatted = formatted.replace(/(<li>.*<\/li>)/s, '<ul>$1</ul>');
        
        // Numbered lists: 1. item
        formatted = formatted.replace(/^\d+\.\s+(.+)$/gm, '<li>$1</li>');
        
        // Convert line breaks to <br>
        formatted = formatted.replace(/\n/g, '<br>');
        
        // Convert URLs to links
        const urlRegex = /(https?:\/\/[^\s]+)/g;
        formatted = formatted.replace(urlRegex, '<a href="$1" target="_blank" rel="noopener">$1</a>');
        
        return formatted;
    }
    
    /**
     * Add typing indicator
     */
    addTypingIndicator() {
        const typingId = 'typing-' + Date.now();
        const typingDiv = document.createElement('div');
        typingDiv.id = typingId;
        typingDiv.className = 'chatbox-message bot-message typing-indicator';
        
        typingDiv.innerHTML = `
            <div class="message-avatar">
                <i class="fas fa-mountain"></i>
            </div>
            <div class="message-content">
                <div class="message-text">
                    <span class="typing-dots">
                        <span>.</span><span>.</span><span>.</span>
                    </span>
                </div>
            </div>
        `;
        
        this.chatboxMessages.appendChild(typingDiv);
        this.scrollToBottom();
        
        return typingId;
    }
    
    /**
     * Remove typing indicator
     */
    removeTypingIndicator(typingId) {
        const typingElement = document.getElementById(typingId);
        if (typingElement) {
            typingElement.remove();
        }
    }
    
    /**
     * Get current time formatted
     */
    getCurrentTime() {
        const now = new Date();
        const hours = now.getHours().toString().padStart(2, '0');
        const minutes = now.getMinutes().toString().padStart(2, '0');
        return `${hours}:${minutes}`;
    }
    
    /**
     * Scroll chat to bottom
     */
    scrollToBottom() {
        setTimeout(() => {
            this.chatboxMessages.scrollTop = this.chatboxMessages.scrollHeight;
        }, 100);
    }
    
    /**
     * Load chat history from localStorage
     */
    loadChatHistory() {
        try {
            const saved = localStorage.getItem('hgs_chatbox_history');
            if (saved) {
                this.chatHistory = JSON.parse(saved);
            }
        } catch (e) {
            console.error('Error loading chat history:', e);
        }
    }
    
    /**
     * Save chat history to localStorage
     */
    saveChatHistory() {
        try {
            // Keep only last 50 messages
            const recentHistory = this.chatHistory.slice(-50);
            localStorage.setItem('hgs_chatbox_history', JSON.stringify(recentHistory));
        } catch (e) {
            console.error('Error saving chat history:', e);
        }
    }
    
    /**
     * Clear chat history
     */
    clearHistory() {
        this.chatHistory = [];
        localStorage.removeItem('hgs_chatbox_history');
        this.chatboxMessages.innerHTML = '';
    }
}

// Initialize chatbox when DOM is loaded
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.chatbox = new Chatbox();
    });
} else {
    window.chatbox = new Chatbox();
}
