<?php
// chat_widget.php - Include this in your footer
?>
<style>
/* AI Chat Widget Styles */
.ai-chat-widget {
    position: fixed;
    bottom: 20px;
    right: 20px;
    z-index: 9999;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
}

.ai-chat-button {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 50%;
    cursor: pointer;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    animation: pulse 2s infinite;
}

.ai-chat-button:hover {
    transform: scale(1.1);
    box-shadow: 0 6px 20px rgba(0,0,0,0.3);
}

.ai-chat-button svg {
    width: 32px;
    height: 32px;
}

.ai-chat-container {
    position: absolute;
    bottom: 80px;
    right: 0;
    width: 380px;
    height: 500px;
    background: white;
    border-radius: 15px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    display: none;
    flex-direction: column;
    overflow: hidden;
    animation: slideUp 0.3s ease;
}

.ai-chat-container.open {
    display: flex;
}

.ai-chat-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 15px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.ai-chat-header h3 {
    margin: 0;
    font-size: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.ai-chat-header h3 svg {
    width: 20px;
    height: 20px;
}

.ai-chat-close {
    background: none;
    border: none;
    color: white;
    font-size: 24px;
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: background 0.3s;
}

.ai-chat-close:hover {
    background: rgba(255,255,255,0.2);
}

.ai-chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 15px;
    background: #f5f5f5;
}

.message {
    margin-bottom: 15px;
    display: flex;
    flex-direction: column;
}

.message.user {
    align-items: flex-end;
}

.message.bot {
    align-items: flex-start;
}

.message-content {
    max-width: 80%;
    padding: 10px 15px;
    border-radius: 18px;
    word-wrap: break-word;
    white-space: pre-wrap;
    line-height: 1.4;
    font-size: 14px;
}

.message.user .message-content {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-bottom-right-radius: 5px;
}

.message.bot .message-content {
    background: white;
    color: #333;
    border-bottom-left-radius: 5px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.1);
}

.message-time {
    font-size: 10px;
    color: #999;
    margin-top: 5px;
    margin-left: 10px;
    margin-right: 10px;
}

.typing-indicator {
    display: flex;
    padding: 10px 15px;
    background: white;
    border-radius: 18px;
    border-bottom-left-radius: 5px;
    align-items: center;
    gap: 5px;
}

.typing-indicator span {
    width: 8px;
    height: 8px;
    background: #999;
    border-radius: 50%;
    animation: typing 1.4s infinite;
}

.typing-indicator span:nth-child(2) {
    animation-delay: 0.2s;
}

.typing-indicator span:nth-child(3) {
    animation-delay: 0.4s;
}

@keyframes typing {
    0%, 60%, 100% {
        transform: translateY(0);
        opacity: 0.4;
    }
    30% {
        transform: translateY(-10px);
        opacity: 1;
    }
}

.ai-chat-input-area {
    padding: 15px;
    background: white;
    border-top: 1px solid #e0e0e0;
    display: flex;
    gap: 10px;
}

.ai-chat-input {
    flex: 1;
    padding: 10px 15px;
    border: 1px solid #e0e0e0;
    border-radius: 25px;
    outline: none;
    font-size: 14px;
    transition: border-color 0.3s;
}

.ai-chat-input:focus {
    border-color: #667eea;
}

.ai-chat-send {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.3s;
}

.ai-chat-send:hover {
    transform: scale(1.05);
}

.ai-chat-send svg {
    width: 18px;
    height: 18px;
}

.ai-chat-send:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

@keyframes pulse {
    0% {
        box-shadow: 0 0 0 0 rgba(102, 126, 234, 0.4);
    }
    70% {
        box-shadow: 0 0 0 15px rgba(102, 126, 234, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(102, 126, 234, 0);
    }
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Mobile Responsive */
@media (max-width: 480px) {
    .ai-chat-container {
        width: calc(100vw - 40px);
        right: 0;
        bottom: 80px;
    }
}
</style>

<div class="ai-chat-widget">
    <div class="ai-chat-button" id="aiChatButton">
        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            <path d="M8 10h.01"/>
            <path d="M12 10h.01"/>
            <path d="M16 10h.01"/>
        </svg>
    </div>
    
    <div class="ai-chat-container" id="aiChatContainer">
        <div class="ai-chat-header">
            <h3>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2a10 10 0 0 1 10 10c0 5.5-4.5 10-10 10S2 17.5 2 12 6.5 2 12 2z"/>
                    <path d="M8 9l4-4 4 4"/>
                    <path d="M12 5v10"/>
                </svg>
                AI Academic Assistant
            </h3>
            <button class="ai-chat-close" id="aiChatClose">×</button>
        </div>
        
        <div class="ai-chat-messages" id="aiChatMessages">
            <div class="message bot">
                <div class="message-content">
                    Hello! 👋 I'm your AI Academic Assistant.<br><br>
                    I can help you with:
                    • Course registration 📚
                    • Results & GPA 📊
                    • Fees & payments 💰
                    • Hostel information 🏠
                    • Academic calendar 📅
                    • And much more!
                    <br><br>
                    What would you like to know?
                </div>
                <div class="message-time">Just now</div>
            </div>
        </div>
        
        <div class="ai-chat-input-area">
            <input type="text" class="ai-chat-input" id="aiChatInput" placeholder="Type your question here..." autocomplete="off">
            <button class="ai-chat-send" id="aiChatSend">
                <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                    <line x1="22" y1="2" x2="11" y2="13"/>
                    <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                </svg>
            </button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const chatButton = document.getElementById('aiChatButton');
    const chatContainer = document.getElementById('aiChatContainer');
    const chatClose = document.getElementById('aiChatClose');
    const chatInput = document.getElementById('aiChatInput');
    const chatSend = document.getElementById('aiChatSend');
    const chatMessages = document.getElementById('aiChatMessages');
    
    let isWaiting = false;
    
    // Open/Close chat
    chatButton.addEventListener('click', function() {
        chatContainer.classList.add('open');
    });
    
    chatClose.addEventListener('click', function() {
        chatContainer.classList.remove('open');
    });
    
    // Send message function
    function sendMessage() {
        const message = chatInput.value.trim();
        if (message === '' || isWaiting) return;
        
        // Add user message to chat
        addMessage(message, 'user');
        chatInput.value = '';
        
        // Show typing indicator
        showTypingIndicator();
        isWaiting = true;
        chatSend.disabled = true;
        
        // Send to server
        fetch('ai_assistant.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: 'message=' + encodeURIComponent(message)
        })
        .then(response => response.json())
        .then(data => {
            removeTypingIndicator();
            if (data.error) {
                addMessage(data.error, 'bot');
            } else {
                addMessage(data.response, 'bot');
            }
            isWaiting = false;
            chatSend.disabled = false;
        })
        .catch(error => {
            removeTypingIndicator();
            addMessage('Sorry, I encountered an error. Please try again.', 'bot');
            isWaiting = false;
            chatSend.disabled = false;
        });
    }
    
    // Add message to chat
    function addMessage(text, sender) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${sender}`;
        
        const contentDiv = document.createElement('div');
        contentDiv.className = 'message-content';
        contentDiv.innerHTML = text.replace(/\n/g, '<br>');
        
        const timeDiv = document.createElement('div');
        timeDiv.className = 'message-time';
        timeDiv.textContent = getCurrentTime();
        
        messageDiv.appendChild(contentDiv);
        messageDiv.appendChild(timeDiv);
        
        chatMessages.appendChild(messageDiv);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
    
    // Show typing indicator
    function showTypingIndicator() {
        const typingDiv = document.createElement('div');
        typingDiv.className = 'message bot';
        typingDiv.id = 'typingIndicator';
        
        const indicatorDiv = document.createElement('div');
        indicatorDiv.className = 'typing-indicator';
        indicatorDiv.innerHTML = '<span></span><span></span><span></span>';
        
        typingDiv.appendChild(indicatorDiv);
        chatMessages.appendChild(typingDiv);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
    
    // Remove typing indicator
    function removeTypingIndicator() {
        const typingIndicator = document.getElementById('typingIndicator');
        if (typingIndicator) {
            typingIndicator.remove();
        }
    }
    
    // Get current time
    function getCurrentTime() {
        const now = new Date();
        return now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    
    // Event listeners
    chatSend.addEventListener('click', sendMessage);
    chatInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            sendMessage();
        }
    });
});
</script>