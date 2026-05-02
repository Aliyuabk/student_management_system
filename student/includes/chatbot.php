<?php
// This file will be included in footer.php
// Make sure to include this before the closing body tag
?>

<!-- Chatbot Styles -->
<style>
.chatbot-container {
    position: fixed;
    bottom: 90px;
    right: 20px;
    z-index: 1000;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.chatbot-toggle {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    box-shadow: 0 4px 15px rgba(46, 125, 50, 0.3);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: var(--transition);
    position: relative;
    animation: pulse 2s infinite;
}

.chatbot-toggle:hover {
    transform: scale(1.1);
    box-shadow: 0 6px 20px rgba(46, 125, 50, 0.4);
}

.chatbot-toggle svg {
    width: 30px;
    height: 30px;
    fill: var(--white);
}

.chatbot-toggle .notification-dot {
    position: absolute;
    top: 0;
    right: 0;
    width: 12px;
    height: 12px;
    background: var(--danger-color);
    border-radius: 50%;
    border: 2px solid var(--white);
    animation: pulse 1.5s infinite;
}

.chatbot-panel {
    position: absolute;
    bottom: 70px;
    right: 0;
    width: 380px;
    height: 550px;
    background: var(--white);
    border-radius: 20px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
    display: none;
    flex-direction: column;
    overflow: hidden;
    animation: slideUp 0.3s ease;
    border: 1px solid var(--gray-200);
}

.chatbot-panel.open {
    display: flex;
}

.chatbot-header {
    padding: 20px;
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    color: var(--white);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.chatbot-header h3 {
    font-size: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.chatbot-header h3 svg {
    width: 20px;
    height: 20px;
    fill: currentColor;
}

.chatbot-close {
    background: rgba(255, 255, 255, 0.2);
    border: none;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: var(--transition);
}

.chatbot-close:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: rotate(90deg);
}

.chatbot-close svg {
    width: 18px;
    height: 18px;
    fill: var(--white);
}

.chatbot-messages {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
    background: var(--gray-100);
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.message {
    display: flex;
    gap: 10px;
    max-width: 85%;
    animation: fadeIn 0.3s ease;
}

.message.bot {
    align-self: flex-start;
}

.message.user {
    align-self: flex-end;
    flex-direction: row-reverse;
}

.message-avatar {
    width: 35px;
    height: 35px;
    border-radius: 50%;
    background: var(--primary-color);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.message.user .message-avatar {
    background: var(--text-light);
}

.message-avatar svg {
    width: 18px;
    height: 18px;
    fill: var(--white);
}

.message-content {
    background: var(--white);
    padding: 12px 15px;
    border-radius: 18px;
    box-shadow: var(--shadow-sm);
    position: relative;
    word-wrap: break-word;
}

.message.bot .message-content {
    border-top-left-radius: 5px;
    background: var(--white);
}

.message.user .message-content {
    border-top-right-radius: 5px;
    background: var(--primary-color);
    color: var(--white);
}

.message-content p {
    font-size: 13px;
    line-height: 1.5;
    margin: 0;
}

.message-content .time {
    font-size: 10px;
    color: var(--text-light);
    margin-top: 5px;
    display: block;
}

.message.user .message-content .time {
    color: rgba(255, 255, 255, 0.7);
}

.message-options {
    margin-top: 10px;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.option-btn {
    background: var(--primary-soft);
    border: 1px solid var(--primary-color);
    color: var(--primary-color);
    padding: 8px 12px;
    border-radius: 20px;
    font-size: 12px;
    cursor: pointer;
    transition: var(--transition);
}

.option-btn:hover {
    background: var(--primary-color);
    color: var(--white);
}

.typing-indicator {
    display: flex;
    gap: 4px;
    padding: 12px 15px;
    background: var(--white);
    border-radius: 18px;
    border-top-left-radius: 5px;
    width: fit-content;
}

.typing-indicator span {
    width: 6px;
    height: 6px;
    background: var(--text-light);
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
        opacity: 0.6;
    }
    30% {
        transform: translateY(-5px);
        opacity: 1;
    }
}

.chatbot-input {
    padding: 15px;
    background: var(--white);
    border-top: 1px solid var(--gray-200);
    display: flex;
    gap: 10px;
}

.chatbot-input input {
    flex: 1;
    padding: 12px 15px;
    border: 2px solid var(--gray-300);
    border-radius: 25px;
    font-size: 13px;
    transition: var(--transition);
}

.chatbot-input input:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
}

.chatbot-input button {
    width: 45px;
    height: 45px;
    border-radius: 50%;
    background: var(--primary-color);
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: var(--transition);
}

.chatbot-input button:hover {
    background: var(--primary-dark);
    transform: scale(1.05);
}

.chatbot-input button svg {
    width: 20px;
    height: 20px;
    fill: var(--white);
}

.chatbot-input button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.quick-questions {
    padding: 10px 15px;
    background: var(--white);
    border-top: 1px solid var(--gray-200);
    display: flex;
    gap: 8px;
    overflow-x: auto;
    scrollbar-width: thin;
}

.quick-questions::-webkit-scrollbar {
    height: 4px;
}

.quick-questions::-webkit-scrollbar-thumb {
    background: var(--gray-400);
    border-radius: 4px;
}

.quick-question {
    background: var(--gray-100);
    border: 1px solid var(--gray-300);
    color: var(--text-dark);
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 11px;
    white-space: nowrap;
    cursor: pointer;
    transition: var(--transition);
}

.quick-question:hover {
    background: var(--primary-soft);
    border-color: var(--primary-color);
    color: var(--primary-color);
}

/* Loading animation for data fetching */
.spinner {
    display: inline-block;
    width: 12px;
    height: 12px;
    border: 2px solid rgba(255,255,255,.3);
    border-radius: 50%;
    border-top-color: #fff;
    animation: spin 0.8s linear infinite;
    margin-left: 8px;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

@keyframes pulse {
    0% {
        box-shadow: 0 0 0 0 rgba(46, 125, 50, 0.4);
    }
    70% {
        box-shadow: 0 0 0 10px rgba(46, 125, 50, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(46, 125, 50, 0);
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

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@media (max-width: 480px) {
    .chatbot-panel {
        width: 320px;
        height: 500px;
        right: 0;
    }
    
    .message {
        max-width: 90%;
    }
}
</style>

<!-- Chatbot HTML -->
<div class="chatbot-container">
    <div class="chatbot-toggle" id="chatbotToggle" onclick="toggleChatbot()">
        <svg viewBox="0 0 24 24">
            <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
        </svg>
        <span class="notification-dot" id="chatbotNotification" style="display: none;"></span>
    </div>

    <div class="chatbot-panel" id="chatbotPanel">
        <div class="chatbot-header">
            <h3>
                <svg viewBox="0 0 24 24">
                    <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
                </svg>
                Student Assistant
            </h3>
            <button class="chatbot-close" onclick="toggleChatbot()">
                <svg viewBox="0 0 24 24">
                    <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                </svg>
            </button>
        </div>

        <div class="chatbot-messages" id="chatbotMessages">
            <!-- Welcome Message -->
            <div class="message bot">
                <div class="message-avatar">
                    <svg viewBox="0 0 24 24">
                        <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
                    </svg>
                </div>
                <div class="message-content">
                    <p>👋 Hi! I'm your student assistant. I can help you with:</p>
                    <div class="message-options">
                        <button class="option-btn" onclick="askQuestion('fees')">💰 Fee details</button>
                        <button class="option-btn" onclick="askQuestion('courses')">📚 My courses</button>
                        <button class="option-btn" onclick="askQuestion('results')">📊 My results</button>
                        <button class="option-btn" onclick="askQuestion('deadlines')">📅 Deadlines</button>
                    </div>
                    <span class="time">Just now</span>
                </div>
            </div>
        </div>

        <div class="quick-questions" id="quickQuestions">
            <span class="quick-question" onclick="askQuickQuestion('What are my outstanding fees?')">💰 Outstanding fees</span>
            <span class="quick-question" onclick="askQuickQuestion('Show my registered courses')">📚 My courses</span>
            <span class="quick-question" onclick="askQuickQuestion('When is the registration deadline?')">📅 Deadlines</span>
            <span class="quick-question" onclick="askQuickQuestion('What is my CGPA?')">📊 My CGPA</span>
            <span class="quick-question" onclick="askQuickQuestion('What is my student information?')">👤 My profile</span>
            <span class="quick-question" onclick="askQuickQuestion('Show my payment history')">💳 Payments</span>
        </div>

        <div class="chatbot-input">
            <input type="text" id="chatbotInput" placeholder="Type your question..." onkeypress="handleKeyPress(event)">
            <button onclick="sendMessage()" id="sendButton">
                <svg viewBox="0 0 24 24">
                    <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                </svg>
            </button>
        </div>
    </div>
</div>

<!-- Chatbot JavaScript -->
<script>
let chatbotOpen = false;
let isTyping = false;

// Toggle chatbot panel
function toggleChatbot() {
    const panel = document.getElementById('chatbotPanel');
    const notification = document.getElementById('chatbotNotification');
    
    chatbotOpen = !chatbotOpen;
    
    if(chatbotOpen) {
        panel.classList.add('open');
        notification.style.display = 'none';
        // Mark messages as read
        localStorage.setItem('chatbotLastSeen', Date.now());
    } else {
        panel.classList.remove('open');
    }
}

// Handle enter key
function handleKeyPress(event) {
    if(event.key === 'Enter') {
        sendMessage();
    }
}

// Send user message
function sendMessage() {
    const input = document.getElementById('chatbotInput');
    const message = input.value.trim();
    
    if(message === '' || isTyping) return;
    
    // Add user message
    addMessage(message, 'user');
    input.value = '';
    
    // Show typing indicator
    showTypingIndicator();
    
    // Get bot response
    setTimeout(() => {
        getBotResponse(message);
    }, 500);
}

// Add message to chat
function addMessage(text, sender) {
    const messagesDiv = document.getElementById('chatbotMessages');
    const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    
    const messageDiv = document.createElement('div');
    messageDiv.className = `message ${sender}`;
    
    const avatar = sender === 'bot' 
        ? '<div class="message-avatar"><svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg></div>'
        : '<div class="message-avatar"><svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg></div>';
    
    messageDiv.innerHTML = `
        ${avatar}
        <div class="message-content">
            <p>${formatMessage(text)}</p>
            <span class="time">${time}</span>
        </div>
    `;
    
    messagesDiv.appendChild(messageDiv);
    messagesDiv.scrollTop = messagesDiv.scrollHeight;
}

// Format message with line breaks
function formatMessage(text) {
    return text.replace(/\n/g, '<br>');
}

// Show typing indicator
function showTypingIndicator() {
    isTyping = true;
    document.getElementById('sendButton').disabled = true;
    
    const messagesDiv = document.getElementById('chatbotMessages');
    const typingDiv = document.createElement('div');
    typingDiv.className = 'message bot';
    typingDiv.id = 'typingIndicator';
    typingDiv.innerHTML = `
        <div class="message-avatar">
            <svg viewBox="0 0 24 24">
                <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
            </svg>
        </div>
        <div class="typing-indicator">
            <span></span>
            <span></span>
            <span></span>
        </div>
    `;
    
    messagesDiv.appendChild(typingDiv);
    messagesDiv.scrollTop = messagesDiv.scrollHeight;
}

// Hide typing indicator
function hideTypingIndicator() {
    const typingIndicator = document.getElementById('typingIndicator');
    if(typingIndicator) {
        typingIndicator.remove();
    }
    isTyping = false;
    document.getElementById('sendButton').disabled = false;
}

// Get bot response from server
function getBotResponse(userMessage) {
    fetch('chatbot-api.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({message: userMessage})
    })
    .then(response => response.json())
    .then(data => {
        hideTypingIndicator();
        
        if(data.response) {
            addMessage(data.response, 'bot');
            
            // Add options if available
            if(data.options && data.options.length > 0) {
                addOptions(data.options);
            }
        } else {
            addMessage("I'm sorry, I couldn't understand that. Please try rephrasing your question or use the quick options below.", 'bot');
        }
    })
    .catch(error => {
        hideTypingIndicator();
        addMessage("Sorry, I'm having trouble connecting. Please try again later.", 'bot');
        console.error('Error:', error);
    });
}

// Add option buttons to last bot message
function addOptions(options) {
    const messagesDiv = document.getElementById('chatbotMessages');
    const lastMessage = messagesDiv.lastChild;
    
    if(lastMessage && lastMessage.classList.contains('message') && lastMessage.classList.contains('bot')) {
        const optionsDiv = document.createElement('div');
        optionsDiv.className = 'message-options';
        
        options.forEach(option => {
            const btn = document.createElement('button');
            btn.className = 'option-btn';
            btn.textContent = option;
            btn.onclick = () => askQuestion(option.toLowerCase());
            optionsDiv.appendChild(btn);
        });
        
        lastMessage.querySelector('.message-content').appendChild(optionsDiv);
    }
}

// Handle quick questions
function askQuickQuestion(question) {
    addMessage(question, 'user');
    showTypingIndicator();
    
    setTimeout(() => {
        getBotResponse(question);
    }, 300);
}

// Handle predefined questions
function askQuestion(type) {
    let question = '';
    switch(type) {
        case 'fees':
            question = 'What are my outstanding fees?';
            break;
        case 'courses':
            question = 'Show my registered courses';
            break;
        case 'results':
            question = 'Show my recent results';
            break;
        case 'deadlines':
            question = 'What are the important deadlines?';
            break;
        default:
            question = type;
    }
    
    askQuickQuestion(question);
}

// Check for unread messages (show notification if new messages)
function checkUnreadMessages() {
    const lastSeen = localStorage.getItem('chatbotLastSeen') || 0;
    const now = Date.now();
    
    // Show notification if chatbot hasn't been opened in last hour
    if(now - lastSeen > 3600000 && !chatbotOpen) {
        document.getElementById('chatbotNotification').style.display = 'block';
    }
}

// Check for unread messages every minute
setInterval(checkUnreadMessages, 60000);

// Initial check
setTimeout(checkUnreadMessages, 5000);
</script>