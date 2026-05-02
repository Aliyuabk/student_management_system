<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['student_id'])) {
    header('Location: login.php');
    exit();
}

// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_password = '';
$db_name = 'student_portal_db';

// Initialize variables
$student_data = [];
$messages = [];
$support_agents = [];
$current_chat_id = null;
$unread_messages = 0;

// Process chat actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['send_message']) && isset($_POST['message']) && trim($_POST['message']) !== '') {
        $message = trim($_POST['message']);
        $chat_id = $_POST['chat_id'] ?? 0;
        
        // In a real application, you would save this to the database
        // For demo purposes, we'll simulate it
        $new_message = [
            'id' => uniqid(),
            'chat_id' => $chat_id,
            'sender_id' => $_SESSION['student_id'],
            'sender_type' => 'student',
            'sender_name' => $_SESSION['student_name'] ?? 'Student',
            'message' => htmlspecialchars($message),
            'timestamp' => date('Y-m-d H:i:s'),
            'is_read' => 0
        ];
        
        // Simulate auto-response
        $auto_response = [
            'id' => uniqid(),
            'chat_id' => $chat_id,
            'sender_id' => 'SUPPORT001',
            'sender_type' => 'agent',
            'sender_name' => 'Support Agent',
            'message' => 'Thank you for your message. Our support team will get back to you shortly. For urgent matters, please call our hotline at 08120000003.',
            'timestamp' => date('Y-m-d H:i:s', strtotime('+1 minute')),
            'is_read' => 0
        ];
        
        $_SESSION['chat_messages'][] = $new_message;
        $_SESSION['chat_messages'][] = $auto_response;
    }
    
    if (isset($_POST['start_new_chat'])) {
        $subject = $_POST['subject'] ?? 'General Inquiry';
        $category = $_POST['category'] ?? 'general';
        
        // Create new chat session
        $new_chat = [
            'chat_id' => uniqid(),
            'student_id' => $_SESSION['student_id'],
            'subject' => htmlspecialchars($subject),
            'category' => $category,
            'status' => 'open',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        $_SESSION['current_chat'] = $new_chat['chat_id'];
        $_SESSION['chat_sessions'][] = $new_chat;
        
        // Auto welcome message
        $welcome_message = [
            'id' => uniqid(),
            'chat_id' => $new_chat['chat_id'],
            'sender_id' => 'SUPPORT001',
            'sender_type' => 'system',
            'sender_name' => 'Support System',
            'message' => 'Welcome to Al-Qalam University Support Chat! A support agent will be with you shortly. Please describe your issue in detail.',
            'timestamp' => date('Y-m-d H:i:s'),
            'is_read' => 1
        ];
        
        $_SESSION['chat_messages'][] = $welcome_message;
    }
    
    if (isset($_POST['close_chat'])) {
        $chat_id = $_POST['chat_id'];
        
        // Update chat status
        if (isset($_SESSION['chat_sessions'])) {
            foreach ($_SESSION['chat_sessions'] as &$chat) {
                if ($chat['chat_id'] == $chat_id) {
                    $chat['status'] = 'closed';
                    $chat['updated_at'] = date('Y-m-d H:i:s');
                    break;
                }
            }
        }
        
        // Add closing message
        $closing_message = [
            'id' => uniqid(),
            'chat_id' => $chat_id,
            'sender_id' => 'SUPPORT001',
            'sender_type' => 'system',
            'sender_name' => 'Support System',
            'message' => 'This chat has been marked as resolved. Thank you for contacting support.',
            'timestamp' => date('Y-m-d H:i:s'),
            'is_read' => 1
        ];
        
        $_SESSION['chat_messages'][] = $closing_message;
    }
}

// Initialize session variables if not set
if (!isset($_SESSION['chat_messages'])) {
    $_SESSION['chat_messages'] = [];
}

if (!isset($_SESSION['chat_sessions'])) {
    $_SESSION['chat_sessions'] = [];
    
    // Create a sample chat session
    $sample_chat = [
        'chat_id' => 'CHAT001',
        'student_id' => $_SESSION['student_id'],
        'subject' => 'Portal Access Issue',
        'category' => 'technical',
        'status' => 'open',
        'created_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
        'updated_at' => date('Y-m-d H:i:s', strtotime('-1 hour'))
    ];
    
    $_SESSION['chat_sessions'][] = $sample_chat;
    $_SESSION['current_chat'] = 'CHAT001';
    
    // Add sample messages
    $sample_messages = [
        [
            'id' => 'MSG001',
            'chat_id' => 'CHAT001',
            'sender_id' => $_SESSION['student_id'],
            'sender_type' => 'student',
            'sender_name' => $_SESSION['student_name'] ?? 'Student',
            'message' => 'Hello, I\'m having trouble accessing my portal.',
            'timestamp' => date('Y-m-d H:i:s', strtotime('-2 days')),
            'is_read' => 1
        ],
        [
            'id' => 'MSG002',
            'chat_id' => 'CHAT001',
            'sender_id' => 'SUPPORT001',
            'sender_type' => 'agent',
            'sender_name' => 'Sarah Johnson',
            'message' => 'Hello! I\'m Sarah from the IT support team. Could you please describe the issue you\'re facing?',
            'timestamp' => date('Y-m-d H:i:s', strtotime('-2 days +5 minutes')),
            'is_read' => 1
        ],
        [
            'id' => 'MSG003',
            'chat_id' => 'CHAT001',
            'sender_id' => $_SESSION['student_id'],
            'sender_type' => 'student',
            'sender_name' => $_SESSION['student_name'] ?? 'Student',
            'message' => 'When I try to login, it says "Invalid credentials" even though I\'m sure my password is correct.',
            'timestamp' => date('Y-m-d H:i:s', strtotime('-2 days +10 minutes')),
            'is_read' => 1
        ],
        [
            'id' => 'MSG004',
            'chat_id' => 'CHAT001',
            'sender_id' => 'SUPPORT001',
            'sender_type' => 'agent',
            'sender_name' => 'Sarah Johnson',
            'message' => 'I can help with that. Have you tried using the "Forgot Password" feature? If that doesn\'t work, I can reset your password manually.',
            'timestamp' => date('Y-m-d H:i:s', strtotime('-2 days +15 minutes')),
            'is_read' => 1
        ]
    ];
    
    foreach ($sample_messages as $msg) {
        $_SESSION['chat_messages'][] = $msg;
    }
}

// Get current chat
$current_chat_id = $_SESSION['current_chat'] ?? null;

// Filter messages for current chat
if ($current_chat_id) {
    foreach ($_SESSION['chat_messages'] as $msg) {
        if ($msg['chat_id'] == $current_chat_id) {
            $messages[] = $msg;
        }
    }
}

// Get chat sessions
$chat_sessions = $_SESSION['chat_sessions'] ?? [];

// Get current chat details
$current_chat = null;
foreach ($chat_sessions as $chat) {
    if ($chat['chat_id'] == $current_chat_id) {
        $current_chat = $chat;
        break;
    }
}

try {
    // Create database connection
    $conn = new mysqli($db_host, $db_user, $db_password, $db_name);
    
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }
    
    // Get student ID from session
    $student_id = $_SESSION['student_id'];
    
    // Fetch student information
    $sql = "SELECT s.*, d.department_name, p.program_name 
            FROM students s
            LEFT JOIN departments d ON s.department_id = d.department_id
            LEFT JOIN programs p ON s.program_id = p.program_id
            WHERE s.student_id = ?";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Database query preparation failed: " . $conn->error);
    }
    
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $student_data = $result->fetch_assoc();
        $stmt->close();
    } else {
        throw new Exception("Student record not found.");
    }
    
    $conn->close();
    
} catch (Exception $e) {
    // Fallback data for demo
    $student_data = [
        'first_name' => $_SESSION['student_name'] ?? 'Student',
        'last_name' => 'User',
        'matric_number' => $_SESSION['matric_number'] ?? 'CSC/2023/001',
        'current_level' => 300,
        'department_name' => 'Computer Science',
        'program_name' => 'B.Sc. Computer Science'
    ];
}

// Support agents data
$support_agents = [
    [
        'id' => 'SUPPORT001',
        'name' => 'Sarah Johnson',
        'department' => 'ICT Support',
        'specialization' => 'Technical Issues',
        'status' => 'online',
        'avatar_color' => '#4361ee',
        'response_time' => 'Usually replies in 5 minutes'
    ],
    [
        'id' => 'SUPPORT002',
        'name' => 'Michael Adebayo',
        'department' => 'Academic Support',
        'specialization' => 'Course Registration',
        'status' => 'online',
        'avatar_color' => '#10B981',
        'response_time' => 'Usually replies in 10 minutes'
    ],
    [
        'id' => 'SUPPORT003',
        'name' => 'Grace Williams',
        'department' => 'Financial Support',
        'specialization' => 'Fee Payments',
        'status' => 'away',
        'avatar_color' => '#F59E0B',
        'response_time' => 'Usually replies in 15 minutes'
    ],
    [
        'id' => 'SUPPORT004',
        'name' => 'David Okafor',
        'department' => 'Hostel Management',
        'specialization' => 'Accommodation',
        'status' => 'offline',
        'avatar_color' => '#EF4444',
        'response_time' => 'Usually replies in 30 minutes'
    ]
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat Support | Al-Qalam University</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --primary-light: #4895ef;
            --secondary: #7209b7;
            --success: #10B981;
            --warning: #F59E0B;
            --danger: #EF4444;
            --info: #3B82F6;
            --light: #f8f9fa;
            --dark: #1f2937;
            --dark-gray: #374151;
            --gray: #6c757d;
            --light-gray: #e5e7eb;
            --border-radius: 12px;
            --border-radius-sm: 8px;
            --chat-student-bg: #4361ee;
            --chat-agent-bg: #f1f5f9;
            --chat-system-bg: #fef3c7;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background-color: #f5f7fb;
            color: var(--dark);
            min-height: 100vh;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        /* Header */
        .header {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-left h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 5px;
        }
        
        .header-left p {
            color: var(--gray);
            font-size: 15px;
        }
        
        .header-right {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        /* Buttons */
        .btn {
            padding: 12px 24px;
            border-radius: var(--border-radius-sm);
            border: none;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            text-decoration: none;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
        }
        
        .btn-secondary {
            background: white;
            color: var(--primary);
            border: 2px solid var(--primary);
        }
        
        .btn-secondary:hover {
            background: var(--primary);
            color: white;
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-success:hover {
            background: #0da271;
        }
        
        .btn-warning {
            background: var(--warning);
            color: white;
        }
        
        .btn-warning:hover {
            background: #d97706;
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        
        .btn-danger:hover {
            background: #dc2626;
        }
        
        .btn-small {
            padding: 8px 16px;
            font-size: 13px;
        }
        
        /* Student Info Banner */
        .student-banner {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-radius: var(--border-radius);
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
        }
        
        .student-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
        }
        
        .info-label {
            font-size: 14px;
            opacity: 0.8;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 18px;
            font-weight: 600;
        }
        
        /* Chat Container */
        .chat-container {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 20px;
            margin-bottom: 30px;
            height: 600px;
        }
        
        /* Chats Sidebar */
        .chats-sidebar {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            display: flex;
            flex-direction: column;
        }
        
        .chats-header {
            padding: 20px;
            border-bottom: 2px solid var(--light-gray);
        }
        
        .chats-header h3 {
            font-size: 18px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }
        
        .chats-header p {
            color: var(--gray);
            font-size: 14px;
        }
        
        .chats-list {
            flex: 1;
            overflow-y: auto;
            padding: 10px;
        }
        
        .chat-item {
            padding: 15px;
            border-radius: var(--border-radius-sm);
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.3s;
            border: 1px solid transparent;
        }
        
        .chat-item:hover {
            background: var(--light);
            border-color: var(--light-gray);
        }
        
        .chat-item.active {
            background: rgba(67, 97, 238, 0.1);
            border-color: var(--primary);
        }
        
        .chat-subject {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .chat-subject .badge {
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .badge-open {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .badge-closed {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray);
        }
        
        .chat-preview {
            font-size: 13px;
            color: var(--gray);
            margin-bottom: 8px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .chat-meta {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: var(--gray);
        }
        
        .chat-unread {
            background: var(--primary);
            color: white;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 600;
        }
        
        /* New Chat Button */
        .new-chat-btn {
            margin: 20px;
            padding: 12px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--border-radius-sm);
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .new-chat-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        /* Chat Main Area */
        .chat-main {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            display: flex;
            flex-direction: column;
        }
        
        .chat-header {
            padding: 20px;
            border-bottom: 2px solid var(--light-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .chat-header-info h3 {
            font-size: 18px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }
        
        .chat-header-info p {
            color: var(--gray);
            font-size: 14px;
        }
        
        .chat-actions {
            display: flex;
            gap: 10px;
        }
        
        /* Chat Messages Area */
        .chat-messages {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            background: #fafafa;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .message {
            max-width: 70%;
            padding: 12px 16px;
            border-radius: 18px;
            position: relative;
            word-wrap: break-word;
        }
        
        .message-student {
            align-self: flex-end;
            background: var(--chat-student-bg);
            color: white;
            border-bottom-right-radius: 4px;
        }
        
        .message-agent {
            align-self: flex-start;
            background: var(--chat-agent-bg);
            color: var(--dark);
            border-bottom-left-radius: 4px;
        }
        
        .message-system {
            align-self: center;
            background: var(--chat-system-bg);
            color: #92400e;
            border-radius: var(--border-radius-sm);
            max-width: 90%;
            font-size: 14px;
            text-align: center;
        }
        
        .message-content {
            margin-bottom: 5px;
            line-height: 1.4;
        }
        
        .message-meta {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            opacity: 0.7;
        }
        
        .message-sender {
            font-weight: 600;
            margin-bottom: 4px;
            font-size: 13px;
        }
        
        /* Chat Input Area */
        .chat-input-area {
            padding: 20px;
            border-top: 2px solid var(--light-gray);
        }
        
        .chat-input-form {
            display: flex;
            gap: 10px;
        }
        
        .chat-input {
            flex: 1;
            padding: 12px 16px;
            border: 1px solid var(--light-gray);
            border-radius: var(--border-radius-sm);
            font-size: 14px;
            resize: none;
            min-height: 50px;
            max-height: 120px;
        }
        
        .chat-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
        }
        
        .send-btn {
            padding: 0 20px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 50px;
        }
        
        .send-btn:hover {
            background: var(--primary-dark);
        }
        
        .send-btn:disabled {
            background: var(--gray);
            cursor: not-allowed;
        }
        
        /* Support Agents */
        .agents-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        
        .section-header {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--light-gray);
        }
        
        .section-header h2 {
            font-size: 22px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 10px;
        }
        
        .section-header p {
            color: var(--gray);
        }
        
        .agents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .agent-card {
            background: white;
            border-radius: var(--border-radius-sm);
            padding: 20px;
            border: 1px solid var(--light-gray);
            transition: all 0.3s;
        }
        
        .agent-card:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            transform: translateY(-3px);
        }
        
        .agent-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .agent-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 18px;
        }
        
        .agent-info h4 {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 5px;
        }
        
        .agent-info p {
            font-size: 13px;
            color: var(--gray);
        }
        
        .agent-status {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            margin-top: 5px;
        }
        
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        
        .status-online {
            background: var(--success);
        }
        
        .status-away {
            background: var(--warning);
        }
        
        .status-offline {
            background: var(--gray);
        }
        
        .agent-details {
            font-size: 14px;
            color: var(--gray);
            line-height: 1.5;
        }
        
        .agent-detail {
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .agent-detail i {
            color: var(--primary);
            width: 16px;
        }
        
        /* New Chat Modal */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: var(--border-radius);
            padding: 30px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        }
        
        .modal-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-gray);
        }
        
        .modal-header h3 {
            font-size: 20px;
            font-weight: 700;
            color: var(--dark);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
            font-size: 15px;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 16px;
            font-size: 15px;
            border: 1px solid #e1e5e9;
            border-radius: 8px;
            background-color: white;
            color: var(--dark);
            transition: all 0.2s ease;
            line-height: 1.5;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
        }
        
        .form-select {
            width: 100%;
            padding: 12px 16px;
            font-size: 15px;
            border: 1px solid #e1e5e9;
            border-radius: 8px;
            background-color: white;
            color: var(--dark);
            appearance: none;
            cursor: pointer;
        }
        
        .modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 25px;
        }
        
        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
        }
        
        .quick-action {
            padding: 8px 16px;
            background: var(--light);
            border: 1px solid var(--light-gray);
            border-radius: 20px;
            font-size: 13px;
            color: var(--dark);
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .quick-action:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        /* Typing Indicator */
        .typing-indicator {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 10px 15px;
            background: var(--chat-agent-bg);
            border-radius: 18px;
            align-self: flex-start;
            margin-bottom: 10px;
            border-bottom-left-radius: 4px;
        }
        
        .typing-dot {
            width: 8px;
            height: 8px;
            background: var(--gray);
            border-radius: 50%;
            animation: typing 1.4s infinite ease-in-out;
        }
        
        .typing-dot:nth-child(1) {
            animation-delay: -0.32s;
        }
        
        .typing-dot:nth-child(2) {
            animation-delay: -0.16s;
        }
        
        @keyframes typing {
            0%, 80%, 100% {
                transform: scale(0);
                opacity: 0.5;
            }
            40% {
                transform: scale(1);
                opacity: 1;
            }
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: var(--gray);
        }
        
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            color: var(--light-gray);
        }
        
        /* Responsive Design */
        @media (max-width: 1024px) {
            .chat-container {
                grid-template-columns: 1fr;
                height: auto;
            }
            
            .chats-sidebar {
                height: 300px;
            }
        }
        
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }
            
            .header-right {
                width: 100%;
                justify-content: center;
                flex-wrap: wrap;
            }
            
            .chat-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .chat-actions {
                width: 100%;
                justify-content: center;
            }
            
            .message {
                max-width: 85%;
            }
            
            .agents-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media print {
            .header-right,
            .chat-input-area,
            .new-chat-btn,
            .btn,
            .modal {
                display: none;
            }
        }
    </style>
</head>

<body>
     <?php include 'includes/preloader.php'; ?>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                <h1>Chat Support</h1>
                <p>Get real-time help from our support team</p>
            </div>
            <div class="header-right">
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
                <a href="student-support.php" class="btn btn-secondary">
                    <i class="fas fa-question-circle"></i> Support Hub
                </a>
                <button onclick="window.print()" class="btn btn-primary">
                    <i class="fas fa-print"></i> Print Chat
                </button>
            </div>
        </div>

        <!-- Student Info Banner -->
        <div class="student-banner">
            <div class="student-info-grid">
                <div class="info-item">
                    <div class="info-label">Student ID</div>
                    <div class="info-value"><?php echo htmlspecialchars($student_data['matric_number'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Name</div>
                    <div class="info-value"><?php echo htmlspecialchars(($student_data['first_name'] ?? '') . ' ' . ($student_data['last_name'] ?? '')); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Department</div>
                    <div class="info-value"><?php echo htmlspecialchars($student_data['department_name'] ?? 'Not Available'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Support Status</div>
                    <div class="info-value">
                        <?php if ($current_chat && $current_chat['status'] === 'open'): ?>
                            <span style="color: var(--success);">
                                <i class="fas fa-circle" style="font-size: 10px;"></i> Active Support
                            </span>
                        <?php else: ?>
                            <span style="color: var(--gray);">
                                <i class="fas fa-circle" style="font-size: 10px;"></i> No Active Chats
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Chat Container -->
        <div class="chat-container">
            <!-- Chats Sidebar -->
            <div class="chats-sidebar">
                <div class="chats-header">
                    <h3>Your Conversations</h3>
                    <p><?php echo count($chat_sessions); ?> chat(s) total</p>
                </div>
                
                <div class="chats-list" id="chatsList">
                    <?php if (empty($chat_sessions)): ?>
                        <div class="empty-state" style="padding: 40px 20px;">
                            <i class="fas fa-comments"></i>
                            <p>No conversations yet</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($chat_sessions as $chat): 
                            // Get last message for preview
                            $last_message = '';
                            $last_time = '';
                            foreach (array_reverse($_SESSION['chat_messages']) as $msg) {
                                if ($msg['chat_id'] == $chat['chat_id']) {
                                    $last_message = $msg['message'];
                                    $last_time = $msg['timestamp'];
                                    break;
                                }
                            }
                        ?>
                        <div class="chat-item <?php echo ($chat['chat_id'] == $current_chat_id) ? 'active' : ''; ?>" 
                             onclick="selectChat('<?php echo $chat['chat_id']; ?>')">
                            <div class="chat-subject">
                                <?php echo htmlspecialchars($chat['subject']); ?>
                                <span class="badge badge-<?php echo $chat['status']; ?>">
                                    <?php echo ucfirst($chat['status']); ?>
                                </span>
                            </div>
                            <div class="chat-preview">
                                <?php echo htmlspecialchars(substr($last_message, 0, 60)) . (strlen($last_message) > 60 ? '...' : ''); ?>
                            </div>
                            <div class="chat-meta">
                                <span><?php echo date('M j, g:i A', strtotime($chat['updated_at'])); ?></span>
                                <?php if ($chat['status'] === 'open'): ?>
                                    <span class="chat-unread">!</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <button class="new-chat-btn" onclick="showNewChatModal()">
                    <i class="fas fa-plus"></i> Start New Chat
                </button>
            </div>

            <!-- Chat Main Area -->
            <div class="chat-main">
                <?php if ($current_chat): ?>
                <div class="chat-header">
                    <div class="chat-header-info">
                        <h3><?php echo htmlspecialchars($current_chat['subject']); ?></h3>
                        <p>
                            Started <?php echo date('F j, Y', strtotime($current_chat['created_at'])); ?> • 
                            Status: <span style="color: <?php echo $current_chat['status'] === 'open' ? 'var(--success)' : 'var(--gray)'; ?>; font-weight: 600;">
                                <?php echo ucfirst($current_chat['status']); ?>
                            </span>
                        </p>
                    </div>
                    <div class="chat-actions">
                        <?php if ($current_chat['status'] === 'open'): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="chat_id" value="<?php echo $current_chat['chat_id']; ?>">
                            <button type="submit" name="close_chat" class="btn btn-success btn-small">
                                <i class="fas fa-check"></i> Mark Resolved
                            </button>
                        </form>
                        <?php endif; ?>
                        <button class="btn btn-secondary btn-small" onclick="copyChatTranscript()">
                            <i class="fas fa-copy"></i> Copy Transcript
                        </button>
                    </div>
                </div>
                
                <div class="chat-messages" id="chatMessages">
                    <?php if (empty($messages)): ?>
                        <div class="empty-state" style="padding: 40px 20px;">
                            <i class="fas fa-comment-dots"></i>
                            <p>No messages yet. Start the conversation!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($messages as $msg): ?>
                        <div class="message message-<?php echo $msg['sender_type']; ?>">
                            <?php if ($msg['sender_type'] !== 'system'): ?>
                            <div class="message-sender">
                                <?php echo htmlspecialchars($msg['sender_name']); ?>
                            </div>
                            <?php endif; ?>
                            <div class="message-content">
                                <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                            </div>
                            <div class="message-meta">
                                <span><?php echo date('g:i A', strtotime($msg['timestamp'])); ?></span>
                                <?php if ($msg['sender_type'] === 'student'): ?>
                                <span>
                                    <?php echo $msg['is_read'] ? '<i class="fas fa-check-double" style="color: #10B981;"></i>' : '<i class="fas fa-check"></i>'; ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if ($current_chat['status'] === 'open'): ?>
                        <div class="typing-indicator" id="typingIndicator" style="display: none;">
                            <div class="typing-dot"></div>
                            <div class="typing-dot"></div>
                            <div class="typing-dot"></div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                
                <?php if ($current_chat['status'] === 'open'): ?>
                <div class="chat-input-area">
                    <form method="POST" class="chat-input-form" id="chatForm">
                        <input type="hidden" name="chat_id" value="<?php echo $current_chat['chat_id']; ?>">
                        <textarea name="message" class="chat-input" placeholder="Type your message here..." 
                                  rows="2" id="messageInput" required></textarea>
                        <button type="submit" name="send_message" class="send-btn" id="sendBtn">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>
                    
                    <div class="quick-actions">
                        <div class="quick-action" onclick="insertQuickMessage('I need help with portal access')">
                            Portal Issue
                        </div>
                        <div class="quick-action" onclick="insertQuickMessage('I have a question about course registration')">
                            Course Registration
                        </div>
                        <div class="quick-action" onclick="insertQuickMessage('I need help with fee payment')">
                            Fee Payment
                        </div>
                        <div class="quick-action" onclick="insertQuickMessage('I have a technical problem')">
                            Technical Problem
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="chat-input-area" style="text-align: center; padding: 30px;">
                    <p style="color: var(--gray); margin-bottom: 15px;">
                        <i class="fas fa-lock"></i> This chat has been resolved
                    </p>
                    <button class="btn btn-primary" onclick="showNewChatModal()">
                        <i class="fas fa-plus"></i> Start New Chat
                    </button>
                </div>
                <?php endif; ?>
                
                <?php else: ?>
                <div class="empty-state" style="flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                    <i class="fas fa-comments" style="font-size: 72px; margin-bottom: 20px; color: var(--light-gray);"></i>
                    <h3 style="margin-bottom: 10px; color: var(--dark);">No Chat Selected</h3>
                    <p style="color: var(--gray); margin-bottom: 20px;">Select a conversation or start a new one</p>
                    <button class="btn btn-primary" onclick="showNewChatModal()">
                        <i class="fas fa-plus"></i> Start New Chat
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Support Agents Section -->
        <div class="agents-section">
            <div class="section-header">
                <h2>Available Support Agents</h2>
                <p>Our team is ready to assist you with various issues</p>
            </div>
            
            <div class="agents-grid">
                <?php foreach ($support_agents as $agent): ?>
                <div class="agent-card">
                    <div class="agent-header">
                        <div class="agent-avatar" style="background: <?php echo $agent['avatar_color']; ?>;">
                            <?php echo substr($agent['name'], 0, 1); ?>
                        </div>
                        <div class="agent-info">
                            <h4><?php echo htmlspecialchars($agent['name']); ?></h4>
                            <p><?php echo htmlspecialchars($agent['department']); ?></p>
                            <div class="agent-status">
                                <span class="status-dot status-<?php echo $agent['status']; ?>"></span>
                                <span style="text-transform: capitalize;"><?php echo $agent['status']; ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="agent-details">
                        <div class="agent-detail">
                            <i class="fas fa-briefcase"></i>
                            <span><?php echo htmlspecialchars($agent['specialization']); ?></span>
                        </div>
                        <div class="agent-detail">
                            <i class="fas fa-clock"></i>
                            <span><?php echo htmlspecialchars($agent['response_time']); ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- New Chat Modal -->
        <div class="modal" id="newChatModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Start New Chat</h3>
                </div>
                <form method="POST" id="newChatForm">
                    <div class="form-group">
                        <label class="form-label">Subject</label>
                        <input type="text" name="subject" class="form-control" 
                               placeholder="Brief description of your issue" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="category" class="form-select" required>
                            <option value="">-- Select Category --</option>
                            <option value="technical">Technical Support</option>
                            <option value="academic">Academic Issues</option>
                            <option value="financial">Financial Matters</option>
                            <option value="hostel">Hostel & Accommodation</option>
                            <option value="general">General Inquiry</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Priority</label>
                        <select class="form-select">
                            <option>Normal</option>
                            <option>High</option>
                            <option>Urgent</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Description (Optional)</label>
                        <textarea name="description" class="form-control" rows="3" 
                                  placeholder="Provide more details about your issue..."></textarea>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="submit" name="start_new_chat" class="btn btn-primary">
                            <i class="fas fa-comment-medical"></i> Start Chat
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="hideNewChatModal()">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Action Buttons -->
        <div style="display: flex; gap: 15px; justify-content: center; margin-top: 30px; flex-wrap: wrap;">
            <a href="dashboard.php" class="btn btn-primary">
                <i class="fas fa-home"></i> Back to Dashboard
            </a>
            <a href="student-support.php" class="btn btn-secondary">
                <i class="fas fa-question-circle"></i> Support Hub
            </a>
            <button onclick="window.print()" class="btn btn-secondary">
                <i class="fas fa-print"></i> Print Chat
            </button>
            <button onclick="showNewChatModal()" class="btn btn-success">
                <i class="fas fa-plus"></i> New Chat
            </button>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Auto-scroll to bottom of chat
        const chatMessages = document.getElementById('chatMessages');
        if (chatMessages) {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
        
        // Auto-resize textarea
        const messageInput = document.getElementById('messageInput');
        if (messageInput) {
            messageInput.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        }
        
        // Form submission
        const chatForm = document.getElementById('chatForm');
        if (chatForm) {
            chatForm.addEventListener('submit', function(e) {
                const messageInput = this.querySelector('textarea[name="message"]');
                if (!messageInput.value.trim()) {
                    e.preventDefault();
                    return;
                }
                
                // Show typing indicator
                const typingIndicator = document.getElementById('typingIndicator');
                if (typingIndicator) {
                    typingIndicator.style.display = 'flex';
                    chatMessages.scrollTop = chatMessages.scrollHeight;
                }
                
                // Disable send button temporarily
                const sendBtn = this.querySelector('button[type="submit"]');
                sendBtn.disabled = true;
                sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                
                // In a real app, this would be AJAX
                // For demo, we'll simulate a delay
                setTimeout(() => {
                    sendBtn.disabled = false;
                    sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i>';
                    if (typingIndicator) {
                        typingIndicator.style.display = 'none';
                    }
                }, 2000);
            });
        }
        
        // Auto-refresh chat every 10 seconds
        if (<?php echo ($current_chat && $current_chat['status'] === 'open') ? 'true' : 'false'; ?>) {
            setInterval(() => {
                // In a real application, this would be an AJAX call to check for new messages
                console.log('Checking for new messages...');
            }, 10000);
        }
    });
    
    function selectChat(chatId) {
        // Create a form and submit it to select the chat
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'select_chat';
        input.value = chatId;
        
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
    }
    
    function showNewChatModal() {
        document.getElementById('newChatModal').classList.add('active');
    }
    
    function hideNewChatModal() {
        document.getElementById('newChatModal').classList.remove('active');
    }
    
    function insertQuickMessage(text) {
        const messageInput = document.getElementById('messageInput');
        if (messageInput) {
            messageInput.value = text;
            messageInput.focus();
            messageInput.style.height = 'auto';
            messageInput.style.height = (messageInput.scrollHeight) + 'px';
        }
    }
    
    function copyChatTranscript() {
        const messages = document.querySelectorAll('.message');
        let transcript = `Chat Transcript - ${new Date().toLocaleString()}\n\n`;
        
        messages.forEach(msg => {
            const sender = msg.querySelector('.message-sender')?.textContent || 'System';
            const content = msg.querySelector('.message-content')?.textContent || '';
            const time = msg.querySelector('.message-meta span')?.textContent || '';
            
            transcript += `[${time}] ${sender}: ${content}\n`;
        });
        
        navigator.clipboard.writeText(transcript).then(() => {
            alert('Chat transcript copied to clipboard!');
        }).catch(err => {
            console.error('Failed to copy: ', err);
            alert('Failed to copy transcript. Please try again.');
        });
    }
    
    // Close modal when clicking outside
    document.getElementById('newChatModal')?.addEventListener('click', function(e) {
        if (e.target === this) {
            hideNewChatModal();
        }
    });
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            hideNewChatModal();
        }
    });
    
    // Print optimization
    window.addEventListener('beforeprint', function() {
        // Hide unnecessary elements when printing
        document.querySelectorAll('.header-right, .chat-input-area, .new-chat-btn, .btn, .modal, .quick-actions').forEach(el => {
            el.style.display = 'none';
        });
    });
    
    window.addEventListener('afterprint', function() {
        // Restore elements after printing
        document.querySelectorAll('.header-right, .chat-input-area, .new-chat-btn, .btn, .quick-actions').forEach(el => {
            el.style.display = '';
        });
    });
    
    // Chat notification sound (optional)
    function playNotificationSound() {
        const audio = new Audio('data:audio/wav;base64,UklGRigAAABXQVZFZm10IBIAAAABAAEAQB8AAEAfAAABAAgAZGF0YQ');
        audio.play().catch(e => console.log('Audio play failed:', e));
    }
    
    // Simulate incoming message (for demo)
    function simulateIncomingMessage() {
        const chatMessages = document.getElementById('chatMessages');
        if (!chatMessages) return;
        
        const newMessage = document.createElement('div');
        newMessage.className = 'message message-agent';
        newMessage.innerHTML = `
            <div class="message-sender">Support Agent</div>
            <div class="message-content">This is a simulated incoming message for demonstration purposes.</div>
            <div class="message-meta">
                <span>${new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</span>
            </div>
        `;
        
        chatMessages.appendChild(newMessage);
        chatMessages.scrollTop = chatMessages.scrollHeight;
        playNotificationSound();
    }
    
    // Demo: simulate incoming message after 30 seconds
    setTimeout(simulateIncomingMessage, 30000);
    </script>
</body>
</html>